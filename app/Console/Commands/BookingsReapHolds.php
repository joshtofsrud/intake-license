<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantPendingBooking;
use Illuminate\Console\Command;

/**
 * MARKER-PATCH-387 — bookings:reap-holds.
 *
 * Clears out charge-then-create booking holds:
 *  - abandoned: status 'pending' AND expires_at more than 2h ago. The buffer
 *    beyond the 20-minute hold window guarantees that any genuinely-paid hold
 *    whose payment_intent.succeeded webhook is delayed has already been
 *    materialized by the webhook backstop before we delete anything.
 *  - stale materialized: status 'materialized' older than 30 days. The
 *    appointment exists and the webhook-idempotency window is long closed.
 */
class BookingsReapHolds extends Command
{
    protected $signature   = 'bookings:reap-holds';
    protected $description = 'Delete abandoned booking holds and prune old materialized ones.';

    public function handle(): int
    {
        $abandoned = TenantPendingBooking::where('status', 'pending')
            ->where('expires_at', '<', now()->subHours(2))
            ->delete();

        $pruned = TenantPendingBooking::where('status', 'materialized')
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Reaped {$abandoned} abandoned holds, pruned {$pruned} old materialized holds.");

        return self::SUCCESS;
    }
}
