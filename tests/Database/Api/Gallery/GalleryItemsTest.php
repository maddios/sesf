<?php

namespace Tests\Database\Api\Gallery;

use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use App\Support\NumericPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * GET gallery/items, POST gallery/items (reorder and titles), POST gallery/default-item and
 * POST gallery/remove-item.
 */
class GalleryItemsTest extends TestCase
{
    use LoadsLegacySchema, UsesTempGalleryRoot;

    private CarShow $show;

    private User $owner;

    private Gallery $gallery;

    private Gallery $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->show = CarShow::factory()->create();
        $this->owner = User::factory()->create();
        $this->gallery = Gallery::factory()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
        $this->other = Gallery::factory()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
    }

    #[Test]
    public function reordering_saves_titles_and_order_and_returns_the_list_in_order(): void
    {
        $this->actingAs($this->owner);
        $first = $this->itemIn($this->gallery, 1, 'First');
        $second = $this->itemIn($this->gallery, 2, 'Second');

        $response = $this->postJson('/gallery/items', [
            'gallery_id' => $this->gallery->id,
            'items' => [
                ['id' => $first->id, 'sort_order' => 2, 'title' => 'Renamed'],
                ['id' => $second->id, 'sort_order' => 1],
            ],
        ])->assertOk();

        $this->assertSame(2, $response->json('count'));
        $this->assertSame([$second->id, $first->id], array_column($response->json('items'), 'id'));

        $this->assertSame(2, (int) $first->refresh()->sort_order);
        $this->assertSame('Renamed', $first->title);
        $this->assertSame(1, (int) $second->refresh()->sort_order);
    }

    #[Test]
    public function an_id_from_another_gallery_is_400_and_nothing_changes(): void
    {
        $this->actingAs($this->owner);
        $inside = $this->itemIn($this->gallery, 1, 'Original');
        $outside = $this->itemIn($this->other, 1, 'Elsewhere');

        $this->postJson('/gallery/items', [
            'gallery_id' => $this->gallery->id,
            'items' => [
                ['id' => $inside->id, 'sort_order' => 9, 'title' => 'Changed'],
                ['id' => $outside->id, 'sort_order' => 9, 'title' => 'Changed'],
            ],
        ])->assertStatus(400)->assertExactJson(['error' => 'Item not in gallery']);

        $this->assertSame(1, (int) $inside->refresh()->sort_order);
        $this->assertSame('Original', $inside->title);
        $this->assertSame(1, (int) $outside->refresh()->sort_order);
        $this->assertSame('Elsewhere', $outside->title);
    }

    #[Test]
    public function a_title_longer_than_256_characters_is_400(): void
    {
        $this->actingAs($this->owner);
        $item = $this->itemIn($this->gallery, 1, 'Short');

        $this->postJson('/gallery/items', [
            'gallery_id' => $this->gallery->id,
            'items' => [['id' => $item->id, 'title' => str_repeat('a', 257)]],
        ])->assertStatus(400)->assertJsonStructure(['error']);

        $this->assertSame('Short', $item->refresh()->title);
    }

    #[Test]
    public function the_first_page_has_two_parent_items_and_the_count_covers_all_five(): void
    {
        config(['sesf.gallery_items_per_page' => 2]);
        [$parent] = $this->pagedGalleries();

        $response = $this->itemsPage($parent, 0);

        $this->assertSame(5, $response->json('count'));
        $this->assertSame(2, $response->json('per_page'));
        $this->assertSame(['P1', 'P2'], array_column($response->json('items'), 'title'));
    }

    #[Test]
    public function a_page_can_span_the_parent_and_the_child_items(): void
    {
        config(['sesf.gallery_items_per_page' => 2]);
        [$parent] = $this->pagedGalleries();

        $response = $this->itemsPage($parent, 2);

        $this->assertSame(5, $response->json('count'));
        $this->assertSame(['P3', 'C1'], array_column($response->json('items'), 'title'));
    }

    #[Test]
    public function a_late_page_holds_only_child_items(): void
    {
        config(['sesf.gallery_items_per_page' => 2]);
        [$parent] = $this->pagedGalleries();

        $response = $this->itemsPage($parent, 4);

        $this->assertSame(5, $response->json('count'));
        $this->assertSame(['C2'], array_column($response->json('items'), 'title'));
    }

    #[Test]
    public function all_items_returns_every_parent_and_child_item(): void
    {
        config(['sesf.gallery_items_per_page' => 2]);
        [$parent] = $this->pagedGalleries();

        $response = $this->getJson($this->itemsUrl($parent, 0).'&all_items=1')->assertOk();

        $this->assertSame(5, $response->json('count'));
        $this->assertCount(5, $response->json('items'));
    }

    #[Test]
    public function disabled_child_items_are_left_out_of_the_sub_gallery_listing(): void
    {
        config(['sesf.gallery_items_per_page' => 2]);
        [$parent] = $this->pagedGalleries();
        $hidden = Gallery::factory()->child($parent)->disabled()->create(['user_id' => $this->owner->id]);
        $this->itemIn($hidden, 1, 'Hidden');

        $response = $this->getJson($this->itemsUrl($parent, 0).'&all_items=1')->assertOk();

        $this->assertSame(5, $response->json('count'));
        $this->assertNotContains('Hidden', array_column($response->json('items'), 'title'));
    }

    #[Test]
    public function a_guest_rollup_leaves_out_disabled_galleries_and_the_owner_sees_them(): void
    {
        $enabled = Gallery::factory()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
        $disabled = Gallery::factory()->disabled()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
        $shown = $this->itemIn($enabled, 1, 'Shown');
        $withheld = $this->itemIn($disabled, 1, 'Withheld');
        $url = '/gallery/items?show_id='.$this->show->id.'&get_subcategory_items=1&all_items=1';

        $guestIds = array_column($this->getJson($url)->assertOk()->json('items'), 'id');
        $this->assertContains($shown->id, $guestIds);
        $this->assertNotContains($withheld->id, $guestIds);

        $this->actingAs($this->owner);
        $ownerIds = array_column($this->getJson($url)->assertOk()->json('items'), 'id');
        $this->assertContains($withheld->id, $ownerIds);
    }

    #[Test]
    public function an_item_carries_the_legacy_keys_and_no_contact_details_of_its_uploader(): void
    {
        $item = $this->itemIn($this->gallery, 1, 'Shape');

        $json = $this->getJson('/gallery/items?gallery_id='.$this->gallery->id)->assertOk()->json('items.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'type_id', 'title', 'original_name', 'sort_order', 'date_taken', 'created_at', 'updated_at', 'imageURL', 'user'],
            array_keys($json),
        );
        $this->assertSame($item->id, $json['id']);
        $this->assertArrayNotHasKey('email', $json['user']);
        $this->assertArrayNotHasKey('phone', $json['user']);
        $this->assertArrayNotHasKey('address', $json['user']);
    }

    #[Test]
    public function the_cover_must_be_an_item_of_the_gallery(): void
    {
        $this->actingAs($this->owner);
        $inside = $this->itemIn($this->gallery, 1, 'Cover');
        $outside = $this->itemIn($this->other, 1, 'Elsewhere');

        $this->postJson('/gallery/default-item', ['gallery_id' => $this->gallery->id, 'item_id' => $outside->id])
            ->assertStatus(400);
        $this->assertNull($this->gallery->refresh()->default_item_id);

        $this->postJson('/gallery/default-item', ['gallery_id' => $this->gallery->id, 'item_id' => $inside->id])
            ->assertOk();
        $this->assertSame($inside->id, $this->gallery->refresh()->default_item_id);
    }

    #[Test]
    public function removing_an_item_deletes_its_rows_links_cover_and_file(): void
    {
        $this->actingAs($this->owner);
        $item = $this->itemIn($this->gallery, 1, 'Doomed');
        $item->gallery()->attach($this->other->id, ['user_id' => $this->owner->id]);
        $this->gallery->forceFill(['default_item_id' => $item->id])->save();
        $path = $this->storeFile($item);

        $this->postJson('/gallery/remove-item', ['gallery_id' => $this->gallery->id, 'item_id' => $item->id])
            ->assertOk();

        $this->assertFalse(GalleryItem::query()->whereKey($item->id)->exists());
        $this->assertSame(0, DB::table('gallery_item_xref')->where('item_id', $item->id)->count());
        $this->assertNull($this->gallery->refresh()->default_item_id);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * Parent P with items P1..P3, enabled child C with items C1..C2 (all in the same show).
     *
     * @return array{0: Gallery, 1: Gallery}
     */
    private function pagedGalleries(): array
    {
        $parent = Gallery::factory()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
        $child = Gallery::factory()->child($parent)->create(['user_id' => $this->owner->id]);

        foreach (['P1', 'P2', 'P3'] as $i => $title) {
            $this->itemIn($parent, $i + 1, $title);
        }
        foreach (['C1', 'C2'] as $i => $title) {
            $this->itemIn($child, $i + 1, $title);
        }

        return [$parent, $child];
    }

    private function itemIn(Gallery $gallery, int $sortOrder, string $title): GalleryItem
    {
        $item = GalleryItem::factory()->create(['sort_order' => $sortOrder, 'title' => $title]);
        $gallery->items()->attach($item->id, ['user_id' => $item->user_id]);

        return $item;
    }

    private function itemsUrl(Gallery $gallery, int $skip): string
    {
        return '/gallery/items?gallery_id='.$gallery->id.'&show_id='.$this->show->id.'&get_subcategory_items=1&skip='.$skip;
    }

    /**
     * @return TestResponse<Response>
     */
    private function itemsPage(Gallery $gallery, int $skip): TestResponse
    {
        return $this->getJson($this->itemsUrl($gallery, $skip))->assertOk();
    }

    private function storeFile(GalleryItem $item): string
    {
        $path = $this->galleryTempRoot.'/'.NumericPath::for($item->id).'/'.$item->id.'.jpg';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, F::jpeg());

        return $path;
    }
}
