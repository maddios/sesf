<?php

use App\Http\Controllers\Waivers\WaiverPageController;
use App\Http\Controllers\Waivers\WaiverPdfController;
use App\Http\Controllers\Waivers\WaiverPortalController;
use App\Http\Controllers\Waivers\WaiverSignController;
use Illuminate\Support\Facades\Route;

// Waiver JSON endpoints (docs/waivers.md). Legacy paths; web middleware (session + CSRF) applies
// because this file is loaded from routes/web.php. The HTML pages at these prefixes are built
// separately and call these endpoints.

$tracking = '[A-Za-z0-9_-]{8,64}';

Route::get('waiver_sign/t/{trackingId}/prefill', [WaiverSignController::class, 'prefillByTracking'])
    ->where('trackingId', $tracking)->middleware('throttle:waiver-prefill');
Route::post('waiver_sign/t/{trackingId}', [WaiverSignController::class, 'signByTracking'])
    ->where('trackingId', $tracking)->middleware(['block.impersonated', 'throttle:waiver-sign']);

Route::get('waiver_sign/s/{showId}/prefill', [WaiverSignController::class, 'prefillAdhoc'])
    ->whereNumber('showId')->middleware('throttle:waiver-prefill');
Route::post('waiver_sign/s/{showId}', [WaiverSignController::class, 'signAdhoc'])
    ->whereNumber('showId')->middleware(['block.impersonated', 'throttle:waiver-sign']);

Route::get('waiver_sign/thank-you/{trackingId}/data', [WaiverSignController::class, 'thankYou'])
    ->where('trackingId', $tracking)->middleware('throttle:waiver-prefill');

Route::get('waiver_sign/t/{trackingId}', [WaiverPageController::class, 'signByTracking'])->where('trackingId', $tracking);
Route::get('waiver_sign/s/{showId}', [WaiverPageController::class, 'signAdhoc'])->whereNumber('showId');
Route::get('waiver_sign/thank-you/{trackingId}', [WaiverPageController::class, 'thankYou'])->where('trackingId', $tracking);
Route::get('waiver/{showId}', [WaiverPageController::class, 'portal'])->whereNumber('showId');
Route::get('waiver/{showId}/share', [WaiverPortalController::class, 'share'])->whereNumber('showId');

Route::get('waiver-pdf', WaiverPdfController::class);

Route::post('waiver/{showId}/login', [WaiverPortalController::class, 'login'])
    ->whereNumber('showId')->middleware(['throttle:portal-login', 'throttle:login']);
Route::get('waiver/{showId}/sign-url', [WaiverPortalController::class, 'signUrl'])->whereNumber('showId')->middleware('block.impersonated');
Route::get('waiver/{showId}/status', [WaiverPortalController::class, 'status'])->whereNumber('showId');
Route::match(['GET', 'POST'], 'waiver/{showId}/logout', [WaiverPortalController::class, 'logout'])->whereNumber('showId');
