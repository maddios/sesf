<?php

use App\Models\CarShow;
use App\Models\CarShowLocation;

test('uses the show_location table with timestamps and guards only id', function () {
    $location = new CarShowLocation;

    expect($location->getTable())->toBe('show_location')
        ->and($location->getKeyName())->toBe('id')
        ->and($location->usesTimestamps())->toBeTrue()
        ->and($location->getGuarded())->toBe(['id']);
});

test('casts is_primary to boolean and coordinates to float', function () {
    $location = CarShowLocation::factory()->primary()->create([
        'lattitude' => '35.7796',
        'longitude' => '-78.6382',
    ])->refresh();

    expect($location->is_primary)->toBeTrue()
        ->and($location->lattitude)->toBe(35.7796)
        ->and($location->longitude)->toBe(-78.6382);
});

test('cityState and fullAddress keys are present and formatted as legacy', function () {
    $location = CarShowLocation::factory()->create([
        'address1' => '100 Main St',
        'address2' => 'Suite 2',
        'city' => 'Raleigh',
        'state' => 'NC',
        'zip' => '27601',
    ]);

    expect($location->toArray())->toHaveKeys(['cityState', 'fullAddress'])
        ->and(json_decode($location->toJson(), true))->toHaveKeys(['cityState', 'fullAddress'])
        ->and($location->cityState)->toBe('Raleigh, NC')
        ->and($location->fullAddress)->toBe('100 Main St Suite 2 Raleigh, NC 27601');
});

test('fullAddress keeps the legacy double space when address2 is empty', function () {
    $location = CarShowLocation::factory()->create(['address1' => '1 A', 'address2' => null, 'city' => 'C', 'state' => 'S', 'zip' => 'Z']);

    expect($location->fullAddress)->toBe('1 A  C, S Z');
});

test('sort_order is assigned as max plus one within the same show', function () {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();

    $first = CarShowLocation::factory()->create(['show_id' => $show->id]);
    $second = CarShowLocation::factory()->create(['show_id' => $show->id]);
    $otherShow = CarShowLocation::factory()->create(['show_id' => $other->id]);

    expect($first->sort_order)->toBe(1)
        ->and($second->sort_order)->toBe(2)
        ->and($otherShow->sort_order)->toBe(1);
});

test('an explicit non-zero sort_order is kept', function () {
    $show = CarShow::factory()->create();

    $location = CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 7]);

    expect($location->sort_order)->toBe(7);
});

test('the sort_order hook does not geocode the address', function () {
    $location = CarShowLocation::factory()->create(['lattitude' => null, 'longitude' => null]);

    expect($location->refresh()->lattitude)->toBeNull()
        ->and($location->longitude)->toBeNull();
});

test('carShow relation resolves the parent show', function () {
    $show = CarShow::factory()->create();
    $location = CarShowLocation::factory()->create(['show_id' => $show->id]);

    expect($location->carShow->id)->toBe($show->id)
        ->and($show->locations()->count())->toBe(1);
});
