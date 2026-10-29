@extends('emails.layouts.html')

@section('title', 'PayPal denied — admin')

@section('preheader')
PayPal cancel/deny/refund for user {{ $d->userId }} / show {{ $d->showId }}.
@endsection

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Cancel / Deny / Refund', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">PayPal transaction did not arrive</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">user_id {{ $d->userId }} &nbsp;&middot;&nbsp; show_id {{ $d->showId }}</p>
              <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#334155;">PayPal reports this user canceled, denied, failed, or requested a refund. The user's status has been left <strong style="color:#15803d;">paid</strong> because their total still exceeds the registration fee. Verify this is correct; if not, manually adjust on the admin page (see user_id and show_id above).</p>
              @include('emails.partials.raw_block', ['title' => 'PayPal contents', 'content' => $d->ipnReport])
@endsection
