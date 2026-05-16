<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CustomersReportService
 *
 * Whole-database (NOT date-ranged) customer analytics for the
 * Reports → Customers tab. Three independent panels:
 *
 *   - missingContactInfo()  : customers with no phone number on file
 *   - lapsedCustomers()     : customers whose most-recent appointment
 *                              was more than 180 days ago (90–180d = at-risk)
 *   - highestLtv()          : top customers by lifetime value, where LTV =
 *                              sum of delivered-appointment totals + paid sales,
 *                              minus refund sales. Snapshotted values, not
 *                              live-recomputed prices.
 *
 * All methods are tenant-scoped through the constructor-injected tenant.
 *
 * Performance note: at 10,000+ customers per tenant, the lapsedCustomers
 * query (which left-joins to the most-recent-appointment-per-customer)
 * may benefit from an index on tenant_appointments.(customer_id, appointment_date).
 * The current schema indexes (tenant_id, appointment_date) only. Not adding
 * an index in this patch — defer until data volume warrants it.
 */
class CustomersReportService
{
    /**
     * Status values that count as a real visit (drove revenue, used capacity).
     * Mirrors ReportsDataService::DELIVERED_STATUSES.
     */
    private const DELIVERED_STATUSES = ['in_progress', 'completed', 'shipped', 'closed'];

    private const LAPSED_DAYS = 180;
    private const AT_RISK_DAYS = 90;

    private const LIST_LIMIT_LAPSED = 100;
    private const LIST_LIMIT_LTV = 50;
    private const LIST_LIMIT_MISSING = 100;

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Customers missing usable phone contact.
     *
     * Schema: email is NOT NULL (unique per tenant); phone is nullable.
     * So "missing contact info" = phone is null or empty string.
     *
     * Returns:
     *   total           : count of all customers missing phone
     *   reachable_count : number of total customers (for context %)
     *   list            : top N customers missing phone, newest first
     */
    public function missingContactInfo(bool $aggregatesOnly = false): array
    {
        $totalCustomers = TenantCustomer::where('tenant_id', $this->tenant->id)->count();

        $missingQuery = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where(function ($q) {
                $q->whereNull('phone')->orWhere('phone', '');
            });

        $totalMissing = (clone $missingQuery)->count();

        $list = $aggregatesOnly ? [] : $missingQuery
            ->orderByDesc('created_at')
            ->limit(self::LIST_LIMIT_MISSING)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at'])
            ->map(fn($c) => [
                'id'       => $c->id,
                'name'     => trim($c->first_name . ' ' . $c->last_name),
                'email'    => $c->email,
                'added_at' => $c->created_at,
            ])
            ->all();

        return [
            'total_missing'   => $totalMissing,
            'total_customers' => $totalCustomers,
            'percent_missing' => $totalCustomers > 0
                ? round(($totalMissing / $totalCustomers) * 100, 1)
                : 0.0,
            'list'            => $list,
            'list_limit'      => self::LIST_LIMIT_MISSING,
        ];
    }

