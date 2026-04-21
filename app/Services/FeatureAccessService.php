<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FeatureAccessService
 *
 * Single source of truth for "does this tenant have access to feature X?"
 *
 * Resolution order:
 *   1. Is there an active suppression for this (tenant, addon)? -> NO access.
 *   2. Is there an active tenant_addons row?                    -> YES access.
 *   3. Is this addon included in the tenant's plan tier?        -> YES access.
 *   4. Otherwise                                                -> NO access.
 *
 * "Active" tenant_addons status: 'active', 'canceling', 'failed_payment'.
 *   - canceling keeps access until current_period_end (Option A).
 *   - failed_payment keeps access during the grace window.
 *
 * Caching: per-request only, keyed by tenant_id. The master admin writes
 * invalidate via clearCache(). We do NOT use long-lived cache here because
 * feature gates need to reflect staff toggles instantly.
 */
class FeatureAccessService
{
    /**
     * Per-request memoization. Keyed by tenant_id, value is a resolved
     * access map: ['addon_code' => true|false].
     *
     * @var array<int, array<string, bool>>
     */
    protected static array $requestCache = [];

    public function hasAddon(Tenant $tenant, string $code): bool
    {
        $map = $this->resolveAccessMap($tenant);
        return $map[$code] ?? false;
    }

    public function activeAddonCodes(Tenant $tenant): array
    {
        $map = $this->resolveAccessMap($tenant);
        return array_keys(array_filter($map));
    }

    /**
     * Detailed feature breakdown for the master admin UI.
     */
    public function detailedFeatureBreakdown(Tenant $tenant): Collection
    {
        $addons = DB::table('addons')
            ->whereIn('status', ['active', 'deprecated'])
            ->orderBy('sort_order')
            ->get();

        $tenantAddons = DB::table('tenant_addons')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->get()
            ->keyBy('addon_code');

        $suppressions = DB::table('tenant_addon_suppressions')
            ->where('tenant_id', $tenant->id)
            ->whereNull('lifted_at')
            ->get()
            ->keyBy('addon_code');

        $planTier = $tenant->plan_tier ?? 'starter';

        return $addons->map(function ($addon) use ($tenantAddons, $suppressions, $planTier) {
            $includedPlans = $addon->included_in_plans
                ? json_decode($addon->included_in_plans, true)
                : [];

            $planIncludes = is_array($includedPlans) && in_array($planTier, $includedPlans, true);

            $tenantAddon = $tenantAddons->get($addon->code);
            $suppression = $suppressions->get($addon->code);

            $source = null;
            $hasAccess = false;

            if ($suppression) {
                $hasAccess = false;
                $source = null;
            } elseif ($tenantAddon) {
                $hasAccess = true;
                $source = $tenantAddon->source;
            } elseif ($planIncludes) {
                $hasAccess = true;
                $source = 'plan_tier';
            }

            return (object) [
                'code' => $addon->code,
                'name' => $addon->name,
                'description' => $addon->description,
                'tooltip' => $addon->tooltip,
                'category' => $addon->category,
                'price_cents' => $addon->price_cents,
                'price_display_override' => $addon->price_display_override,
                'billing_cadence' => $addon->billing_cadence,
                'included_in_plans' => $includedPlans,
                'sort_order' => $addon->sort_order,
                'is_self_serve' => (bool) $addon->is_self_serve,
                'is_new' => (bool) $addon->is_new,
                'status' => $addon->status,

                'has_access' => $hasAccess,
                'source' => $source,
                'plan_includes' => $planIncludes,

                'tenant_addon_id' => $tenantAddon->id ?? null,
                'tenant_addon_status' => $tenantAddon->status ?? null,
                'current_period_end' => $tenantAddon->current_period_end ?? null,
                'canceling_at' => $tenantAddon->canceling_at ?? null,

                'is_suppressed' => (bool) $suppression,
                'suppression_id' => $suppression->id ?? null,
                'suppression_reason' => $suppression->reason ?? null,
            ];
        });
    }

    public function clearCache(Tenant $tenant): void
    {
        unset(static::$requestCache[$tenant->id]);
    }

    public static function flushRequestCache(): void
    {
        static::$requestCache = [];
    }

    protected function resolveAccessMap(Tenant $tenant): array
    {
        if (isset(static::$requestCache[$tenant->id])) {
            return static::$requestCache[$tenant->id];
        }

        $addons = DB::table('addons')
            ->where('status', 'active')
            ->select('code', 'included_in_plans')
            ->get();

        $planTier = $tenant->plan_tier ?? 'starter';

        $map = [];
        foreach ($addons as $addon) {
            $includedPlans = $addon->included_in_plans
                ? json_decode($addon->included_in_plans, true)
                : [];

            $map[$addon->code] = is_array($includedPlans)
                && in_array($planTier, $includedPlans, true);
        }

        $active = DB::table('tenant_addons')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->pluck('addon_code');

        foreach ($active as $code) {
            $map[$code] = true;
        }

        $suppressed = DB::table('tenant_addon_suppressions')
            ->where('tenant_id', $tenant->id)
            ->whereNull('lifted_at')
            ->pluck('addon_code');

        foreach ($suppressed as $code) {
            $map[$code] = false;
        }

        static::$requestCache[$tenant->id] = $map;

        return $map;
    }
}
