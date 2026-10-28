@extends('waiver.layout')

@section('title', 'Waiver status')

@section('content')
<h1 class="sesf-waiver-event-title">Waiver status</h1>

<div id="sesf-portal-status-msg" class="sesf-waiver-alert" role="alert" hidden></div>
<div id="sesf-portal-loading" class="sesf-waiver-alert sesf-waiver-alert-info" role="status">Loading…</div>

<section id="sesf-portal-login" class="sesf-waiver-form" aria-labelledby="sesf-portal-login-title" hidden>
    <h2 id="sesf-portal-login-title" class="sesf-waiver-section-title">Sign in</h2>
    <ul class="sesf-portal-tabs" role="tablist">
        <li role="presentation">
            <button type="button" id="sesf-tab-password" class="sesf-btn sesf-btn-secondary" role="tab" aria-selected="true" aria-controls="sesf-panel-password">Password</button>
        </li>
        <li role="presentation">
            <button type="button" id="sesf-tab-email" class="sesf-btn sesf-btn-secondary" role="tab" aria-selected="false" aria-controls="sesf-panel-email">Email</button>
        </li>
    </ul>

    <div id="sesf-panel-password" role="tabpanel" aria-labelledby="sesf-tab-password">
        <form id="sesf-portal-login-form" novalidate>
            <div class="sesf-waiver-field">
                <label for="sesf-portal-email">Email address</label>
                <input id="sesf-portal-email" name="email" type="email" maxlength="128" autocomplete="username" required>
            </div>
            <div class="sesf-waiver-field">
                <label for="sesf-portal-password">Password</label>
                <input id="sesf-portal-password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <div class="sesf-waiver-minor">
                <label><input id="sesf-portal-remember" type="checkbox" name="remember" value="1"> Remember me</label>
            </div>
            <button type="submit" id="sesf-portal-login-submit" class="sesf-btn sesf-btn-primary">Sign in</button>
        </form>
    </div>

    <div id="sesf-panel-email" role="tabpanel" aria-labelledby="sesf-tab-email" hidden>
        <form id="sesf-portal-email-step1" novalidate>
            <div class="sesf-waiver-field">
                <label for="sesf-portal-email-addr">Email address</label>
                <input id="sesf-portal-email-addr" type="email" maxlength="128" autocomplete="username" required>
            </div>
            <p class="sesf-waiver-hint">We will send a 6-digit code and a sign-in button.</p>
            <button type="submit" id="sesf-portal-email-send" class="sesf-btn sesf-btn-primary">Email me a sign-in code</button>
        </form>
        <form id="sesf-portal-email-step2" novalidate hidden>
            <ol class="sesf-waiver-hint">
                <li>Open the email we just sent.</li>
                <li>Type the 6 numbers below.</li>
            </ol>
            <div class="sesf-waiver-field">
                <label for="sesf-portal-otp">Your code</label>
                <input id="sesf-portal-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="000000">
            </div>
            <button type="submit" id="sesf-portal-email-continue" class="sesf-btn sesf-btn-primary">Continue</button>
            <p class="sesf-waiver-hint">You can also tap the sign-in button in that email.</p>
            <p><button type="button" id="sesf-portal-email-restart" class="sesf-btn-link">Use a different email</button></p>
        </form>
    </div>
</section>

<section id="sesf-portal-list" class="sesf-waiver-form" aria-labelledby="sesf-portal-list-title" hidden>
    <p id="sesf-portal-signed-in" class="sesf-waiver-muted" hidden></p>
    <h2 id="sesf-portal-list-title" class="sesf-waiver-section-title">Waiver status</h2>
    <ul id="sesf-portal-people" class="sesf-waiver-people"></ul>
    <button type="button" id="sesf-portal-logout" class="sesf-btn sesf-btn-secondary">Log out</button>
</section>

<dialog id="sesf-waiver-modal" class="sesf-sig-modal sesf-waiver-modal" aria-labelledby="sesf-waiver-modal-title">
    <div class="sesf-sig-modal-header">
        <h2 id="sesf-waiver-modal-title"></h2>
        <button type="button" id="sesf-waiver-modal-close" class="sesf-btn sesf-btn-secondary" aria-label="Close waiver">&times;</button>
    </div>
    <iframe id="sesf-waiver-modal-iframe" class="sesf-waiver-modal-iframe" title="Waiver form"></iframe>
    <p id="sesf-waiver-modal-foot" class="sesf-waiver-alert sesf-waiver-alert-info" role="status" hidden></p>
</dialog>
@endsection
