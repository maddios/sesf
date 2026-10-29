@extends('waiver.layout')

@section('title', 'Thank you')

@section('content')
<div id="sesf-thankyou-loading" class="sesf-waiver-alert sesf-waiver-alert-info" role="status">Loading your confirmation…</div>

<section id="sesf-thankyou" class="sesf-waiver-alert sesf-waiver-alert-success" hidden>
    <p><strong>Thank you.</strong> Your waiver has been signed and recorded.</p>
    <p id="sesf-thankyou-show" class="sesf-waiver-show"></p>
    <p id="sesf-thankyou-ref" class="sesf-waiver-muted"></p>
    <p class="sesf-waiver-muted">Keep this screen until you are finished. If a waiver portal or checklist still shows “not signed,” refresh that page—your signature is stored on our servers.</p>
    <div id="sesf-thankyou-standalone-links">
        <p><a id="sesf-thankyou-event" href="#">Back to event page</a></p>
        @if ($standalone)
        <p class="sesf-waiver-muted">You may close this window when you are finished.</p>
        @else
        <p class="sesf-waiver-muted">You can return to the previous page or continue browsing.</p>
        @endif
    </div>
    <div id="sesf-thankyou-portal-embed" hidden>
        <p class="sesf-waiver-muted">You can close this panel with the × button at the top, or keep this open — your waiver list will refresh in a few seconds.</p>
    </div>
</section>

<div id="sesf-thankyou-error" class="sesf-waiver-alert sesf-waiver-alert-error" role="alert" hidden></div>
@endsection
