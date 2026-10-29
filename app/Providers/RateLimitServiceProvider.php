<?php

namespace App\Providers;

use App\Support\EmailAddress;
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

        // Password change: per signed-in member, or per IP for a request with no session.
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by(
            $request->user() !== null ? 'pc-user|'.$request->user()->getAuthIdentifier() : 'pc-ip|'.$request->ip(),
        ));

        RateLimiter::for('waiver-sign', fn (Request $request) => Limit::perMinute(40)->by($request->ip()));

        // PayPal IPN (new; legacy had none). PayPal posts from a handful of IPs and retries a 429,
        // so the limit is generous: it only caps floods of forged posts (each may cost a postback).
        RateLimiter::for('paypal-ipn', fn (Request $request) => Limit::perMinute(120)->by('ipn-ip|'.$request->ip()));

        RateLimiter::for('waiver-prefill', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Legacy resize URLs (new; legacy had none). A gallery page is 36 thumbnails plus covers and logos, and a
        // show's visitors can share one NAT address, so this is loose; renders have their own tighter limit
        // (LegacyImageController::RENDERS_PER_MINUTE).
        RateLimiter::for('legacy-images', fn (Request $request) => Limit::perMinute(1200)->by('li-ip|'.$request->ip()));

        // Member account writes. Keyed by the signed-in member, so one member's limit never affects another.
        // Password checks (account-info email change, delete-account): guessing protection for a hijacked session.
        RateLimiter::for('account-password-check', fn (Request $request) => [
            Limit::perMinute(6)->by('apc-min|'.self::memberKey($request)),
            Limit::perHour(20)->by('apc-hr|'.self::memberKey($request)),
        ]);

        // Other member writes that queue work or change stored data (location, private preferences, tutorial, sell-car).
        RateLimiter::for('account-write', fn (Request $request) => Limit::perMinute(30)->by('aw-user|'.self::memberKey($request)));

        // Car profile edits: a car write creates rows, so a daily cap applies as well.
        RateLimiter::for('car-write', fn (Request $request) => [
            Limit::perMinute(20)->by('cw-min|'.self::memberKey($request)),
            Limit::perDay(100)->by('cw-day|'.self::memberKey($request)),
        ]);

        // Admin writes (new): 120 a minute per admin. Applied to the whole admin-api group, so reads
        // (safe methods) are not limited here.
        RateLimiter::for('admin-write', fn (Request $request) => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(120)->by('admin-write|'.self::memberKey($request)));
    }

    /** The signed-in member's id, or the client IP for a request with no session. */
    private static function memberKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? (string) $user->getAuthIdentifier() : 'ip|'.$request->ip();
    }

    private static function emailKey(Request $request): string
    {
        return EmailAddress::normalize($request->input('email', ''));
    }
}
