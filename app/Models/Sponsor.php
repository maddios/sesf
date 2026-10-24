<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\SponsorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Legacy sponsor (table sponsor). Ports app/models/Sponsor.php.
 *
 * The legacy type() relation read sponsor.type_id, which does not exist. The sponsor tier
 * lives on show_sponsor.type_id, so it is not on this model.
 * The legacy typeId scope joined on a PHP object and could not run. It is not ported.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $URL
 * @property string|null $description
 * @property string|null $short_description
 * @property bool $is_enabled
 * @property string|null $internal_notes
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read string $imageURL
 * @property-read string $shortDescription
 * @property-read string $imageName
 */
class Sponsor extends Model
{
    /** @use HasFactory<SponsorFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'sponsor';

    public $timestamps = true;

    protected $guarded = ['id', 'is_enabled'];

    /** Internal notes are for staff only and never appear in the SPA JSON. */
    protected $hidden = ['internal_notes'];

    protected $appends = ['imageURL', 'shortDescription', 'imageName'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Legacy: the stored short_description, or the first 90 characters of description followed by '...'.
     */
    public function getShortDescriptionAttribute(): string
    {
        return $this->attributes['short_description']
            ?: substr((string) $this->description, 0, 90).'...';
    }

    /**
     * Legacy: sponsor image URL. The file's mtime is appended as a cache-buster when the file exists.
     */
    public function getImageURLAttribute(): string
    {
        $name = $this->id.'.jpg';
        $url = config('sesf.sponsor_image_url').$name;
        $mtime = @filemtime(config('sesf.sponsor_image_root').$name);

        return $mtime === false ? $url : $url.'?v='.$mtime;
    }

    public function getImageNameAttribute(): string
    {
        return $this->id.'.jpg';
    }

    /**
     * Legacy carShow: shows this sponsor is attached to, with the show_sponsor pivot.
     *
     * @return BelongsToMany<CarShow, $this>
     */
    public function carShow(): BelongsToMany
    {
        return $this->belongsToMany(CarShow::class, 'show_sponsor', 'sponsor_id', 'show_id')
            ->withPivot('type_id', 'is_primary', 'sort_order');
    }
}
