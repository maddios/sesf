<?php

use App\Enums\UserType;
use App\Models\Casts\LegacyEnum;
use Illuminate\Database\Eloquent\Model;

function legacyEnumModel(): Model
{
    return new class extends Model {};
}

enum LegacyEnumWithoutTrait: int
{
    case One = 1;
}

test('get returns the enum case for known legacy values', function (mixed $stored) {
    $cast = new LegacyEnum(UserType::class);

    expect($cast->get(legacyEnumModel(), 'type_id', $stored, []))->toBe(UserType::Judge);
})->with([
    'int' => [2],
    'numeric string from the database' => ['2'],
]);

test('get returns null for null, blank and unknown values instead of throwing', function (mixed $stored) {
    $cast = new LegacyEnum(UserType::class);

    expect($cast->get(legacyEnumModel(), 'type_id', $stored, []))->toBeNull();
})->with([
    'null' => [null],
    'blank' => [''],
    'unknown int' => [99],
    'unknown numeric string' => ['0'],
    'unknown string 0 id' => ['9999'],
    'non-numeric text' => ['judge'],
    'float' => [1.5],
    'array' => [[1]],
]);

test('set stores the backing value of an enum case', function () {
    $cast = new LegacyEnum(UserType::class);

    expect($cast->set(legacyEnumModel(), 'type_id', UserType::Administrator, []))->toBe(3);
});

test('set keeps null as null', function () {
    expect(new LegacyEnum(UserType::class)->set(legacyEnumModel(), 'type_id', null, []))->toBeNull();
});

test('set keeps an unknown int as-is and stores numeric strings as ints', function (mixed $input, ?int $expected) {
    $cast = new LegacyEnum(UserType::class);

    expect($cast->set(legacyEnumModel(), 'type_id', $input, []))->toBe($expected);
})->with([
    'unknown int is kept' => [99, 99],
    'known int' => [1, 1],
    'numeric string' => ['2', 2],
    'numeric string with spaces' => [' 3 ', 3],
]);

test('set rejects input that is neither an enum case, an int nor a numeric string', function (mixed $input) {
    expect(fn () => new LegacyEnum(UserType::class)->set(legacyEnumModel(), 'type_id', $input, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'word' => ['judge'],
    'float' => [1.5],
    'array' => [[1]],
    'blank string' => [''],
]);

test('set rejects a case of a different enum', function () {
    expect(fn () => new LegacyEnum(UserType::class)->set(legacyEnumModel(), 'type_id', LegacyEnumWithoutTrait::One, []))
        ->toThrow(InvalidArgumentException::class);
});

test('the enum must be a backed enum that uses TriesLegacyValues', function (string $class) {
    expect(fn () => new LegacyEnum($class))->toThrow(InvalidArgumentException::class);
})->with([
    'enum without the trait' => [LegacyEnumWithoutTrait::class],
    'not an enum' => [stdClass::class],
    'missing class' => ['App\\Enums\\DoesNotExist'],
]);

test('an Eloquent model with an unknown legacy value loads and serializes without throwing', function () {
    $model = new class extends Model
    {
        protected $table = 'user';

        protected function casts(): array
        {
            return ['type_id' => LegacyEnum::of(UserType::class)];
        }
    };

    $model->setRawAttributes(['type_id' => '77']);

    expect($model->getAttribute('type_id'))->toBeNull()
        ->and($model->toArray())->toHaveKey('type_id', null);
});

test('of() returns the cast string that Eloquent resolves to the cast', function () {
    expect(LegacyEnum::of(UserType::class))->toBe(LegacyEnum::class.':'.UserType::class);
});

test('the cast string form parses the enum class and reads known values', function () {
    $model = new class extends Model
    {
        protected $table = 'user';

        protected function casts(): array
        {
            return ['type_id' => LegacyEnum::of(UserType::class)];
        }
    };

    $model->setRawAttributes(['type_id' => '3']);
    $model->setAttribute('type_id', UserType::Regular);

    expect($model->getAttribute('type_id'))->toBe(UserType::Regular)
        ->and($model->getAttributes()['type_id'])->toBe(1);
});
