<?php

use App\Enums\JudgingType;
use App\Models\Car;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use Illuminate\Database\QueryException;

test('uses the show_registration_car table with timestamps and guards only id', function () {
    $row = new ShowRegistrationCar;

    expect($row->getTable())->toBe('show_registration_car')
        ->and($row->getKeyName())->toBe('id')
        ->and($row->usesTimestamps())->toBeTrue()
        ->and($row->getGuarded())->toBe(['id']);
});

test('judging_type_id casts to JudgingType and may be null', function () {
    $judged = ShowRegistrationCar::factory()->judged(JudgingType::SuperClean)->create()->refresh();
    $unjudged = ShowRegistrationCar::factory()->create()->refresh();

    expect($judged->judging_type_id)->toBe(JudgingType::SuperClean)
        ->and($unjudged->judging_type_id)->toBeNull();
});

test('unique key (car_id, registration_id) rejects a car entered twice in one registration', function () {
    $row = ShowRegistrationCar::factory()->create();

    expect(fn () => ShowRegistrationCar::factory()->create([
        'car_id' => $row->car_id,
        'registration_id' => $row->registration_id,
    ]))->toThrow(QueryException::class);
});

test('the same car may be entered in two different registrations', function () {
    $car = Car::factory()->create();

    $first = ShowRegistrationCar::factory()->create(['car_id' => $car->id]);
    $second = ShowRegistrationCar::factory()->create(['car_id' => $car->id]);

    expect($first->registration_id)->not->toBe($second->registration_id);
});

test('car and registration relations work in both directions', function () {
    $registration = ShowRegistration::factory()->create();
    $car = Car::factory()->create();
    $row = ShowRegistrationCar::factory()->create([
        'car_id' => $car->id,
        'registration_id' => $registration->id,
    ]);

    expect($row->car->id)->toBe($car->id)
        ->and($row->registration->id)->toBe($registration->id)
        ->and($registration->registrationCars->pluck('id')->all())->toBe([$row->id])
        ->and($car->registrations->pluck('id')->all())->toBe([$registration->id]);
});
