<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Unit tests boot the Laravel application via Tests\TestCase.
| Feature tests additionally reset the database between tests. The
| database is in-memory SQLite (see phpunit.xml).
|
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Database tests run against the ephemeral MariaDB (docker compose profile "test").
// They never use the shared sesf_dev database; see tests/Support/LoadsLegacySchema.php.
pest()->extend(TestCase::class)
    ->use(LoadsLegacySchema::class)
    ->in('Database');
