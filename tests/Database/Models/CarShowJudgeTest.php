<?php

use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('uses the car_show_judge table without timestamps and guards only id', function (): void {
    $judge = new CarShowJudge;

    expect($judge->getTable())->toBe('car_show_judge')
        ->and($judge->usesTimestamps())->toBeFalse()
        ->and($judge->getGuarded())->toBe(['id']);
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
