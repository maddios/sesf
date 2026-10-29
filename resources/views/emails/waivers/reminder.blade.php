{{-- Waiver reminder email (ported from legacy emails/member/waiver_reminder; legacy [[[ ]]] escaping is {{ }} here). --}}
@extends('emails.layouts.html')

@section('title')
Waiver reminder — {{ $showName }}
@stop

@section('preheader')
Not everyone in your party has signed the waiver for {{ $showName }} yet.
@stop

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Waiver still needed</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">{{ $showName }}</p>

              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $userFirst }},</p>
              <p style="margin:0 0 20px 0;font-size:16px;line-height:1.6;color:#334155;">A friendly reminder: <strong style="color:#0f172a;">not everyone in your party has completed the waiver</strong> for <strong style="color:#0f172a;">{{ $showName }}</strong>. Every attendee — including guests — must sign.</p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#eff6ff;border-left:3px solid #1d4ed8;border-radius:6px;">
                <tr>
                  <td style="padding:14px 18px;font-size:14px;line-height:1.5;color:#1e3a8a;">Use the waiver portal to see who still needs to sign, copy share links, and open the signing page for each person.</td>
                </tr>
              </table>

              @include('emails.partials.button', ['url' => $portalUrl, 'label' => 'Open waiver portal'])

              <p style="margin:20px 0 0 0;font-size:13px;line-height:1.5;color:#64748b;text-align:center;">Sign in with your Southeast Sharkfest account — the same one you use to register.</p>
              <p style="margin:18px 0 0 0;font-size:12px;line-height:1.5;color:#94a3b8;text-align:center;">Sent to the primary registrant only — not to guest emails.</p>
@stop
