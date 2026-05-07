<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * memberships:tick
 *
 * Runs daily. Two jobs in one command:
 *
 *   1. Membership period rollover. For every active membership whose
 *      current_period_end has passed, advance the period by one month and
 *      reset classes_used_this_period. Without this, "monthly limit"
 *      memberships stay frozen on a single period's usage forever.
 *
 *   2. Pack expiry. For every active or exhausted pack whose expires_at
 *      has passed, flip status='expired'. Existing credits stay on the row
 *      for accounting honesty, but the pack stops being eligible for class
 *      coverage.
 *
 * Both operations are idempotent — running twice in a day is a no-op the
 * second time. Safe to retry on failure.
 *
 * Wire-up: Josh runs the existing intake-scheduler externally (see
 * addons:expire pattern). Add this command to the same crontab/systemd
 * timer as `0 4 * * * php artisan memberships:tick` or similar. Or add to
 * routes/console.php Schedule::command() if standardizing on Laravel scheduler.
 */
class MembershipsTickCommand extends Command
{
    protected $signature = 'memberships:tick';

    protected $description = 'Roll over membership periods and expire stale packs.';

    public function handle(): int
    {
        $now = now();

        $rolled  = $this->rolloverMemberships($now);
        $expired = $this->expirePacks($now);

        $this->info("Rolled over {$rolled} membership period(s). Expired {$expired} pack(s).");
        return self::SUCCESS;
    }

    /**
     * Find every active membership whose period has lapsed and advance it.
     *
     * Loop chunks to avoid loading the whole table — at scale a tenant could
     * have thousands of active memberships. Each row is a tiny update so the
     * cursor is fine.
     */
    private function rolloverMemberships(Carbon $now): int
    {
        $count = 0;

        TenantCustomerMembership::where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $now->toDateString())
            ->cursor()
            ->each(function (TenantCustomerMembership $m) use (&$count, $now) {
                try {
                    DB::transaction(function () use ($m, $now) {
                        // Advance the period. We anchor off the existing end so
                        // a membership that lapsed mid-month still rolls cleanly,
                        // but if the period_end is way in the past (system was
                        // down for a month), we catch up to "next from now" so
                        // we don't loop. Most cases: period_end was yesterday,
                        // new start = today, new end = today + 1 month.
                        $newStart = $m->current_period_end->copy()->addDay();
                        if ($newStart->lt($now->copy()->startOfDay())) {
                            $newStart = $now->copy()->startOfDay();
                        }
                        $newEnd = $newStart->copy()->addMonth()->subDay();

                        $m->update([
                            'current_period_start'     => $newStart,
                            'current_period_end'       => $newEnd,
                            'classes_used_this_period' => 0,
                        ]);
                    });
                    $count++;
                } catch (\Throwable $e) {
                    // Don't fail the whole batch on one bad row.
                    Log::warning('memberships:tick rollover failed', [
                        'membership_id' => $m->id,
                        'tenant_id'     => $m->tenant_id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            });

        return $count;
    }

    /**
     * Mark packs whose expires_at has passed as expired. Includes both
     * 'active' (had unused credits) and 'exhausted' (zero credits remaining)
     * — both should transition to 'expired' for accurate reporting.
     *
     * Note: 'cancelled' packs are left alone. Their cancellation is the
     * terminal event — expiring them would obscure the history.
     */
    private function expirePacks(Carbon $now): int
    {
        return TenantCustomerPack::whereIn('status', ['active', 'exhausted'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now->toDateString())
            ->update(['status' => 'expired']);
    }
}
