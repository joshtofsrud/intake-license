<?php
// MARKER-CATALOG-IMPORT-ALL

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\CatalogChangeBatch;
use App\Services\Distributors\DistributorCatalogImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Import a distributor catalog into a tenant's inventory, however large.
 *
 * Pages through the candidate rows rather than loading the lot: a 47,000-row
 * catalog would otherwise hydrate 47,000 models at once and hold a web worker
 * for minutes. ShouldBeUnique per tenant+distributor so a double-click cannot
 * run two passes over the same catalog.
 */
class ImportDistributorCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    private const PAGE = 2000;

    public function __construct(
        public string $tenantId,
        public string $code,
        public array $filters,
        public string $batchId,
    ) {}

    public function uniqueId(): string
    {
        return 'catalog-import:' . $this->tenantId . ':' . $this->code;
    }

    public function handle(DistributorCatalogImportService $importer): void
    {
        $tenant = Tenant::find($this->tenantId);
        $batch  = CatalogChangeBatch::find($this->batchId);
        if (! $tenant || ! $batch) {
            return;
        }

        $totals = ['created' => 0, 'merged' => 0, 'skipped' => 0, 'matched_catalog' => 0, 'errors' => 0]; // MARKER-IMPORT-SKU-MERGE
        $offset = 0;

        // MARKER-CATALOG-PROGRESS-HOLD — the import service rewrites this same
        // batch row each page as its undo ledger, resetting status and
        // progress_total. Keep the total here and re-assert state per page.
        $total = (int) $batch->progress_total
            ?: $importer->candidateCount($this->code, $this->filters);

        try {
            while (true) {
                $res = $importer->import($tenant->id, $this->code, $this->filters, false, self::PAGE, $offset);

                $seen = (int) ($res['matched_catalog'] ?? 0);
                foreach (['created', 'merged', 'skipped', 'matched_catalog', 'errors'] as $k) {
                    $totals[$k] += (int) ($res[$k] ?? 0);
                }

                $offset += self::PAGE;
                $batch->update([
                    'status'         => 'running',
                    'progress_stage' => 'importing',
                    'progress_total' => $total,
                    'progress_done'  => min($offset, $total),
                    'item_count'     => $totals['created'] + $totals['merged'],
                ]);

                // A short page means the catalog is exhausted.
                if ($seen < self::PAGE) {
                    break;
                }
            }

            $batch->update([
                'status'         => 'done',
                'progress_done'  => $total,
                'progress_total' => $total,
                'progress_stage' => 'done',
                'result'         => $totals,
                'item_count'     => $totals['created'] + $totals['merged'],
            ]);
        } catch (\Throwable $e) {
            // MARKER-JOB-ISSUES — reported, not just logged: master admin
            // sees it, the alert address gets it, and it carries a refId.
            $ref = \App\Support\JobFailureReporter::report(
                self::class,
                $this->code . ' catalog import stopped after ' . number_format($totals['created'] + $totals['merged']) . ' items',
                $e,
                ['batch_id' => $this->batchId, 'code' => $this->code, 'offset' => $offset, 'totals' => $totals],
                $this->tenantId
            );
            // Items already imported stay imported and stay on this batch, so
            // the existing undo still covers exactly what was written.
            $batch->update([
                'status' => 'failed', 'progress_stage' => 'failed',
                'result' => $totals + ['ref_id' => $ref, 'error' => \Illuminate\Support\Str::limit($e->getMessage(), 300)],
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        CatalogChangeBatch::where('id', $this->batchId)
            ->update(['status' => 'failed', 'progress_stage' => 'failed']);
    }
}
