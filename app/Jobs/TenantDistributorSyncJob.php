<?php
// MARKER-PATCH-HLC7A

namespace App\Jobs;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\TenantDistributorSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tier-2 sync for one tenant subscription (their key): cost, availability,
 * sell-price seed, vanish flags. Stamps last_sync_* on the subscription.
 */
class TenantDistributorSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public string $subscriptionId) {}

    public function handle(TenantDistributorSyncService $svc): void
    {
        $sub = TenantDistributorCatalogSubscription::find($this->subscriptionId);
        if (! $sub) {
            return;
        }

        try {
            $res = $svc->sync($sub);
            $sub->last_sync_at = now();
            $sub->last_sync_status = empty($res['errors']) ? 'ok' : 'partial';
            $sub->last_sync_error = $res['errors'] ? implode(' | ', array_slice($res['errors'], 0, 3)) : null;
            $sub->save();
        } catch (\Throwable $e) {
            $sub->last_sync_at = now();
            $sub->last_sync_status = 'failed';
            $sub->last_sync_error = $e->getMessage();
            $sub->save();
            Log::error('TenantDistributorSyncJob failed', ['sub' => $sub->id, 'error' => $e->getMessage()]);
        }
    }
}
