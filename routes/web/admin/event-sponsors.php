<?php

use App\Http\Controllers\Admin\EventSponsorController;
use Illuminate\Support\Facades\Route;

// Sponsors attached to one event (show_sponsor, plus car_show.primary_sponsor_id). Loaded by routes/web/admin.php.

Route::get('events/{event}/sponsors', [EventSponsorController::class, 'index'])->whereNumber('event')->name('event-sponsors.index');
Route::post('events/{event}/sponsors', [EventSponsorController::class, 'store'])->whereNumber('event')->name('event-sponsors.store');
Route::get('events/{event}/sponsor-candidates', [EventSponsorController::class, 'candidates'])->whereNumber('event')->name('event-sponsors.candidates');
Route::put('events/{event}/sponsors/order', [EventSponsorController::class, 'reorder'])->whereNumber('event')->name('event-sponsors.reorder');
Route::put('events/{event}/sponsors/{link}', [EventSponsorController::class, 'update'])->whereNumber(['event', 'link'])->name('event-sponsors.update');
Route::delete('events/{event}/sponsors/{link}', [EventSponsorController::class, 'destroy'])->whereNumber(['event', 'link'])->name('event-sponsors.destroy');
Route::put('events/{event}/primary-sponsor', [EventSponsorController::class, 'primary'])->whereNumber('event')->name('event-sponsors.primary');
