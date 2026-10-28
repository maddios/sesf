@extends('judging.layout')

@php
    $registration = $car->registration;
    $owner = $registration?->user;
@endphp

@section('title', 'Judging summary')

@section('content')
    @if ($isOwner && $car->car !== null)
        <p><a class="text-primary underline" href="/#/car/{{ $car->car->id }}">← Back to car profile</a></p>
    @endif
    <h1 class="mt-2 text-2xl font-bold text-heading">Judging summary</h1>

    <dl class="mt-4 grid grid-cols-1 gap-3 rounded-lg border border-border bg-panel p-4 sm:grid-cols-2">
        <div><dt class="text-sm text-muted">Owner</dt><dd>{{ $owner?->fullName }}</dd></div>
        <div><dt class="text-sm text-muted">Car</dt><dd>{{ $car->car?->year }} {{ $car->car?->model }}</dd></div>
        <div><dt class="text-sm text-muted">Event</dt><dd>{{ $registration?->carShow?->name }}</dd></div>
        <div><dt class="text-sm text-muted">Judging type</dt><dd>{{ $car->judging_type_id?->label() }}</dd></div>
    </dl>

    @if ($answers->isEmpty())
        <p class="mt-6">No judging data yet.</p>
    @else
        <section class="mt-6 rounded-lg border border-border bg-panel p-4" aria-labelledby="score-heading">
            <div class="flex items-center justify-between">
                <h2 id="score-heading" class="text-lg font-bold text-heading">Scores</h2>
                <span class="rounded-full bg-brand px-3 py-1 font-bold text-white">{{ $total }}</span>
            </div>
            <ul class="mt-3 divide-y divide-border">
                @foreach ($pages as $page)
                    <li class="flex justify-between py-2"><span>{{ $page['name'] }}</span><span class="font-semibold">{{ $page['sum'] }}</span></li>
                @endforeach
            </ul>
        </section>

        @foreach ($answers as $row)
            @php($answer = $row['answer'])
            <article class="mt-4 rounded-lg border border-border bg-panel p-4">
                <p class="text-sm text-muted">{{ $answer->question?->page_name }}</p>
                <h2 class="font-bold text-heading">{{ $answer->question?->title }}</h2>
                <p class="mt-2"><strong>Score:</strong> {{ $answer->data }} <span class="text-sm text-muted">· {{ $answer->judge?->fullName }}</span></p>
                @if ($answer->comment)
                    <p class="mt-2 whitespace-pre-line"><strong>Comment:</strong> {{ $answer->comment }}</p>
                @endif
                @if ($row['photos'] !== [])
                    <ul class="mt-3 flex flex-wrap gap-2" aria-label="Photos for this question">
                        @foreach ($row['photos'] as $index => $photo)
                            <li>
                                <a href="{{ $photo }}" target="_blank" rel="noopener">
                                    <img src="{{ \App\Support\LegacyImageUrl::fit($photo, 200, 150) }}" alt="Photo {{ $index + 1 }} for {{ $answer->question?->title }}" loading="lazy" class="h-28 w-36 rounded object-cover">
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @endforeach
    @endif
@endsection
