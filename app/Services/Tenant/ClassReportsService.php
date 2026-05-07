<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantClassMembershipProduct;
use App\Models\Tenant\TenantClassPackProduct;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ClassReportsService
 *
 * Builds the panels shown on /admin/classes/reports. Each public method
 * returns either a Collection of customer-shaped rows (for list panels) or
 * an array of stats (for the headline strip + top earning products table).
 *
 * Time windows are constants here. If we ever expose them as UI inputs,
 * accept Carbon dates as method args. For v1 the values match the mockup.
 *
 * Each panel method takes $tenantId because we'll likely call this from
 * the controller and possibly from a future digest email job. Don't bake in
 * tenant() global scope.
 *
 * Performance notes:
 *   - All queries scope on tenant_id (always indexed)
 *   - List panels use eager-loaded relationships, no N+1
 *   - Default panel size is 25 — good enough for the page render; the
 *     "View all" button can paginate beyond
 */
class ClassReportsService
{
    /** Default rows per list panel — first page on the report page. */
    public const PANEL_LIMIT = 25;

    /** Window for "drop-in regulars" — paid drop-ins in last 90 days. */
    private const DROPIN_WINDOW_DAYS = 90;

    /** Min drop-ins to qualify as a "regular" (per the conversion panel). */
    private const DROPIN_MIN_COUNT = 3;

    /** Window for "used-up packs" — packs exhausted in this window. */
    private const USED_PACK_WINDOW_DAYS = 60;

    /** Window for "recently cancelled memberships". */
    private const CANCELLED_WINDOW_DAYS = 30;

    /** Window for "lapsed memberships" — expired/cancelled in last 90 days. */
    private const LAPSED_WINDOW_DAYS = 90;

    // ------------------------------------------------------------------
    // Headline strip (4 numbers across the top of the page)
    // ------------------------------------------------------------------

    /**
     * Top-of-page summary numbers. Cheap-ish — 4 count queries.
     */
    public function headline(string $tenantId): array
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();

        $activeMembers = TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $activePacks = TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $packCreditsLeft = (int) TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->sum('credits_remaining');

        $newMembersThisMonth = TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('created_at', '>=', $monthStart)
            ->count();

        // Drop-ins this month: per_class or cash payment_method on registrations
        // that landed in the current calendar month.
        $dropinsThisMonth = TenantClassRegistration::where('tenant_id', $tenantId)
            ->whereIn('payment_method', ['per_class', 'cash'])
            ->where('registered_at', '>=', $monthStart)
            ->count();

        $dropinCustomersThisMonth = TenantClassRegistration::where('tenant_id', $tenantId)
            ->whereIn('payment_method', ['per_class', 'cash'])
            ->where('registered_at', '>=', $monthStart)
            ->distinct('customer_id')
            ->count('customer_id');

        // Lapsed last 30 days: memberships that went cancelled OR expired.
        // Approximation: status changed to one of those terminal states recently.
        // We don't have a status_changed_at column, so we proxy off updated_at.
        $lapsedRecently = TenantCustomerMembership::where('tenant_id', $tenantId)
            ->whereIn('status', ['cancelled', 'expired'])
            ->where('updated_at', '>=', $now->copy()->subDays(self::CANCELLED_WINDOW_DAYS))
            ->count();

        // Estimated ARR at risk: sum of monthly prices for those memberships.
        $arrAtRisk = (int) TenantCustomerMembership::where('tenant_id', $tenantId)
            ->whereIn('status', ['cancelled', 'expired'])
            ->where('updated_at', '>=', $now->copy()->subDays(self::CANCELLED_WINDOW_DAYS))
            ->join('tenant_class_membership_products', 'tenant_customer_memberships.product_id', '=', 'tenant_class_membership_products.id')
            ->sum('tenant_class_membership_products.price_cents');

