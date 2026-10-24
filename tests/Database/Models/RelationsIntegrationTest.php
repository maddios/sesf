<?php

use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarShow;
use App\Models\CarType;
use App\Models\Gallery;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Database\Factories\CarFactory;

// Each relation is checked in both directions against real rows and real foreign keys.
// Lookup rows (car_body, car_type, user_type, gallery_type) come from the fixture.

test('User -> Car and Car -> User are inverse', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $car = Car::factory()->create(['user_id' => $owner->id]);
    Car::factory()->create(['user_id' => $other->id]);

    expect($owner->cars()->pluck('car.id')->all())->toBe([$car->id])
        ->and($car->user->is($owner))->toBeTrue();
});

test('a sold car has no owner in either direction', function () {
    $car = Car::factory()->sold()->create();
    $user = User::factory()->create();

    expect($car->user)->toBeNull()
        ->and($user->cars()->count())->toBe(0);
});

test('Car -> CarBody and CarBody -> Car are inverse', function () {
    $body = CarBody::query()->findOrFail(CarBodyFactory::E63_COUPE);
    $car = Car::factory()->create(['body_id' => $body->id]);
    Car::factory()->create(['body_id' => CarBodyFactory::E24]);

    expect($car->body->is($body))->toBeTrue()
        ->and($body->cars()->pluck('car.id')->all())->toBe([$car->id]);
});

test('Car -> CarType and CarType -> Car are inverse', function () {
    $car = Car::factory()->motorcycle()->create();
    Car::factory()->create(['type_id' => CarFactory::TYPE_CAR]);

    $type = CarType::query()->findOrFail(CarFactory::TYPE_MOTORCYCLE);

    expect($car->type->name)->toBe('Motorcycle')
        ->and($type->cars()->pluck('car.id')->all())->toBe([$car->id]);
});

test('CarShow -> CarBody and CarBody -> CarShow go through car_body_show', function () {
    $show = CarShow::factory()->create();
    $otherShow = CarShow::factory()->create();
    $coupe = CarBody::query()->findOrFail(CarBodyFactory::E63_COUPE);
    $convertible = CarBody::query()->findOrFail(CarBodyFactory::E64_CONVERTIBLE);

    $show->carBodies()->attach([$coupe->id, $convertible->id]);
    $otherShow->carBodies()->attach($convertible->id);

    expect($show->carBodies()->pluck('car_body.id')->sort()->values()->all())
        ->toBe([$coupe->id, $convertible->id])
        ->and($coupe->shows()->pluck('car_show.id')->all())->toBe([$show->id])
        ->and($convertible->shows()->pluck('car_show.id')->sort()->values()->all())
        ->toBe([$show->id, $otherShow->id]);
});

test('CarShow -> Gallery and Gallery -> CarShow are inverse', function () {
    $show = CarShow::factory()->create();
    $gallery = Gallery::factory()->create(['show_id' => $show->id]);
    Gallery::factory()->create();

    expect($show->galleries()->pluck('gallery.id')->all())->toBe([$gallery->id])
        ->and($gallery->show->is($show))->toBeTrue();
});

test('Gallery -> User is the owner', function () {
    $owner = User::factory()->create();
    $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

    expect($gallery->user->is($owner))->toBeTrue()
        ->and($gallery->user_id)->toBe($owner->id);
});
