<?php

namespace App\Http\Middleware;

use App\Support\Admin\AuditLog;
use App\Support\Admin\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the web group. Every successful write (non-safe method, status below 400) made while an administrator is
 * logged in as a member gets an audit entry with the administrator's id, so member-side changes are traceable.
 */
class AuditImpersonatedWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        // The marker is read before the request runs: the start request sets it, and that write is audited by the controller.
        $impersonatorId = ImpersonationSession::impersonatorId($request);
        $response = $next($request);

        if ($impersonatorId === null || $request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return $response;
        }

        $userId = $request->user()?->getAuthIdentifier();
        AuditLog::record('impersonation.write', 'user', is_int($userId) ? [$userId] : [], [], [
            'impersonator_id' => $impersonatorId,
            'status' => $response->getStatusCode(),
        ], $request);

        return $response;
    }
}
