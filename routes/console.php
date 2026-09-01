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
// MARKER-CAMPAIGN-DELIVERY — drain pending campaign sends, 120/min.
Schedule::command('campaigns:process-sends')
    ->everyMinute()
    ->withoutOverlapping();

// MARKER-QBP-CLS-AUTO — keep each QBP subscription's image service prefix
// fresh. Without it the catalog still syncs and only images go missing, which
// is exactly why it went unnoticed until a shop asked where their photos were.
Schedule::command('qbp:cls-refresh')
    ->cron('10 4 */3 * *')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('rentals:extension-offer-scan')
    ->everyFifteenMinutes(); // MARKER-RENTAL-EXT

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

// MARKER-CATALOG-SCHEDULE — the catalog chain. Order matters: each step
// reads what the one before it wrote, and a step that runs early produces a
// half-built index rather than an error.

Schedule::command('distributors:sync-catalog HLC --delta')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// MARKER-DELTA-REAL — QBP pages by brand: 892 calls regardless, so --delta
// saves database writes rather than fetches. It carries modifiedTime.iMillis,
// which isUnchanged now reads, so unchanged rows are skipped on write.
Schedule::command('distributors:sync-catalog QBP --delta')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// MARKER-DELTA-REAL — BTI was never scheduled at all; its catalog last moved
// by hand. Full, not delta: BTI supplies no per-row timestamp, and its feed
// regenerates whole on every request anyway.
Schedule::command('distributors:sync-catalog BTI')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// Reads the rows both syncs just wrote. Without this, matching sees nothing
// new — which is exactly why 55,773 QBP rows were invisible to the importer.
Schedule::command('catalog:index-identifiers')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

// Reads the identifiers the index just wrote. Links the same product across
// distributors so the importer can say "already carried" instead of creating
// a duplicate.
Schedule::command('catalog:match')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Moved from 05:00 to 06:30: per-tenant cost and stock should land after
// matching, so a newly linked source is priced on the same night it links.
Schedule::command('distributors:sync-tenant --all')
    ->dailyAt('06:30')
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

// ----------------------------------------------------------------
// MARKER-PATCH-622 — rebuild shop-search typo vocabulary nightly,
// after the distributor syncs (4/5AM) so new catalog words land.
// ----------------------------------------------------------------
Schedule::command('search:build-terms')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-REG-SETTINGS — reap stale register drafts/quotes nightly,
// per each tenant's retention setting (default: keep forever).
// ----------------------------------------------------------------
Schedule::command('sales:reap-drafts')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();
// ----------------------------------------------------------------
// MARKER-GIFTCARDS-PUBLIC — e-gift delivery: scheduled deliver_on
// dates plus a backstop for failed issue-time sends. Idempotent.
// ----------------------------------------------------------------
Schedule::command('gift-cards:deliver')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
// ----------------------------------------------------------------
// MARKER-GC-FUNCTIONS — clear abandoned (never paid) online gift card
// purchases per each shop's retention setting. Rows with any ledger
// history are never in scope.
// ----------------------------------------------------------------
Schedule::command('gift-cards:reap-pending')
    ->dailyAt('03:25')
    ->withoutOverlapping()
    ->runInBackground();

// MARKER-SCHED-PUBLIC — reminder emails for booked calls. The command stamps
// before sending, so overlap can't double-send.
Schedule::command('bookings:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// MARKER-SCHED-GOOGLE — pull Google busy time for the booking window.
Schedule::command('bookings:sync-google')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
