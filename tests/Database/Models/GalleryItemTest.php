<?php

use App\Enums\GalleryItemType;
use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use App\Support\NumericPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Returns a fresh, empty image root for one test. The caller deletes it when done.
 */
function galleryImageRoot(): string
{
    $root = sys_get_temp_dir().'/sesf-gallery-'.bin2hex(random_bytes(6)).'/';
    mkdir($root, 0777, true);

    return $root;
}

test('uses the gallery_item table with timestamps and guards only id', function (): void {
    $item = new GalleryItem;

    expect($item->getTable())->toBe('gallery_item')
        ->and($item->getKeyName())->toBe('id')
        ->and($item->usesTimestamps())->toBeTrue()
        ->and($item->getGuarded())->toBe(['id']);
});

test('casts type_id to GalleryItemType and date_taken to a datetime', function (): void {
    $image = GalleryItem::factory()->create(['date_taken' => '2024-05-01 10:30:00'])->refresh();
    // gallery_item_type has only the Image row in the lookup fixture, so a video is not persisted here.
    $video = GalleryItem::factory()->video()->make();

    expect($image->type_id)->toBe(GalleryItemType::Image)
        ->and($video->type_id)->toBe(GalleryItemType::Video)
        ->and($image->date_taken)->toBeInstanceOf(Carbon::class)
        ->and($image->date_taken->toDateTimeString())->toBe('2024-05-01 10:30:00');
});

test('imageURL is present in toArray and toJson with the legacy path', function (): void {
    $item = GalleryItem::factory()->create()->refresh();
    $expected = config('sesf.gallery_image_url').NumericPath::for($item->id).'/'.$item->id.'.jpg';

    expect($item->toArray())->toHaveKey('imageURL')
        ->and($item->toArray()['imageURL'])->toStartWith($expected)
        ->and(json_decode($item->toJson(), true))->toHaveKey('imageURL')
        ->and(json_decode($item->toJson(), true)['imageURL'])->toBe($item->toArray()['imageURL']);
});

test('imageURL has no cache-bust suffix when the file does not exist', function (): void {
    $root = galleryImageRoot();
    config(['sesf.gallery_image_root' => $root]);

    try {
        $item = GalleryItem::factory()->create();
        $expected = config('sesf.gallery_image_url').NumericPath::for($item->id).'/'.$item->id.'.jpg';

        expect($item->imageURL)->toBe($expected);
    } finally {
        File::deleteDirectory(rtrim($root, '/'));
    }
});

test('imageURL adds the file mtime when the image exists under the configured root', function (): void {
    $root = galleryImageRoot();
    config(['sesf.gallery_image_root' => $root]);

    try {
        $item = GalleryItem::factory()->create();
        $relative = NumericPath::for($item->id).'/'.$item->id.'.jpg';
        $file = $root.$relative;
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'jpg');
        touch($file, 1700000000);

        expect($item->imageURL)->toBe(config('sesf.gallery_image_url').$relative.'?1700000000');

        touch($file, 1800000000);
        expect($item->imageURL)->toBe(config('sesf.gallery_image_url').$relative.'?1800000000');
    } finally {
        File::deleteDirectory(rtrim($root, '/'));
    }
});

test('whereGalleryId, whereGalleryIdIn and whereShowId filter items without duplicates', function (): void {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    $g1 = Gallery::factory()->create(['show_id' => $show->id]);
    $g2 = Gallery::factory()->create(['show_id' => $show->id]);
    $g3 = Gallery::factory()->create(['show_id' => $other->id]);
    $a = GalleryItem::factory()->create();
    $b = GalleryItem::factory()->create();
    $c = GalleryItem::factory()->create();
    GalleryItem::factory()->create();

    $g1->items()->attach($a->id, ['user_id' => $g1->user_id]);
    $g1->items()->attach($b->id, ['user_id' => $g1->user_id]);
    $g2->items()->attach($b->id, ['user_id' => $g2->user_id]);
    $g3->items()->attach($c->id, ['user_id' => $g3->user_id]);

    $ids = static fn ($query): array => $query->pluck('gallery_item.id')->all();

    expect($ids(GalleryItem::query()->whereGalleryId($g1->id)))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ids(GalleryItem::query()->whereGalleryIdIn([$g1->id, $g2->id])))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ids(GalleryItem::query()->whereShowId($show->id)))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ids(GalleryItem::query()->whereShowId($other->id)))->toEqualCanonicalizing([$c->id])
        ->and($ids(GalleryItem::query()->whereShowId($show->id)->whereGalleryId($g2->id)))->toEqualCanonicalizing([$b->id])
        ->and($ids(GalleryItem::query()->whereShowId($show->id)->whereGalleryIdIn([$g1->id, $g3->id])))->toEqualCanonicalizing([$a->id, $b->id])
        ->and(GalleryItem::query()->whereGalleryIdIn([])->count())->toBe(0);
});

test('user and gallery relations resolve in both directions', function (): void {
    $user = User::factory()->create();
    $gallery = Gallery::factory()->create(['user_id' => $user->id]);
    $item = GalleryItem::factory()->create(['user_id' => $user->id]);
    $gallery->items()->attach($item->id, ['title' => 'In gallery', 'sort_order' => 3, 'user_id' => $user->id]);

    $galleries = $item->gallery()->get();

    expect($item->user->id)->toBe($user->id)
        ->and($galleries->pluck('id')->all())->toBe([$gallery->id])
        ->and($galleries->pluck('pivot.title')->all())->toBe(['In gallery'])
        ->and($galleries->pluck('pivot.sort_order')->all())->toBe([3])
        ->and($gallery->items()->pluck('gallery_item.id')->all())->toBe([$item->id]);
});

test('factory states create valid items', function (): void {
    // Not persisted: the lookup fixture has no gallery_item_type row for Video.
    $video = GalleryItem::factory()->video()->make();

    expect($video->type_id)->toBe(GalleryItemType::Video)
        ->and($video->user)->not->toBeNull()
        ->and(GalleryItem::factory()->create()->type_id)->toBe(GalleryItemType::Image);
});
