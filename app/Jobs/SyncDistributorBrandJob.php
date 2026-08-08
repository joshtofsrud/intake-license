<?php
// MARKER-BRAND-SYNC

namespace App\Jobs;

use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorCatalogSyncService;
use App\Services\Distributors\DistributorRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refresh a SINGLE brand for one distributor. Efficient for pagesByBrand
 * adapters (QBP fetches only that brand); for others it pulls the full feed
 * and writes just the target brand. Mirrors SyncDistributorCatalogJob.
 */
class SyncDistributorBrandJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    /** One refresh per (distributor, brand) at a time. */
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'distributor-brand-sync-' . strtoupper($this->distributorCode) . '-' . $this->brandName;
    }

    public function __construct(
        public string $distributorCode,
        public string $brandName,
    ) {}

    public function handle(DistributorRegistry $registry, DistributorCatalogSyncService $sync): void
    {
        $code = strtoupper($this->distributorCode);
        $conn = PlatformDistributorConnection::forCode($code);

        if (! $conn->api_key) {
            Log::warning("SyncDistributorBrandJob: no platform API key for {$code}.");
            return;
        }

        $adapter = $registry->make($code, ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us']);
        if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
            $adapter->setAuthStyle($conn->auth_style);
        }

        $res = $sync->syncBrand($adapter, $this->brandName);
        Log::info("SyncDistributorBrandJob {$code}/{$this->brandName}: wrote {$res['written']} of {$res['seen']} variants.");
    }
}
