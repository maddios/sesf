<?php

use App\Models\Judging\Answer;
use App\Models\Judging\Form;
use App\Models\Judging\FormQuestion;

test('uses the judging_form_question table with timestamps and guards only id', function (): void {
    $question = new FormQuestion;

    expect($question->getTable())->toBe('judging_form_question')
        ->and($question->getKeyName())->toBe('id')
        ->and($question->usesTimestamps())->toBeTrue()
        ->and($question->getGuarded())->toBe(['id']);
});

test('casts the range columns to integers and has_quarter to boolean', function (): void {
    $question = FormQuestion::factory()->range(2, 9, 1)->create(['has_quarter' => 1])->refresh();

    expect($question->valueMin)->toBe(2)
        ->and($question->valueMax)->toBe(9)
        ->and($question->valueStep)->toBe(1)
        ->and($question->has_quarter)->toBeTrue()
        ->and(FormQuestion::factory()->create(['has_quarter' => false])->refresh()->has_quarter)->toBeFalse();
});

test('isRating is true for the rating type only', function (): void {
    expect(FormQuestion::factory()->create()->isRating())->toBeTrue()
        ->and(FormQuestion::factory()->create(['type' => 'text'])->isRating())->toBeFalse()
        ->and(FormQuestion::TYPE_RATING)->toBe('rating');
});

test('allowedValues lists min to max in steps of valueStep', function (): void {
    expect(FormQuestion::factory()->range(1, 10, 1)->make()->allowedValues())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
        ->and(FormQuestion::factory()->range(1, 10, 3)->make()->allowedValues())->toBe([1, 4, 7, 10])
        ->and(FormQuestion::factory()->range(0, 10, 5)->make()->allowedValues())->toBe([0, 5, 10])
        ->and(FormQuestion::factory()->range(5, 5, 1)->make()->allowedValues())->toBe([5]);
});

test('allowedValues never exceeds valueMax', function (): void {
    expect(FormQuestion::factory()->range(1, 10, 4)->make()->allowedValues())->toBe([1, 5, 9]);
});

test('allowedValues is empty for a zero step, min above max, or missing bounds', function (): void {
    expect(FormQuestion::factory()->range(1, 10, 0)->make()->allowedValues())->toBe([])
        ->and(FormQuestion::factory()->range(10, 1, 1)->make()->allowedValues())->toBe([])
        ->and(FormQuestion::factory()->make(['valueMin' => null])->allowedValues())->toBe([])
        ->and(FormQuestion::factory()->make(['valueMax' => null])->allowedValues())->toBe([])
        ->and(FormQuestion::factory()->make(['valueStep' => null])->allowedValues())->toBe([]);
});

test('forPage and ordered scopes filter and sort', function (): void {
    $form = Form::factory()->create();
    $p1b = FormQuestion::factory()->create(['form_id' => $form->id, 'page' => 1, 'sort_order' => 2]);
    $p1a = FormQuestion::factory()->create(['form_id' => $form->id, 'page' => 1, 'sort_order' => 1]);
    $p2 = FormQuestion::factory()->create(['form_id' => $form->id, 'page' => 2, 'sort_order' => 1]);

    expect(FormQuestion::query()->forPage(1)->whereIn('judging_form_question.id', [$p1a->id, $p1b->id, $p2->id])->pluck('judging_form_question.id')->sort()->values()->all())
        ->toBe(collect([$p1a->id, $p1b->id])->sort()->values()->all())
        ->and(FormQuestion::query()->whereIn('judging_form_question.id', [$p1b->id, $p2->id, $p1a->id])->ordered()->pluck('judging_form_question.id')->all())
        ->toBe([$p1a->id, $p1b->id, $p2->id]);
});

test('form and answers relations resolve in both directions', function (): void {
    $question = FormQuestion::factory()->create();
    $answer = Answer::factory()->create(['question_id' => $question->id]);
    Answer::factory()->deleted()->create(['question_id' => $question->id]);

    expect($question->form)->toBeInstanceOf(Form::class)
        ->and($question->answers()->pluck('show_registration_car_judging.id')->all())->toBe([$answer->id])
        ->and($answer->question->id)->toBe($question->id);
});
