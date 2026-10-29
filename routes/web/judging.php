<?php

use App\Http\Controllers\Judging\JudgingAnswersController;
use App\Http\Controllers\Judging\JudgingDrawController;
use App\Http\Controllers\Judging\JudgingPageController;
use App\Http\Controllers\Judging\JudgingQrController;
use Illuminate\Support\Facades\Route;

// Standalone judging pages and their JSON (docs/judging.md). Legacy paths; {token} is a JudgingId
// (legacy DES id or plain digits). Authorization is in the controllers (gates judge-car,
// judging-answers, judge-for-show), never from the path.

$token = '[A-Za-z0-9]{1,64}';

Route::get('judging/{token}', [JudgingPageController::class, 'show'])->where('token', $token);
Route::get('judgingData/{token}', [JudgingPageController::class, 'data'])->where('token', $token);
Route::post('judgingSubmit/{token}', [JudgingPageController::class, 'submit'])->where('token', $token)->middleware('throttle:240,1');

Route::get('judging_answers/{token}', JudgingAnswersController::class)->where('token', $token);
Route::get('judging_draw/{show}', JudgingDrawController::class)->whereNumber('show');

Route::get('judging_qr_code/show/{show}', [JudgingQrController::class, 'sheet'])->whereNumber('show');
Route::get('judging_qr_code/{token}', [JudgingQrController::class, 'car'])->where('token', $token);
