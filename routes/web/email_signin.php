<?php

use App\Http\Controllers\Auth\EmailSigninController;
use Illuminate\Support\Facades\Route;

Route::post('login/email-signin/request', [EmailSigninController::class, 'requestCode'])
    ->middleware('throttle:email-signin-request')
    ->name('email-signin.request');

Route::post('login/email-signin/verify-code', [EmailSigninController::class, 'verifyCode'])
    ->middleware('throttle:email-signin-verify')
    ->name('email-signin.verify-code');

Route::get('login/email-signin/consume/{token}', [EmailSigninController::class, 'consume'])
    ->where('token', '[A-Fa-f0-9]{64}')
    ->name('email-signin.consume');
