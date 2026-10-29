<?php

use App\Http\Controllers\Admin\ImpersonationController;
use Illuminate\Support\Facades\Route;

// Log in as a member ("impersonation"). Start is admin-only. Status and stop must work while the session
// belongs to the impersonated member, so they drop the admin middleware and keep auth. See ImpersonationController.

Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])->whereNumber('user')->name('impersonation.start');
Route::get('impersonation', [ImpersonationController::class, 'status'])->withoutMiddleware('admin')->name('impersonation.status');
Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])->withoutMiddleware('admin')->name('impersonation.stop');
