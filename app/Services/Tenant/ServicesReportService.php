<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ServicesReportService
 *
 * Date-ranged service depth analytics for the Reports → Services tab.
 *
 * Panels:
 *   - throughput()       : appointments delivered per day across the range
 *   - serviceMix()       : top services by revenue + count
 *   - partsAttach()      : % of appointments with parts; parts$ / service$
 *   - comebacks()        : same customer returning within COMEBACK_WINDOW days
 *   - productionByResource(): appointment count + revenue per resource
 *
 * Stub panels (return is_stub=true; not yet buildable without schema work):
 *   - mechanicProductivity(): requires assigned_staff_id on appointments
 *   - estimateAccuracy()    : requires an "estimate" entity with quoted$ vs actual$
 *
 * All methods support $aggregatesOnly for the locked-state UX. When true,
 * returns aggregate counts but skips heavy list/chart queries.
 */
class ServicesReportService
{
    /**
     * Statuses that count as "the work was actually done" — same set used
     * across the reports services so numbers reconcile.
     */
    private const DELIVERED_STATUSES = ['in_progress', 'completed', 'shipped', 'closed'];

    // PRODUCT DECISION: comeback window. If a customer returns within 30 days
    // after a delivered appointment, count it as a comeback. 30d is the
    // industry-default "did the fix not hold" window. Tunable.
    private const COMEBACK_WINDOW_DAYS = 30;

    private const LIST_LIMIT = 50;

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Throughput: delivered appointments per day across the range.
     * Returns a sparkline-friendly daily array plus headline totals.
     */
    public function throughput(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $rangeDays = $from->diffInDays($to) + 1;

        $totalDelivered = DB::table('tenant_appointments')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $avgPerDay = $rangeDays > 0
            ? round($totalDelivered / $rangeDays, 1)
            : 0;

        if ($aggregatesOnly) {
            return [
                'total_delivered' => $totalDelivered,
                'avg_per_day'     => $avgPerDay,
                'range_days'      => $rangeDays,
                'daily'           => [],
            ];
        }

        // PRODUCT DECISION: throughput grouped by appointment_date (the day
        // service was scheduled), not by completed_at. We don't reliably
        // track completed_at on every appointment, and the operator
        // typically thinks of work in terms of "what day was it on the
        // calendar."
        $rows = DB::table('tenant_appointments')
            ->selectRaw('appointment_date, COUNT(*) as count')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('appointment_date')
            ->get()
            ->keyBy('appointment_date');

        $daily = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $k = $d->toDateString();
            $daily[] = [
                'date'  => $k,
                'count' => (int) ($rows->get($k)->count ?? 0),
            ];
        }

