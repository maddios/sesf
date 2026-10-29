<?php

use App\Enums\UserType;
use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** @param  array<string, mixed>  $overrides */
function gateUser(UserType $type, array $overrides = []): User
{
    return User::factory()->create(array_merge(['type_id' => $type->value, 'is_admin' => $type === UserType::Administrator], $overrides));
}

function gateAssign(User $judge, CarShow $show): void
{
    CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $show->id]);
}

/**
 * A car entered in $show by $owner.
 */
function gateCarIn(CarShow $show, User $owner): ShowRegistrationCar
{
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $owner->id]);

    return ShowRegistrationCar::factory()->create(['registration_id' => $registration->id]);
}

test('the admin gate allows only administrators', function (UserType $type, bool $expected) {
    expect(Gate::forUser(gateUser($type))->allows('admin'))->toBe($expected);
})->with([
    'regular' => [UserType::Regular, false],
    'judge' => [UserType::Judge, false],
    'administrator' => [UserType::Administrator, true],
]);

test('the judge gate allows judges and administrators', function (UserType $type, bool $expected) {
    expect(Gate::forUser(gateUser($type))->allows('judge'))->toBe($expected);
})->with([
    'regular' => [UserType::Regular, false],
    'judge' => [UserType::Judge, true],
    'administrator' => [UserType::Administrator, true],
]);

test('a guest is refused by every role gate', function () {
    expect(Gate::allows('admin'))->toBeFalse();
    expect(Gate::allows('judge'))->toBeFalse();
    expect(Gate::allows('judge-for-show', [1]))->toBeFalse();
});

test('judge-for-show: the role and show assignment decide access to a show', function (string $who, bool $expected) {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();

    $user = match ($who) {
        'admin' => gateUser(UserType::Administrator),
        'assigned judge' => tap(gateUser(UserType::Judge), fn (User $u) => gateAssign($u, $show)),
        'unassigned judge' => gateUser(UserType::Judge),
        'judge assigned to another show' => tap(gateUser(UserType::Judge), fn (User $u) => gateAssign($u, $other)),
        'assigned regular member' => tap(gateUser(UserType::Regular), fn (User $u) => gateAssign($u, $show)),
        'regular member' => gateUser(UserType::Regular),
        default => throw new LogicException('Unknown case: '.$who),
    };

    expect(Gate::forUser($user)->allows('judge-for-show', [$show->id]))->toBe($expected);
})->with([
    'admin with no assignment' => ['admin', true],
    'assigned judge' => ['assigned judge', true],
    'unassigned judge' => ['unassigned judge', false],
    'judge assigned to another show' => ['judge assigned to another show', false],
    'assigned regular member' => ['assigned regular member', false],
    'regular member' => ['regular member', false],
]);

test('judge-for-show with a car id: a judge cannot judge their own car', function () {
    $show = CarShow::factory()->create();
    $judge = gateUser(UserType::Judge);
    gateAssign($judge, $show);

    $ownCar = gateCarIn($show, $judge);
    $otherCar = gateCarIn($show, gateUser(UserType::Regular));

    expect(Gate::forUser($judge)->allows('judge-for-show', [$show->id, $ownCar->id]))->toBeFalse();
    expect(Gate::forUser($judge)->allows('judge-for-show', [$show->id, $otherCar->id]))->toBeTrue();
});

test('judge-for-show with a car id: the car must be entered in the show it is checked against', function () {
    $show = CarShow::factory()->create();
    $otherShow = CarShow::factory()->create();
    $judge = gateUser(UserType::Judge);
    gateAssign($judge, $show);

    $carInOtherShow = gateCarIn($otherShow, gateUser(UserType::Regular));

    expect(Gate::forUser($judge)->allows('judge-for-show', [$show->id, $carInOtherShow->id]))->toBeFalse();
});

test('judge-for-show with a car id that does not exist is refused', function () {
    $show = CarShow::factory()->create();
    $judge = gateUser(UserType::Judge);
    gateAssign($judge, $show);

    expect(Gate::forUser($judge)->allows('judge-for-show', [$show->id, 999999]))->toBeFalse();
});

test('judge-for-show with a car id: an admin may judge any car, including their own', function () {
    $show = CarShow::factory()->create();
    $admin = gateUser(UserType::Administrator);
    $ownCar = gateCarIn($show, $admin);

    expect(Gate::forUser($admin)->allows('judge-for-show', [$show->id, $ownCar->id]))->toBeTrue();
});

test('judge-car: the car owner is refused, other assigned judges are allowed', function (string $who, bool $expected) {
    $show = CarShow::factory()->create();
    $owner = gateUser(UserType::Judge);
    gateAssign($owner, $show);
    $car = gateCarIn($show, $owner);

    $user = match ($who) {
        'owner (assigned judge)' => $owner,
        'other assigned judge' => tap(gateUser(UserType::Judge), fn (User $u) => gateAssign($u, $show)),
        'unassigned judge' => gateUser(UserType::Judge),
        'regular member' => gateUser(UserType::Regular),
        'admin' => gateUser(UserType::Administrator),
        default => throw new LogicException('Unknown case: '.$who),
    };

    expect(Gate::forUser($user)->allows('judge-car', $car))->toBe($expected);
})->with([
    'owner (assigned judge)' => ['owner (assigned judge)', false],
    'other assigned judge' => ['other assigned judge', true],
    'unassigned judge' => ['unassigned judge', false],
    'regular member' => ['regular member', false],
    'admin' => ['admin', true],
]);
