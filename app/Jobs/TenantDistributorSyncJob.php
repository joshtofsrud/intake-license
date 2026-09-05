<?php
// MARKER-PATCH-HLC7A

namespace App\Jobs;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\TenantDistributorSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tier-2 sync for one tenant subscription (their key): cost, availability,
 * sell-price seed, vanish flags. Stamps last_sync_* on the subscription.
 */
class TenantDistributorSyncJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /** Only one refresh per subscription at a time. */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'tenant-distributor-sync-' . $this->subscriptionId;
    }

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
            // MARKER-JOB-ISSUES-2 — a sync failing nightly for a month was
            // visible only on the shop's connection page, to whoever looked.
            \App\Support\JobFailureReporter::report(self::class, ($sub->distributor_code ?? 'Distributor') . ' catalog sync failed', $e,
                ['subscription_id' => $sub->id, 'code' => $sub->distributor_code ?? null], $sub->tenant_id);
            Log::error('TenantDistributorSyncJob failed', ['sub' => $sub->id, 'error' => $e->getMessage()]);
        }
    }
}
