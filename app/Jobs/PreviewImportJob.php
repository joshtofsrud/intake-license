<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantImport;
use App\Services\Tenant\Import\CustomerImporter;
use App\Services\Tenant\Import\InventoryImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** MARKER-IMPORT-QUEUE — the dry run, off the web request. */
class PreviewImportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1500;   // under the 2000s REDIS_QUEUE_RETRY_AFTER
    public int $tries   = 1;

    public function __construct(public string $tenantId, public string $importId) {}

    public function uniqueId(): string
    {
        return 'import-preview-' . $this->importId;
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) { return; }

        $import = TenantImport::where('tenant_id', $tenant->id)->find($this->importId);
        if (! $import) { return; }

        try {
            $importer = $import->type === 'inventory'
                ? new InventoryImporter($tenant, $import)
                : new CustomerImporter($tenant, $import);

            $result = $importer->preview();

            $import->forceFill([
                'status'         => $import->cancel_requested_at ? 'draft' : 'previewed',
                // MARKER-IMPORT-CATS — the category tally rides with the counts
                // so the review screen needs no second pass over the file.
                'totals'         => array_merge((array) $import->totals, [
                    'preview'          => $result['counts'],
                    'categories'       => $result['categories'] ?? [],
                    'categoriesCapped' => $result['categoriesCapped'] ?? false,
                    'newCategories'    => $result['newCategories'] ?? [],
                    'newVendors'       => $result['newVendors'] ?? [],
                ]),
                'progress_stage' => $import->cancel_requested_at ? 'cancelled' : 'finished',
                'progress_seen_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $reason = trim((string) $e->getMessage()) ?: class_basename($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
            $import->forceFill([
                'status'           => 'failed',
                'failure_reason'   => $reason,
                'progress_stage'   => 'failed',
                'progress_seen_at' => now(),
            ])->save();
            \App\Support\JobFailureReporter::report(static::class, 'Import preview failed', $e,
                ['import' => $import->id], $tenant->id);
        }
    }
}
