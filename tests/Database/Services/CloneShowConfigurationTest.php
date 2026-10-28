<?php

use App\Models\CarShow;
use App\Services\CloneShowConfiguration;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/** @param  list<int>  $bodyIds */
function cloneSeedBodies(int $showId, array $bodyIds): void
{
    foreach ($bodyIds as $bodyId) {
        DB::table('car_body_show')->insert(['body_id' => $bodyId, 'show_id' => $showId]);
    }
}

function cloneSeedJudging(int $showId, int $judgingTypeId, int $carBodyId): void
{
    DB::table('judging_show_body')->insert([
        'judging_type_id' => $judgingTypeId,
        'car_body_id' => $carBodyId,
        'car_show_id' => $showId,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => null,
    ]);
}

test('candidateSources lists id and name, newest date_start first', function () {
    $older = CarShow::factory()->create(['name' => 'Older', 'date_start' => '2023-05-01']);
    $newer = CarShow::factory()->create(['name' => 'Newer', 'date_start' => '2025-05-01']);

    $sources = (new CloneShowConfiguration)->candidateSources();

    expect($sources->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($sources->first()->name)->toBe('Newer')
        ->and(array_keys($sources->first()->getAttributes()))->toEqualCanonicalizing(['id', 'name']);
});

test('copy duplicates exactly the source body and judging rows onto the target', function () {
    $source = CarShow::factory()->create(['date_start' => '2024-05-01']);
    $target = CarShow::factory()->create(['date_start' => '2026-05-01']);
    $unrelated = CarShow::factory()->create(['date_start' => '2022-05-01']);

    cloneSeedBodies($source->id, [1, 2]);
    cloneSeedJudging($source->id, 1, 1);
    cloneSeedJudging($source->id, 2, 2);
    cloneSeedBodies($unrelated->id, [3]);
    cloneSeedJudging($unrelated->id, 1, 3);

    (new CloneShowConfiguration)->copy($target, $source);

    $bodies = DB::table('car_body_show')->where('show_id', $target->id)->orderBy('body_id')->pluck('body_id')->map(fn ($id) => (int) $id)->all();
    $judging = DB::table('judging_show_body')
        ->where('car_show_id', $target->id)
        ->orderBy('car_body_id')
        ->get(['judging_type_id', 'car_body_id'])
        ->map(fn ($row) => [(int) $row->judging_type_id, (int) $row->car_body_id])
        ->all();

    expect($bodies)->toBe([1, 2])
        ->and($judging)->toBe([[1, 1], [2, 2]])
        ->and(DB::table('judging_show_body')->where('car_show_id', $target->id)->whereNotNull('created_at')->count())->toBe(2)
        ->and(DB::table('car_body_show')->where('show_id', $source->id)->count())->toBe(2)
        ->and(DB::table('judging_show_body')->where('car_show_id', $source->id)->count())->toBe(2)
        ->and(DB::table('car_body_show')->where('show_id', $unrelated->id)->count())->toBe(1)
        ->and(DB::table('judging_show_body')->where('car_show_id', $unrelated->id)->count())->toBe(1);
});

test('copy rejects cloning a show from itself', function () {
    $show = CarShow::factory()->create();
    cloneSeedBodies($show->id, [1]);

    expect(fn () => (new CloneShowConfiguration)->copy($show, $show))
        ->toThrow(InvalidArgumentException::class, 'Cannot clone car/judging setup from the same event.');
});

test('copy rejects a source that is not in the database', function () {
    $target = CarShow::factory()->create();
    $ghost = new CarShow(['id' => 999999]);

    expect(fn () => (new CloneShowConfiguration)->copy($target, $ghost))
        ->toThrow(InvalidArgumentException::class, 'Source or target event was not found.');
});

test('copy rejects a target that is not in the database', function () {
    $source = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);
    $ghost = new CarShow(['id' => 999998]);

    expect(fn () => (new CloneShowConfiguration)->copy($ghost, $source))
        ->toThrow(InvalidArgumentException::class, 'Source or target event was not found.');
});

test('copy rejects a source with no car bodies and writes nothing', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();

    expect(fn () => (new CloneShowConfiguration)->copy($target, $source))
        ->toThrow(InvalidArgumentException::class, 'car_body_show is empty');

    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(0);
});

test('copy is atomic: a failure in the judging insert rolls back the body rows', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1, 2]);
    cloneSeedJudging($source->id, 1, 1);

    // Swap in a private event dispatcher so the failing listener only applies to this test.
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $connection->setEventDispatcher(new Dispatcher(app()));

    try {
        DB::listen(function (QueryExecuted $query): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'judging_show_body')) {
                throw new RuntimeException('forced failure');
            }
        });

        expect(fn () => (new CloneShowConfiguration)->copy($target, $source))
            ->toThrow(RuntimeException::class, 'forced failure');
    } finally {
        $connection->setEventDispatcher($original);
    }

    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(0)
        ->and(DB::table('judging_show_body')->where('car_show_id', $target->id)->count())->toBe(0);
});

