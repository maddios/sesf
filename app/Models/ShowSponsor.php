<?php

namespace App\Models;

use App\Enums\SponsorType;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ShowSponsorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy show-sponsor link (table show_sponsor). Ports app/models/ShowSponsor.php.
 * The sponsor tier is type_id, cast to SponsorType. The legacy type() relation has no model yet.
 *
 * Fixed defect: legacy save() did not return the parent result. The sort_order hook here does
 * not change the save result.
 *
 * @property int $id
 * @property int $show_id
 * @property int $sponsor_id
 * @property bool|null $is_primary
 * @property int $sort_order
 * @property SponsorType|null $type_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ShowSponsor extends Model
{
    /** @use HasFactory<ShowSponsorFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_sponsor';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_id' => SponsorType::class,
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Legacy: assign sort_order when it is missing or 0, as max + 1 for the same show.
        // Fixed: the max is taken per show, not across the whole table.
        static::creating(function (ShowSponsor $showSponsor): void {
            if (empty($showSponsor->sort_order)) {
                $showSponsor->sort_order = (int) static::query()
                    ->where('show_id', $showSponsor->show_id)
                    ->max('sort_order') + 1;
            }
        });
    }

    /**
     * @return BelongsTo<Sponsor, $this>
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Sponsor::class, 'sponsor_id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }
}
