<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="strict-origin-when-cross-origin">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Southeast Sharkfest</title>
        <link rel="icon" href="/images/favicon.ico" sizes="any">
        <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
        <meta property="og:image" content="{{ rtrim((string) config('app.url'), '/') }}/images/sesf_final_logo_v2.jpg">
        <meta name="google-site-verification" content="Qw0JT0-fLK0PD-KsmkhYErrfOSPSePY_EmflO7WI1mI">
        @include('partials.datadog_rum')
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="min-h-screen antialiased">
        <div id="app"></div>
        @php
            // Public, non-secret client config read by resources/js/config.ts. Built here (not inline in @json)
            // because Blade's directive parser cannot handle a multi-line array argument.
            $sesfClientConfig = [
                'forumUrl' => config('sesf.forum_url'),
                'shopUrl' => config('sesf.shop_url'),
                'mapsKey' => config('sesf.google.maps_js_key'),
                'turnstileSiteKey' => config('sesf.turnstile.site_key'),
                'paypalEmail' => config('sesf.paypal_email'),
                'appUrl' => config('app.url'),
            ];
        @endphp
        <script id="sesf-config" type="application/json">@json($sesfClientConfig)</script>
        <noscript>
            <p class="p-4">Southeast Sharkfest requires JavaScript. Please enable JavaScript in your browser and reload this page.</p>
        </noscript>
    </body>
</html>
