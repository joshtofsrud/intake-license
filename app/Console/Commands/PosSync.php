<?php

namespace App\Console\Commands;

use App\Jobs\PosCatalogSyncJob;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosCatalogSync;
use Illuminate\Console\Command;

/**
 * MARKER-CONNECTED-POS-CATALOG: keeps every Connected POS current.
 * Every minute: pick up changes since the last run. --full: re-read everything
 * (nightly reconcile). A shop whose run is still going is left alone, unless it
 * has been stuck long enough to be dead.
 */
class PosSync extends Command
{
    protected $signature = 'pos:sync {--full : Re-read every product and count from the start}';
    protected $description = 'Bring Connected POS products, prices and stock into Intake';

    public function handle(PosCatalogSync $sync): int
    {
        $full = (bool) $this->option('full');
        TenantPosConnection::where('sync_enabled', true)->where('status', 'connected')->each(function (TenantPosConnection $conn) use ($sync, $full) {
            $st = (array) ($conn->sync_state ?? []);
            $stuck = ($st['running'] ?? false) && $conn->updated_at && $conn->updated_at->lt(now()->subMinutes(15));
            if (($st['running'] ?? false) && ! $stuck) {
                return;
            }
            $sync->start($conn, $full);
            PosCatalogSyncJob::dispatch($conn->id);
        });

        return self::SUCCESS;
    }
}
