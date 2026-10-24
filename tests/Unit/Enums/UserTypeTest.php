<?php

use App\Enums\UserType;

dataset('user types', [
    [UserType::Regular, 1, 'Regular'],
    [UserType::Judge, 2, 'Judge'],
    [UserType::Administrator, 3, 'Administrator'],
]);

it('matches legacy values and labels', function (UserType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('user types');

it('resolves legacy ids including numeric strings', function (UserType $case, int $value) {
    expect(UserType::tryFromLegacy($value))->toBe($case)
        ->and(UserType::tryFromLegacy((string) $value))->toBe($case);
})->with('user types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(UserType::tryFromLegacy($value))->toBeNull();
})->with([[0], [4], ['99'], ['abc'], [''], [null]]);

it('exposes role helpers', function () {
    expect(UserType::Administrator->isAdmin())->toBeTrue()
        ->and(UserType::Judge->isAdmin())->toBeFalse()
        ->and(UserType::Judge->isJudge())->toBeTrue()
        ->and(UserType::Regular->isJudge())->toBeFalse();
});
