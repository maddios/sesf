<?php

use App\Http\Controllers\Admin\LegacyAdminRedirectController;
use Illuminate\Support\Facades\Route;

// Legacy admin URLs (frozennode /admin and the custom /admin2 area) now live in the SPA under
// /#/admin. These routes only redirect (302), keeping the query string inside the hash; they are
// public because the SPA route guard and the admin-api middleware enforce access.

Route::get('admin/{any?}', [LegacyAdminRedirectController::class, 'admin'])
    ->where('any', '.*')
    ->name('legacy.admin');

Route::get('admin2/{section?}/{rest?}', [LegacyAdminRedirectController::class, 'admin2'])
    ->where('rest', '.*')
    ->name('legacy.admin2');
