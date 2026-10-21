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

        // MARKER-CLASSES-P2: renewals are billed now (card / desk / comp).
        $billing = app(\App\Services\MembershipBillingService::class)->run($now);
        $rolled  = $billing['renewed'] + $billing['charged'];
        $this->info('Memberships: ' . json_encode($billing));
        $expired = $this->expirePacks($now);

        $this->info("Rolled over {$rolled} membership period(s). Expired {$expired} pack(s).");
        return self::SUCCESS;
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
        // same per-tenant local-date treatment as rollover.
        $count = 0;
        // MARKER-CLASSES-P1: one row at a time so each expiry lands in the credit ledger.
        foreach (self::tenantLocalDates() as $tenantId => $localToday) {
            TenantCustomerPack::where('tenant_id', $tenantId)
                ->whereIn('status', ['active', 'exhausted'])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $localToday)
                ->get()
                ->each(function (TenantCustomerPack $p) use (&$count) {
                    DB::transaction(function () use ($p) {
                        $left = (int) $p->credits_remaining;
                        $p->update(['status' => 'expired']);
                        if ($left > 0) {
                            \App\Services\ClassCreditLedger::record($p->tenant_id, $p->customer_id, -$left, 'expired', ['pack_id' => $p->id], "{$left} unused credit(s)");
                        }
                    });
                    $count++;
                });
        }
        return $count;
    }

    /**
     * map of tenant_id => that tenant's local "today"
     * (Y-m-d). One query; used by daily jobs so business-date comparisons
     * respect each tenant's timezone instead of the UTC calendar.
     */
    public static function tenantLocalDates(): array
    {
        return \App\Models\Tenant::query()
            ->pluck('timezone', 'id')
            ->map(fn ($tz) => now($tz ?: config('app.timezone', 'UTC'))->toDateString())
            ->all();
    }
}
