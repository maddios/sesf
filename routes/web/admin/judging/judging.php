<?php

use App\Http\Controllers\Admin\Judging\JudgingEntryController;
use App\Http\Controllers\Admin\Judging\JudgingJudgeController;
use App\Http\Controllers\Admin\Judging\JudgingReleaseController;
use Illuminate\Support\Facades\Route;

// Judging admin (docs/judging.md). Loaded by routes/web/admin.php with auth + judge_or_admin +
// judge_for_show (admins, or judges assigned to the {show} route parameter). Only `shows` opts out:
// it takes no show and lists just the caller's own shows. Judge assignment and release add admin.

Route::prefix('judging')->name('judging.')->group(static function (): void {
    Route::get('shows', [JudgingEntryController::class, 'shows'])->withoutMiddleware('judge_for_show')->name('shows');

    Route::prefix('shows/{show}')->whereNumber('show')->group(static function (): void {
        Route::get('entries', [JudgingEntryController::class, 'index'])->name('entries.index');
        Route::get('unjudged', [JudgingEntryController::class, 'unjudged'])->name('unjudged');
        Route::get('types', [JudgingEntryController::class, 'types'])->name('types');
        Route::post('entries', [JudgingEntryController::class, 'store'])->name('entries.store');
        Route::delete('entries/{entry}', [JudgingEntryController::class, 'destroy'])->whereNumber('entry')->name('entries.destroy');

        Route::middleware('admin')->group(static function (): void {
            Route::get('judges', [JudgingJudgeController::class, 'index'])->name('judges.index');
            Route::get('judges/eligible', [JudgingJudgeController::class, 'eligible'])->name('judges.eligible');
            Route::post('judges', [JudgingJudgeController::class, 'store'])->name('judges.store');
            Route::delete('judges/{user}', [JudgingJudgeController::class, 'destroy'])->whereNumber('user')->name('judges.destroy');
            Route::put('release', [JudgingReleaseController::class, 'update'])->name('release');
            Route::post('results-mail', [JudgingReleaseController::class, 'mail'])->name('results-mail');
        });
    });
});
