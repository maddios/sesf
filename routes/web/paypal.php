<?php

use App\Http\Controllers\PayPalIpnController;
use Illuminate\Support\Facades\Route;

// PayPal IPN receiver (docs/paypal-ipn.md). POST only: PayPal always POSTs IPNs (legacy accepted
// any verb). CSRF-exempt in bootstrap/app.php; no throttle, because every request comes from
// PayPal's servers and a throttled IPN would only be retried.
Route::post('paypal-ipn', [PayPalIpnController::class, 'handle'])->name('paypal.ipn');
