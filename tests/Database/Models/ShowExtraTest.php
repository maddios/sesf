<?php

use App\Enums\ExtraType;
use App\Models\CarShow;
use App\Models\ShowExtra;
use Illuminate\Database\QueryException;

test('uses the show_extra table with timestamps and guards its privilege and money columns', function () {
    $extra = new ShowExtra;

    expect($extra->getTable())->toBe('show_extra')
        ->and($extra->getKeyName())->toBe('id')
        ->and($extra->usesTimestamps())->toBeTrue()
        ->and($extra->getGuarded())->toBe(['id', 'price']);
});

test('casts type to ExtraType, price to money, and min/max/flags', function () {
    $extra = ShowExtra::factory()->ofType(ExtraType::NumericList)->create([
        'price' => '19.999',
        'min_value' => '2',
        'max_value' => '50',
        'is_enabled' => 1,
    ])->refresh();

    expect($extra->type_id)->toBe(ExtraType::NumericList)
        ->and($extra->price)->toBe('20.00')
        ->and($extra->min_value)->toBe(2)
        ->and($extra->max_value)->toBe(50)
        ->and($extra->is_enabled)->toBeTrue();
});

test('auth_key is unique and nullable', function () {
    ShowExtra::factory()->create(['auth_key' => 'key-1']);
    ShowExtra::factory()->create(['auth_key' => null]);
    ShowExtra::factory()->create(['auth_key' => null]);

    expect(fn () => ShowExtra::factory()->create(['auth_key' => 'key-1']))
        ->toThrow(QueryException::class);
});

test('carShow relation works in both directions', function () {
    $show = CarShow::factory()->create();
    $extra = ShowExtra::factory()->create(['show_id' => $show->id]);

    expect($extra->carShow->id)->toBe($show->id)
        ->and($show->showExtras->pluck('id')->all())->toBe([$extra->id]);
});

test('toJson keys: price stays a string with two decimals', function () {
    $extra = ShowExtra::factory()->create(['price' => 3]);

    expect(json_decode($extra->toJson(), true)['price'])->toBe('3.00');
});

test('price accepts up to the double(10,2) limit and rejects more', function () {
    expect(fn () => (new ShowExtra)->setAttribute('price', '99999999.99'))->not->toThrow(InvalidArgumentException::class);
    expect(fn () => (new ShowExtra)->setAttribute('price', '100000000.00'))->toThrow(InvalidArgumentException::class);
});

test('auth_key is public by design, so it is not hidden and appears in toArray() and toJson()', function () {
    $extra = ShowExtra::factory()->create(['auth_key' => 'public-key-123'])->refresh();

    expect($extra->getHidden())->toBe([])
        ->and($extra->toArray())->toHaveKey('auth_key', 'public-key-123')
        ->and(json_decode($extra->toJson(), true))->toHaveKey('auth_key', 'public-key-123');
});

test('mass assignment ignores the guarded price column', function () {
    $extra = new ShowExtra;
    $extra->fill(['price' => '1.00', 'name' => 'Lunch']);

    expect($extra->getAttributes())->not->toHaveKey('price')
        ->and($extra->name)->toBe('Lunch');
});
