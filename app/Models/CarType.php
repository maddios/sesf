<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy vehicle type (table car_type). Two fixed rows: Car and Motorcycle.
 *
 * Kept as a model rather than an enum: car.type() and CarType::all() (legacy dropdown) use the
 * table, and the foreign key from car.type_id makes it a real lookup. The primary key is assigned
 * by hand, and the table has no timestamps.
 *
 * @property int $id
 * @property string $name
 */
class CarType extends Model
{
    use HasLegacyDates;

    protected $table = 'car_type';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Cars of this type (legacy cars()).
     *
     * @return HasMany<Car, $this>
     */
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class, 'type_id');
    }
}
