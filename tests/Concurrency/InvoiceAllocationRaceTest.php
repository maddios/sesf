<?php

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;
use Tests\TestCase;

/*
 * Real multi-process race for InvoiceNumberAllocator (see docs/concurrency.md).
 *
 * Workers are separate PHP processes with their own database sessions, so the data they need
 * must be committed: this file does NOT use the per-test rollback transaction. It creates its
 * own fixtures and deletes them in finally blocks. It only runs against the ephemeral sesf_test
 * database (same guard as tests/Database).
 */

uses(TestCase::class)->group('concurrency');

beforeEach(function () {
    $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
    LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);
    config(['database.default' => LegacyTestDatabase::CONNECTION]);

    $connection = DB::connection(LegacyTestDatabase::CONNECTION);
    $actual = (string) $connection->selectOne('select database() as db')->db;
    if ($actual !== LegacyTestDatabase::REQUIRED_DATABASE) {
        throw new RuntimeException("Refusing to run: connected to [{$actual}].");
    }

    LegacyTestDatabase::ensureSchema($connection, base_path(LegacyTestDatabase::SCHEMA_FILE));
});

/**
 * Starts one worker per user, waits until all are READY (snapshots taken), releases the barrier
 * and collects one JSON result per worker.
 *
 * @param  list<int>  $userIds
 * @return list<array{exit: int, result: array<string, mixed>|null, stderr: string}>
 */
function runInvoiceRace(string $mode, int $showId, array $userIds): array
{
    $barrier = 'sesf_invoice_race_'.getmypid().'_'.$showId;
    $worker = __DIR__.'/Support/invoice-race-worker.php';
    $env = array_merge(getenv(), ['APP_ENV' => 'testing']);

    // The parent holds the barrier until every worker is waiting on it.
    expect((int) DB::selectOne('select get_lock(?, 10) as got', [$barrier])->got)->toBe(1);

    $procs = [];

    try {
        foreach ($userIds as $userId) {
            $pipes = [];
            $proc = proc_open(
                [PHP_BINARY, $worker, $mode, (string) $showId, (string) $userId, $barrier],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                $env,
            );
            if (! is_resource($proc)) {
                throw new RuntimeException('proc_open failed');
            }
            fclose($pipes[0]);
            $procs[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'lines' => [], 'stderr' => ''];
        }

        // Phase 1: every worker has booted and (outer mode) taken its snapshot.
        foreach ($procs as $i => $p) {
            $line = fgets($p['out']);
            if ($line === false || ! str_contains($line, '"ready"')) {
                throw new RuntimeException("Worker {$i} did not get ready: ".$line.stream_get_contents($p['err']));
            }
        }

        // Phase 2: wait until all workers block on the barrier, then release them together.
        $deadline = microtime(true) + 15;
        do {
            $waiting = (int) DB::selectOne(
                "select count(*) as n from information_schema.processlist where info like 'select get_lock(%'"
            )->n;
            if ($waiting >= count($procs)) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
    } finally {
        DB::selectOne('select release_lock(?) as released', [$barrier]);
    }

    $results = [];
    foreach ($procs as $p) {
        $line = stream_get_contents($p['out']);
        $stderr = (string) stream_get_contents($p['err']);
        fclose($p['out']);
        fclose($p['err']);
        $exit = proc_close($p['proc']);
        $decoded = json_decode(trim((string) $line), true);
        $results[] = ['exit' => $exit, 'result' => is_array($decoded) ? $decoded : null, 'stderr' => $stderr];
    }

    return $results;
}

test('concurrent registrations for one show get unique, consecutive invoice numbers', function (string $mode, int $workers, int $existing) {
    $show = CarShow::factory()->create();
    $userIds = [];

    try {
        for ($i = 0; $i < $existing; $i++) {
            $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
            $userIds[] = $registration->user_id;
        }

        $racers = User::factory()->count($workers)->create()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $userIds = array_merge($userIds, $racers);

        $results = runInvoiceRace($mode, $show->id, $racers);

        $failures = array_values(array_filter($results, fn (array $r): bool => $r['exit'] !== 0 || ($r['result']['ok'] ?? false) !== true));
        expect($failures)->toBe([], sprintf(
            '%d of %d workers failed (%s mode): %s',
            count($failures),
            $workers,
            $mode,
            implode(' | ', array_map(fn (array $r): string => (string) ($r['result']['error'] ?? $r['stderr']), $failures)),
        ));

        $returned = array_map(fn (array $r): int => (int) $r['result']['invoice_id'], $results);
        sort($returned);
        $expected = range($existing + 1, $existing + $workers);

        // In insert (id) order the numbers strictly increase with no gaps.
        $stored = ShowRegistration::query()->where('show_id', $show->id)->orderBy('id')
            ->pluck('invoice_id')->map(fn ($n): int => (int) $n)->all();

        expect($returned)->toBe($expected);
        expect($stored)->toBe(range(1, $existing + $workers));
        expect(count(array_unique($stored)))->toBe($existing + $workers);
    } finally {
        DB::table('show_registration')->where('show_id', $show->id)->delete();
        if ($userIds !== []) {
            DB::table('user_preferences')->whereIn('user_id', $userIds)->delete();
            DB::table('user')->whereIn('id', $userIds)->delete();
        }
        DB::table('car_show')->where('id', $show->id)->delete();
    }
})->with([
    'no outer transaction' => ['plain', 6, 0],
    'outer transaction that read first' => ['outer', 6, 0],
    'outer transaction, show already has registrations' => ['outer', 8, 3],
]);
