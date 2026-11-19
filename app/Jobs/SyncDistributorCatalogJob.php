<?php

namespace App\Jobs;

use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorCatalogSyncService;
use App\Services\Distributors\DistributorRegistry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tier-1 catalog sync as a background job (redis). Dispatched from the
 * Distributor hub "Run sync" buttons; reads the platform connection for the key.
 */
class SyncDistributorCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /** A worker OOM-kill isn't counted as an attempt, so a crash would
     *  retry from page 1 forever. Cap the whole job by wall-clock and
     *  don't auto-retry — memory is fixed, but this is the backstop. */
    public int $tries = 1;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    /** Only one catalog sync per distributor at a time. */
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'distributor-catalog-sync-' . strtoupper($this->distributorCode);
    }

    public function __construct(
        public string $distributorCode = 'HLC',
        public bool $delta = false,
        // a few products only (Kona: handles), for trying one before the whole catalog
        public array $only = [],
    ) {}

    public function handle(DistributorRegistry $registry, DistributorCatalogSyncService $sync): void
    {
        $code = strtoupper($this->distributorCode);
        $conn = PlatformDistributorConnection::forCode($code);

        if (! $conn->api_key && $registry->needsKey($code)) {
            Log::warning("SyncDistributorCatalogJob: no platform API key for {$code}.");
            return;
        }

        $adapter = $registry->make($code, ['api_key' => (string) $conn->api_key, 'region' => $conn->region ?? 'us']);
        if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
            $adapter->setAuthStyle($conn->auth_style);
        }
        if ($this->only && method_exists($adapter, 'only')) {
            $adapter->only($this->only);
        }
        // a reader that goes one product at a time writes each into the
        // catalog as it is read, so the catalog fills while the read runs
        if (method_exists($adapter, 'onBike')) {
            $adapter->onBike(fn (array $product) => $sync->writeProduct($code, $adapter->name(), $product));
        }

        $since = null;
        if ($this->delta) {
            $st = DB::table('distributor_sync_state')
                ->where('distributor_code', $code)->where('source_ref', 'catalog')->first();
            if ($st?->last_synced_at) {
                $since = Carbon::parse($st->last_synced_at);
            }
        }

        try {
            $res = $sync->syncIdentity($adapter, $since);
            Log::info("SyncDistributorCatalogJob {$code}: wrote {$res['written']} of {$res['seen']} variants.");
            // MARKER-SYNC-REINDEX: index and match now, so today's imports can
            // see this distributor's part numbers and barcodes across catalogs.
            RefreshCatalogMatchesJob::dispatch($code);

            // a read that stopped at its time limit with more to read hands
            // over to a fresh job (Kona: one bike at a time takes hours)
            if (method_exists($adapter, 'hasMore') && $adapter->hasMore()) {
                \Illuminate\Support\Facades\Cache::put('brand_suppliers.queued.' . $code, now()->timestamp, now()->addHour());
                ContinueCatalogReadJob::dispatch($code)->delay(now()->addSeconds(15));
            }
        } catch (\Throwable $e) {
            DB::table('distributor_sync_state')->updateOrInsert(
                ['distributor_code' => $code, 'source_ref' => 'catalog'],
                ['last_status' => 'failed', 'last_run_at' => now(),
                 'last_error' => $e->getMessage(), 'updated_at' => now(), 'created_at' => now()],
            );
            throw $e;
        }
    }
}
