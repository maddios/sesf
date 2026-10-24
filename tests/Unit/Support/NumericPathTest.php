<?php

use App\Support\NumericPath;

/*
 * Expected values are the output of the legacy StringHelper::numeric_path(), checked against the
 * original PHP function. Ids are zero-padded to three characters, and only the first three
 * characters are used, so the path never goes deeper than three levels.
 */
dataset('legacy numeric paths', [
    'zero' => [0, '0/0/0'],
    'single digit' => [5, '0/0/5'],
    'two digits' => [12, '0/1/2'],
    'three digits' => [123, '1/2/3'],
    'four digits uses the first three' => [1234, '1/2/3'],
    'five digits uses the first three' => [12345, '1/2/3'],
    'eight digits uses the first three' => [987654321, '9/8/7'],
    'round thousand' => [1000, '1/0/0'],
    'numeric string' => ['42', '0/4/2'],
    'leading zeros in a string are kept' => ['007', '0/0/7'],
    'non-digit input is padded like legacy' => ['-5', '0/-/5'],
]);

test('reproduces the legacy numeric_path output', function (int|string $id, string $expected): void {
    expect(NumericPath::for($id))->toBe($expected);
})->with('legacy numeric paths');

test('the result has at most three segments', function (): void {
    expect(explode('/', NumericPath::for(9999999)))->toHaveCount(3);
});
