@extends('emails.layouts.html')

@section('title')
Judging results — {{ $showName }}
@stop

@section('preheader')
Your judging result sheet is ready for {{ $showName }}.
@stop

@section('content')
              <h1 class="sesf-h1" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">Your judging results</h1>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#64748b;">{{ $showName }}</p>

              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $userFirst }},</p>
              <p style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:#334155;">Your judging result sheet is now available. The button below opens it directly.</p>

              @include('emails.partials.button', ['url' => $url, 'label' => 'View judging result'])
@stop
