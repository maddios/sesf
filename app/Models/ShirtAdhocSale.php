<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ShirtAdhocSaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A walk-up shirt sale at one show, not tied to a registration (legacy table shirt_adhoc_sale).
 *
 * show_id is a signed smallint to match car_show.id, so the foreign key holds. The table is
 * latin1 like the rest of the schema.
 *
 * @property int $id
 * @property int $show_id
 * @property int $created_by_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ShirtAdhocSale extends Model
{
    /** @use HasFactory<ShirtAdhocSaleFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'shirt_adhoc_sale';

    public $timestamps = true;

    protected $guarded = ['id', 'created_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_id' => 'integer',
            'created_by_user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ShirtAdhocSale>  $query
     * @return Builder<ShirtAdhocSale>
     */
    public function scopeForShow(Builder $query, int $showId): Builder
    {
        return $query->where('show_id', $showId);
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<ShirtAdhocSaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShirtAdhocSaleItem::class, 'sale_id');
    }
}
