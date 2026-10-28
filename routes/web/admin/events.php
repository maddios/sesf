<?php

use App\Http\Controllers\Admin\EventController;
use Illuminate\Support\Facades\Route;

// Events (legacy car_show, admin config carShow.php). Loaded by routes/web/admin.php.

Route::get('events', [EventController::class, 'index'])->name('events.index');
Route::post('events', [EventController::class, 'store'])->name('events.store');
Route::get('events/clone-sources', [EventController::class, 'cloneSources'])->name('events.clone-sources');
Route::get('events/{event}', [EventController::class, 'show'])->whereNumber('event')->name('events.show');
Route::put('events/{event}', [EventController::class, 'update'])->whereNumber('event')->name('events.update');
Route::delete('events/{event}', [EventController::class, 'destroy'])->whereNumber('event')->name('events.destroy');
Route::post('events/{event}/activate', [EventController::class, 'activate'])->whereNumber('event')->name('events.activate');
Route::post('events/{event}/deactivate', [EventController::class, 'deactivate'])->whereNumber('event')->name('events.deactivate');
Route::post('events/{event}/image', [EventController::class, 'image'])->whereNumber('event')->name('events.image');
Route::post('events/{event}/schedule-pdf', [EventController::class, 'schedulePdf'])->whereNumber('event')->name('events.schedule-pdf');
