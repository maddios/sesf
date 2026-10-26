<?php

namespace App\Http\Middleware;

use App\Support\Admin\AuditLog;
use App\Support\Admin\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias block.impersonated: refuses account-security changes (password, email, account deletion) while an
 * administrator is logged in as the member. The refusal is audited with the administrator's id.
 */
class BlockImpersonated
{
    public function handle(Request $request, Closure $next): Response
    {
        $impersonatorId = ImpersonationSession::impersonatorId($request);
        if ($impersonatorId === null) {
            return $next($request);
        }

        $userId = $request->user()?->getAuthIdentifier();
        AuditLog::record('impersonation.blocked', 'user', is_int($userId) ? [$userId] : [], [], ['impersonator_id' => $impersonatorId], $request);

        return response()->json([
            'message' => 'This change is not available while you are logged in as another user.',
        ], 403);
    }
}
