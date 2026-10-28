<?php

/*
 * Child process for tests/Concurrency/GalleryUploadRaceTest.php. Not a test file.
 *
 *   php gallery-sort-worker.php <gallery_id> <user_id> <image_root> <source_png> <barrier_lock_name>
 *
 * Boots the app on the testing_mariadb connection, prints "ready", waits on GET_LOCK(<barrier>)
 * held by the parent, then uploads one photo to the gallery and prints one JSON line.
 */

use App\Models\Gallery;
use App\Models\User;
use App\Services\Gallery\GalleryWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

[, $galleryId, $userId, $imageRoot, $source, $barrier] = (array) ($_SERVER['argv'] ?? []) + [null, null, null, null, null, null];

$emit = static function (array $payload): void {
    fwrite(STDOUT, json_encode($payload).PHP_EOL);
    fflush(STDOUT);
};

try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
    LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);
    config(['database.default' => LegacyTestDatabase::CONNECTION, 'sesf.gallery_image_root' => $imageRoot.'/']);

    if ((string) DB::selectOne('select database() as db')->db !== LegacyTestDatabase::REQUIRED_DATABASE) {
        throw new RuntimeException('Worker is not connected to '.LegacyTestDatabase::REQUIRED_DATABASE);
    }

    $gallery = Gallery::query()->findOrFail((int) $galleryId);
    $user = User::query()->findOrFail((int) $userId);
    $file = new UploadedFile($source, 'a.png', 'image/png', null, true);

    $emit(['ready' => true]);

    $got = (int) DB::selectOne('select get_lock(?, 60) as got', [$barrier])->got;
    DB::selectOne('select release_lock(?) as released', [$barrier]);
    if ($got !== 1) {
        throw new RuntimeException('Barrier lock not acquired.');
    }

    $item = app(GalleryWriter::class)->upload($gallery, $user, $file, null);

    $emit(['ok' => true, 'id' => $item->id, 'sort_order' => (int) $item->sort_order]);
    exit(0);
} catch (Throwable $e) {
    $emit(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
