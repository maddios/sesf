<?php

use App\Models\Judging\Answer;
use App\Models\Judging\AnswerImage;
use App\Models\Judging\FormQuestion;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('uses the legacy judging table with timestamps, soft deletes and guards only id', function (): void {
    $answer = new Answer;

    expect($answer->getTable())->toBe('show_registration_car_judging')
        ->and($answer->getKeyName())->toBe('id')
        ->and($answer->usesTimestamps())->toBeTrue()
        ->and($answer->getGuarded())->toBe(['id'])
        ->and(in_array(SoftDeletes::class, class_uses_recursive($answer), true))->toBeTrue();
});

test('casts ids to integers and the timestamps to datetimes', function (): void {
    $answer = Answer::factory()->create()->refresh();

    expect($answer->question_id)->toBeInt()
        ->and($answer->show_registration_car_id)->toBeInt()
        ->and($answer->user_id)->toBeInt()
        ->and($answer->created_at)->toBeInstanceOf(Carbon::class)
        ->and($answer->deleted_at)->toBeNull();
});

test('soft-deleted answers are hidden by default and visible with withTrashed', function (): void {
    $live = Answer::factory()->create();
    $gone = Answer::factory()->deleted()->create();

    expect(Answer::query()->pluck('id')->all())->toBe([$live->id])
        ->and(Answer::withTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([$live->id, $gone->id])->sort()->values()->all())
        ->and(Answer::onlyTrashed()->pluck('id')->all())->toBe([$gone->id])
        ->and($gone->refresh()->trashed())->toBeTrue();
});

test('delete() soft-deletes the row and restore() brings it back', function (): void {
    $answer = Answer::factory()->create();

    $answer->delete();

    expect(Answer::find($answer->id))->toBeNull()
        ->and(DB::table('show_registration_car_judging')->where('id', $answer->id)->value('deleted_at'))->not->toBeNull();

    Answer::withTrashed()->whereKey($answer->id)->restore();

    expect(Answer::find($answer->id))->not->toBeNull();
});

test('rating reads numeric data as a float and returns null for NULL, empty or non-numeric text', function (): void {
    $cases = [
        '7' => 7.0,
        '7.5' => 7.5,
        ' 8 ' => 8.0,
        '' => null,
        'abc' => null,
    ];

    foreach ($cases as $data => $expected) {
        expect(Answer::factory()->rated((string) $data)->create()->refresh()->rating)->toBe($expected);
    }

    expect(Answer::factory()->create(['data' => null])->refresh()->rating)->toBeNull();
});

test('rating does not change the stored data column', function (): void {
    $answer = Answer::factory()->rated('7.5')->create()->refresh();

    expect($answer->data)->toBe('7.5')
        ->and($answer->getAttributes()['data'])->toBe('7.5');
});

test('zero-date raw rows read created_at and updated_at as null', function (): void {
    $question = FormQuestion::factory()->create();
    $car = ShowRegistrationCar::factory()->create();
    $judge = User::factory()->create();

    $id = DB::table('show_registration_car_judging')->insertGetId([
        'question_id' => $question->id,
        'show_registration_car_id' => $car->id,
        'user_id' => $judge->id,
        'data' => '5',
        'comment' => null,
        'created_at' => '0000-00-00 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
        'deleted_at' => null,
    ]);

    $answer = Answer::find($id);

    expect($answer->created_at)->toBeNull()
        ->and($answer->updated_at)->toBeNull()
        ->and($answer->rating)->toBe(5.0);
});

test('relations resolve in both directions', function (): void {
    $answer = Answer::factory()->create();

    expect($answer->question)->toBeInstanceOf(FormQuestion::class)
        ->and($answer->registrationCar)->toBeInstanceOf(ShowRegistrationCar::class)
        ->and($answer->judge)->toBeInstanceOf(User::class)
        ->and($answer->question->answers()->pluck('show_registration_car_judging.id')->all())->toBe([$answer->id])
        ->and($answer->registrationCar->judgingAnswers()->pluck('show_registration_car_judging.id')->all())->toBe([$answer->id]);
});

test('scopes filter by question, registration car and judge', function (): void {
    $judge = User::factory()->create();
    $question = FormQuestion::factory()->create();
    $car = ShowRegistrationCar::factory()->create();
    $mine = Answer::factory()->create(['question_id' => $question->id, 'show_registration_car_id' => $car->id, 'user_id' => $judge->id]);
    $other = Answer::factory()->create();

    expect(Answer::query()->forQuestion($question->id)->pluck('show_registration_car_judging.id')->all())->toBe([$mine->id])
        ->and(Answer::query()->forRegistrationCar($car->id)->pluck('show_registration_car_judging.id')->all())->toBe([$mine->id])
        ->and(Answer::query()->byJudge($judge->id)->pluck('show_registration_car_judging.id')->all())->toBe([$mine->id])
        ->and(Answer::query()->byJudge($other->user_id)->pluck('show_registration_car_judging.id')->all())->toBe([$other->id]);
});

test('photoDirectory is the relative legacy path built from car and question ids', function (): void {
    $answer = Answer::factory()->create();

    expect($answer->photoDirectory())->toBe('images/judging/'.$answer->show_registration_car_id.'/'.$answer->question_id.'/')
        ->and(Answer::factory()->make(['show_registration_car_id' => 7, 'question_id' => 9])->photoDirectory())->toBe('images/judging/7/9/');
});

test('images relation links to AnswerImage in both directions', function (): void {
    $answer = Answer::factory()->create();
    $image = AnswerImage::factory()->create(['answer_id' => $answer->id]);

    expect($answer->images()->pluck('show_registration_car_judging_image.id')->all())->toBe([$image->id])
        ->and($image->answer->id)->toBe($answer->id);
});
