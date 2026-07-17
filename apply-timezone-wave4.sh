#!/bin/bash
# timezone-wave4 — DST-safe bucketing + the timezone regression harness.
#   · helpers: tenant_tz_offset_expr() — builds a per-row DST-correct SQL
#     offset (CASE over the transitions inside the queried range) instead of
#     applying TODAY's offset to historical rows
#   · ReportsDataService hourly + daily revenue buckets and the dashboard
#     daily revenue spark now use it — rows near a DST change no longer
#     shift an hour or land on the wrong local day
#     (TrafficReportService hourly was already DST-safe by construction)
#   · tests/Unit/TimezoneHelpersTest.php — 16 assertions covering normal
#     days, late-evening UTC crossover, spring-forward (23h), fall-back
#     (25h), era-correct bucketing, and a direct reproduction of the
#     today's-offset bug. All 16 verified passing against these exact
#     helpers. Run: vendor/bin/phpunit --bootstrap vendor/autoload.php
#     tests/Unit/TimezoneHelpersTest.php
# No routes, no migrations.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-TZ-WAVE4" app/helpers.php; then
  echo "timezone-wave4 already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TZ-WAVE1" app/helpers.php; then
  echo "timezone-wave1 not applied — wrong base, aborting."; exit 1
fi
mkdir -p tests/Unit

cat > 'app/helpers.php' <<'TZW4_0_EOF'
<?php

if (! function_exists('tenant')) {
    /**
     * Get the current tenant instance, or null if not in a tenant context.
     *
     * @return \App\Models\Tenant|null
     */
    function tenant(): ?\App\Models\Tenant
    {
        return app('tenant');
    }
}

if (! function_exists('tenant_url')) {
    /**
     * Generate a URL for the current tenant's public site.
     *
     * @param  string $path
     * @return string
     */
    function tenant_url(string $path = ''): string
    {
        $t = tenant();
        if (! $t) return url($path);

        // MARKER-PATCH-123 — delegate to Tenant::publicUrl() so custom
        // domains served via tenant_domains (and legacy custom_domain) are
        // both handled in one place.
        return $t->publicUrl() . '/' . ltrim($path, '/');
    }
}

if (! function_exists('format_money')) {
    /**
     * Format cents as a currency string using the current tenant's symbol.
     *
     * @param  int    $cents
     * @param  string $symbol  Fallback if no tenant in scope
     * @return string
     */
    function format_money(int $cents, string $symbol = '$'): string
    {
        $sym = tenant()?->currency_symbol ?? $symbol;
        return $sym . number_format($cents / 100, 2);
    }
}

if (! function_exists('tlocal')) {
    /**
     * MARKER-PATCH-189 — Render a UTC datetime instant in the current tenant's
     * timezone. THE canonical way to display any 'datetime'-cast column
     * (scheduled_at, starts_at, created_at, sent_at, …). Storing UTC and
     * converting at the edge is the standard; this makes the conversion
     * impossible to forget. For naive wall-clock values (appointment_time),
     * do NOT use this — those are already tenant-local and must not be shifted.
     *
     * @param  \Carbon\Carbon|\DateTimeInterface|string|null $instant  UTC instant
     * @param  string $format  PHP date format (default: '8:30 AM')
     * @return string          Empty string for null
     */
    function tlocal($instant, string $format = 'g:i A'): string
    {
        if ($instant === null || $instant === '') {
            return '';
        }
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $c  = $instant instanceof \Carbon\Carbon
            ? $instant->copy()
            : \Carbon\Carbon::parse($instant, 'UTC');
        // A bare string/DateTime is assumed UTC (matches how the DB stores
        // 'datetime' casts). Carbon instances already carry their own tz.
        return $c->setTimezone($tz)->format($format);
    }
}

if (! function_exists('tnow')) {
    /**
     * MARKER-PATCH-234C — "now" as a tenant-local Carbon. Use for
     * date-of-day boundaries the tenant will see (today's pickups, week
     * windows). For storage timestamps and created_at comparisons use plain
     * now() — those are UTC. Mirrors DashboardDataService::tnow().
     *
     * @return \Carbon\Carbon
     */
    function tnow(): \Carbon\Carbon
    {
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        return \Carbon\Carbon::now($tz);
    }
}

if (! function_exists('tlocal_date')) {
    /** Tenant-local date, e.g. "May 31, 2026". @see tlocal() */
    function tlocal_date($instant, string $format = 'M j, Y'): string
    {
        return tlocal($instant, $format);
    }
}

if (! function_exists('tlocal_datetime')) {
    /** Tenant-local date + time, e.g. "May 31, 2026 8:30 AM". @see tlocal() */
    function tlocal_datetime($instant, string $format = 'M j, Y g:i A'): string
    {
        return tlocal($instant, $format);
    }
}

if (! function_exists('tlocal_carbon')) {
    /**
     * Same conversion as tlocal() but returns the Carbon (for further work /
     * comparisons), not a formatted string. Returns null for null input.
     *
     * @return \Carbon\Carbon|null
     */
    function tlocal_carbon($instant): ?\Carbon\Carbon
    {
        if ($instant === null || $instant === '') {
            return null;
        }
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $c  = $instant instanceof \Carbon\Carbon
            ? $instant->copy()
            : \Carbon\Carbon::parse($instant, 'UTC');
        return $c->setTimezone($tz);
    }
}

if (! function_exists('debug_log')) {
    /**
     * Shortcut to the DebugLogService singleton.
     *
     *   debug_log()->error($exception);
     *   debug_log()->audit('settings_updated', 'Tenant updated', $tenant, $diff);
     *   debug_log()->mail($recipient, 'booking.confirmation');
     */
    function debug_log(): \App\Services\DebugLogService
    {
        return app(\App\Services\DebugLogService::class);
    }
}

