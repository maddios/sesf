<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LookupController;
use Illuminate\Support\Facades\Route;

// Admin dashboard counts and shared lookups (RelationSelect sources). Loaded by routes/web/admin.php.

Route::get('dashboard', DashboardController::class)->name('dashboard');
Route::get('lookups/events', [LookupController::class, 'events'])->name('lookups.events');
