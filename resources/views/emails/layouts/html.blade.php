{{--
    Shared email layout (ported from the legacy emails/layouts/html).

    Usage in a template: extend this layout, then set the sections title, preheader and content.
    The title is the <title>, the preheader is the hidden inbox preview line, and content is the body.

    Partials, included with the data they need as the second argument of the include:
      emails.partials.button     url, label
      emails.partials.badge      text, kind (success, info, warning, danger or neutral)
      emails.partials.kv         label, value, and optionally last (true drops the bottom border)
      emails.partials.raw_block  title, content (monospace, pre-wrap)
    The header and footer are included here and need no data. The logo is
    config('sesf.email_logo_url') when set, otherwise config('sesf.email_logo_path').

    Every value is escaped. Only HTML built by our own code may bypass escaping.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
  <title>@yield('title', 'Southeast Sharkfest')</title>
  <!--[if mso]>
  <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
  </xml>
  <style type="text/css">
    body, table, td, p, a { font-family: Arial, Helvetica, sans-serif !important; }
  </style>
  <![endif]-->
  <style type="text/css">
    body { margin:0 !important; padding:0 !important; width:100% !important; }
    a { text-decoration:none; }
    img { -ms-interpolation-mode:bicubic; }
    @media only screen and (max-width: 600px) {
      .sesf-card { border-radius:0 !important; border-left:0 !important; border-right:0 !important; }
      .sesf-pad { padding:24px 22px 28px !important; }
      .sesf-header-pad { padding:16px 20px !important; }
      .sesf-h1 { font-size:22px !important; line-height:1.25 !important; }
      .sesf-button a { display:block !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased;color:#334155;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:#f1f5f9;">@yield('preheader', 'Southeast Sharkfest')</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f1f5f9;">
    <tr>
      <td align="center" style="padding:32px 12px;">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">
          <tr>
            <td class="sesf-card" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,0.04),0 8px 24px rgba(15,23,42,0.06);overflow:hidden;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                @include('emails.partials.header')
                <tr>
                  <td class="sesf-pad" style="padding:32px 36px 36px;font-size:16px;line-height:1.6;color:#334155;">
                    @yield('content')
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          @include('emails.partials.footer')
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
