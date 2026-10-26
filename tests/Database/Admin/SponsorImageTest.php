<?php

namespace Tests\Database\Admin;

use App\Models\Sponsor;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;

/**
 * POST admin-api/sponsors/{id}/image: logo at the legacy flat path {sponsor_image_root}{id}.jpg.
 */
class SponsorImageTest extends AdminTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-sponsor-img-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
        config(['sesf.sponsor_image_root' => $this->root.'/']);

        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob($this->root.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->root);
        });
    }

    /** @return TestResponse<Response> */
    private function upload(Sponsor $sponsor, string $bytes, string $name = 'logo.png'): TestResponse
    {
        return $this->actingAs($this->admin())->post(
            '/admin-api/sponsors/'.$sponsor->id.'/image',
            ['file' => UploadedFile::fake()->createWithContent($name, $bytes)],
            self::JSON,
        );
    }

    #[Test]
    public function an_admin_uploads_a_logo_and_it_is_stored_as_jpeg_at_the_legacy_path(): void
    {
        $sponsor = Sponsor::factory()->create();

        $this->upload($sponsor, F::png(5, 5))->assertOk()->assertJsonPath('data.has_image', true);

        $path = $this->root.'/'.$sponsor->id.'.jpg';
        $this->assertFileExists($path);
        $this->assertSame('image/jpeg', mime_content_type($path));
        // No staging directories are left behind.
        $this->assertSame([$sponsor->id.'.jpg'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    #[Test]
    public function a_fake_image_is_a_422_on_file_and_the_old_logo_is_kept(): void
    {
        $sponsor = Sponsor::factory()->create();
        $this->upload($sponsor, F::jpeg())->assertOk();
        $before = (string) file_get_contents($this->root.'/'.$sponsor->id.'.jpg');

        $this->upload($sponsor, F::textPretendingToBeJpeg(), 'logo.jpg')
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame($before, file_get_contents($this->root.'/'.$sponsor->id.'.jpg'));
    }

    #[Test]
    public function a_missing_file_is_a_422(): void
    {
        $sponsor = Sponsor::factory()->create();

        $this->actingAs($this->admin())->post('/admin-api/sponsors/'.$sponsor->id.'/image', [], self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    #[Test]
    public function non_admins_cannot_upload(): void
    {
        $sponsor = Sponsor::factory()->create();
        $file = ['file' => UploadedFile::fake()->createWithContent('a.png', F::png())];

        $this->post('/admin-api/sponsors/'.$sponsor->id.'/image', $file, self::JSON)->assertStatus(401);
        $this->actingAs($this->actor('judge'))->post('/admin-api/sponsors/'.$sponsor->id.'/image', $file, self::JSON)->assertStatus(403);
        $this->assertFileDoesNotExist($this->root.'/'.$sponsor->id.'.jpg');
    }

    #[Test]
    public function deleting_a_sponsor_removes_its_logo(): void
    {
        $sponsor = Sponsor::factory()->create();
        $this->upload($sponsor, F::png())->assertOk();

        $this->actingAs($this->admin())->deleteJson('/admin-api/sponsors/'.$sponsor->id)->assertNoContent();

        $this->assertFileDoesNotExist($this->root.'/'.$sponsor->id.'.jpg');
    }
}
