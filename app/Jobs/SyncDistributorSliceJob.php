<?php
// MARKER-SYNC-CHUNKED

namespace App\Jobs;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\TenantDistributorSyncService;
use App\Support\DistributorSyncRuns;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One slice of a "Sync now" run: the linked items of one distributor with
 * pivot id in (after, upto]. Planned by RunTenantDistributorSyncJob.
 *
 * A distributor that throws is recorded and marked failed, and its remaining
 * slices skip — the other distributors still run (MARKER-SYNC-ISOLATE). Only a
 * slice that dies outright (timeout, killed worker) ends the chain, and then
 * failed() closes the run with the reason.
 */
class SyncDistributorSliceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $tenantId,
        public string $subscriptionId,
        public string $code,
        public ?string $after,
        public ?string $upto,
        public bool $dryRun,
        public int $slice,
        public int $slices,
    ) {}

    public function handle(TenantDistributorSyncService $service): void
    {
        if (in_array($this->code, DistributorSyncRuns::failedCodes($this->tenantId, $this->runId), true)) {
            DistributorSyncRuns::merge($this->tenantId, $this->runId, ['slices_done' => 1]);
            return;
        }

        $sub = TenantDistributorCatalogSubscription::query()
            ->where('tenant_id', $this->tenantId)->whereKey($this->subscriptionId)->first();
        if (! $sub || ! $sub->is_active) {
            DistributorSyncRuns::merge($this->tenantId, $this->runId,
                ['slices_done' => 1, 'errors' => ['disconnected or paused during the run']], $this->code, true);
            return;
        }

        try {
            $res = $service->sync($sub, $this->dryRun, $this->after, $this->upto);
        } catch (\Throwable $e) {
            DistributorSyncRuns::merge($this->tenantId, $this->runId,
                ['slices_done' => 1, 'errors' => [$e->getMessage()]], $this->code, true);
            JobFailureReporter::report(self::class, $this->code . ' sync failed inside a "Sync now" run', $e,   // MARKER-JOB-ISSUES-2
                ['code' => $this->code, 'slice' => $this->slice . '/' . $this->slices], $this->tenantId);
            return;
        }

        $res['slices_done'] = 1;
        DistributorSyncRuns::merge($this->tenantId, $this->runId, $res, $this->code);
    }

    public function failed(\Throwable $e): void
    {
        DistributorSyncRuns::close($this->tenantId, $this->runId,
            $this->code . ' stopped at slice ' . $this->slice . ' of ' . $this->slices . ': ' . $e->getMessage());
        JobFailureReporter::report(self::class, $this->code . ' sync slice ' . $this->slice . '/' . $this->slices . ' stopped', $e,
            ['code' => $this->code], $this->tenantId);
    }
}
