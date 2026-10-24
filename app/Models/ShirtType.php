<?php

namespace App\Models;

use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shirt in the catalogue (legacy table shirt_type). Ports app/models/UserShirtType.php.
 *
 * Rows are pre-seeded by tests/Fixtures/lookup-data.sql (ids 1 to 22). The factory only adds
 * extra rows for tests; see ShirtTypeFactory.
 *
 * Fixed defects: legacy shirts() was a belongsTo pointing at shirt.type_id. It is a hasMany.
 *
 * @property int $id
 * @property string $name
 * @property string|null $price
 */
class ShirtType extends Model
{
    /** @use HasFactory<ShirtTypeFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'shirt_type';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => Money2::class,
        ];
    }

    /**
     * Shirt lines ordered in this type.
     *
     * @return HasMany<Shirt, $this>
     */
    public function shirts(): HasMany
    {
        return $this->hasMany(Shirt::class, 'type_id');
    }

    /**
     * Ad hoc sale lines of this type.
     *
     * @return HasMany<ShirtAdhocSaleItem, $this>
     */
    public function adhocSaleItems(): HasMany
    {
        return $this->hasMany(ShirtAdhocSaleItem::class, 'type_id');
    }
}
