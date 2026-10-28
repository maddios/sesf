<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;

/**
 * POST admin-api/events/{id}/image and /schedule-pdf: the legacy car-show image and schedule uploads,
 * stored at images/shows/{id}.jpg and packet/{id}.pdf.
 */
class EventUploadTest extends AdminTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-event-upload-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shows', 0755, true);
        mkdir($this->root.'/packet', 0755, true);
        config(['sesf.show_image_root' => $this->root.'/shows/', 'sesf.schedule_pdf_root' => $this->root.'/packet/']);

        $this->beforeApplicationDestroyed(function (): void {
            foreach (['shows', 'packet'] as $dir) {
                foreach (glob($this->root.'/'.$dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($this->root.'/'.$dir);
            }
            @rmdir($this->root);
        });
    }

    /** @return TestResponse<Response> */
    private function upload(CarShow $event, string $path, string $bytes, string $name): TestResponse
    {
        return $this->actingAs($this->admin())->post(
            '/admin-api/events/'.$event->id.'/'.$path,
            ['file' => UploadedFile::fake()->createWithContent($name, $bytes)],
            self::JSON,
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function actors(): array
    {
        return [
            'anonymous' => ['anonymous', 401],
            'member' => ['member', 403],
            'judge' => ['judge', 403],
        ];
    }

    #[Test]
    #[DataProvider('actors')]
    public function uploads_are_admin_only(string $who, int $status): void
    {
        $event = CarShow::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);

        foreach (['image' => F::png(2, 2), 'schedule-pdf' => self::PDF] as $path => $bytes) {
            $as()->post('/admin-api/events/'.$event->id.'/'.$path, ['file' => UploadedFile::fake()->createWithContent('x', $bytes)], self::JSON)
                ->assertStatus($status);
        }
        $this->assertSame([], glob($this->root.'/*/*') ?: []);
    }

    #[Test]
    public function an_image_is_stored_as_jpeg_at_the_legacy_path_and_sets_has_image(): void
    {
        $event = CarShow::factory()->create(['has_image' => false]);

        $this->upload($event, 'image', F::png(5, 5), 'show.png')->assertOk()->assertJsonPath('data.has_image', true);

        $path = $this->root.'/shows/'.$event->id.'.jpg';
        $this->assertFileExists($path);
        $this->assertSame('image/jpeg', mime_content_type($path));
        $this->assertSame('event.image', collect($this->auditEntries())->last()['action']);
    }

    #[Test]
    public function a_fake_image_is_a_422_and_has_image_stays_off(): void
    {
        $event = CarShow::factory()->create(['has_image' => false]);

        $this->upload($event, 'image', F::textPretendingToBeJpeg(), 'show.jpg')->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertFalse((bool) $event->refresh()->has_image);
        $this->assertFileDoesNotExist($this->root.'/shows/'.$event->id.'.jpg');
    }

    #[Test]
    public function a_schedule_pdf_is_stored_and_turns_the_pdf_schedule_on(): void
    {
        $event = CarShow::factory()->create(['is_schedule_pdf' => false]);

        $this->upload($event, 'schedule-pdf', self::PDF, 'schedule.pdf')->assertOk()->assertJsonPath('data.is_schedule_pdf', true);

        $this->assertSame(self::PDF, file_get_contents($this->root.'/packet/'.$event->id.'.pdf'));
        $this->assertSame([$event->id.'.pdf'], array_values(array_diff(scandir($this->root.'/packet') ?: [], ['.', '..'])));
        $this->assertSame('event.schedule_pdf', collect($this->auditEntries())->last()['action']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPdfs(): array
    {
        return [
            'html named .pdf' => ['<html><script>alert(1)</script></html>'],
            'image' => [F::png(2, 2)],
            'magic in the middle' => ["junk\n%PDF-1.4\n"],
        ];
    }

    #[Test]
    #[DataProvider('notPdfs')]
    public function a_file_that_is_not_a_pdf_is_a_422_and_the_old_one_is_kept(string $bytes): void
    {
        $event = CarShow::factory()->create();
        $this->upload($event, 'schedule-pdf', self::PDF, 'schedule.pdf')->assertOk();

        $this->upload($event, 'schedule-pdf', $bytes, 'schedule.pdf')->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(self::PDF, file_get_contents($this->root.'/packet/'.$event->id.'.pdf'));
    }

    #[Test]
    public function a_missing_file_or_event_is_rejected(): void
    {
        $event = CarShow::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin-api/events/'.$event->id.'/schedule-pdf', [], self::JSON)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($admin)->post('/admin-api/events/'.$event->id.'/image', [], self::JSON)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload(CarShow::factory()->make(['id' => 999999]), 'schedule-pdf', self::PDF, 'schedule.pdf')->assertNotFound();
    }
}
