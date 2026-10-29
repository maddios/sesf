<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; color: #000; }
        .page { text-align: center; page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .header { font-size: 14px; }
        h1 { font-size: 40px; margin: 8px 0; }
        h2 { font-size: 24px; margin: 6px 0; }
        img { width: 300px; height: 300px; }
        .url { font-size: 11px; word-break: break-all; }
    </style>
</head>
<body>
@forelse ($pages as $page)
    <div class="page">
        <div class="header">{{ $show->name }}</div>
        <h1>{{ $page['car']->ownerFirst }} {{ mb_substr($page['car']->ownerLast, 0, 1) }}</h1>
        <h2>{{ $page['car']->judgingType }}</h2>
        <h2>({{ $page['car']->invoiceId }}) {{ $page['car']->carName() }}</h2>
        <img src="{{ $page['qr'] }}" alt="">
        <p class="url">{{ $page['url'] }}</p>
    </div>
@empty
    <p>No cars are entered for judging in {{ $show->name }}.</p>
@endforelse
</body>
</html>
