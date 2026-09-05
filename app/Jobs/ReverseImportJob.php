<?php
// MARKER-IMPORT-PROGRESS

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantImport;
use App\Services\Tenant\Import\ImportReverser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Undo an import off the web request. ShouldBeUnique on the import id: a
 * double-clicked button or a reclaimed job cannot start a second pass over
 * the same rows. (The reverse is idempotent — rows are stamped reversed_at —
 * but two passes still means two lots of load, which is the thing being
 * fixed here.)
 */
class ReverseImportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;   // comfortably under the queue's retry_after
    public int $tries = 1;

    public function __construct(public string $tenantId, public string $importId) {}

    public function uniqueId(): string
    {
        return 'reverse-import:' . $this->importId;
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) return;

        $import = TenantImport::where('tenant_id', $tenant->id)->find($this->importId);
        if (! $import || $import->status !== 'reversing') return;

        try {
            $result = (new ImportReverser($tenant, $import))->reverse();

            $import->update([
                'status'         => 'reversed',
                'totals'         => array_merge((array) $import->totals, ['reversal' => $result]),
                'progress_stage' => 'reversed',
                'progress_seen_at' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('ReverseImportJob failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            // Back to done: the rows already undone stay stamped, so pressing
            // Reverse again picks up exactly where this left off.
            $import->update([
                'status'         => 'done',
                'failure_reason' => 'Reverse stopped: ' . $e->getMessage(),
                'progress_stage' => 'failed',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        TenantImport::where('id', $this->importId)->update([
            'status' => 'done', 'progress_stage' => 'failed',
            'failure_reason' => 'Reverse stopped: ' . $e->getMessage(),
        ]);
    }
}
