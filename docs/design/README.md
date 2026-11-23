# Approved UI designs (reference)

Exported from the "BOC Visa Portal Design" canvas, approved 2026-11-23.
Each `*.dc.html` file is one screen: plain HTML with inline styles (the `{{accent}}` hole = `#C8102E`, the `<x-dc>`/`<helmet>`/`data-dc-script` wrappers are canvas-only and not part of the app).

| File | App screen |
|---|---|
| Main, Register, VerifyEmail, ForgotPassword, ResetPassword | Applicant auth (`resources/views/livewire/auth/*`) |
| Dashboard (mobile), DashboardDesktop | `livewire/dashboard.blade.php` (one responsive view) |
| ApplicantForm, FixApplicant | `livewire/applicant-form.blade.php` (add / edit / correction mode) |
| PaymentResult, PaymentFailed, PaymentPending | `checkout/return.blade.php` (paid / failed / pending) |
| Invoice | `invoices/pdf.blade.php` |
| StaffLogin, StaffQueue, StaffReview, StaffPayments, StaffEvents | Filament staff panel (theme reference) |

Tokens: accent #C8102E (hover #B0102A, dark #9F1239), ink #111827, muted #4B5563, border #E5E7EB, page #F6F6F7. Fonts: Manrope 800 headings, Inter body. Logo: `public/images/boc-logo.png`.
