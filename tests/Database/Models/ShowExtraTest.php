<?php

use App\Enums\ExtraType;
use App\Models\CarShow;
use App\Models\ShowExtra;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('show_extra_type')->insertOrIgnore([['id' => 1, 'name' => 'Cars'], ['id' => 2, 'name' => 'People'], ['id' => 3, 'name' => 'Registration'], ['id' => 4, 'name' => 'Numeric List']]);
});

test('uses the show_extra table with timestamps and guards only id', function () {
    $extra = new ShowExtra;

    expect($extra->getTable())->toBe('show_extra')
        ->and($extra->getKeyName())->toBe('id')
        ->and($extra->usesTimestamps())->toBeTrue()
        ->and($extra->getGuarded())->toBe(['id']);
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
