<?php

use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use Illuminate\Database\QueryException;

test('uses the show_extra_registration table with timestamps and guards only id', function () {
    $row = new ShowExtraRegistration;

    expect($row->getTable())->toBe('show_extra_registration')
        ->and($row->getKeyName())->toBe('id')
        ->and($row->usesTimestamps())->toBeTrue()
        ->and($row->getGuarded())->toBe(['id']);
});

test('num_extras casts to an int', function () {
    $row = ShowExtraRegistration::factory()->quantity(4)->create()->refresh();

    expect($row->num_extras)->toBe(4);
});

test('unique key (show_extra_id, registration_id) rejects the same extra twice on one registration', function () {
    $row = ShowExtraRegistration::factory()->create();

    expect(fn () => ShowExtraRegistration::factory()->create([
        'show_extra_id' => $row->show_extra_id,
        'registration_id' => $row->registration_id,
    ]))->toThrow(QueryException::class);
});

test('showExtra and registration relations work in both directions', function () {
    $registration = ShowRegistration::factory()->create();
    $extra = ShowExtra::factory()->create(['show_id' => $registration->show_id]);
    $row = ShowExtraRegistration::factory()->create([
        'show_extra_id' => $extra->id,
        'registration_id' => $registration->id,
    ]);

    expect($row->showExtra->id)->toBe($extra->id)
        ->and($row->registration->id)->toBe($registration->id)
        ->and($registration->extras->pluck('id')->all())->toBe([$row->id])
        ->and($extra->registration->pluck('id')->all())->toBe([$registration->id]);
});