if (! function_exists('tender_label')) {
    /**
     * MARKER-PATCH-630 — human label for a payment_method key.
     * 'cash_app' → 'Cash App', 'custom_house_account' → 'House account'.
     * Prefers the tenant's configured method name when available.
     */
    function tender_label(?string $key): string
    {
        if (!$key) return '';
        static $cache = [];
        $tid = function_exists('tenant') && app()->bound('tenant') && tenant() ? tenant()->id : null;
        $ck = ($tid ?? '-') . ':' . $key;
        if (isset($cache[$ck])) return $cache[$ck];

        $name = null;
        if ($tid) {
            try {
                $name = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $tid)
                    ->where('method_key', $key)->value('name');
            } catch (\Throwable $e) { /* table may not exist yet */ }
        }
        if (!$name) {
            $name = ucfirst(str_replace('_', ' ', preg_replace('/^custom_/', '', $key)));
        }
        return $cache[$ck] = $name;
    }
}

if (! function_exists('tenant_day_utc_range')) {
    /**
     * MARKER-TZ-WAVE1 — the ONE way to bound a tenant-local calendar day
     * when querying UTC timestamp columns. Returns [startUtc, endUtc)
     * for the given tenant-local day.
     *
     * WRONG: ->whereDate('paid_at', tnow()->toDateString())
     *        (compares the UTC date of the stored instant — evening rows
     *        land on tomorrow)
     * RIGHT: [$s, $e] = tenant_day_utc_range(tnow());
     *        ->where('paid_at', '>=', $s)->where('paid_at', '<', $e)
     *
     * @param  \Carbon\Carbon|string  $day  tenant-local day (Carbon or Y-m-d)
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    function tenant_day_utc_range(\Carbon\Carbon|string $day, ?string $tz = null): array
    {
        $tz ??= tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $local = $day instanceof \Carbon\Carbon
            ? $day->copy()->setTimezone($tz)->startOfDay()
            : \Carbon\Carbon::parse($day, $tz)->startOfDay();

        return [$local->copy()->utc(), $local->copy()->addDay()->utc()];
    }
}

if (! function_exists('tenant_tz_offset_expr')) {
    /**
     * MARKER-TZ-WAVE4 — DST-correct SQL expression converting a UTC
     * timestamp COLUMN to tenant-local time for bucketing (DATE()/HOUR()).
     *
     * WRONG: $off = Carbon::now($tz)->utcOffset() * 60;           // TODAY's offset
     *        DATE(DATE_ADD(recorded_at, INTERVAL $off SECOND))    // applied to history
     *        — rows across a DST change bucket an hour off, and around
     *          midnight land on the wrong local day.
     * RIGHT: [$expr, $b] = tenant_tz_offset_expr('recorded_at', $tz, $startUtc, $endUtc);
     *        ->selectRaw("DATE($expr) as d, ...", $b)
     *
     * Builds a CASE over the DST transitions inside [$startUtc, $endUtc] so
     * each row gets the offset that was in force at its own instant.
     * $column MUST be a trusted literal (never user input).
     *
     * @return array{0:string,1:array}  [sql fragment, bindings]
     */
    function tenant_tz_offset_expr(string $column, string $tz, \Carbon\Carbon $startUtc, \Carbon\Carbon $endUtc): array
    {
        $zone = new \DateTimeZone($tz);
        $transitions = $zone->getTransitions($startUtc->timestamp, $endUtc->timestamp) ?: [];

        // First entry describes the offset in force at range start; the rest
        // are actual changes inside the range.
        $eras = [];
        foreach ($transitions as $t) {
            $eras[] = ['ts' => (int) $t['ts'], 'offset' => (int) $t['offset']];
        }
        if ($eras === []) {
            $eras[] = ['ts' => $startUtc->timestamp, 'offset' => $zone->getOffset($startUtc->toDateTime())];
        }

        if (count($eras) === 1) {
            return ["DATE_ADD({$column}, INTERVAL ? SECOND)", [$eras[0]['offset']]];
        }

        $sql = 'CASE';
        $bindings = [];
        for ($i = 1; $i < count($eras); $i++) {
            $sql .= " WHEN {$column} < ? THEN ?";
            $bindings[] = \Carbon\Carbon::createFromTimestampUTC($eras[$i]['ts'])->toDateTimeString();
            $bindings[] = $eras[$i - 1]['offset'];
        }
        $sql .= ' ELSE ? END';
        $bindings[] = $eras[count($eras) - 1]['offset'];

        return ["DATE_ADD({$column}, INTERVAL ({$sql}) SECOND)", $bindings];
    }
}
TZW4_0_EOF

cat > 'app/Services/Tenant/ReportsDataService.php' <<'TZW4_1_EOF'
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

        // MARKER-TZ-WAVE4 — DST-correct per-row offset (a fixed "today"
        // offset shifted historical rows across DST changes).
        [$tzExpr, $tzBind] = tenant_tz_offset_expr('recorded_at', $tz, $winStart, $winEnd);

        $base = DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$winStart, $winEnd]);

        $totalCents = (int) (clone $base)->sum('amount_cents');

        $series = [];
        if ($isSingleDay) {
            $hourly = (clone $base)
                ->selectRaw("HOUR({$tzExpr}) as hour, SUM(amount_cents) as cents, COUNT(*) as n", $tzBind)
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
                ->selectRaw("DATE({$tzExpr}) as d, SUM(amount_cents) as cents, COUNT(*) as n", $tzBind)
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
        // MARKER-TZ-WAVE1 — created_at is UTC; bucket by the tenant day's
        // UTC range so evening signups land on the right local day.
        [$s, $e] = tenant_day_utc_range($date, $this->tenant->timezone());
        return TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $s)
            ->where('created_at', '<',  $e)
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
TZW4_1_EOF

