<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

/**
 * Row counts exported from the live reference tables (tests/Fixtures/lookup-data.sql).
 *
 * @return array<string, int>
 */
function expectedLookupCounts(): array
{
    return [
        'user_type' => 3,
        'user_guest_type' => 2,
        'sponsor_type' => 4,
        'show_agenda_type' => 2,
        'show_extra_type' => 4,
        'gallery_item_type' => 1,
        'gallery_type' => 1,
        'judging_type' => 3,
        'payment_method' => 2,
        'car_type' => 2,
        'car_body' => 6,
        'shirt_type' => 22,
        'judging_form' => 3,
        'judging_form_question' => 44,
    ];
}

function lookupConnection(): Connection
{
    return DB::connection(LegacyTestDatabase::CONNECTION);
}

test('each lookup table holds the fixture row count', function (string $table, int $expected) {
    expect(lookupConnection()->table($table)->count())->toBe($expected);
})->with(array_map(
    fn (string $table, int $count): array => [$table, $count],
    array_keys(expectedLookupCounts()),
    expectedLookupCounts(),
));

test('fixture rows keep their legacy ids', function () {
    $db = lookupConnection();

    expect($db->table('user_type')->orderBy('id')->pluck('name')->all())->toBe(['Regular', 'Judge', 'Administrator'])
        ->and($db->table('car_type')->where('id', 1)->value('name'))->toBe('Car')
        ->and($db->table('car_type')->where('id', 2)->value('name'))->toBe('Motorcycle')
        ->and($db->table('car_body')->where('id', 1)->value('name'))->toBe('e24')
        ->and($db->table('shirt_type')->where('id', 22)->value('price'))->toEqual(55);
});

test('a lookup insert inside a test is visible to that test', function () {
    lookupConnection()->table('car_body')->insert(['id' => 999, 'name' => 'Rolled back body']);

    expect(lookupConnection()->table('car_body')->count())->toBe(7);
});

test('a lookup insert from the previous test is rolled back', function () {
    expect(lookupConnection()->table('car_body')->count())->toBe(6)
        ->and(lookupConnection()->table('car_body')->where('id', 999)->exists())->toBeFalse();
});

test('loading the fixture again is a no-op', function () {
    LegacyTestDatabase::loadLookupFixture(lookupConnection(), base_path(LegacyTestDatabase::LOOKUP_FIXTURE));

    expect(lookupConnection()->table('user_type')->count())->toBe(3)
        ->and(lookupConnection()->table('judging_form_question')->count())->toBe(44);
});

test('a failing lookup statement throws and names the fixture file', function () {
    $db = lookupConnection();
    // Empty the probe table inside this test's transaction, so the loader does not skip.
    $db->table('user_type')->delete();

    $path = sys_get_temp_dir().'/lookup-bad-'.uniqid().'.sql';
    file_put_contents($path, "INSERT INTO no_such_lookup_table (id) VALUES (1);\n");

    try {
        expect(fn () => LegacyTestDatabase::loadLookupFixture($db, $path))
            ->toThrow(RuntimeException::class, basename($path));
    } finally {
        unlink($path);
    }
});
