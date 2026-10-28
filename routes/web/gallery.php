<?php

use App\Http\Controllers\Api\GalleryReadController;
use App\Http\Controllers\Api\GalleryWriteController;
use Illuminate\Support\Facades\Route;

// Gallery JSON. URLs and verbs match the legacy Route::controller('gallery', 'GalleryController').
// Reads are public (access rules per gallery); writes need a session and CSRF (web middleware).
// Uploads are one photo per request, so the throttle allows a bulk upload of a full event.

Route::get('gallery/checked-in', [GalleryReadController::class, 'checkedIn']);
Route::get('gallery/car-show-list', [GalleryReadController::class, 'carShowList']);
Route::get('gallery/list', [GalleryReadController::class, 'list']);
Route::get('gallery/breadcrumb', [GalleryReadController::class, 'breadcrumb']);
Route::get('gallery/info', [GalleryReadController::class, 'info']);
Route::get('gallery/items', [GalleryReadController::class, 'items']);

Route::post('gallery/items', [GalleryWriteController::class, 'saveItems']);
Route::post('gallery/create', [GalleryWriteController::class, 'create']);
Route::post('gallery/show-hide', [GalleryWriteController::class, 'showHide']);
Route::post('gallery/upload-file', [GalleryWriteController::class, 'uploadFile'])->middleware('throttle:120,1');
Route::post('gallery/default-item', [GalleryWriteController::class, 'defaultItem']);
Route::post('gallery/remove-item', [GalleryWriteController::class, 'removeItem']);
