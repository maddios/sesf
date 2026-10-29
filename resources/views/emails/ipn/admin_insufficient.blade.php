@extends('emails.layouts.html')

@section('title', 'Insufficient PayPal payment — admin')

@section('preheader')
Insufficient PayPal payment for user {{ $d->userId }} / show {{ $d->showId }}.
@endsection

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Insufficient', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Insufficient PayPal payment</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">user_id {{ $d->userId }} &nbsp;&middot;&nbsp; show_id {{ $d->showId }}</p>
              <p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#334155;">Either the script calculated the price incorrectly, or the amount changed after the user clicked Pay. Please investigate — they remain set to <strong style="color:#b91c1c;">unpaid</strong>.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:8px 0;">
                    @include('emails.partials.kv', ['label' => 'Requested', 'value' => \App\Support\Money::format($d->requiredCents)])
                    @include('emails.partials.kv', ['label' => 'Received', 'value' => \App\Support\Money::format($d->grossCents)])
                    @include('emails.partials.kv', ['label' => 'Running total', 'value' => \App\Support\Money::format($d->totalReceivedCents), 'last' => true])
                  </td>
                </tr>
              </table>
              @include('emails.partials.raw_block', ['title' => 'PayPal contents', 'content' => $d->ipnReport])
@endsection