        return [
            'total_delivered' => $totalDelivered,
            'avg_per_day'     => $avgPerDay,
            'range_days'      => $rangeDays,
            'daily'           => $daily,
        ];
    }

    /**
     * Service mix: top services by revenue across the range.
     * Pulls from tenant_appointment_items (the snapshot-on-write line items).
     */
    public function serviceMix(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        // PRODUCT DECISION: service mix counts all line items on delivered
        // appointments, regardless of payment_status. The "did we do this
        // work" question is separate from "did we get paid." Use payment_status
        // filter on the revenue panels (Money tab) if you want paid-only.
        $base = DB::table('tenant_appointment_items as ai')
            ->join('tenant_appointments as a', 'a.id', '=', 'ai.appointment_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()]);

        $totalLineItems = (clone $base)->count();
        $totalRevenue = (clone $base)->sum('ai.price_cents');

        if ($aggregatesOnly) {
            return [
                'total_line_items' => $totalLineItems,
                'total_revenue'    => (int) $totalRevenue,
                'top'              => [],
            ];
        }

        $top = (clone $base)
            ->selectRaw('ai.item_name_snapshot as name,
                         COUNT(*) as count,
                         SUM(ai.price_cents) as cents')
            ->groupBy('ai.item_name_snapshot')
            ->orderByDesc('cents')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn($r) => [
                'name'   => $r->name,
                'count'  => (int) $r->count,
                'cents'  => (int) $r->cents,
            ])
            ->all();

        return [
            'total_line_items' => $totalLineItems,
            'total_revenue'    => (int) $totalRevenue,
            'top'              => $top,
        ];
    }

    /**
     * Parts attach: how often parts get added to appointments,
     * and how much parts revenue compares to service revenue.
     */
    public function partsAttach(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        // Total delivered appointments in range
        $deliveredAppts = DB::table('tenant_appointments')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()]);

        $totalAppts = (clone $deliveredAppts)->count();

        // Appointments with at least one part
        $apptsWithParts = (clone $deliveredAppts)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('tenant_appointment_parts')
                  ->whereColumn('tenant_appointment_parts.appointment_id', 'tenant_appointments.id');
            })
            ->count();

        $attachPct = $totalAppts > 0
            ? round(($apptsWithParts / $totalAppts) * 100, 1)
            : 0.0;

        // Parts revenue + cost — snapshotted from tenant_appointment_parts
        $partsRow = DB::table('tenant_appointment_parts as p')
            ->join('tenant_appointments as a', 'a.id', '=', 'p.appointment_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                SUM(COALESCE(p.unit_price_cents_override, p.unit_price_cents) * p.quantity) as revenue_cents,
                SUM(COALESCE(p.cost_cents_at_time, 0) * p.quantity) as cost_cents
            ')
            ->first();

        $partsRevenue = (int) ($partsRow->revenue_cents ?? 0);
        $partsCost = (int) ($partsRow->cost_cents ?? 0);
        $partsMargin = $partsRevenue - $partsCost;

        // Service revenue (line items) for the same window — used in the
        // parts$/service$ ratio.
        $serviceRevenue = (int) DB::table('tenant_appointment_items as ai')
            ->join('tenant_appointments as a', 'a.id', '=', 'ai.appointment_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->sum('ai.price_cents');

        $partsToServiceRatio = $serviceRevenue > 0
            ? round(($partsRevenue / $serviceRevenue) * 100, 1)
            : 0.0;

        return [
            'total_appts'              => $totalAppts,
            'appts_with_parts'         => $apptsWithParts,
            'attach_pct'               => $attachPct,
            'parts_revenue_cents'      => $partsRevenue,
            'parts_cost_cents'         => $partsCost,
            'parts_margin_cents'       => $partsMargin,
            'service_revenue_cents'    => $serviceRevenue,
            'parts_to_service_pct'     => $partsToServiceRatio,
        ];
    }

    /**
     * Comebacks: customers who returned within COMEBACK_WINDOW_DAYS
     * after a delivered appointment, in the range.
     *
     * Definition: for any delivered appointment IN the range, count it as
     * a "first" if the SAME customer had ANOTHER delivered appointment
     * within COMEBACK_WINDOW_DAYS after it (regardless of whether the
     * comeback is itself in range).
     */
    public function comebacks(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        // Pull (customer_id, appointment_date) for all delivered appointments
        // in a window that extends past the range end by COMEBACK_WINDOW_DAYS
        // so we can detect comebacks that happen just outside the range.
        $lookEnd = $to->copy()->addDays(self::COMEBACK_WINDOW_DAYS);

        $rows = DB::table('tenant_appointments')
            ->select('id', 'customer_id', 'appointment_date')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereNotNull('customer_id')
            ->whereBetween('appointment_date', [$from->toDateString(), $lookEnd->toDateString()])
            ->orderBy('customer_id')
            ->orderBy('appointment_date')
            ->get();

        // Group by customer and walk: for each delivered appointment in the
        // range, does the next delivered appointment for that customer fall
        // within COMEBACK_WINDOW_DAYS?
        $byCust = $rows->groupBy('customer_id');
        $firstsInRange = 0;
        $comebacksHit = 0;

        foreach ($byCust as $custRows) {
            $list = $custRows->values();
            for ($i = 0; $i < count($list); $i++) {
                $appt = $list[$i];
                $apptDate = Carbon::parse($appt->appointment_date);

                // Only consider appointments IN the actual range as "firsts"
                if ($apptDate->lt($from) || $apptDate->gt($to)) continue;

                $firstsInRange++;

                // Is there a next appointment for this customer within window?
                if ($i + 1 < count($list)) {
                    $nextDate = Carbon::parse($list[$i + 1]->appointment_date);
                    $diff = $apptDate->diffInDays($nextDate);
                    if ($diff > 0 && $diff <= self::COMEBACK_WINDOW_DAYS) {
                        $comebacksHit++;
                    }
                }
            }
        }

        $comebackRate = $firstsInRange > 0
            ? round(($comebacksHit / $firstsInRange) * 100, 1)
            : 0.0;

        return [
            'window_days'      => self::COMEBACK_WINDOW_DAYS,
            'firsts_in_range'  => $firstsInRange,
            'comebacks'        => $comebacksHit,
            'comeback_rate'    => $comebackRate,
        ];
    }

    /**
     * Production by resource: appointment count + revenue per resource
     * (work station, chair, lift, bay, etc.) for the range.
     */
    public function productionByResource(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $totalAppts = DB::table('tenant_appointments')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('resource_id')
            ->count();

        if ($aggregatesOnly) {
            return [
                'total_appts' => $totalAppts,
                'list'        => [],
            ];
        }

        $list = DB::table('tenant_appointments as a')
            ->leftJoin('tenant_resources as r', 'r.id', '=', 'a.resource_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('a.resource_id')
            ->selectRaw('
                a.resource_id as resource_id,
                r.name as resource_name,
                COUNT(*) as appt_count,
                SUM(a.total_cents) as revenue_cents
            ')
            ->groupBy('a.resource_id', 'r.name')
            ->orderByDesc('revenue_cents')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn($r) => [
                'resource_id'   => $r->resource_id,
                'resource_name' => $r->resource_name ?? '(deleted resource)',
                'appt_count'    => (int) $r->appt_count,
                'revenue_cents' => (int) $r->revenue_cents,
            ])
            ->all();

        return [
            'total_appts' => $totalAppts,
            'list'        => $list,
        ];
    }

    /**
     * Mechanic productivity — STUB.
     * Requires assigned_staff_id on tenant_appointments. That column
     * doesn't exist yet (pre-launch feature per the roadmap).
     */
    public function mechanicProductivity(): array
    {
        return [
            'is_stub'  => true,
            'reason'   => 'Awaiting staff-assignment-on-appointment schema. Coming soon.',
        ];
    }

    /**
     * Estimate accuracy — STUB.
     * Requires an "estimate" entity (quoted total vs actual total). The
     * current work-order pattern doesn't capture quoted-vs-actual variance.
     */
    public function estimateAccuracy(): array
    {
        return [
            'is_stub' => true,
            'reason'  => 'Awaiting estimate / quote-vs-actual data model. Coming soon.',
        ];
    }
}
