<?php

use App\Http\Controllers\Admin\AgendaController;
use Illuminate\Support\Facades\Route;

// Show agenda items, ordered per event (legacy config Agenda.php). Loaded by routes/web/admin.php.

Route::get('agenda', [AgendaController::class, 'index'])->name('agenda.index');
Route::post('agenda', [AgendaController::class, 'store'])->name('agenda.store');
Route::post('agenda/reorder', [AgendaController::class, 'reorder'])->name('agenda.reorder');
Route::get('agenda/{agenda}', [AgendaController::class, 'show'])->whereNumber('agenda')->name('agenda.show');
Route::put('agenda/{agenda}', [AgendaController::class, 'update'])->whereNumber('agenda')->name('agenda.update');
Route::delete('agenda/{agenda}', [AgendaController::class, 'destroy'])->whereNumber('agenda')->name('agenda.destroy');
