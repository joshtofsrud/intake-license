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
// ----------------------------------------------------------------
// MARKER-PATCH-247 — overdue rentals sweep: emits the rental.overdue
// staff alert (derived state, so it must be polled). Idempotent.
// ----------------------------------------------------------------
Schedule::command('rentals:overdue-sweep')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

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

// ----------------------------------------------------------------
// MARKER-PATCH-155 — 24-hour delivery reminders
// Same hourly cadence as appointments:remind. reminded_at column
// is the idempotence guard.
// ----------------------------------------------------------------
Schedule::command('deliveries:remind')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-PATCH-529 — assume-first delivery windows. Pending proposals
// past their deadline get the first open window locked in. Status
// transition is the idempotence guard.
// ----------------------------------------------------------------
// ----------------------------------------------------------------
// MARKER-PATCH-555 — two-tier distributor sync, nightly. Tier 1 pulls
// the HLC catalog delta; tier 2 reconciles every tenant's linked items
// (cost, availability, vanish + title flags) an hour later.
// ----------------------------------------------------------------
// MARKER-PATCH-574 — abandoned-cart hygiene, nightly
Schedule::command('orders:reap-abandoned')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('distributors:sync-catalog HLC --delta')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('distributors:sync-tenant --all')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('deliveries:assume-windows')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-PATCH-387 — reap abandoned booking holds (charge-then-create).
// Cheap indexed delete. Hourly is plenty; holds expire in 20 min and are
// only deleted 2h past expiry so a lagging webhook can still materialize a
// genuinely-paid hold first.
// ----------------------------------------------------------------
Schedule::command('bookings:reap-holds')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();


// ----------------------------------------------------------------
// MARKER-PATCH-614 — auto-close time punches left open past the cap.
// Hourly so a forgotten clock-out is capped within the hour rather
// than billing overnight. auto_closed flag + audit row guard it.
// ----------------------------------------------------------------
Schedule::command('timeclock:auto-close')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

