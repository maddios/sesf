@extends('emails.layouts.html')

@section('title', 'PayPal email mismatch — admin')

@section('preheader', 'A PayPal payment was received but the email did not match the configured PayPal email.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Email mismatch', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">PayPal email did not match</h1>
              <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#334155;">A payment was received from PayPal, but the email did not match the PayPal email supplied to the system. The complete PayPal dump follows.</p>
              @include('emails.partials.raw_block', ['title' => 'PayPal report', 'content' => $d->ipnReport])
@endsection
