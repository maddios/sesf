<?php

namespace App\Http\Controllers;

use App\Services\PayPal\IpnMailer;
use App\Services\PayPal\IpnOutcome;
use App\Services\PayPal\IpnPayload;
use App\Services\PayPal\IpnProcessor;
use App\Support\AfterResponse;
use Error;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /paypal-ipn. Empty body always. 200 once the IPN has been handled (applied, recorded,
 * duplicate, rejected, INVALID): PayPal stops re-sending on a 200. 503 when the money event could
 * not be handled (postback failed, processing crashed), so PayPal retries it later; retries are
 * safe because a (txn_id, payment_status) already applied is skipped. Mail notices go out after
 * the response (AfterResponse).
 */
class PayPalIpnController extends Controller
{
    public function handle(Request $request): Response
    {
        $declared = (int) $request->header('Content-Length', '0');
        if ($declared > IpnPayload::MAX_BYTES) {
            Log::warning('PayPal IPN ignored: body too large', ['bytes' => $declared]);

            return response('', 200);
        }

        $payload = IpnPayload::fromRequest($request);

        try {
            $processor = app(IpnProcessor::class);
            $outcome = $processor->process($payload);

            if ($outcome->mails !== []) {
                AfterResponse::run(fn () => $processor->deliver($outcome));
            }

            $retry = in_array($outcome->result, [IpnOutcome::POSTBACK_FAILED, IpnOutcome::FATAL], true);

            return response('', $retry ? 503 : 200);
        } catch (Throwable $e) {
            // Errors (TypeError, a missing IpnMailer binding...) are bugs: logged with full
            // context at critical level, never swallowed silently. 503 so PayPal retries.
            Log::critical('PayPal IPN handler crashed', $payload->logContext() + [
                'exception' => $e,
                'is_error' => $e instanceof Error,
            ]);
            $this->notifyFatal($payload, $e);

            return response('', 503);
        }
    }

    private function notifyFatal(IpnPayload $payload, Throwable $e): void
    {
        AfterResponse::run(function () use ($payload, $e): void {
            if (app()->bound(IpnMailer::class)) {
                app(IpnMailer::class)->adminFatal($payload, $e::class.': '.$e->getMessage());
            }
        });
    }
}
