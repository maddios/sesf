<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PruneLegacyImageCache extends Command
{
    protected $signature = 'legacy-images:prune {--days=30 : Delete cached renderings not modified for this many days}';

    protected $description = 'Delete cached legacy image renderings older than the given number of days';

    public function handle(): int
    {
        $root = rtrim((string) config('sesf.legacy_images.cache_dir'), '/');
        if (! is_dir($root)) {
            $this->info('Removed 0 cached images.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $this->option('days'))->getTimestamp();
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        $removed = 0;
        foreach ($entries as $entry) {
            if ($entry->isLink()) {
                continue;
            }

            if ($entry->isDir()) {
                // A directory a render has just created is newer than the cutoff, so it is never removed under it.
                if ($entry->getMTime() < $cutoff) {
                    @rmdir($entry->getPathname());
                }

                continue;
            }

            if ($entry->getMTime() < $cutoff && @unlink($entry->getPathname())) {
                $removed++;
            }
        }

        $this->info("Removed {$removed} cached images.");

        return self::SUCCESS;
    }
}
