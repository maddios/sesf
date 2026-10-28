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
    'gallery_max_items_per_album' => (int) env('SESF_GALLERY_MAX_ITEMS_PER_ALBUM', 2000),
    'gallery_max_items_per_member_per_show' => (int) env('SESF_GALLERY_MAX_ITEMS_PER_MEMBER_PER_SHOW', 1000),
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
    // Legacy JitImage resize URLs: /images/{mode}/{w}/{h}[/{g}]/{source} (App\Services\Images\LegacyImages).
    // source is resolved against base (legacy jitimage 'base' was public_path()), and the real path must be
    // inside one of roots. sizes lists every "mode/w/h" the legacy templates and SPA request; anything else is
    // a 404, so the cache cannot be filled with arbitrary sizes. Mode 2 also takes a gravity 1-9.
    'legacy_images' => [
        'base' => public_path(),
        'roots' => [
            public_path('images/'),
            public_path('images/members/'),
            public_path('images/cars/'),
            public_path('images/shows/'),
            public_path('images/sponsor/'),
            public_path('images/gallery/'),
            public_path('images/judging/'),
        ],
        'cache_dir' => storage_path('framework/legacy-images'),
        'sizes' => [
            '1/0/70', '1/1070/360',
            '2/320/230', '2/640/460', '2/200/140', '2/400/280', '2/221/165',
            '4/1070/800', '4/500/500', '4/200/150', '4/652/407', '4/0/60', '4/300/220', '4/228/0',
            '4/100/100', '4/362/272', '4/150/60', '4/320/230', '4/459/367', '4/221/0', '4/250/65',
            '4/240/240', '4/97/73',
        ],
    ],

    'schedule_pdf_url' => 'packet/',
    'schedule_pdf_root' => public_path('packet/'),

    // PayPal IPN receiver (App\Services\PayPal, docs/paypal-ipn.md). email is the account that must
    // receive the payment (receiver_email or business). sandbox must match the IPN's test_ipn flag.
    'paypal' => [
        'email' => env('SESF_PAYPAL_EMAIL', 'registration@bmwsharkfest.org'),
        'verify_url' => env('PAYPAL_IPN_VERIFY_URL', 'https://ipnpb.paypal.com/cgi-bin/webscr'),
        'sandbox' => filter_var(env('PAYPAL_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),
        'currency' => 'USD',
    ],

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
            'track_resources' => true,
            'track_user_interactions' => true,
            'track_long_tasks' => true,
        ],
        'logs' => [
            'enabled' => filter_var(env('DATADOG_LOGS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'api_key' => env('DATADOG_API_KEY', ''),
            'level' => env('DATADOG_LOGS_LEVEL', 'info'),
            'service' => env('DATADOG_SERVICE', 'sesf'),
        ],
    ],
];
