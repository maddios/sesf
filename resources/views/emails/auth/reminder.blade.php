@extends('emails.layouts.html')

@section('title', 'Reset your password — Southeast Sharkfest')

@section('preheader', 'A password reset was requested for your Southeast Sharkfest account.')

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Reset your password</h1>
              <p style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:#334155;">A password reset was requested for your Southeast Sharkfest account. Click the button below to choose a new password.</p>

              @include('emails.partials.button', ['url' => $resetUrl, 'label' => 'Reset password'])

              <p style="margin:24px 0 8px 0;font-size:13px;line-height:1.5;color:#64748b;">If the button doesn't work, copy and paste this link into your browser:</p>
              <p style="margin:0;font-size:13px;line-height:1.5;color:#1d4ed8;word-break:break-all;"><a href="{{ $resetUrl }}" style="color:#1d4ed8;text-decoration:none;">{{ $resetUrl }}</a></p>
              <p style="margin:18px 0 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">If you didn't request this, you can safely ignore this email.</p>
@stop
