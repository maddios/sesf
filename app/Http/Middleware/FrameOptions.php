<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clickjacking protection for every web response. The waiver sign and thank-you pages run inside same-origin
 * iframes (the check-in sign dialog and the waiver portal's sign modal), so they may be framed by this site;
 * nothing else may be framed at all.
 */
final class FrameOptions
{
    /** Paths framed by this site's own pages. */
    private const SAME_ORIGIN_FRAMES = ['waiver_sign/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $sameOrigin = $request->is(...self::SAME_ORIGIN_FRAMES);

        $response->headers->set('X-Frame-Options', $sameOrigin ? 'SAMEORIGIN' : 'DENY');
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.($sameOrigin ? "'self'" : "'none'"));
        }

        return $response;
    }
}
