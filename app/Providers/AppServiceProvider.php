<?php

namespace App\Providers;

use App\Policies\Gates;
use App\Support\DestructiveCommandGuard;
use App\Support\DestructiveCommandPolicy;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
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

        // The global flag only sees the default connection. This listener also checks the target of
        // each destructive command (e.g. --database=mysql while the default is testing_mariadb).
        Event::listen(CommandStarting::class, [DestructiveCommandGuard::class, 'handle']);

        Gates::register();

        // Every generated URL uses APP_URL, never the Host header of the request that built it.
        $appUrl = (string) config('app.url');
        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);

            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }
    }
}
