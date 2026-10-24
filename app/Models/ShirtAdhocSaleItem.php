<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ShirtAdhocSaleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shirt sold in an ad hoc sale (legacy table shirt_adhoc_sale_item). One row per shirt.
 *
 * The sale gives the show. Use sale() to reach it.
 *
 * @property int $id
 * @property int $sale_id
 * @property int $type_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ShirtAdhocSaleItem extends Model
{
    /** @use HasFactory<ShirtAdhocSaleItemFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'shirt_adhoc_sale_item';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_id' => 'integer',
            'type_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShirtAdhocSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(ShirtAdhocSale::class, 'sale_id');
    }

    /**
     * @return BelongsTo<ShirtType, $this>
     */
    public function shirtType(): BelongsTo
    {
        return $this->belongsTo(ShirtType::class, 'type_id');
    }
}
