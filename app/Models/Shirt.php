<?php

namespace App\Models;

use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ShirtFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A t-shirt ordered with a registration (legacy table shirt). Ports app/models/UserShirt.php.
 *
 * price is the amount paid for this line. It is nullable for legacy rows. New orders snapshot the
 * price from ShirtType when the line is created, so a later catalogue price change does not alter it.
 *
 * Fixed defects: legacy getNameAttribute() returned the literal string 'name' and is removed.
 * The legacy relations named classes that do not exist (User, CarShowRegistration, UserShirtType);
 * they now point at the real models.
 *
 * @property int $id
 * @property int $user_id
 * @property int $registration_id
 * @property int $type_id
 * @property int $show_id
 * @property string|null $price
 * @property bool $is_handed_out
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Shirt extends Model
{
    /** @use HasFactory<ShirtFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'shirt';

    public $timestamps = true;

    protected $guarded = ['id', 'price', 'is_handed_out'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'registration_id' => 'integer',
            'type_id' => 'integer',
            'show_id' => 'integer',
            'price' => Money2::class.':9999.99',
            'is_handed_out' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Shirt>  $query
     * @return Builder<Shirt>
     */
    public function scopeForShow(Builder $query, int $showId): Builder
    {
        return $query->where('show_id', $showId);
    }

    /**
     * Shirts that were (or were not) handed out. Pass false for the pickup queue.
     *
     * @param  Builder<Shirt>  $query
     * @return Builder<Shirt>
     */
    public function scopeHandedOut(Builder $query, bool $handedOut = true): Builder
    {
        return $query->where('is_handed_out', $handedOut);
    }

    /**
     * @return BelongsTo<ShowRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ShowRegistration::class, 'registration_id');
    }

    /**
     * @return BelongsTo<ShirtType, $this>
     */
    public function shirtType(): BelongsTo
    {
        return $this->belongsTo(ShirtType::class, 'type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }
}
