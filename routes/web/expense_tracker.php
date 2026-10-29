<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Legacy /expense_tracker?show_id=N now lives in the SPA admin. Redirect only; the admin-api enforces access.

Route::get('expense_tracker', static function (Request $request) {
    $showId = $request->query('show_id');
    $query = is_string($showId) && ctype_digit($showId) ? '?show_id='.$showId : '';

    return redirect('/#/admin/expenses'.$query, 302);
})->name('legacy.expense-tracker');
