<?php

use App\Http\Controllers\Admin\EventShirtController;
use Illuminate\Support\Facades\Route;

// Shirt types offered at one event and their prices (show_shirt_type). Loaded by routes/web/admin.php.

Route::get('events/{event}/shirts', [EventShirtController::class, 'index'])->whereNumber('event')->name('event-shirts.index');
Route::put('events/{event}/shirts', [EventShirtController::class, 'update'])->whereNumber('event')->name('event-shirts.update');
