<?php
$__sesfKvLast = isset($last) && $last;
$__sesfKvBorder = $__sesfKvLast ? 'none' : '1px solid #e2e8f0';
?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
  <tr>
    <td style="padding:10px 18px;border-bottom:{{ $__sesfKvBorder }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
          <td width="40%" style="font-size:12px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#64748b;vertical-align:top;">{{ $label }}</td>
          <td style="font-size:14px;color:#0f172a;font-family:'SF Mono',Menlo,Consolas,'Liberation Mono',monospace;word-break:break-all;vertical-align:top;">{{ $value }}</td>
        </tr>
      </table>
    </td>
  </tr>
</table>
