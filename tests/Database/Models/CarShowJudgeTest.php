<?php

use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: CarShowJudge, 1: CarShowJudge} Two assignments of one judge to two shows.
 */
function judgeOnTwoShows(): array
{
    $judge = User::factory()->create();

    return [
        CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => CarShow::factory()->create()->id]),
        CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => CarShow::factory()->create()->id]),
    ];
}

/** @return list<string> */
function judgePairs(): array
{
    return CarShowJudge::query()->orderBy('user_id')->orderBy('show_id')->get()
        ->map(fn (CarShowJudge $row): string => $row->user_id.':'.$row->show_id)->all();
}

test('uses the car_show_judge table without timestamps and guards created_at only', function (): void {
    $judge = new CarShowJudge(['user_id' => 3, 'show_id' => 4, 'created_at' => '2020-01-01 00:00:00']);

    expect($judge->getTable())->toBe('car_show_judge')
        ->and($judge->usesTimestamps())->toBeFalse()
        ->and($judge->getGuarded())->toBe(['created_at'])
        ->and(array_keys($judge->getAttributes()))->toBe(['user_id', 'show_id']);
});

test('casts created_at to a datetime and the keys to integers', function (): void {
    $row = CarShowJudge::factory()->create()->refresh();

    expect($row->user_id)->toBeInt()
        ->and($row->show_id)->toBeInt()
        ->and($row->created_at)->not->toBeNull();
});

test('the unique (user_id, show_id) pair rejects a second assignment', function (): void {
    $row = CarShowJudge::factory()->create();

    expect(fn () => CarShowJudge::factory()->create(['user_id' => $row->user_id, 'show_id' => $row->show_id]))
        ->toThrow(QueryException::class);
});

test('the same judge may be assigned to two shows and two judges to the same show', function (): void {
    $judge = User::factory()->create();
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();

    CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $show->id]);
    CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $other->id]);
    CarShowJudge::factory()->create(['show_id' => $show->id]);

    expect(CarShowJudge::query()->count())->toBe(3);
});

test('assignedTo replaces the legacy userAssignedToShow check', function (): void {
    $row = CarShowJudge::factory()->create();

    expect(CarShowJudge::query()->assignedTo($row->user_id, $row->show_id)->exists())->toBeTrue()
        ->and(CarShowJudge::query()->assignedTo($row->user_id, $row->show_id + 1)->exists())->toBeFalse()
        ->and(CarShowJudge::query()->assignedTo($row->user_id + 1, $row->show_id)->exists())->toBeFalse();
});

test('delete() removes only the one (user, show) row', function (): void {
    $judge = User::factory()->create();
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    $keep = CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $other->id]);
    $remove = CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $show->id]);

    $remove->delete();

    expect(CarShowJudge::query()->assignedTo($judge->id, $show->id)->exists())->toBeFalse()
        ->and(CarShowJudge::query()->assignedTo($keep->user_id, $keep->show_id)->exists())->toBeTrue();
});

test('judge and carShow relations resolve, and both directions via User and CarShow', function (): void {
    $row = CarShowJudge::factory()->create();

    expect($row->judge)->toBeInstanceOf(User::class)
        ->and($row->judge->carShowJudgeAssignments()->pluck('show_id')->all())->toBe([$row->show_id])
        ->and($row->carShow)->toBeInstanceOf(CarShow::class)
        ->and($row->carShow->carShowJudges()->pluck('user_id')->all())->toBe([$row->user_id]);
});

test('a raw row with a zero created_at reads as null', function (): void {
    $user = User::factory()->create();
    $show = CarShow::factory()->create();
    DB::table('car_show_judge')->insert([
        'user_id' => $user->id,
        'show_id' => $show->id,
        'created_at' => '0000-00-00 00:00:00',
    ]);

    expect(CarShowJudge::query()->assignedTo($user->id, $show->id)->first()->created_at)->toBeNull();
});

test('changing show_id and saving moves exactly that row', function (): void {
    [$first, $second] = judgeOnTwoShows();
    $newShow = CarShow::factory()->create();
    $oldShow = $second->show_id;

    $second->show_id = $newShow->id;
    expect($second->save())->toBeTrue();

    expect(CarShowJudge::query()->assignedTo($second->user_id, $oldShow)->exists())->toBeFalse();
    expect(CarShowJudge::query()->assignedTo($first->user_id, $first->show_id)->exists())->toBeTrue();
    expect(CarShowJudge::query()->assignedTo($second->user_id, $newShow->id)->exists())->toBeTrue();
    expect(CarShowJudge::query()->count())->toBe(2);
    expect($second->getOriginal('show_id'))->toBe($newShow->id);
});

