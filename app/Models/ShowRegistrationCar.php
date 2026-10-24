<?php

namespace App\Models;

use App\Enums\JudgingType;
use App\Models\Concerns\HasLegacyDates;
use App\Models\Judging\Answer;
use Carbon\CarbonInterface;
use Database\Factories\ShowRegistrationCarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One car entered in one registration (legacy table show_registration_car). Ports
 * app/models/CarShowRegistrationCar.php. Each car appears at most once per registration.
 *
 * Not ported: the legacy scopeCalculateJudgingStatus joined judging_form_question.type (a string)
 * to judging_type_id (an id). That join was wrong and has no caller in the new code. It belongs
 * to the judging wave, which should rebuild it on the judging form's type id.
 *
 * @property int $id
 * @property int $car_id
 * @property int $registration_id
 * @property JudgingType|null $judging_type_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ShowRegistrationCar extends Model
{
    /** @use HasFactory<ShowRegistrationCarFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_registration_car';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'car_id' => 'integer',
            'registration_id' => 'integer',
            'judging_type_id' => JudgingType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Car, $this>
     */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'car_id');
    }

    /**
     * @return BelongsTo<ShowRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ShowRegistration::class, 'registration_id');
    }

    /**
     * @return HasMany<Answer, $this>
     */
    public function judgingAnswers(): HasMany
    {
        return $this->hasMany(Answer::class, 'show_registration_car_id');
    }
}
