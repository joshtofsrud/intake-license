<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportsDataService
 *
 * Powers the /admin/reports page. Mirrors DashboardDataService's pattern —
 * one method per zone, return shape designed for the Blade view.
 *
 * Phase 1 ships the "Daily ops · today" view of all six zones.
 * Phase 2 adds the Daily/Monthly toggle (range parameter on every method).
 * Phase 3 adds drilldowns + CSV/PDF export.
 *
 * Status conventions (from session-locked pipeline + dog-food data):
 *   delivered = ['completed', 'closed']  — counts as fulfilled work
 *   cancelled = ['cancelled']
 *   no_show   = appointments past their end time, status NOT IN delivered/cancelled/refunded
 *   refunded  = ['refunded']
 */
class ReportsDataService
{
    private const DELIVERED_STATUSES = ['completed', 'closed'];
    private const CANCELLED_STATUSES = ['cancelled'];
    private const REFUNDED_STATUSES  = ['refunded'];

    public function __construct(private readonly Tenant $tenant) {}

    /** Top-of-page KPI row: 4 cards. */
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

    /** Zone 1: Revenue. Hourly breakdown for today + service mix. */
    public function zoneRevenue(): array
    {
        $today = $this->tenant->localToday();

        // Hourly revenue from delivered/paid appointments
        $hourly = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $today->toDateString())
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->selectRaw("HOUR(appointment_time) as hour, SUM(total_cents) as cents, COUNT(*) as n")
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $hourSeries = [];
        for ($h = 8; $h <= 18; $h++) {
            $row = $hourly->get($h);
            $hourSeries[] = [
                'hour'    => $h,
                'label'   => Carbon::createFromTime($h)->format('ga'),
                'cents'   => (int) ($row->cents ?? 0),
                'count'   => (int) ($row->n ?? 0),
            ];
        }

        $totalCents = collect($hourSeries)->sum('cents');
        $bestHour = collect($hourSeries)->sortByDesc('cents')->first();

