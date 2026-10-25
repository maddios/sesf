<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\CarShow;
use Illuminate\Http\Request;

/**
 * Shared query-string handling for the public event endpoints.
 */
trait ReadsEventQuery
{
    /**
     * Clamps the limit to 1..100. A missing limit uses the given default.
     */
    protected function limitFrom(Request $request, int $default): int
    {
        $validated = $request->validate(['limit' => ['nullable', 'integer']]);

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : $default;

        return min(max($limit, 1), 100);
    }

    /**
     * Reads show_id (required) and loads that show. An unknown id gives a 404 (ModelNotFoundException).
     */
    protected function showFrom(Request $request): CarShow
    {
        $validated = $request->validate(['show_id' => ['required', 'integer', 'min:1']]);

        return CarShow::query()->findOrFail((int) $validated['show_id']);
    }
}
