@extends('emails.layouts.html')

@section('title', 'PayPal invalid invoice — admin')

@section('preheader', 'PayPal payment received but could not be processed automatically.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Invoice mismatch', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">PayPal invoice could not be matched</h1>
              <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#334155;">A PayPal payment was received but couldn't be processed automatically. The user id (invoice #) assigned at Pay-time may have changed, or another error occurred. Please verify the transaction and set the user's status to PAID if necessary.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:8px 0;">
                    @include('emails.partials.kv', ['label' => 'item_number', 'value' => $d->itemNumber, 'last' => true])
                  </td>
                </tr>
              </table>
              @include('emails.partials.raw_block', ['title' => 'PayPal report', 'content' => $d->ipnReport])
@endsection
