<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ImpersonationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Log in as" a member, and the way back.
 *
 * - start (admin only): writes the audit entry FIRST, so a failed audit write means no login. It switches to the
 *   member, then stores the administrator's and the member's ids (ImpersonationSession) and regenerates the session.
 * - status: whether the session is impersonating, for the banner. Works for the member.
 * - stop: needs a marker that matches the signed-in member. It re-checks that the administrator is still an
 *   administrator, then switches back and regenerates. A marker that does not match ends the session. Logging out
 *   ends the impersonation with the session, and any other login clears the marker (ClearImpersonationOnLogin).
 *
 * Limits: administrators cannot be impersonated, nobody can impersonate themselves and a disabled account must be
 * restored first. A second impersonation cannot start from the member's session: start sits behind the admin
 * middleware, and the member is not an administrator. So impersonation never gives more than the administrator has.
 */
final class ImpersonationController extends Controller
{
    public const SESSION_KEY = ImpersonationSession::KEY;

    public function start(Request $request, int $user): JsonResponse
    {
        $admin = $request->user();
        $target = User::query()->withTrashed()->find($user) ?? abort(404, 'User not found.');

        if ($admin === null || $admin->is($target)) {
            return response()->json(['message' => 'You cannot log in as yourself.'], 422);
        }
        if ($target->trashed()) {
            return response()->json(['message' => 'Restore this account before logging in as it.'], 409);
        }
        if ($target->isAdmin()) {
            return response()->json(['message' => 'Administrator accounts cannot be impersonated.'], 403);
        }

        AuditLog::record('impersonation.start', 'user', [$target->id], [], ['impersonator_id' => $admin->id]);

        Auth::guard('web')->login($target);
        ImpersonationSession::start($request->session(), $admin, $target);
        $request->session()->regenerate();

        return response()->json(['data' => ['user_id' => $target->id]]);
    }

    public function status(Request $request): JsonResponse
    {
        $impersonatorId = ImpersonationSession::impersonatorId($request);
        $impersonator = $impersonatorId !== null ? User::query()->find($impersonatorId) : null;

        return response()->json(['data' => [
            'impersonating' => $impersonatorId !== null,
            'impersonator_name' => $impersonator?->fullName,
        ]]);
    }

    public function stop(Request $request): JsonResponse
    {
        $impersonatorId = ImpersonationSession::impersonatorId($request);
        if ($impersonatorId === null) {
            if ($request->session()->has(self::SESSION_KEY)) {
                return $this->endSession($request);
            }

            return response()->json(['message' => 'You are not logged in as another user.'], 409);
        }

        /** @var User $current */
        $current = $request->user();
        AuditLog::record('impersonation.stop', 'user', [$current->id], [], ['impersonator_id' => $impersonatorId]);

        $admin = User::query()->find($impersonatorId);
        if ($admin === null || ! $admin->isAdmin()) {
            return $this->endSession($request);
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return response()->json(['data' => ['user_id' => $admin->id]]);
    }

    private function endSession(Request $request): JsonResponse
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Your administrator session has ended. Please sign in again.'], 401);
    }
}
