@extends('emails.layouts.html')

@section('title', 'PayPal postback error — Southeast Sharkfest')

@section('preheader')
PayPal postback failed for item {{ $d->itemNumber }}.
@endsection

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Postback failed', 'kind' => 'danger'])</p>
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">PayPal postback failed</h1>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 0px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:8px 0;">
                    @include('emails.partials.kv', ['label' => 'Error', 'value' => $errorText])
                    @include('emails.partials.kv', ['label' => 'item_number', 'value' => $d->itemNumber, 'last' => true])
                  </td>
                </tr>
              </table>
@endsection
