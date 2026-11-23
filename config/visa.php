<?php

return [
    // Disk for passports, photos, visas, invoices. 'local' (storage/app/private) in dev, 's3' in prod.
    'disk' => env('VISA_DISK', 'local'),

    'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),

    'tap' => [
        'secret_key' => env('TAP_SECRET_KEY'),
        'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
    ],

    'checkout_timeout_minutes' => (int) env('CHECKOUT_TIMEOUT_MINUTES', 30),

    'file_retention_days' => (int) env('VISA_FILE_RETENTION_DAYS', 90),

    // Comma-separated IPs/CIDRs. Empty = no restriction.
    'admin_ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),

    'invoice' => [
        'prefix' => 'BOC',
        'company_name' => 'Bahrain Olympic Committee',
        'address' => env('INVOICE_ADDRESS', 'Manama, Kingdom of Bahrain'),
    ],
];
