<?php

use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('uses the gallery table with timestamps and guards its privilege and money columns', function (): void {
    $gallery = new Gallery;

    expect($gallery->getTable())->toBe('gallery')
        ->and($gallery->getKeyName())->toBe('id')
        ->and($gallery->usesTimestamps())->toBeTrue()
        ->and($gallery->getGuarded())->toBe(['id', 'user_id', 'is_enabled']);
});

test('is_enabled reads as a boolean', function (): void {
    $disabled = Gallery::factory()->disabled()->create()->refresh();
    $enabled = Gallery::factory()->create()->refresh();

    expect($disabled->is_enabled)->toBeFalse()
        ->and($enabled->is_enabled)->toBeTrue();
});

test('is_private reads the enum text as a boolean', function (): void {
    $private = Gallery::factory()->private()->create();
    $public = Gallery::factory()->create();

    expect($private->is_private)->toBeTrue()
        ->and($public->is_private)->toBeFalse()
        ->and(DB::table('gallery')->where('id', $private->id)->value('is_private'))->toBe('1')
        ->and(DB::table('gallery')->where('id', $public->id)->value('is_private'))->toBe('0');
});

test('is_private writes the strings 1 and 0 for any boolean-like input', function (): void {
    $gallery = Gallery::factory()->create();

    $gallery->setAttribute('is_private', true);
    $gallery->save();
    $afterTrue = DB::table('gallery')->where('id', $gallery->id)->value('is_private');

    $gallery->setAttribute('is_private', '0');
    $gallery->save();
    $afterStringZero = DB::table('gallery')->where('id', $gallery->id)->value('is_private');

    $gallery->setAttribute('is_private', 1);
    $gallery->save();
    $afterIntOne = DB::table('gallery')->where('id', $gallery->id)->value('is_private');

    expect($afterTrue)->toBe('1')
        ->and($afterStringZero)->toBe('0')
        ->and($afterIntOne)->toBe('1')
        ->and($gallery->refresh()->is_private)->toBeTrue();
});

