<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shared denial for the role middleware.
 *
 * - Guests: 401 JSON for JSON requests, otherwise a redirect to the SPA login.
 * - Signed-in users without the role: 403 JSON for JSON requests, otherwise a plain 403 page.
 */
trait DeniesRequests
{
    /**
     * @return JsonResponse|RedirectResponse
     */
    protected function deny(Request $request)
    {
        if ($request->user() === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect('/#/login');
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        abort(403, 'Forbidden');
    }
}
