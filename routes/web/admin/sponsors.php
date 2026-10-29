<?php

use App\Http\Controllers\Admin\SponsorController;
use Illuminate\Support\Facades\Route;

// Sponsors (reference admin resource; legacy frozennode config sponsor.php). Loaded by routes/web/admin.php.

Route::get('sponsors', [SponsorController::class, 'index'])->name('sponsors.index');
Route::post('sponsors', [SponsorController::class, 'store'])->name('sponsors.store');
Route::get('sponsors/{sponsor}', [SponsorController::class, 'show'])->whereNumber('sponsor')->name('sponsors.show');
Route::put('sponsors/{sponsor}', [SponsorController::class, 'update'])->whereNumber('sponsor')->name('sponsors.update');
Route::delete('sponsors/{sponsor}', [SponsorController::class, 'destroy'])->whereNumber('sponsor')->name('sponsors.destroy');
Route::post('sponsors/{sponsor}/enable', [SponsorController::class, 'enable'])->whereNumber('sponsor')->name('sponsors.enable');
Route::post('sponsors/{sponsor}/disable', [SponsorController::class, 'disable'])->whereNumber('sponsor')->name('sponsors.disable');
Route::post('sponsors/{sponsor}/image', [SponsorController::class, 'image'])->whereNumber('sponsor')->name('sponsors.image');
