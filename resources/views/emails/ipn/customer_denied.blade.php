@extends('emails.layouts.html')

@section('title', 'PayPal payment — Southeast Sharkfest')

@section('preheader', 'A PayPal transaction did not complete, but your registration is still paid.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Status: Paid', 'kind' => 'info'])</p>
              <h1 class="sesf-h1" style="margin:0 0 20px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">A PayPal transaction didn't complete</h1>
              <p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">PayPal has notified us that a payment did not complete (canceled, denied, failed, or refunded). Your registration remains <strong style="color:#0f172a;">Paid</strong> because your total still meets the registration fee. If that doesn't look right, please contact us via the website.</p>
@endsection
