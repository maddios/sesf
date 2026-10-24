<?php

use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Unit tests boot the Laravel application via Tests\TestCase.
| The default connection is in-memory SQLite (see phpunit.xml). Feature tests do not
| migrate or reset a schema: there are no migrations in this project, and each test
| creates only the tables it needs. Database tests use the ephemeral MariaDB
| (docker compose profile "test"); see tests/Support/LegacyTestDatabase.php.
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature');

// Database tests run against the ephemeral MariaDB (docker compose profile "test").
// They never use the shared sesf_dev database; see tests/Support/LegacyTestDatabase.php.
pest()->extend(TestCase::class)
    ->use(LoadsLegacySchema::class)
    ->in('Database');
