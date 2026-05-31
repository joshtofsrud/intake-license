<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportsDataService
 *
 * Phase 3: single global date range drives every zone. Each zone method
 * takes Carbon $from and Carbon $to directly. The controller is responsible
 * for parsing 'today' | 'week' | 'month' | 'custom' into a date pair.
 *
 * Capacity zone is the one exception — it falls back to the last 14 days
 * when the requested range is shorter than 7 days, since the day-of-week ×
 * hour heatmap needs density to be readable.
 */
class ReportsDataService
{
    private const DELIVERED_STATUSES = ['completed', 'closed'];
    private const CANCELLED_STATUSES = ['cancelled'];
    private const REFUNDED_STATUSES  = ['refunded'];

    public function __construct(private readonly Tenant $tenant) {}

    /** Top KPI row — always shows today's snapshot regardless of range. */
    public function topKpis(): array
    {
        $today = $this->tenant->localToday();
        $lastWeekSameDay = $today->copy()->subWeek();

        $todayRevenue = $this->revenueForDate($today);
        $lastWkRevenue = $this->revenueForDate($lastWeekSameDay);

        $todayBookings = $this->bookingCountForDate($today);
        $lastWkBookings = $this->bookingCountForDate($lastWeekSameDay);

        $todayCapacity = $this->capacityForDate($today);

        $thirtyDayNoShowRate = $this->noShowRateForRange(
            $today->copy()->subDays(29), $today
        );
        $todayNoShowCount = $this->noShowCountForDate($today);

        $todayNewCust = $this->newCustomerCountForDate($today);
        $lastWkNewCust = $this->newCustomerCountForDate($lastWeekSameDay);

        return [
            [
                'label'         => 'Revenue today',
                'value_dollars' => $todayRevenue / 100,
                'delta'         => $this->deltaPercent($todayRevenue, $lastWkRevenue),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'money',
            ],
            [
                'label'         => 'Bookings',
                'value_int'     => $todayBookings,
                'capacity'      => $todayCapacity,
                'delta'         => $this->deltaCount($todayBookings, $lastWkBookings),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'count',
            ],
            [
                'label'         => 'No-show rate',
                'value_int'     => round($thirtyDayNoShowRate * 100),
                'detail'        => $todayNoShowCount . ' today',
                'period_label'  => 'trailing 30 days',
                'format'        => 'percent',
            ],
            [
                'label'         => 'New customers today',
                'value_int'     => $todayNewCust,
                'delta'         => $this->deltaCount($todayNewCust, $lastWkNewCust),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'count',
            ],
        ];
    }

