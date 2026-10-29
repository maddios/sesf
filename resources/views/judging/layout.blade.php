<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="strict-origin-when-cross-origin">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title') · Southeast Sharkfest Judging</title>
        @include('partials.datadog_rum')
        <link rel="icon" href="/images/favicon.ico" sizes="any">
        @vite($entries ?? ['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-text antialiased">
        <main class="mx-auto max-w-4xl px-4 py-6">
            @yield('content')
        </main>
    </body>
</html>