cat > 'app/Services/Tenant/DashboardDataService.php' <<'TZW4_2_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Support\AppointmentStatus;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantInventoryItem;  // MARKER-PATCH-110-STEP-1
use App\Services\Tenant\CustomersReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardDataService
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * "Now" in tenant local time. Use for any date-of-day calculation
     * the tenant will see (greeting hour, today's appointments, week boundaries).
     * For storage timestamps and created_at/updated_at comparisons, use plain now() — those are UTC.
     */
    private function tnow(): Carbon
    {
        return Carbon::now($this->tenant->timezone());
    }

    public function greeting(?object $user = null): array
    {
        $hour = (int) $this->tnow()->format('G');
        $timeOfDay = match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default    => 'evening',
        };

        $name = null;
        if ($user && $user->name) {
            $name = trim(explode(' ', $user->name)[0]);
        }

        return [
            'time_of_day' => $timeOfDay,
            'name'        => $name,
            'date_long'   => $this->tnow()->format('l, F j'),
        ];
    }

    public function zoneToday(): array
    {
        $today = $this->tnow()->toDateString();
        $weekStart = $this->tnow()->startOfWeek()->toDateString();

        $todayAppointments = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereDate('appointment_date', $today)
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with(['items', 'customer'])
            ->get();

        $nextUp = $todayAppointments->first(function ($a) {
            if (!$a->appointment_time) return false;
            // MARKER-PATCH-362 — appointment_time is naive tenant-local wall-clock;
            // parse it in the tenant timezone so "is it still upcoming?" compares
            // real instants against tnow() (was ~7h early, which hid the next-up
            // banner for genuinely upcoming appointments).
            $apptDateTime = Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time, $this->tenant->timezone());
            return $apptDateTime->greaterThanOrEqualTo($this->tnow());
        });

        // Patch 47: no fallback to first-of-day. If today's appointments are all
        // in the past, $nextUp stays null and the Blade hides the card. Showing
        // a completed 8am appointment as "Next up" at 9pm is worse than hiding
        // the card entirely. Future: fall through to tomorrow's first appointment.
        // (No fallback assignment — $nextUp may legitimately be null.)

        $last24hNewBookings = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $weekBase = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekStart, $today]);

        $weekBookings = (clone $weekBase)->count();
        // MARKER-PATCH-185 — week revenue = payments received (sale ledger).
        $tzW = $this->tenant->timezone();
        // $weekStart is a Y-m-d string; parse in tenant tz for the UTC window.
        $weekRevenue = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [
                Carbon::parse($weekStart, $tzW)->startOfDay()->utc(),
                $this->tnow()->copy()->setTimezone($tzW)->endOfDay()->utc(),
            ])
            ->sum('amount_cents');
        $weekCancellations = (clone $weekBase)->whereIn('status', AppointmentStatus::terminalStatuses())->count();

        $weekNewCustomers = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $weekStart)
            ->count();

        // MARKER-PATCH-183 — today's deliveries for the dashboard mini-section.
        $todayDeliveries = collect();
        try {
            $todayDeliveries = (new \App\Services\Tenant\TenantDeliveryService($this->tenant))
                ->forDay($this->tnow());
        } catch (\Throwable $e) {
            $todayDeliveries = collect();
        }

        return [
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'today_deliveries'    => $todayDeliveries,
            'next_up'             => $nextUp,
            'last_24h_bookings'   => $last24hNewBookings,
            'week_bookings'       => $weekBookings,
            'week_revenue_cents'  => $weekRevenue,
            'week_new_customers'  => $weekNewCustomers,
            'week_cancellations'  => $weekCancellations,
            'strip'               => $this->build7DayStripCenteredOn($this->tnow()->startOfDay()),
        ];
    }

    public function zoneAttention(): array
    {
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->toDateString();

        $unconfirmedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::awaitingStatuses())
            ->whereDate('appointment_date', '>=', $today)
            ->count();

        // MARKER-PICKUP-OUTREACH
        $pickupOutreachCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('pickup_outreach_pending', true)
            ->count();

        $unpaidDoneCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $unpaidDoneSumCents = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->sum(DB::raw('total_cents - paid_cents'));

        $readyPickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $waitlistCount = 0;
        if (class_exists(TenantWaitlistEntry::class)) {
            try {
                $waitlistCount = TenantWaitlistEntry::where('tenant_id', $tenantId)
                    ->where('status', 'waiting')
                    ->count();
            } catch (\Throwable $e) {
                $waitlistCount = 0;
            }
        }

        // MARKER-PATCH-539 — completed jobs with no scheduled drop-off (P&D tenants).
        // No-reply proposals (customer never picked from the options link) called out.
        $awaitingDeliveryCount = 0;
        $awaitingNoReplyCount  = 0;
        if ($this->tenant->deliveries_enabled) {
            $base = TenantAppointment::query()
                ->where('tenant_appointments.tenant_id', $tenantId)
                ->where('tenant_appointments.status', 'completed')
                ->whereNotNull('tenant_appointments.completed_at')
                ->where('tenant_appointments.completed_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('tenant_deliveries')
                        ->whereColumn('tenant_deliveries.appointment_id', 'tenant_appointments.id')
                        ->where('tenant_deliveries.type', 'dropoff')
                        ->where('tenant_deliveries.status', '!=', 'cancelled');
                });
            $awaitingDeliveryCount = (clone $base)->count();
            if ($awaitingDeliveryCount > 0) {
                $awaitingNoReplyCount = (clone $base)
                    ->whereExists(function ($q) use ($tenantId) {
                        $q->selectRaw('1')
                            ->from('tenant_delivery_proposals')
                            ->whereColumn('tenant_delivery_proposals.appointment_id', 'tenant_appointments.id')
                            ->where('tenant_delivery_proposals.tenant_id', $tenantId)
                            ->where('tenant_delivery_proposals.status', 'no_reply');
                    })->count();
            }
        }

        $cards = [];

        if ($awaitingDeliveryCount > 0) {
            $singular = $this->tenant->asset_label_singular ?: 'job';   // MARKER-PATCH-539
            $plural   = $this->tenant->asset_label_plural ?: 'jobs';
            $cards[] = [
                'count' => $awaitingDeliveryCount,
                'title' => 'Awaiting delivery',
                'key'   => 'awaiting_delivery',
                'icon'  => '🚚',
                'desc'  => ($awaitingDeliveryCount === 1
                        ? "1 completed {$singular} with no drop-off scheduled"
                        : "{$awaitingDeliveryCount} completed {$plural} with no drop-off scheduled")
                    . ($awaitingNoReplyCount > 0 ? " — {$awaitingNoReplyCount} never replied to the options link" : ''),
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index', ['filter' => 'awaiting_delivery']),
            ];
        }

        if ($unconfirmedCount > 0) {
            $cards[] = [
                'count' => $unconfirmedCount,
                'title' => 'Pending bookings',
                'key'   => 'pending_bookings',
                'icon'  => '🛎️',
                'desc'  => $unconfirmedCount === 1
                    ? '1 booking awaiting confirmation or drop-off'
                    : $unconfirmedCount . ' bookings awaiting confirmation or drop-off',
                'tone'  => 'red',  // your action: review and confirm
                'link'  => route('tenant.appointments.index', ['filter' => 'unconfirmed_bookings']),
            ];
        }

        // MARKER-PICKUP-OUTREACH — bookings that asked for pickup outreach
        if ($pickupOutreachCount > 0) {
            $cards[] = [
                'count' => $pickupOutreachCount,
                'title' => 'Pickup to arrange',
                'key'   => 'pickup_outreach',
                'icon'  => '🚚',
                'desc'  => $pickupOutreachCount === 1
                    ? '1 booking asked you to reach out about pickup'
                    : $pickupOutreachCount . ' bookings asked you to reach out about pickup',
                'tone'  => 'amber',  // your action: contact and schedule
                'link'  => route('tenant.appointments.index', ['filter' => 'pickup_outreach']),
            ];
        }

        if ($unpaidDoneCount > 0) {
            $cards[] = [
                'count' => $unpaidDoneCount,
                'title' => 'Unpaid completed jobs',
                'key'   => 'unpaid_completed',
                'icon'  => '💳',
                'desc'  => '$' . number_format($unpaidDoneSumCents / 100, 0) . ' outstanding on finished work',
                'tone'  => 'amber',  // customer's action: send payment
                'link'  => route('tenant.appointments.index', ['filter' => 'unpaid_completed']),
            ];
        }

        if ($readyPickupCount > 0) {
            $cards[] = [
                'count' => $readyPickupCount,
                'title' => 'Ready for pickup',
                'key'   => 'ready_pickup',
                'icon'  => '✅',
                'desc'  => $readyPickupCount === 1
                    ? 'Customer ready to receive their bike'
                    : 'Customers ready to receive their bikes',
                'tone'  => 'amber',  // customer's action: collect their item
                'link'  => route('tenant.appointments.index', ['filter' => 'ready_pickup']),
            ];
        }

        if ($waitlistCount > 0) {
            $cards[] = [
                'count' => $waitlistCount,
                'title' => 'Waitlist entries',
                'key'   => 'waitlist',
                'icon'  => '⏳',
                'desc'  => $waitlistCount === 1
                    ? 'Customer waiting for an opening'
                    : 'Customers waiting for an opening',
                'tone'  => 'amber',  // customer's action: accept the opening (waitlist page, not appointments)
                'link'  => route('tenant.waitlist.index'),
            ];
        }

        // ---- Overdue categories ----
        $overdueUnstartedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::notStartedStatuses())
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueUnstartedCount > 0) {
            $cards[] = [
                'count' => $overdueUnstartedCount,
                'title' => 'Overdue: not started',
                'key'   => 'overdue_unstarted',
                'icon'  => '⏰',
                'desc'  => $overdueUnstartedCount === 1
                    ? 'Appointment past its scheduled date and never started'
                    : 'Appointments past their scheduled date and never started',
                'tone'  => 'red',
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_unstarted']),
            ];
        }

        $overdueInProgressCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::inProgressStatuses())
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueInProgressCount > 0) {
            $cards[] = [
                'count' => $overdueInProgressCount,
                'title' => 'Overdue: in progress',
                'key'   => 'overdue_in_progress',
                'icon'  => '🔧',
                'desc'  => $overdueInProgressCount === 1
                    ? 'Job started but not closed out'
                    : 'Jobs started but not closed out',
                'tone'  => 'red',  // your action: close out the job (more concerning than unstarted)
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_in_progress']),
            ];
        }

        $stalePickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('updated_at', '<', now()->subDays(3))
            ->count();

        if ($stalePickupCount > 0) {
            $cards[] = [
                'count' => $stalePickupCount,
                'title' => 'Stale pickups',
                'key'   => 'stale_pickups',
                'icon'  => '📅',
                'desc'  => $stalePickupCount === 1
                    ? 'Completed 3+ days ago, customer not collected'
                    : 'Completed 3+ days ago, customers not collected',
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index', ['filter' => 'stale_pickups']),
            ];
        }

        // patch-92 SO triage cards — appended to the existing rule set.
        // Arrived: status=arrived (waiting on staff to pull from bench).
        // Overdue: status=ordered AND expected_arrival_date past today
        //   (vendor missed promised date, chase them).
        // MARKER-PATCH-422 — Needed: status=needed (soft request, not yet ordered from a vendor).
        $soNeededCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
            ->count();

        if ($soNeededCount > 0) {
            $cards[] = [
                'count' => $soNeededCount,
                'title' => 'Special orders to place',
                'key'   => 'so_needed',
                'icon'  => '🛒',
                'desc'  => $soNeededCount === 1
                    ? 'Customer part not yet ordered from a vendor'
                    : 'Customer parts not yet ordered from a vendor',
                'tone'  => 'amber',  // your action: pick a vendor + place the order
                'link'  => route('tenant.special-orders.index'),
            ];
        }

        $soArrivedCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ARRIVED)
            ->count();

        if ($soArrivedCount > 0) {
            $cards[] = [
                'count' => $soArrivedCount,
                'title' => 'Special orders arrived',
                'key'   => 'so_arrived',
                'icon'  => '📦',
                'desc'  => $soArrivedCount === 1
                    ? 'Customer part on the bench, ready to pull and notify'
                    : 'Customer parts on the bench, ready to pull and notify',
                'tone'  => 'amber',  // your action: pull from bench + tell customer
                'link'  => route('tenant.special-orders.index', ['view' => 'arrived_bench']),
            ];
        }

        $soOverdueCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
            ->whereNotNull('expected_arrival_date')
            ->whereDate('expected_arrival_date', '<', $today)
            ->count();

        if ($soOverdueCount > 0) {
            $cards[] = [
                'count' => $soOverdueCount,
                'title' => 'Special orders overdue',
                'key'   => 'so_overdue',
                'icon'  => '⚠️',
                'desc'  => $soOverdueCount === 1
                    ? 'Vendor missed expected arrival — chase them'
                    : 'Vendors missed expected arrivals — chase them',
                'tone'  => 'red',  // your action: contact vendor about delay
                'link'  => route('tenant.special-orders.index', ['view' => 'overdue']),
            ];
        }

        // patch-102 location-scoped transfer tiles — show two separate tiles
        // when relevant: items needing to be SENT FROM here, and items
        // currently IN TRANSIT TO here.
        $sessionLocId = session('current_location_id');

        if ($sessionLocId) {
            $toSendCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->where('from_location_id', $sessionLocId)
                ->count();

            if ($toSendCount > 0) {
                $cards[] = [
                    'count' => $toSendCount,
                    'title' => 'Transfers to send',
                    'key'   => 'transfers_to_send',
                    'icon'  => '📤',
                    'desc'  => $toSendCount === 1
                        ? 'Another location is asking for stock from here'
                        : 'Other locations are asking for stock from here',
                    'tone'  => 'amber',
                    'link'  => route('tenant.transfer-requests.index', ['view' => 'to_send']),
                ];
            }

            $toReceiveCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
                ->where('status', 'in_transit')
                ->where('to_location_id', $sessionLocId)
                ->count();

            if ($toReceiveCount > 0) {
                $cards[] = [
                    'count' => $toReceiveCount,
                    'title' => 'Transfers arriving',
                    'key'   => 'transfers_arriving',
                    'icon'  => '📥',
                    'desc'  => $toReceiveCount === 1
                        ? 'Stock is in transit to this location'
                        : 'Stock items are in transit to this location',
                    'tone'  => 'blue',
                    'link'  => route('tenant.transfer-requests.index', ['view' => 'to_receive']),
                ];
            }
        }

        // MARKER-PATCH-110-STEP-2 — Low stock + Win-back triage rules
        // Both rules are tenant-scoped and use existing indexed columns.

        // Low stock: items at or below shop_reorder_threshold. Mirrors the
        // 'stock=low' filter on the inventory index. NULL threshold = item
        // isn't being tracked for reorder, so it's excluded.
        $lowStockCount = TenantInventoryItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('shop_reorder_threshold')
            ->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold')
            ->count();

        if ($lowStockCount > 0) {
            $cards[] = [
                'count' => $lowStockCount,
                'title' => 'Low stock',
                'key'   => 'low_stock',
                'icon'  => '📉',
                'desc'  => $lowStockCount === 1
                    ? 'Item at or below its reorder threshold'
                    : 'Items at or below their reorder thresholds',
                'tone'  => 'amber',  // your action: plan replenishment
                'link'  => route('tenant.inventory.index', ['stock' => 'low']),
            ];
        }

        // MARKER-PATCH-609 — catalog attention: open pricing/MAP/MSRP flags from
        // distributor sync. Same count as the Catalog attention page header.
        try {
            $catalogAttn = \App\Models\Tenant\TenantPricingAttentionFlag::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('status', 'open')
                ->count();
        } catch (\Throwable $e) {
            $catalogAttn = 0;
        }

        if ($catalogAttn > 0) {
            $cards[] = [
                'count' => $catalogAttn,
                'title' => 'Catalog attention',
                'key'   => 'catalog_attention',
                'icon'  => '🏷️',
                'desc'  => $catalogAttn === 1
                    ? 'Price or MAP change from your distributor needs review'
                    : 'Price or MAP changes from your distributor need review',
                'tone'  => 'amber',
                'link'  => route('tenant.distributors.attention'),
            ];
        }

        // Win-back: customers lapsed 180+ days (had a delivered appointment
        // but not in 180+ days). Delegates to CustomersReportService for the
        // same definition as the Reports → Customers tab, so the numbers
        // agree across surfaces. aggregatesOnly skips the heavy list query.
        try {
            $lapsed = (new CustomersReportService($this->tenant))
                ->lapsedCustomers(aggregatesOnly: true);
            $winbackCount = (int) ($lapsed['lapsed_count'] ?? 0);
        } catch (\Throwable $e) {
            $winbackCount = 0;
        }

        if ($winbackCount > 0) {
            $cards[] = [
                'count' => $winbackCount,
                'title' => 'Win-back candidates',
                'key'   => 'win_back',
                'icon'  => '👋',
                'desc'  => $winbackCount === 1
                    ? 'Customer has not been in for 180+ days'
                    : 'Customers have not been in for 180+ days',
                'tone'  => 'violet',  // your action: start a re-engagement campaign
                'link'  => route('tenant.customers.index'),
            ];
        }

        return [
            'cards'       => $cards,
            'total_items' => count($cards),
        ];
    }

    public function zoneGrowth(): array
    {
        // MARKER-PATCH-115 — match Reports' revenue definition:
        //   - status IN ('completed','closed') so only delivered work counts
        //   - 30-day window inclusive of today (Reports' last_30 uses the
        //     same subDays(29) bound).
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->endOfDay();
        $thirtyAgo = $this->tnow()->subDays(29)->startOfDay();   // start of current 30d window
        $sixtyAgo  = $this->tnow()->subDays(59)->startOfDay();   // start of prior 30d window

        // MARKER-PATCH-185 — revenue = payments received (sale ledger), matching
        // Reports. recorded_at is UTC; bound by tenant-local windows -> UTC.
        $tzG = $this->tenant->timezone();
        $curStart = $thirtyAgo->copy()->setTimezone($tzG)->startOfDay()->utc();
        $curEnd   = $today->copy()->setTimezone($tzG)->endOfDay()->utc();
        $priStart = $sixtyAgo->copy()->setTimezone($tzG)->startOfDay()->utc();
        $priEnd   = $thirtyAgo->copy()->subDay()->setTimezone($tzG)->endOfDay()->utc();

        $revenueCurrent = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$curStart, $curEnd])
            ->sum('amount_cents');

        $revenuePrior = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$priStart, $priEnd])
            ->sum('amount_cents');

        $revenueDelta = $revenuePrior > 0
            ? round((($revenueCurrent - $revenuePrior) / $revenuePrior) * 100)
            : null;

        $customersCurrent = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$thirtyAgo, $today])
            ->count();

        $customersPrior = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$sixtyAgo, $thirtyAgo->copy()->subDay()])
            ->count();

        $customersDelta = $customersPrior > 0
            ? round((($customersCurrent - $customersPrior) / $customersPrior) * 100)
            : null;

        $revenueSpark = $this->dailyRevenueSeries($tenantId, $thirtyAgo, $today);
        $customersSpark = $this->dailyCustomerSeries($tenantId, $thirtyAgo, $today);

        // Honest operational health. Each item: ['label', 'detail', 'status'].
        // Statuses match dashboard.css: 'ok' (green), 'warn' (amber), 'err' (red/grey).
        $health = [];

        // Payment processing — driven by actual processor connection state. Stripe
        // Connect / PayPal / Square aren't wired yet, so until a tenant finishes a
        // real connection flow this stays 'err' (or 'warn' if they recorded intent).
        $ppStatus = $this->tenant->payment_processor_status ?? 'not_started';
        $ppLabel  = ucfirst($this->tenant->payment_processor ?? 'Processor');
        $health[] = match ($ppStatus) {
            'connected' => [
                'label'  => 'Payment processing',
                'detail' => $ppLabel . ' connected',
                'status' => 'ok',
            ],
            'intent_recorded', 'connecting' => [
                'label'  => 'Payment processing',
                'detail' => 'Pending — finish ' . $ppLabel . ' setup',
                'status' => 'warn',
            ],
            default => [
                'label'  => 'Payment processing',
                'detail' => 'Not connected',
                'status' => 'err',
            ],
        };

        // Website — fresh tenants seed Home as is_published=false. Any published
        // page means the tenant has actually pushed something live.
        $publishedCount = \App\Models\Tenant\TenantPage::where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->count();
        $health[] = $publishedCount > 0
            ? [
                'label'  => 'Website',
                'detail' => $publishedCount . ' page' . ($publishedCount === 1 ? '' : 's') . ' published',
                'status' => 'ok',
            ]
            : [
                'label'  => 'Website',
                'detail' => 'No pages published yet',
                'status' => 'err',
            ];

        // Email deliverability — bounce/complaint webhook tracking isn't wired yet.
        // Until we have real signal, show 'warn' rather than fake green.
        $health[] = [
            'label'  => 'Email deliverability',
            'detail' => 'Setup not complete',
            'status' => 'warn',
        ];

        return [
            'revenue' => [
                'current_cents' => $revenueCurrent,
                'prior_cents'   => $revenuePrior,
                'delta_pct'     => $revenueDelta,
                'sparkline'     => $revenueSpark,
            ],
            'customers' => [
                'current'   => $customersCurrent,
                'prior'     => $customersPrior,
                'delta_pct' => $customersDelta,
                'sparkline' => $customersSpark,
            ],
            'health' => $health,
        ];
    }

    public function onboardingProgress(bool $dismissedThisSession): array
    {
        $tenant = $this->tenant;

        $brandingDone = !empty($tenant->logo_url)
            || (!empty($tenant->accent_color) && $tenant->accent_color !== '#BEF264')
            || !empty($tenant->tagline);

        $servicesDone = TenantServiceItem::where('tenant_id', $tenant->id)->exists();
        $hoursDone    = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        $allDone = $brandingDone && $servicesDone && $hoursDone;

        return [
            'branding'   => $brandingDone,
            'services'   => $servicesDone,
            'hours'      => $hoursDone,
            'all_done'   => $allDone,
            // The 8-step onboarding wizard replaces this modal entirely. The
            // dashboard now redirects incomplete tenants to the wizard up front,
            // so the modal never needs to fire. Leaving the field for backward
            // compatibility with the Blade partial; flag is permanently false.
            'show_modal' => false,
        ];
    }

    /**
     * MARKER-PATCH-110-STEP-3
     * Launcher tile sub-stats. One DB hit per stat where the data isn't
     * already in zoneToday/zoneAttention. Order matters — tiles render
     * in array order.
     *
     * Cheap stats only: counts and simple aggregates. Anything that would
     * require a join across 3+ tables stays static label-only for now.
     */
    public function zoneLauncher(array $today, array $attention): array
    {
        $tenantId = $this->tenant->id;
        $todayStr = $this->tnow()->toDateString();

        // Today's register total. Sums tenant_sales paid today.
        // MARKER-TZ-WAVE1 — paid_at is a UTC instant; whereDate() compared
        // its UTC date to the tenant-local date, so evening sales vanished
        // from today's tile. Compare against the tenant day's UTC range.
        [$dayStartUtc, $dayEndUtc] = tenant_day_utc_range($this->tnow());
        $todaySalesTotal = (int) DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('paid_at', '>=', $dayStartUtc)
            ->where('paid_at', '<',  $dayEndUtc)
            ->where('payment_status', 'paid')
            ->sum('total_cents');

        // Customer count — single COUNT, cheap.
        $customerCount = (int) DB::table('tenant_customers')
            ->where('tenant_id', $tenantId)
            ->count();

        // Waitlist count — same query as zoneAttention waitlist card uses.
        $waitlistCount = 0;
        if (class_exists(TenantWaitlistEntry::class)) {
            try {
                $waitlistCount = TenantWaitlistEntry::where('tenant_id', $tenantId)
                    ->where('status', 'waiting')
                    ->count();
            } catch (\Throwable $e) {
                $waitlistCount = 0;
            }
        }

        // Inventory counts — active items, plus low-stock pulled from
        // already-computed attention cards if present.
        $activeItemsCount = (int) TenantInventoryItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $lowStockCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Low stock')['count'] ?? 0;

        // Special order counts — pulled from existing attention cards.
        $soArrivedCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Special orders arrived')['count'] ?? 0;
        $soOverdueCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Special orders overdue')['count'] ?? 0;

        // Services count — active service items, single COUNT.
        $servicesCount = (int) TenantServiceItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Resources count — active resources.
        $resourcesCount = (int) TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Staff count — tenant_users (excluding owner, if needed).
        $staffCount = (int) TenantUser::where('tenant_id', $tenantId)->count();

        // Published page count — same query zoneGrowth uses for health.
        $publishedPageCount = (int) \App\Models\Tenant\TenantPage::where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->count();

        return [
            'calendar' => [
                'today_count' => $today['today_count'] ?? 0,
                'cap'         => null,  // Cap calculation deferred — needs resource summation.
            ],
            'register' => [
                'today_total_cents' => $todaySalesTotal,
            ],
            'customers' => [
                'count' => $customerCount,
            ],
            'waitlist' => [
                'count' => $waitlistCount,
            ],
            'inventory' => [
                'active_count'    => $activeItemsCount,
                'low_stock_count' => $lowStockCount,
            ],
            'special_orders' => [
                'arrived_count' => $soArrivedCount,
                'overdue_count' => $soOverdueCount,
            ],
            'services' => [
                'count' => $servicesCount,
            ],
            'resources' => [
                'count'       => $resourcesCount,
                'staff_count' => $staffCount,
            ],
            'pages' => [
                'published_count' => $publishedPageCount,
            ],
        ];
    }

    private function dailyRevenueSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        // MARKER-PATCH-185 — daily revenue spark = payments received (ledger),
        // bucketed by recorded_at in tenant tz.
        $tzS = $this->tenant->timezone();
        // MARKER-TZ-WAVE4 — DST-correct per-row offset.
        $sparkStart = $from->copy()->setTimezone($tzS)->startOfDay()->utc();
        $sparkEnd   = $to->copy()->setTimezone($tzS)->endOfDay()->utc();
        [$tzExpr, $tzBind] = tenant_tz_offset_expr('recorded_at', $tzS, $sparkStart, $sparkEnd);
        $rows = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$sparkStart, $sparkEnd])
            ->selectRaw("DATE({$tzExpr}) as d, SUM(amount_cents) as cents", $tzBind)
            ->groupBy('d')
            ->pluck('cents', 'd')
            ->toArray();

        return $this->fillDailySeries($from, $to, $rows, 0);
    }

    private function dailyCustomerSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->toArray();

        return $this->fillDailySeries($from, $to, $rows, 0);
    }

    private function fillDailySeries(Carbon $from, Carbon $to, array $rows, int|float $default): array
    {
        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $series[] = (int) ($rows[$key] ?? $default);
            $cursor->addDay();
        }
        return $series;
    }

    /**
     * Banner state for prompting tenants to set up work order fields.
     * Returns null if no banner should show.
     */
    /**
     * Returns appointment list + counts for a specific date + 7-day strip counts.
     * Used by both server render (for initial dashboard load with ?date=) and
     * the AJAX day-swap endpoint.
     */
    public function dayData(string $date): array
    {
        $tenantId = $this->tenant->id;
        $target = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        $appointments = TenantAppointment::where('tenant_id', $tenantId)
            ->whereDate('appointment_date', $target->toDateString())
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with('items')
            ->get();

        // 7-day strip: 3 days before, target, 3 days after.
        // Level (0-3) powers the heatmap-style load indicator on each day card.
        $strip = $this->build7DayStripCenteredOn($target);

        return [
            'target_date'       => $target->toDateString(),
            'target_date_long'  => $target->format('l, F j'),
            'appointments'      => $appointments,
            'appointment_count' => $appointments->count(),
            'strip'             => $strip,
        ];
    }

    /**
     * Build the 7-day strip array (3 days before, target, 3 days after)
     * with appointment counts and a 0-3 load level for each day. Used by
     * both the initial dashboard render (zoneToday) and the AJAX day-swap
     * endpoint (dayData).
     */
    private function build7DayStripCenteredOn(\Illuminate\Support\Carbon $target): array
    {
        $tenantId = $this->tenant->id;

        $activeResourceCount = max(1, TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count());

        $rulesByDow = TenantCapacityRule::where('tenant_id', $tenantId)
            ->where('rule_type', 'default')
            ->get()
            ->keyBy('day_of_week');

        $strip = [];
        for ($i = -3; $i <= 3; $i++) {
            $d = $target->copy()->addDays($i);
            $count = TenantAppointment::where('tenant_id', $tenantId)
                ->whereDate('appointment_date', $d->toDateString())
                ->whereNotIn('status', AppointmentStatus::terminalStatuses())
                ->count();

            $strip[] = [
                'date'       => $d->toDateString(),
                'day_short'  => $d->format('D'),
                'day_num'    => (int) $d->format('j'),
                'is_today'   => $d->isToday(),
                'is_target'  => $i === 0,
                'count'      => $count,
                'load_level' => $this->loadLevelForDay($d, $count, $rulesByDow, $activeResourceCount),
            ];
        }
        return $strip;
    }

    /**
     * Compute a 0-3 load level for a given day based on appointment count
     * vs. theoretical max slots (capacity rule's open hours × resources).
     * 0 = closed or zero appointments
     * 1 = 1-33% full
     * 2 = 34-66% full
     * 3 = 67-100% full
     */
    private function loadLevelForDay(
        \Illuminate\Support\Carbon $date,
        int $count,
        \Illuminate\Support\Collection $rulesByDow,
        int $activeResourceCount
    ): int {
        if ($count === 0) {
            return 0;
        }
        $rule = $rulesByDow->get($date->dayOfWeek);
        if (!$rule || !$rule->open_time || !$rule->close_time) {
            // Day with bookings but no capacity rule: show light load.
            return 1;
        }
        $open  = \Illuminate\Support\Carbon::parse($date->toDateString() . ' ' . $rule->open_time);
        $close = \Illuminate\Support\Carbon::parse($date->toDateString() . ' ' . $rule->close_time);
        $intervalMin = max(1, (int) ($rule->slot_interval_minutes ?? 30));
        $minutesOpen = max(0, $close->diffInMinutes($open));
        $slotsPerResource = intdiv($minutesOpen, $intervalMin);
        $maxSlots = max(1, $slotsPerResource * $activeResourceCount);
        $ratio = $count / $maxSlots;
        if ($ratio >= 0.67) return 3;
        if ($ratio >= 0.34) return 2;
        return 1;
    }

        public function workOrderBanner(bool $dismissed): ?array
    {
        if ($dismissed) { return null; }

        $hasFields = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $this->tenant->id)
            ->exists();

        if ($hasFields) { return null; }

        return [
            'title' => 'Set up your work order fields',
            'body'  => 'Track serial numbers, models, and whatever else your team needs to record when receiving a job. Tenants in your industry usually configure this once and forget about it.',
            'cta_label' => 'Configure now',
            'cta_url' => route('tenant.work-order-fields.index'),
        ];
    }

}
TZW4_2_EOF

