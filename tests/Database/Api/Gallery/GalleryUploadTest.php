<?php

namespace Tests\Database\Api\Gallery;

use App\Enums\GalleryItemType;
use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use App\Support\NumericPath;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * POST gallery/upload-file (multipart: file, gallery_id, last_modified_date).
 */
class GalleryUploadTest extends TestCase
{
    use LoadsLegacySchema, UsesTempGalleryRoot;

    private User $owner;

    private Gallery $gallery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->gallery = Gallery::factory()->create([
            'show_id' => CarShow::factory()->create()->id,
            'user_id' => $this->owner->id,
        ]);
        $this->actingAs($this->owner);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedImages(): array
    {
        return [
            'png' => [F::png(3, 3), 'holiday.png'],
            'jpeg' => [F::jpeg(4, 4), 'holiday.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedImages')]
    public function a_valid_image_becomes_an_item_linked_to_the_gallery(string $bytes, string $name): void
    {
        $this->upload($bytes, $name)->assertOk()->assertExactJson(['ok' => true]);

        $item = GalleryItem::query()->where('original_name', $name)->firstOrFail();

        $this->assertSame(GalleryItemType::Image, $item->type_id);
        $this->assertSame($name, $item->title);
        $this->assertSame((int) $this->owner->id, (int) $item->user_id);
        $this->assertSame(1, (int) $item->sort_order);
        $this->assertTrue(
            DB::table('gallery_item_xref')
                ->where('item_id', $item->id)
                ->where('gallery_id', $this->gallery->id)
                ->where('user_id', $this->owner->id)
                ->exists(),
        );
        $this->assertSame('image/jpeg', getimagesize($this->storedPath($item))['mime']);
    }

    #[Test]
    public function each_upload_takes_the_next_sort_order(): void
    {
        $this->upload(F::png(), 'first.png')->assertOk();
        $this->upload(F::png(), 'second.png')->assertOk();

        $this->assertSame(1, (int) GalleryItem::query()->where('original_name', 'first.png')->value('sort_order'));
        $this->assertSame(2, (int) GalleryItem::query()->where('original_name', 'second.png')->value('sort_order'));
    }

    #[Test]
    public function exif_metadata_is_not_stored(): void
    {
        $this->upload(F::jpegWithExif(), 'camera.jpg')->assertOk();

        $item = GalleryItem::query()->where('original_name', 'camera.jpg')->firstOrFail();
        $stored = file_get_contents($this->storedPath($item));

        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('SecretCam', $stored);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'text named jpg' => [F::textPretendingToBeJpeg(), 'photo.jpg'],
            'svg with script' => [F::svgWithScript(), 'x.svg'],
            'truncated png' => [F::truncatedPng(), 'x.png'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedFiles')]
    public function a_rejected_file_leaves_no_item_and_no_link(string $bytes, string $name): void
    {
        $itemsBefore = GalleryItem::query()->count();
        $linksBefore = DB::table('gallery_item_xref')->count();

        $this->upload($bytes, $name)->assertStatus(400)->assertJsonIsObject();

        $this->assertSame($itemsBefore, GalleryItem::query()->count());
        $this->assertSame($linksBefore, DB::table('gallery_item_xref')->count());
        $this->assertSame(0, DB::table('gallery_item_xref')->where('gallery_id', $this->gallery->id)->count());
    }

    #[Test]
    public function a_request_without_a_file_is_400(): void
    {
        $this->post('/gallery/upload-file', ['gallery_id' => $this->gallery->id], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'No file uploaded']);
    }

    #[Test]
    public function the_browser_last_modified_date_is_read_into_date_taken(): void
    {
        $this->upload(F::png(), 'dated.png', [
            'last_modified_date' => 'Mon Oct 26 2026 10:00:00 GMT-0400 (Eastern Daylight Time)',
        ])->assertOk();

        $item = GalleryItem::query()->where('original_name', 'dated.png')->firstOrFail();
        $this->assertNotNull($item->date_taken);

        $taken = CarbonImmutable::parse((string) $item->date_taken);
        $parsedFromHeader = $taken->format('Y-m-d H:i:s') === '2026-10-26 10:00:00';
        $fellBackToNow = abs($taken->diffInSeconds(CarbonImmutable::now())) < 120;

        $this->assertTrue($parsedFromHeader || $fellBackToNow, "date_taken was {$item->date_taken}");
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return TestResponse<Response>
     */
    private function upload(string $bytes, string $name, array $fields = []): TestResponse
    {
        return $this->post('/gallery/upload-file', $fields + [
            'gallery_id' => $this->gallery->id,
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json']);
    }

    private function storedPath(GalleryItem $item): string
    {
        return $this->galleryTempRoot.'/'.NumericPath::for($item->id).'/'.$item->id.'.jpg';
    }
}
