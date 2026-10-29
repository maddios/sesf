@extends('judging.layout')

@section('title', 'Judging draw')

@section('content')
    <h1 class="text-2xl font-bold text-heading">Judging draw · {{ $show->name }}</h1>

    @forelse ($groups as $name => $places)
        <section class="mt-6">
            <h2 class="text-lg font-bold text-heading">{{ $name }}</h2>
            <table class="mt-2 w-full border-collapse bg-panel text-left">
                <thead>
                    <tr class="border-b border-border-strong">
                        <th scope="col" class="p-2">Registration #</th>
                        <th scope="col" class="p-2">Owner</th>
                        <th scope="col" class="p-2">Car</th>
                        <th scope="col" class="p-2">Score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($places as $place)
                        @php($judged = $place['car'])
                        <tr class="border-b border-border">
                            <td class="p-2">{{ $judged->invoiceId }}</td>
                            <td class="p-2">{{ $judged->ownerName() }}</td>
                            <td class="p-2"><a class="text-primary underline" href="/judging_answers/{{ $judged->token() }}" target="_blank" rel="noopener">{{ $judged->carName() }}</a></td>
                            <td class="p-2">
                                @if (! $judged->isComplete())
                                    <span class="font-semibold text-danger">Incomplete</span>
                                @elseif ($place['tie'])
                                    <span class="font-semibold text-danger">{{ $judged->score }} (tie)</span>
                                @else
                                    {{ $judged->score }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p class="mt-6">No cars are entered for judging in this event.</p>
    @endforelse
@endsection