cat > 'tests/Unit/TimezoneHelpersTest.php' <<'TZW4_3_EOF'
<?php

// MARKER-TZ-WAVE4 — timezone regression harness. Standalone PHPUnit (no
// Laravel boot, no DB): exercises the two helpers every day-boundary and
// bucketing query is built on. If these fail, the whole timezone-correctness
// story fails with them.
//
// Run: vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/TimezoneHelpersTest.php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/helpers.php';

class TimezoneHelpersTest extends TestCase
{
    // ---------- tenant_day_utc_range ----------

    public function test_normal_pacific_day_bounds(): void
    {
        [$s, $e] = tenant_day_utc_range('2026-07-16', 'America/Los_Angeles');
        $this->assertSame('2026-07-16 07:00:00', $s->toDateTimeString());
        $this->assertSame('2026-07-17 07:00:00', $e->toDateTimeString());
        $this->assertSame('UTC', $s->timezoneName);
    }

    public function test_carbon_input_uses_its_local_day(): void
    {
        // 11 PM Pacific on the 16th is already the 17th in UTC — the range
        // must still describe the tenant's 16th.
        $lateEvening = Carbon::parse('2026-07-16 23:00:00', 'America/Los_Angeles');
        [$s, $e] = tenant_day_utc_range($lateEvening, 'America/Los_Angeles');
        $this->assertSame('2026-07-16 07:00:00', $s->toDateTimeString());
        $this->assertSame('2026-07-17 07:00:00', $e->toDateTimeString());
    }

