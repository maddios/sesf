@extends('emails.layouts.html')

@section('title', 'Payment pending — Southeast Sharkfest')

@section('preheader', 'Your PayPal payment is pending. We will confirm once PayPal completes it.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Pending', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 20px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Your payment is pending</h1>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $d->firstName }} {{ $d->lastName }},</p>
              <p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">Thank you for your PayPal payment. It arrived with a <strong>Pending</strong> status. As soon as PayPal sets it to <strong>Complete</strong>, you'll receive a confirmation email and your status will be set to <strong style="color:#15803d;">Paid</strong>.</p>
@endsection
