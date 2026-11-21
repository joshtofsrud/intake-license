<?php

namespace App\Jobs;

use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosImportService;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * MARKER-CONNECTED-POS-SECTION: one step of an Inventory › Import run from the Connected POS;
 * queues the next step until the list is done. One run per shop at a time. A run the shop
 * stopped keeps its place, and Resume carries on from there.
 */
class PosImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public string $connectionId) {}

    public function handle(PosImportService $import): void
    {
        $lock = Cache::lock('pos_import:' . $this->connectionId, 290);
        if (! $lock->get()) {
            return;
        }

        $conn = TenantPosConnection::find($this->connectionId);
        try {
            if (! $conn || ! (($conn->import_state ?? [])['running'] ?? false)) {
                return;
            }
            $more = $import->step($conn);
        } catch (Throwable $e) {
            if ($conn) {
                $import->fail($conn, $e->getMessage());
                JobFailureReporter::report(self::class, 'Connected POS import stopped', $e, ['adapter' => $conn->adapter_code], $conn->tenant_id);
            }
            return;
        } finally {
            $lock->release();
        }

        if ($more) {
            self::dispatch($this->connectionId);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($conn = TenantPosConnection::find($this->connectionId)) {
            app(PosImportService::class)->fail($conn, $e->getMessage());
        }
    }
}
