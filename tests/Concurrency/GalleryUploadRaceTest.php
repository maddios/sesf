<?php

use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LegacyTestDatabase;
use Tests\TestCase;

/*
 * Real multi-process race for GalleryWriter::upload() (sort_order allocation under the gallery
 * row lock). Workers are separate PHP processes, so the fixtures must be committed: this file
 * does NOT use the per-test rollback transaction and deletes its rows in finally blocks.
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
 * Starts $workers upload processes for one gallery and releases them together through a GET_LOCK
 * barrier. Returns one entry per worker: exit code, decoded JSON result, stderr.
 *
 * @return list<array{exit: int, result: array<string, mixed>|null, stderr: string}>
 */
function runGallerySortRace(int $galleryId, int $userId, int $workers, string $imageRoot, string $source): array
{
    $barrier = 'sesf_gallery_race_'.getmypid().'_'.bin2hex(random_bytes(4));
    $worker = __DIR__.'/Support/gallery-sort-worker.php';
    $env = array_merge(getenv(), ['APP_ENV' => 'testing']);

    if ((int) DB::selectOne('select get_lock(?, 10) as got', [$barrier])->got !== 1) {
        throw new RuntimeException('Could not take the barrier lock.');
    }

    $procs = [];
    $arrived = false;

    try {
        for ($i = 0; $i < $workers; $i++) {
            $pipes = [];
            $proc = proc_open(
                [PHP_BINARY, $worker, (string) $galleryId, (string) $userId, $imageRoot, $source, $barrier],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                $env,
            );
            if (! is_resource($proc)) {
                throw new RuntimeException('proc_open failed');
            }
            fclose($pipes[0]);
            $procs[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
        }

        foreach ($procs as $i => $p) {
            $line = fgets($p['out']);
            if ($line === false || ! str_contains($line, '"ready"')) {
                throw new RuntimeException("Worker {$i} did not get ready: ".$line.stream_get_contents($p['err']));
            }
        }

        $deadline = microtime(true) + 20;
        do {
            $waiting = (int) DB::selectOne(
                'select count(*) as n from information_schema.processlist where info like ?',
                ['select get_lock(%'],
            )->n;
            if ($waiting >= $workers) {
                $arrived = true;
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        if (! $arrived) {
            throw new RuntimeException(sprintf('Barrier timeout: %d of %d workers arrived.', $waiting, $workers));
        }
    } finally {
        if (! $arrived) {
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
        $results[] = ['exit' => $exit, 'result' => is_array($decoded) ? $decoded : null, 'stderr' => $stderr];
    }

    return $results;
}

function removeGalleryRaceTree(string $path): void
{
    if (is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
        removeGalleryRaceTree($path.DIRECTORY_SEPARATOR.$name);
    }
    @rmdir($path);
}

test('concurrent uploads to one gallery get distinct, consecutive sort orders', function () {
    $show = CarShow::factory()->create();
    $owner = User::factory()->create();
    $gallery = Gallery::factory()->create(['show_id' => $show->id, 'user_id' => $owner->id]);

    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-gallery-race-'.bin2hex(random_bytes(6));
    mkdir($root, 0755, true);
    $source = $root.DIRECTORY_SEPARATOR.'source.png';
    file_put_contents($source, F::png());

    try {
        $results = runGallerySortRace((int) $gallery->id, (int) $owner->id, 4, $root, $source);

        $failures = array_values(array_filter($results, fn (array $r): bool => $r['exit'] !== 0 || ($r['result']['ok'] ?? false) !== true));
        expect($failures)->toBe([], implode(' | ', array_map(fn (array $r): string => (string) ($r['result']['error'] ?? $r['stderr']), $failures)));

        $sortOrders = array_map(fn (array $r): int => (int) $r['result']['sort_order'], $results);
        sort($sortOrders);
        expect($sortOrders)->toBe([1, 2, 3, 4]);

        $stored = GalleryItem::query()
            ->whereIn('id', array_map(fn (array $r): int => (int) $r['result']['id'], $results))
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->map(fn ($n): int => (int) $n)
            ->all();
        expect($stored)->toBe([1, 2, 3, 4]);
    } finally {
        $itemIds = DB::table('gallery_item_xref')->where('gallery_id', $gallery->id)->pluck('item_id')->all();
        DB::table('gallery_item_xref')->where('gallery_id', $gallery->id)->delete();
        DB::table('gallery_item')->whereIn('id', $itemIds)->delete();
        DB::table('gallery')->where('id', $gallery->id)->delete();
        DB::table('car_show')->where('id', $show->id)->delete();
        DB::table('user_preferences')->where('user_id', $owner->id)->delete();
        DB::table('user')->where('id', $owner->id)->delete();
        removeGalleryRaceTree($root);
    }
});
