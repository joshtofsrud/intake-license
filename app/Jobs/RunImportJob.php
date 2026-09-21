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

/** MARKER-IMPORT-QUEUE — the real write, off the web request. */
class RunImportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1500;
    public int $tries   = 1;

    public function __construct(public string $tenantId, public string $importId) {}

    public function uniqueId(): string
    {
        return 'import-run-' . $this->importId;
    }

    public function handle(): void
    {
        // MARKER-IMPORT-STATUS-RACE — every exit from here says why. This job
        // used to return on a status mismatch without a word, so a race
        // between the preview finishing and the run starting looked exactly
        // like nothing happening at all.
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            \Illuminate\Support\Facades\Log::warning('RunImportJob: tenant gone', [
                'tenant' => $this->tenantId, 'import' => $this->importId,
            ]);
            return;
        }

        $import = TenantImport::where('tenant_id', $tenant->id)->find($this->importId);
        if (! $import) {
            \Illuminate\Support\Facades\Log::warning('RunImportJob: import gone', [
                'tenant' => $tenant->id, 'import' => $this->importId,
            ]);
            return;
        }

        if ($import->status !== 'running') {
            // Recoverable: the row still says a run was asked for, so say so
            // on the row itself rather than disappearing.
            $reason = 'The run was asked for but the import was in "' . $import->status
                . '" when the worker picked it up, so nothing was written. Press Import again.';

            $import->forceFill([
                'status'           => 'failed',
                'failure_reason'   => $reason,
                'progress_stage'   => 'failed',
                'progress_seen_at' => now(),
            ])->save();

            \App\Support\JobFailureReporter::report(
                static::class,
                'Import run refused itself: status was ' . $import->status,
                new \RuntimeException($reason),
                ['import' => $import->id],
                $tenant->id
            );
            return;
        }

        try {
            $importer = $import->type === 'inventory'
                ? new InventoryImporter($tenant, $import)
                : new CustomerImporter($tenant, $import);

            $result = $importer->run();

            // MARKER-IMPORT-QUEUE-CLEAN — the failed rows, or the download link
            // on the finished screen has nothing behind it.
            $errorPath = ! empty($result['errorRows'])
                ? \App\Support\ImportErrorCsv::write($import, $result['errorRows'])
                : null;

            $cancelled = (bool) $import->fresh()->cancel_requested_at;

            $import->forceFill([
                'status'           => $cancelled ? 'cancelled' : 'done',
                'failure_reason'   => null, // MARKER-IMPORT-RESULTS
                'totals'           => array_merge((array) $import->totals, ['run' => $result['counts'] ?? $result]),
                'error_path'       => $errorPath,
                'finished_at'      => now(),
                'progress_stage'   => $cancelled ? 'cancelled' : 'finished',
                'progress_seen_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $reason = trim((string) $e->getMessage()) ?: class_basename($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
            $import->forceFill([
                'status'           => 'failed',
                'failure_reason'   => $reason,
                'finished_at'      => now(),
                'progress_stage'   => 'failed',
                'progress_seen_at' => now(),
            ])->save();
            \App\Support\JobFailureReporter::report(static::class, 'Import run failed', $e,
                ['import' => $import->id], $tenant->id);
        }
    }
}
