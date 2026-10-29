<?php

use App\Models\CarShow;
use App\Models\ShirtType;
use App\Services\Shirts\ShowShirtCatalog;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('an event without rows offers every type at its default price and is not configured', function () {
    $show = CarShow::factory()->create();

    $menu = (new ShowShirtCatalog)->forShow($show->id);

    expect($menu->configured)->toBeFalse();
    expect(count($menu->offered()))->toBe(ShirtType::query()->count());
    expect($menu->priceOf(ShirtTypeFactory::MENS_S_POLO))->toBe('55.00');
    expect($menu->find(ShirtTypeFactory::MENS_S_POLO)?->showPrice)->toBeNull();
});

test('a configured event offers only its rows, effective price = override ?? default', function () {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    DB::table('show_shirt_type')->insert([
        ['show_id' => $show->id, 'shirt_type_id' => ShirtTypeFactory::MENS_S_POLO, 'price' => 12.345],
        ['show_id' => $show->id, 'shirt_type_id' => ShirtTypeFactory::MENS_S_TSHIRT, 'price' => null],
        ['show_id' => $other->id, 'shirt_type_id' => ShirtTypeFactory::MENS_M_TSHIRT, 'price' => 1],
    ]);

    $menu = (new ShowShirtCatalog)->forShow($show->id);

    expect($menu->configured)->toBeTrue();
    expect(array_map(fn ($type) => [$type->id, $type->price], $menu->offered()))
        ->toBe([[ShirtTypeFactory::MENS_S_TSHIRT, '35.00'], [ShirtTypeFactory::MENS_S_POLO, '12.35']]);
    expect($menu->isOffered(ShirtTypeFactory::MENS_M_TSHIRT))->toBeFalse();
    // Not offered, still priced (legacy lines without a snapshot) and listed for the admin.
    expect($menu->priceOf(ShirtTypeFactory::MENS_M_TSHIRT))->toBe('35.00');
    expect(count($menu->all()))->toBe(ShirtType::query()->count());
    expect($menu->priceOf(999))->toBeNull();
});

test('a line price is the snapshot, else the effective price, else zero for a missing type', function () {
    $show = CarShow::factory()->create();
    DB::table('show_shirt_type')->insert(['show_id' => $show->id, 'shirt_type_id' => ShirtTypeFactory::MENS_S_POLO, 'price' => 40]);

    $menu = (new ShowShirtCatalog)->forShow($show->id);

    expect($menu->linePrice('28.00', ShirtTypeFactory::MENS_S_POLO))->toBe('28.00');
    expect($menu->linePrice(null, ShirtTypeFactory::MENS_S_POLO))->toBe('40.00');
    expect($menu->linePrice(null, 999))->toBe('0.00');
});

test('deleting an event deletes its shirt offer (ON DELETE CASCADE)', function () {
    $show = CarShow::factory()->create();
    DB::table('show_shirt_type')->insert(['show_id' => $show->id, 'shirt_type_id' => 1, 'price' => null]);

    $show->delete();

    expect(DB::table('show_shirt_type')->where('show_id', $show->id)->count())->toBe(0);
});

test('the foreign keys refuse an unknown event or shirt type', function () {
    $show = CarShow::factory()->create();

    expect(fn () => DB::table('show_shirt_type')->insert(['show_id' => $show->id, 'shirt_type_id' => 999, 'price' => null]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('show_shirt_type')->insert(['show_id' => 32000, 'shirt_type_id' => 1, 'price' => null]))
        ->toThrow(QueryException::class);
});
