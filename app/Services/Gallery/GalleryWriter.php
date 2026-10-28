<?php

namespace App\Services\Gallery;

use App\Enums\GalleryItemType;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use App\Support\NumericPath;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gallery writes. Ports the write actions of legacy GalleryController.
 *
 * Anything that reads or assigns gallery_item.sort_order first locks the gallery row
 * (SELECT ... FOR UPDATE) inside a transaction, as legacy lockGalleryRowForSortOrder did, so
 * concurrent uploads never share a sort_order and a reorder never interleaves with an upload.
 */
final class GalleryWriter
{
    private const TITLE_MAX = 256;

    public function __construct(private readonly ImageStore $images) {}

    /**
     * A member's own top-level album for the show: the existing one, or a new disabled album
     * titled with their "First L." name. The user row is locked so a double submit cannot
     * create two albums.
     */
    public function memberAlbum(User $user, int $showId): Gallery
    {
        return DB::transaction(function () use ($user, $showId): Gallery {
            DB::table('user')->where('id', $user->id)->lockForUpdate()->first();

            $existing = Gallery::query()->forShow($showId)->roots()->where('user_id', $user->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $gallery = new Gallery;
            $gallery->show_id = $showId;
            $gallery->title = $user->firstNameLastInitial;
            $gallery->user_id = (int) $user->id;
            $gallery->type_id = 1;
            $gallery->parent_id = null;
            $gallery->is_enabled = false;
            $gallery->save();

            return $gallery->refresh();
        });
    }

    /** Admin create. is_enabled and is_private keep their column defaults, as in legacy. */
    public function adminCreate(User $admin, int $showId, string $title, ?int $parentId): Gallery
    {
        $gallery = new Gallery;
        $gallery->show_id = $showId;
        $gallery->title = $title;
        $gallery->user_id = (int) $admin->id;
        $gallery->type_id = 1;
        $gallery->parent_id = $parentId;
        $gallery->save();

        return $gallery->refresh();
    }

    /**
     * Stores an uploaded photo as the next item of the gallery. The row is created first (its id
     * names the file); if the image is rejected or cannot be written the row is removed again.
     *
     * @throws ImageUploadException when the file is not an acceptable image
     * @throws Throwable when the row or the file cannot be saved
     */
    public function upload(Gallery $gallery, User $uploader, UploadedFile $file, ?string $lastModifiedDate): GalleryItem
    {
        $item = DB::transaction(function () use ($gallery, $uploader, $file, $lastModifiedDate): GalleryItem {
            $this->lockGallery((int) $gallery->id);

            $name = mb_substr($file->getClientOriginalName(), 0, self::TITLE_MAX);

            $item = new GalleryItem;
            $item->user_id = (int) $uploader->id;
            $item->date_taken = $this->dateTaken($lastModifiedDate);
            $item->sort_order = $this->nextSortOrder((int) $gallery->id);
            $item->title = $name;
            $item->original_name = $name;
            $item->type_id = GalleryItemType::Image;
            $item->save();

            $gallery->items()->attach($item->id, ['user_id' => $item->user_id]);

            return $item;
        });

        try {
            $this->images->storeAs($file, $this->directoryFor((int) $item->id), $item->id.'.jpg');
        } catch (Throwable $e) {
            $this->deleteItemRows($item);

            throw $e;
        }

        return $item;
    }

    /**
     * Applies title and sort_order changes to items of this gallery in one transaction under the
     * gallery lock. Returns false (nothing changed) when any id is not an item of the gallery.
     *
     * @param  array<int, array{id: int, title?: string|null, sort_order?: int|null}>  $changes
     */
    public function updateItems(Gallery $gallery, array $changes): bool
    {
        return DB::transaction(function () use ($gallery, $changes): bool {
            $this->lockGallery((int) $gallery->id);

            $ids = array_map(fn (array $change): int => $change['id'], $changes);
            $items = GalleryItem::query()->whereGalleryId((int) $gallery->id)->whereKey($ids)->get()->keyBy('id');

            if (count(array_diff($ids, $items->keys()->all())) > 0) {
                return false;
            }

            foreach ($changes as $change) {
                $attributes = array_intersect_key($change, ['title' => true, 'sort_order' => true]);
                if ($attributes === []) {
                    continue;
                }

                $item = $items->get($change['id']);
                $item->forceFill($attributes)->save();
            }

            return true;
        });
    }

    public function setCover(Gallery $gallery, int $itemId): Gallery
    {
        $gallery->default_item_id = $itemId;
        $gallery->save();

        return $gallery;
    }

    /**
     * Deletes the item, its gallery links, any cover that points at it, and its image file.
     */
    public function remove(GalleryItem $item): void
    {
        $this->deleteItemRows($item);

        $path = $this->directoryFor((int) $item->id).'/'.$item->id.'.jpg';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function isItemOfGallery(Gallery $gallery, int $itemId): bool
    {
        return GalleryItem::query()->whereGalleryId((int) $gallery->id)->whereKey($itemId)->exists();
    }

    private function deleteItemRows(GalleryItem $item): void
    {
        DB::transaction(function () use ($item): void {
            DB::table('gallery')->where('default_item_id', $item->id)->update(['default_item_id' => null]);
            DB::table('gallery_item_xref')->where('item_id', $item->id)->delete();
            $item->delete();
        });
    }

    private function lockGallery(int $galleryId): void
    {
        DB::table('gallery')->where('id', $galleryId)->lockForUpdate()->first(['id']);
    }

    private function nextSortOrder(int $galleryId): int
    {
        $max = DB::table('gallery_item')
            ->join('gallery_item_xref', 'gallery_item.id', '=', 'gallery_item_xref.item_id')
            ->where('gallery_item_xref.gallery_id', $galleryId)
            ->max('gallery_item.sort_order');

        return $max === null ? 1 : (int) $max + 1;
    }

    /**
     * Legacy read the browser's File.lastModifiedDate string (with "Eastern Daylight Time"
     * shortened to "EDT") and fell back to now when it did not parse.
     */
    private function dateTaken(?string $lastModifiedDate): CarbonImmutable
    {
        if ($lastModifiedDate === null || trim($lastModifiedDate) === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse(str_replace('Eastern Daylight Time', 'EDT', $lastModifiedDate));
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function directoryFor(int $itemId): string
    {
        return rtrim((string) config('sesf.gallery_image_root'), '/\\').'/'.NumericPath::for($itemId);
    }
}
