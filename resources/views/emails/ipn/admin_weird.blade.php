@extends('emails.layouts.html')

@section('title', 'PayPal transaction unclear — admin')

@section('preheader', 'A PayPal transaction did not match any known case — please review.')

@section('content')
              <p style="margin:0 0 12px 0;">@include('emails.partials.badge', ['text' => 'Needs review', 'kind' => 'warning'])</p>
              <h1 class="sesf-h1" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">PayPal transaction didn't match any case</h1>
              <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#334155;">This transaction fell through all expected cases (paid / not paid / complete / incomplete). Please verify why this occurred. The complete PayPal submission follows — the ID# is the leftmost number on the admin page.</p>
              @include('emails.partials.raw_block', ['title' => 'PayPal contents', 'content' => $d->ipnReport])
@endsection
