@extends('emails.layouts.html')

@section('title', 'Payment incomplete — Southeast Sharkfest')

@section('preheader')
We received {{ \App\Support\Money::format($d->totalReceivedCents) }} but {{ \App\Support\Money::format($d->requiredCents) }} was required. You still owe {{ \App\Support\Money::format($d->stillOwedCents) }}.
@endsection

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Incomplete', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 20px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Your payment is short</h1>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $d->firstName }} {{ $d->lastName }}, thank you for your PayPal payment. Either a system error occurred, or the requested amount was changed during checkout.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;">
                    <p style="margin:0 0 4px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Required</p>
                    <p style="margin:0;font-size:18px;font-weight:600;color:#0f172a;">{{ \App\Support\Money::format($d->requiredCents) }}</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;">
                    <p style="margin:0 0 4px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Received</p>
                    <p style="margin:0;font-size:18px;font-weight:600;color:#0f172a;">{{ \App\Support\Money::format($d->totalReceivedCents) }}</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:16px 18px;background:#fef9c3;border-radius:0 0 10px 10px;">
                    <p style="margin:0 0 4px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#a16207;">Still owed</p>
                    <p style="margin:0;font-size:20px;font-weight:700;color:#854d0e;">{{ \App\Support\Money::format($d->stillOwedCents) }}</p>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Open your billing page to submit the difference.</p>
              @include('emails.partials.button', ['url' => $d->payUrl, 'label' => 'Pay the difference'])
              <p style="margin:24px 0 0 0;font-size:13px;line-height:1.5;color:#64748b;text-align:center;">If you feel this is in error, contact one of our administrators on the <a href="{{ rtrim((string) config('app.url'), '/') }}/#/contact" style="color:#1d4ed8;text-decoration:none;">Contact Us</a> page.</p>
@endsection
