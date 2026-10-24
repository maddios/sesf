<?php

use App\Enums\JudgingType;
use App\Models\Judging\Form;
use App\Models\Judging\FormQuestion;
use Database\Factories\Judging\FormFactory;

test('uses the judging_form table with timestamps and guards only id', function (): void {
    $form = new Form;

    expect($form->getTable())->toBe('judging_form')
        ->and($form->getKeyName())->toBe('id')
        ->and($form->usesTimestamps())->toBeTrue()
        ->and($form->getGuarded())->toBe(['id']);
});

test('casts type_id to JudgingType', function (): void {
    $form = Form::factory()->forType(JudgingType::Concours)->create()->refresh();

    expect($form->type_id)->toBe(JudgingType::Concours)
        ->and($form->type_id)->toBeInstanceOf(JudgingType::class);
});

test('the factory is valid under the foreign key and the default type exists', function (): void {
    $form = FormFactory::new()->create();

    expect(Form::query()->whereKey($form->id)->exists())->toBeTrue()
        ->and($form->type_id)->toBe(JudgingType::Clean);
});

test('questions are ordered by page, then sort_order', function (): void {
    $form = Form::factory()->create();
    $late = FormQuestion::factory()->create(['form_id' => $form->id, 'page' => 2, 'sort_order' => 1]);
    $second = FormQuestion::factory()->create(['form_id' => $form->id, 'page' => 1, 'sort_order' => 5]);
    $first = FormQuestion::factory()->create(['form_id' => $form->id, 'page' => 1, 'sort_order' => 2]);
    FormQuestion::factory()->create(); // another form

    expect($form->questions()->pluck('judging_form_question.id')->all())->toBe([$first->id, $second->id, $late->id]);
});

test('questions and form relations resolve in both directions', function (): void {
    $form = Form::factory()->create();
    $question = FormQuestion::factory()->create(['form_id' => $form->id]);

    expect($question->form->id)->toBe($form->id)
        ->and($form->questions)->toHaveCount(1)
        ->and($form->questions->first()->id)->toBe($question->id);
});
