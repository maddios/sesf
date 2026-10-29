<?php

use Illuminate\Support\Facades\Route;

// Admin JSON API for the SPA admin pages (/#/admin/...). See docs/admin-conventions.md.
//
// - routes/web/admin/*.php          admins only (auth + admin)
// - routes/web/admin/judging/*.php  judges and admins (auth + judge_or_admin + judge_for_show).
//                                   Default deny: every route must take a {show} parameter and the
//                                   caller must be an admin or a judge assigned to it. A route with
//                                   no show opts out explicitly with ->withoutMiddleware('judge_for_show')
//                                   and must scope its own results.
//
// Every route here is under /admin-api, named admin-api.*, runs with web middleware (session +
// CSRF), is audited (audit.admin: request id + audit entry for each successful write) and is
// throttled by the admin-write limiter (writes only, 120 a minute per admin).

/** @param list<string> $roles */
$adminApiGroup = static function (array $roles, string $directory): void {
    Route::prefix('admin-api')
        ->name('admin-api.')
        ->middleware(['auth', ...$roles, 'throttle:admin-write', 'audit.admin'])
        ->group(static function () use ($directory): void {
            foreach (glob($directory.'/*.php') ?: [] as $routeFile) {
                require $routeFile;
            }
        });
};

$adminApiGroup(['admin'], __DIR__.'/admin');
$adminApiGroup(['judge_or_admin', 'judge_for_show'], __DIR__.'/admin/judging');
