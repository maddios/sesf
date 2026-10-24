<?php

use App\Models\Casts\Money2;
use Illuminate\Database\Eloquent\Model;

function money2Model(): Model
{
    return new class extends Model {};
}

dataset('money2 stored values', [
    'null' => [null, null],
    'zero float' => [0.0, '0.00'],
    'two decimals' => [12.34, '12.34'],
    'one decimal string from the db' => ['12.3', '12.30'],
    'whole float' => [7.0, '7.00'],
    'string with two decimals' => ['99.99', '99.99'],
    'float that binary-drifts below the half' => [1.005, '1.01'],
    'third decimal half rounds up' => ['2.345', '2.35'],
    'third decimal below half rounds down' => ['2.344', '2.34'],
    'negative amount' => [-3.5, '-3.50'],
]);

dataset('money2 written values', [
    'null' => [null, null],
    'blank string' => ['', null],
    'int' => [42, 42.0],
    'float' => [12.34, 12.34],
    'numeric string' => ['12.34', 12.34],
    'numeric string with spaces' => [' 5 ', 5.0],
    'half cent string rounds up' => ['0.125', 0.13],
    'float with binary drift rounds up' => [1.005, 1.01],
    'exponent notation' => ['1e3', 1000.0],
    'negative string' => ['-2.5', -2.5],
]);

test('get formats the stored value with exactly two decimals', function (mixed $stored, ?string $expected) {
    $cast = new Money2;

    expect($cast->get(money2Model(), 'price', $stored, []))->toBe($expected);
})->with('money2 stored values');

test('set stores a float rounded to two decimals, and null stays null', function (mixed $input, ?float $expected) {
    $cast = new Money2;

    expect($cast->set(money2Model(), 'price', $input, []))->toBe($expected);
})->with('money2 written values');

test('set returns a float, never a string or int', function () {
    $stored = (new Money2)->set(money2Model(), 'price', '10', []);

    expect($stored)->toBeFloat()->toBe(10.0);
});

test('set rejects non-numeric input', function (mixed $input) {
    expect(fn () => (new Money2)->set(money2Model(), 'price', $input, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'word' => ['abc'],
    'mixed' => ['12abc'],
    'nan' => [NAN],
    'infinity' => [INF],
    'array' => [[1]],
    'bool' => [true],
]);

test('get rejects a non-numeric stored value', function () {
    expect(fn () => (new Money2)->get(money2Model(), 'price', 'abc', []))
        ->toThrow(InvalidArgumentException::class);
});
