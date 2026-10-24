<?php

namespace Database\Factories\Judging;

use App\Models\Judging\Answer;
use App\Models\Judging\AnswerImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A photo attached to an answer. The answer is created through AnswerFactory.
 *
 * @extends Factory<AnswerImage>
 */
class AnswerImageFactory extends Factory
{
    protected $model = AnswerImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'answer_id' => Answer::factory(),
        ];
    }
}
