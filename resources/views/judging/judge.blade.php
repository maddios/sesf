@extends('judging.layout', ['entries' => ['resources/css/app.css', 'resources/js/judging/judge.ts']])

@php
    $registration = $car->registration;
    $owner = $registration?->user;
    $title = trim(($car->judging_type_id?->label() ?? '').' - '.($owner?->fullName ?? '').' - '.$car->car?->year.' '.$car->car?->model);
    $judgingConfig = [
        'dataUrl' => '/judgingData/'.$token,
        'submitUrl' => '/judgingSubmit/'.$token,
    ];
@endphp

@section('title', $title)

@section('content')
    <header class="flex flex-col gap-4 rounded-lg border border-border bg-panel p-4 sm:flex-row sm:items-center">
        @if ($car->car !== null)
            <img src="{{ \App\Support\LegacyImageUrl::fit($car->car->imageURL, 500, 500) }}" alt="{{ $car->car->year }} {{ $car->car->model }}" class="h-32 w-32 rounded object-cover">
        @endif
        <div>
            <h1 class="text-xl font-bold text-heading">{{ $car->car?->year }} {{ $car->car?->model }}</h1>
            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-muted">Owner</dt>
                <dd>{{ $owner?->fullName }}</dd>
                <dt class="text-muted">Car number</dt>
                <dd>{{ $registration?->invoice_id }}</dd>
                <dt class="text-muted">Judging type</dt>
                <dd>{{ $car->judging_type_id?->label() ?? 'Not set' }}</dd>
                <dt class="text-muted">Event</dt>
                <dd>{{ $registration?->carShow?->name }}</dd>
            </dl>
        </div>
    </header>

    <div id="judging-app" class="mt-4"></div>
    <script id="judging-config" type="application/json">@json($judgingConfig)</script>
    <noscript><p class="mt-4">Judging needs JavaScript. Please enable it and reload this page.</p></noscript>
@endsection
