@extends('emails.layouts.html')

@section('title', 'Welcome to Southeast Sharkfest')

@section('preheader', 'Your Southeast Sharkfest account is ready.')

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 12px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Welcome to the family</h1>
              <p style="margin:0 0 20px 0;font-size:16px;line-height:1.6;color:#334155;">Your Southeast Sharkfest account has been created. We're glad to have you with us.</p>
              <p style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:#334155;">When you're ready to register for a regional gathering, head back to the website and open the <strong style="color:#0f172a;">Events</strong> tab to see what's coming up. We look forward to seeing you at one of our family reunions.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:16px 18px;">
                    <p style="margin:0 0 4px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Sign-in email</p>
                    <p style="margin:0;font-size:15px;font-weight:600;color:#0f172a;word-break:break-all;">{{ $email }}</p>
                  </td>
                </tr>
              </table>
@stop
