<?php

use App\Http\Controllers\Api\ProfileImageController;
use Illuminate\Support\Facades\Route;

// Member profile photo for the SPA. URL and verb match the legacy MemberProfile::postProfileImage.
// Web middleware (session + CSRF) applies. Uploads are throttled to 20 a minute per user.

Route::post('member/profile-image', [ProfileImageController::class, 'store'])->middleware('throttle:20,1');
