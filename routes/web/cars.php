<?php

use App\Http\Controllers\Api\CarDirectoryController;
use App\Http\Controllers\Api\CarProfileReadController;
use Illuminate\Support\Facades\Route;

// Legacy JSON contract for the Vue car pages. URLs, verbs and keys are kept as they were.
Route::get('cars/list-filters', [CarDirectoryController::class, 'listFilters']);
Route::get('cars/list', [CarDirectoryController::class, 'list']);

Route::get('car/profile', [CarProfileReadController::class, 'profile']);
Route::get('car/body', [CarProfileReadController::class, 'body']);
Route::get('car/type', [CarProfileReadController::class, 'type']);
