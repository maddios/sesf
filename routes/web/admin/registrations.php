<?php

use App\Http\Controllers\Admin\RegistrationController;
use Illuminate\Support\Facades\Route;

// Event registrations (legacy CarShowRegistration admin). Loaded by routes/web/admin.php.

Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
Route::get('registrations/{registration}', [RegistrationController::class, 'show'])->whereNumber('registration')->name('registrations.show');
Route::get('registrations/{registration}/cars', [RegistrationController::class, 'cars'])->whereNumber('registration')->name('registrations.cars');
Route::put('registrations/{registration}', [RegistrationController::class, 'update'])->whereNumber('registration')->name('registrations.update');
Route::delete('registrations/{registration}', [RegistrationController::class, 'destroy'])->whereNumber('registration')->name('registrations.destroy');
