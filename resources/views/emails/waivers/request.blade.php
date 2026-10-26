{{-- Waiver request email (ported from legacy emails/member/waiver_request; legacy [[[ ]]] escaping is {{ }} here). --}}
@extends('emails.layouts.html')

@section('title')
Sign your waiver — {{ $showName }}
@stop

@section('preheader')
Action required: complete your Southeast Sharkfest waiver for {{ $showName }}.
@stop

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Sign your waiver</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">{{ $showName }}</p>

              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $userFirst }},</p>
              <p style="margin:0 0 20px 0;font-size:16px;line-height:1.6;color:#334155;">This email address needs to complete the Southeast Sharkfest waiver for <strong style="color:#0f172a;">{{ $showName }}</strong>. The button below opens your personal signing link — use it to sign for yourself. Guests will receive their own link.</p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#eff6ff;border-left:3px solid #1d4ed8;border-radius:6px;">
                <tr>
                  <td style="padding:14px 18px;font-size:14px;line-height:1.5;color:#1e3a8a;">Please read the instructions on the signing page carefully to avoid delays at check-in.</td>
                </tr>
              </table>

              @include('emails.partials.button', ['url' => $url, 'label' => 'Sign waiver'])

              <p style="margin:20px 0 0 0;font-size:13px;line-height:1.5;color:#64748b;text-align:center;">No login required — this link is tied to you only.</p>
@stop
