@extends('waiver.layout')

@section('title', 'Waiver link not found')

@section('content')
<div class="sesf-waiver-alert sesf-waiver-alert-error" role="alert">
    <p><strong>This waiver link is not valid.</strong></p>
    <p>Ask the event organizers for a new link or QR code.</p>
</div>
@endsection
