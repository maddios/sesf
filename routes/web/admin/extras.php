<?php

use App\Http\Controllers\Admin\ExtraController;
use Illuminate\Support\Facades\Route;

// Show extras (paid add-ons, legacy config extras.php). Loaded by routes/web/admin.php.

Route::get('extras', [ExtraController::class, 'index'])->name('extras.index');
Route::post('extras', [ExtraController::class, 'store'])->name('extras.store');
Route::get('extras/{extra}', [ExtraController::class, 'show'])->whereNumber('extra')->name('extras.show');
Route::put('extras/{extra}', [ExtraController::class, 'update'])->whereNumber('extra')->name('extras.update');
Route::delete('extras/{extra}', [ExtraController::class, 'destroy'])->whereNumber('extra')->name('extras.destroy');
