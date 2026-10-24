<?php

use App\Support\Money;

dataset('amounts to cents', [
    'zero string' => ['0', 0],
    'zero float' => [0.0, 0],
    'half a cent rounds up' => ['0.005', 1],
    'half a cent float rounds up' => [0.005, 1],
    'just under half a cent rounds down' => ['0.004', 0],
    'string with one decimal' => ['1234.5', 123450],
    'float with one decimal' => [1234.5, 123450],
    'string with two decimals' => ['12.34', 1234],
    'float with two decimals' => [12.34, 1234],
    'rounding carries into dollars' => ['0.995', 100],
    'rounding carries across the decimal' => ['9.999', 1000],
    'whitespace is trimmed' => [' 5 ', 500],
    'whole number' => ['42', 4200],
]);

dataset('rejected amounts', [
    'negative string' => ['-1'],
    'negative float' => [-0.01],
    'non-numeric string' => ['abc'],
    'malformed decimal' => ['1.2.3'],
    'empty string' => [''],
    'NAN' => [NAN],
    'INF' => [INF],
    'string too large for int cents' => ['99999999999999999999'],
    'float too large for int cents' => [1e20],
    'one cent over PHP_INT_MAX' => ['92233720368547758.08'],
    'whole dollars over the cent limit' => ['92233720368547759.00'],
    'fraction over the cent limit' => ['92233720368547758.9'],
    'absurdly long digit string' => [str_repeat('9', 400)],
]);

dataset('largest valid amounts', [
    'exactly PHP_INT_MAX cents' => ['92233720368547758.07', PHP_INT_MAX],
    'largest whole dollars that fit' => ['92233720368547757.99', 9223372036854775799],
    'largest whole dollars with zero cents' => ['92233720368547758.00', 9223372036854775800],
]);

dataset('formatted amounts', [
    'zero' => [0, '$0.00'],
    'five cents' => [5, '$0.05'],
    'thousands separator' => [123450, '$1,234.50'],
    'millions' => [100000000, '$1,000,000.00'],
]);

test('toCents converts amounts to integer cents', function (string|float $amount, int $expected) {
    expect(Money::toCents($amount))->toBe($expected);
})->with('amounts to cents');

test('toCents accepts the largest amounts that fit in int cents', function (string $amount, int $expected) {
    expect(Money::toCents($amount))->toBe($expected);
})->with('largest valid amounts');

test('toCents rejects invalid amounts', function (string|float $amount) {
    Money::toCents($amount);
})->with('rejected amounts')->throws(InvalidArgumentException::class);

test('toCents rejects oversized amounts with a clear message', function (string|float $amount) {
    Money::toCents($amount);
})->with([
    'string too large' => ['99999999999999999999'],
    'float too large' => [1e20],
])->throws(InvalidArgumentException::class, 'Amount is too large.');

test('format renders cents as a dollar string', function (int $cents, string $expected) {
    expect(Money::format($cents))->toBe($expected);
})->with('formatted amounts');

test('format rejects negative cents', function () {
    Money::format(-1);
})->throws(InvalidArgumentException::class, 'Cents must not be negative.');
