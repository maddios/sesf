<?php

use App\Http\Controllers\Api\HomeController;
use Illuminate\Support\Facades\Route;

// Public home page JSON. URLs match the legacy HomeController (getEvents, getSponsors). Public, no auth.

Route::get('home/events', [HomeController::class, 'events']);

Route::get('home/sponsors', [HomeController::class, 'sponsors']);
