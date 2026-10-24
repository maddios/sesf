<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

test('the loader applies sql files in filename order, ignoring the .done suffix', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-sql-'.bin2hex(random_bytes(6));
    mkdir($dir);

    try {
        file_put_contents($dir.'/002_second.sql', "-- purpose: second\nINSERT INTO announcements (show_id, text) VALUES (1, 'sql-dir-second');\n");
        file_put_contents($dir.'/001_first.sql.done', "INSERT INTO announcements (show_id, text) VALUES (1, 'sql-dir-first');\n");
        file_put_contents($dir.'/notes.txt', 'not applied');

        $connection = DB::connection(LegacyTestDatabase::CONNECTION);

        expect(LegacyTestDatabase::applySqlDirectory($connection, $dir))
            ->toBe(['001_first.sql.done', '002_second.sql']);

        expect($connection->table('announcements')
            ->whereIn('text', ['sql-dir-first', 'sql-dir-second'])
            ->orderBy('id')
            ->pluck('text')
            ->all())->toBe(['sql-dir-first', 'sql-dir-second']);

        expect(LegacyTestDatabase::applySqlDirectory($connection, $dir.DIRECTORY_SEPARATOR.'missing'))
            ->toBe([]);
    } finally {
        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
        rmdir($dir);
    }
});
