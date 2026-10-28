<?php

use App\Http\Controllers\Admin\WaiverController;
use Illuminate\Support\Facades\Route;

// Waiver list per event and its actions (docs/admin-conventions.md). Signed PDFs: GET waiver-pdf.

Route::get('waivers', [WaiverController::class, 'index'])->name('waivers.index');
Route::get('waivers/walk-ins', [WaiverController::class, 'walkIns'])->name('waivers.walk-ins');
Route::get('waivers/open-sign', [WaiverController::class, 'openSign'])->name('waivers.open-sign');
Route::get('waivers/blank-pdf', [WaiverController::class, 'blankPdf'])->name('waivers.blank-pdf');
Route::post('waivers/request', [WaiverController::class, 'sendRequest'])->name('waivers.request');
Route::post('waivers/reminders', [WaiverController::class, 'reminders'])->name('waivers.reminders');
Route::post('waivers/regenerate-pdfs', [WaiverController::class, 'regeneratePdfs'])->name('waivers.regenerate-pdfs');
