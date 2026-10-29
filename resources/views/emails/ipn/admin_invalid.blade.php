@extends('emails.layouts.html')

@section('title', 'Invalid PayPal IPN — admin')

@section('preheader')
PayPal could not validate the IPN for item {{ $d->itemNumber }}.
@endsection

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Invalid IPN', 'kind' => 'danger'])</p>
              <h1 class="sesf-h1" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Invalid IPN transaction</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">user_id {{ $d->userId }} &nbsp;&middot;&nbsp; show_id {{ $d->showId }}</p>
              <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#334155;">A transaction was received, but when confirmed with PayPal it did not match their records. This may indicate a fake payment attempt — investigate the item and check for other suspicious activity. If the payment was valid, disregard this and set paid/amount manually.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:8px 0;">
                    @include('emails.partials.kv', ['label' => 'Reason', 'value' => $reason, 'last' => true])
                  </td>
                </tr>
              </table>
              @include('emails.partials.raw_block', ['title' => 'PayPal transaction', 'content' => $d->ipnReport])
@endsection
