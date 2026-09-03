<?php

namespace App\Console\Commands;

use App\Models\PlatformSettings;
use App\Models\Tenant;
use App\Models\TenantChargeRun;
use App\Services\Billing\ChargeService;
use Illuminate\Console\Command;

/**
 * MARKER-BILLING-CHARGE — the hourly pass: settle balances over the threshold,
 * retry what failed, and reconcile anything left mid-flight.
 *
 * Does nothing at all while the master switch is off, which is its default.
 */
class BillingChargeDue extends Command
{
    protected $signature   = 'billing:charge-due {--dry : report what would happen, charge nothing}';
    protected $description = 'Charge tenants whose usage balance has crossed their threshold';

    public function handle(ChargeService $charges): int
    {
        $master = (bool) (PlatformSettings::current()->charging_enabled ?? false);
        if (! $master && ! $this->option('dry')) {
            $this->line('charging is switched off platform-wide — nothing to do');
            return self::SUCCESS;
        }

        // 1. anything stuck mid-flight: ask Stripe, never retry blind
        foreach (TenantChargeRun::where('status', TenantChargeRun::CHARGING)
            ->where('updated_at', '<', now()->subMinutes(10))->get() as $run) {
            $this->line("reconciling run {$run->id}");
            if (! $this->option('dry')) $charges->reconcile($run);
        }

        // 2. failed runs whose retry is due
        foreach (TenantChargeRun::where('status', TenantChargeRun::FAILED)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())->get() as $run) {
            $this->line("retrying run {$run->id} (attempt " . ($run->attempts + 1) . ')');
            if (! $this->option('dry')) $charges->charge($run);
        }

        // 3. balances over the threshold
        $charged = 0;
        foreach (Tenant::where('is_platform', false)->where('is_demo', false)->get() as $tenant) {
            $balance   = $charges->unbilledCents($tenant);
            $threshold = $charges->threshold($tenant);

            if ($balance < $threshold) continue;

            if (! $charges->canCharge($tenant)) {
                $this->line(sprintf('  %-28s $%s over threshold, but charging is off or no card',
                    $tenant->name, number_format($balance / 100, 2)));
                continue;
            }

            $this->line(sprintf('  %-28s $%s → charge', $tenant->name, number_format($balance / 100, 2)));

            if (! $this->option('dry')) {
                $run = $charges->maybeCharge($tenant);
                if ($run && $run->status === TenantChargeRun::CHARGED) $charged++;
            }
        }

        $this->info($this->option('dry') ? 'dry run — nothing charged' : "charged: {$charged}");
        return self::SUCCESS;
    }
}
