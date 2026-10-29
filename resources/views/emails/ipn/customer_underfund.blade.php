@extends('emails.layouts.html')

@section('title', 'PayPal payment — Southeast Sharkfest')

@section('preheader', 'Your total submitted payments dropped below the registration fee.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Unpaid', 'kind' => 'danger'])</p>
              <h1 class="sesf-h1" style="margin:0 0 20px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Your payment didn't complete</h1>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Your PayPal payment failed for one or more reasons. Your total submitted payments ({{ \App\Support\Money::format($d->totalReceivedCents) }}) are now below the registration fee ({{ \App\Support\Money::format($d->requiredCents) }}), so your status has been set to <strong style="color:#b91c1c;">Unpaid</strong>.</p>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">If this is a mistake, please let our administrators know immediately.</p>
              <p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">If this is correct and you'd still like to attend, log in to the SESF website and follow the payment procedure as before.</p>
@endsection
