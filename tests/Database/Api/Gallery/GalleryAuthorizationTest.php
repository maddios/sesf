<?php

namespace Tests\Database\Api\Gallery;

use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Who may read and change galleries. Actors: guest, member (not the owner), owner, admin.
 * Galleries: enabled and disabled, both owned by $owner, both roots of $show.
 */
class GalleryAuthorizationTest extends TestCase
{
    use LoadsLegacySchema, UsesTempGalleryRoot;

    private CarShow $show;

    private User $owner;

    private User $member;

    private User $admin;

    private Gallery $enabled;

    private Gallery $disabled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->show = CarShow::factory()->create();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->enabled = Gallery::factory()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
        $this->disabled = Gallery::factory()->disabled()->create(['show_id' => $this->show->id, 'user_id' => $this->owner->id]);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function visibilityMatrix(): array
    {
        return [
            'enabled, guest' => ['enabled', 'guest', 200],
            'enabled, member' => ['enabled', 'member', 200],
            'enabled, owner' => ['enabled', 'owner', 200],
            'enabled, admin' => ['enabled', 'admin', 200],
            'disabled, guest' => ['disabled', 'guest', 403],
            'disabled, member' => ['disabled', 'member', 403],
            'disabled, owner' => ['disabled', 'owner', 200],
            'disabled, admin' => ['disabled', 'admin', 200],
        ];
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function writeMatrix(): array
    {
        $statuses = ['guest' => 401, 'member' => 403, 'owner' => 200, 'admin' => 200];
        $rows = [];

        foreach (['items', 'upload-file', 'default-item', 'remove-item'] as $endpoint) {
            foreach ($statuses as $actor => $status) {
                $rows["{$endpoint}, {$actor}"] = [$endpoint, $actor, $status];
            }
        }

        return $rows;
    }

    #[Test]
    #[DataProvider('visibilityMatrix')]
    public function info_follows_the_visibility_matrix(string $which, string $actor, int $status): void
    {
        $this->actAs($actor)->getJson('/gallery/info?gallery_id='.$this->galleryNamed($which)->id)
            ->assertStatus($status);
    }

    #[Test]
    public function info_without_a_known_id_is_an_empty_200(): void
    {
        $this->assertSame('', $this->getJson('/gallery/info')->assertOk()->getContent());
        $this->assertSame('', $this->getJson('/gallery/info?gallery_id=999999')->assertOk()->getContent());
    }

    #[Test]
    #[DataProvider('visibilityMatrix')]
    public function items_follows_the_visibility_matrix(string $which, string $actor, int $status): void
    {
        $this->actAs($actor)->getJson('/gallery/items?gallery_id='.$this->galleryNamed($which)->id)
            ->assertStatus($status);
    }

    #[Test]
    public function items_for_an_unknown_gallery_is_404(): void
    {
        $this->getJson('/gallery/items?gallery_id=999999')->assertStatus(404);
    }

    #[Test]
    public function items_without_a_gallery_or_subcategory_request_is_400(): void
    {
        $this->getJson('/gallery/items?show_id='.$this->show->id)->assertStatus(400);
    }

    #[Test]
    public function breadcrumb_for_an_unknown_show_is_400(): void
    {
        $this->getJson('/gallery/breadcrumb?show_id=999999&gallery_id='.$this->enabled->id)->assertStatus(400);
    }

    #[Test]
    public function breadcrumb_is_403_for_a_member_when_an_ancestor_is_disabled(): void
    {
        $child = Gallery::factory()->child($this->disabled)->create(['user_id' => $this->owner->id]);

        $this->actAs('member')
            ->getJson('/gallery/breadcrumb?show_id='.$this->show->id.'&gallery_id='.$child->id)
            ->assertStatus(403);
    }

    #[Test]
    public function hiding_a_parent_hides_its_enabled_descendants_everywhere(): void
    {
        $child = Gallery::factory()->child($this->disabled)->create(['user_id' => $this->admin->id]);
        $grandchild = Gallery::factory()->child($child)->create(['user_id' => $this->admin->id]);
        $item = $this->itemIn($grandchild);
        $visibleItem = $this->itemIn($this->enabled);

        foreach (['guest', 'member'] as $actor) {
            foreach ([$child, $grandchild] as $gallery) {
                $this->actAs($actor)->getJson('/gallery/info?gallery_id='.$gallery->id)->assertStatus(403);
                $this->actAs($actor)->getJson('/gallery/items?gallery_id='.$gallery->id)->assertStatus(403);
                $this->actAs($actor)->getJson('/gallery/list?show_id='.$this->show->id.'&gallery_id='.$gallery->id)->assertStatus(403);
            }
            $rollup = $this->actAs($actor)->getJson('/gallery/items?show_id='.$this->show->id.'&get_subcategory_items=1&all_items=1')->assertOk()->json();
            $ids = array_column($rollup['items'] ?? $rollup, 'id');
            $this->assertContains($visibleItem->id, $ids, "actor {$actor}");
            $this->assertNotContains($item->id, $ids, "actor {$actor}");

            $entry = collect((array) $this->actAs($actor)->getJson('/gallery/car-show-list')->assertOk()->json())->firstWhere('id', $this->show->id);
            $this->assertSame([$this->enabled->id], array_column($entry['galleries'] ?? [], 'id'), "actor {$actor}");
        }

        foreach (['owner', 'admin'] as $actor) {
            $this->actAs($actor)->getJson('/gallery/items?gallery_id='.$grandchild->id)->assertOk();
        }
    }

    #[Test]
    public function breadcrumb_lists_the_car_show_and_the_ancestors_root_first(): void
    {
        $middle = Gallery::factory()->child($this->enabled)->create(['user_id' => $this->owner->id]);
        $leaf = Gallery::factory()->child($middle)->create(['user_id' => $this->owner->id]);

        $response = $this->getJson('/gallery/breadcrumb?show_id='.$this->show->id.'&gallery_id='.$leaf->id)
            ->assertOk()
            ->assertJsonStructure(['car_show', 'galleries']);

        $this->assertSame($this->show->id, $response->json('car_show.id'));
        $this->assertSame([$this->enabled->id, $middle->id], array_column($response->json('galleries'), 'id'));
    }

    #[Test]
    public function list_shows_non_admins_only_the_enabled_roots(): void
    {
        Gallery::factory()->child($this->enabled)->create(['user_id' => $this->owner->id]);

        foreach (['guest', 'member', 'owner'] as $actor) {
            $ids = array_column($this->actAs($actor)->getJson('/gallery/list?show_id='.$this->show->id)->assertOk()->json(), 'id');
            $this->assertSame([$this->enabled->id], $ids, "actor {$actor}");
        }
    }

    #[Test]
    public function list_shows_admins_the_disabled_roots_too(): void
    {
        $ids = array_column($this->actAs('admin')->getJson('/gallery/list?show_id='.$this->show->id)->assertOk()->json(), 'id');

        $this->assertSame([$this->enabled->id, $this->disabled->id], $ids);
    }

    #[Test]
    public function list_of_a_disabled_parent_is_403_for_a_member(): void
    {
        $this->actAs('member')->getJson('/gallery/list?show_id='.$this->show->id.'&gallery_id='.$this->disabled->id)
            ->assertStatus(403);
        $this->actAs('admin')->getJson('/gallery/list?show_id='.$this->show->id.'&gallery_id='.$this->disabled->id)
            ->assertOk();
    }

    #[Test]
    public function car_show_list_guests_see_only_active_shows_with_an_enabled_gallery(): void
    {
        $onlyDisabled = $this->activeShowWith(enabled: false);
        $inactive = CarShow::factory()->create(['is_active' => false]);
        Gallery::factory()->create(['show_id' => $inactive->id, 'user_id' => $this->owner->id]);

        $ids = $this->showIds($this->getJson('/gallery/car-show-list')->assertOk());

        $this->assertContains($this->show->id, $ids);
        $this->assertNotContains($onlyDisabled->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    #[Test]
    public function car_show_list_admins_see_every_active_show_with_any_gallery(): void
    {
        $onlyDisabled = $this->activeShowWith(enabled: false);
        $inactive = CarShow::factory()->create(['is_active' => false]);
        Gallery::factory()->create(['show_id' => $inactive->id, 'user_id' => $this->owner->id]);

        $ids = $this->showIds($this->actAs('admin')->getJson('/gallery/car-show-list')->assertOk());

        $this->assertContains($this->show->id, $ids);
        $this->assertContains($onlyDisabled->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    #[Test]
    #[DataProvider('carShowGalleryVisibility')]
    public function car_show_list_galleries_contain_only_what_the_viewer_may_see(string $actor, bool $seesDisabled): void
    {
        /** @var list<array{id: int, galleries: list<array{id: int}>}> $shows */
        $shows = $this->actAs($actor)->getJson('/gallery/car-show-list')->assertOk()->json();

        $entry = collect($shows)->firstWhere('id', $this->show->id);
        $this->assertNotNull($entry);
        $expected = $seesDisabled ? [$this->enabled->id, $this->disabled->id] : [$this->enabled->id];
        $this->assertSame($expected, array_column($entry['galleries'], 'id'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function carShowGalleryVisibility(): array
    {
        return [
            'guest' => ['guest', false],
            'member' => ['member', false],
            'owner' => ['owner', true],
            'admin' => ['admin', true],
        ];
    }

    #[Test]
    public function checked_in_is_false_for_a_guest(): void
    {
        $this->getJson('/gallery/checked-in?show_id='.$this->show->id)
            ->assertOk()
            ->assertExactJson(['checked_in' => false]);
    }

    #[Test]
    public function checked_in_member_gets_no_album_until_one_is_created(): void
    {
        ShowRegistration::factory()->checkedIn()->create(['show_id' => $this->show->id, 'user_id' => $this->member->id]);

        $this->actAs('member')->getJson('/gallery/checked-in?show_id='.$this->show->id)
            ->assertOk()
            ->assertExactJson(['checked_in' => true, 'member_gallery_id' => null]);

        $album = $this->actAs('member')->postJson('/gallery/create', ['show_id' => $this->show->id])->json('id');

        $this->actAs('member')->getJson('/gallery/checked-in?show_id='.$this->show->id)
            ->assertOk()
            ->assertExactJson(['checked_in' => true, 'member_gallery_id' => $album]);
    }

    #[Test]
    public function a_cancelled_registration_is_not_checked_in(): void
    {
        ShowRegistration::factory()->checkedIn()->cancelled()->create(['show_id' => $this->show->id, 'user_id' => $this->member->id]);

        $this->actAs('member')->getJson('/gallery/checked-in?show_id='.$this->show->id)
            ->assertOk()
            ->assertExactJson(['checked_in' => false, 'member_gallery_id' => null]);
    }

    #[Test]
    #[DataProvider('writeMatrix')]
    public function writes_follow_the_manage_matrix(string $endpoint, string $actor, int $status): void
    {
        $this->actAs($actor);

        $this->writeTo($endpoint, $this->enabled)->assertStatus($status);
    }

    #[Test]
    #[DataProvider('showHideActors')]
    public function show_hide_is_admin_only(string $actor, int $status): void
    {
        $this->actAs($actor)->postJson('/gallery/show-hide', ['gallery_id' => $this->enabled->id, 'is_enabled' => false])
            ->assertStatus($status);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function showHideActors(): array
    {
        return [
            'guest' => ['guest', 403],
            'member' => ['member', 403],
            'owner' => ['owner', 403],
            'admin' => ['admin', 200],
        ];
    }

    #[Test]
    public function show_hide_toggles_the_gallery_for_an_admin(): void
    {
        $this->actAs('admin')->postJson('/gallery/show-hide', ['gallery_id' => $this->enabled->id, 'is_enabled' => false])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertFalse($this->enabled->refresh()->is_enabled);
    }

    #[Test]
    public function show_hide_with_an_unknown_gallery_is_400_for_an_admin(): void
    {
        $this->actAs('admin')->postJson('/gallery/show-hide', ['gallery_id' => 999999, 'is_enabled' => true])
            ->assertStatus(400);
    }

    #[Test]
    public function create_rejects_a_guest(): void
    {
        $this->postJson('/gallery/create', ['show_id' => $this->show->id])
            ->assertStatus(401);
    }

    #[Test]
    public function create_rejects_a_member_who_is_not_checked_in(): void
    {
        $this->actAs('member')->postJson('/gallery/create', ['show_id' => $this->show->id])
            ->assertStatus(403);
    }

    #[Test]
    public function a_checked_in_member_gets_one_disabled_album_titled_with_their_name(): void
    {
        ShowRegistration::factory()->checkedIn()->create(['show_id' => $this->show->id, 'user_id' => $this->member->id]);

        $first = $this->actAs('member')->postJson('/gallery/create', ['show_id' => $this->show->id])
            ->assertOk()
            ->assertJsonFragment(['title' => $this->member->firstNameLastInitial]);

        $this->assertSame(0, $first->json('is_enabled'));
        $this->assertSame($this->member->id, $first->json('user_id'));
        $this->assertNull($first->json('parent_id'));

        $second = $this->actAs('member')->postJson('/gallery/create', ['show_id' => $this->show->id])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Gallery::query()->where('show_id', $this->show->id)->where('user_id', $this->member->id)->count());
    }

    #[Test]
    public function an_admin_creates_a_root_gallery_or_a_sub_gallery(): void
    {
        $root = $this->actAs('admin')->postJson('/gallery/create', ['show_id' => $this->show->id, 'title' => 'Admin root'])
            ->assertOk();

        $this->assertNull($root->json('parent_id'));
        $this->assertSame('Admin root', $root->json('title'));

        $child = $this->actAs('admin')->postJson('/gallery/create', [
            'show_id' => $this->show->id,
            'title' => 'Admin child',
            'gallery_id' => $this->enabled->id,
        ])->assertOk();

        $this->assertSame($this->enabled->id, $child->json('parent_id'));
    }

    #[Test]
    public function an_admin_create_without_a_title_is_400_keyed_by_field(): void
    {
        $response = $this->actAs('admin')->postJson('/gallery/create', ['show_id' => $this->show->id])
            ->assertStatus(400);

        $this->assertIsArray($response->json());
        $this->assertArrayHasKey('title', $response->json());
    }

    #[Test]
    public function an_admin_create_with_a_parent_from_another_show_is_400(): void
    {
        $other = Gallery::factory()->create();

        $response = $this->actAs('admin')->postJson('/gallery/create', [
            'show_id' => $this->show->id,
            'title' => 'Crossed',
            'gallery_id' => $other->id,
        ])->assertStatus(400);

        $this->assertArrayHasKey('gallery_id', $response->json());
    }

    private function actAs(string $actor): static
    {
        $user = match ($actor) {
            'guest' => null,
            'member' => $this->member,
            'owner' => $this->owner,
            'admin' => $this->admin,
            default => throw new InvalidArgumentException("Unknown actor [{$actor}]."),
        };

        $this->app['session']->flush();

        if ($user !== null) {
            $this->actingAs($user);
        }

        return $this;
    }

    private function galleryNamed(string $which): Gallery
    {
        return $which === 'enabled' ? $this->enabled : $this->disabled;
    }

    private function activeShowWith(bool $enabled): CarShow
    {
        $show = CarShow::factory()->create();
        Gallery::factory()->create([
            'show_id' => $show->id,
            'user_id' => $this->owner->id,
            'is_enabled' => $enabled,
        ]);

        return $show;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int, int>
     */
    private function showIds(TestResponse $response): array
    {
        return array_column($response->json(), 'id');
    }

    private function itemIn(Gallery $gallery): GalleryItem
    {
        $item = GalleryItem::factory()->create();
        $gallery->items()->attach($item->id, ['user_id' => $item->user_id]);

        return $item;
    }

    /**
     * @return TestResponse<Response>
     */
    private function writeTo(string $endpoint, Gallery $gallery): TestResponse
    {
        return match ($endpoint) {
            'items' => $this->postJson('/gallery/items', ['gallery_id' => $gallery->id, 'items' => []]),
            'upload-file' => $this->post('/gallery/upload-file', [
                'gallery_id' => $gallery->id,
                'file' => UploadedFile::fake()->createWithContent('a.png', F::png()),
            ], ['Accept' => 'application/json']),
            'default-item' => $this->postJson('/gallery/default-item', ['gallery_id' => $gallery->id, 'item_id' => $this->itemIn($gallery)->id]),
            'remove-item' => $this->postJson('/gallery/remove-item', ['gallery_id' => $gallery->id, 'item_id' => $this->itemIn($gallery)->id]),
            default => throw new InvalidArgumentException("Unknown endpoint [{$endpoint}]."),
        };
    }
}