        // Service mix for today
        $byService = TenantAppointment::where('tenant_appointments.tenant_id', $this->tenant->id)
            ->where('appointment_date', $today->toDateString())
            ->whereIn('tenant_appointments.status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->join('tenant_appointment_items as tai', 'tai.appointment_id', '=', 'tenant_appointments.id')
            ->selectRaw('tai.item_name_snapshot as name, SUM(COALESCE(tai.price_cents_override, tai.price_cents)) as cents, COUNT(DISTINCT tenant_appointments.id) as bookings')
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
            'total_cents'   => $totalCents,
            'best_hour'     => $bestHour && $bestHour['cents'] > 0
                ? ['label' => $bestHour['label'], 'cents' => $bestHour['cents']]
                : null,
            'hourly'        => $hourSeries,
            'by_service'    => $byService,
        ];
    }

    /** Zone 2: Bookings + cancellations. */
    public function zoneBookings(): array
    {
        $today = $this->tenant->localToday();
        $weekAgo = $today->copy()->subDays(6);

        // KPI sub-row for today
        $confirmed = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $today->toDateString())
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();

        $cancelled = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $today->toDateString())
            ->whereIn('status', self::CANCELLED_STATUSES)
            ->count();

        $noShows = $this->noShowCountForDate($today);
        $walkins = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $today->toDateString())
            ->whereDate('created_at', $today->toDateString())
            ->count();

        // 7-day timeline
        $timeline = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekAgo->toDateString(), $today->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('appointment_date as d, COUNT(*) as n')
            ->groupBy('d')
            ->pluck('n', 'd')
            ->toArray();

        $series = [];
        for ($d = $weekAgo->copy(); $d->lte($today); $d->addDay()) {
            $series[] = [
                'date'  => $d->toDateString(),
                'label' => $d->format('D'),
                'count' => (int) ($timeline[$d->toDateString()] ?? 0),
            ];
        }

        return [
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'no_shows'  => $noShows,
            'walkins'   => $walkins,
            'timeline'  => $series,
        ];
    }

    /** Zone 3: Customers + retention. Defaults to current calendar month. */
    public function zoneCustomers(): array
    {
        $today = $this->tenant->localToday();
        $monthStart = $today->copy()->startOfMonth();

        // Daily new vs returning over the month
        $monthCustomerIds = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$monthStart->toDateString(), $today->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->select('appointment_date', 'customer_id')
            ->get()
            ->groupBy(fn($r) => $r->appointment_date);

        $newCustIdsThisMonth = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$monthStart->toDateString() . ' 00:00:00', $today->toDateString() . ' 23:59:59'])
            ->pluck('id')
            ->all();
        $newSet = array_flip($newCustIdsThisMonth);

        $daily = [];
        for ($d = $monthStart->copy(); $d->lte($today); $d->addDay()) {
            $key = $d->toDateString();
            $newCount = 0;
            $returningCount = 0;
            foreach ($monthCustomerIds->get($key, collect()) as $row) {
                if (isset($newSet[$row->customer_id])) $newCount++;
                else $returningCount++;
            }
            $daily[] = [
                'date'      => $key,
                'new'       => $newCount,
                'returning' => $returningCount,
            ];
        }

        // Top customers this month by lifetime spend (matters more than this month alone)
        $topCustomers = TenantCustomer::where('tenant_customers.tenant_id', $this->tenant->id)
            ->join('tenant_appointments as ta', function ($j) {
                $j->on('ta.customer_id', '=', 'tenant_customers.id')
                  ->whereIn('ta.status', self::DELIVERED_STATUSES)
                  ->where('ta.payment_status', 'paid');
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
                'is_new_this_month' => Carbon::parse($r->created_at)->gte($monthStart),
            ])
            ->all();

        return [
            'month_label'   => $today->format('F'),
            'daily'         => $daily,
            'top_customers' => $topCustomers,
        ];
    }

    /** Zone 4: Service popularity. Trailing 30 days. */
    public function zoneServices(): array
    {
        $today = $this->tenant->localToday();
        $thirtyAgo = $today->copy()->subDays(29);

        $rows = DB::table('tenant_appointments as ta')
            ->where('ta.tenant_id', $this->tenant->id)
            ->whereBetween('ta.appointment_date', [$thirtyAgo->toDateString(), $today->toDateString()])
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

    /** Zone 5: Staff utilization. Trailing 7 days. */
    public function zoneStaff(): array
    {
        $today = $this->tenant->localToday();
        $weekAgo = $today->copy()->subDays(6);

        $resources = TenantResource::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $bookedMinutes = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekAgo->toDateString(), $today->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('resource_id, SUM(total_duration_minutes) as mins, COUNT(*) as n')
            ->groupBy('resource_id')
            ->get()
            ->keyBy('resource_id');

        $revenue = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekAgo->toDateString(), $today->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->selectRaw('resource_id, SUM(total_cents) as cents')
            ->groupBy('resource_id')
            ->pluck('cents', 'resource_id')
            ->toArray();

        $noShowsByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekAgo->toDateString(), $today->toDateString()])
            ->whereDate('appointment_date', '<', $today->toDateString())
            ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('resource_id, COUNT(*) as n')
            ->groupBy('resource_id')
            ->pluck('n', 'resource_id')
            ->toArray();

        $totalByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekAgo->toDateString(), $today->toDateString()])
            ->selectRaw('resource_id, COUNT(*) as n')
            ->groupBy('resource_id')
            ->pluck('n', 'resource_id')
            ->toArray();

        // Available minutes per resource: 7 days × 8 hour workday default
        // (This is a heuristic for v1; later this should pull from actual hours.)
        $availableMinutes = 7 * 8 * 60;

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
                'name'        => $r->name,
                'subtitle'    => $r->subtitle ?: 'Staff',
                'color_hex'   => $r->color_hex,
                'utilization' => $utilization,
                'booked_hrs'  => round($booked / 60, 1),
                'available_hrs' => round($availableMinutes / 60, 1),
                'appts'       => $appts,
                'revenue_cents' => $rev,
                'no_show_rate'=> $noShowRate,
                'health'      => $health,
            ];
        })->all();

        return ['cards' => $cards];
    }

    /** Zone 6: Capacity heatmap. Last 14 days × hours 8a-9p. */
    public function zoneCapacity(): array
    {
        $today = $this->tenant->localToday();
        $start = $today->copy()->subDays(13);

        // Day-of-week × hour bucket map: count of bookings landing in that cell
        // over the 14-day window. Days with no rows get all-zero rows.
        $cells = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$start->toDateString(), $today->toDateString()])
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
                // Map count to 0-5 fill bucket
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
        // Pull from default capacity rule for that day-of-week, fall back to override
        // for the specific date if one exists. Returns null if not gated.
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
        // Past appointments NOT delivered/cancelled/refunded = no-show
        if ($date->gte($this->tenant->localToday())) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();
    }

    private function noShowRateForRange(Carbon $from, Carbon $to): float
    {
        $endsBeforeToday = min($to->toDateString(), $this->tenant->localToday()->copy()->subDay()->toDateString());

        $total = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $endsBeforeToday])
            ->whereNotIn('status', self::CANCELLED_STATUSES)
            ->count();

        if ($total === 0) return 0;

        $noShows = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $endsBeforeToday])
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
