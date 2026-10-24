<?php

namespace App\Models\Judging;

use App\Enums\JudgingType;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\Judging\FormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A judging scoresheet (legacy table judging_form, legacy model Judging\Form). Each form belongs
 * to one judging type.
 *
 * @property int $id
 * @property string|null $name
 * @property JudgingType $type_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'judging_form';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_id' => JudgingType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Questions on this form, in page order, then sort_order.
     *
     * @return HasMany<FormQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(FormQuestion::class, 'form_id')
            ->orderBy('judging_form_question.page')
            ->orderBy('judging_form_question.sort_order');
    }
}
