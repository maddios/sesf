<?php

use Illuminate\Support\Facades\Route;

// Feature route files live in routes/web/*.php (one per area) and are loaded here, so parallel
// work on different areas does not collide in this file. The SPA shell stays last-defined at '/'.
foreach (glob(__DIR__.'/web/*.php') ?: [] as $routeFile) {
    require $routeFile;
}

Route::view('/', 'app')->name('spa');