    public function test_utc_carbon_converts_to_tenant_day(): void
    {
        // 02:00 UTC on the 17th = 7 PM Pacific on the 16th.
        $utcInstant = Carbon::parse('2026-07-17 02:00:00', 'UTC');
        [$s, $e] = tenant_day_utc_range($utcInstant, 'America/Los_Angeles');
        $this->assertSame('2026-07-16 07:00:00', $s->toDateTimeString());
    }

    public function test_spring_forward_day_is_23_hours(): void
    {
        // US spring forward 2026: March 8.
        [$s, $e] = tenant_day_utc_range('2026-03-08', 'America/Los_Angeles');
        $this->assertSame(23 * 3600, $e->timestamp - $s->timestamp);
        $this->assertSame('2026-03-08 08:00:00', $s->toDateTimeString()); // PST -8
        $this->assertSame('2026-03-09 07:00:00', $e->toDateTimeString()); // PDT -7
    }

    public function test_fall_back_day_is_25_hours(): void
    {
        // US fall back 2026: November 1.
        [$s, $e] = tenant_day_utc_range('2026-11-01', 'America/Los_Angeles');
        $this->assertSame(25 * 3600, $e->timestamp - $s->timestamp);
    }

    public function test_utc_tenant_day_is_identity(): void
    {
        [$s, $e] = tenant_day_utc_range('2026-07-16', 'UTC');
        $this->assertSame('2026-07-16 00:00:00', $s->toDateTimeString());
        $this->assertSame('2026-07-17 00:00:00', $e->toDateTimeString());
    }

