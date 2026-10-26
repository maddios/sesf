{{--
    Waiver PDF (port of legacy WaiverLegalDocument::pdfHtml). Rendered by App\Services\Waivers\WaiverPdf
    with dompdf: remote resources, PHP and JavaScript are disabled, and only data: URIs load.

    Trusted HTML (built by LegalDocument from our own data): $css, $legalPrefix, $liability.
    Everything the signer entered is escaped here with {{ }}: $firstName, $lastName, $email,
    $guardianName, $typedText. $signatureDataUri is a data:image/png;base64 URI of a re-encoded PNG
    (base64 alphabet only), or null.
--}}
<!DOCTYPE html>
<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:9pt;line-height:1.25;padding-bottom:40pt;}.sesf-waiver-title{text-align:center;}
.sesf-waiver-pdf-break-before-liability{page-break-before:always;height:0;margin:0;padding:0;overflow:hidden;line-height:0;font-size:0;}
.sesf-waiver-printed-label{margin:0.5em 0 0;}
{!! $css !!}</style></head><body>
{!! $legalPrefix !!}
<div class="sesf-waiver-pdf-break-before-liability"></div>
<div class="sesf-waiver-pdf-sign-page">
{!! $liability !!}
@if ($paperBlankForm)
<p style="margin:0.5em 0 0.4em;line-height:1.4;">Enter the participant's name below (if the participant is a minor, enter the minor's name). If the individual listed above is a minor (under 18), parent or guardian must sign below and enter parent/guardian name where indicated.</p>
@include('waivers.pdf._name_fields', ['heading' => 'Printed name (participant):', 'first' => '', 'last' => ''])
<p style="margin:0.55em 0 0.35em;line-height:1.35;">The individual named above as participant is a minor (under 18) — I am the parent or guardian.</p>
<p style="margin:0 0 0.35em;line-height:1.35;"><strong>If the participant is a minor (under 18), parent or guardian: print your name in the lines below, then sign in the signature area (same person).</strong></p>
<p style="margin:0 0 0.4em;font-size:8pt;line-height:1.35;">If the participant is an adult, leave the parent/guardian lines blank.</p>
@include('waivers.pdf._name_fields', ['heading' => 'Printed name (parent/guardian):', 'first' => '', 'last' => ''])
<div style="margin-top:1em;">
<div style="display:table;width:100%;table-layout:fixed;margin-top:0.5in;">
<div style="display:table-row;">
<div style="display:table-cell;width:64%;padding-right:12px;vertical-align:bottom;">
<div style="border-bottom:1px solid #000;min-height:56px;"></div>
<div style="text-align:center;margin:4px 0 0;font-size:8pt;">Signature</div>
</div>
<div style="display:table-cell;width:36%;vertical-align:bottom;">
<div style="border-bottom:1px solid #000;min-height:28px;"></div>
<div style="text-align:center;margin:4px 0 0;font-size:8pt;">Date</div>
</div>
</div></div>
</div>
@else
@include('waivers.pdf._name_fields', ['heading' => 'Printed name (participant):', 'first' => $firstName, 'last' => $lastName])
@if ($guardianName !== '')
<p style="margin:0.35em 0 0.2em;"><strong>Parent/guardian name:</strong> {{ $guardianName }}</p>
@endif
@if ($email !== '')
<p style="margin:0.25em 0 0.2em;"><strong>Email:</strong> {{ $email }}</p>
@endif
@if ($signatureDataUri !== null)
<div class="sesf-waiver-pdf-sig-block" style="margin:0.25em 0 0;padding:0;line-height:1.2;">
<strong>Signature:</strong><br/>
<img src="{{ $signatureDataUri }}" style="max-width:360px;height:auto;" alt="Signature"/>
@if ($typedText !== '')
<div style="margin:2px 0 0;padding:0;font-size:8pt;">(typed signature: {{ $typedText }})</div>
@endif
<div style="margin:4px 0 0;padding:0;"><strong>Signed electronically:</strong> {{ $signedAtText }}</div>
</div>
@else
@if ($signatureNote !== '')
<div style="margin:0.3em 0 0;padding:0;line-height:1.2;font-size:9pt;"><strong>Signature:</strong> {{ $signatureNote }}</div>
@endif
@if ($signedAtText !== '')
<div style="margin:0.3em 0 0;padding:0;line-height:1.2;font-size:9pt;"><strong>Signed electronically:</strong> {{ $signedAtText }}</div>
@endif
@endif
@endif
</div>
</div>
</body></html>
