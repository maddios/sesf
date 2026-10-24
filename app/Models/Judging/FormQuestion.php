<?php

namespace App\Models\Judging;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\Judging\FormQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question on a judging form (legacy table judging_form_question, legacy model
 * Judging\FormQuestion). Every question is a rating from valueMin to valueMax in steps of
 * valueStep. type is always 'rating' in the data.
 *
 * valueMin, valueMax and valueStep are smallint unsigned in the DDL, so they are cast to integers.
 * has_quarter is a tinyint(1) flag and is cast to boolean. Its meaning (quarter-point ratings) is
 * not applied here. The scoring port will handle it.
 *
 * @property int $id
 * @property int $form_id
 * @property string|null $type
 * @property string|null $title
 * @property string|null $description
 * @property int|null $page
 * @property string|null $page_name
 * @property int|null $sort_order
 * @property int|null $valueMin
 * @property int|null $valueMax
 * @property int|null $valueStep
 * @property bool $has_quarter
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class FormQuestion extends Model
{
    /** @use HasFactory<FormQuestionFactory> */
    use HasFactory, HasLegacyDates;

    public const TYPE_RATING = 'rating';

    protected $table = 'judging_form_question';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'page' => 'integer',
            'sort_order' => 'integer',
            'valueMin' => 'integer',
            'valueMax' => 'integer',
            'valueStep' => 'integer',
            'has_quarter' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    /**
     * Answers given to this question, for every car. Answer uses SoftDeletes, so deleted answers
     * are excluded. Legacy answer() was a hasOne filtered on deleted_at.
     *
     * @return HasMany<Answer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'question_id');
    }

    public function isRating(): bool
    {
        return $this->type === self::TYPE_RATING;
    }

    /**
     * Ratings in order, from valueMin to valueMax in steps of valueStep. Returns an empty list when
     * a bound or the step is missing, the step is below 1, or valueMin is above valueMax.
     *
     * @return list<int>
     */
    public function allowedValues(): array
    {
        $min = $this->valueMin;
        $max = $this->valueMax;
        $step = $this->valueStep;

        if ($min === null || $max === null || $step === null || $step < 1 || $min > $max) {
            return [];
        }

        return range($min, $max, $step);
    }

    /**
     * @param  Builder<FormQuestion>  $query
     * @return Builder<FormQuestion>
     */
    public function scopeForPage(Builder $query, int $page): Builder
    {
        return $query->where('judging_form_question.page', $page);
    }

    /**
     * Page order, then sort_order.
     *
     * @param  Builder<FormQuestion>  $query
     * @return Builder<FormQuestion>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('judging_form_question.page')->orderBy('judging_form_question.sort_order');
    }
}
