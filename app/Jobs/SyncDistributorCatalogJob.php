<?php
// MARKER-PATCH-HLC6

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
    ) {}

    public function handle(DistributorRegistry $registry, DistributorCatalogSyncService $sync): void
    {
        $code = strtoupper($this->distributorCode);
        $conn = PlatformDistributorConnection::forCode($code);

        if (! $conn->api_key) {
            Log::warning("SyncDistributorCatalogJob: no platform API key for {$code}.");
            return;
        }

        $adapter = $registry->make($code, ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us']);
        if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
            $adapter->setAuthStyle($conn->auth_style);
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