test('zero-date timestamps on a raw gallery row read as null', function (): void {
    $gallery = Gallery::factory()->create();
    DB::table('gallery')->where('id', $gallery->id)->update([
        'created_at' => '0000-00-00 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    $loaded = Gallery::query()->findOrFail($gallery->id);

    expect($loaded->created_at)->toBeNull()
        ->and($loaded->updated_at)->toBeNull();
});

test('show, user and default item relations resolve in both directions', function (): void {
    $show = CarShow::factory()->create();
    $user = User::factory()->create();
    $gallery = Gallery::factory()->create(['show_id' => $show->id, 'user_id' => $user->id]);
    $item = GalleryItem::factory()->create(['user_id' => $user->id]);
    $gallery->update(['default_item_id' => $item->id]);

    expect($gallery->show->id)->toBe($show->id)
        ->and($gallery->user->id)->toBe($user->id)
        ->and($gallery->defaultItem->id)->toBe($item->id)
        ->and($show->galleries()->pluck('id')->all())->toBe([$gallery->id]);
});

test('tree helpers give ancestors and breadcrumb root first', function (): void {
    $root = Gallery::factory()->root()->create();
    $child = Gallery::factory()->child($root)->create();
    $leaf = Gallery::factory()->child($child)->create();
    $leaf = $leaf->refresh();

    expect($leaf->ancestors()->pluck('id')->all())->toBe([$root->id, $child->id])
        ->and($leaf->breadcrumb()->pluck('id')->all())->toBe([$root->id, $child->id, $leaf->id])
        ->and($root->ancestors()->all())->toBe([])
        ->and($root->breadcrumb()->pluck('id')->all())->toBe([$root->id])
        ->and($leaf->parentGallery->id)->toBe($child->id)
        ->and($root->children()->pluck('id')->all())->toBe([$child->id]);
});

test('ancestors stops when the parent data contains a cycle', function (): void {
    $a = Gallery::factory()->root()->create();
    $b = Gallery::factory()->child($a)->create();
    DB::table('gallery')->where('id', $a->id)->update(['parent_id' => $b->id]);

    expect($b->refresh()->ancestors()->pluck('id')->all())->toBe([$a->id]);
});

test('items are ordered by the xref sort_order and expose the pivot title', function (): void {
    $gallery = Gallery::factory()->create();
    $late = GalleryItem::factory()->create();
    $early = GalleryItem::factory()->create();

    $gallery->items()->attach($late->id, ['title' => 'Late title', 'sort_order' => 2, 'user_id' => $gallery->user_id]);
    $gallery->items()->attach($early->id, ['title' => 'Early title', 'sort_order' => 1, 'user_id' => $gallery->user_id]);

    $items = $gallery->items()->get();

    expect($items->pluck('id')->all())->toBe([$early->id, $late->id])
        ->and($items->pluck('pivot.title')->all())->toBe(['Early title', 'Late title']);
});

test('xref rows with zero-date timestamps load through the items relation', function (): void {
    $gallery = Gallery::factory()->create();
    $item = GalleryItem::factory()->create();
    DB::table('gallery_item_xref')->insert([
        'item_id' => $item->id,
        'gallery_id' => $gallery->id,
        'user_id' => $gallery->user_id,
        'title' => 'Raw row',
        'sort_order' => null,
        'created_at' => '0000-00-00 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    $items = $gallery->items()->get();

    expect($items->pluck('id')->all())->toBe([$item->id])
        ->and($items->pluck('pivot.title')->all())->toBe(['Raw row'])
        ->and(DB::table('gallery_item_xref')->where('item_id', $item->id)->value('created_at'))->toBe('0000-00-00 00:00:00');
});

test('enabled, forShow and roots scopes filter galleries', function (): void {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    $root = Gallery::factory()->root()->create(['show_id' => $show->id]);
    $child = Gallery::factory()->child($root)->create();
    $disabled = Gallery::factory()->disabled()->create(['show_id' => $show->id]);
    $elsewhere = Gallery::factory()->create(['show_id' => $other->id]);

    expect(Gallery::query()->forShow($show->id)->pluck('id')->all())->toEqualCanonicalizing([$root->id, $child->id, $disabled->id])
        ->and(Gallery::query()->enabled()->pluck('id')->all())->toContain($root->id, $child->id, $elsewhere->id)
        ->and(Gallery::query()->enabled()->pluck('id')->all())->not->toContain($disabled->id)
        ->and(Gallery::query()->roots()->pluck('id')->all())->toContain($root->id, $disabled->id, $elsewhere->id)
        ->and(Gallery::query()->roots()->pluck('id')->all())->not->toContain($child->id);
});

test('visibleTo shows admins everything, owners their own, and everyone else only enabled galleries', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerDisabled = Gallery::factory()->disabled()->create(['user_id' => $owner->id]);
    $ownerEnabled = Gallery::factory()->create(['user_id' => $owner->id]);
    $othersEnabled = Gallery::factory()->create(['user_id' => $other->id]);
    $othersDisabled = Gallery::factory()->disabled()->create(['user_id' => $other->id]);

    $visibleIds = static fn (?int $userId, bool $isAdmin): array => Gallery::query()
        ->visibleTo($userId, $isAdmin)
        ->pluck('id')
        ->all();

    expect($visibleIds($owner->id, true))->toEqualCanonicalizing([$ownerDisabled->id, $ownerEnabled->id, $othersEnabled->id, $othersDisabled->id])
        ->and($visibleIds($owner->id, false))->toEqualCanonicalizing([$ownerDisabled->id, $ownerEnabled->id, $othersEnabled->id])
        ->and($visibleIds($other->id, false))->toEqualCanonicalizing([$ownerEnabled->id, $othersEnabled->id, $othersDisabled->id])
        ->and($visibleIds(null, false))->toEqualCanonicalizing([$ownerEnabled->id, $othersEnabled->id])
        ->and($visibleIds(null, true))->toHaveCount(4);
});

test('factory states create rows whose foreign keys resolve', function (): void {
    $root = Gallery::factory()->root()->create();
    $child = Gallery::factory()->child($root)->create();
    $disabled = Gallery::factory()->disabled()->create();
    $private = Gallery::factory()->private()->create();

    expect($child->parent_id)->toBe($root->id)
        ->and($child->show_id)->toBe($root->show_id)
        ->and($disabled->is_enabled)->toBeFalse()
        ->and($private->is_private)->toBeTrue();

    foreach ([$root, $child, $disabled, $private] as $gallery) {
        expect($gallery->show)->not->toBeNull()
            ->and($gallery->user)->not->toBeNull();
    }
});

test('mass assignment ignores the guarded owner and enabled columns', function () {
    $gallery = new Gallery;
    $gallery->fill(['user_id' => 3, 'is_enabled' => true, 'title' => 'Cars']);

    expect($gallery->getAttributes())->not->toHaveKeys(['user_id', 'is_enabled'])
        ->and($gallery->title)->toBe('Cars');
});
