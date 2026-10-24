<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ShowExtraRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A show extra bought on a registration (legacy table show_extra_registration). Ports
 * app/models/ShowExtraRegistration.php. Each extra appears at most once per registration.
 *
 * @property int $id
 * @property int $show_extra_id
 * @property int $registration_id
 * @property int|null $num_extras
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ShowExtraRegistration extends Model
{
    /** @use HasFactory<ShowExtraRegistrationFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_extra_registration';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_extra_id' => 'integer',
            'registration_id' => 'integer',
            'num_extras' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShowExtra, $this>
     */
    public function showExtra(): BelongsTo
    {
        return $this->belongsTo(ShowExtra::class, 'show_extra_id');
    }

    /**
     * @return BelongsTo<ShowRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ShowRegistration::class, 'registration_id');
    }
}
