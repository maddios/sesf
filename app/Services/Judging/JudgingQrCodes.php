<?php

namespace App\Services\Judging;

use App\Models\CarShow;
use App\Support\Judging\JudgingId;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Judging QR codes (legacy CarJudging::getQrCode and JudgingList::generateJudgingQRPDF): a PNG per
 * registration car encoding {APP_URL}/judging/{JudgingId}, and a landscape A4 PDF with one page per
 * judged car of a show.
 *
 * dompdf is locked down as in WaiverPdf: no remote loading, PHP or JavaScript, only data:// images
 * (the QR PNGs), chroot to the judging views.
 */
final class JudgingQrCodes
{
    public function __construct(private readonly JudgingScores $scores) {}

    public static function judgingUrl(int $registrationCarId): string
    {
        return rtrim((string) config('app.url'), '/').'/judging/'.JudgingId::encode($registrationCarId);
    }

    public function png(int $registrationCarId): string
    {
        return (new QRCode($this->options(outputBase64: false)))->render(self::judgingUrl($registrationCarId));
    }

    public function sheetPdf(CarShow $show): string
    {
        $qr = new QRCode($this->options(outputBase64: true));
        $pages = array_map(fn (JudgedCar $car): array => [
            'car' => $car,
            'url' => self::judgingUrl($car->id),
            'qr' => $qr->render(self::judgingUrl($car->id)),
        ], $this->scores->forShow($show->id));

        $dompdf = new Dompdf($this->pdfOptions());
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml(view('judging.qr_sheet', ['show' => $show, 'pages' => $pages])->render(), 'UTF-8');
        $dompdf->render();

        $pdf = (string) $dompdf->output();
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('PDF render failed.');
        }

        return $pdf;
    }

    private function options(bool $outputBase64): QROptions
    {
        return new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::H,
            'scale' => 6,
            'outputBase64' => $outputBase64,
        ]);
    }

    private function pdfOptions(): Options
    {
        $fontCache = storage_path('framework/cache/dompdf');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0750, true);
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('views/judging')]);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(is_dir($fontCache) ? $fontCache : $options->getFontDir());

        return $options;
    }
}
