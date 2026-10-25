<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\DeniesRequests;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Judges and administrators pass. This checks the role only, not show assignment (see EnsureJudgeForShow).
 */
class EnsureJudgeOrAdmin
{
    use DeniesRequests;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && Gate::forUser($user)->allows('judge')) {
            return $next($request);
        }

        return $this->deny($request);
    }
}
