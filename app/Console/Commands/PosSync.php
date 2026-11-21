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
            if (! $this->addonOn($conn)) {
                return;
            }
            $st = (array) ($conn->sync_state ?? []);
            if ($st['stopped'] ?? false) {
                return; // MARKER-POS-STOP-RESUME: the shop stopped it; Resume restarts it
            }
            $stuck = ($st['running'] ?? false) && $conn->updated_at && $conn->updated_at->lt(now()->subMinutes(15));
            if (($st['running'] ?? false) && ! $stuck) {
                return;
            }
            $sync->start($conn, $full);
            PosCatalogSyncJob::dispatch($conn->id);
        });

        // MARKER-POS-CUSTOMERS-IN: customers changed since the last run, for shops that bring them in
        TenantPosConnection::where('customers_in', true)->where('status', 'connected')->each(function (TenantPosConnection $conn) use ($full) {
            if (! $this->addonOn($conn)) {
                return;
            }
            $st = (array) ($conn->customer_sync ?? []);
            if (($st['stopped'] ?? false) || empty($st['last_done'])) {
                return; // stopped by the shop, or the first import hasn't finished: Import / Resume handle those
            }
            $stuck = ($st['running'] ?? false) && $conn->updated_at && $conn->updated_at->lt(now()->subMinutes(15));
            if (($st['running'] ?? false) && ! $stuck) {
                return;
            }
            app(\App\Services\Pos\PosCustomerSync::class)->start($conn, $full);
            \App\Jobs\PosCustomerSyncJob::dispatch($conn->id);
        });

        // MARKER-POS-SALES-HISTORY: new sales made in the Connected POS, for shops that keep them
        // coming. Only in 2 Way Data (it stops at Switch over), and only once the first history
        // read has finished. The nightly --full does not re-read history; this is always "since last time".
        TenantPosConnection::where('sales_in', true)->where('status', 'connected')->where('mode', TenantPosConnection::TWO_WAY)->each(function (TenantPosConnection $conn) {
            if (! $this->addonOn($conn)) {
                return;
            }
            $st = (array) ($conn->sales_state ?? []);
            if (($st['stopped'] ?? false) || empty($st['last_done'])) {
                return; // stopped by the shop, or the first read hasn't finished: Start / Resume on the Sales history tab handle those
            }
            // a check that failed (the Connected POS was briefly unreachable) is simply tried again from where it was
            $stuck = ($st['running'] ?? false) && $conn->updated_at && $conn->updated_at->lt(now()->subMinutes(15));
            if (($st['running'] ?? false) && ! $stuck) {
                return;
            }
            app(\App\Services\Pos\PosSalesSync::class)->start($conn, false);
            \App\Jobs\PosSalesSyncJob::dispatch($conn->id);
        });

        return self::SUCCESS;
    }

    /** MARKER-CONNECTED-POS-SECTION: a shop whose Connected POS add-on was taken away is no longer read. */
    private function addonOn(TenantPosConnection $conn): bool
    {
        $tenant = $conn->tenant;

        return $tenant && $tenant->connected_pos_enabled;
    }
}
