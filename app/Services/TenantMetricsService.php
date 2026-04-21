<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * TenantMetricsService
 *
 * Per-tenant metrics used in master admin lists and dashboards:
 *   - mrr_cents: recurring monthly revenue (plan + active self_serve addons)
 *   - addon_count: number of active addons (self_serve + staff_push + beta_comp)
 *   - bookings_30d: appointments created in the last 30 days
 *   - last_activity: most recent appointment timestamp (or null)
 *
 * Caching: per-tenant, 60-second TTL. Not meant for real-time — meant for
 * list views where a few seconds of staleness is fine.
 *
 * Usage:
 *   $m = app(TenantMetricsService::class)->forTenant($tenant);
 *   $m['mrr_cents'];
 *   // or bulk:
 *   $all = app(TenantMetricsService::class)->forMany(Tenant::all());
 */
class TenantMetricsService
{
    protected const CACHE_TTL_SECONDS = 60;

    /**
     * Plan tier prices in cents. Read from env with fallbacks.
     * Keep aligned with intakepricingandtiers.pdf.
     */
    public function planPriceCents(string $tier): int
    {
        return match ($tier) {
            'starter' => (int) env('PLAN_PRICE_STARTER', 2900),
            'branded' => (int) env('PLAN_PRICE_BRANDED', 7900),
            'scale'   => (int) env('PLAN_PRICE_SCALE', 19900),
            'custom'  => (int) env('PLAN_PRICE_CUSTOM', 0), // quoted individually
            default   => 0,
        };
    }

    /**
     * Compute metrics for a single tenant. Cached.
     */
    public function forTenant(Tenant $tenant): array
    {
        return Cache::remember(
            "tenant_metrics:{$tenant->id}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->compute($tenant)
        );
    }

    /**
     * Compute metrics for multiple tenants. Uses the cache per-tenant;
     * does NOT batch DB calls yet (can optimize if 100+ tenants).
     */
    public function forMany($tenants): array
    {
        $out = [];
        foreach ($tenants as $t) {
            $out[$t->id] = $this->forTenant($t);
        }
        return $out;
    }

    /**
     * Invalidate the cache for a tenant. Call after any addon or
     * subscription change.
     */
    public function clearCache(Tenant $tenant): void
    {
        Cache::forget("tenant_metrics:{$tenant->id}");
    }

    // ==================================================================
    // Internal
    // ==================================================================

    protected function compute(Tenant $tenant): array
    {
        $isTrial = $this->isOnTrial($tenant);
        $planCents = $this->planPriceCents($tenant->plan_tier ?? 'starter');

        // Active self_serve addons contribute to MRR. staff_push / beta_comp
        // do not bill, so exclude them from MRR even though they count as
        // active for the addon_count metric.
        $paidAddons = DB::table('tenant_feature_addons')
            ->join('addons', 'tenant_feature_addons.addon_code', '=', 'addons.code')
            ->where('tenant_feature_addons.tenant_id', $tenant->id)
            ->whereIn('tenant_feature_addons.status', ['active', 'canceling', 'failed_payment'])
            ->where('tenant_feature_addons.source', 'self_serve')
            ->where('addons.billing_cadence', 'monthly')
            ->sum('addons.price_cents');

        $allActiveAddons = DB::table('tenant_feature_addons')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->count();

        $mrrCents = $isTrial ? 0 : ($planCents + (int) $paidAddons);

        $bookings30d = DB::table('tenant_appointments')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $lastActivity = DB::table('tenant_appointments')
            ->where('tenant_id', $tenant->id)
            ->max('created_at');

        return [
            'mrr_cents'     => $mrrCents,
            'is_trial'      => $isTrial,
            'plan_cents'    => $planCents,
            'addon_mrr'     => (int) $paidAddons,
            'addon_count'   => $allActiveAddons,
            'bookings_30d'  => $bookings30d,
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * Tenant is on trial if onboarding_status is 'pending' AND created less
     * than 14 days ago. Adjust here if trial logic changes.
     */
    protected function isOnTrial(Tenant $tenant): bool
    {
        if (($tenant->onboarding_status ?? null) !== 'pending') {
            return false;
        }
        if (! $tenant->created_at) {
            return false;
        }
        return $tenant->created_at->diffInDays(now()) < 14;
    }
}
