<?php

use App\Models\Shirt;
use App\Models\ShirtAdhocSaleItem;
use App\Models\ShirtType;
use Database\Factories\ShirtTypeFactory;

test('uses the shirt_type table without timestamps', function () {
    $type = new ShirtType;

    expect($type->getTable())->toBe('shirt_type')
        ->and($type->usesTimestamps())->toBeFalse()
        ->and($type->getGuarded())->toBe(['id']);
});

test('fixture rows are present and price is a Money2 string', function () {
    $type = ShirtType::query()->findOrFail(ShirtTypeFactory::MENS_S_POLO);

    expect($type->name)->toBe('Mens S Polo shirt')
        ->and($type->price)->toBe('55.00');
});

test('the factory adds an extra row with a decimal price', function () {
    $type = ShirtType::factory()->create(['price' => '12.345'])->refresh();

    expect($type->getKey())->toBeGreaterThan(ShirtTypeFactory::MENS_S_POLO)
        ->and($type->price)->toBe('12.35');
});

test('shirts and adhocSaleItems are hasMany on shirt.type_id and adhoc item type_id', function () {
    $type = ShirtType::query()->findOrFail(ShirtTypeFactory::MENS_M_TSHIRT);
    $shirt = Shirt::factory()->create(['type_id' => $type->getKey()]);
    $item = ShirtAdhocSaleItem::factory()->ofType($type)->create();

    expect($type->shirts->pluck('id')->all())->toContain($shirt->id)
        ->and($type->adhocSaleItems->pluck('id')->all())->toContain($item->id)
        ->and($shirt->shirtType->getKey())->toBe($type->getKey())
        ->and($item->shirtType->getKey())->toBe($type->getKey());
});
