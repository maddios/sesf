<?php

use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarShow;
use App\Models\CarType;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Illuminate\Support\Facades\DB;

/**
 * Creates a show registration for a car via raw inserts (the ShowRegistration model comes later).
 */
function registerCarForShow(int $carId, int $showId, int $userId): void
{
    $registrationId = DB::connection('testing_mariadb')->table('show_registration')->insertGetId([
        'show_id' => $showId,
        'user_id' => $userId,
        'invoice_id' => fake()->unique()->numberBetween(1, 65000),
    ]);

    DB::connection('testing_mariadb')->table('show_registration_car')->insert([
        'car_id' => $carId,
        'registration_id' => $registrationId,
    ]);
}

test('maps to the legacy car table with timestamps and guarded id', function () {
    $car = new Car;

    expect($car->getTable())->toBe('car')
        ->and($car->getKeyName())->toBe('id')
        ->and($car->usesTimestamps())->toBeTrue()
        ->and($car->getGuarded())->toBe(['id']);
});

test('casts flags to boolean and foreign keys to integer', function () {
    $car = Car::factory()->withImage()->create()->refresh();

    expect($car->has_image)->toBeTrue()
        ->and($car->is_confirmed)->toBeTrue()
        ->and($car->user_id)->toBeInt()
        ->and($car->body_id)->toBeInt()
        ->and($car->type_id)->toBeInt();
});

test('year stays a string because the legacy column is varchar(5)', function () {
    $car = Car::factory()->create(['year' => '1969'])->refresh();

    expect($car->year)->toBe('1969');
});

test('zero and empty timestamps read as null', function () {
    $car = Car::factory()->create();
    DB::connection('testing_mariadb')->table('car')->where('id', $car->id)
        ->update(['updated_at' => '0000-00-00 00:00:00']);

    $fresh = Car::query()->findOrFail($car->id);

    expect($fresh->updated_at)->toBeNull()
        ->and($fresh->created_at)->not->toBeNull();
});

test('sold cars have a null owner and the owner relation returns null', function () {
    $car = Car::factory()->sold()->create()->refresh();

    expect($car->user_id)->toBeNull()
        ->and($car->user)->toBeNull();
});

test('accessor keys are present in toArray and toJson', function () {
    $car = Car::factory()->create(['year' => '1969', 'model' => 'Camaro']);
    $keys = ['imageURL', 'profileURL', 'name', 'fullName', 'editableModel'];

    foreach ($keys as $key) {
        expect($car->toArray())->toHaveKey($key);
        expect($car->toJson())->toContain('"'.$key.'"');
    }
});

test('profileURL is the legacy hash route for the car', function () {
    $car = Car::factory()->create();

    expect($car->profileURL)->toBe('#/car/'.$car->id);
});

test('name and fullName are "year model" and editableModel is the model text', function () {
    $car = Car::factory()->create(['year' => '1969', 'model' => 'Camaro']);

    expect($car->name)->toBe('1969 Camaro')
        ->and($car->fullName)->toBe('1969 Camaro')
        ->and($car->editableModel)->toBe('Camaro');
});

test('setting editableModel writes the model column', function () {
    $car = Car::factory()->create(['model' => 'Old']);

    $car->editableModel = 'New';
    $car->save();

    expect(Car::query()->findOrFail($car->id)->model)->toBe('New');
});

test('imageURL is the generic image when the car has no image', function () {
    $car = Car::factory()->create(['has_image' => false]);

    expect($car->imageURL)->toBe(config('sesf.image_url').'car-generic.jpg');
});

test('imageURL cache-busts with filemtime only when the image file exists', function () {
    $root = sys_get_temp_dir().'/sesf-car-images-'.uniqid().'/';
    config(['sesf.car_image_root' => $root]);
    $car = Car::factory()->withImage()->make(['id' => 4242]);

    expect($car->imageURL)->toBe(config('sesf.car_image_url').'4242/profile.jpg');

    mkdir($root.'4242', 0777, true);
    file_put_contents($root.'4242/profile.jpg', 'x');
    touch($root.'4242/profile.jpg', 1700000000);

    expect($car->imageURL)->toBe(config('sesf.car_image_url').'4242/profile.jpg?1700000000');

    unlink($root.'4242/profile.jpg');
    rmdir($root.'4242');
    rmdir($root);
});

test('user relation returns the owner and User::cars returns the car', function () {
    $user = User::factory()->create();
    $car = Car::factory()->create(['user_id' => $user->id]);

    expect($car->user->id)->toBe($user->id)
        ->and($user->cars()->pluck('id')->all())->toBe([$car->id]);
});

test('body relation and CarBody::cars are inverse', function () {
    $body = CarBody::query()->findOrFail(CarBodyFactory::E24);
    $car = Car::factory()->create(['body_id' => $body->id]);

    expect($car->body->id)->toBe($body->id)
        ->and($body->cars()->pluck('id')->all())->toBe([$car->id]);
});

test('type relation and CarType::cars are inverse', function () {
    $car = Car::factory()->motorcycle()->create();
    $type = CarType::query()->findOrFail($car->type_id);

    expect($car->type->name)->toBe('Motorcycle')
        ->and($type->cars()->pluck('id')->all())->toBe([$car->id]);
});

test('whereShowId returns each car registered for the show once', function () {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    $car = Car::factory()->create();
    $otherCar = Car::factory()->create();

    registerCarForShow($car->id, $show->id, $car->user_id);
    registerCarForShow($car->id, $other->id, $car->user_id);
    registerCarForShow($otherCar->id, $other->id, $otherCar->user_id);

    $ids = Car::query()->whereShowId($show->id)->pluck('id')->all();

    expect($ids)->toBe([$car->id]);
});

test('whereBodyId filters by body', function () {
    $body = CarBody::query()->findOrFail(CarBodyFactory::E63_COUPE);
    $match = Car::factory()->create(['body_id' => $body->id]);
    Car::factory()->create();

    $ids = Car::query()->whereBodyId($body->id)->pluck('id')->all();

    expect($ids)->toBe([$match->id]);
});

test('restrictCarsByBodyByShow keeps only bodies allowed at the show and adds body_name', function () {
    $show = CarShow::factory()->create();
    $allowed = CarBody::query()->findOrFail(CarBodyFactory::E63_COUPE);
    $blocked = CarBody::query()->findOrFail(CarBodyFactory::E64_CONVERTIBLE);
    DB::connection('testing_mariadb')->table('car_body_show')->insert([
        'body_id' => $allowed->id,
        'show_id' => $show->id,
    ]);

    $match = Car::factory()->create(['body_id' => $allowed->id]);
    Car::factory()->create(['body_id' => $blocked->id]);

    $rows = Car::query()->restrictCarsByBodyByShow($show->id)->get();

    $first = $rows->first();

    expect($rows->pluck('id')->all())->toBe([$match->id]);
    expect($first?->getAttribute('body_name'))->toBe('e63 (Coupe)');
});
