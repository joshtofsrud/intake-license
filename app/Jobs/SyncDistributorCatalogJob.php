<?php
// MARKER-PATCH-HLC6

namespace App\Jobs;

use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorCatalogSyncService;
use App\Services\Distributors\DistributorRegistry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
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
class SyncDistributorCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

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

        $res = $sync->syncIdentity($adapter, $since);
        Log::info("SyncDistributorCatalogJob {$code}: wrote {$res['written']} of {$res['seen']} variants.");
    }
}
