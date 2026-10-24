<?php

namespace App\Models;

use App\Enums\ExtraType;
use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ShowExtraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Legacy show extra (table show_extra). Ports app/models/ShowExtra.php.
 * The legacy type() relation (show_extra_type) has no model yet, so type_id is cast to ExtraType.
 *
 * @property int $id
 * @property bool $is_enabled
 * @property int $show_id
 * @property string $name
 * @property string $description
 * @property string|null $auth_key
 * @property string $price
 * @property ExtraType $type_id
 * @property int $min_value
 * @property int $max_value
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ShowExtra extends Model
{
    /** @use HasFactory<ShowExtraFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_extra';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_id' => ExtraType::class,
            'price' => Money2::class,
            'is_enabled' => 'boolean',
            'min_value' => 'integer',
            'max_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }

    /**
     * @return BelongsToMany<ShowRegistration, $this>
     */
    public function registration(): BelongsToMany // @phpstan-ignore-line later-wave class
    {
        return $this->belongsToMany(ShowRegistration::class, 'show_extra_registration', 'show_extra_id', 'registration_id') // @phpstan-ignore-line later-wave class
            ->withPivot('num_extras');
    }
}
