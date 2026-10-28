<?php

namespace Tests\Feature;

use App\Http\Controllers\LegacyImageController;
use App\Services\Images\LegacyImageSpec;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Imagick;
use ImagickDraw;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The legacy JitImage compat route against a temp base directory laid out like public/:
 *   {base}/images/...          allowed root
 *   {base}/images/judging/...  allowed root
 *   {base}/private/...         under base, outside every root
 */
class LegacyImageRouteTest extends TestCase
{
    private string $base;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/sesf-legacy-img-'.bin2hex(random_bytes(6));
        $this->cache = $this->base.'-cache';
        foreach (['images/gallery/1/2', 'images/judging/7/3', 'private'] as $dir) {
            mkdir($this->base.'/'.$dir, 0755, true);
        }

        config([
            'sesf.legacy_images.base' => $this->base,
            'sesf.legacy_images.roots' => [$this->base.'/images/', $this->base.'/images/judging/'],
            'sesf.legacy_images.cache_dir' => $this->cache,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    #[Test]
    public function mode_1_resizes_to_the_exact_box_ignoring_the_aspect_ratio(): void
    {
        $this->source('images/a.png', self::solid(400, 300, 'png'));

        $response = $this->get('/images/1/1070/360/images/a.png');

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSize([1070, 360], $response);
    }

    #[Test]
    public function mode_1_derives_a_zero_side_from_the_aspect_ratio(): void
    {
        $this->source('images/logo.jpg', self::solid(200, 100));

        $this->assertSize([140, 70], $this->get('/images/1/0/70/images/logo.jpg'));
    }

    #[Test]
    public function mode_2_covers_the_box_and_crops_it_to_the_exact_size(): void
    {
        $this->source('images/gallery/1/2/12.jpg', self::solid(1000, 500));

        $response = $this->get('/images/2/320/230/5/images/gallery/1/2/12.jpg');

        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSize([320, 230], $response);
    }

    #[Test]
    public function mode_2_enlarges_a_small_source_to_fill_the_box(): void
    {
        $this->source('images/small.jpg', self::solid(100, 50));

        $this->assertSize([200, 140], $this->get('/images/2/200/140/5/images/small.jpg'));
    }

    /** @return array<string, array{int, string}> */
    public static function gravities(): array
    {
        return ['west' => [4, 'red'], 'northwest' => [1, 'red'], 'east' => [6, 'blue'], 'southeast' => [9, 'blue']];
    }

    #[Test]
    #[DataProvider('gravities')]
    public function mode_2_crops_at_the_gravity(int $gravity, string $colour): void
    {
        $this->source('images/split.png', self::split(800, 200));

        $response = $this->get('/images/2/200/140/'.$gravity.'/images/split.png');

        $this->assertSame($colour, $this->pixel($response, 100, 70));
    }

    #[Test]
    public function mode_4_shrinks_to_fit_inside_the_box(): void
    {
        $this->source('images/wide.jpg', self::solid(1000, 500));

        $this->assertSize([300, 150], $this->get('/images/4/300/220/images/wide.jpg'));
    }

    #[Test]
    public function mode_4_never_enlarges(): void
    {
        $this->source('images/tiny.jpg', self::solid(100, 50));

        $this->assertSize([100, 50], $this->get('/images/4/300/220/images/tiny.jpg'));
    }

    #[Test]
    public function mode_4_treats_a_zero_side_as_unconstrained(): void
    {
        $this->source('images/sponsor.jpg', self::solid(200, 100));

        $this->assertSize([120, 60], $this->get('/images/4/0/60/images/sponsor.jpg'));
    }

    #[Test]
    public function gif_sources_stay_gif(): void
    {
        $this->source('images/avatar.gif', self::solid(300, 300, 'gif'));

        $response = $this->get('/images/4/100/100/images/avatar.gif');

        $response->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertSame('image/gif', $this->info($response)['mime']);
    }

    #[Test]
    public function judging_photos_are_served_publicly_as_legacy_did(): void
    {
        $this->source('images/judging/7/3/1_a.jpg', self::solid(1600, 1200));

        $this->assertSize([200, 150], $this->get('/images/4/200/150/images/judging/7/3/1_a.jpg'));
    }

    #[Test]
    public function it_sends_caching_and_safety_headers_and_no_cookies(): void
    {
        touch($this->source('images/h.jpg', self::solid(50, 50)), 1700000000);

        $response = $this->get('/images/4/100/100/images/h.jpg');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Last-Modified', 'Tue, 14 Nov 2023 22:13:20 GMT')
            ->assertHeaderMissing('Set-Cookie');
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/\A"[0-9a-f]{64}"\z/', (string) $response->headers->get('ETag'));
    }

    #[Test]
    public function versioned_urls_are_cached_for_a_year(): void
    {
        $this->source('images/v.jpg', self::solid(50, 50));

        $response = $this->get('/images/4/100/100/images/v.jpg?1700000000');

        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function it_answers_304_to_a_matching_if_none_match_or_if_modified_since(): void
    {
        touch($this->source('images/c.jpg', self::solid(50, 50)), 1700000000);
        $etag = (string) $this->get('/images/4/100/100/images/c.jpg')->headers->get('ETag');

        $this->get('/images/4/100/100/images/c.jpg', ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
        $this->get('/images/4/100/100/images/c.jpg', ['If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT'])->assertStatus(304);
        $this->get('/images/4/100/100/images/c.jpg', ['If-None-Match' => '"stale"'])->assertOk();
    }

    #[Test]
    public function a_cache_hit_is_served_without_rendering_again(): void
    {
        $this->source('images/hit.jpg', self::solid(500, 500));
        $first = $this->get('/images/4/100/100/images/hit.jpg')->assertOk();
        $files = File::allFiles($this->cache);
        $this->assertCount(1, $files);

        file_put_contents($files[0]->getPathname(), 'served-from-cache');
        $second = $this->get('/images/4/100/100/images/hit.jpg');

        $second->assertOk();
        $this->assertSame('served-from-cache', $this->bytes($second));
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    #[Test]
    public function a_changed_source_gets_a_new_entry(): void
    {
        $file = $this->source('images/m.jpg', self::solid(500, 500));
        touch($file, 1700000000);
        $before = $this->get('/images/4/100/100/images/m.jpg')->headers->get('ETag');

        file_put_contents($file, self::solid(600, 300));
        touch($file, 1700000100);
        clearstatcache();
        $after = $this->get('/images/4/100/100/images/m.jpg');

        $this->assertNotSame($before, $after->headers->get('ETag'));
        $this->assertSize([100, 50], $after);
    }

    #[Test]
    public function a_missing_source_is_404(): void
    {
        $this->get('/images/4/300/220/images/cars/999/profile.jpg')->assertNotFound();
    }

    #[Test]
    public function a_corrupt_image_is_404_not_500_and_is_not_cached(): void
    {
        $jpeg = self::solid(64, 48);
        $this->source('images/corrupt.jpg', substr($jpeg, 0, (int) strpos($jpeg, "\xFF\xDA") + 2));

        $this->get('/images/4/300/220/images/corrupt.jpg')->assertNotFound();
        $this->assertSame([], is_dir($this->cache) ? File::allFiles($this->cache) : []);
    }

    /** @return array<string, array{string, string}> */
    public static function disallowedContent(): array
    {
        $webp = new Imagick;
        $webp->newImage(4, 4, 'red');
        $webp->setImageFormat('webp');

        return [
            'text named .jpg' => ['fake.jpg', 'not an image'],
            'svg named .png' => ['x.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'webp named .gif' => ['w.gif', $webp->getImageBlob()],
        ];
    }

    #[Test]
    #[DataProvider('disallowedContent')]
    public function content_that_is_not_an_allowed_image_is_404(string $name, string $bytes): void
    {
        $this->source('images/'.$name, $bytes);

        $this->get('/images/4/300/220/images/'.$name)->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function disallowedExtensions(): array
    {
        return ['php' => ['x.php'], 'svg' => ['x.svg'], 'double' => ['x.jpg.php'], 'none' => ['x'], 'bmp' => ['x.bmp']];
    }

    #[Test]
    #[DataProvider('disallowedExtensions')]
    public function extensions_outside_the_allowlist_are_404(string $name): void
    {
        $this->source('images/'.$name, self::solid(10, 10));

        $this->get('/images/4/300/220/images/'.$name)->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function escapes(): array
    {
        return [
            'dot dot' => ['images/../private/secret.jpg'],
            'encoded dot dot' => ['images/%2e%2e/private/secret.jpg'],
            'double encoded' => ['images/%252e%252e/private/secret.jpg'],
            'dot segment' => ['images/./../private/secret.jpg'],
            'backslash' => ['images/..%5Cprivate%5Csecret.jpg'],
            'null byte' => ['images/secret.jpg%00.jpg'],
            'dotfile' => ['images/.hidden.jpg'],
            'empty segment' => ['images//private/secret.jpg'],
            'not under images' => ['private/secret.jpg'],
            'absolute' => ['/etc/passwd.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('escapes')]
    public function traversal_and_paths_outside_the_roots_are_404(string $source): void
    {
        $this->source('private/secret.jpg', self::solid(10, 10));
        $this->source('images/.hidden.jpg', self::solid(10, 10));

        $this->get('/images/4/300/220/'.$source)->assertNotFound();
    }

    #[Test]
    public function symlinks_out_of_the_roots_are_not_followed(): void
    {
        $this->source('private/secret.jpg', self::solid(10, 10));
        symlink($this->base.'/private/secret.jpg', $this->base.'/images/link.jpg');
        symlink($this->base.'/private', $this->base.'/images/linkdir');

        $this->get('/images/4/300/220/images/link.jpg')->assertNotFound();
        $this->get('/images/4/300/220/images/linkdir/secret.jpg')->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function badSpecs(): array
    {
        return [
            'mode 2 without gravity' => ['2/320/230'],
            'mode 4 with gravity' => ['4/300/220/5'],
            'gravity 0' => ['2/320/230/0'],
            'unused mode 3' => ['3/320/230/5'],
            'unused mode 5' => ['5/50'],
            'unlisted size' => ['4/301/220'],
            'huge size' => ['4/99999/99999'],
        ];
    }

    #[Test]
    #[DataProvider('badSpecs')]
    public function malformed_specs_and_unlisted_sizes_are_404(string $spec): void
    {
        $this->source('images/ok.jpg', self::solid(10, 10));

        $this->get('/images/'.$spec.'/images/ok.jpg')->assertNotFound();
    }

    #[Test]
    public function sizes_are_capped_even_when_listed(): void
    {
        config(['sesf.legacy_images.sizes' => ['4/3000/3000']]);
        $this->source('images/ok.jpg', self::solid(10, 10));

        $this->get('/images/4/3000/3000/images/ok.jpg')->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function legacySizes(): array
    {
        $specs = [
            '1/0/70', '1/1070/360', '2/320/230/5', '2/200/140/5', '2/221/165/5', '4/1070/800', '4/500/500', '4/200/150',
            '4/652/407', '4/0/60', '4/300/220', '4/228/0', '4/100/100', '4/362/272', '4/150/60', '4/320/230', '4/459/367',
            '4/221/0', '4/250/65', '4/240/240', '4/97/73',
            // SPA gallery srcset 2x variants
            '2/640/460/5', '2/400/280/5',
        ];

        return array_combine($specs, array_map(fn (string $s): array => [$s], $specs));
    }

    #[Test]
    #[DataProvider('legacySizes')]
    public function every_size_the_legacy_templates_used_is_kept(string $spec): void
    {
        $this->assertNotNull(LegacyImageSpec::parse($spec.'/images/x.jpg', config('sesf.legacy_images.sizes')));
    }

    #[Test]
    public function renders_are_limited_per_ip_but_cache_hits_still_serve(): void
    {
        $this->source('images/cached.jpg', self::solid(50, 50));
        $this->source('images/fresh.jpg', self::solid(50, 50));
        $this->get('/images/4/100/100/images/cached.jpg')->assertOk();

        for ($i = 0; $i < LegacyImageController::RENDERS_PER_MINUTE; $i++) {
            RateLimiter::hit('legacy-image-render|127.0.0.1', 60);
        }

        $this->get('/images/4/100/100/images/fresh.jpg')->assertStatus(429)->assertHeader('Retry-After');
        $this->get('/images/4/100/100/images/cached.jpg')->assertOk();
    }

    private function source(string $relative, string $bytes): string
    {
        $path = $this->base.'/'.$relative;
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function solid(int $width, int $height, string $format = 'jpeg'): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, 'blue');
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }

    /** Left half red, right half blue, so the crop gravity decides the colour in the middle of the output. */
    private static function split(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, 'blue');
        $draw = new ImagickDraw;
        $draw->setFillColor('red');
        $draw->rectangle(0, 0, intdiv($width, 2) - 1, $height - 1);
        $image->drawImage($draw);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** @param  TestResponse<Response>  $response */
    private function bytes(TestResponse $response): string
    {
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);

        return (string) file_get_contents($file->getFile()->getPathname());
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int|string, mixed>
     */
    private function info(TestResponse $response): array
    {
        $info = getimagesizefromstring($this->bytes($response));
        $this->assertNotFalse($info);

        return $info;
    }

    /**
     * @param  array{int, int}  $expected
     * @param  TestResponse<Response>  $response
     */
    private function assertSize(array $expected, TestResponse $response): void
    {
        $response->assertOk();
        $info = $this->info($response);
        $this->assertSame($expected, [$info[0], $info[1]]);
    }

    /** @param  TestResponse<Response>  $response */
    private function pixel(TestResponse $response, int $x, int $y): string
    {
        $image = new Imagick;
        $image->readImageBlob($this->bytes($response));
        $rgb = $image->getImagePixelColor($x, $y)->getColor();

        return match (true) {
            $rgb['r'] > 200 && $rgb['b'] < 60 => 'red',
            $rgb['b'] > 200 && $rgb['r'] < 60 => 'blue',
            default => 'other',
        };
    }
}
