<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Database\Factories\CarBodyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy car body style (table car_body), e.g. sedan or coupe.
 *
 * The primary key is assigned by hand (no AUTO_INCREMENT), so $incrementing is false.
 * The table has no timestamps.
 *
 * @property int $id
 * @property string|null $name
 */
class CarBody extends Model
{
    /** @use HasFactory<CarBodyFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'car_body';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Cars with this body (legacy car(), which was declared as belongsTo and is a hasMany).
     *
     * @return HasMany<Car, $this>
     */
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class, 'body_id');
    }

    /**
     * Shows where this body is eligible (car_body_show).
     *
     * @return BelongsToMany<CarShow, $this>
     */
    public function shows(): BelongsToMany
    {
        return $this->belongsToMany(CarShow::class, 'car_body_show', 'body_id', 'show_id');
    }

    /**
     * Bodies allowed at a show (legacy restrictBodyByShow).
     *
     * @param  Builder<CarBody>  $query
     * @return Builder<CarBody>
     */
    public function scopeRestrictBodyByShow(Builder $query, int $showId): Builder
    {
        return $query
            ->join('car_body_show', 'car_body_show.body_id', '=', 'car_body.id')
            ->where('car_body_show.show_id', '=', $showId)
            ->select('car_body.*');
    }
}
