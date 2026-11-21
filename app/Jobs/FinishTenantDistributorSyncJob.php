<?php

namespace App\Jobs;

use App\Support\DistributorSyncRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Last link of a "Sync now" chain: every slice ran, so the run is finished. */
class FinishTenantDistributorSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(public string $runId, public string $tenantId) {}

    public function handle(): void
    {
        DistributorSyncRuns::close($this->tenantId, $this->runId);
        // MARKER-WH-COUNT-FAST: the warehouse stock counts are worked out here, in the background,
        // so the next page that shows them has the fresh numbers without waiting on the count
        try {
            if ($tenant = \App\Models\Tenant::find($this->tenantId)) {
                \App\Services\Distributors\WarehouseRegistry::refreshStockCounts($tenant);
            }
        } catch (\Throwable $e) {
            report($e); // the sync itself is finished; a count that couldn't be refreshed keeps the last one
        }
    }
}
