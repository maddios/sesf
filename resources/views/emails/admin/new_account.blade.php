@extends('emails.layouts.html')

@section('title', 'New member registration')

@section('preheader')
{{ $user->first }} {{ $user->last }} just registered.
@stop

@section('content')
              <p style="margin:0 0 4px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">New registration</p>
              <h1 class="sesf-h1" style="margin:0 0 24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.25;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">{{ $user->first }} {{ $user->last }}</h1>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:8px 0;">
                    @include('emails.partials.kv', ['label' => 'ID', 'value' => $user->id])
                    @include('emails.partials.kv', ['label' => 'Email', 'value' => $user->email])
                    @include('emails.partials.kv', ['label' => 'Address 1', 'value' => $user->address1])
                    @include('emails.partials.kv', ['label' => 'Address 2', 'value' => $user->address2])
                    @include('emails.partials.kv', ['label' => 'City', 'value' => $user->city])
                    @include('emails.partials.kv', ['label' => 'State', 'value' => $user->state])
                    @include('emails.partials.kv', ['label' => 'ZIP', 'value' => $user->zip, 'last' => true])
                  </td>
                </tr>
              </table>
@stop
