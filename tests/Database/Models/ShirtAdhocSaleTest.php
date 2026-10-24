<?php

use App\Models\CarShow;
use App\Models\ShirtAdhocSale;
use App\Models\ShirtAdhocSaleItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Database\QueryException;

test('uses the shirt_adhoc_sale table with timestamps and guards its privilege and money columns', function () {
    $sale = new ShirtAdhocSale;

    expect($sale->getTable())->toBe('shirt_adhoc_sale')
        ->and($sale->usesTimestamps())->toBeTrue()
        ->and($sale->getGuarded())->toBe(['id', 'created_by_user_id']);
});

test('show_id and created_by_user_id are integers and created_at is a datetime', function () {
    $sale = ShirtAdhocSale::factory()->create()->refresh();

    expect($sale->show_id)->toBeInt()
        ->and($sale->created_by_user_id)->toBeInt()
        ->and($sale->created_at)->toBeInstanceOf(CarbonInterface::class);
});

test('relations: carShow, createdBy and items', function () {
    $show = CarShow::factory()->create();
    $user = User::factory()->create();
    $sale = ShirtAdhocSale::factory()->create(['show_id' => $show->id, 'created_by_user_id' => $user->id]);
    $item = ShirtAdhocSaleItem::factory()->create(['sale_id' => $sale->id]);

    expect($sale->carShow->getKey())->toBe($show->id)
        ->and($sale->createdBy->getKey())->toBe($user->id)
        ->and($sale->items->pluck('id')->all())->toBe([$item->id]);
});

test('the item side reaches the sale and its show', function () {
    $item = ShirtAdhocSaleItem::factory()->create();

    expect($item->sale)->toBeInstanceOf(ShirtAdhocSale::class)
        ->and($item->sale->carShow)->toBeInstanceOf(CarShow::class);
});

test('scope forShow keeps only the sales of that show', function () {
    $show = CarShow::factory()->create();
    $mine = ShirtAdhocSale::factory()->create(['show_id' => $show->id]);
    ShirtAdhocSale::factory()->create();

    expect(ShirtAdhocSale::query()->forShow($show->id)->pluck('id')->all())->toBe([$mine->id]);
});

test('a sale for a missing show violates the foreign key', function () {
    expect(fn () => ShirtAdhocSale::factory()->create(['show_id' => 30000]))
        ->toThrow(QueryException::class);
});

test('a sale for a missing user violates the foreign key', function () {
    expect(fn () => ShirtAdhocSale::factory()->create(['created_by_user_id' => 999999999]))
        ->toThrow(QueryException::class);
});

test('shirt type id is smallint-safe for items', function () {
    $item = ShirtAdhocSaleItem::factory()->create(['type_id' => ShirtTypeFactory::MENS_S_POLO]);

    expect($item->refresh()->type_id)->toBe(ShirtTypeFactory::MENS_S_POLO);
});

test('mass assignment ignores the guarded creator column', function () {
    $sale = new ShirtAdhocSale;
    $sale->fill(['created_by_user_id' => 5, 'show_id' => 9]);

    expect($sale->getAttributes())->not->toHaveKey('created_by_user_id')
        ->and($sale->show_id)->toBe(9);
});
