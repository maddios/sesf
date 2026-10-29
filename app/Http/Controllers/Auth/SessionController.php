<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Resources\Auth\LoginUserResource;
use App\Models\User;
use App\Support\Admin\ImpersonationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/**
 * Password login, session probe, logout and password change for the SPA.
 *
 * Replaces legacy UserAuth::authenticate/login/logout/updatePassword with the same URLs, verbs
 * and JSON shapes (see routes/web/login.php). Security changes from the legacy code:
 *  - the session is regenerated on login and password change, and invalidated on logout;
 *  - a deleted account is named only when the correct password was given (no enumeration);
 *  - a missing account still spends one bcrypt check, and the whole login attempt is padded to
 *    500 ms (Timebox), so success, wrong password, unknown email and deleted account look alike;
 *  - update-password only changes the signed-in member's own password (no user_id parameter);
 *  - logout redirects only to a local path.
 */
class SessionController extends Controller
{
    /** Message for a deleted account, shown only with the correct password (legacy text). */
    private const DELETED_MESSAGE = 'Your account has been deleted. Please contact an administrator to have it re-enabled.';

    /** Message for every other failed login (legacy text). */
    private const INVALID_MESSAGE = 'Either your email or password are incorrect';

    /**
     * A valid bcrypt hash (cost 12) of a random string, used to spend one bcrypt check when the
     * email matches no account. It never matches a real password.
     */
    private const DUMMY_HASH = '$2y$12$m4pEfwGO4HxhrdIbhCviOe.IhmdTJUFaF00iE9AP5AuhtBrjBAt0G';

    /** Legacy session probe: 200 with the member payload, or 401 with an empty body. */
    public function authenticate(Request $request): JsonResponse|Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return response('', 401);
        }

        return $this->loginPayload($request, $user);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return (new Timebox)->call(fn (): JsonResponse => $this->attemptLogin($request), 500_000);
    }

    private function attemptLogin(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');
        $guard = Auth::guard('web');
        $guard->setRememberDuration((int) config('auth.remember_minutes'));

        if ($guard->attempt(['email' => $email, 'password' => $password], $request->boolean('remember'))) {
            $request->session()->regenerate();

            /** @var User $user */
            $user = $guard->user();

            return $this->loginPayload($request, $user);
        }

        return response()->json([$this->failureMessage($email, $password)], 401);
    }

    /**
     * Logout accepts any verb because the legacy navigation links use GET.
     *
     * Trade-off: a GET logout can be triggered cross-site (e.g. an image tag), which only logs
     * the member out. It does not expose data or change credentials. Non-GET verbs still need the
     * CSRF token from the web middleware.
     */
    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        ImpersonationSession::logout($request);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => 'Logged out']);
        }

        $next = $request->input('redirect');
        if (is_string($next) && $this->isSafeLocalPath($next)) {
            return redirect($next);
        }

        return redirect('/#/login');
    }

    /** Changes the signed-in member's password. The member is always Auth::user(). */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->validated('old_password'), $user->password)) {
            return response()->json(['Old Password invalid'], 400);
        }

        $changed = $user->setPasswordWithConfirmation(
            (string) $request->validated('new_password'),
            (string) $request->validated('verify_password'),
        );
        if (! $changed) {
            return response()->json(['New password is not valid'], 400);
        }

        // Rotating the remember token ends every "remember me" cookie issued before this change.
        $user->setRememberToken(Str::random(60));
        $user->save();
        $request->session()->regenerate();

        return response()->json('');
    }

    /**
     * Legacy wording for every failed login. A deleted account gets its own message only when the
     * password is correct; otherwise the generic message is returned.
     */
    private function failureMessage(string $email, string $password): string
    {
        $deletedHashes = User::onlyTrashed()->where('email', $email)->pluck('password');

        if ($deletedHashes->isEmpty()) {
            // Auth::attempt() already spent a bcrypt check when the account exists (even with a
            // wrong password). Without an account, spend one here so the response time does not
            // reveal whether the email is registered.
            if (! User::where('email', $email)->exists()) {
                Hash::check($password, self::DUMMY_HASH);
            }

            return self::INVALID_MESSAGE;
        }

        foreach ($deletedHashes as $hash) {
            if (Hash::check($password, $hash)) {
                return self::DELETED_MESSAGE;
            }
        }

        return self::INVALID_MESSAGE;
    }

    private function loginPayload(Request $request, User $user): JsonResponse
    {
        return response()->json((new LoginUserResource($user))->resolve($request));
    }

    /**
     * True for a single leading slash followed by path characters only: no "//" or "/\" prefix
     * (protocol-relative or backslash host), and no whitespace or control characters that browsers
     * strip before parsing the URL.
     */
    private function isSafeLocalPath(string $path): bool
    {
        return preg_match('#\A/(?![/\\\\])[^\\\\\x00-\x20\x7f]*\z#', $path) === 1;
    }
}