        return [
            'active_members'                => $activeMembers,
            'active_members_delta'          => $newMembersThisMonth,
            'active_packs'                  => $activePacks,
            'pack_credits_remaining'        => $packCreditsLeft,
            'dropins_this_month'            => $dropinsThisMonth,
            'dropin_customers_this_month'   => $dropinCustomersThisMonth,
            'lapsed_recent'                 => $lapsedRecently,
            'arr_at_risk_cents'             => $arrAtRisk,
        ];
    }

    // ------------------------------------------------------------------
    // Panel: Drop-in regulars
    // Customers with N+ drop-in registrations in last 90d, no active
    // membership and no active pack. The "convert to membership" gold.
    // ------------------------------------------------------------------

    public function dropInRegulars(string $tenantId, int $limit = self::PANEL_LIMIT): Collection
    {
        $since = now()->subDays(self::DROPIN_WINDOW_DAYS);

        // Aggregate drop-ins per customer in the window
        $rows = TenantClassRegistration::where('tenant_id', $tenantId)
            ->whereIn('payment_method', ['per_class', 'cash'])
            ->where('registered_at', '>=', $since)
            ->whereIn('status', ['registered', 'checked_in'])
            ->select('customer_id', DB::raw('COUNT(*) as dropin_count'), DB::raw('SUM(paid_cents) as total_spend_cents'))
            ->groupBy('customer_id')
            ->having('dropin_count', '>=', self::DROPIN_MIN_COUNT)
            ->orderByDesc('dropin_count')
            ->limit($limit * 2) // over-fetch — we'll filter for active coverage below
            ->get()
            ->keyBy('customer_id');

        if ($rows->isEmpty()) return collect();

        $customerIds = $rows->keys()->all();

        // Find which of those customers have active coverage to exclude
        $hasMembership = TenantCustomerMembership::where('tenant_id', $tenantId)
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->pluck('customer_id')
            ->all();

        $hasPack = TenantCustomerPack::where('tenant_id', $tenantId)
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->pluck('customer_id')
            ->all();

        $excluded = array_unique(array_merge($hasMembership, $hasPack));

        // Find the cheapest pack this tenant offers — used for "would save $X" framing.
        $cheapestPack = TenantClassPackProduct::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->first();
        $perClassEstimate = $cheapestPack && $cheapestPack->credit_count > 0
            ? (int) round($cheapestPack->price_cents / $cheapestPack->credit_count)
            : null;

        $finalIds = array_diff($customerIds, $excluded);
        $customers = TenantCustomer::where('tenant_id', $tenantId)
            ->whereIn('id', $finalIds)
            ->get()
            ->keyBy('id');

        return collect($finalIds)
            ->take($limit)
            ->map(function ($cid) use ($rows, $customers, $cheapestPack, $perClassEstimate) {
                $stat = $rows->get($cid);
                $cust = $customers->get($cid);
                if (!$cust) return null;

                $count   = (int) $stat->dropin_count;
                $spent   = (int) $stat->total_spend_cents;
                $avgPaid = $count > 0 ? (int) round($spent / $count) : 0;
                $savings = null;
                if ($cheapestPack && $perClassEstimate && $avgPaid > $perClassEstimate) {
                    $savings = ($avgPaid - $perClassEstimate) * $count;
                }

                return [
                    'customer_id' => $cust->id,
                    'name'        => $cust->fullName(),
                    'email'       => $cust->email,
                    'fact'        => sprintf('%d drop-ins', $count)
                        . ($cheapestPack ? " · would save \${$this->dollars($savings)} with {$cheapestPack->name}" : ''),
                    'meta'        => '$' . $this->dollars($spent) . ' spent',
                    'cta'         => 'Grant pack',
                    'severity'    => 'green',
                ];
            })
            ->filter()
            ->values();
    }

    // ------------------------------------------------------------------
    // Panel: At-risk active members
    // Active capped membership, low usage. Or unlimited tier with low visits.
    // Threshold: 30% by default, configurable via $thresholdPct.
    // ------------------------------------------------------------------

    public function atRiskMembers(string $tenantId, int $thresholdPct = 30, int $limit = self::PANEL_LIMIT): Collection
    {
        $now = now();

        return TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('current_period_end', '>=', $now)
            ->with(['customer:id,first_name,last_name,email', 'product:id,name,type,monthly_limit,price_cents'])
            ->get()
            ->filter(function ($m) use ($thresholdPct, $now) {
                if (!$m->product || !$m->customer) return false;

                if ($m->product->type === 'unlimited') {
                    // Unlimited: at-risk = used very few classes total
                    // Threshold approximation: <2 visits in current period
                    return $m->classes_used_this_period < 2;
                }
                // Capped: usage% < threshold
                $limit = max(1, (int) $m->product->monthly_limit);
                $pct   = ($m->classes_used_this_period / $limit) * 100;
                return $pct < $thresholdPct;
            })
            ->sortBy(function ($m) {
                // Sort by period end ascending — most-urgent first
                return $m->current_period_end->timestamp;
            })
            ->take($limit)
            ->map(function ($m) use ($now) {
                $product = $m->product;
                $cust    = $m->customer;
                $daysLeft = max(0, $now->diffInDays($m->current_period_end, false));

                if ($product->type === 'unlimited') {
                    $fact = sprintf('%d visit%s · unlimited tier underused',
                        $m->classes_used_this_period,
                        $m->classes_used_this_period === 1 ? '' : 's');
                    $severity = $m->classes_used_this_period === 0 ? 'red' : 'amber';
                } else {
                    $fact = sprintf('%d / %d used · period ends in %d day%s',
                        $m->classes_used_this_period,
                        $product->monthly_limit,
                        $daysLeft,
                        $daysLeft === 1 ? '' : 's');
                    $severity = $m->classes_used_this_period === 0 ? 'red' : 'amber';
                }

                $tierLabel = $product->type === 'unlimited'
                    ? 'Unlimited'
                    : "{$product->monthly_limit}-class";

                return [
                    'customer_id' => $cust->id,
                    'name'        => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: 'Unnamed',
                    'email'       => $cust->email,
                    'fact'        => $fact,
                    'meta'        => "{$tierLabel} · \${$this->dollars($product->price_cents)}/mo",
                    'cta'         => 'Reach out',
                    'severity'    => $severity,
                ];
            })
            ->values();
    }

    // ------------------------------------------------------------------
    // Panel: Used-up packs
    // Packs with status='exhausted' and zero credits in last 60 days.
    // ------------------------------------------------------------------

    public function usedUpPacks(string $tenantId, int $limit = self::PANEL_LIMIT): Collection
    {
        $since = now()->subDays(self::USED_PACK_WINDOW_DAYS);

        return TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('status', 'exhausted')
            ->where('updated_at', '>=', $since)
            ->with(['customer:id,first_name,last_name,email', 'product:id,name,credit_count,expiry_days'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($p) {
                $cust = $p->customer;
                if (!$cust) return null;

                $daysToExhaust = $p->created_at->diffInDays($p->updated_at);
                $isHeavyUser = $daysToExhaust > 0 && $daysToExhaust <= 35;

                $fact = sprintf(
                    '%s used in %d days%s',
                    $p->product?->name ?? 'Pack',
                    $daysToExhaust,
                    $isHeavyUser ? ' · heavy user' : ''
                );

                return [
                    'customer_id' => $cust->id,
                    'name'        => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: 'Unnamed',
                    'email'       => $cust->email,
                    'fact'        => $fact,
                    'meta'        => $this->relativeDate($p->updated_at),
                    'cta'         => 'Grant pack',
                    'severity'    => $isHeavyUser ? 'green' : 'amber',
                ];
            })
            ->filter()
            ->values();
    }

    // ------------------------------------------------------------------
    // Panel: Recently cancelled memberships
    // Cancelled in last 30 days. Includes tenure for context.
    // ------------------------------------------------------------------

    public function recentlyCancelled(string $tenantId, int $limit = self::PANEL_LIMIT): Collection
    {
        $since = now()->subDays(self::CANCELLED_WINDOW_DAYS);

        return TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('status', 'cancelled')
            ->where('updated_at', '>=', $since)
            ->with(['customer:id,first_name,last_name,email', 'product:id,name,type,monthly_limit'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($m) {
                $cust = $m->customer;
                if (!$cust) return null;

                $tenureMonths = max(1, (int) round($m->created_at->diffInDays($m->updated_at) / 30));
                $tierLabel = $m->product?->type === 'unlimited'
                    ? 'Unlimited'
                    : ($m->product?->monthly_limit ? "{$m->product->monthly_limit}-class" : 'membership');

                return [
                    'customer_id' => $cust->id,
                    'name'        => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: 'Unnamed',
                    'email'       => $cust->email,
                    'fact'        => sprintf('Was on %s · %d mo. tenure', $tierLabel, $tenureMonths),
                    'meta'        => $this->relativeDate($m->updated_at),
                    'cta'         => 'Win back',
                    'severity'    => 'red',
                ];
            })
            ->filter()
            ->values();
    }

    // ------------------------------------------------------------------
    // Panel: Lapsed memberships
    // Expired or cancelled in last 90 days. Shows usage from last cycle.
    // ------------------------------------------------------------------

    public function lapsedMemberships(string $tenantId, int $limit = self::PANEL_LIMIT): Collection
    {
        $since = now()->subDays(self::LAPSED_WINDOW_DAYS);

        return TenantCustomerMembership::where('tenant_id', $tenantId)
            ->whereIn('status', ['expired', 'cancelled'])
            ->where('updated_at', '>=', $since)
            ->with(['customer:id,first_name,last_name,email', 'product:id,name,type,monthly_limit'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($m) {
                $cust = $m->customer;
                if (!$cust) return null;

                $tierLabel = $m->product?->type === 'unlimited'
                    ? 'Unlimited'
                    : ($m->product?->monthly_limit ? "{$m->product->monthly_limit}-class" : 'membership');

                $usageHint = '';
                if ($m->product?->type === 'unlimited') {
                    $usageHint = " · {$m->classes_used_this_period} visits last cycle";
                } else {
                    $limit = $m->product?->monthly_limit ?? 0;
                    if ($limit > 0) {
                        $pct = ($m->classes_used_this_period / $limit) * 100;
                        $usageHint = $pct >= 75
                            ? " · heavy user last cycle ({$m->classes_used_this_period}/{$limit} used)"
                            : " · light user ({$m->classes_used_this_period}/{$limit} used)";
                    }
                }

                $statusLabel = $m->status === 'cancelled' ? 'cancelled' : 'expired';

                return [
                    'customer_id' => $cust->id,
                    'name'        => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: 'Unnamed',
                    'email'       => $cust->email,
                    'fact'        => sprintf('%s %s%s', $tierLabel, $statusLabel, $usageHint),
                    'meta'        => $this->relativeDate($m->updated_at),
                    'cta'         => 'Grant membership',
                    'severity'    => 'amber',
                ];
            })
            ->filter()
            ->values();
    }

    // ------------------------------------------------------------------
    // Panel: Top earning products
    // Memberships and packs ranked by 30-day revenue. Includes active count
    // and lifetime sold for context.
    // ------------------------------------------------------------------

    public function topEarningProducts(string $tenantId): Collection
    {
        $monthAgo = now()->subDays(30);

        $memberships = TenantClassMembershipProduct::where('tenant_id', $tenantId)
            ->withCount([
                'memberships as active_count' => fn($q) => $q->where('status', 'active'),
                'memberships as lifetime_count',
                'memberships as recent_count' => fn($q) => $q->where('created_at', '>=', $monthAgo),
            ])
            ->get()
            ->map(function ($p) {
                return [
                    'kind'              => 'membership',
                    'name'              => $p->name,
                    'meta'              => '$' . $this->dollars($p->price_cents) . '/mo · membership',
                    'active'            => (int) $p->active_count,
                    'lifetime'          => (int) $p->lifetime_count,
                    // 30-day revenue: active memberships pay monthly, so monthly
                    // recurring estimate = active_count * price. New ones in
                    // the last 30 days have already been counted as active.
                    'revenue_cents'     => (int) $p->active_count * (int) $p->price_cents,
                ];
            });

        $packs = TenantClassPackProduct::where('tenant_id', $tenantId)
            ->withCount([
                'customerPacks as active_count' => fn($q) => $q->where('status', 'active'),
                'customerPacks as lifetime_count',
                'customerPacks as recent_count' => fn($q) => $q->where('created_at', '>=', $monthAgo),
            ])
            ->get()
            ->map(function ($p) {
                return [
                    'kind'              => 'pack',
                    'name'              => $p->name,
                    'meta'              => '$' . $this->dollars($p->price_cents) . ' · ' . $p->expiry_days . ' day expiry',
                    'active'            => (int) $p->active_count,
                    'lifetime'          => (int) $p->lifetime_count,
                    // 30-day revenue: count of new packs in last 30 days * price
                    'revenue_cents'     => (int) ($p->recent_count ?? 0) * (int) $p->price_cents,
                ];
            });

        $combined = $memberships->concat($packs)
            ->sortByDesc('revenue_cents')
            ->values();

        // Compute relative bar width based on top earner
        $maxRev = $combined->max('revenue_cents') ?: 1;
        return $combined->map(function ($row) use ($maxRev) {
            $row['revenue_pct'] = max(2, (int) round(($row['revenue_cents'] / $maxRev) * 100));
            return $row;
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function dollars(?int $cents): string
    {
        if (!$cents) return '0';
        $dollars = $cents / 100;
        return $dollars < 100 ? number_format($dollars, 0) : number_format($dollars);
    }

    private function relativeDate(Carbon $when): string
    {
        $now = now();
        $days = (int) round($when->diffInDays($now));
        if ($days === 0) return 'today';
        if ($days === 1) return 'yesterday';
        if ($days < 30) return "{$days} days ago";
        if ($days < 365) {
            $months = (int) round($days / 30);
            return $months === 1 ? '1 month ago' : "{$months} months ago";
        }
        return $when->format('M j, Y');
    }
}
