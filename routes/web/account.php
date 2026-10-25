<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\TutorialController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Member account, private messages, contact form and tutorial flag. URLs and verbs match the
// legacy MemberProfile, HomeController and TutorialController. Web middleware (session + CSRF)
// applies because this file is loaded from routes/web.php.

// Private messages: 5 per minute per sender (a signed-in member; the auth middleware runs first).
RateLimiter::for('account-message', fn (Request $request) => Limit::perMinute(5)
    ->by('pm-user|'.$request->user()?->getAuthIdentifier()));

// Contact form: 3 per minute and 10 per hour per IP, with separate counters for each window.
RateLimiter::for('account-contact', fn (Request $request) => [
    Limit::perMinute(3)->by('contact-min|'.$request->ip()),
    Limit::perHour(10)->by('contact-hr|'.$request->ip()),
]);

Route::post('member/register', [RegistrationController::class, 'store'])
    ->middleware('throttle:10,1');

Route::get('member/location', [AccountController::class, 'showLocation'])->middleware('auth');
Route::post('member/location', [AccountController::class, 'updateLocation'])->middleware('auth');

Route::get('member/private-preferences', [AccountController::class, 'showPreferences'])->middleware('auth');
Route::post('member/private-preferences', [AccountController::class, 'updatePreferences'])->middleware('auth');

Route::post('member/account-info', [AccountController::class, 'updateAccountInfo'])->middleware('auth');
Route::post('member/delete-account', [AccountController::class, 'deleteAccount'])->middleware('auth');

Route::post('member/message', [MessageController::class, 'send'])
    ->middleware(['auth', 'throttle:account-message']);

Route::post('home/report', [ContactController::class, 'report'])
    ->middleware('throttle:account-contact');

Route::get('tutorial/tutorial', [TutorialController::class, 'show'])->middleware('auth');
Route::post('tutorial/tutorial', [TutorialController::class, 'update'])->middleware('auth');
