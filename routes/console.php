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

// ----------------------------------------------------------------
// Memberships & packs daily tick — period rollover + pack expiry.
// Runs at 04:00 to follow the debug log prune. Idempotent.
// ----------------------------------------------------------------
Schedule::command('memberships:tick')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-PATCH-151C — Prune tenant_funnel_events older than 90 days.
// Cheap (single composite-indexed delete in chunks). Runs at 03:00 so
// it finishes well before debug-log:prune at 03:30.
// ----------------------------------------------------------------
Schedule::command('funnel:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-PATCH-118 - Custom domain state polling
// Cheap (per-row API call to Cloudflare, only for domains past their
// backoff). Failures per-domain don't kill the batch.
// ----------------------------------------------------------------
Schedule::command('domains:poll')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-PATCH-154 — 24-hour appointment reminders
// Hourly cron with a 23-25h window. reminded_at column on the row is
// the idempotence guard so each appointment is reminded once.
// ----------------------------------------------------------------
Schedule::command('appointments:remind')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

