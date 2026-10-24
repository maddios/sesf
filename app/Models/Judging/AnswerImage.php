<?php

namespace App\Models\Judging;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\Judging\AnswerImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy table show_registration_car_judging_image (legacy model Judging\CarImage, which was
 * declared as Judging\Car by mistake; this model is named AnswerImage).
 *
 * The legacy upload flow never writes this table. Judging photos are stored only on disk, under
 * public/images/judging/{show_registration_car_id}/{question_id}/, and listed with a glob. This
 * model exists for the schema and the relation to Answer. Photo files are handled by a future
 * disk-based service in the judging phase.
 *
 * @property int $id
 * @property int $answer_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AnswerImage extends Model
{
    /** @use HasFactory<AnswerImageFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_registration_car_judging_image';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answer_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Answer, $this>
     */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class, 'answer_id');
    }
}
