<?php

use App\Models\CarShow;
use App\Models\Expense;

test('uses the expense table with timestamps and guards only id', function () {
    $expense = new Expense;

    expect($expense->getTable())->toBe('expense')
        ->and($expense->getKeyName())->toBe('id')
        ->and($expense->usesTimestamps())->toBeTrue()
        ->and($expense->getGuarded())->toBe(['id']);
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
