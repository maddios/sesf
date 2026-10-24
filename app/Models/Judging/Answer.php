<?php

namespace App\Models\Judging;

use App\Models\Concerns\HasLegacyDates;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Judging\AnswerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One judge's answer to one question for one registered car (legacy table
 * show_registration_car_judging, legacy model Judging\Car).
 *
 * The table has deleted_at, but the legacy model had no SoftDeletes trait. Its callers filtered
 * whereNull('deleted_at') by hand. This model uses SoftDeletes, so deleted answers are hidden by
 * default. Use withTrashed() to see them.
 *
 * data is a varchar holding a numeric rating. The rating accessor reads it as a float and returns
 * null for NULL or non-numeric text. The column itself is not changed.
 *
 * @property int $id
 * @property int $question_id
 * @property int $show_registration_car_id
 * @property int $user_id
 * @property string|null $data
 * @property string|null $comment
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read float|null $rating
 */
class Answer extends Model
{
    /** @use HasFactory<AnswerFactory> */
    use HasFactory, HasLegacyDates, SoftDeletes;

    protected $table = 'show_registration_car_judging';

    public $timestamps = true;

    protected $guarded = ['id', 'user_id', 'deleted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'show_registration_car_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The rating in data as a float. Null when data is NULL, empty, or not numeric.
     */
    public function getRatingAttribute(): ?float
    {
        $data = trim((string) ($this->attributes['data'] ?? ''));

        return is_numeric($data) ? (float) $data : null;
    }

    /**
     * Relative directory where legacy judging photos for this answer are stored:
     * images/judging/{show_registration_car_id}/{question_id}/. Only builds the path; it does not
     * touch the filesystem.
     */
    public function photoDirectory(): string
    {
        return 'images/judging/'.$this->show_registration_car_id.'/'.$this->question_id.'/';
    }

    /**
     * @return BelongsTo<FormQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(FormQuestion::class, 'question_id');
    }

    /**
     * @return BelongsTo<ShowRegistrationCar, $this>
     */
    public function registrationCar(): BelongsTo
    {
        return $this->belongsTo(ShowRegistrationCar::class, 'show_registration_car_id');
    }

    /**
     * The judge who gave the answer.
     *
     * @return BelongsTo<User, $this>
     */
    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<AnswerImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(AnswerImage::class, 'answer_id');
    }

    /**
     * @param  Builder<Answer>  $query
     * @return Builder<Answer>
     */
    public function scopeForQuestion(Builder $query, int $questionId): Builder
    {
        return $query->where('show_registration_car_judging.question_id', $questionId);
    }

    /**
     * @param  Builder<Answer>  $query
     * @return Builder<Answer>
     */
    public function scopeForRegistrationCar(Builder $query, int $registrationCarId): Builder
    {
        return $query->where('show_registration_car_judging.show_registration_car_id', $registrationCarId);
    }

    /**
     * @param  Builder<Answer>  $query
     * @return Builder<Answer>
     */
    public function scopeByJudge(Builder $query, int $userId): Builder
    {
        return $query->where('show_registration_car_judging.user_id', $userId);
    }
}
