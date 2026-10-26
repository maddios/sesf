<?php

namespace App\Support\Admin;

use Illuminate\Http\Request;

/**
 * The "logged in as" marker in the session: the id of the administrator who started the impersonation.
 * Present only while an administrator is viewing the site as a member (see ImpersonationController).
 */
final class ImpersonationSession
{
    public const KEY = 'impersonator_id';

    public static function impersonatorId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::KEY);

        return is_int($id) ? $id : null;
    }
}