    /**
     * Customers who used to come in and haven't lately.
     *
     * Definition (locked 2026-05-16):
     *   - Lapsed:  no delivered appointment in the last 180 days,
     *              but HAS at least one delivered appointment ever.
     *   - At-risk: most-recent delivered appointment is 90–180 days ago.
     *
     * Customers with zero delivered appointments are excluded — they're
     * "never engaged," not "lapsed."
     */
    public function lapsedCustomers(bool $aggregatesOnly = false): array
    {
        $now = $this->tenant->localToday();
        $lapsedCutoff  = $now->copy()->subDays(self::LAPSED_DAYS);
        $atRiskCutoff  = $now->copy()->subDays(self::AT_RISK_DAYS);

        // Build a subquery: most recent delivered appointment per customer.
        $deliveredStatusesSql = "'" . implode("','", self::DELIVERED_STATUSES) . "'";
        $latest = DB::table('tenant_appointments')
            ->selectRaw('customer_id, MAX(appointment_date) as last_appt')
            ->where('tenant_id', $this->tenant->id)
            ->whereRaw("status IN ($deliveredStatusesSql)")
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        $base = DB::table('tenant_customers as c')
            ->joinSub($latest, 'l', fn($j) => $j->on('l.customer_id', '=', 'c.id'))
            ->where('c.tenant_id', $this->tenant->id);

        $lapsedCount = (clone $base)
            ->whereDate('l.last_appt', '<', $lapsedCutoff->toDateString())
            ->count();

        $atRiskCount = (clone $base)
            ->whereDate('l.last_appt', '>=', $lapsedCutoff->toDateString())
            ->whereDate('l.last_appt', '<',  $atRiskCutoff->toDateString())
            ->count();

        $list = $aggregatesOnly ? [] : (clone $base)
            ->whereDate('l.last_appt', '<', $lapsedCutoff->toDateString())
            ->orderBy('l.last_appt', 'asc') // longest-lapsed first — most urgent
            ->limit(self::LIST_LIMIT_LAPSED)
            ->select('c.id', 'c.first_name', 'c.last_name', 'c.email', 'c.phone', 'l.last_appt')
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'name'       => trim($r->first_name . ' ' . $r->last_name),
                'email'      => $r->email,
                'phone'      => $r->phone,
                'last_visit' => $r->last_appt,
                'days_since' => Carbon::parse($r->last_appt)->diffInDays($now),
            ])
            ->all();

        return [
            'lapsed_count'    => $lapsedCount,
            'at_risk_count'   => $atRiskCount,
            'lapsed_days'     => self::LAPSED_DAYS,
            'at_risk_days'    => self::AT_RISK_DAYS,
            'list'            => $list,
            'list_limit'      => self::LIST_LIMIT_LAPSED,
        ];
    }

    /**
     * Top customers by lifetime value.
     *
     * LTV scope (locked 2026-05-16):
     *   Sum of snapshotted line-item totals from:
     *     - tenant_appointments where status IN (delivered set)
     *     - tenant_sales where payment_status = 'paid' AND refund_of_sale_id IS NULL
     *   Less refund sales (rows where refund_of_sale_id IS NOT NULL).
     *
     * Snapshot values from the rows, not live-recomputed from current prices.
     */
    public function highestLtv(bool $aggregatesOnly = false): array
    {
        if ($aggregatesOnly) {
            return [
                'list'       => [],
                'list_limit' => self::LIST_LIMIT_LTV,
                'total_ltv'  => 0,
            ];
        }

        // Appointment revenue per customer (delivered + paid only).
        $apptRevenue = DB::table('tenant_appointments')
            ->selectRaw('customer_id, SUM(total_cents) as cents')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        // Sale revenue per customer (paid sales, refunds excluded from positive
        // side and subtracted from negative side).
        $salePositive = DB::table('tenant_sales')
            ->selectRaw('customer_id, SUM(total_cents) as cents')
            ->where('tenant_id', $this->tenant->id)
            ->where('payment_status', 'paid')
            ->whereNull('refund_of_sale_id')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        $saleRefund = DB::table('tenant_sales')
            ->selectRaw('customer_id, SUM(total_cents) as cents')
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('refund_of_sale_id')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        // Pull all three streams and merge in PHP. At <100K customers per
        // tenant, three indexed-aggregate queries are cheaper than one
        // many-way UNION with joins, and easier to reason about.
        $apptMap = $apptRevenue->get()->keyBy('customer_id');
        $salePosMap = $salePositive->get()->keyBy('customer_id');
        $saleRefMap = $saleRefund->get()->keyBy('customer_id');

        // Build the LTV-by-customer dictionary.
        $ltv = [];
        foreach ($apptMap as $cid => $row)    $ltv[$cid] = ($ltv[$cid] ?? 0) + (int)$row->cents;
        foreach ($salePosMap as $cid => $row) $ltv[$cid] = ($ltv[$cid] ?? 0) + (int)$row->cents;
        foreach ($saleRefMap as $cid => $row) $ltv[$cid] = ($ltv[$cid] ?? 0) - (int)$row->cents;

        // Filter out customers with non-positive LTV (refund-only edge case).
        $ltv = array_filter($ltv, fn($cents) => $cents > 0);

        // Sort desc, take top N.
        arsort($ltv);
        $topIds = array_slice(array_keys($ltv), 0, self::LIST_LIMIT_LTV, true);

        if (empty($topIds)) {
            return [
                'list'       => [],
                'list_limit' => self::LIST_LIMIT_LTV,
                'total_ltv'  => 0,
            ];
        }

        // Hydrate the top N with customer details.
        $customers = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereIn('id', $topIds)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'created_at'])
            ->keyBy('id');

        // Visit-count map for context (one cheap query, same scope).
        $visitMap = DB::table('tenant_appointments')
            ->selectRaw('customer_id, COUNT(*) as visits')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereIn('customer_id', $topIds)
            ->groupBy('customer_id')
            ->pluck('visits', 'customer_id');

        $list = [];
        foreach ($topIds as $cid) {
            $c = $customers->get($cid);
            if (!$c) continue;
            $list[] = [
                'id'             => $c->id,
                'name'           => trim($c->first_name . ' ' . $c->last_name),
                'email'          => $c->email,
                'phone'          => $c->phone,
                'ltv_cents'      => $ltv[$cid],
                'visits'         => (int) ($visitMap[$cid] ?? 0),
                'customer_since' => $c->created_at,
            ];
        }

        return [
            'list'       => $list,
            'list_limit' => self::LIST_LIMIT_LTV,
            'total_ltv'  => array_sum($ltv),
        ];
    }
}
