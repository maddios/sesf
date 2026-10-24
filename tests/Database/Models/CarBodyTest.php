<?php

use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarShow;
use Database\Factories\CarBodyFactory;
use Illuminate\Support\Facades\DB;

test('maps to car_body with a manual primary key and no timestamps', function () {
    $body = new CarBody;

    expect($body->getTable())->toBe('car_body')
        ->and($body->getKeyName())->toBe('id')
        ->and($body->getIncrementing())->toBeFalse()
        ->and($body->usesTimestamps())->toBeFalse()
        ->and($body->getGuarded())->toBe(['id']);
});

test('keeps the id that was assigned by hand', function () {
    expect(CarBody::query()->findOrFail(CarBodyFactory::E64_CONVERTIBLE)->name)->toBe('e64 (Conv)');
});

test('cars relation returns the cars with this body', function () {
    $body = CarBody::query()->findOrFail(CarBodyFactory::E24);
    $car = Car::factory()->create(['body_id' => $body->id]);

    expect($body->cars()->pluck('id')->all())->toBe([$car->id]);
});

test('shows relation returns the shows where the body is allowed (car_body_show)', function () {
    $body = CarBody::query()->findOrFail(CarBodyFactory::E24);
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    DB::connection('testing_mariadb')->table('car_body_show')->insert([
        ['body_id' => $body->id, 'show_id' => $show->id],
    ]);

    expect($body->shows()->pluck('car_show.id')->all())->toBe([$show->id]);
});

test('CarShow::carBodies and CarBody::shows are inverse through car_body_show', function () {
    $body = CarBody::query()->findOrFail(CarBodyFactory::E24);
    $show = CarShow::factory()->create();
    DB::connection('testing_mariadb')->table('car_body_show')->insert([
        ['body_id' => $body->id, 'show_id' => $show->id],
    ]);

    expect($show->carBodies()->pluck('car_body.id')->all())->toBe([$body->id]);
    expect($body->shows()->pluck('car_show.id')->all())->toBe([$show->id]);
});

test('restrictBodyByShow keeps only bodies allowed at the show', function () {
    $show = CarShow::factory()->create();
    $allowed = CarBody::query()->findOrFail(CarBodyFactory::E24);
    CarBody::query()->findOrFail(CarBodyFactory::E63_COUPE);
    DB::connection('testing_mariadb')->table('car_body_show')->insert([
        ['body_id' => $allowed->id, 'show_id' => $show->id],
    ]);

    $ids = CarBody::query()->restrictBodyByShow($show->id)->pluck('car_body.id')->all();

    expect($ids)->toBe([$allowed->id]);
});
