<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
