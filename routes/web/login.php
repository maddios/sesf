<?php

use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

// Session routes for the SPA. URLs and verbs match the legacy UserAuth controller.
// Web middleware (session + CSRF) applies because this file is loaded from routes/web.php.

Route::post('login/authenticate', [SessionController::class, 'authenticate']);

Route::post('login/login', [SessionController::class, 'login'])->middleware('throttle:login');

Route::any('login/logout', [SessionController::class, 'logout']);

Route::post('login/update-password', [SessionController::class, 'updatePassword']);
