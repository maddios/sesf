<?php

namespace App\Http\Controllers;

use App\Services\Images\LegacyImages;
use App\Services\Images\LegacyImageSource;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy JitImage URLs: GET /images/{mode}/{w}/{h}[/{g}]/{source}. Public, as in legacy, and outside the
 * web middleware group (no session or cookies). Unknown sizes, bad paths and missing or undecodable
 * sources are 404. Cache misses are rendered under a separate, tighter per-IP limit than cache hits.
 */
class LegacyImageController extends Controller
{
    public const RENDERS_PER_MINUTE = 240;

    /** Versioned URLs (imageURL carries ?{mtime}) can be cached for a year; bare legacy URLs for a day. */
    private const MAX_AGE_VERSIONED = 31536000;

    private const MAX_AGE_UNVERSIONED = 86400;

    public function __construct(private readonly LegacyImages $images) {}

    public function __invoke(Request $request, string $spec): Response
    {
        $parsed = $this->images->parse($spec) ?? abort(404);
        $source = $this->images->locate($parsed->source) ?? abort(404);

        $key = $this->images->cacheKey($parsed, $source);
        $headers = $this->headers($request, $source);

        $notModified = new Response('', 200, $headers);
        $this->validators($notModified, $key, $source);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $path = $this->images->cachePath($key, $source);
        if (! is_file($path)) {
            $this->throttleRender($request);
            if (! $this->images->render($parsed, $source, $path)) {
                abort(404);
            }
        }

        $response = new BinaryFileResponse($path, 200, $headers, true, null, false, false);
        $this->validators($response, $key, $source);

        return $response;
    }

    /** @return array<string, string> */
    private function headers(Request $request, LegacyImageSource $source): array
    {
        $maxAge = $request->getQueryString() === null ? self::MAX_AGE_UNVERSIONED : self::MAX_AGE_VERSIONED;

        return [
            'Content-Type' => $source->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age='.$maxAge,
        ];
    }

    private function validators(Response $response, string $key, LegacyImageSource $source): void
    {
        $response->setEtag($key);
        $response->setLastModified(new DateTimeImmutable('@'.$source->mtime));
    }

    private function throttleRender(Request $request): void
    {
        $limiterKey = 'legacy-image-render|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, self::RENDERS_PER_MINUTE)) {
            abort(429, '', ['Retry-After' => (string) RateLimiter::availableIn($limiterKey)]);
        }

        RateLimiter::hit($limiterKey, 60);
    }
}
