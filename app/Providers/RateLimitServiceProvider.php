<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters. Limits mirror the legacy per-IP-per-minute filters (app/filters.php:
 * throttle_portal_login 25, throttle_email_signin_request 15, throttle_email_signin_verify 30,
 * throttle_waiver_sign 40, throttle_waiver_prefill 120). Password login, forgot/reset password and
 * per-email limits are new: the legacy app had none.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Keyed by email + IP so one attacker cannot lock a victim out from other addresses,
        // plus a looser per-IP ceiling against credential stuffing across many emails.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-email|'.self::emailKey($request).'|'.$request->ip()),
            Limit::perMinute(60)->by('login-ip|'.$request->ip()),
        ]);

        RateLimiter::for('portal-login', fn (Request $request) => Limit::perMinute(25)->by($request->ip()));

        RateLimiter::for('email-signin-request', fn (Request $request) => [
            Limit::perMinute(15)->by('esr-ip|'.$request->ip()),
            Limit::perMinute(5)->by('esr-email|'.self::emailKey($request)),
        ]);

        RateLimiter::for('email-signin-verify', fn (Request $request) => [
            Limit::perMinute(30)->by('esv-ip|'.$request->ip()),
            Limit::perMinute(10)->by('esv-email|'.self::emailKey($request)),
        ]);

        RateLimiter::for('password-forgot', fn (Request $request) => [
            Limit::perMinute(6)->by('pf-ip|'.$request->ip()),
            Limit::perMinute(3)->by('pf-email|'.self::emailKey($request)),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('waiver-sign', fn (Request $request) => Limit::perMinute(40)->by($request->ip()));

        RateLimiter::for('waiver-prefill', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }

    private static function emailKey(Request $request): string
    {
        return mb_strtolower(trim((string) $request->input('email', '')));
    }
}
