<?php
// MARKER-PATCH-555
// MARKER-SYNC-CHUNKED — this job now PLANS the run and hands the work to a
// chain of SyncDistributorSliceJob (about 2,000 linked items each, per
// distributor), finished by FinishTenantDistributorSyncJob. As one job it
// could not finish a large catalog inside its 30-minute limit — WMM timed
// out five times Sep 9–12 — and every timeout left the run row open, so
// Catalog attention showed "running" until the next press.

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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RunTenantDistributorSyncJob — the "Sync now" / "Dry run" / "Refresh"
 * buttons. Writes a tenant_distributor_sync_runs row for the page to show.
 */
class RunTenantDistributorSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Linked items per slice job. Each slice makes ~80 API calls at 50 per call. */
    public const SLICE = 2000;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public bool $dryRun = false,
        public string $trigger = 'manual',
        public ?string $runId = null,
    ) {
        // Known before handle() so failed() can close the same row.
        $this->runId ??= (string) Str::uuid();
    }

    public function handle(TenantDistributorSyncService $service): void
    {
        DB::table('tenant_distributor_sync_runs')->insert([
            'id' => $this->runId, 'tenant_id' => $this->tenantId,
            'trigger' => $this->trigger, 'dry_run' => $this->dryRun,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $subs = TenantDistributorCatalogSubscription::query()
                ->where('tenant_id', $this->tenantId)->where('is_active', true)
                ->orderBy('distributor_code')->get();

            if ($subs->isEmpty()) {
                DistributorSyncRuns::merge($this->tenantId, $this->runId, ['note' => 'no active subscriptions']);
                DistributorSyncRuns::close($this->tenantId, $this->runId);
                return;
            }

            $plan = [];
            foreach ($subs as $sub) {
                $code = strtoupper((string) $sub->distributor_code);
                $ids  = $service->linkedPivotQuery($this->tenantId, $code)->orderBy('id')->pluck('id');

                // One slice even when nothing is linked, so a missing map or
                // bad credentials still surface on the run as they always did.
                $chunks = $ids->isEmpty() ? [[]] : $ids->chunk(self::SLICE)->map->values()->all();
                $after  = null;
                foreach ($chunks as $i => $chunk) {
                    $last  = $i === count($chunks) - 1;
                    // The last slice is open-ended, so items linked while the
                    // run is planned are still included.
                    $upto  = $last ? null : (string) $chunk[count($chunk) - 1];
                    $plan[] = [$sub->id, $code, $after, $upto];
                    $after = $upto;
                }
            }

            $total = count($plan);
            $jobs = [];
            foreach ($plan as $n => [$subId, $code, $after, $upto]) {
                $jobs[] = new SyncDistributorSliceJob(
                    $this->runId, $this->tenantId, (string) $subId, $code, $after, $upto, $this->dryRun, $n + 1, $total
                );
            }
            $jobs[] = new FinishTenantDistributorSyncJob($this->runId, $this->tenantId);

            DistributorSyncRuns::merge($this->tenantId, $this->runId, ['slices_total' => $total]);
            Bus::chain($jobs)->dispatch();
        } catch (\Throwable $e) {
            DistributorSyncRuns::close($this->tenantId, $this->runId, 'Run could not start: ' . $e->getMessage());
            JobFailureReporter::report(self::class, '"Sync now" run could not start', $e, [], $this->tenantId);   // MARKER-JOB-ISSUES-2
        }
    }

    /** Timed out or killed while planning: close the row rather than leave it "running". */
    public function failed(\Throwable $e): void
    {
        DistributorSyncRuns::close($this->tenantId, (string) $this->runId, 'Run stopped while planning: ' . $e->getMessage());
    }
}
