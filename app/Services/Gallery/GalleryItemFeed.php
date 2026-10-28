<?php

namespace App\Services\Gallery;

use App\Models\Gallery;
use App\Models\GalleryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Item lists for GET gallery/items. Ports legacy GalleryController::getItems,
 * getItemsRollupForShow, buildGalleryItemsAllForLightbox and findChildGalleries, including their
 * paging: parent items first, then items of enabled sub-galleries of the same show.
 *
 * Every list is ordered by gallery_item.sort_order, then created_at (id breaks ties so paging is
 * stable). Results are ['items' => Collection, 'count' => int, 'per_page' => int].
 */
final class GalleryItemFeed
{
    /**
     * @return array{items: Collection<int, GalleryItem>, count: int, per_page: int}
     */
    public function forGallery(Gallery $gallery, ?int $showId, bool $withSubGalleries, bool $allItems, int $skip): array
    {
        $parent = fn (): Builder => GalleryItem::query()->whereGalleryId((int) $gallery->id);
        $parentCount = $parent()->count();

        if (! $withSubGalleries) {
            return $this->result($this->ordered($parent())->get(), $parentCount);
        }

        $childIds = $showId === null ? [] : $this->childGalleryIds((int) $gallery->id, $showId);

        if ($childIds === []) {
            $query = $this->ordered($parent());

            return $this->result($allItems ? $query->get() : $this->page($query, $skip, $this->perPage())->get(), $parentCount);
        }

        $sub = fn (): Builder => GalleryItem::query()->whereShowId((int) $showId)->whereGalleryIdIn($childIds);

        if ($allItems) {
            $merged = $this->ordered($parent())->get()->concat($this->ordered($sub())->get());

            return $this->result($merged, $merged->count());
        }

        $total = $parentCount + $sub()->count();
        $items = $this->page($this->ordered($parent()), $skip, $this->perPage())->get();

        if ($items->count() === $this->perPage()) {
            return $this->result($items, $total);
        }

        $subItems = $this->page($this->ordered($sub()), max(0, $skip - $parentCount), $this->perPage() - $items->count())->get();

        return $this->result($items->concat($subItems), $total);
    }

    /**
     * Items of every gallery of the show in $galleryIds (the viewer's visible galleries).
     *
     * @param  array<int, int>  $galleryIds
     * @return array{items: Collection<int, GalleryItem>, count: int, per_page: int}
     */
    public function rollupForShow(int $showId, array $galleryIds, bool $allItems, int $skip): array
    {
        if ($galleryIds === []) {
            return $this->result(new Collection, 0);
        }

        $query = fn (): Builder => GalleryItem::query()->whereHas(
            'gallery',
            fn (Builder $gallery) => $gallery->where('gallery.show_id', $showId)->whereIn('gallery.id', $galleryIds),
        );

        $count = $query()->count();
        $ordered = $this->ordered($query());

        return $this->result($allItems ? $ordered->get() : $this->page($ordered, $skip, $this->perPage())->get(), $count);
    }

    public function perPage(): int
    {
        return (int) config('sesf.gallery_items_per_page');
    }

    /**
     * Enabled descendants of a gallery within the show, children before grandchildren.
     *
     * @return array<int, int>
     */
    private function childGalleryIds(int $galleryId, int $showId): array
    {
        $found = [];
        $level = [$galleryId];
        $seen = [$galleryId => true];

        while ($level !== []) {
            $children = Gallery::query()
                ->enabled()
                ->forShow($showId)
                ->whereIn('parent_id', $level)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->reject(fn (int $id): bool => isset($seen[$id]))
                ->values()
                ->all();

            foreach ($children as $id) {
                $seen[$id] = true;
                $found[] = $id;
            }

            $level = $children;
        }

        return $found;
    }

    /**
     * @param  Builder<GalleryItem>  $query
     * @return Builder<GalleryItem>
     */
    private function ordered(Builder $query): Builder
    {
        return $query->with('user')
            ->orderBy('gallery_item.sort_order')
            ->orderBy('gallery_item.created_at')
            ->orderBy('gallery_item.id');
    }

    /**
     * @param  Builder<GalleryItem>  $query
     * @return Builder<GalleryItem>
     */
    private function page(Builder $query, int $skip, int $take): Builder
    {
        return $query->skip(max(0, $skip))->take($take);
    }

    /**
     * @param  Collection<int, GalleryItem>|\Illuminate\Support\Collection<int, GalleryItem>  $items
     * @return array{items: Collection<int, GalleryItem>, count: int, per_page: int}
     */
    private function result(Collection|\Illuminate\Support\Collection $items, int $count): array
    {
        return ['items' => new Collection($items->all()), 'count' => $count, 'per_page' => $this->perPage()];
    }
}
