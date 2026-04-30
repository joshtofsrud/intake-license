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
 * Powers the /admin/reports page. Mirrors DashboardDataService's pattern —
 * one method per zone, return shape designed for the Blade view.
 *
 * Phase 2: per-zone time range toggle (Today / Week / Month).
 * Range strings are 'today', 'week', 'month' (anything else falls back
 * to 'today'). The controller parses query params and passes the chosen
 * range to each zone method independently.
 *
 * Status conventions (from session-locked pipeline + dog-food data):
 *   delivered = ['completed', 'closed']  — counts as fulfilled work
 *   cancelled = ['cancelled']
 *   no_show   = past appointments NOT in delivered/cancelled/refunded
 *   refunded  = ['refunded']
 */
class ReportsDataService
{
    private const DELIVERED_STATUSES = ['completed', 'closed'];
    private const CANCELLED_STATUSES = ['cancelled'];
    private const REFUNDED_STATUSES  = ['refunded'];

    public const RANGES = ['today', 'week', 'month'];

    public function __construct(private readonly Tenant $tenant) {}

    /** Convert a range string into a [from, to] Carbon pair. */
    private function rangeBounds(string $range): array
    {
        $today = $this->tenant->localToday();
        return match ($range) {
            'week'  => [$today->copy()->subDays(6), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            default => [$today->copy(), $today->copy()],
        };
    }

    /** Same range one period back (for delta-vs-prior comparisons). */
    private function priorRangeBounds(string $range): array
    {
        $today = $this->tenant->localToday();
        return match ($range) {
            'week'  => [$today->copy()->subDays(13), $today->copy()->subDays(7)],
            'month' => [$today->copy()->subMonth()->startOfMonth(), $today->copy()->subMonth()->endOfMonth()],
            default => [$today->copy()->subWeek(), $today->copy()->subWeek()],
        };
    }

    private function rangeLabel(string $range): string
    {
        $today = $this->tenant->localToday();
        return match ($range) {
            'week'  => 'last 7 days',
            'month' => $today->format('F'),
            default => 'today',
        };
    }

    /** Top KPI row — always shows today's snapshot regardless of zone toggles. */
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

    /** Zone 1: Revenue. Hourly breakdown for today; daily for week/month. */
    public function zoneRevenue(string $range = 'today'): array
    {
        [$from, $to] = $this->rangeBounds($range);

        $totalCents = (int) TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->sum('total_cents');

        // Series: hourly for today, daily for week/month
        $series = [];
        if ($range === 'today') {
            $hourly = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->where('appointment_date', $from->toDateString())
                ->whereIn('status', self::DELIVERED_STATUSES)
                ->where('payment_status', 'paid')
                ->selectRaw("HOUR(appointment_time) as hour, SUM(total_cents) as cents, COUNT(*) as n")
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
            $daily = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('status', self::DELIVERED_STATUSES)
                ->where('payment_status', 'paid')
                ->selectRaw('appointment_date as d, SUM(total_cents) as cents, COUNT(*) as n')
                ->groupBy('d')
                ->get()
                ->keyBy('d');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $row = $daily->get($d->toDateString());
                $series[] = [
                    'label' => $d->format($range === 'week' ? 'D' : 'j'),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        }

        $bestBucket = collect($series)->sortByDesc('cents')->first();

        // Service mix for the same range
        $byService = DB::table('tenant_appointments as ta')
            ->where('ta.tenant_id', $this->tenant->id)
            ->whereBetween('ta.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('ta.status', self::DELIVERED_STATUSES)
            ->where('ta.payment_status', 'paid')
            ->join('tenant_appointment_items as tai', 'tai.appointment_id', '=', 'ta.id')
            ->selectRaw('tai.item_name_snapshot as name, SUM(COALESCE(tai.price_cents_override, tai.price_cents)) as cents, COUNT(DISTINCT ta.id) as bookings')
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

        return [
            'range'         => $range,
            'range_label'   => $this->rangeLabel($range),
            'total_cents'   => $totalCents,
            'best_bucket'   => $bestBucket && $bestBucket['cents'] > 0
                ? ['label' => $bestBucket['label'], 'cents' => $bestBucket['cents']]
                : null,
            'series'        => $series,
            'series_kind'   => $range === 'today' ? 'hourly' : 'daily',
            'by_service'    => $byService,
        ];
    }

    /** Zone 2: Bookings + cancellations. */
    public function zoneBookings(string $range = 'week'): array
    {
        [$from, $to] = $this->rangeBounds($range);

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
            ->whereColumn('created_at', '>=', 'appointment_date')
            ->whereRaw('DATE(created_at) = appointment_date')
            ->count();

        // Daily timeline across the range
        $daily = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('appointment_date as d, COUNT(*) as n')
            ->groupBy('d')
            ->pluck('n', 'd')
            ->toArray();

        $timeline = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $timeline[] = [
                'date'  => $d->toDateString(),
                'label' => $d->format($range === 'today' ? 'ga' : ($range === 'week' ? 'D' : 'j')),
                'count' => (int) ($daily[$d->toDateString()] ?? 0),
            ];
        }

        // For 'today' the daily chart is just one bar — replace with hourly.
        if ($range === 'today') {
            $hourly = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->where('appointment_date', $from->toDateString())
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw("HOUR(appointment_time) as hour, COUNT(*) as n")
                ->groupBy('hour')
                ->pluck('n', 'hour')
                ->toArray();
            $timeline = [];
            for ($h = 8; $h <= 18; $h++) {
                $timeline[] = [
                    'date'  => $from->toDateString(),
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'count' => (int) ($hourly[$h] ?? 0),
                ];
            }
        }

        return [
            'range'       => $range,
            'range_label' => $this->rangeLabel($range),
            'confirmed'   => $confirmed,
            'cancelled'   => $cancelled,
            'no_shows'    => $noShows,
            'walkins'     => $walkins,
            'timeline'    => $timeline,
        ];
    }

    /** Zone 3: Customers + retention. */
    public function zoneCustomers(string $range = 'month'): array
    {
        [$from, $to] = $this->rangeBounds($range);
        $today = $this->tenant->localToday();

        // Daily new vs returning over the range
        $rangeAppts = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->select('appointment_date', 'customer_id')
            ->get()
            ->groupBy(fn($r) => $r->appointment_date);

        // "New this period" = customer created within the range
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

        // Top customers by spend within the range
        $topCustomers = TenantCustomer::where('tenant_customers.tenant_id', $this->tenant->id)
            ->join('tenant_appointments as ta', function ($j) use ($from, $to) {
                $j->on('ta.customer_id', '=', 'tenant_customers.id')
                  ->whereIn('ta.status', self::DELIVERED_STATUSES)
                  ->where('ta.payment_status', 'paid')
                  ->whereBetween('ta.appointment_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->selectRaw('tenant_customers.id, tenant_customers.first_name, tenant_customers.last_name, tenant_customers.created_at, SUM(ta.total_cents) as cents, COUNT(ta.id) as visits')
            ->groupBy('tenant_customers.id', 'tenant_customers.first_name', 'tenant_customers.last_name', 'tenant_customers.created_at')
            ->orderByDesc('cents')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'name'     => trim($r->first_name . ' ' . $r->last_name),
                'cents'    => (int) $r->cents,
                'visits'   => (int) $r->visits,
                'is_new_in_period' => Carbon::parse($r->created_at)->between($from, $to->copy()->endOfDay()),
            ])
            ->all();

        return [
            'range'         => $range,
            'range_label'   => $this->rangeLabel($range),
            'daily'         => $daily,
            'top_customers' => $topCustomers,
        ];
    }

    /** Zone 4: Service popularity. */
    public function zoneServices(string $range = 'month'): array
    {
        [$from, $to] = $this->rangeBounds($range);

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
            'range'       => $range,
            'range_label' => $this->rangeLabel($range),
            'services' => $rows->map(fn($r) => [
                'name'      => $r->name,
                'bookings'  => (int) $r->bookings,
                'cents'     => (int) $r->cents,
                'bar_pct'   => round(($r->cents / $maxCents) * 100),
            ])->all(),
        ];
    }

    /** Zone 5: Staff utilization. */
    public function zoneStaff(string $range = 'week'): array
    {
        [$from, $to] = $this->rangeBounds($range);
        $days = max(1, $from->diffInDays($to) + 1);
        $availableMinutes = $days * 8 * 60;  // 8h/day baseline

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

        $revenue = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->selectRaw('resource_id, SUM(total_cents) as cents')
            ->groupBy('resource_id')
            ->pluck('cents', 'resource_id')
            ->toArray();

        $today = $this->tenant->localToday();
        $noShowsByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), min($to->toDateString(), $today->copy()->subDay()->toDateString())])
            ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('resource_id, COUNT(*) as n')
            ->groupBy('resource_id')
            ->pluck('n', 'resource_id')
            ->toArray();

        $totalByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('resource_id, COUNT(*) as n')
            ->groupBy('resource_id')
            ->pluck('n', 'resource_id')
            ->toArray();

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

        return [
            'range'       => $range,
            'range_label' => $this->rangeLabel($range),
            'cards'       => $cards,
        ];
    }

    /** Zone 6: Capacity heatmap. */
    public function zoneCapacity(string $range = 'month'): array
    {
        [$from, $to] = $this->rangeBounds($range);

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
            'range'        => $range,
            'range_label'  => $this->rangeLabel($range),
            'grid'         => $grid,
            'hour_labels'  => array_map(
                fn($h) => Carbon::createFromTime($h)->format('ga'),
                range(8, 21)
            ),
        ];
    }

    // ---------- helpers ----------

    private function revenueForDate(Carbon $date): int
    {
        return (int) TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->sum('total_cents');
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
        if ($date->gte($this->tenant->localToday())) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();
    }

    private function noShowCountForRange(Carbon $from, Carbon $to): int
    {
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();
    }

    private function noShowRateForRange(Carbon $from, Carbon $to): float
    {
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
            ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
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