test('copy refuses a target that already has car bodies and changes nothing', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1, 2]);
    cloneSeedJudging($source->id, 1, 1);
    cloneSeedBodies($target->id, [1]);

    expect(fn () => (new CloneShowConfiguration)->copy($target, $source))
        ->toThrow(InvalidArgumentException::class, 'already configured (1 car bodies, 0 judging rows, 0 shirt types)');

    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(1);
    expect(DB::table('judging_show_body')->where('car_show_id', $target->id)->count())->toBe(0);
});

test('copy refuses a target that has only judging rows (no unique key would stop duplicates)', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);
    cloneSeedJudging($source->id, 1, 1);
    cloneSeedJudging($target->id, 1, 1);

    expect(fn () => (new CloneShowConfiguration)->copy($target, $source))
        ->toThrow(InvalidArgumentException::class, 'already configured (0 car bodies, 1 judging rows, 0 shirt types)');

    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(0);
    expect(DB::table('judging_show_body')->where('car_show_id', $target->id)->count())->toBe(1);
});

test('copy a second time onto the same target is refused', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1, 2]);
    cloneSeedJudging($source->id, 1, 1);

    (new CloneShowConfiguration)->copy($target, $source);

    expect(fn () => (new CloneShowConfiguration)->copy($target, $source))
        ->toThrow(InvalidArgumentException::class, 'already configured');
    expect(DB::table('judging_show_body')->where('car_show_id', $target->id)->count())->toBe(1);
});

test('copy of a source with bodies but no judging rows copies the bodies only', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [2, 3]);

    (new CloneShowConfiguration)->copy($target, $source);

    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(2);
    expect(DB::table('judging_show_body')->where('car_show_id', $target->id)->count())->toBe(0);
});

test('every check runs inside the copy transaction, after both shows are locked', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);
    $outerLevel = DB::transactionLevel();
    $selects = [];
    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects[] = [strtolower($query->sql), $query->connection->transactionLevel()];
        }
    });

    (new CloneShowConfiguration)->copy($target, $source);

    expect($selects)->toHaveCount(5);
    expect($selects[0][0])->toContain('`car_show`')->toContain('order by `id` asc')->toEndWith('for update');
    foreach ($selects as [$sql, $level]) {
        expect($level)->toBe($outerLevel + 1);
        expect(str_ends_with($sql, 'for update') || str_ends_with($sql, 'lock in share mode'))->toBeTrue();
    }
});

test('copy duplicates the source shirt offer (types and price overrides) onto the target', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    $unrelated = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);
    DB::table('show_shirt_type')->insert([
        ['show_id' => $source->id, 'shirt_type_id' => 1, 'price' => 18.5],
        ['show_id' => $source->id, 'shirt_type_id' => 3, 'price' => null],
        ['show_id' => $unrelated->id, 'shirt_type_id' => 2, 'price' => 9],
    ]);

    (new CloneShowConfiguration)->copy($target, $source);

    $rows = DB::table('show_shirt_type')->where('show_id', $target->id)->orderBy('shirt_type_id')->get()
        ->map(fn ($row) => [(int) $row->shirt_type_id, $row->price === null ? null : number_format((float) $row->price, 2, '.', '')])
        ->all();
    expect($rows)->toBe([[1, '18.50'], [3, null]]);
});

test('copy of a source without a shirt offer leaves the target offering every type at its default', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);

    (new CloneShowConfiguration)->copy($target, $source);

    expect(DB::table('show_shirt_type')->where('show_id', $target->id)->count())->toBe(0);
});

test('copy onto a target that already has a shirt offer is refused and changes nothing', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);
    DB::table('show_shirt_type')->insert(['show_id' => $target->id, 'shirt_type_id' => 2, 'price' => null]);

    expect(fn () => (new CloneShowConfiguration)->copy($target, $source))
        ->toThrow(InvalidArgumentException::class, 'already configured (0 car bodies, 0 judging rows, 1 shirt types)');
    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(0);
});

test('a failing shirt copy rolls back the body and judging copy (one transaction)', function () {
    $source = CarShow::factory()->create();
    $target = CarShow::factory()->create();
    cloneSeedBodies($source->id, [1]);
    cloneSeedJudging($source->id, 1, 1);
    DB::table('show_shirt_type')->insert(['show_id' => $source->id, 'shirt_type_id' => 1, 'price' => null]);
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'INSERT INTO show_shirt_type')) {
            throw new RuntimeException('shirt copy failed');
        }
    });

    expect(fn () => (new CloneShowConfiguration)->copy($target, $source))->toThrow(RuntimeException::class, 'shirt copy failed');
    expect(DB::table('car_body_show')->where('show_id', $target->id)->count())->toBe(0);
    expect(DB::table('judging_show_body')->where('car_show_id', $target->id)->count())->toBe(0);
    expect(DB::table('show_shirt_type')->where('show_id', $target->id)->count())->toBe(0);
});
