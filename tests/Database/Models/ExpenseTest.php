<?php

use App\Models\CarShow;
use App\Models\Expense;

test('uses the expense table with timestamps and guards its privilege and money columns', function () {
    $expense = new Expense;

    expect($expense->getTable())->toBe('expense')
        ->and($expense->getKeyName())->toBe('id')
        ->and($expense->usesTimestamps())->toBeTrue()
        ->and($expense->getGuarded())->toBe(['id', 'amount']);
});

test('amount uses the money cast and is stored as given, without sign changes', function () {
    $expense = Expense::factory()->create(['amount' => '-12.5'])->refresh();

    expect($expense->amount)->toBe('-12.50')
        ->and(Expense::factory()->create(['amount' => 12.5])->refresh()->amount)->toBe('12.50');
});

test('show relation works in both directions, and an expense may have no show', function () {
    $show = CarShow::factory()->create();
    $expense = Expense::factory()->create(['show_id' => $show->id]);
    $unattached = Expense::factory()->withoutShow()->create();

    expect($expense->show->id)->toBe($show->id)
        ->and($unattached->show)->toBeNull()
        ->and($unattached->refresh()->show_id)->toBeNull();
});

test('amount rejects amounts above the float(8,2) limit', function () {
    expect(fn () => (new Expense)->setAttribute('amount', '1000000.00'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Expense)->setAttribute('amount', '999999.99'))->not->toThrow(InvalidArgumentException::class);
});

test('mass assignment ignores the guarded amount column', function () {
    $expense = new Expense;
    $expense->fill(['amount' => '5.00', 'description' => 'Ice']);

    expect($expense->getAttributes())->not->toHaveKey('amount')
        ->and($expense->description)->toBe('Ice');
});
