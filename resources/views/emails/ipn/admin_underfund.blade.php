@extends('emails.layouts.html')

@section('title', 'PayPal underfunded — admin')

@section('preheader')
Refund/cancel dropped user {{ $d->userId }} below the registration fee.
@endsection

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Underfunded — set to Unpaid', 'kind' => 'danger'])</p>
              <h1 class="sesf-h1" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">PayPal underfunded</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">user_id {{ $d->userId }} &nbsp;&middot;&nbsp; show_id {{ $d->showId }}</p>
              <p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#334155;">PayPal reports a cancel/deny/fail/refund for this user. The total dropped below their registration fee, so their status was set to <strong style="color:#b91c1c;">NOT paid</strong>. Verify this is correct; if not, manually adjust on the admin page (see user_id and show_id above).</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:8px 0;">
                    @include('emails.partials.kv', ['label' => 'Registration fee', 'value' => \App\Support\Money::format($d->requiredCents)])
                    @include('emails.partials.kv', ['label' => 'New total', 'value' => \App\Support\Money::format($d->totalReceivedCents), 'last' => true])
                  </td>
                </tr>
              </table>
              @include('emails.partials.raw_block', ['title' => 'PayPal contents', 'content' => $d->ipnReport])
@endsection
