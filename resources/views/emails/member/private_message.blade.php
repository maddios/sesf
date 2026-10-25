@extends('emails.layouts.html')

@section('title', 'New message — Southeast Sharkfest')

@section('preheader')
{{ $senderName }} sent you a private message on Southeast Sharkfest.
@stop

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.25;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">You have a new message</h1>
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hello {{ $recipientFirst }},</p>
              <p style="margin:0 0 20px 0;font-size:16px;line-height:1.6;color:#334155;"><a href="{{ $senderProfileUrl }}" style="color:#1d4ed8;font-weight:600;text-decoration:none;">{{ $senderName }}</a> sent you a private message:</p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <tr>
                  <td style="padding:18px 20px;font-size:15px;line-height:1.6;color:#0f172a;font-family:'SF Mono',Menlo,Consolas,'Liberation Mono',monospace;white-space:pre-wrap;word-break:break-word;">{{ $body }}</td>
                </tr>
              </table>

              <p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">If you no longer wish to receive messages from other members, visit your <a href="{{ $settingsUrl }}" style="color:#1d4ed8;text-decoration:none;">profile settings</a> and uncheck &quot;Receive private messages from other members&quot; under Account Preferences.</p>
@stop
