<?php

namespace App\Http\Controllers;

use App\Services\PayPal\IpnMailer;
use App\Services\PayPal\IpnPayload;
use App\Services\PayPal\IpnProcessor;
use App\Support\AfterResponse;
use Error;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /paypal-ipn. Always answers 200 with an empty body once the IPN has been handled, whatever
 * the outcome, because PayPal re-sends an IPN until it gets a 200 and every failure is already
 * logged and reported to the admin. Mail notices go out after the response (AfterResponse).
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
        } catch (Throwable $e) {
            // Errors (TypeError, a missing IpnMailer binding...) are bugs: logged with full
            // context at critical level, never swallowed silently. PayPal still gets its 200.
            Log::critical('PayPal IPN handler crashed', $payload->logContext() + [
                'exception' => $e,
                'is_error' => $e instanceof Error,
            ]);
            $this->notifyFatal($payload, $e);
        }

        return response('', 200);
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
