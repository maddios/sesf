<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReportExportController;
use Illuminate\Support\Facades\Route;

// Admin reports (members, event summary, extras). Read-only lists plus SpreadsheetML exports.

Route::get('reports/default-event', [ReportController::class, 'defaultEvent'])->name('reports.default-event');
Route::get('reports/members', [ReportController::class, 'members'])->name('reports.members');
Route::get('reports/members/export.xls', [ReportExportController::class, 'members'])->name('reports.members.export');
Route::get('reports/event-summary', [ReportController::class, 'eventSummary'])->name('reports.event-summary');
Route::get('reports/event-summary/totals', [ReportController::class, 'eventSummaryTotals'])->name('reports.event-summary.totals');
Route::get('reports/event-summary/export.xls', [ReportExportController::class, 'eventSummary'])->name('reports.event-summary.export');
Route::get('reports/extras', [ReportController::class, 'extras'])->name('reports.extras');
Route::get('reports/extras/export.xls', [ReportExportController::class, 'extras'])->name('reports.extras.export');
