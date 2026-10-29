<?php
/*
 * Logo source: config('sesf.email_logo_url') (env SESF_EMAIL_LOGO_URL) when set, so dev and staging
 * can point at a publicly reachable logo. Otherwise URL::to(config('sesf.email_logo_path')).
 * URL::to is used, not URL::asset(): asset() strips index.php from the root, and the logo must
 * resolve the same way from HTTP and from queued or CLI sends.
 */
$__sesfLogoSrc = config('sesf.email_logo_url');
if (! $__sesfLogoSrc) {
    $__sesfLogoSrc = URL::to((string) config('sesf.email_logo_path'));
}
?>
<tr>
  <td class="sesf-header-pad" style="background:#ffffff;padding:18px 36px;border-bottom:1px solid #eef2f7;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
      <tr>
        <td align="left" style="vertical-align:middle;">
          <a href="{{ URL::to('/') }}" style="text-decoration:none;border:0;display:inline-block;line-height:0;">
            <img src="{{ $__sesfLogoSrc }}" alt="Southeast Sharkfest" width="180" style="display:block;border:0;outline:none;height:auto;width:180px;max-width:180px;">
          </a>
        </td>
      </tr>
    </table>
  </td>
</tr>
