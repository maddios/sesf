<?php

use App\Http\Controllers\Admin\CheckinController;
use Illuminate\Support\Facades\Route;

// On-site check-in and registration (legacy CheckinList). Loaded by routes/web/admin.php.

Route::get('checkin/registrations', [CheckinController::class, 'index'])->name('checkin.index');
Route::post('checkin/registrations/{registration}/check-in', [CheckinController::class, 'checkIn'])->whereNumber('registration')->name('checkin.check-in');
Route::post('checkin/registrations/{registration}/check-out', [CheckinController::class, 'checkOut'])->whereNumber('registration')->name('checkin.check-out');
Route::post('checkin/registrations/{registration}/waiver-reminder', [CheckinController::class, 'waiverReminder'])->whereNumber('registration')->name('checkin.waiver-reminder');
Route::post('checkin/registrations/{registration}/waiver-sign-url', [CheckinController::class, 'waiverSignUrl'])->whereNumber('registration')->name('checkin.waiver-sign-url');
Route::post('checkin/registrations/{registration}/pay-link-email', [CheckinController::class, 'payLinkEmail'])->whereNumber('registration')->name('checkin.pay-link-email');
Route::get('checkin/shows/{show}/registration-options', [CheckinController::class, 'options'])->whereNumber('show')->name('checkin.options');
Route::post('checkin/shows/{show}/registrations', [CheckinController::class, 'store'])->whereNumber('show')->name('checkin.store');
Route::get('checkin/users/{user}/garage', [CheckinController::class, 'garage'])->whereNumber('user')->name('checkin.garage');
