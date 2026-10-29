<?php
$__sesfBtnLabel = isset($label) ? (string) $label : 'Open';
$__sesfBtnUrl = isset($url) ? (string) $url : '#';
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px auto 4px;">
  <tr>
    <td align="center" bgcolor="#1d4ed8" style="border-radius:8px;background:#1d4ed8;">
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $__sesfBtnUrl }}" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="17%" stroke="f" fillcolor="#1d4ed8">
        <w:anchorlock/>
        <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;">{{ $__sesfBtnLabel }}</center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-- -->
      <a href="{{ $__sesfBtnUrl }}" class="sesf-button-link" style="background:#1d4ed8;color:#ffffff !important;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:16px;font-weight:600;line-height:1;padding:16px 32px;text-decoration:none;border-radius:8px;mso-hide:all;">{{ $__sesfBtnLabel }}</a>
      <!--<![endif]-->
    </td>
  </tr>
</table>
