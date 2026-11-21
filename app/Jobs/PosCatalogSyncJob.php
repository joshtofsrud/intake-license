<?php

namespace App\Jobs;

use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosCatalogSync;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * MARKER-CONNECTED-POS-CATALOG: one step of a Connected POS import; queues the
 * next step until caught up. One run per shop at a time.
 */
class PosCatalogSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public string $connectionId) {}

    public function handle(PosCatalogSync $sync): void
    {
        $lock = Cache::lock('pos_catalog_sync:' . $this->connectionId, 290);
        if (! $lock->get()) {
            return;
        }

        $conn = TenantPosConnection::find($this->connectionId);
        try {
            // MARKER-CONNECTED-POS-SECTION: a read the shop asked for runs with sync off too (it only records and links)
            if (! $conn || $conn->status !== 'connected' || ! (($conn->sync_state ?? [])['running'] ?? false)) {
                return;
            }
            $more = $sync->step($conn);
        } catch (Throwable $e) {
            if ($conn) {
                $sync->fail($conn, $e->getMessage());
                JobFailureReporter::report(self::class, 'Connected POS sync failed', $e, ['adapter' => $conn->adapter_code], $conn->tenant_id);
            }
            return;
        } finally {
            $lock->release();
        }

        if ($more) {
            self::dispatch($this->connectionId);
        }
    }
}
