@extends('waiver.layout')

@section('title', 'Share waiver link')

@section('content')
<p><a href="{{ url('/waiver/'.$show->id) }}">← Back to waiver list</a></p>

<section id="sesf-share-root" class="sesf-waiver-form sesf-waiver-share" data-share-title="{{ 'Waiver: '.$show->name }}" data-share-text="{{ 'Please use this link to sign the waiver for '.$show->name.':' }}">
    <p class="sesf-waiver-section-title">Share link</p>
    <h1 class="sesf-waiver-event-title">For {{ $personName }}</h1>
    <p class="sesf-waiver-show">{{ $show->name }}</p>
    <p class="sesf-waiver-hint">Tap <strong>Share</strong> to send this link with Messages, Mail, or another app on your phone. Use <strong>Copy link</strong> if you prefer to paste it yourself.</p>

    <label for="sesf-share-link" class="sesf-waiver-field-label">Signing link</label>
    <textarea id="sesf-share-link" class="sesf-share-link" rows="4" readonly>{{ $signUrl }}</textarea>

    <button type="button" id="sesf-share-native" class="sesf-btn sesf-btn-primary">Share</button>
    <button type="button" id="sesf-share-copy" class="sesf-btn sesf-btn-secondary">Copy link</button>
    <p id="sesf-share-copied" class="sesf-waiver-alert sesf-waiver-alert-success" role="status" hidden>Copied to clipboard</p>
    <p id="sesf-share-copy-failed" class="sesf-waiver-alert sesf-waiver-alert-error" role="alert" hidden>Could not copy automatically — tap the box above, choose Select All, then Copy.</p>
</section>
@endsection
