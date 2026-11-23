# BOC Event Visa Management System — Design

**Date:** 2026-11-23
**Owner:** Bahrain Olympic Committee (BOC)
**Status:** Approved design, pending implementation plan

## 1. Purpose

BOC hosts events attended by participants from outside Bahrain who need a Bahrain visa. This system lets applicants (individuals or delegation managers) submit visa applications per event, upload passport and photo, pay the visa fee via Tap, and receive the issued visa. BOC staff process applications (applying manually on the Bahrain government visa system), track payments, and issue invoices.

## 2. Decisions summary

| Topic | Decision |
|---|---|
| Events | Admins create events; every application belongs to one event |
| Applicant access | Full accounts (email + password, email verification required) |
| Groups | One account can submit many applicants (delegations); one Tap payment can cover several applicants |
| Stack | Laravel 12, PHP 8.3, MySQL 8, Livewire 3 + Tailwind (portal), Filament 4 (staff panel), Redis queue |
| Architecture | Single Laravel monolith: portal on `/`, Filament on `/admin`, queue workers |
| Hosting | AWS Bahrain (me-south-1): EC2 (app + MySQL), private S3 (files + backups), SES (email) |
| OCR | Self-hosted MRZ reading (Tesseract + MRZ parser); applicant always reviews/edits |
| Payment | Tap hosted checkout; flat fee per applicant per event, BHD; webhook is source of truth |
| Invoices | PDF invoice per payment, sequential number, no VAT (per-event `vat_rate` field, default 0, for later) |
| Notifications | Email at launch via Laravel Notifications; WhatsApp number stored, channel added later |
| Staff roles | Admin, Processor, Finance |
| UI design | Applicant portal designed in Claude Design → ported to Blade/Tailwind; Filament branded only |

## 3. Architecture

One Laravel application, one deployment on a single EC2 instance in me-south-1:

- **Web:** Nginx + PHP-FPM. Applicant portal (Livewire) and Filament admin panel.
- **Queue worker:** Redis-backed (Supervisor-managed) for OCR jobs, emails, invoice PDF generation, webhook processing.
- **Scheduler:** Tap reconciliation, abandoned-checkout cleanup, file retention purge, nightly backup.
- **MySQL 8** on the same EC2 instance, bound to `127.0.0.1` only.
- **S3 (private, SSE-KMS):** passport scans, photos, visa PDFs, invoice PDFs, database backups.
- **SES:** transactional email.

### Backups
- Nightly `mysqldump` (compressed, encrypted) to a private S3 bucket, 30-day lifecycle retention.
- Daily EBS snapshots via AWS Data Lifecycle Manager.

## 4. Data model

### `users`
`id, name, email (unique), password, role enum(applicant, admin, processor, finance), is_active, email_verified_at, two_factor_* (staff), timestamps`

### `events`
`id, name, slug (unique), start_date, end_date, application_deadline, visa_fee decimal(10,3), currency (default 'BHD'), vat_rate decimal(5,2) default 0, is_open bool, timestamps`

### `applicants`
`id, user_id (submitter), event_id, full_name, email, nationality (ISO-3166 alpha-3), date_of_birth, passport_number, issuing_country (alpha-3), passport_issue_date, passport_expiry_date, mobile, whatsapp, passport_scan_path, photo_path, status, assigned_processor_id (nullable), correction_note, rejection_reason, gov_reference_no, visa_pdf_path, ocr_raw (json, nullable), submitted_at, applied_at, issued_at, timestamps`

- Unique index: `(event_id, passport_number)`.

### `payments`
`id, user_id, event_id, tap_charge_id (unique), amount decimal(10,3), currency, status enum(initiated, paid, failed, refunded), paid_at, refunded_at, payment_method, raw_payload json, timestamps`

### `applicant_payment`
`applicant_id, payment_id` — which applicants one payment covers. An applicant can be linked to at most one payment in `initiated` or `paid` state.

### `invoices`
`id, payment_id, number (unique, e.g. BOC-2026-00001), type enum(invoice, credit_note), references_invoice_id (nullable), subtotal, vat_amount, total, pdf_path, issued_at, timestamps`

Sequential numbering is allocated inside a DB transaction with a row lock on a counter row (`invoice_sequences` table: `year, last_number`).

### `status_logs`
`id, applicant_id, from_status, to_status, user_id, note, created_at` — audit trail for every status change.

## 5. Applicant status flow

```
Draft → Pending Payment → Submitted → In Review → Applied → Visa Issued
             ↓ (timeout/fail)            ↓             ↓
           Draft                 Needs Correction   Rejected
                                  ↓ (applicant resubmits)
                                Submitted
In Review → Rejected
```

Allowed transitions live in one map on the `Applicant` model (`ApplicantStatus` enum + `canTransitionTo()`). All transitions go through one method that validates, writes `status_logs`, and dispatches the applicant notification. Admin override bypasses the map but requires a note.

## 6. Applicant flow

1. **Register / login** — name, email, password (contact numbers are captured per applicant). Email verification required before applying. Standard password reset.
2. **Dashboard** — open events; "My applicants" grouped by event with status badges; payments and invoice downloads.
3. **Add applicant** (repeatable per person):
   1. Upload passport bio page (JPG/PNG/PDF, ≤ 5 MB).
   2. Synchronous OCR on the uploaded image (PDF uploads skip OCR): Tesseract → MRZ parser (TD3) with check-digit validation. Pre-fills full name, nationality, DOB, passport number, issuing country, expiry. On failure: "Couldn't read passport, please fill manually."
   3. Applicant reviews/edits all fields and enters issue date, email, mobile, WhatsApp.
   4. Upload personal photo (JPG/PNG; white-background guideline shown).
   5. Saved as **Draft** (editable/deletable).
   - Validation: passport expiry ≥ event end date + 6 months; issue date < expiry; DOB in the past; no duplicate passport for the event; event open and before deadline.
