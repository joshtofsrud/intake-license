<?php
// MARKER-SYNC-CHUNKED

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
    }
}
