<?php

namespace App\Providers;

use App\Support\DestructiveCommandPolicy;
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
        // migrate:fresh, db:wipe and friends are refused unless the default connection is an
        // ephemeral test database. Decided from the default connection, not APP_ENV, so they can
        // never wipe the shared sesf_dev database (or production) by accident.
        $default = (string) config('database.default');
        $connection = (array) config("database.connections.{$default}", []);

        DB::prohibitDestructiveCommands(DestructiveCommandPolicy::shouldProhibit($connection, $default));
    }
}
