<?php

use Illuminate\Support\Facades\Route;

// Admin JSON API for the SPA admin pages (/#/admin/...). See docs/admin-conventions.md.
//
// - routes/web/admin/*.php          admins only (auth + admin)
// - routes/web/admin/judging/*.php  judges and admins (auth + judge_or_admin); add judge_for_show
//                                   on routes that take a show id
//
// Every route here is under /admin-api, named admin-api.*, runs with web middleware (session +
// CSRF), is audited (audit.admin: request id + audit entry for each successful write) and is
// throttled by the admin-write limiter (writes only, 120 a minute per admin).

$adminApiGroup = static function (string $role, string $directory): void {
    Route::prefix('admin-api')
        ->name('admin-api.')
        ->middleware(['auth', $role, 'throttle:admin-write', 'audit.admin'])
        ->group(static function () use ($directory): void {
            foreach (glob($directory.'/*.php') ?: [] as $routeFile) {
                require $routeFile;
            }
        });
};

$adminApiGroup('admin', __DIR__.'/admin');
$adminApiGroup('judge_or_admin', __DIR__.'/admin/judging');
