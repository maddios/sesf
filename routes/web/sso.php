<?php

use App\Http\Controllers\Auth\VanillaSsoController;
use Illuminate\Support\Facades\Route;

// Vanilla forum jsConnect endpoint. Legacy path: login/vanilla.
Route::get('login/vanilla', [VanillaSsoController::class, 'handle'])->name('sso.vanilla');
