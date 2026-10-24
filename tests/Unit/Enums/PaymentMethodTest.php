<?php

use App\Enums\PaymentMethod;

dataset('payment methods', [
    [PaymentMethod::PayPal, 1, 'PayPal'],
    [PaymentMethod::Check, 2, 'Check'],
]);

it('matches legacy values and labels', function (PaymentMethod $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('payment methods');

it('resolves legacy ids including numeric strings', function (PaymentMethod $case, int $value) {
    expect(PaymentMethod::tryFromLegacy($value))->toBe($case)
        ->and(PaymentMethod::tryFromLegacy((string) $value))->toBe($case);
})->with('payment methods');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(PaymentMethod::tryFromLegacy($value))->toBeNull();
})->with([[0], [3], ['99'], ['abc'], [''], [null]]);
