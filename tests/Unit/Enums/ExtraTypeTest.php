<?php

use App\Enums\ExtraType;

dataset('extra types', [
    [ExtraType::Cars, 1, 'Cars'],
    [ExtraType::People, 2, 'Guests'],
    [ExtraType::Registration, 3, 'Registration'],
    [ExtraType::NumericList, 4, 'Numeric List'],
]);

it('matches legacy values and labels', function (ExtraType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('extra types');

it('resolves legacy ids including numeric strings', function (ExtraType $case, int $value) {
    expect(ExtraType::tryFromLegacy($value))->toBe($case)
        ->and(ExtraType::tryFromLegacy((string) $value))->toBe($case);
})->with('extra types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(ExtraType::tryFromLegacy($value))->toBeNull();
})->with([[0], [5], ['99'], ['abc'], [''], [null]]);
