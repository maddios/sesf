<?php

namespace App\Http\Controllers\Waivers;

use App\Http\Controllers\Controller;
use App\Models\CarShow;
use App\Services\Waivers\LegalDocument;
use App\Services\Waivers\WaiverNotFound;
use App\Services\Waivers\WaiverPdf;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HTML pages for the waiver flows (Blade shells). The widget in resources/js/waiver reads the
 * page's own path and calls the JSON endpoints in routes/web/waivers.php, so no secret (tracking
 * id, token) is written into the page.
 */
class WaiverPageController extends Controller
{
    public function __construct(private readonly WaiverSignService $waivers) {}

    public function signByTracking(string $trackingId): Response
    {
        $waiver = $this->waivers->findByTrackingId($trackingId);
        if ($waiver === null) {
            return $this->notFound();
        }

        try {
            $show = $this->waivers->context($waiver)->show;
        } catch (WaiverNotFound) {
            return $this->notFound();
        }

        if ($waiver->has_signed) {
            return $this->page('waiver.complete', 'complete', ['show' => $show]);
        }

        return $this->signPage($show);
    }

    public function signAdhoc(int $showId): Response
    {
        $show = CarShow::query()->find($showId);

        return $show === null ? $this->notFound() : $this->signPage($show);
    }

    public function thankYou(Request $request, string $trackingId): Response
    {
        $waiver = $this->waivers->findByTrackingId($trackingId);
        if ($waiver === null || ! $waiver->has_signed) {
            return $this->notFound();
        }

        return $this->page('waiver.thank-you', 'thank-you', ['standalone' => $request->query('ui') !== 'embedded']);
    }

    public function portal(int $showId): Response
    {
        $show = CarShow::query()->find($showId);
        if ($show === null) {
            return $this->notFound();
        }

        return $this->page('waiver.portal', 'portal', ['show' => $show]);
    }

    private function signPage(CarShow $show): Response
    {
        return $this->page('waiver.sign', 'sign', [
            'show' => $show,
            'legalHtml' => LegalDocument::htmlBody($show, WaiverPdf::healthNoticeEmail()),
            'legalCss' => LegalDocument::clauseStylesCss(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, string $page, array $data = []): Response
    {
        return response()->view($view, ['config' => ['page' => $page]] + $data);
    }

    private function notFound(): Response
    {
        return response()->view('waiver.not-found', ['config' => ['page' => 'not-found']], 404);
    }
}
