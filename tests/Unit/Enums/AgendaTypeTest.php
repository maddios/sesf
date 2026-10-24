<?php

use App\Enums\AgendaType;

dataset('agenda types', [
    [AgendaType::Agenda, 1, 'Agenda'],
    [AgendaType::Judging, 2, 'Judging'],
]);

it('matches legacy values and labels', function (AgendaType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('agenda types');

it('resolves legacy ids including numeric strings', function (AgendaType $case, int $value) {
    expect(AgendaType::tryFromLegacy($value))->toBe($case)
        ->and(AgendaType::tryFromLegacy((string) $value))->toBe($case);
})->with('agenda types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(AgendaType::tryFromLegacy($value))->toBeNull();
})->with([[0], [3], ['99'], ['abc'], [''], [null]]);
