<?php

use App\Http\Controllers\Admin\ExpenseController;
use Illuminate\Support\Facades\Route;

// Expense tracker per event (legacy /expense_tracker, ExpenseTracker controller). Loaded by routes/web/admin.php.

Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('expenses.update');
Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');
