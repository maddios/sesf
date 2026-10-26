<?php

use App\Http\Controllers\Admin\RegistrationController;
use Illuminate\Support\Facades\Route;

// Event registrations (legacy CarShowRegistration admin). Loaded by routes/web/admin.php.

Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
Route::get('registrations/{registration}', [RegistrationController::class, 'show'])->whereNumber('registration')->name('registrations.show');
Route::put('registrations/{registration}', [RegistrationController::class, 'update'])->whereNumber('registration')->name('registrations.update');
