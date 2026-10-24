<?php

// Southeast Sharkfest app settings. Values come from the environment where a secret, ID or
// per-environment value is involved; fixed paths and limits are literals.
//
// The *_root entries are the filesystem roots that the matching *_url paths are served from.

return [

    'forum_url' => env('SESF_FORUM_URL', ''),
    'shop_url' => env('SESF_SHOP_URL', ''),
    'reg_email' => env('SESF_REG_EMAIL', 'registration@bmwsharkfest.org'),
    'paypal_email' => env('SESF_PAYPAL_EMAIL', 'registration@bmwsharkfest.org'),
    'waiver_health_notice_email' => env('SESF_WAIVER_HEALTH_NOTICE_EMAIL', ''),

    // When true, every outgoing mail is redirected to dev_address (see RedirectMailInDev).
    // Fails safe: the default is on everywhere except production, so a missing variable in
    // production never disables the guard in other environments it was meant for.
    // Parsed with FILTER_VALIDATE_BOOLEAN so the string "false" is false.
    'email_debug' => filter_var(env('SESF_EMAIL_DEBUG', env('APP_ENV') !== 'production'), FILTER_VALIDATE_BOOLEAN),
    'dev_address' => env('SESF_DEV_ADDRESS', ''),

    'email_logo_url' => env('SESF_EMAIL_LOGO_URL', ''),
    'email_logo_path' => 'images/sesf_final_logo_v2.jpg',

    'gallery_items_per_page' => 36,
    'waiver_sign_ttl_seconds' => 2592000,
    'waiver_signature_base64_max_chars' => 400000,

    'image_url' => 'images/',
    'image_root' => public_path('images/'),
    'member_image_url' => 'images/members/',
    'member_image_root' => public_path('images/members/'),
    'car_image_url' => 'images/cars/',
    'car_image_root' => public_path('images/cars/'),
    'show_image_url' => 'images/shows/',
    'show_image_root' => public_path('images/shows/'),
    'sponsor_image_url' => 'images/sponsor/',
    'sponsor_image_root' => public_path('images/sponsor/'),
    'gallery_image_url' => 'images/gallery/',
    'gallery_image_root' => public_path('images/gallery/'),
    'schedule_pdf_url' => 'packet/',
    'schedule_pdf_root' => public_path('packet/'),

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret' => env('TURNSTILE_SECRET', ''),
    ],

    'vanilla' => [
        'client_id' => env('VANILLA_CLIENT_ID', ''),
        'secret' => env('VANILLA_SECRET', ''),
    ],

    'google' => [
        'geocode_key' => env('GOOGLE_GEOCODE_KEY', ''),
        'maps_js_key' => env('GOOGLE_MAPS_JS_KEY', ''),
    ],

    'datadog' => [
        'rum' => [
            'enabled' => filter_var(env('DATADOG_RUM_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'application_id' => env('DATADOG_APPLICATION_ID', ''),
            'client_token' => env('DATADOG_CLIENT_TOKEN', ''),
            'site' => env('DATADOG_SITE', 'us5.datadoghq.com'),
            'service' => env('DATADOG_SERVICE', 'sesf'),
            'version' => '1.0.0',
            'session_sample_rate' => 100,
            'session_replay_sample_rate' => 20,
        ],
        'logs' => [
            'enabled' => filter_var(env('DATADOG_LOGS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'api_key' => env('DATADOG_API_KEY', ''),
            'level' => env('DATADOG_LOGS_LEVEL', 'info'),
            'service' => env('DATADOG_SERVICE', 'sesf'),
        ],
    ],
];
