<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Southeast Sharkfest</title>
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div id="app"></div>
        <noscript>
            <p class="p-4">Southeast Sharkfest requires JavaScript. Please enable JavaScript in your browser and reload this page.</p>
        </noscript>
    </body>
</html>
