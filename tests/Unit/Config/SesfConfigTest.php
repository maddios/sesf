<?php

it('has every sesf key with the expected literal defaults', function () {
    expect(config('sesf.reg_email'))->toBe('registration@bmwsharkfest.org')
        ->and(config('sesf.paypal_email'))->toBe('registration@bmwsharkfest.org')
        ->and(config('sesf.email_logo_path'))->toBe('images/sesf_final_logo_v2.jpg')
        ->and(config('sesf.gallery_items_per_page'))->toBe(36)
        ->and(config('sesf.waiver_sign_ttl_seconds'))->toBe(2592000)
        ->and(config('sesf.waiver_signature_base64_max_chars'))->toBe(400000)
        ->and(config('sesf.image_url'))->toBe('images/')
        ->and(config('sesf.member_image_url'))->toBe('images/members/')
        ->and(config('sesf.car_image_url'))->toBe('images/cars/')
        ->and(config('sesf.show_image_url'))->toBe('images/shows/')
        ->and(config('sesf.sponsor_image_url'))->toBe('images/sponsor/')
        ->and(config('sesf.gallery_image_url'))->toBe('images/gallery/')
        ->and(config('sesf.schedule_pdf_url'))->toBe('packet/')
        ->and(config('sesf.datadog.rum.version'))->toBe('1.0.0')
        ->and(config('sesf.datadog.rum.session_sample_rate'))->toBe(100)
        ->and(config('sesf.datadog.rum.session_replay_sample_rate'))->toBe(20);
});

it('has every env-driven key present', function () {
    $keys = [
        'forum_url', 'shop_url', 'reg_email', 'paypal_email', 'waiver_health_notice_email',
        'email_debug', 'dev_address', 'email_logo_url', 'email_logo_path',
        'gallery_items_per_page', 'waiver_sign_ttl_seconds', 'waiver_signature_base64_max_chars',
        'image_url', 'image_root', 'member_image_url', 'member_image_root', 'car_image_url',
        'car_image_root', 'show_image_url', 'show_image_root', 'sponsor_image_url',
        'sponsor_image_root', 'gallery_image_url', 'gallery_image_root', 'schedule_pdf_url',
        'schedule_pdf_root', 'turnstile', 'vanilla', 'google', 'datadog',
    ];

    $sesf = config('sesf');

    foreach ($keys as $key) {
        expect($sesf)->toHaveKey($key);
    }

    expect($sesf['turnstile'])->toHaveKeys(['site_key', 'secret'])
        ->and($sesf['vanilla'])->toHaveKeys(['client_id', 'secret'])
        ->and($sesf['google'])->toHaveKeys(['geocode_key', 'maps_js_key'])
        ->and($sesf['datadog']['rum'])->toHaveKeys(['enabled', 'application_id', 'client_token', 'site', 'service', 'version', 'session_sample_rate', 'session_replay_sample_rate'])
        ->and($sesf['datadog']['logs'])->toHaveKeys(['enabled', 'api_key', 'level', 'service']);
});

it('casts the boolean flags to bool', function () {
    expect(config('sesf.email_debug'))->toBeBool()
        ->and(config('sesf.datadog.rum.enabled'))->toBeBool()
        ->and(config('sesf.datadog.logs.enabled'))->toBeBool();
});

it('reads the timezone from APP_TIMEZONE', function () {
    expect(config('app.timezone'))->toBeString();
});
