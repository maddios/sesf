@extends('emails.layouts.html')

@section('title', 'Payment received — Southeast Sharkfest')

@section('preheader', 'Your PayPal payment was received. Status: Paid.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Paid', 'kind' => 'success'])</p>
              <h1 class="sesf-h1" style="margin:0 0 20px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Thank you for your payment</h1>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $d->firstName }} {{ $d->lastName }},</p>
              <p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">Your PayPal payment was received and your status is now <strong style="color:#15803d;">Paid</strong>. Thank you again — we can't wait to see you there!</p>
@endsection
