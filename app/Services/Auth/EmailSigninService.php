<?php

namespace App\Services\Auth;

use App\Mail\EmailSigninMail;
use App\Models\LoginEmailSignin;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Emailed sign-in (legacy UserAuth email-signin methods), with the hardening from the auth slice:
 * hashed token and code, a per-row failure counter, and a daily quota enforced by the server.
 *
 * Hashes: token_hash = HMAC-SHA256(url token, APP key); code_hash = HMAC-SHA256("code|".code, APP key).
 * Plain values exist only in the email and are never stored.
 */
class EmailSigninService
{
    public const TTL_MINUTES = 30;

    /** Failed code attempts allowed per request row before the row is closed. */
    public const MAX_ATTEMPTS = 5;

    public const INVALID_MESSAGE = 'The code is incorrect or has expired';

    /** Sign-in mails one user can be sent per window. Beyond it nothing is written or sent. */
    public const MAX_SIGN_IN_EMAILS_PER_USER = 3;

    public const SIGN_IN_EMAIL_WINDOW_SECONDS = 600;

    /** Seconds a code check waits for the row lock before it fails closed. */
    private const LOCK_WAIT_SECONDS = 3;

    private const LOCK_TTL_SECONDS = 5;

    /**
     * Zero-pads a generated code to six digits (e.g. 7 becomes "000007").
     */
    public static function formatCode(int $number): string
    {
        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Legacy redirect-path rule: a single absolute path of letters, digits, "_", "-" and "/",
     * with no "//" and no "..", at most 1024 characters. Anything else becomes null.
     */
    public static function normalizeRedirectPath(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $path = is_string($raw) ? trim($raw) : '';
        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        if (str_contains($path, '//') || str_contains($path, '..')) {
            return null;
        }

        if (strlen($path) > 1024) {
            return null;
        }

        if (! preg_match('#^/[A-Za-z0-9_/-]+$#', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Where a successful sign-in sends the browser (legacy redirectResponseForEmailSignin).
     */
    public static function redirectTarget(?string $redirectPath): string
    {
        if (! $redirectPath) {
            return '/';
        }

        if (preg_match('#^/waiver/[0-9]+$#', $redirectPath)) {
            return $redirectPath;
        }

        if (str_starts_with($redirectPath, '/judging/') && strlen($redirectPath) > 9) {
            return $redirectPath;
        }

        return '/#/'.ltrim($redirectPath, '/');
    }

    /**
     * Stores a new sign-in request for an existing user and emails the link and code.
     * Earlier pending rows for the user are closed first. Mail failures are logged, not thrown,
     * so the caller's response never reveals whether mail went out.
     */
    public function issue(User $user, mixed $rawRedirectPath, ?string $ip): void
    {
        // Checked first, so an over-limit request writes nothing and sends nothing.
        if (! RateLimiter::attempt(
            'esr-user:'.$user->id,
            self::MAX_SIGN_IN_EMAILS_PER_USER,
            fn () => true,
            self::SIGN_IN_EMAIL_WINDOW_SECONDS,
        )) {
            return;
        }

        LoginEmailSignin::query()
            ->where('user_id', $user->id)
            ->unused()
            ->update(['used_at' => now()]);

        $urlToken = bin2hex(random_bytes(32));
        $code = self::formatCode(random_int(0, 999999));
        $now = now();

        LoginEmailSignin::query()->create([
            'user_id' => $user->id,
            'token_hash' => $this->hashUrlToken($urlToken),
            'code_hash' => $this->hashCode($code),
            'redirect_path' => self::normalizeRedirectPath($rawRedirectPath),
            'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
            'created_at' => $now,
            'request_ip' => $ip,
        ]);

        try {
            Mail::to($user->email)->send(new EmailSigninMail(
                self::consumeUrl($urlToken),
                $code,
            ));
        } catch (\Throwable $e) {
            Log::error('Email sign-in mail failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * The absolute emailed sign-in link. Built from APP_URL, never from the request's Host header.
     */
    public static function consumeUrl(string $urlToken): string
    {
        return rtrim((string) config('app.url'), '/').'/login/email-signin/consume/'.$urlToken;
    }

    /**
     * Checks a six-digit code for the user with this email. On success the row is closed and
     * returned with its user loaded. Returns null for every failure, including an unknown email.
     *
     * The attempt is serialised per row by a cache lock, so concurrent guesses cannot all pass the
     * attempt check before any of them is counted. If the lock cannot be taken, the check fails closed.
     */
    public function consumeCode(string $email, string $digits): ?LoginEmailSignin
    {
        $user = User::query()->where('email', EmailAddress::normalize($email))->first();
        if (! $user) {
            return null;
        }

        $rowId = LoginEmailSignin::query()
            ->where('user_id', $user->id)
            ->unused()
            ->unexpired()
            ->orderByDesc('id')
            ->value('id');
        if (! $rowId) {
            return null;
        }

        try {
            return Cache::lock('signin-verify:'.$rowId, self::LOCK_TTL_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->checkCodeLocked((int) $rowId, $user, $digits));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * The part of consumeCode that runs under the row lock: reload the row, refuse it if closed or
     * over the attempt limit (without comparing), then compare and count a failure.
     */
    private function checkCodeLocked(int $rowId, User $user, string $digits): ?LoginEmailSignin
    {
        $row = LoginEmailSignin::query()
            ->whereKey($rowId)
            ->unused()
            ->unexpired()
            ->first();
        if (! $row) {
            return null;
        }

        if ($this->failedAttempts($row) >= self::MAX_ATTEMPTS) {
            $this->closeRow($row);

            return null;
        }

        if (! hash_equals($row->code_hash, $this->hashCode($digits))) {
            $this->recordFailedAttempt($row);

            return null;
        }

        $row->forceFill(['used_at' => now()])->save();
        $row->setRelation('user', $user);

        return $row;
    }

    /**
     * Consumes the emailed link. Returns the row with its user loaded, or null when the token is
     * unknown, used, expired, or belongs to a deleted user.
     */
    public function consumeToken(string $urlToken): ?LoginEmailSignin
    {
        $row = LoginEmailSignin::query()
            ->where('token_hash', $this->hashUrlToken($urlToken))
            ->unused()
            ->unexpired()
            ->first();
        if (! $row) {
            return null;
        }

        $user = $row->user;
        if (! $user) {
            return null;
        }

        $row->forceFill(['used_at' => now()])->save();
        $row->setRelation('user', $user);

        return $row;
    }

    /**
     * Counts a wrong code against the row. The counter lives in the cache (the table has no
     * column for it) and expires with the row. At MAX_ATTEMPTS the row is closed.
     */
    private function recordFailedAttempt(LoginEmailSignin $row): void
    {
        $key = $this->failureKey($row);
        $ttl = max(1, $row->expires_at->getTimestamp() - now()->getTimestamp());

        Cache::add($key, 0, $ttl);
        $failures = (int) Cache::increment($key);

        if ($failures >= self::MAX_ATTEMPTS) {
            $this->closeRow($row);
        }
    }

    private function failedAttempts(LoginEmailSignin $row): int
    {
        return (int) Cache::get($this->failureKey($row), 0);
    }

    private function closeRow(LoginEmailSignin $row): void
    {
        $row->forceFill(['used_at' => now()])->save();
        Cache::forget($this->failureKey($row));
    }

    private function failureKey(LoginEmailSignin $row): string
    {
        return 'email-signin:failures:'.$row->id;
    }

    private function hashUrlToken(string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, (string) config('app.key'));
    }

    private function hashCode(string $sixDigits): string
    {
        return hash_hmac('sha256', 'code|'.$sixDigits, (string) config('app.key'));
    }
}
