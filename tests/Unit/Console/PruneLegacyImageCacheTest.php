<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $cacheDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-legacy-prune-'.bin2hex(random_bytes(6));
    mkdir($cacheDir.'/ab/cd', 0755, true);
    config(['sesf.legacy_images.cache_dir' => $cacheDir]);
});

afterEach(function () {
    removeTree(config('sesf.legacy_images.cache_dir'));
});

function cacheDir(): string
{
    return config('sesf.legacy_images.cache_dir');
}

function ageFile(string $path, int $days): void
{
    touch($path, now()->subDays($days)->getTimestamp());
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
        removeTree($path.DIRECTORY_SEPARATOR.$name);
    }
    @rmdir($path);
}

it('deletes only cached files older than the cutoff', function () {
    $old = cacheDir().'/ab/cd/old.jpg';
    $new = cacheDir().'/ab/cd/new.jpg';
    file_put_contents($old, 'old');
    file_put_contents($new, 'new');
    ageFile($old, 40);
    ageFile($new, 2);

    expect(Artisan::call('legacy-images:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Removed 1 cached images.');

    expect(file_exists($old))->toBeFalse()
        ->and(file_exists($new))->toBeTrue();
});

it('honours the --days option', function () {
    $file = cacheDir().'/ab/cd/file.jpg';
    file_put_contents($file, 'x');
    ageFile($file, 10);

    expect(Artisan::call('legacy-images:prune', ['--days' => 5]))->toBe(0);

    expect(file_exists($file))->toBeFalse();
});

it('removes subdirectories that have stayed empty past the cutoff and keeps the cache root', function () {
    ageFile(cacheDir().'/ab/cd', 40);
    ageFile(cacheDir().'/ab', 40);

    expect(Artisan::call('legacy-images:prune'))->toBe(0);

    expect(is_dir(cacheDir().'/ab/cd'))->toBeFalse()
        ->and(is_dir(cacheDir()))->toBeTrue();
});

it('keeps a freshly created empty subdirectory a render is about to write into', function () {
    expect(Artisan::call('legacy-images:prune'))->toBe(0);

    expect(is_dir(cacheDir().'/ab/cd'))->toBeTrue();
});

it('never follows a symlink out of the cache directory', function () {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-legacy-outside-'.bin2hex(random_bytes(6)).'.jpg';
    file_put_contents($outside, 'outside');
    ageFile($outside, 40);
    symlink($outside, cacheDir().'/link.jpg');

    try {
        expect(Artisan::call('legacy-images:prune'))->toBe(0);

        expect(file_exists($outside))->toBeTrue();
    } finally {
        @unlink($outside);
    }
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'legacy-images:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
