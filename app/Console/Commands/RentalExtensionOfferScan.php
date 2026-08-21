<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantRental;
use App\Services\RentalExtensionOfferService;
use Illuminate\Console\Command;

/**
 * MARKER-RENTAL-EXT — rentals:extension-offer-scan. Every 15 minutes:
 * for tenants with the add-on active AND the setting on, find out
 * rentals due within the send window whose unit sits empty afterward,
 * and fire the SMS magic-link offer. Quiet hours skip the tenant for
 * this pass. Idempotent: one open offer per rental episode.
 */
class RentalExtensionOfferScan extends Command
{
    protected $signature = 'rentals:extension-offer-scan';
    protected $description = 'Send last-minute extension offers for eligible rentals.';

    public function handle(RentalExtensionOfferService $svc): int
    {
        $expired = $svc->expireStale();
        $sent = 0;

        $candidates = TenantRental::query()
            ->where('status', 'out')
            ->whereNull('returned_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addHours(6)) // coarse pre-filter; per-tenant window below
            ->get();

        $tenants = [];
        foreach ($candidates as $rental) {
            $tenants[$rental->tenant_id] ??= Tenant::find($rental->tenant_id);
            $tenant = $tenants[$rental->tenant_id];
            if (!$tenant || !$svc->isFeatureOn($tenant)) continue;
            if ($svc->inQuietHours($tenant)) continue;

            $cfg = $svc->settings($tenant);
            if ($rental->due_at->gt(now()->addMinutes($cfg['send_before']))) continue;

            $reason = null;
            $e = $svc->eligibility($tenant, $rental, $reason);
            if (!$e) continue;

            $svc->createAndSend($tenant, $rental, $e, 'auto');
            $sent++;
        }

        $this->info("Extension scan: {$sent} offers sent, {$expired} expired.");
        return self::SUCCESS;
    }
}
