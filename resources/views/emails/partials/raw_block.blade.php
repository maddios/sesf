<?php
$__sesfRawTitle = isset($title) ? (string) $title : 'PayPal report';
$__sesfRawContent = isset($content) ? (string) $content : '';
?>
<p style="margin:0 0 8px 0;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">{{ $__sesfRawTitle }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
  <tr>
    <td style="padding:14px 16px;font-size:12px;line-height:1.55;color:#334155;font-family:'SF Mono',Menlo,Consolas,'Liberation Mono',monospace;white-space:pre-wrap;word-break:break-all;">{{ $__sesfRawContent }}</td>
  </tr>
</table>
