<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Users (legacy frozennode user.php). Loaded by routes/web/admin.php. Impersonation: admin/impersonation.php.

Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/lookup', [UserController::class, 'lookup'])->name('users.lookup');
Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
Route::delete('users/{user}', [UserController::class, 'disable'])->whereNumber('user')->name('users.disable');
Route::post('users/{user}/restore', [UserController::class, 'restore'])->whereNumber('user')->name('users.restore');
Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->whereNumber('user')->name('users.password-reset');
Route::post('users/{user}/registrations', [UserController::class, 'registerForShow'])->whereNumber('user')->name('users.registrations');
