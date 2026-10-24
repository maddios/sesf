<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // migrate:fresh, db:wipe and friends are refused outside the test run, so they can never
        // wipe the shared sesf_dev database (or production) by accident.
        DB::prohibitDestructiveCommands(! $this->app->runningUnitTests());
    }
}
