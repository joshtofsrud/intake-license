<?php
// MARKER-PATCH-HLC3B

namespace App\Console\Commands;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\TenantDistributorSyncService;
use Illuminate\Console\Command;

class DistributorsSyncTenantCommand extends Command
{
    protected $signature = 'distributors:sync-tenant
        {subscription? : Subscription id to sync}
        {--all : Sync every active subscription}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Tier-2 per-tenant sync: live cost + availability for linked items, sell-price seed, vanish flags.';

    public function handle(TenantDistributorSyncService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($this->argument('subscription')) {
            $subs = TenantDistributorCatalogSubscription::query()
                ->whereKey($this->argument('subscription'))->get();
        } elseif ($this->option('all')) {
            $subs = TenantDistributorCatalogSubscription::query()->where('is_active', true)->get();
        } else {
            $this->error('Pass a subscription id or --all.');
            return self::FAILURE;
        }

        if ($subs->isEmpty()) {
            $this->warn('No matching subscriptions.');
            return self::SUCCESS;
        }

        foreach ($subs as $sub) {
            $this->info(($dry ? '[dry-run] ' : '') . "Tenant {$sub->tenant_id} · {$sub->distributor_code} ...");
            try {
                $res = $service->sync($sub, $dry);
            } catch (\Throwable $e) {
                $this->error('  ' . $e->getMessage());
                continue;
            }
            $this->table(
                ['metric', 'value'],
                collect($res)->except('errors')->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : $v])->values()->all()
            );
            foreach (array_slice($res['errors'], 0, 5) as $e) {
                $this->line("  - {$e}");
            }
        }

        return self::SUCCESS;
    }
}
