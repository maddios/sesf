<?php

use App\Http\Controllers\Admin\ExtraRegistrationController;
use Illuminate\Support\Facades\Route;

// Extras bought on a registration (legacy config extraRegistration.php). Edit and delete only. Loaded by routes/web/admin.php.

Route::get('extra-registrations', [ExtraRegistrationController::class, 'index'])->name('extra-registrations.index');
Route::get('extra-registrations/{extraRegistration}', [ExtraRegistrationController::class, 'show'])->whereNumber('extraRegistration')->name('extra-registrations.show');
Route::put('extra-registrations/{extraRegistration}', [ExtraRegistrationController::class, 'update'])->whereNumber('extraRegistration')->name('extra-registrations.update');
Route::delete('extra-registrations/{extraRegistration}', [ExtraRegistrationController::class, 'destroy'])->whereNumber('extraRegistration')->name('extra-registrations.destroy');
