<?php

return [
    // Disk for passports, photos, visas, invoices. 'local' (storage/app/private) in dev, 's3' in prod.
    'disk' => env('VISA_DISK', 'local'),

    // Shown on the payment-failed page; hidden when null.
    'support_email' => env('SUPPORT_EMAIL'),

    'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),

    // Passport-trained model (BSD-3, DoubangoTelecom/tesseractMRZ) shipped in resources/tessdata.
    'tesseract_lang' => env('TESSERACT_LANG', 'mrz'),
    'tessdata_dir' => env('TESSDATA_DIR', resource_path('tessdata')),

    // Poppler's pdftoppm turns page 1 of a PDF passport scan into an image for OCR.
    'pdftoppm_path' => env('PDFTOPPM_PATH', 'pdftoppm'),

    'tap' => [
        'secret_key' => env('TAP_SECRET_KEY'),
        'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
    ],

    'checkout_timeout_minutes' => (int) env('CHECKOUT_TIMEOUT_MINUTES', 30),

    'file_retention_days' => (int) env('VISA_FILE_RETENTION_DAYS', 90),

    // Staff 2FA is mandatory. Only turn off on a local dev machine (ADMIN_REQUIRE_2FA=false).
    'admin_require_2fa' => (bool) env('ADMIN_REQUIRE_2FA', true),

    // Comma-separated IPs/CIDRs. Empty = no restriction.
    'admin_ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),

    'invoice' => [
        'prefix' => 'BOC',
        'company_name' => 'Bahrain Olympic Committee',
        'address' => env('INVOICE_ADDRESS', 'Manama, Kingdom of Bahrain'),
    ],
];
