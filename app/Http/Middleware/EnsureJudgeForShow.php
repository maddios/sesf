<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\DeniesRequests;
use App\Models\CarShow;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins pass. A judge passes only when assigned to the show (judge-for-show gate).
 *
 * The show id comes only from the route parameter `show` (a bound CarShow or a numeric id), or
 * `show_id` when there is no `show`. Query and body input are never read, so a request cannot
 * choose the show it is checked against. A route without such a parameter, or a missing or
 * non-numeric id, is denied.
 */
class EnsureJudgeForShow
{
    use DeniesRequests;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $showId = $this->showId($request);

        if ($user instanceof User && $showId !== null && Gate::forUser($user)->allows('judge-for-show', [$showId])) {
            return $next($request);
        }

        return $this->deny($request);
    }

    private function showId(Request $request): ?int
    {
        $raw = $request->route('show') ?? $request->route('show_id');

        if ($raw instanceof CarShow) {
            $raw = $raw->getKey();
        }

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }
}
