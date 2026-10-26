<?php

namespace App\Http\Controllers\Waivers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waivers\SignWaiverRequest;
use App\Models\CarShow;
use App\Models\Waiver;
use App\Services\Waivers\AdhocLimitReached;
use App\Services\Waivers\InvalidSignature;
use App\Services\Waivers\WaiverAlreadySigned;
use App\Services\Waivers\WaiverNotFound;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * JSON endpoints behind the public signing pages (legacy WaiverSignController; the pages are
 * rendered elsewhere). Paths are the legacy ones; CSRF is Laravel's (X-XSRF-TOKEN, X-CSRF-TOKEN or
 * _token). Error bodies keep the legacy shape {ok: false, error}.
 *
 * Unknown tracking id: 404. Known but signed: 409. Telling those apart reveals nothing useful,
 * because a tracking id is 128 random bits and cannot be guessed; the lookup is an indexed
 * equality match, not a comparison loop, so timing does not leak prefixes either.
 */
class WaiverSignController extends Controller
{
    private const EXPIRED_SESSION = 'Your browser session ended before submit. Open the same event sign-in link again (or refresh), complete the form, and submit once.';

    private const STALE_ADHOC = 'This sign-in page is no longer valid. Open the event QR or sign link again from the start.';

    private const SERVER_ERROR = 'We could not finish saving your waiver (server error). Wait a moment, refresh this page, and tap Submit once more—if it still fails, contact the event.';

    public function __construct(private readonly WaiverSignService $waivers) {}

    public function prefillByTracking(string $trackingId): JsonResponse
    {
        $waiver = $this->waivers->findByTrackingId($trackingId);
        if ($waiver === null) {
            return $this->error('Invalid waiver link.', 404);
        }

        return $this->prefill($waiver);
    }

    public function signByTracking(SignWaiverRequest $request, string $trackingId): JsonResponse
    {
        $waiver = $this->waivers->findByTrackingId($trackingId);
        if ($waiver === null) {
            return $this->error('Invalid waiver link.', 404);
        }

        return $this->complete($request, $waiver);
    }

    public function prefillAdhoc(Request $request, int $showId): JsonResponse
    {
        $show = CarShow::query()->find($showId);
        if ($show === null) {
            return $this->error('Event not found', 404);
        }

        try {
            $waiver = $this->waivers->adhocForSession($show, $request->session(), (string) $request->ip());
        } catch (AdhocLimitReached $e) {
            return $this->error($e->getMessage(), 429)->header('Retry-After', (string) max(1, $e->retryAfterSeconds));
        }

        return $this->prefill($waiver);
    }

    public function signAdhoc(SignWaiverRequest $request, int $showId): JsonResponse
    {
        $show = CarShow::query()->find($showId);
        if ($show === null) {
            return $this->error('Event not found', 404);
        }

        $key = WaiverSignService::ADHOC_SESSION_PREFIX.$show->id;
        if (! $request->session()->has($key)) {
            return $this->error(self::EXPIRED_SESSION, 403);
        }
        $waiver = $this->waivers->adhocFromSession($show, $request->session());
        if ($waiver === null) {
            return $this->error(self::STALE_ADHOC, 403);
        }

        $response = $this->complete($request, $waiver);
        if ($response->getStatusCode() === 200) {
            $request->session()->forget($key);
        }

        return $response;
    }

    /** Data for the thank-you page of a signed waiver. */
    public function thankYou(string $trackingId): JsonResponse
    {
        $waiver = $this->waivers->findByTrackingId($trackingId);
        if ($waiver === null || ! $waiver->has_signed) {
            return $this->error('Not found.', 404);
        }

        try {
            $context = $this->waivers->context($waiver);
        } catch (WaiverNotFound $e) {
            return $this->error($e->getMessage(), 404);
        }

        return response()->json([
            'ok' => true,
            'show' => ['id' => (int) $context->show->id, 'name' => (string) $context->show->name],
            'eventUrl' => rtrim((string) config('app.url'), '/').'/'.$context->show->profileURL,
            'reference' => substr((string) $waiver->waiver_id, -8),
            'signedAt' => $waiver->signed_at?->toIso8601String(),
        ]);
    }

    private function prefill(Waiver $waiver): JsonResponse
    {
        try {
            $data = $this->waivers->prefill($waiver);
        } catch (WaiverNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (WaiverAlreadySigned) {
            return $this->error('Already signed.', 409);
        }

        return response()->json(['ok' => true] + $data + ['csrf' => csrf_token()]);
    }

    private function complete(SignWaiverRequest $request, Waiver $waiver): JsonResponse
    {
        try {
            $signed = $this->waivers->sign($waiver, $request->toPayload(), $request->ip(), $request->userAgent());
        } catch (WaiverAlreadySigned $e) {
            return $this->error($e->getMessage(), 409);
        } catch (WaiverNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (InvalidSignature $e) {
            return $this->error($e->getMessage(), 400);
        } catch (Throwable $e) {
            Log::error('Waiver sign failed', ['waiver_row' => $waiver->id, 'error' => $e->getMessage()]);

            return $this->error(self::SERVER_ERROR, 500);
        }

        $thankYou = rtrim((string) config('app.url'), '/').'/waiver_sign/thank-you/'.rawurlencode((string) $signed->getAttribute('tracking_id'));
        if ($request->input('waiver_ui_mode') === 'embedded') {
            $thankYou .= '?ui=embedded';
        }

        return response()->json(['ok' => true, 'thankYouUrl' => $thankYou]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $message], $status);
    }
}
