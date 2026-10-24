<?php

use App\Models\Concerns\HasLegacyDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const LEGACY_ZERO_DATETIME = '0000-00-00 00:00:00';

/**
 * Throwaway model for the legacy `car` table, which has created_at and updated_at
 * columns that default to zero dates.
 */
function legacyDatesCarModel(): Model
{
    return new class extends Model
    {
        use HasLegacyDates;

        protected $connection = 'testing_mariadb';

        protected $table = 'car';

        protected $guarded = [];

        public $timestamps = true;
    };
}

/**
 * Inserts a car row with the given timestamps and returns its id. The car_body and car_type
 * rows it references come from the lookup fixture.
 */
function insertLegacyDatesCar(string $createdAt, string $updatedAt): int
{
    $db = DB::connection('testing_mariadb');

    return (int) $db->table('car')->insertGetId([
        'user_id' => null,
        'body_id' => 1,
        'type_id' => 1,
        'nickname' => 'Legacy',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);
}

test('zero timestamps read as null through the model', function () {
    $id = insertLegacyDatesCar(LEGACY_ZERO_DATETIME, LEGACY_ZERO_DATETIME);

    $car = legacyDatesCarModel()->newQuery()->findOrFail($id);

    expect($car->getAttribute('created_at'))->toBeNull()
        ->and($car->getAttribute('updated_at'))->toBeNull()
        ->and($car->getAttributes()['created_at'])->toBe(LEGACY_ZERO_DATETIME);
});

test('toArray and toJson give null for zero timestamps, not a negative year', function () {
    $id = insertLegacyDatesCar(LEGACY_ZERO_DATETIME, LEGACY_ZERO_DATETIME);

    $car = legacyDatesCarModel()->newQuery()->findOrFail($id);

    expect($car->toArray())->toHaveKey('created_at', null)
        ->toHaveKey('updated_at', null);

    $json = json_decode($car->toJson(), true);
    expect($json['created_at'])->toBeNull()
        ->and($json['updated_at'])->toBeNull()
        ->and($car->toJson())->not->toContain('-0001');
});

test('save works on a row whose timestamps are zero dates', function () {
    $id = insertLegacyDatesCar(LEGACY_ZERO_DATETIME, LEGACY_ZERO_DATETIME);

    $car = legacyDatesCarModel()->newQuery()->findOrFail($id);
    $car->setAttribute('nickname', 'Saved');
    $car->save();

    $row = DB::connection('testing_mariadb')->table('car')->where('id', $id)->first();

    expect($row->nickname)->toBe('Saved')
        ->and($row->updated_at)->not->toBe(LEGACY_ZERO_DATETIME)
        ->and($row->created_at)->toBe(LEGACY_ZERO_DATETIME);
});

test('a normal datetime round-trips through the model and save', function () {
    $id = insertLegacyDatesCar('2024-05-06 07:08:09', '2024-05-06 07:08:10');

    $car = legacyDatesCarModel()->newQuery()->findOrFail($id);

    expect($car->getAttribute('created_at'))->toBeInstanceOf(Carbon::class)
        ->and($car->getAttribute('created_at')->format('Y-m-d H:i:s'))->toBe('2024-05-06 07:08:09')
        ->and($car->toArray()['created_at'])->not->toBeNull();

    $car->setAttribute('created_at', '2023-01-02 03:04:05');
    $car->save();

    $stored = DB::connection('testing_mariadb')->table('car')->where('id', $id)->value('created_at');
    expect($stored)->toBe('2023-01-02 03:04:05');
});

test('the date cast tolerates a zero date', function () {
    $id = insertLegacyDatesCar(LEGACY_ZERO_DATETIME, LEGACY_ZERO_DATETIME);

    $car = new class extends Model
    {
        use HasLegacyDates;

        protected $connection = 'testing_mariadb';

        protected $table = 'car';

        protected $casts = ['created_at' => 'date'];
    };

    $found = $car->newQuery()->findOrFail($id);

    expect($found->getAttribute('created_at'))->toBeNull()
        ->and($found->toArray()['created_at'])->toBeNull();
});
