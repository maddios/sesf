<?php

use App\Enums\UserGuestType;

dataset('user guest types', [
    [UserGuestType::Adult, 1, 'Adult'],
    [UserGuestType::Child, 2, 'Child (Under 13)'],
]);

it('matches legacy values and labels', function (UserGuestType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('user guest types');

it('resolves legacy ids including numeric strings', function (UserGuestType $case, int $value) {
    expect(UserGuestType::tryFromLegacy($value))->toBe($case)
        ->and(UserGuestType::tryFromLegacy((string) $value))->toBe($case);
})->with('user guest types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(UserGuestType::tryFromLegacy($value))->toBeNull();
})->with([[0], [3], ['99'], ['abc'], [''], [null]]);
