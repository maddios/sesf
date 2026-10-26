<?php

use App\Http\Controllers\Admin\LocationController;
use Illuminate\Support\Facades\Route;

// Show locations (venues), ordered per event (legacy config Locations.php). Loaded by routes/web/admin.php.

Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
Route::post('locations/reorder', [LocationController::class, 'reorder'])->name('locations.reorder');
Route::get('locations/{location}', [LocationController::class, 'show'])->whereNumber('location')->name('locations.show');
Route::put('locations/{location}', [LocationController::class, 'update'])->whereNumber('location')->name('locations.update');
Route::delete('locations/{location}', [LocationController::class, 'destroy'])->whereNumber('location')->name('locations.destroy');
