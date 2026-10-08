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
        // config:cache-safe.
        return match ($tier) {
            'starter' => (int) config('intake.plan_prices.starter', 2900),
            'branded' => (int) config('intake.plan_prices.branded', 7900),
            'scale'   => (int) config('intake.plan_prices.scale', 19900),
            'custom'  => (int) config('intake.plan_prices.custom', 0), // quoted individually
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
     * is this shop actually using Intake? Quick numbers
     * for the master admin tenant cards. Cached 2 minutes per tenant.
     */
    public function pulse(Tenant $tenant): array
    {
        return Cache::remember("tenant_pulse:{$tenant->id}", 120, function () use ($tenant) {
            $id  = $tenant->id;
            $tz  = $tenant->timezone ?: 'America/Los_Angeles';
            $now = now($tz);
            [$dayStart, $dayEnd] = tenant_day_utc_range($now, $tz);
            $paid = fn () => DB::table('tenant_sales')->where('tenant_id', $id)
                ->where('payment_status', 'paid')->whereNull('refund_of_sale_id');

            $today = (clone $paid())->where('paid_at', '>=', $dayStart)->where('paid_at', '<', $dayEnd)
                ->selectRaw('COUNT(*) n, COALESCE(SUM(total_cents),0) c')->first();
            $wk = (clone $paid())->where('paid_at', '>=', now()->subDays(7))
                ->selectRaw('COUNT(*) n, COALESCE(SUM(total_cents),0) c')->first();
            $prevWk = (int) (clone $paid())->whereBetween('paid_at', [now()->subDays(14), now()->subDays(7)])->sum('total_cents');
            $m30 = (clone $paid())->where('paid_at', '>=', now()->subDays(30))
                ->selectRaw('COUNT(*) n, SUM(CASE WHEN customer_id IS NULL THEN 0 ELSE 1 END) w')->first();

            $last = collect([
                DB::table('tenant_sales')->where('tenant_id', $id)->max('created_at'),
                DB::table('tenant_appointments')->where('tenant_id', $id)->max('updated_at'),
                DB::table('tenant_users')->where('tenant_id', $id)->max('last_login_at'),
            ])->filter()->max();

            $openJobs = DB::table('tenant_appointments')->where('tenant_id', $id)
                ->whereIn('status', ['pending', 'confirmed', 'in_progress'])->count();
            $weekBookings = DB::table('tenant_appointments')->where('tenant_id', $id)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereBetween('appointment_date', [$now->toDateString(), $now->copy()->addDays(6)->toDateString()])->count();

            $pp = (string) ($tenant->payment_processor_status ?? 'not_started');
            $cards = ($pp === 'connected' || ! empty($tenant->stripe_connect_charges_enabled)) ? 'yes'
                : (in_array($pp, ['intent_recorded', 'connecting'], true) || ! empty($tenant->stripe_connect_account_id) ? 'pending' : 'no');

            $problems = 0;
            try {
                $problems = DB::table('debug_logs')->where('tenant_id', $id)->whereIn('severity', ['error', 'critical'])
                    ->where('is_resolved', false)->where('created_at', '>=', now()->subDays(7))->count();
            } catch (\Throwable $e) {}

            return [
                'today_cents'  => (int) $today->c,
                'today_count'  => (int) $today->n,
                'week_cents'   => (int) $wk->c,
                'week_count'   => (int) $wk->n,
                'week_change'  => $prevWk > 0 ? (int) round(((int) $wk->c - $prevWk) * 100 / $prevWk) : null,
                'attach_pct'   => (int) $m30->n > 0 ? (int) round((int) $m30->w * 100 / (int) $m30->n) : null,
                'sales_30d'    => (int) $m30->n,
                'last_active'  => $last,
                'open_jobs'    => $openJobs,
                'week_bookings'=> $weekBookings,
                'cards'        => $cards,
                'problems'     => $problems,
            ];
        });
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
