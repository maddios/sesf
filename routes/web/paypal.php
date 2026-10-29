<?php

use App\Http\Controllers\PayPalIpnController;
use Illuminate\Support\Facades\Route;

// PayPal IPN receiver (docs/paypal-ipn.md). POST only: PayPal always POSTs IPNs (legacy accepted
// any verb). CSRF-exempt in bootstrap/app.php. Throttled per IP by the generous `paypal-ipn`
// limiter (PayPal sends from a few IPs and retries a 429), which caps forged-post floods.
Route::post('paypal-ipn', [PayPalIpnController::class, 'handle'])
    ->middleware('throttle:paypal-ipn')
    ->name('paypal.ipn');
