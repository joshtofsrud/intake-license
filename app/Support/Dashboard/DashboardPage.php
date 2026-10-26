<?php

namespace App\Support\Dashboard;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\Tenant\DashboardDataService;

/**
 * MARKER-DASH-PREVIEW: everything a dashboard frame draws, for one person at
 * one shop. The shop's own dashboard (DashboardController) and master admin's
 * preview both build the page here, so a preview is the real thing: the same
 * figures, the same blocks, and the same access rules.
 */
class DashboardPage
{
    /**
     * @param object|null $user  who it's for: a shop person, or (impersonating) master admin's user for the greeting
     * @param array $rows  the rows to draw, already cut to what this person gets
     * @param array $opts  'setup_dismissed', 'wof_dismissed' (cookies on the real page)
     */
    public static function data(Tenant $tenant, ?object $user, BlockAccess $see, array $rows, array $opts = []): array
    {
        $service = new DashboardDataService($tenant);

        // compute zones in order, then pass today + attention into
        // zoneLauncher so it can reuse already-computed counts.
        $today = $service->zoneToday();
        $attention = $see->attention($service->zoneAttention());
        $workOrderBanner = $service->workOrderBanner((bool) ($opts['wof_dismissed'] ?? false));

        $data = [
            'greeting'  => $service->greeting($user),
            'today'     => $today,
            'attention' => $attention,
            'growth'    => $service->zoneGrowth(),
            'launcher'  => $service->zoneLauncher($today, $attention),
            'progress'  => $service->onboardingProgress((bool) ($opts['setup_dismissed'] ?? false)),
            'workOrderBanner' => $see->canRoute('tenant.work-order-fields.index') ? $workOrderBanner : null,
            'see'       => $see,
            'dashRows'  => $rows,
        ];

        // Only load what these blocks draw.
        $needs = BlockRegistry::needs($rows);
        if (in_array('week', $needs, true)) {
            $data['week'] = $service->weekAhead();
        }
        if (in_array('jobs', $needs, true)) {
            $data['jobs'] = $service->jobsCompleted30();
        }
        if (in_array('tiles', $needs, true)) {
            $data['tiles'] = $see->tiles(\App\Support\DashboardTiles::layout($tenant, $user instanceof TenantUser ? $user : null));
        }

        return $data;
    }
}
