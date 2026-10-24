<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\CarShowLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy show venue (table show_location). Ports app/models/CarShowLocation.php.
 *
 * Not ported: the legacy save() geocoded the address through GoogleAPI. Geocoding is a
 * service concern and must not run from the model. lattitude and longitude are stored as given.
 *
 * Fixed defect: legacy assigned sort_order as the table-wide max + 1. It is now the max within
 * the same show (see the creating hook).
 *
 * @property int $id
 * @property int|null $location_id
 * @property int|null $show_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_primary
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $address1
 * @property string|null $address2
 * @property string $city
 * @property string $state
 * @property string $zip
 * @property float|null $lattitude
 * @property float|null $longitude
 * @property-read string $cityState
 * @property-read string $fullAddress
 */
class CarShowLocation extends Model
{
    /** @use HasFactory<CarShowLocationFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_location';

    public $timestamps = true;

    protected $guarded = ['id'];

    protected $appends = ['cityState', 'fullAddress'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'lattitude' => 'float',
            'longitude' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Legacy: assign sort_order when it is missing or empty (0 counts as empty), as max + 1.
        // Fixed: the max is taken per show, not across the whole table.
        static::creating(function (CarShowLocation $location): void {
            if (empty($location->sort_order)) {
                $location->sort_order = (int) static::query()
                    ->where('show_id', $location->show_id)
                    ->max('sort_order') + 1;
            }
        });
    }

    /**
     * Legacy: "City, ST".
     */
    public function getCityStateAttribute(): string
    {
        return $this->city.', '.$this->state;
    }

    /**
     * Legacy: address1 address2 city, state zip (address2 is empty when null, so the spacing matches).
     */
    public function getFullAddressAttribute(): string
    {
        return $this->address1.' '.$this->address2.' '.$this->city.', '.$this->state.' '.$this->zip;
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }
}
