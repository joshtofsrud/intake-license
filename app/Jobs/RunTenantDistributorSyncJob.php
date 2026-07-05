<?php
// MARKER-PATCH-555

namespace App\Jobs;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\TenantDistributorSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RunTenantDistributorSyncJob — the "Sync now" / "Dry run" button behind
 * Catalog attention. Runs every active subscription for one tenant,
 * aggregates the per-subscription stats, and writes a
 * tenant_distributor_sync_runs row for the page to display.
 */
class RunTenantDistributorSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public bool $dryRun = false,
        public string $trigger = 'manual',
    ) {}

    public function handle(TenantDistributorSyncService $service): void
    {
        $runId = (string) Str::uuid();
        DB::table('tenant_distributor_sync_runs')->insert([
            'id' => $runId, 'tenant_id' => $this->tenantId,
            'trigger' => $this->trigger, 'dry_run' => $this->dryRun,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $agg = []; $error = null;
        try {
            $subs = TenantDistributorCatalogSubscription::query()
                ->where('tenant_id', $this->tenantId)->where('is_active', true)->get();
            foreach ($subs as $sub) {
                $res = $service->sync($sub, $this->dryRun);
                foreach ($res as $k => $v) {
                    if (is_numeric($v)) $agg[$k] = ($agg[$k] ?? 0) + $v;
                }
                if (!empty($res['errors'])) {
                    $agg['errors'] = array_merge($agg['errors'] ?? [], (array) $res['errors']);
                }
            }
            if ($subs->isEmpty()) $agg['note'] = 'no active subscriptions';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        DB::table('tenant_distributor_sync_runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'stats' => json_encode($agg),
            'error' => $error,
            'updated_at' => now(),
        ]);
    }
}
