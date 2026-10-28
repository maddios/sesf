@extends('emails.layouts.html')

@section('title')
Complete your payment — {{ $showName }}
@stop

@section('preheader')
Pay your registration fee for {{ $showName }} (Registration #{{ $invoiceId }}).
@stop

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Complete your payment</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">{{ $showName }} &nbsp;&middot;&nbsp; Registration #{{ $invoiceId }}</p>

              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $userFirst }},</p>
              <p style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:#334155;">A registration was created for you for <strong style="color:#0f172a;">{{ $showName }}</strong>. Use the button below to open your account billing page and pay the registration fee.</p>

              @include('emails.partials.button', ['url' => $payUrl, 'label' => 'Pay registration fee'])

              <p style="margin:20px 0 0 0;font-size:13px;line-height:1.5;color:#64748b;text-align:center;">Sign in with your Southeast Sharkfest account if prompted.</p>
@stop
