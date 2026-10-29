<?php

use App\Http\Controllers\Api\EventRegistrationReadController;
use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

// Registration page and invoice reads. URLs, verbs and keys match the legacy EventProfile and
// MemberProfile GET methods. Every route requires sign-in (401 JSON from the auth middleware).
// Read-only: none of these writes to the database.

Route::get('event/registration', [EventRegistrationReadController::class, 'registration'])->middleware('auth');
Route::get('event/cars', [EventRegistrationReadController::class, 'cars'])->middleware('auth');
Route::get('event/extra', [EventRegistrationReadController::class, 'extra'])->middleware('auth');

Route::get('member/invoice-list', [InvoiceController::class, 'list'])->middleware('auth');
Route::get('member/invoice', [InvoiceController::class, 'show'])->middleware('auth');