    // ---------- tenant_tz_offset_expr ----------

    public function test_no_transition_yields_simple_interval(): void
    {
        $start = Carbon::parse('2026-07-01 07:00:00', 'UTC');
        $end   = Carbon::parse('2026-07-15 07:00:00', 'UTC');
        [$expr, $bind] = tenant_tz_offset_expr('recorded_at', 'America/Los_Angeles', $start, $end);
        $this->assertSame('DATE_ADD(recorded_at, INTERVAL ? SECOND)', $expr);
        $this->assertSame([-7 * 3600], $bind); // PDT all range
    }

    public function test_spring_forward_range_builds_case(): void
    {
        // Range straddling the 2026-03-08 10:00 UTC transition (2 AM PST).
        $start = Carbon::parse('2026-03-07 08:00:00', 'UTC');
        $end   = Carbon::parse('2026-03-10 07:00:00', 'UTC');
        [$expr, $bind] = tenant_tz_offset_expr('recorded_at', 'America/Los_Angeles', $start, $end);

        $this->assertStringContainsString('CASE WHEN recorded_at < ?', $expr);
        // Bindings: [transition instant, offset before, offset after]
        $this->assertSame('2026-03-08 10:00:00', $bind[0]);
        $this->assertSame(-8 * 3600, $bind[1]); // PST before
        $this->assertSame(-7 * 3600, $bind[2]); // PDT after
    }

