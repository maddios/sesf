<?php

use App\Http\Controllers\Api\CarController;
use Illuminate\Support\Facades\Route;

// Car writes for the SPA. URLs and verbs match the legacy CarProfile and MemberProfile controllers.
// Web middleware (session + CSRF) applies because this file is loaded from routes/web.php.
// Uploads are throttled to 20 a minute per user (per IP when signed out).

Route::put('car/profile', [CarController::class, 'update'])->middleware('throttle:car-write');

Route::post('car/profile-image', [CarController::class, 'storeImage'])->middleware('throttle:20,1');

Route::post('member/sell-car', [CarController::class, 'sell'])->middleware('throttle:account-write');
