<?php

use App\Models\Judging\Answer;
use App\Models\Judging\AnswerImage;

test('uses the legacy image table with timestamps and guards only id', function (): void {
    $image = new AnswerImage;

    expect($image->getTable())->toBe('show_registration_car_judging_image')
        ->and($image->getKeyName())->toBe('id')
        ->and($image->usesTimestamps())->toBeTrue()
        ->and($image->getGuarded())->toBe(['id']);
});

test('casts answer_id to an integer and the timestamps to datetimes', function (): void {
    $image = AnswerImage::factory()->create()->refresh();

    expect($image->answer_id)->toBeInt()
        ->and($image->created_at)->not->toBeNull();
});

test('answer relation resolves in both directions', function (): void {
    $answer = Answer::factory()->create();
    $image = AnswerImage::factory()->create(['answer_id' => $answer->id]);

    expect($image->answer)->toBeInstanceOf(Answer::class)
        ->and($image->answer->id)->toBe($answer->id)
        ->and($answer->images()->pluck('show_registration_car_judging_image.id')->all())->toBe([$image->id]);
});
