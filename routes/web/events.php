<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventListController;
use Illuminate\Support\Facades\Route;

// Public event JSON (GET only). URLs match the legacy CarShowList and EventProfile GET methods.
// Public, no auth. Registration, extras and cars are not routed here.

Route::get('events/list-filters', [EventListController::class, 'listFilters']);

Route::get('events/car-shows', [EventListController::class, 'carShows']);

Route::get('event/info', [EventController::class, 'info']);

Route::get('event/agenda', [EventController::class, 'agenda']);

Route::get('event/locations', [EventController::class, 'locations']);

Route::get('event/news', [EventController::class, 'news']);

Route::get('event/attendees', [EventController::class, 'attendees']);

Route::get('event/sponsors', [EventController::class, 'sponsors']);

Route::get('event/schedule', [EventController::class, 'schedule']);