4. **Checkout:**
   1. Select one or more Draft applicants of one event → **Pending Payment**, details locked.
   2. Total = `visa_fee × count` (BHD).
   3. Create Tap charge → redirect to Tap hosted page (cards / BenefitPay / Apple Pay).
   4. Tap webhook (signature verified, idempotent on `tap_charge_id`) → payment `paid` → applicants **Submitted** → invoice generated and emailed.
   5. Redirect page shows status only; never changes state.
   6. Failed/abandoned: after 30 minutes applicants return to **Draft**; user can retry.
5. **After submission:** status visible on dashboard, email on each change. **Needs Correction** shows processor note; applicant edits flagged items and resubmits (no new payment). **Visa Issued**: visa PDF downloadable and emailed.

## 7. Staff flow (Filament `/admin`)

### Processor
- **Queue:** applicants table, default filter Submitted; filters: event, status, nationality, assigned-to-me. Columns: name, nationality, passport no., event, status, submitted at, waiting time.
- **Detail:** all fields, passport scan and photo previews + download, click-to-call / `wa.me` links, status history.
- **Actions** (each logged + applicant emailed):
  - Claim: Submitted → In Review (sets `assigned_processor_id`)
  - Request correction: In Review → Needs Correction (note required)
  - Mark applied: In Review → Applied (government reference no. required)
  - Issue visa: Applied → Visa Issued (PDF upload required)
  - Reject: In Review / Applied → Rejected (reason required)
- **Bulk download:** zip of passport scans + photos for selected applicants.

### Finance (read-only)
- **Payments list:** date, payer, event, applicant count, amount, Tap charge ID, status; filters: event, status, date range; totals for filtered range; Excel export.
- **Payment detail:** covered applicants (name + status only, no passport files), invoices.
- **Invoices:** view/download/resend invoice and credit notes.
- **Mark refunded:** sets payment `refunded` and issues a credit note (refund itself done in Tap dashboard).
- **Dashboard widget:** revenue per event; paid / failed / refunded counts.

### Admin
- Everything Processor and Finance can do.
- **Events:** CRUD (name, dates, fee, deadline, open/closed, vat_rate). Closing stops new applications; existing continue.
- **Staff users:** create/deactivate admin, processor, finance accounts (no staff self-registration).
- **Status override:** any status, note required.
- **Applicants export:** filtered list to `.xlsx`.

## 8. Invoices

- Generated by a queued job when a payment becomes `paid` (exactly once per payment).
- Contents: BOC name, logo, address; invoice number; issue date; payer name and email; event; one line per applicant (name, passport no.) at the per-applicant fee; subtotal, VAT (0, line hidden when rate is 0), total; payment method; Tap charge ID; paid date.
- Emailed to payer; downloadable from applicant dashboard; Finance/Admin can resend.
- Refund → credit note with its own number referencing the original invoice.
- PDF via `barryvdh/laravel-dompdf` from a Blade template.

## 9. Notifications

All notifications are Laravel Notification classes (`via()` returns `['mail']` now; WhatsApp channel added later without changing call sites). Events: email verification, payment confirmed + invoice, needs correction, applied, visa issued (with PDF), rejected. Sent via queue; failures retried and never block status changes.

## 10. Error handling

- **OCR failure / MRZ checksum failure:** manual entry; never blocks submission.
- **Missing/late Tap webhook:** scheduled job every 10 minutes queries Tap for `initiated` charges older than 5 minutes and reconciles. Webhook + reconciliation share one idempotent `markPaid()`.
- **Double payment:** prevented by locking applicants in Pending Payment and the one-active-payment rule.
- **Invalid transitions:** rejected by the transition map.
- **Email failures:** queued retry.

## 11. Security & privacy (PDPL)

- Private S3 bucket, SSE-KMS; files served only via 5-minute signed URLs after policy check.
- Policies: applicants see only their own applicants/payments; Processor and Admin see all applicants; Finance sees no passport files or photos.
- Staff: strong passwords + mandatory 2FA; `/admin` optionally restricted to BOC IP range.
- Rate limiting on login, registration, uploads.
- Uploads: MIME + size validation; images re-encoded to strip embedded content.
- Retention: configurable purge of passport scans and photos N days after event end (default 90); text record, invoices kept.
- HTTPS only; MySQL on localhost; encrypted backups.

## 12. Testing (Pest)

- **Feature:** registration/verification; add applicant; checkout with faked Tap (webhook → paid → Submitted → invoice created); webhook idempotency; reconciliation job; correction loop; each processor action; Finance cannot access passport files; applicant cannot access another user's applicants; invoice numbering is sequential.
- **Unit:** MRZ parser (valid TD3, bad checksum, garbage input); status transition map.
- Tap sandbox used for manual end-to-end testing before go-live; no live Tap calls in CI.

## 13. Out of scope (v1)

- In-app refund execution (done in Tap dashboard)
- WhatsApp automated messaging (channel slot exists)
- VAT calculation (field exists, default 0)
- Multi-language UI
- Integration with the Bahrain government visa system
