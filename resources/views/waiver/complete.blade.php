@extends('waiver.layout')

@section('title', 'Waiver already on file')

@section('content')
<div class="sesf-waiver-alert sesf-waiver-alert-success" role="status">
    <p><strong>Waiver already on file.</strong> This registration has a completed waiver. If a portal still shows “not signed,” refresh that page. If you need a copy of the PDF, contact the event organizers.</p>
    <p class="sesf-waiver-show">{{ $show->name }}</p>
</div>
@endsection