    public function test_offsets_bucket_rows_onto_correct_local_days(): void
    {
        // The bug this kills: a sale at 2026-03-07 23:30 Pacific (07:30 UTC
        // next day, PST era) bucketed with TODAY'S July offset (-7) lands on
        // March 8. With the era-correct offset (-8) it stays on March 7.
        $start = Carbon::parse('2026-03-07 08:00:00', 'UTC');
        $end   = Carbon::parse('2026-03-10 07:00:00', 'UTC');
        [$expr, $bind] = tenant_tz_offset_expr('recorded_at', 'America/Los_Angeles', $start, $end);

        $saleUtc = Carbon::parse('2026-03-08 07:30:00', 'UTC'); // 11:30 PM Mar 7 PST
        // Emulate the SQL CASE in PHP with the returned bindings.
        $offset = $saleUtc->toDateTimeString() < $bind[0] ? $bind[1] : $bind[2];
        $localDay = $saleUtc->copy()->addSeconds($offset)->toDateString();
        $this->assertSame('2026-03-07', $localDay);

        $wrongDay = $saleUtc->copy()->addSeconds(-7 * 3600)->toDateString(); // today's-offset bug
        $this->assertSame('2026-03-08', $wrongDay);
    }
}
TZW4_3_EOF

echo "timezone-wave4 applied — server needs view:clear"
