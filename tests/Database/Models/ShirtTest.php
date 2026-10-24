<?php

use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtType;
use App\Models\ShowRegistration;
use App\Models\User;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;

test('uses the shirt table with timestamps and guards its privilege and money columns', function () {
    $shirt = new Shirt;

    expect($shirt->getTable())->toBe('shirt')
        ->and($shirt->getKeyName())->toBe('id')
        ->and($shirt->usesTimestamps())->toBeTrue()
        ->and($shirt->getGuarded())->toBe(['id', 'price', 'is_handed_out']);
});

test('price is a Money2 string and is_handed_out is boolean', function () {
    $shirt = Shirt::factory()->create(['price' => '35.5', 'is_handed_out' => 1])->refresh();

    expect($shirt->price)->toBe('35.50')
        ->and($shirt->is_handed_out)->toBeTrue();
});

test('price can be null for legacy rows', function () {
    $shirt = Shirt::factory()->unpriced()->create()->refresh();

    expect($shirt->price)->toBeNull();
});

test('zero updated_at in a raw row reads as null', function () {
    $registration = ShowRegistration::factory()->create();
    $id = DB::table('shirt')->insertGetId([
        'user_id' => User::factory()->create()->id,
        'registration_id' => $registration->id,
        'type_id' => ShirtTypeFactory::MENS_S_TSHIRT,
        'show_id' => $registration->show_id,
        'price' => 35.00,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    expect(Shirt::query()->findOrFail($id)->updated_at)->toBeNull();
});

test('relations: registration, shirtType, user and carShow', function () {
    $shirt = Shirt::factory()->create();

    expect($shirt->registration)->toBeInstanceOf(ShowRegistration::class)
        ->and($shirt->shirtType->getKey())->toBe(ShirtTypeFactory::MENS_M_TSHIRT)
        ->and($shirt->user)->toBeInstanceOf(User::class)
        ->and($shirt->carShow)->toBeInstanceOf(CarShow::class);
});

test('shirts are reachable from the registration and the shirt type', function () {
    $shirt = Shirt::factory()->create();
    $type = ShirtType::query()->findOrFail(ShirtTypeFactory::MENS_M_TSHIRT);

    expect($shirt->registration->shirts->pluck('id')->all())->toBe([$shirt->id])
        ->and($type->shirts->pluck('id'))->toContain($shirt->id);
});

test('show_id follows the registration when not given', function () {
    $registration = ShowRegistration::factory()->create();

    $shirt = Shirt::factory()->create(['registration_id' => $registration->id]);

    expect($shirt->show_id)->toBe($registration->show_id);
});

test('scopes forShow and handedOut', function () {
    $show = CarShow::factory()->create();
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
    $handed = Shirt::factory()->handedOut()->create(['registration_id' => $registration->id, 'show_id' => $show->id]);
    $pending = Shirt::factory()->create(['registration_id' => $registration->id, 'show_id' => $show->id]);
    Shirt::factory()->create();

    expect(Shirt::query()->forShow($show->id)->pluck('id')->sort()->values()->all())
        ->toBe([$handed->id, $pending->id])
        ->and(Shirt::query()->handedOut()->pluck('id')->all())->toBe([$handed->id])
        ->and(Shirt::query()->handedOut(false)->pluck('id')->sort()->values()->all())
        ->toContain($pending->id);
});

test('no legacy accessor is appended to JSON', function () {
    $shirt = Shirt::factory()->create()->refresh();

    expect($shirt->toArray())->not->toHaveKey('name')
        ->and(json_decode($shirt->toJson(), true))->not->toHaveKey('name');
});

test('price rejects amounts above the float(6,2) limit', function () {
    expect(fn () => (new Shirt)->setAttribute('price', '10000.00'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Shirt)->setAttribute('price', '9999.99'))->not->toThrow(InvalidArgumentException::class);
});

test('mass assignment ignores guarded price and handed-out columns', function () {
    $shirt = new Shirt;
    $shirt->fill(['price' => '1.00', 'is_handed_out' => true, 'type_id' => 3]);

    expect($shirt->getAttributes())->not->toHaveKeys(['price', 'is_handed_out'])
        ->and($shirt->type_id)->toBe(3);
});

test('the factory price follows the catalogue price of its shirt type', function () {
    $shirt = Shirt::factory()->create()->refresh();

    expect($shirt->price)->toBe(ShirtType::query()->findOrFail(ShirtTypeFactory::MENS_M_TSHIRT)->price)
        ->and($shirt->price)->toBe('35.00');
});
