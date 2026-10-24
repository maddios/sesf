<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\GalleryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A gallery of items for a car show (legacy table `gallery`). Ports app/models/Gallery.php.
 *
 * Galleries form a tree through parent_id. Items are linked through gallery_item_xref, which
 * carries a per-gallery title and sort_order. is_private is stored as the enum('0','1') text
 * the legacy schema uses. The model exposes a boolean, and the setter writes '1' or '0'.
 *
 * The legacy gallery type (gallery_type) is kept as a plain id. No GalleryType model exists.
 *
 * @property int $id
 * @property int $show_id
 * @property int $user_id
 * @property int $type_id
 * @property bool $is_enabled
 * @property int|null $default_item_id
 * @property int|null $parent_id
 * @property bool $is_private
 * @property string $title
 * @property int|null $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Gallery extends Model
{
    /** @use HasFactory<GalleryFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'gallery';

    public $timestamps = true;

    protected $guarded = ['id', 'user_id', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Reads the enum('0','1') column as a boolean.
     */
    public function getIsPrivateAttribute(): bool
    {
        return (string) ($this->attributes['is_private'] ?? '0') === '1';
    }

    /**
     * Writes a boolean-like value as '1' or '0'. A PHP bool would be stored as 1 or 0, and MySQL
     * reads an integer 1 in an enum as the first member, so this writes the strings explicitly.
     * Accepts true/false, 1/0 and '1'/'0'/'true'/'false'.
     */
    public function setIsPrivateAttribute(mixed $value): void
    {
        $this->attributes['is_private'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Gallery, $this>
     */
    public function parentGallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class, 'parent_id');
    }

    /**
     * @return HasMany<Gallery, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Gallery::class, 'parent_id');
    }

    /**
     * @return BelongsTo<GalleryItem, $this>
     */
    public function defaultItem(): BelongsTo
    {
        return $this->belongsTo(GalleryItem::class, 'default_item_id');
    }

    /**
     * Items in this gallery, ordered by the xref sort_order.
     *
     * @return BelongsToMany<GalleryItem, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(GalleryItem::class, 'gallery_item_xref', 'gallery_id', 'item_id')
            ->withPivot('title', 'sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * The chain of parent galleries, ordered root first and nearest parent last. Excludes this
     * gallery. Stops if the data contains a cycle.
     *
     * @return Collection<int, Gallery>
     */
    public function ancestors(): Collection
    {
        $chain = [];
        $seen = [$this->id => true];
        $parent = $this->parentGallery;

        while ($parent !== null && ! isset($seen[$parent->id])) {
            $seen[$parent->id] = true;
            $chain[] = $parent;
            $parent = $parent->parentGallery;
        }

        return new Collection(array_reverse($chain));
    }

    /**
     * Ancestors followed by this gallery, ordered root to leaf.
     *
     * @return Collection<int, Gallery>
     */
    public function breadcrumb(): Collection
    {
        return $this->ancestors()->push($this);
    }

    /**
     * @param  Builder<Gallery>  $query
     * @return Builder<Gallery>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('gallery.is_enabled', true);
    }

    /**
     * @param  Builder<Gallery>  $query
     * @return Builder<Gallery>
     */
    public function scopeForShow(Builder $query, int $showId): Builder
    {
        return $query->where('gallery.show_id', $showId);
    }

    /**
     * Top-level galleries (no parent).
     *
     * @param  Builder<Gallery>  $query
     * @return Builder<Gallery>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('gallery.parent_id');
    }

    /**
     * Who can see which galleries. Admins see everything. Everyone else sees enabled galleries
     * plus the galleries they own, including disabled ones. A null user id (anonymous) sees
     * only enabled galleries. Access rules that depend on the viewer's role live in the
     * controller. This scope only applies the rule above.
     *
     * @param  Builder<Gallery>  $query
     * @return Builder<Gallery>
     */
    public function scopeVisibleTo(Builder $query, ?int $userId, bool $isAdmin): Builder
    {
        if ($isAdmin) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($userId): void {
            $visible->where('gallery.is_enabled', true);

            if ($userId !== null) {
                $visible->orWhere('gallery.user_id', $userId);
            }
        });
    }
}
