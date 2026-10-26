<?php

use App\Http\Controllers\Admin\CarController;
use Illuminate\Support\Facades\Route;

// Cars (legacy frozennode car.php). Loaded by routes/web/admin.php.

Route::get('cars', [CarController::class, 'index'])->name('cars.index');
Route::get('cars/options', [CarController::class, 'options'])->name('cars.options');
Route::get('cars/{car}', [CarController::class, 'show'])->whereNumber('car')->name('cars.show');
Route::put('cars/{car}', [CarController::class, 'update'])->whereNumber('car')->name('cars.update');
Route::delete('cars/{car}', [CarController::class, 'destroy'])->whereNumber('car')->name('cars.destroy');
Route::post('cars/{car}/confirm', [CarController::class, 'confirm'])->whereNumber('car')->name('cars.confirm');
Route::post('cars/{car}/unconfirm', [CarController::class, 'unconfirm'])->whereNumber('car')->name('cars.unconfirm');
Route::post('cars/{car}/image', [CarController::class, 'image'])->whereNumber('car')->name('cars.image');