test('changing user_id and saving moves exactly that row, and a second save still matches', function (): void {
    [$first, $second] = judgeOnTwoShows();
    $oldUser = $second->user_id;
    $newUser = User::factory()->create();

    $second->user_id = $newUser->id;
    $second->save();

    expect(CarShowJudge::query()->assignedTo($oldUser, $second->show_id)->exists())->toBeFalse();
    expect(CarShowJudge::query()->assignedTo($newUser->id, $second->show_id)->exists())->toBeTrue();
    expect(CarShowJudge::query()->assignedTo($first->user_id, $first->show_id)->exists())->toBeTrue();

    $otherShow = CarShow::factory()->create();
    $second->show_id = $otherShow->id;
    $second->save();

    expect(CarShowJudge::query()->assignedTo($newUser->id, $otherShow->id)->exists())->toBeTrue();
    expect(CarShowJudge::query()->count())->toBe(2);
});

test('moving a row onto an existing pair is rejected by the primary key', function (): void {
    [$first, $second] = judgeOnTwoShows();

    $second->show_id = $first->show_id;

    expect(fn () => $second->save())->toThrow(QueryException::class);
});

test('delete() after an unsaved key change removes the stored row, not the edited pair', function (): void {
    [$first, $second] = judgeOnTwoShows();
    $storedShow = $second->show_id;

    $second->show_id = $first->show_id;
    $second->delete();

    expect(CarShowJudge::query()->assignedTo($second->user_id, $storedShow)->exists())->toBeFalse();
    expect(CarShowJudge::query()->assignedTo($first->user_id, $first->show_id)->exists())->toBeTrue();
});

test('touch() is a no-op without timestamps and changes no row', function (): void {
    [$first, $second] = judgeOnTwoShows();
    $before = judgePairs();

    expect($second->touch())->toBeFalse();
    expect(judgePairs())->toBe($before);
});

test('refresh() and fresh() reload the same (user, show) row, not another show of the user', function (): void {
    [$first, $second] = judgeOnTwoShows();
    DB::table('car_show_judge')
        ->where('user_id', $second->user_id)->where('show_id', $second->show_id)
        ->update(['created_at' => '2021-02-03 04:05:06']);

    $fresh = $second->fresh();
    $second->refresh();

    expect($fresh->show_id)->toBe($second->show_id);
    expect($fresh->created_at->format('Y-m-d H:i:s'))->toBe('2021-02-03 04:05:06');
    expect($second->created_at->format('Y-m-d H:i:s'))->toBe('2021-02-03 04:05:06');
    expect($first->fresh()->show_id)->toBe($first->show_id);
});

test('getKey() is the composite pair, so two shows of one judge are different models', function (): void {
    [$first, $second] = judgeOnTwoShows();

    expect($first->getKey())->toBe($first->user_id.':'.$first->show_id);
    expect($first->is($second))->toBeFalse();
    expect($first->is(CarShowJudge::findByKeys($first->user_id, $first->show_id)))->toBeTrue();
    expect(CarShowJudge::query()->get()->unique()->count())->toBe(2);
    expect((new CarShowJudge)->getKey())->toBeNull();
});

test('findByKeys returns the one pair or null', function (): void {
    [$first, $second] = judgeOnTwoShows();

    expect(CarShowJudge::findByKeys($second->user_id, $second->show_id)?->show_id)->toBe($second->show_id);
    expect(CarShowJudge::findByKeys($second->user_id, 999999))->toBeNull();
});

test('single-key lookups and destroy() throw instead of matching on user_id alone', function (string $call): void {
    [$first] = judgeOnTwoShows();

    $run = match ($call) {
        'find' => fn () => CarShowJudge::query()->find($first->user_id),
        'findOrFail' => fn () => CarShowJudge::query()->findOrFail($first->user_id),
        'findMany' => fn () => CarShowJudge::query()->findMany([$first->user_id]),
        'whereKey' => fn () => CarShowJudge::query()->whereKey($first->user_id),
        'whereKeyNot' => fn () => CarShowJudge::query()->whereKeyNot($first->user_id),
        'destroy' => fn () => CarShowJudge::destroy($first->user_id),
        default => throw new InvalidArgumentException($call),
    };

    expect($run)->toThrow(LogicException::class, 'composite key');
    expect(CarShowJudge::query()->count())->toBe(2);
})->with(['find', 'findOrFail', 'findMany', 'whereKey', 'whereKeyNot', 'destroy']);
