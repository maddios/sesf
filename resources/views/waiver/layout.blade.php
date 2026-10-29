<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Waiver') — Southeast Sharkfest</title>
    @include('partials.datadog_rum')
    @yield('head')
    @vite(['resources/js/waiver/main.ts'])
</head>
<body class="sesf-waiver-page">
<main class="sesf-waiver-shell">
    @yield('content')
</main>
<script id="sesf-waiver-config" type="application/json">@json($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
<noscript>
    <p class="sesf-waiver-alert sesf-waiver-alert-error" role="alert">Southeast Sharkfest waivers require JavaScript. Please enable JavaScript in your browser and reload this page.</p>
</noscript>
</body>
</html>
