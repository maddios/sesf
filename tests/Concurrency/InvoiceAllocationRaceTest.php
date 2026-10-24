<?php

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\InvoiceNumberAllocator;
use Illuminate\Database\QueryException;
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
 * Starts one worker per [show, user] job, waits until all are READY (snapshots taken) and all
 * block on the barrier, releases them together and collects one JSON result per worker.
 * Fails (after killing the workers) if they do not all reach the barrier in time.
 *
 * @param  list<array{0: int, 1: int}>  $jobs  [show_id, user_id] pairs.
 * @return list<array{show: int, exit: int, result: array<string, mixed>|null, stderr: string}>
 */
function runInvoiceRace(string $mode, array $jobs): array
{
    $barrier = 'sesf_invoice_race_'.getmypid().'_'.bin2hex(random_bytes(4));
    $worker = __DIR__.'/Support/invoice-race-worker.php';
    $env = array_merge(getenv(), ['APP_ENV' => 'testing']);

    // The parent holds the barrier until every worker is waiting on it.
    if ((int) DB::selectOne('select get_lock(?, 10) as got', [$barrier])->got !== 1) {
        throw new RuntimeException('Could not take the barrier lock.');
    }

    $procs = [];
    $arrived = false;

    try {
        foreach ($jobs as [$showId, $userId]) {
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
            $procs[] = ['show' => $showId, 'proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
        }

        // Phase 1: every worker has booted and (outer mode) taken its snapshot.
        foreach ($procs as $i => $p) {
            $line = fgets($p['out']);
            if ($line === false || ! str_contains($line, '"ready"')) {
                throw new RuntimeException("Worker {$i} did not get ready: ".$line.stream_get_contents($p['err']));
            }
        }

        // Phase 2: all workers must block on the barrier before it is released.
        $deadline = microtime(true) + 20;
        $waiting = 0;
        do {
            $waiting = (int) DB::selectOne(
                'select count(*) as n from information_schema.processlist where info like ?',
                ['select get_lock(%'],
            )->n;
            if ($waiting >= count($procs)) {
                $arrived = true;
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        if (! $arrived) {
            throw new RuntimeException(sprintf('Barrier timeout: %d of %d workers arrived.', $waiting, count($procs)));
        }
    } finally {
        if (! $arrived) {
            // Do not let stragglers register after the test has given up: kill them first.
            foreach ($procs as $p) {
                proc_terminate($p['proc'], 9);
                proc_close($p['proc']);
            }
        }
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
        $results[] = ['show' => $p['show'], 'exit' => $exit, 'result' => is_array($decoded) ? $decoded : null, 'stderr' => $stderr];
    }

    return $results;
}

/**
 * A snapshot of the server's deadlock history. Two equal snapshots mean no deadlock happened in
 * between.
 *  - MariaDB 10.11 (and 10.5+): the status counter Innodb_deadlocks, as "count:N".
 *  - MariaDB 10.3 has no such counter: the LATEST DETECTED DEADLOCK section of
 *    SHOW ENGINE INNODB STATUS, as "latest:<timestamp line>:<md5 of the section>", or
 *    "latest:none" when the server has never logged a deadlock.
 *
 * @throws RuntimeException when neither source is available.
 */
function deadlockSnapshot(): string
{
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");
    if ($row !== null && is_numeric($row->Value ?? null)) {
        return 'count:'.(int) $row->Value;
    }

    $status = DB::selectOne('show engine innodb status');
    $text = is_object($status) ? ($status->Status ?? null) : null;
    if (! is_string($text) || ! str_contains($text, 'TRANSACTIONS')) {
        throw new RuntimeException('Neither Innodb_deadlocks nor SHOW ENGINE INNODB STATUS is available.');
    }

    // Sections look like "------------------------\nLATEST DETECTED DEADLOCK\n------------------------\n...".
    if (preg_match('/\nLATEST DETECTED DEADLOCK\n-+\n(.*?)\n-+\n[A-Z][A-Z \/]+\n-+\n/s', $text, $m) !== 1) {
        return 'latest:none';
    }

    $firstLine = strtok($m[1], "\n");

    return 'latest:'.trim((string) $firstLine).':'.md5($m[1]);
}

/**
 * @param  list<int>  $showIds
 * @param  list<int>  $userIds
 */
function cleanupRaceFixtures(array $showIds, array $userIds): void
{
    if ($showIds !== []) {
        DB::table('show_registration')->whereIn('show_id', $showIds)->delete();
    }
    if ($userIds !== []) {
        DB::table('show_registration')->whereIn('user_id', $userIds)->delete();
        DB::table('user_preferences')->whereIn('user_id', $userIds)->delete();
        DB::table('user')->whereIn('id', $userIds)->delete();
    }
    if ($showIds !== []) {
        DB::table('car_show')->whereIn('id', $showIds)->delete();
    }
}

test('concurrent registrations across shows get per-show unique, consecutive numbers without deadlocks', function (string $mode, array $existingPerShow, int $workersPerShow) {
    $showIds = [];
    $userIds = [];

    try {
        // Created back to back, so the show ids are adjacent.
        foreach ($existingPerShow as $existing) {
            $showIds[] = (int) CarShow::factory()->create()->id;
        }
        foreach ($showIds as $i => $showId) {
            for ($n = 0; $n < $existingPerShow[$i]; $n++) {
                $userIds[] = (int) ShowRegistration::factory()->create(['show_id' => $showId])->user_id;
            }
        }

        $racers = User::factory()->count($workersPerShow * count($showIds))->create()
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $userIds = array_merge($userIds, $racers);

        // Interleave users across shows, so adjacent user ids land in different shows.
        $jobs = [];
        foreach ($racers as $k => $userId) {
            $jobs[] = [$showIds[$k % count($showIds)], $userId];
        }

        $deadlocksBefore = deadlockSnapshot();
        $results = runInvoiceRace($mode, $jobs);

        $failures = array_values(array_filter($results, fn (array $r): bool => $r['exit'] !== 0 || ($r['result']['ok'] ?? false) !== true));
        expect($failures)->toBe([], sprintf(
            '%d of %d workers failed (%s mode): %s',
            count($failures),
            count($jobs),
            $mode,
            implode(' | ', array_map(fn (array $r): string => (string) ($r['result']['error'] ?? $r['stderr']), $failures)),
        ));

        // No deadlock happened at all, not even one that a retry hid.
        expect(deadlockSnapshot())->toBe($deadlocksBefore);

        foreach ($showIds as $i => $showId) {
            $existing = $existingPerShow[$i];
            $returned = array_map(
                fn (array $r): int => (int) $r['result']['invoice_id'],
                array_values(array_filter($results, fn (array $r): bool => $r['show'] === $showId)),
            );
            sort($returned);

            // In insert (id) order the numbers strictly increase with no gaps.
            $stored = ShowRegistration::query()->where('show_id', $showId)->orderBy('id')
                ->pluck('invoice_id')->map(fn ($n): int => (int) $n)->all();

            expect($returned)->toBe(range($existing + 1, $existing + $workersPerShow));
            expect($stored)->toBe(range(1, $existing + $workersPerShow));
        }
    } finally {
        cleanupRaceFixtures($showIds, $userIds);
    }
})->with([
    'one show, no outer transaction' => ['plain', [0], 6],
    'one show, outer transaction that read first' => ['outer', [0], 6],
    'one show with registrations, outer transaction' => ['outer', [3], 8],
    'three empty adjacent shows, no outer transaction' => ['plain', [0, 0, 0], 4],
    'three empty adjacent shows, outer transaction' => ['outer', [0, 0, 0], 4],
    'three adjacent shows, some with registrations, outer transaction' => ['outer', [2, 0, 1], 4],
]);

/**
 * Allocator that throws a deadlock error for the first $failures calls, then allocates.
 *
 * @param  ArrayObject<int, int>  $calls  Receives one entry per allocate() call.
 */
function deadlockingAllocator(int $failures, ArrayObject $calls): InvoiceNumberAllocator
{
    return new class($failures, $calls) extends InvoiceNumberAllocator
    {
        /** @param  ArrayObject<int, int>  $calls */
        public function __construct(private int $failures, private ArrayObject $calls) {}

        public function allocate(int $showId): int
        {
            $this->calls[] = $showId;
            if (count($this->calls) <= $this->failures) {
                throw new QueryException(
                    LegacyTestDatabase::CONNECTION,
                    'select 1',
                    [],
                    new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
                );
            }

            return parent::allocate($showId);
        }
    };
}

test('a deadlock in a save() that owns the transaction is retried', function () {
    $show = CarShow::factory()->create();
    $user = User::factory()->create();
    /** @var ArrayObject<int, int> $calls */
    $calls = new ArrayObject;
    $allocator = deadlockingAllocator(ShowRegistration::CONCURRENCY_ATTEMPTS - 1, $calls);
    app()->instance(InvoiceNumberAllocator::class, $allocator);

    try {
        $registration = ShowRegistration::query()->create(['show_id' => $show->id, 'user_id' => $user->id]);

        expect($calls->count())->toBe(ShowRegistration::CONCURRENCY_ATTEMPTS);
        expect($registration->invoice_id)->toBe(1);
        expect(DB::transactionLevel())->toBe(0);
        expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(1);
    } finally {
        cleanupRaceFixtures([(int) $show->id], [(int) $user->id]);
    }
});

test('a deadlock that persists for CONCURRENCY_ATTEMPTS is rethrown', function () {
    $show = CarShow::factory()->create();
    $user = User::factory()->create();
    /** @var ArrayObject<int, int> $calls */
    $calls = new ArrayObject;
    $allocator = deadlockingAllocator(PHP_INT_MAX, $calls);
    app()->instance(InvoiceNumberAllocator::class, $allocator);

    try {
        expect(fn () => ShowRegistration::query()->create(['show_id' => $show->id, 'user_id' => $user->id]))
            ->toThrow(QueryException::class, 'Deadlock found');
        expect($calls->count())->toBe(ShowRegistration::CONCURRENCY_ATTEMPTS);
        expect(DB::transactionLevel())->toBe(0);
        expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(0);
    } finally {
        cleanupRaceFixtures([(int) $show->id], [(int) $user->id]);
    }
});
