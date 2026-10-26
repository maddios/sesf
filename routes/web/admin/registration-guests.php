<?php

use App\Http\Controllers\Admin\RegistrationGuestController;
use Illuminate\Support\Facades\Route;

// Guests on event registrations (user_guest). Loaded by routes/web/admin.php.

Route::get('registration-guests', [RegistrationGuestController::class, 'index'])->name('registration-guests.index');
Route::get('registration-guests/{guest}', [RegistrationGuestController::class, 'show'])->whereNumber('guest')->name('registration-guests.show');
Route::put('registration-guests/{guest}', [RegistrationGuestController::class, 'update'])->whereNumber('guest')->name('registration-guests.update');
Route::delete('registration-guests/{guest}', [RegistrationGuestController::class, 'destroy'])->whereNumber('guest')->name('registration-guests.destroy');
