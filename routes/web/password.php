<?php

use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::post('login/forgot-password', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:password-forgot')
    ->name('password.forgot');

Route::post('login/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware(['block.impersonated', 'throttle:password-reset'])
    ->name('password.reset');
