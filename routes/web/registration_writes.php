<?php

use App\Http\Controllers\Api\EventRegistrationWriteController;
use Illuminate\Support\Facades\Route;

// Registration writes (legacy EventProfile::postRegistration / postExtra), same URLs. Web
// middleware (session + CSRF) applies because this file is loaded from routes/web.php. Sign-in
// required (401 JSON). Writes: 30 a minute per user. The quote writes nothing and is called for
// live totals, so it has its own, larger budget. Named limiter prefixes keep these counters apart
// from other throttled routes. See docs/registration-write.md.

Route::middleware(['auth', 'throttle:30,1,registration-write'])->group(function (): void {
    Route::post('event/registration', [EventRegistrationWriteController::class, 'save']);
    Route::post('event/extra', [EventRegistrationWriteController::class, 'extra']);
});

Route::post('event/registration/quote', [EventRegistrationWriteController::class, 'quote'])
    ->middleware(['auth', 'throttle:120,1,registration-quote']);
