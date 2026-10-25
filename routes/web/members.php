<?php

use App\Http\Controllers\Api\MemberDirectoryController;
use App\Http\Controllers\Api\MemberProfileReadController;
use Illuminate\Support\Facades\Route;

// Legacy JSON contract for the Vue member pages. URLs, verbs and keys are kept as they were.
Route::get('members/list-filters', [MemberDirectoryController::class, 'listFilters']);
Route::get('members/users', [MemberDirectoryController::class, 'users']);

Route::get('member/profile', [MemberProfileReadController::class, 'profile']);
Route::get('member/member-info', [MemberProfileReadController::class, 'memberInfo']);
Route::get('member/member-preferences', [MemberProfileReadController::class, 'memberPreferences']);
Route::get('member/cars', [MemberProfileReadController::class, 'cars']);
Route::get('member/car-list', [MemberProfileReadController::class, 'carList']);
Route::get('member/attended-events', [MemberProfileReadController::class, 'attendedEvents']);
