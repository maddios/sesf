<?php

use App\Enums\JudgingType;

dataset('judging types', [
    [JudgingType::Clean, 1, 'Clean'],
    [JudgingType::SuperClean, 2, 'Super Clean'],
    [JudgingType::Concours, 3, 'Concours'],
]);

it('matches legacy values and labels', function (JudgingType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('judging types');

it('resolves legacy ids including numeric strings', function (JudgingType $case, int $value) {
    expect(JudgingType::tryFromLegacy($value))->toBe($case)
        ->and(JudgingType::tryFromLegacy((string) $value))->toBe($case);
})->with('judging types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(JudgingType::tryFromLegacy($value))->toBeNull();
})->with([[0], [4], ['99'], ['abc'], [''], [null]]);
