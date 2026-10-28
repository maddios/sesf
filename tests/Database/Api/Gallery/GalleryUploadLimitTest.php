<?php

namespace Tests\Database\Api\Gallery;

use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Upload quotas on POST gallery/upload-file: the per-album cap and the per-member cap for a show.
 */
class GalleryUploadLimitTest extends TestCase
{
    use LoadsLegacySchema, UsesTempGalleryRoot;

    private User $member;

    private CarShow $show;

    private Gallery $gallery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();
        $this->show = CarShow::factory()->create();
        $this->gallery = Gallery::factory()->create([
            'show_id' => $this->show->id,
            'user_id' => $this->member->id,
        ]);
        $this->actingAs($this->member);
    }

    #[Test]
    public function an_album_refuses_uploads_once_it_holds_the_cap(): void
    {
        config(['sesf.gallery_max_items_per_album' => 2, 'sesf.gallery_max_items_per_member_per_show' => 100]);

        $this->upload(F::png(), 'one.png')->assertOk();
        $this->upload(F::png(), 'two.png')->assertOk();

        $this->upload(F::png(), 'three.png')
            ->assertStatus(400)
            ->assertExactJson(['error' => 'This album is full (2 photos).']);

        $this->assertSame(2, DB::table('gallery_item_xref')->where('gallery_id', $this->gallery->id)->count());
        $this->assertFalse(GalleryItem::query()->where('original_name', 'three.png')->exists());
        $this->assertSame(2, $this->storedFileCount());
    }

    #[Test]
    public function a_member_refuses_uploads_past_their_allowance_for_the_show(): void
    {
        config(['sesf.gallery_max_items_per_album' => 100, 'sesf.gallery_max_items_per_member_per_show' => 2]);

        $otherAlbum = Gallery::factory()->create(['show_id' => $this->show->id, 'user_id' => $this->member->id]);

        $this->upload(F::png(), 'one.png')->assertOk();
        $this->upload(F::png(), 'two.png', $otherAlbum->id)->assertOk();

        $this->upload(F::png(), 'three.png', $otherAlbum->id)
            ->assertStatus(400)
            ->assertExactJson(['error' => 'You have reached the upload limit for this event (2 photos).']);

        $this->assertFalse(GalleryItem::query()->where('original_name', 'three.png')->exists());
        $this->assertSame(2, $this->storedFileCount());
    }

    #[Test]
    public function a_member_allowance_does_not_count_items_from_another_show(): void
    {
        config(['sesf.gallery_max_items_per_album' => 100, 'sesf.gallery_max_items_per_member_per_show' => 1]);

        $this->upload(F::png(), 'one.png')->assertOk();

        $otherShowGallery = Gallery::factory()->create([
            'show_id' => CarShow::factory()->create()->id,
            'user_id' => $this->member->id,
        ]);
        $this->upload(F::png(), 'other.png', $otherShowGallery->id)->assertOk();
    }

    #[Test]
    public function an_administrator_is_not_subject_to_the_member_allowance(): void
    {
        config(['sesf.gallery_max_items_per_album' => 100, 'sesf.gallery_max_items_per_member_per_show' => 1]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->upload(F::png(), 'one.png')->assertOk();
        $this->upload(F::png(), 'two.png')->assertOk();
        $this->upload(F::png(), 'three.png')->assertOk();

        $this->assertSame(3, DB::table('gallery_item_xref')->where('gallery_id', $this->gallery->id)->count());
    }

    /**
     * @return TestResponse<Response>
     */
    private function upload(string $bytes, string $name, ?int $galleryId = null): TestResponse
    {
        return $this->post('/gallery/upload-file', [
            'gallery_id' => $galleryId ?? $this->gallery->id,
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json']);
    }

    private function storedFileCount(): int
    {
        return count(glob($this->galleryTempRoot.'/*/*/*/*.jpg') ?: []);
    }
}
