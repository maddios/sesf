<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lenient id parsing for the legacy gallery endpoints: a missing or non-numeric value is null,
 * so each action can answer with its own legacy status code instead of a 422.
 */
trait ReadsGalleryInput
{
    protected function intInput(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        return is_scalar($value) && preg_match('/^\d{1,10}$/', (string) $value) === 1 ? (int) $value : null;
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'Forbidden'], 403);
    }
}
