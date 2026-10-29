<?php

use App\Http\Controllers\LegacyImageController;
use Illuminate\Support\Facades\Route;

// Legacy JitImage resize URLs (thapp/jitimage route "images/{params}/{source}"). Loaded from bootstrap/app.php
// outside the web group: images are public and must not start sessions or set cookies. Existing files under
// public/images are served by the web server before this route is reached.
Route::get('images/{spec}', LegacyImageController::class)
    ->where('spec', '[124]/.+')
    ->middleware('throttle:legacy-images');
