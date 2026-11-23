# Claude Design brief — BOC Event Visa Portal (applicant side)

## Context
Bahrain Olympic Committee portal where foreign athletes, officials and delegation managers apply for a Bahrain visa to attend BOC events. Users are international, often on phones, often non-native English speakers. Tone: official, trustworthy, calm, simple.

- Brand: Bahrain Olympic Committee. Red (#CE1126) and white, neutral greys. BOC logo top-left.
- Mobile-first (375 px), also good on desktop (max content width ~1100 px).
- Output: Tailwind CSS HTML (will be ported into Laravel Blade + Livewire).
- Accessible: clear labels above inputs, visible focus states, AA contrast, error text under each field.
- Only the applicant portal needs custom design. The staff panel (Filament) just uses the BOC red and logo.

## Shared layout
- Header: logo, "Visa Portal", user menu (name, Log out). Logged-out: Log in / Register.
- Footer: BOC name, contact email, privacy note ("Passport data is stored in Bahrain and deleted 90 days after the event").

## Screens

### 1. Log in
Email, password, "Remember me", Forgot password link, Register link.

### 2. Register
Full name, email, password, confirm password. Note: "One account can apply for several people, e.g. a whole team."

### 3. Verify email
"We sent a link to {email}". Resend button.

### 4. Forgot / reset password
Email field → confirmation; reset form with new password + confirm.

### 5. Dashboard (main screen)
- **Open events** cards: event name, dates, visa fee "BHD 30.000 per person", "Apply by {date}", button "Add applicant".
- **My applicants**, grouped by event. Each row/card: name, passport number, status badge, actions.
  - Status badges (8): Draft (grey), Pending payment (grey), Submitted (blue), In review (indigo), Needs correction (amber), Applied (indigo), Visa issued (green), Rejected (red).
  - Draft rows have a checkbox + Edit + Delete.
  - Needs correction: show the processor's note inline in amber + "Fix" button.
  - Rejected: show reason in red.
  - Visa issued: prominent "Download visa" button.
  - Below each event group: sticky/footer bar "3 selected · Total BHD 90.000 · Pay now" (disabled when none selected).
- **Payments**: date, event, amount, status, invoice number link (download PDF).
- Empty states: no events open; no applicants yet ("Add your first applicant").

### 6. Add / edit applicant
Step feel, single page:
1. **Upload passport bio page** (drag/drop or camera on mobile; JPG/PNG/PDF, max 5 MB). States: empty → uploading → "Reading passport…" spinner → success banner "Passport read. Please check every field." or warning banner "Couldn't read the passport automatically. Please fill in the details." Show thumbnail of uploaded image.
2. **Check details** (prefilled by OCR, all editable): Full name, Nationality (searchable country select), Date of birth, Passport number, Country of issue (country select), Date of issue, Date of expiry.
3. **Contact**: Email, Mobile, WhatsApp (with "same as mobile" checkbox).
4. **Photo**: upload with guideline graphic (white background, face centered, no glasses); thumbnail preview.
- Buttons: "Save applicant" (draft) / on correction: "Resubmit".
- Correction mode: amber banner at top with processor's note.
- Inline field errors e.g. "The passport must be valid for at least 6 months after the event ends."

### 7. Payment result
Three states: ✅ Payment received (invoice emailed, applications in queue) · ❌ Payment did not go through (applicants back in draft, Try again) · ⏳ Confirming your payment… Button "Back to dashboard".

### 8. Invoice (PDF, A4, print-friendly, no colour dependency)
Logo, "Invoice BOC-2026-00001" (or "Credit Note"), BOC name/address, issue date, billed to (name, email), event, table: Applicant · Passport · Amount (BHD, 3 decimals), Subtotal, Total, "Paid via Tap (VISA) on {date}", Tap charge ID.

### 9. Emails (simple, single column)
Payment received (+ invoice attached) · Needs correction (note + button) · Applied (gov reference) · Visa issued (+ visa attached) · Rejected (reason).
