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
 * - start (admin only): writes the audit entry FIRST, so a failed audit write means no login. The session
 *   keeps the administrator's id under SESSION_KEY, switches to the member and is regenerated.
 * - status: whether the session is impersonating, for the banner. Works for the member.
 * - stop: needs the impersonation marker. It re-checks that the administrator is still an administrator, then
 *   switches back and regenerates. Logging out ends the impersonation with the session.
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

        $request->session()->put(self::SESSION_KEY, $admin->id);
        Auth::guard('web')->login($target);
        $request->session()->regenerate();

        return response()->json(['data' => ['user_id' => $target->id]]);
    }

    public function status(Request $request): JsonResponse
    {
        $impersonatorId = $request->session()->get(self::SESSION_KEY);
        $impersonator = is_int($impersonatorId) ? User::query()->find($impersonatorId) : null;

        return response()->json(['data' => [
            'impersonating' => is_int($impersonatorId),
            'impersonator_name' => $impersonator?->fullName,
        ]]);
    }

    public function stop(Request $request): JsonResponse
    {
        $impersonatorId = $request->session()->get(self::SESSION_KEY);
        if (! is_int($impersonatorId)) {
            return response()->json(['message' => 'You are not logged in as another user.'], 409);
        }

        /** @var User $current */
        $current = $request->user();
        AuditLog::record('impersonation.stop', 'user', [$current->id], [], ['impersonator_id' => $impersonatorId]);

        $admin = User::query()->find($impersonatorId);
        if ($admin === null || ! $admin->isAdmin()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your administrator session has ended. Please sign in again.'], 401);
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return response()->json(['data' => ['user_id' => $admin->id]]);
    }
}
