<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ----------------------------------------------------------------
// Debug log retention — prune old rows nightly per config/debug.php.
// ----------------------------------------------------------------
Schedule::command('debug-log:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();
