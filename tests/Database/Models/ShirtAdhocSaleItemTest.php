<?php

use App\Models\ShirtAdhocSale;
use App\Models\ShirtAdhocSaleItem;
use App\Models\ShirtType;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Database\QueryException;

test('uses the shirt_adhoc_sale_item table with timestamps and guards only id', function () {
    $item = new ShirtAdhocSaleItem;

    expect($item->getTable())->toBe('shirt_adhoc_sale_item')
        ->and($item->usesTimestamps())->toBeTrue()
        ->and($item->getGuarded())->toBe(['id']);
});

test('relations: sale and shirtType, and the inverse items and adhocSaleItems', function () {
    $item = ShirtAdhocSaleItem::factory()->create(['type_id' => ShirtTypeFactory::MENS_M_TSHIRT]);

    expect($item->sale)->toBeInstanceOf(ShirtAdhocSale::class)
        ->and($item->sale->items->pluck('id')->all())->toBe([$item->id])
        ->and($item->shirtType)->toBeInstanceOf(ShirtType::class)
        ->and($item->shirtType->adhocSaleItems->pluck('id')->all())->toContain($item->id);
});

test('a sale item for a missing sale violates the foreign key', function () {
    expect(fn () => ShirtAdhocSaleItem::factory()->create(['sale_id' => 999999999]))
        ->toThrow(QueryException::class);
});

test('a sale item for a missing shirt type violates the foreign key', function () {
    expect(fn () => ShirtAdhocSaleItem::factory()->create(['type_id' => 9999]))
        ->toThrow(QueryException::class);
});
