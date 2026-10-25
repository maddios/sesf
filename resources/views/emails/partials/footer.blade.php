<?php
$__sesfHost = parse_url(rtrim((string) config('app.url'), '/'), PHP_URL_HOST) ?: 'bmwsharkfest.org';
$__sesfYear = date('Y');
?>
<tr>
  <td style="padding:24px 24px 8px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <p style="margin:0 0 12px 0;font-size:14px;line-height:1.5;color:#475569;">
      Sincerely,<br>
      <span style="color:#64748b;">Kai Xing, Nat Arnold, Eugene Sherbin</span><br>
      <span style="font-size:12px;color:#94a3b8;">…and the entire Southeast Sharkfest crew</span>
    </p>
    <p style="margin:0 0 6px 0;font-size:12px;line-height:1.5;color:#94a3b8;">
      <a href="{{ URL::to('/') }}" style="color:#1d4ed8;text-decoration:none;font-weight:600;">{{ $__sesfHost }}</a>
    </p>
    <p style="margin:0;font-size:11px;line-height:1.5;color:#94a3b8;">
      &copy; {{ $__sesfYear }} Southeast Sharkfest. All rights reserved.
    </p>
  </td>
</tr>
