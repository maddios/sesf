<?php

namespace App\Models;

use App\Enums\GalleryItemType;
use App\Models\Concerns\HasLegacyDates;
use App\Support\NumericPath;
use Carbon\CarbonInterface;
use Database\Factories\GalleryItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One uploaded gallery item (legacy table `gallery_item`). Ports app/models/GalleryItem.php.
 *
 * Images are stored at config('sesf.gallery_image_root') in the sharded layout
 * {a/b/c}/{id}.jpg (see App\Support\NumericPath). The imageURL key is the legacy JSON key for the
 * public URL. A '?<mtime>' suffix is added when the file exists, so browsers refetch replaced images.
 *
 * Gallery membership (with a title and sort_order per gallery) is in gallery_item_xref.
 *
 * @property int $id
 * @property int $user_id
 * @property GalleryItemType $type_id
 * @property string $title
 * @property string|null $original_name
 * @property int|null $sort_order
 * @property CarbonInterface|null $date_taken
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read string $imageURL
 */
class GalleryItem extends Model
{
    /** @use HasFactory<GalleryItemFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'gallery_item';

    public $timestamps = true;

    protected $guarded = ['id', 'user_id'];

    protected $appends = ['imageURL'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_id' => GalleryItemType::class,
            'date_taken' => 'datetime',
        ];
    }

    /**
     * Legacy imageURL: the public base URL, the sharded path, and '{id}.jpg'.
     * Adds '?<filemtime>' when the file exists under gallery_image_root.
     */
    public function getImageURLAttribute(): string
    {
        $id = (int) $this->getKey();
        $relativePath = NumericPath::for($id).'/'.$id.'.jpg';

        $url = config('sesf.gallery_image_url').$relativePath;
        $file = config('sesf.gallery_image_root').$relativePath;

        if (is_file($file)) {
            $url .= '?'.filemtime($file);
        }

        return $url;
    }

    /**
     * Items in the gallery with this id. Uses a correlated EXISTS, not a join, so it can be
     * combined with the other scopes without duplicating rows or colliding on table aliases.
     *
     * @param  Builder<GalleryItem>  $query
     * @return Builder<GalleryItem>
     */
    public function scopeWhereGalleryId(Builder $query, int $galleryId): Builder
    {
        return $query->whereExists(function ($xref) use ($galleryId): void {
            $xref->selectRaw('1')
                ->from('gallery_item_xref')
                ->whereColumn('gallery_item_xref.item_id', 'gallery_item.id')
                ->where('gallery_item_xref.gallery_id', $galleryId);
        });
    }

    /**
     * Items in any gallery that belongs to the show with this id.
     *
     * @param  Builder<GalleryItem>  $query
     * @return Builder<GalleryItem>
     */
    public function scopeWhereShowId(Builder $query, int $showId): Builder
    {
        return $query->whereExists(function ($xref) use ($showId): void {
            $xref->selectRaw('1')
                ->from('gallery_item_xref')
                ->join('gallery', 'gallery.id', '=', 'gallery_item_xref.gallery_id')
                ->whereColumn('gallery_item_xref.item_id', 'gallery_item.id')
                ->where('gallery.show_id', $showId);
        });
    }

    /**
     * Items in any of the given galleries.
     *
     * @param  Builder<GalleryItem>  $query
     * @param  array<int, int>  $galleryIds
     * @return Builder<GalleryItem>
     */
    public function scopeWhereGalleryIdIn(Builder $query, array $galleryIds): Builder
    {
        return $query->whereExists(function ($xref) use ($galleryIds): void {
            $xref->selectRaw('1')
                ->from('gallery_item_xref')
                ->whereColumn('gallery_item_xref.item_id', 'gallery_item.id')
                ->whereIn('gallery_item_xref.gallery_id', $galleryIds);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Galleries that contain this item, with the per-gallery title and sort_order.
     *
     * @return BelongsToMany<Gallery, $this>
     */
    public function gallery(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'gallery_item_xref', 'item_id', 'gallery_id')
            ->withPivot('title', 'sort_order');
    }
}
