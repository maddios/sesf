<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The queue is consumed only by the worker service (queue:work); the scheduler only schedules.
Schedule::command('legacy-images:prune')->daily();
