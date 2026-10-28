<?php

// Waiver signing (App\Services\Waivers, docs/waivers.md). The legal text lives in
// App\Services\Waivers\LegalDocument; these are the paths, limits and dedupe windows around it.

return [

    // Signed PDFs: {storage_dir}/{waiver_id}.pdf, mode 0640. Never under public/. Tests point this
    // at a temp dir (the dev store is bind-mounted at storage/waivers).
    'storage_dir' => storage_path('waivers'),

    // Read-only source of PDFs the legacy app saved under its public/waivers. Kept outside public/
    // so the web server never serves them (public/.htaccess also denies /waivers/). A file found
    // there is copied into storage_dir on first admin view.
    'legacy_public_dir' => env('WAIVERS_LEGACY_DIR', storage_path('legacy-waivers')),

    // Used when sesf.waiver_health_notice_email is empty (legacy default).
    'health_notice_email_fallback' => 'kai@bmwsharkfest.org',

    'signature' => [
        // The base64 limit is config('sesf.waiver_signature_base64_max_chars') (legacy setting).
        'max_width' => 2000,
        'max_height' => 800,
        'typed_text_max_chars' => 80,
    ],

    // waiver.waiver_data is a TEXT column (65535 bytes); the encoded JSON must fit.
    'waiver_data_max_bytes' => 65535,

    // Walk-in (ad hoc) waivers: one pending row per browser session and show is reused; a new row
    // costs one hit on this per-IP budget. Walk-ins at the venue often share one IP (venue Wi-Fi,
    // carrier NAT), so the default allows a busy event day.
    'adhoc_creates_per_ip_per_hour' => (int) env('WAIVER_ADHOC_CREATES_PER_IP_PER_HOUR', 300),

    // At most one request email per person (waiver) and one reminder per registration per window.
    'request_dedupe_minutes' => 10,

    'pdf' => [
        'paper' => 'letter',
        'orientation' => 'portrait',
    ],
];
