@extends('emails.layouts.html')

@section('title', 'Site feedback — Southeast Sharkfest')

@section('preheader')
{{ $senderName }} sent a message via the website.
@stop

@section('content')
              <p style="margin:0 0 4px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Site feedback</p>
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.25;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">New message from {{ $senderName }}</h1>

              <p style="margin:0 0 20px 0;font-size:14px;line-height:1.5;color:#64748b;">
                @if ($senderProfileUrl)
                <a href="{{ $senderProfileUrl }}" style="color:#1d4ed8;text-decoration:none;">View profile</a> &nbsp;&middot;&nbsp;
                @endif
                <a href="mailto:{{ $senderEmail }}" style="color:#1d4ed8;text-decoration:none;">{{ $senderEmail }}</a>
              </p>

              <p style="margin:0 0 8px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Subject</p>
              <p style="margin:0 0 20px 0;font-size:14px;line-height:1.5;color:#0f172a;">{{ $messageSubject }}</p>

              <p style="margin:0 0 8px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Message</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <tr>
                  <td style="padding:18px 20px;font-size:15px;line-height:1.6;color:#0f172a;white-space:pre-wrap;word-break:break-word;">{{ $body }}</td>
                </tr>
              </table>

              @if ($location)
              <p style="margin:0 0 8px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">On page</p>
              <p style="margin:0;font-size:14px;line-height:1.5;color:#0f172a;font-family:'SF Mono',Menlo,Consolas,'Liberation Mono',monospace;word-break:break-all;">{{ $location }}</p>
              @endif
@stop
