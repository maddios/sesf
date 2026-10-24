<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Checked before the traits' setUp runs (LoadsLegacySchema connects to MariaDB there), so a
     * bad configuration never reaches a database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        TestDatabaseGuard::assertApplication($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        TestDatabaseGuard::assertApplication($this->app);

        // Any real outgoing HTTP request fails the test. Tests must Http::fake() what they call.
        Http::preventStrayRequests();
    }
}
