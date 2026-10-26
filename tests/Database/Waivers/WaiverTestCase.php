<?php

namespace Tests\Database\Waivers;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Models\Waiver;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Shared setup for waiver database tests: PDFs go to a temp dir (never the bind-mounted
 * storage/waivers), mail is faked, and each test gets its own client IP so per-IP limiters do not
 * leak between tests.
 */
abstract class WaiverTestCase extends TestCase
{
    use LoadsLegacySchema;

    protected const PASSWORD = 'secret-1';

    protected string $pdfDir;

    protected string $legacyDir;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $base = sys_get_temp_dir().'/sesf-waivers-'.bin2hex(random_bytes(6));
        $this->pdfDir = $base.'/storage';
        $this->legacyDir = $base.'/legacy';
        config([
            'waivers.storage_dir' => $this->pdfDir,
            'waivers.legacy_public_dir' => $this->legacyDir,
            'app.url' => 'https://sesf.example.test',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254)]);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->pdfDir);
        foreach ([$this->pdfDir, $this->legacyDir] as $dir) {
            foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        if (is_dir($base)) {
            rmdir($base);
        }

        parent::tearDown();
    }

    protected function show(): CarShow
    {
        return CarShow::factory()->create(['name' => 'Spring Fest', 'date_start' => '2027-04-10', 'date_end' => '2027-04-12']);
    }

    /** @param array<string, mixed> $user */
    protected function registration(?CarShow $show = null, array $user = []): ShowRegistration
    {
        $owner = User::factory()->create(array_merge(['first' => 'Reggie', 'last' => 'Strant', 'email' => 'reggie.'.bin2hex(random_bytes(3)).'@example.test', 'password' => bcrypt(self::PASSWORD)], $user));

        return ShowRegistration::factory()->create(['show_id' => ($show ?? $this->show())->id, 'user_id' => $owner->id]);
    }

    protected function guest(ShowRegistration $registration, string $first = 'Gina', string $last = 'Guest'): UserGuest
    {
        return UserGuest::factory()->forRegistration($registration)->create(['first' => $first, 'last' => $last, 'email' => 'gina.'.bin2hex(random_bytes(3)).'@example.test']);
    }

    protected function tracking(Waiver $waiver): string
    {
        return (string) $waiver->getAttribute('tracking_id');
    }

    protected function signatureUrl(int $width = 300, int $height = 100): string
    {
        return 'data:image/png;base64,'.base64_encode(F::png($width, $height, 'white'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sam',
            'last_name' => 'Signer',
            'email' => 'sam@example.test',
            'signature_mode' => 'draw',
            'signature_base64' => $this->signatureUrl(),
        ], $overrides);
    }
}
