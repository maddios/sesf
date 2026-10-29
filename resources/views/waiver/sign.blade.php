@extends('waiver.layout')

@section('title', 'Sign waiver')

@section('head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Allura&display=swap" rel="stylesheet">
    <style>{!! $legalCss !!}</style>
@endsection

@section('content')
<h1 class="sesf-waiver-event-title">{{ $show->name }}</h1>

<div class="sesf-waiver-legal-wrap sesf-waiver-legal" role="region" aria-label="Waiver and release">
    {!! $legalHtml !!}
</div>

<div id="sesf-waiver-status" class="sesf-waiver-alert sesf-waiver-alert-error" role="alert" hidden></div>
<div id="sesf-waiver-recovery" class="sesf-waiver-alert sesf-waiver-alert-info" role="status" hidden></div>

<form id="sesf-waiver-form" class="sesf-waiver-form" novalidate>
    <fieldset class="sesf-form-section sesf-waiver-names">
        <legend>Participant</legend>
        <p class="sesf-waiver-hint" id="sesf-name-hint">Enter the participant's name. If the participant is a minor (under 18), enter the minor's name here and the parent or guardian below.</p>
        <div class="sesf-waiver-row">
            <div class="sesf-waiver-field">
                <label for="sesf-first-name">First name</label>
                <input id="sesf-first-name" name="first_name" type="text" maxlength="60" autocomplete="given-name" required aria-describedby="sesf-name-hint">
            </div>
            <div class="sesf-waiver-field">
                <label for="sesf-last-name">Last name</label>
                <input id="sesf-last-name" name="last_name" type="text" maxlength="60" autocomplete="family-name" required aria-describedby="sesf-name-hint">
            </div>
        </div>
        <div class="sesf-waiver-field">
            <label for="sesf-email">Email <span class="sesf-waiver-muted">(optional)</span></label>
            <input id="sesf-email" name="email" type="email" maxlength="128" autocomplete="email">
        </div>
    </fieldset>

    <div class="sesf-waiver-minor">
        <label>
            <input id="sesf-signing-for-minor" type="checkbox" aria-controls="sesf-adult-block">
            The individual named above is a minor (under 18) — I am the parent or guardian
        </label>
    </div>

    <fieldset id="sesf-adult-block" class="sesf-adult-block" hidden>
        <legend>Parent or guardian</legend>
        <p class="sesf-waiver-hint" id="sesf-adult-hint">If the individual listed on this waiver above is a minor (under 18), parent or guardian please sign below and enter parent/guardian name here. Same person who signs in the signature box.</p>
        <div class="sesf-waiver-row">
            <div class="sesf-waiver-field">
                <label for="sesf-adult-first">Parent or guardian first name</label>
                <input id="sesf-adult-first" name="adult_first_name" type="text" maxlength="60" autocomplete="off" aria-describedby="sesf-adult-hint">
            </div>
            <div class="sesf-waiver-field">
                <label for="sesf-adult-last">Parent or guardian last name</label>
                <input id="sesf-adult-last" name="adult_last_name" type="text" maxlength="60" autocomplete="off" aria-describedby="sesf-adult-hint">
            </div>
        </div>
    </fieldset>

    <div class="sesf-waiver-sig">
        <p id="sesf-sig-caption-text" class="sesf-waiver-sig-caption"><strong>Signature:</strong></p>
        <div id="sesf-sig-preview-wrap" class="sesf-waiver-sig-preview-wrap">
            <button type="button" id="sesf-open-sig" class="sesf-btn sesf-btn-secondary sesf-open-sig">Tap to sign</button>
            <div id="sesf-sig-preview" class="sesf-sig-preview">
                <img id="sesf-sig-preview-img" alt="Your signature" hidden>
            </div>
                    </div>
    </div>

    <p class="sesf-waiver-hint sesf-waiver-submit-hint">After you tap <strong>Submit signed waiver</strong>, keep this page open until you see the green thank-you message. Closing too early can interrupt saving.</p>
    <button type="submit" id="sesf-waiver-submit" class="sesf-btn sesf-btn-primary" disabled>Submit signed waiver</button>
</form>

<dialog id="sesf-sig-modal" class="sesf-sig-modal" aria-labelledby="sesf-sig-modal-title">
    <div class="sesf-sig-modal-header">
        <h2 id="sesf-sig-modal-title">Sign here</h2>
        <button type="button" id="sesf-sig-close" class="sesf-btn sesf-btn-secondary" aria-label="Close signature box">&times;</button>
    </div>
    <div class="sesf-sig-modal-body">
        <div class="sesf-sig-mode-bar" role="group" aria-label="Signature method">
            <button type="button" id="sesf-modal-mode-draw" class="sesf-btn sesf-btn-secondary" aria-pressed="true">Draw</button>
            <button type="button" id="sesf-modal-mode-type" class="sesf-btn sesf-btn-secondary" aria-pressed="false">Type</button>
        </div>
        <div id="sesf-sig-modal-msg" class="sesf-waiver-alert sesf-waiver-alert-error" role="alert" hidden></div>

        <div id="sesf-sig-draw-block">
            <p class="sesf-waiver-hint">Use your finger or mouse in the box below.</p>
            <canvas id="sesf-sig-canvas" class="sesf-sig-canvas" role="img" aria-label="Signature box"></canvas>
        </div>

        <div id="sesf-sig-type-block" hidden>
            <div class="sesf-type-preview">
                <p id="sesf-type-preview-placeholder" class="sesf-waiver-muted">Your signature preview appears here</p>
                <img id="sesf-type-preview-img" class="sesf-type-preview-img" alt="Typed signature preview" hidden>
            </div>
            <div class="sesf-sig-modeline">
                <label for="sesf-sig-type-text" class="sesf-waiver-type-label">Type your name</label>
                <button type="button" id="sesf-modal-switch-draw" class="sesf-btn-link">Draw with finger instead →</button>
            </div>
            <textarea id="sesf-sig-type-text" class="sesf-sig-type-textarea" rows="2" maxlength="80" autocomplete="off" placeholder="Type your name"></textarea>
        </div>
    </div>
    <div class="sesf-sig-modal-footer">
        <button type="button" id="sesf-sig-clear" class="sesf-btn sesf-btn-secondary">Clear</button>
        <button type="button" id="sesf-sig-cancel" class="sesf-btn sesf-btn-secondary">Cancel</button>
        <button type="button" id="sesf-sig-done" class="sesf-btn sesf-btn-primary">Done</button>
    </div>
</dialog>
@endsection
