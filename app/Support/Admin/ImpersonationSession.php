<?php

namespace App\Support\Admin;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The "logged in as" marker in the session: the id of the administrator who started the impersonation and the id
 * of the member being viewed. Present only while an administrator is viewing the site as a member
 * (see ImpersonationController).
 *
 * The marker counts only while the signed-in user is that member. Every login clears it (ClearImpersonationOnLogin),
 * so a later sign-in on the same browser can never inherit it.
 */
final class ImpersonationSession
{
    public const KEY = 'impersonator_id';

    public const TARGET_KEY = 'impersonated_id';

    public static function start(Session $session, User $admin, User $target): void
    {
        $session->put([self::KEY => $admin->id, self::TARGET_KEY => $target->id]);
    }

    public static function forget(Session $session): void
    {
        $session->forget([self::KEY, self::TARGET_KEY]);
    }

    /** The administrator's id, or null when the session is not a valid impersonation of the signed-in user. */
    public static function impersonatorId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $id = $session->get(self::KEY);
        $targetId = $session->get(self::TARGET_KEY);
        if (! is_int($id) || ! is_int($targetId)) {
            return null;
        }

        return $request->user()?->getAuthIdentifier() === $targetId ? $id : null;
    }

    /**
     * Ends the web session. While impersonating, the member's remember token is left alone, so the real member stays
     * signed in on their own "remember me" devices.
     */
    public static function logout(Request $request): void
    {
        $guard = Auth::guard('web');
        if (self::impersonatorId($request) !== null) {
            $guard->logoutCurrentDevice();
        } else {
            $guard->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