    /** Zone 1: Revenue. */
    public function zoneRevenue(Carbon $from, Carbon $to): array
    {
        // MARKER-PATCH-184 — revenue now reads the SALE PAYMENT LEDGER
        // ("Payments Received", cash-basis), not appointment totals. Payments
        // are signed (refunds negative) so the ledger nets correctly. recorded_at
        // is stored UTC; we bound by the tenant-local day window converted to UTC,
        // and bucket the series by recorded_at shifted into the tenant timezone.
        $tz = $this->tenant->timezone();
        $isSingleDay = $from->isSameDay($to);

        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();

        // Offset (seconds) from UTC to tenant-local, for SQL bucketing.
        $offsetSec = Carbon::now($tz)->utcOffset() * 60;

        $base = DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$winStart, $winEnd]);

        $totalCents = (int) (clone $base)->sum('amount_cents');

        $series = [];
        if ($isSingleDay) {
            $hourly = (clone $base)
                ->selectRaw("HOUR(DATE_ADD(recorded_at, INTERVAL ? SECOND)) as hour, SUM(amount_cents) as cents, COUNT(*) as n", [$offsetSec])
                ->groupBy('hour')
                ->get()
                ->keyBy('hour');
            for ($h = 8; $h <= 18; $h++) {
                $row = $hourly->get($h);
                $series[] = [
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        } else {
            $daily = (clone $base)
                ->selectRaw("DATE(DATE_ADD(recorded_at, INTERVAL ? SECOND)) as d, SUM(amount_cents) as cents, COUNT(*) as n", [$offsetSec])
                ->groupBy('d')
                ->get()
                ->keyBy('d');
            $days = $from->diffInDays($to);
            $labelFmt = $days <= 7 ? 'D' : ($days <= 31 ? 'j' : 'M j');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $row = $daily->get($d->toDateString());
                $series[] = [
                    'label' => $d->format($labelFmt),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        }

        $bestBucket = collect($series)->sortByDesc('cents')->first();

        // Revenue by service: composition of the SALES that received payment in
        // this window, by line-item name. Sums positive (non-refund) payments'
        // sales only, grouping the sale's line items by name_snapshot. This keeps
        // the breakdown aligned with the cash-basis headline.
        $paidSaleIds = (clone $base)
            ->where('amount_cents', '>', 0)
            ->distinct()
            ->pluck('sale_id');

        $byService = [];
        if ($paidSaleIds->isNotEmpty()) {
            $byService = DB::table('tenant_sale_items')
                ->where('tenant_id', $this->tenant->id)
                ->whereIn('sale_id', $paidSaleIds)
                ->selectRaw('name_snapshot as name, SUM(line_total_cents) as cents, COUNT(DISTINCT sale_id) as bookings')
                ->groupBy('name')
                ->orderByDesc('cents')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'name'     => $r->name,
                    'cents'    => (int) $r->cents,
                    'bookings' => (int) $r->bookings,
                    'pct'      => $totalCents > 0 ? round(($r->cents / $totalCents) * 100) : 0,
                ])
                ->all();
        }

        return [
            'total_cents'   => $totalCents,
            'best_bucket'   => $bestBucket && $bestBucket['cents'] > 0
                ? ['label' => $bestBucket['label'], 'cents' => $bestBucket['cents']]
                : null,
            'series'        => $series,
            'series_kind'   => $isSingleDay ? 'hourly' : 'daily',
            'by_service'    => $byService,
        ];
    }
    public function zoneBookings(Carbon $from, Carbon $to): array
    {
        $isSingleDay = $from->isSameDay($to);

        $confirmed = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();

        $cancelled = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::CANCELLED_STATUSES)
            ->count();

        $noShows = $this->noShowCountForRange($from, $to);

        $walkins = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('DATE(created_at) = appointment_date')
            ->count();

        // Hourly bars for single day, daily for ranges
        $timeline = [];
        if ($isSingleDay) {
            $hourly = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->where('appointment_date', $from->toDateString())
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw("HOUR(appointment_time) as hour, COUNT(*) as n")
                ->groupBy('hour')
                ->pluck('n', 'hour')
                ->toArray();
            for ($h = 8; $h <= 18; $h++) {
                $timeline[] = [
                    'date'  => $from->toDateString(),
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'count' => (int) ($hourly[$h] ?? 0),
                ];
            }
        } else {
            $daily = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw('appointment_date as d, COUNT(*) as n')
                ->groupBy('d')
                ->pluck('n', 'd')
                ->toArray();
            $days = $from->diffInDays($to);
            $labelFmt = $days <= 7 ? 'D' : ($days <= 31 ? 'j' : 'M j');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $timeline[] = [
                    'date'  => $d->toDateString(),
                    'label' => $d->format($labelFmt),
                    'count' => (int) ($daily[$d->toDateString()] ?? 0),
                ];
            }
        }

        return [
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'no_shows'  => $noShows,
            'walkins'   => $walkins,
            'timeline'  => $timeline,
        ];
    }

    /** Zone 3: Customers + retention. */
    public function zoneCustomers(Carbon $from, Carbon $to): array
    {
        $rangeAppts = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->select('appointment_date', 'customer_id')
            ->get()
            ->groupBy(fn($r) => $r->appointment_date);

        $newCustIds = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from->toDateString() . ' 00:00:00', $to->toDateString() . ' 23:59:59'])
            ->pluck('id')
            ->all();
        $newSet = array_flip($newCustIds);

        $daily = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $newCount = 0; $returningCount = 0;
            foreach ($rangeAppts->get($key, collect()) as $row) {
                if (isset($newSet[$row->customer_id])) $newCount++;
                else $returningCount++;
            }
            $daily[] = [
                'date'      => $key,
                'new'       => $newCount,
                'returning' => $returningCount,
            ];
        }

        // MARKER-PATCH-184C — top customers by SPEND now read the sale payment
        // ledger (payments received in window), attributed via the sale's
        // customer. "visits" = distinct sales paid in the window. recorded_at is
        // UTC; bound by the tenant-local window converted to UTC.
        $tz = $this->tenant->timezone();
        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();
        $topCustomers = TenantCustomer::where('tenant_customers.tenant_id', $this->tenant->id)
            ->join('tenant_sales as ts', 'ts.customer_id', '=', 'tenant_customers.id')
            ->join('tenant_sale_payments as tsp', function ($j) use ($winStart, $winEnd) {
                $j->on('tsp.sale_id', '=', 'ts.id')
                  ->whereBetween('tsp.recorded_at', [$winStart, $winEnd]);
            })
            ->selectRaw('tenant_customers.id, tenant_customers.first_name, tenant_customers.last_name, tenant_customers.created_at, SUM(tsp.amount_cents) as cents, COUNT(DISTINCT ts.id) as visits')
            ->groupBy('tenant_customers.id', 'tenant_customers.first_name', 'tenant_customers.last_name', 'tenant_customers.created_at')
            ->orderByDesc('cents')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'name'             => trim($r->first_name . ' ' . $r->last_name),
                'cents'            => (int) $r->cents,
                'visits'           => (int) $r->visits,
                'is_new_in_period' => Carbon::parse($r->created_at)->between($from, $to->copy()->endOfDay()),
            ])
            ->all();

        return [
            'daily'         => $daily,
            'top_customers' => $topCustomers,
        ];
    }

    /** Zone 4: Service popularity. */
    public function zoneServices(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('tenant_appointments as ta')
            ->where('ta.tenant_id', $this->tenant->id)
            ->whereBetween('ta.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('ta.status', self::DELIVERED_STATUSES)
            ->where('ta.payment_status', 'paid')
            ->join('tenant_appointment_items as tai', 'tai.appointment_id', '=', 'ta.id')
            ->selectRaw('tai.item_name_snapshot as name, COUNT(DISTINCT ta.id) as bookings, SUM(COALESCE(tai.price_cents_override, tai.price_cents)) as cents')
            ->groupBy('name')
            ->orderByDesc('cents')
            ->limit(10)
            ->get();

        $maxCents = $rows->max('cents') ?: 1;

        return [
            'services' => $rows->map(fn($r) => [
                'name'      => $r->name,
                'bookings'  => (int) $r->bookings,
                'cents'     => (int) $r->cents,
                'bar_pct'   => round(($r->cents / $maxCents) * 100),
            ])->all(),
        ];
    }

    /** Zone 5: Staff utilization. */
    public function zoneStaff(Carbon $from, Carbon $to): array
    {
        // Real available minutes: sum each day's actual open-to-close window
        // from tenant_capacity_rules (defaults + overrides). Days the shop is
        // closed contribute zero; days with no rule fall back to 8h.
        $availableMinutes = $this->openMinutesForRange($from, $to);

        $resources = TenantResource::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $bookedMinutes = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('resource_id, SUM(total_duration_minutes) as mins, COUNT(*) as n')
            ->groupBy('resource_id')
            ->get()
            ->keyBy('resource_id');

        // MARKER-PATCH-184D — per-resource revenue from the sale payment ledger
        // (payments received in window), attributed via payment -> sale ->
        // appointment -> resource_id. Walk-in retail sales (no appointment) carry
        // no resource and are correctly excluded from per-staff revenue.
        $tzStaff = $this->tenant->timezone();
        $revWinStart = $from->copy()->setTimezone($tzStaff)->startOfDay()->utc();
        $revWinEnd   = $to->copy()->setTimezone($tzStaff)->endOfDay()->utc();
        $revenue = DB::table('tenant_sale_payments as tsp')
            ->where('tsp.tenant_id', $this->tenant->id)
            ->whereBetween('tsp.recorded_at', [$revWinStart, $revWinEnd])
            ->join('tenant_sales as ts', 'ts.id', '=', 'tsp.sale_id')
            ->join('tenant_appointments as ta', 'ta.id', '=', 'ts.appointment_id')
            ->selectRaw('ta.resource_id as resource_id, SUM(tsp.amount_cents) as cents')
            ->groupBy('ta.resource_id')
            ->pluck('cents', 'resource_id')
            ->toArray();

        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());

        $noShowsByResource = [];
        $totalByResource = [];
        if ($from->toDateString() <= $effectiveTo) {
            $noShowsByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
                ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw('resource_id, COUNT(*) as n')
                ->groupBy('resource_id')
                ->pluck('n', 'resource_id')
                ->toArray();

            $totalByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
                ->selectRaw('resource_id, COUNT(*) as n')
                ->groupBy('resource_id')
                ->pluck('n', 'resource_id')
                ->toArray();
        }

        $cards = $resources->map(function ($r) use ($bookedMinutes, $revenue, $noShowsByResource, $totalByResource, $availableMinutes) {
            $row = $bookedMinutes->get($r->id);
            $booked = (int) ($row->mins ?? 0);
            $appts = (int) ($row->n ?? 0);
            $rev = (int) ($revenue[$r->id] ?? 0);
            $noShows = (int) ($noShowsByResource[$r->id] ?? 0);
            $totalCount = (int) ($totalByResource[$r->id] ?? 0);

            $utilization = $availableMinutes > 0
                ? min(100, round(($booked / $availableMinutes) * 100))
                : 0;

            $health = match (true) {
                $utilization > 85 => 'overloaded',
                $utilization >= 50 => 'healthy',
                default => 'underused',
            };

            $noShowRate = $totalCount > 0 ? round(($noShows / $totalCount) * 100) : 0;

            return [
                'name'         => $r->name,
                'subtitle'     => $r->subtitle ?: 'Staff',
                'color_hex'    => $r->color_hex,
                'utilization'  => $utilization,
                'booked_hrs'   => round($booked / 60, 1),
                'available_hrs'=> round($availableMinutes / 60, 1),
                'appts'        => $appts,
                'revenue_cents'=> $rev,
                'no_show_rate' => $noShowRate,
                'health'       => $health,
            ];
        })->all();

        return ['cards' => $cards];
    }

    /**
     * Zone 6: Capacity heatmap.
     * Falls back to last 14 days if the requested range is shorter than 7
     * days — heatmap density needs that much data to be readable.
     */
    public function zoneCapacity(Carbon $from, Carbon $to): array
    {
        $rangeDays = $from->diffInDays($to) + 1;
        $usedFallback = false;
        if ($rangeDays < 7) {
            $usedFallback = true;
            $today = $this->tenant->localToday();
            $from = $today->copy()->subDays(13);
            $to = $today->copy();
        }

        $cells = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw("DAYOFWEEK(appointment_date) - 1 as dow, HOUR(appointment_time) as hour, COUNT(*) as n")
            ->groupBy('dow', 'hour')
            ->get();

        $maxCellCount = $cells->max('n') ?: 1;

        $grid = [];
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        foreach ($days as $dowIdx => $dowLabel) {
            $row = ['label' => $dowLabel, 'cells' => []];
            for ($h = 8; $h <= 21; $h++) {
                $cell = $cells->first(fn($c) => $c->dow == $dowIdx && $c->hour == $h);
                $count = $cell ? (int) $cell->n : 0;
                $fill = match (true) {
                    $count == 0           => 0,
                    $count <= $maxCellCount * 0.15 => 1,
                    $count <= $maxCellCount * 0.35 => 2,
                    $count <= $maxCellCount * 0.55 => 3,
                    $count <= $maxCellCount * 0.80 => 4,
                    default               => 5,
                };
                $row['cells'][] = [
                    'hour'  => $h,
                    'count' => $count,
                    'fill'  => $fill,
                ];
            }
            $grid[] = $row;
        }

        return [
            'grid'          => $grid,
            'used_fallback' => $usedFallback,
            'fallback_label' => $usedFallback
                ? $from->format('M j') . ' – ' . $to->format('M j')
                : null,
            'hour_labels'   => array_map(
                fn($h) => Carbon::createFromTime($h)->format('ga'),
                range(8, 21)
            ),
        ];
    }

    // ---------- helpers ----------

    /**
     * Sum of "shop is open" minutes for every day in the range.
     * Override rules win over default rules for a specific date. If a day
     * has no rule at all, falls back to 8 hours so a brand-new tenant
     * doesn't show 100%-of-zero utilization.
     */
    private function openMinutesForRange(Carbon $from, Carbon $to): int
    {
        $defaults = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'default')
            ->whereNull('specific_date')
            ->get(['day_of_week', 'is_closed', 'open_time', 'close_time'])
            ->keyBy('day_of_week');

        $overrides = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$from->toDateString(), $to->toDateString()])
            ->get(['specific_date', 'is_closed', 'open_time', 'close_time'])
            ->keyBy(fn($r) => $r->specific_date);

        $totalMinutes = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $rule = $overrides->get($d->toDateString()) ?? $defaults->get($d->dayOfWeek);

            if (!$rule) {
                $totalMinutes += 8 * 60;  // fallback when no rule exists
                continue;
            }
            if (!empty($rule->is_closed)) continue;  // closed = 0 minutes
            if (empty($rule->open_time) || empty($rule->close_time)) {
                $totalMinutes += 8 * 60;  // partial rule, fallback
                continue;
            }

            try {
                $open  = Carbon::parse($rule->open_time);
                $close = Carbon::parse($rule->close_time);
                $mins  = max(0, $open->diffInMinutes($close));
                $totalMinutes += $mins;
            } catch (\Throwable $e) {
                $totalMinutes += 8 * 60;
            }
        }

        return $totalMinutes;
    }

    private function revenueForDate(Carbon $date): int
    {
        // MARKER-PATCH-184B — payments received (ledger) for the tenant-local
        // day, replacing appointment totals. recorded_at is UTC; bound by the
        // local-day window converted to UTC. Signed amounts net refunds.
        $tz = $this->tenant->timezone();
        $start = $date->copy()->setTimezone($tz)->startOfDay()->utc();
        $end   = $date->copy()->setTimezone($tz)->endOfDay()->utc();
        return (int) DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$start, $end])
            ->sum('amount_cents');
    }

    private function bookingCountForDate(Carbon $date): int
    {
        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();
    }

    private function capacityForDate(Carbon $date): ?int
    {
        $dow = $date->dayOfWeek;
        $rule = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where(function ($q) use ($dow, $date) {
                $q->where(function ($s) use ($date) {
                    $s->where('rule_type', 'override')->where('specific_date', $date->toDateString());
                })->orWhere(function ($s) use ($dow) {
                    $s->where('rule_type', 'default')->where('day_of_week', $dow)->whereNull('specific_date');
                });
            })
            ->orderByRaw("CASE WHEN rule_type='override' THEN 0 ELSE 1 END")
            ->first();

        return $rule?->max_appointments;
    }

    private function noShowCountForDate(Carbon $date): int
    {
        // Strict + 24h grace: only count yesterday-or-earlier confirmed
        // appointments. Today's date returns 0 because grace hasn't elapsed.
        if ($date->gte($this->tenant->localToday())) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->where('status', 'confirmed')
            ->count();
    }

    private function noShowCountForRange(Carbon $from, Carbon $to): int
    {
        // Strict + 24h grace: only count appointments that were actually
        // confirmed (not pending) AND whose date is at least one full day in
        // the past. This prevents inflating no-show counts with appointments
        // that simply haven't been status-updated yet.
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->where('status', 'confirmed')
            ->count();
    }

    private function noShowRateForRange(Carbon $from, Carbon $to): float
    {
        // Same strict + 24h grace as noShowCountForRange. Denominator is
        // every non-cancelled appointment, numerator is just the confirmed
        // ones that didn't make it to delivered.
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        $total = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->whereNotIn('status', self::CANCELLED_STATUSES)
            ->count();

        if ($total === 0) return 0;

        $noShows = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->where('status', 'confirmed')
            ->count();

        return $noShows / $total;
    }

    private function newCustomerCountForDate(Carbon $date): int
    {
        return TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereDate('created_at', $date->toDateString())
            ->count();
    }

    private function deltaPercent(int $current, int $prior): ?array
    {
        if ($prior === 0) return null;
        $pct = round((($current - $prior) / $prior) * 100);
        return [
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            'value'     => abs($pct) . '%',
        ];
    }

    private function deltaCount(int $current, int $prior): ?array
    {
        $diff = $current - $prior;
        return [
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            'value'     => abs($diff),
        ];
    }
}
