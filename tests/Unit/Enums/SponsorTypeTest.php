<?php

use App\Enums\SponsorType;

dataset('sponsor types', [
    [SponsorType::M, 1, 'M'],
    [SponsorType::Gold, 2, 'Gold'],
    [SponsorType::Silber, 3, 'Silber'],
    [SponsorType::Bronzit, 4, 'Bronzit'],
]);

it('matches legacy values and labels', function (SponsorType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('sponsor types');

it('resolves legacy ids including numeric strings', function (SponsorType $case, int $value) {
    expect(SponsorType::tryFromLegacy($value))->toBe($case)
        ->and(SponsorType::tryFromLegacy((string) $value))->toBe($case);
})->with('sponsor types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(SponsorType::tryFromLegacy($value))->toBeNull();
})->with([[0], [5], ['99'], ['abc'], [''], [null]]);
