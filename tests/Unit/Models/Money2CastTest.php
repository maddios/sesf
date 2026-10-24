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

test('max argument accepts values up to and including the limit', function (mixed $input, float $expected) {
    $cast = new Money2('9999.99');

    expect($cast->set(money2Model(), 'price', $input, []))->toBe($expected);
})->with([
    'just under the float(6,2) limit' => ['9999.98', 9999.98],
    'exactly the float(6,2) limit' => ['9999.99', 9999.99],
    'negative at the limit is allowed' => ['-9999.99', -9999.99],
]);

test('max argument rejects values larger than the limit', function (mixed $input) {
    expect(fn () => (new Money2('9999.99'))->set(money2Model(), 'price', $input, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'one cent over' => ['10000.00'],
    'int far over' => [123456],
    'float over' => [10000.5],
    'negative over' => ['-10000.00'],
    'rounds up over the limit' => ['9999.995'],
]);

test('max argument does not change get() and null still stores null', function () {
    $cast = new Money2('9999.99');

    expect($cast->get(money2Model(), 'price', '9999.9', []))->toBe('9999.90')
        ->and($cast->set(money2Model(), 'price', null, []))->toBeNull();
});

test('without max any finite amount is accepted, as before', function () {
    expect((new Money2)->set(money2Model(), 'price', 123456.78, []))->toBe(123456.78);
});

test('the max argument must be a valid amount', function () {
    expect(fn () => new Money2('abc'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new Money2('-1'))->toThrow(InvalidArgumentException::class);
});

test('an empty max argument means no limit', function () {
    expect((new Money2(''))->set(money2Model(), 'price', 123456.78, []))->toBe(123456.78);
});

test('the cast string argument sets the max on an Eloquent attribute', function () {
    $model = new class extends Model
    {
        protected $table = 'car_show';

        protected function casts(): array
        {
            return ['price_car' => Money2::class.':9999.99'];
        }
    };

    expect(fn () => $model->setAttribute('price_car', '10000.00'))->toThrow(InvalidArgumentException::class);

    $model->setAttribute('price_car', '9999.99');

    expect($model->getAttributes()['price_car'])->toBe(9999.99);
});
