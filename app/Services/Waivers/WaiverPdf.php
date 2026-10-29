<?php

namespace App\Services\Waivers;

use App\Models\CarShow;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Builds the waiver PDF HTML and renders it with dompdf, and stores signed PDFs.
 *
 * dompdf is locked down: remote loading, embedded PHP and JavaScript are off, the only allowed
 * protocol is data:// (the signature image), and chroot is the PDF views directory. The legal text
 * comes from LegalDocument; signer values are escaped by the Blade template.
 *
 * Files: {config('waivers.storage_dir')}/{waiver_id}.pdf, written to a temp file in the same
 * directory and renamed into place (mode 0640), so a reader never sees a partial PDF.
 */
class WaiverPdf
{
    /** Public waiver ids: 16 hex for this app, up to 32 alphanumerics for the legacy provider rows. */
    public const WAIVER_ID_PATTERN = '/\A[A-Za-z0-9]{8,32}\z/';

    public function signedHtml(CarShow $show, SignedWaiverData $data, ?CarbonInterface $signedAt, string $signatureNote = ''): string
    {
        return $this->html($show, [
            'paperBlankForm' => false,
            'firstName' => $data->firstName,
            'lastName' => $data->lastName,
            'email' => $data->email,
            'guardianName' => $data->guardianName,
            'typedText' => $data->typedText,
            'signatureDataUri' => $data->signaturePngBase64 !== null ? 'data:image/png;base64,'.$data->signaturePngBase64 : null,
            'signatureNote' => $signatureNote,
            'signedAtText' => $signedAt !== null ? $signedAt->copy()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s T') : '',
        ]);
    }

    public function blankHtml(CarShow $show): string
    {
        return $this->html($show, [
            'paperBlankForm' => true,
            'firstName' => '',
            'lastName' => '',
            'email' => '',
            'guardianName' => '',
            'typedText' => '',
            'signatureDataUri' => null,
            'signatureNote' => '',
            'signedAtText' => '',
        ]);
    }

    /** Renders HTML to PDF bytes with a "Page N of M" footer (legacy waiverPdfBinary). */
    public function render(string $html): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper((string) config('waivers.pdf.paper', 'letter'), (string) config('waivers.pdf.orientation', 'portrait'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans') ?? $metrics->getFont('sans-serif');
        $size = 8;
        $textWidth = $font !== null ? $metrics->getTextWidth('Page 99 of 99', $font, $size) : 60.0;
        $x = max(0, $canvas->get_width() - 40 - $textWidth);
        $y = $canvas->get_height() - 32;
        $canvas->page_text($x, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, $size, [0, 0, 0]);

        $out = $dompdf->output();
        if (! str_starts_with((string) $out, '%PDF-')) {
            throw new RuntimeException('PDF render failed.');
        }

        return (string) $out;
    }

    public function path(string $waiverId): string
    {
        if (preg_match(self::WAIVER_ID_PATTERN, $waiverId) !== 1) {
            throw new RuntimeException('Invalid waiver id.');
        }

        return rtrim((string) config('waivers.storage_dir'), '/').'/'.$waiverId.'.pdf';
    }

    /** Writes the PDF atomically (temp file + rename) with mode 0640. */
    public function store(string $waiverId, string $pdf): string
    {
        $final = $this->path($waiverId);
        $dir = dirname($final);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('Waiver storage directory is missing.');
        }

        $temp = $dir.'/.'.bin2hex(random_bytes(12)).'.tmp';
        $renamed = false;
        try {
            if (@file_put_contents($temp, $pdf, LOCK_EX) !== strlen($pdf)) {
                throw new RuntimeException('Could not write the waiver PDF.');
            }
            @chmod($temp, 0640);
            if (! @rename($temp, $final)) {
                throw new RuntimeException('Could not move the waiver PDF into place.');
            }
            $renamed = true;
        } finally {
            if (! $renamed && is_file($temp)) {
                @unlink($temp);
            }
        }

        return $final;
    }

    /**
     * @param  array<string, mixed>  $signer
     */
    private function html(CarShow $show, array $signer): string
    {
        return view('waivers.pdf.document', array_merge($signer, [
            'css' => LegalDocument::clauseStylesCss(),
            'legalPrefix' => LegalDocument::pdfLegalPrefix($show, self::healthNoticeEmail(), (bool) $signer['paperBlankForm']),
            'liability' => LegalDocument::blockLiabilityMedical(),
        ]))->render();
    }

    public static function healthNoticeEmail(): string
    {
        $email = trim((string) config('sesf.waiver_health_notice_email'));

        return $email !== '' ? $email : (string) config('waivers.health_notice_email_fallback');
    }

    private function options(): Options
    {
        $fontCache = storage_path('framework/cache/dompdf');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0750, true);
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('views/waivers/pdf')]);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(is_dir($fontCache) ? $fontCache : $options->getFontDir());

        return $options;
    }
}
