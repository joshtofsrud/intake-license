<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCapacityRule;
use App\Services\BookingService;
use App\Support\AppointmentStatus;
use App\Support\Dashboard\BlockAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-DASH-STUDIO: the figures the Studio dashboard draws, in one pass.
 *
 * Four tiles (bookings, sales, work orders, online), the right rail (a month
 * of how busy each day is, and today's run of work) and the queue of jobs in
 * the shop. Each part is only worked out for people whose role can open the
 * area it comes from, and appointments follow the location picked in the
 * sidebar when the shop has more than one.
 *
 * Time-slot shops get a timeline with a "Next up"; drop-off (capacity) shops
 * have no times, so their rail says how full today is and lists the drop-offs,
 * with the oldest one not started as "Next up".
 */
class DashboardStudioData
{
    private Carbon $now;
    private string $tz;
    private ?string $loc;
    private string $mode;

    public function __construct(private readonly Tenant $tenant, private readonly BlockAccess $see)
    {
        $this->tz   = $tenant->timezone();
        $this->now  = Carbon::now($this->tz);
        $this->mode = ($tenant->booking_mode ?? 'drop_off') === 'time_slots' ? 'slots' : 'capacity';
        // the sidebar's location, when the shop has more than one; jobs with no location show everywhere
        $sessionLoc = session('current_location_id');
        $this->loc  = ($sessionLoc && $tenant->multi_location_enabled) ? (string) $sessionLoc : null;
    }

    public function build(): array
    {
        $schedule = $this->see->can('schedule');
        $money    = $this->see->can('reports');
        $todayAppts = $schedule ? $this->todayAppointments() : collect();

        $tiles = [];
        if ($schedule) {
            $tiles[] = $this->bookingsTile($todayAppts);
        }
        if ($money) {
            $tiles[] = $this->salesTile();
        }
        $queue = $schedule ? $this->queue() : null;
        if ($schedule) {
            $tiles[] = $this->jobsTile($queue);
        }
        if ($money) {
            $tiles[] = $this->onlineTile();
        }

        return [
            'mode'   => $this->mode,
            'assetS' => $this->tenant->asset_label_singular ?: 'item',
            'assetP' => $this->tenant->asset_label_plural ?: 'items',
            'tiles'  => $tiles,
            'rail'   => $schedule ? $this->rail($todayAppts) : null,
            'queue'  => $queue,
            'summary'=> $this->summary($todayAppts, $queue),
        ];
    }

    // ------------------------------------------------------------------ tiles

    private function bookingsTile(Collection $appts): array
    {
        $notStarted = AppointmentStatus::notStartedStatuses();
        $toCome = $appts->filter(function ($a) use ($notStarted) {
            if (! in_array($a->status, $notStarted, true)) return false;
            if ($this->mode === 'capacity' || ! $a->appointment_time) return true;
            return $this->at($a)->gte($this->now);
        })->count();

        $tile = [
            'key' => 'bookings', 'title' => 'Bookings today', 'tone' => 1,
            'value' => $appts->count(), 'pre' => '',
            'sub' => $appts->isEmpty() ? 'Nothing booked yet' : ($toCome ? $toCome . ' still to ' . ($this->mode === 'capacity' ? 'drop off' : 'arrive') : 'everyone is in'),
            'link' => route('tenant.calendar.index'),
            'say'  => 'Opening the calendar',
        ];

        if ($this->mode === 'slots') {
            // bookings by hour across the shop's open hours today (or the hours that have bookings)
            [$open, $close] = $this->hoursToday();
            $byHour = [];
            foreach ($appts as $a) {
                if (! $a->appointment_time) continue;
                $h = (int) substr((string) $a->appointment_time, 0, 2);
                $byHour[$h] = ($byHour[$h] ?? 0) + 1;
                $open = min($open, $h); $close = max($close, $h + 1);
            }
            $max = max(1, $byHour ? max($byHour) : 1);
            $bars = [];
            for ($h = $open; $h < $close; $h++) {
                $bars[] = ['h' => ($byHour[$h] ?? 0) / $max, 'n' => $byHour[$h] ?? 0, 'now' => $h === (int) $this->now->format('G'), 'label' => $this->hourLabel($h)];
            }
            $tile['chart'] = ['type' => 'bars', 'bars' => $bars, 'axis' => [$this->hourLabel($open), $this->hourLabel((int) floor(($open + $close) / 2)), $this->hourLabel($close)]];
        } else {
            $load = $this->dayLoad($this->now->toDateString(), 1)[0] ?? null;
            $used = (int) ($load['used'] ?? 0);
            $max  = $load['max'] ?? null;
            $tile['chart'] = ['type' => 'meter', 'used' => $used, 'max' => $max,
                'label' => $max ? $used . ' of ' . $max . ' spots taken' : $used . ' booked · no daily limit set'];
        }

        return $tile;
    }

    private function salesTile(): array
    {
        $dayStart = $this->now->copy()->startOfDay();
        $cur  = $this->paymentsByHour($dayStart);
        $prev = $this->paymentsByHour($dayStart->copy()->subWeek());
        $nowH = (int) $this->now->format('G');
        $sumTo = fn (array $by, int $h) => array_sum(array_filter($by, fn ($k) => $k <= $h, ARRAY_FILTER_USE_KEY));
        $today = $sumTo($cur, 23);
        $lastAtThisTime = $sumTo($prev, $nowH);

        // running totals through the day; this week's line stops at the current hour
        [$open, $close] = $this->hoursToday();
        $open = min($open, $cur ? min(array_keys($cur)) : $open);
        $close = max($close, $nowH + 1);
        $curPts = []; $prevPts = [];
        for ($h = $open; $h < $close; $h++) {
            $prevPts[] = $sumTo($prev, $h);
            if ($h <= $nowH) $curPts[] = $sumTo($cur, $h);
        }
        $pct = $lastAtThisTime > 0 ? (int) round(($today - $lastAtThisTime) / $lastAtThisTime * 100) : null;

        return [
            'key' => 'sales', 'title' => 'Sales today', 'tone' => 2,
            'value' => (int) round($today / 100), 'pre' => '$',
            'sub' => $pct === null ? ($today ? 'nothing by now last ' . $this->now->format('l') : 'no payments yet today') : (($pct >= 0 ? '+' : '−') . abs($pct) . '% vs last ' . $this->now->format('l')),
            'link' => route('tenant.reports.money'),
            'say'  => 'Opening Reports › Money',
            'chart' => ['type' => 'spark', 'cur' => $curPts, 'prev' => $prevPts, 'slots' => max(2, $close - $open), 'axis' => ['Open', 'Now']],
        ];
    }

    private function jobsTile(?array $queue): array
    {
        $c = $queue['counts'] ?? ['waiting' => 0, 'active' => 0, 'ready' => 0];
        $total = array_sum($c);

        return [
            'key' => 'jobs', 'title' => 'Work orders', 'tone' => 3,
            'value' => $total, 'pre' => '',
            'sub' => $total ? 'open right now' : 'nothing open — all caught up',
            'link' => route('tenant.appointments.index'),
            'say'  => 'Opening work orders',
            'chart' => ['type' => 'stack', 'parts' => [
                ['label' => 'Waiting', 'n' => $c['waiting']],
                ['label' => 'In progress', 'n' => $c['active']],
                ['label' => 'Ready', 'n' => $c['ready']],
            ]],
        ];
    }

    private function onlineTile(): array
    {
        $from = $this->now->copy()->startOfDay()->utc();
        $to   = $this->now->copy()->utc();
        $tid  = $this->tenant->id;
        $visitors = 0; $booked = 0;
        try {
            $row = DB::table('tenant_funnel_events')->where('tenant_id', $tid)->whereBetween('created_at', [$from, $to])
                ->selectRaw("COUNT(DISTINCT session_id) as v, COUNT(DISTINCT CASE WHEN event_type = 'booking_completed' THEN session_id END) as b")
                ->first();
            $visitors = (int) ($row->v ?? 0); $booked = (int) ($row->b ?? 0);
        } catch (\Throwable $e) {
            report($e);
        }
        $orders = null;
        if ($this->tenant->online_store_enabled) {
            $orders = (int) DB::table('tenant_orders')->where('tenant_id', $tid)->whereBetween('created_at', [$from, $to])->count();
        }
        $pct = $visitors > 0 ? (int) round($booked / $visitors * 100) : 0;

        return [
            'key' => 'online', 'title' => 'Online today', 'tone' => 4,
            'value' => $visitors, 'pre' => '',
            'sub' => $visitors
                ? Str::plural('visitor', $visitors) . ' · ' . $booked . ' booked' . ($orders !== null ? ' · ' . $orders . ' ' . Str::plural('order', $orders) : '')
                : 'no visitors yet today',
            'link' => route('tenant.reports.traffic'),
            'say'  => 'Opening Reports › Traffic',
            'chart' => ['type' => 'ring', 'pct' => min(100, $pct), 'label' => $visitors ? 'visit to booking' : 'share your booking link to start'],
        ];
    }

    // ------------------------------------------------------------------- rail

    private function rail(Collection $appts): array
    {
        $first = $this->now->copy()->startOfMonth();
        $last  = $this->now->copy()->endOfMonth();
        $q = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$first->toDateString(), $last->toDateString()])
            ->whereNotIn('status', AppointmentStatus::terminalStatuses());
        $this->scopeLoc($q);
        $counts = $q->selectRaw('DATE(appointment_date) as d, COUNT(*) as n, COALESCE(SUM(slot_weight), 0) as w')
            ->groupByRaw('DATE(appointment_date)')->get()->keyBy('d');

        $caps = $this->mode === 'capacity' ? collect($this->dayLoad($first->toDateString(), (int) $last->format('j')))->keyBy('date') : collect();
        $peak = max(1, (int) ($counts->max('n') ?? 1));
        $days = [];
        for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
            $k = $d->toDateString();
            $n = (int) ($counts[$k]->n ?? 0);
            $cap = $caps[$k] ?? null;
            $fill = $this->mode === 'capacity' && $cap && $cap['max'] ? min(1, ($cap['used'] ?? 0) / $cap['max']) : $n / $peak;
            $days[] = ['d' => (int) $d->format('j'), 'date' => $k, 'n' => $n, 'fill' => round($fill, 2),
                'closed' => $cap ? $cap['state'] === 'closed' : false, 'today' => $k === $this->now->toDateString(),
                'url' => route('tenant.calendar.index', ['date' => $k])];
        }

        $rail = [
            'month' => $this->now->format('F Y'),
            'lead'  => (int) $first->format('N') - 1, // Monday-first blanks
            'days'  => $days,
        ];

        if ($this->mode === 'slots') {
            $rail['timeline'] = $this->timeline($appts);
        } else {
            $load = $this->dayLoad($this->now->toDateString(), 1)[0] ?? null;
            $rail['capacity'] = ['used' => (int) ($load['used'] ?? 0), 'max' => $load['max'] ?? null];
            $rail['dropoffs'] = $this->dropoffs($appts);
        }

        return $rail;
    }

    private function timeline(Collection $appts): array
    {
        $rows = [];
        $nextId = null;
        foreach ($appts as $a) {
            if (! $a->appointment_time) continue;
            $at = $this->at($a);
            $done = AppointmentStatus::isDone($a->status);
            $active = in_array($a->status, AppointmentStatus::inProgressStatuses(), true);
            if ($nextId === null && ! $done && ! $active && $at->gte($this->now)) $nextId = $a->id;
            $rows[] = ['t' => $at, 'id' => $a->id, 'time' => $at->format('g:i'), 'title' => $this->serviceSummary($a),
                'who' => $this->who($a), 'state' => $done ? 'done' : ($active ? 'active' : 'todo'),
                'assets' => $this->assets($a), 'url' => route('tenant.appointments.show', $a->id)];
        }
        // pickups and drop-offs on the route, between the appointments
        try {
            foreach ((new TenantDeliveryService($this->tenant))->forDay($this->now) as $dl) {
                $at = Carbon::parse($dl->scheduled_at, 'UTC')->setTimezone($this->tz);
                $rows[] = ['t' => $at, 'id' => 'dl-' . $dl->id, 'time' => $at->format('g:i'),
                    'title' => ($dl->type === 'pickup' ? 'Pickup' : 'Drop-off'), 'who' => trim(($dl->customer?->first_name ?? '') . ' ' . ($dl->customer?->last_name ?? '')) ?: 'On the route',
                    'state' => $at->lt($this->now) ? 'done' : 'todo', 'assets' => [], 'url' => route('tenant.calendar.index'), 'route' => true];
            }
        } catch (\Throwable $e) {
            report($e);
        }
        usort($rows, fn ($x, $y) => $x['t'] <=> $y['t']);
        foreach ($rows as &$r) {
            if ($r['id'] === $nextId) $r['state'] = 'next';
            unset($r['t']);
        }
        unset($r);
        $left = count(array_filter($rows, fn ($r) => $r['state'] !== 'done'));

        return ['rows' => $rows, 'left' => $left, 'nextId' => $nextId];
    }

    private function dropoffs(Collection $appts): array
    {
        $notStarted = AppointmentStatus::notStartedStatuses();
        $rows = [];
        $next = null;
        foreach ($appts->sortBy('created_at') as $a) {
            $done = AppointmentStatus::isDone($a->status);
            $active = in_array($a->status, AppointmentStatus::inProgressStatuses(), true);
            if ($next === null && in_array($a->status, $notStarted, true)) $next = $a->id;
            $rows[] = ['id' => $a->id, 'title' => $this->serviceSummary($a), 'who' => $this->who($a),
                'state' => $done ? 'done' : ($active ? 'active' : 'todo'), 'status' => AppointmentStatus::label($a->status),
                'assets' => $this->assets($a), 'url' => route('tenant.appointments.show', $a->id)];
        }
        foreach ($rows as &$r) {
            if ($r['id'] === $next) $r['state'] = 'next';
        }
        unset($r);

        return ['rows' => $rows, 'left' => count(array_filter($rows, fn ($r) => $r['state'] !== 'done')), 'nextId' => $next];
    }

    // ------------------------------------------------------------------ queue

    /** Jobs in the shop: due or past and not started, in progress, or done and waiting to be collected. Oldest first. */
    private function queue(): array
    {
        $today = $this->now->toDateString();
        $q = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where(function ($w) use ($today) {
                $w->where(fn ($x) => $x->whereIn('status', AppointmentStatus::notStartedStatuses())->whereDate('appointment_date', '<=', $today))
                  ->orWhereIn('status', AppointmentStatus::inProgressStatuses())
                  ->orWhere(fn ($x) => $x->whereIn('status', AppointmentStatus::doneStatuses())->whereIn('payment_status', ['unpaid', 'partial']));
            });
        $this->scopeLoc($q);
        $all = $q->with(['customer', 'items', 'assets'])
            ->orderBy('appointment_date')->orderByRaw('appointment_time IS NULL, appointment_time')->orderBy('created_at')
            ->limit(200)->get();

        $counts = ['waiting' => 0, 'active' => 0, 'ready' => 0];
        $rows = [];
        foreach ($all as $a) {
            $g = AppointmentStatus::isDone($a->status) ? 'ready' : (in_array($a->status, AppointmentStatus::inProgressStatuses(), true) ? 'active' : 'waiting');
            $counts[$g]++;
            if (count($rows) >= 60) continue;
            $since = Carbon::parse($a->appointment_date->toDateString() . ' ' . ($a->appointment_time ?: '00:00:00'), $this->tz);
            $rows[] = [
                'id' => $a->id, 'group' => $g,
                'groupLabel' => ['waiting' => 'Waiting', 'active' => 'In progress', 'ready' => 'Ready'][$g],
                'who' => $this->who($a), 'initials' => $this->initials($a),
                'number' => $a->ra_number ?: Str::upper(Str::substr($a->id, 0, 6)),
                'title' => $this->serviceSummary($a),
                'age' => $this->age($since),
                'late' => $g === 'waiting' && $a->appointment_date->toDateString() < $today,
                'assets' => $this->assets($a),
                'notes' => Str::limit(trim((string) $a->staff_notes), 220),
                'total' => (int) $a->total_cents, 'due' => max(0, (int) $a->total_cents - (int) $a->paid_cents),
                'pay' => $a->payment_status,
                'url' => route('tenant.appointments.show', $a->id),
                'customerUrl' => $a->customer_id && $this->see->can('customers') ? route('tenant.customers.show', $a->customer_id) : null,
                'phone' => $a->customer_phone ?: ($a->customer->phone ?? null),
            ];
        }

        return ['rows' => $rows, 'counts' => $counts, 'more' => max(0, $all->count() - count($rows))];
    }

    private function summary(Collection $appts, ?array $queue): string
    {
        $bits = [];
        if ($this->see->can('schedule')) {
            $bits[] = $appts->count() ? $appts->count() . ' ' . Str::plural('booking', $appts->count()) : 'nothing booked today';
            $ready = $queue['counts']['ready'] ?? 0;
            if ($ready) $bits[] = $ready . ' ready for pickup';
        }
        if ($this->tenant->online_store_enabled && $this->see->can('orders')) {
            try {
                $toPack = (int) DB::table('tenant_orders')->where('tenant_id', $this->tenant->id)->whereIn('status', ['paid', 'processing'])->count();
                if ($toPack) $bits[] = $toPack . ' ' . Str::plural('order', $toPack) . ' to pack';
            } catch (\Throwable $e) {
            }
        }

        return $this->now->format('l, F j') . ($bits ? ' · ' . implode(', ', $bits) . '.' : '');
    }

    // ---------------------------------------------------------------- helpers

    private function todayAppointments(): Collection
    {
        $q = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereDate('appointment_date', $this->now->toDateString())
            ->whereNotIn('status', AppointmentStatus::terminalStatuses());
        $this->scopeLoc($q);

        return $q->orderByRaw('appointment_time IS NULL, appointment_time ASC')->orderBy('created_at')
            ->with(['customer', 'items', 'assets'])->get();
    }

    private function scopeLoc($q): void
    {
        if ($this->loc) {
            $q->where(fn ($w) => $w->where('location_id', $this->loc)->orWhereNull('location_id'));
        }
    }

    private function at(TenantAppointment $a): Carbon
    {
        return Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time, $this->tz);
    }

    private function who(TenantAppointment $a): string
    {
        $n = trim(($a->customer->first_name ?? $a->customer_first_name ?? '') . ' ' . ($a->customer->last_name ?? $a->customer_last_name ?? ''));

        return $n !== '' ? $n : 'Walk-in';
    }

    private function initials(TenantAppointment $a): string
    {
        $w = preg_split('/\s+/', $this->who($a));

        return Str::upper(Str::substr($w[0] ?? '', 0, 1) . Str::substr($w[1] ?? '', 0, 1)) ?: '·';
    }

    private function serviceSummary(TenantAppointment $a): string
    {
        $names = $a->items->pluck('item_name_snapshot')->filter()->values();
        if ($names->isEmpty()) return 'Appointment';

        return $names[0] . ($names->count() > 1 ? ' +' . ($names->count() - 1) : '');
    }

    /**
     * Each asset on a multi-asset booking, with its services and when they run.
     * Times come from the services' durations laid end to end from the start
     * time; assets have no start times of their own. Fewer than two: none.
     */
    private function assets(TenantAppointment $a): array
    {
        if ($a->assets->count() < 2) return [];
        $cursor = $a->appointment_time ? $this->at($a) : null;
        $out = [];
        foreach ($a->assets->sortBy('sort_order') as $as) {
            $items = $a->items->where('appointment_asset_id', $as->id);
            $mins = (int) $items->sum(fn ($i) => $i->duration_minutes_override ?? $i->duration_minutes_snapshot ?? 0);
            $when = null;
            if ($cursor && $mins > 0) {
                $end = $cursor->copy()->addMinutes($mins);
                $when = $cursor->format('g:i') . '–' . $end->format('g:i A');
                $cursor = $end;
            } elseif ($mins > 0) {
                $when = $mins >= 60 ? floor($mins / 60) . 'h' . ($mins % 60 ? ' ' . ($mins % 60) . 'm' : '') : $mins . ' min';
            }
            $out[] = ['name' => trim($as->asset_name_snapshot . ($as->identifier_snapshot ? ' · ' . $as->identifier_snapshot : '')),
                'services' => $items->pluck('item_name_snapshot')->filter()->implode(', ') ?: 'No services yet',
                'when' => $when];
        }

        return $out;
    }

    private function age(Carbon $since): string
    {
        $m = max(0, (int) $since->diffInMinutes($this->now, false));
        if ($m < 60) return $m . 'm';
        if ($m < 60 * 24) return floor($m / 60) . 'h';

        return floor($m / 1440) . 'd';
    }

    private function hourLabel(int $h): string
    {
        $h = max(0, min(24, $h));

        return ((($h + 11) % 12) + 1) . ($h < 12 || $h === 24 ? 'a' : 'p');
    }

    /** Today's opening hours as whole hours, from the shop's hours (8–6 when none are set). */
    private function hoursToday(): array
    {
        $rules = TenantCapacityRule::where('tenant_id', $this->tenant->id)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('rule_type', 'default')->where('day_of_week', $this->now->dayOfWeek))
                ->orWhere(fn ($x) => $x->where('rule_type', 'override')->whereDate('specific_date', $this->now->toDateString())))
            ->get();
        $r = $rules->firstWhere('rule_type', 'override') ?? $rules->first();
        if (! $r || $r->is_closed || ! $r->open_time || ! $r->close_time) return [8, 18];

        return [(int) substr((string) $r->open_time, 0, 2), (int) ceil(((int) substr((string) $r->close_time, 0, 2)) + ((int) substr((string) $r->close_time, 3, 2)) / 60)];
    }

    /** Payments recorded on a day, by local hour (cents). Refunds are recorded negative, so they come off. */
    private function paymentsByHour(Carbon $dayStart): array
    {
        $from = $dayStart->copy()->utc();
        $to = $dayStart->copy()->endOfDay()->utc();
        $offset = $dayStart->format('P');
        $rows = DB::table('tenant_sale_payments')->where('tenant_id', $this->tenant->id)->whereBetween('recorded_at', [$from, $to])
            ->selectRaw("HOUR(CONVERT_TZ(recorded_at, '+00:00', ?)) as h, SUM(amount_cents) as c", [$offset])
            ->groupByRaw('h')->get();   // by alias: a bound expression doesn't match under ONLY_FULL_GROUP_BY
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->h] = (int) $r->c;
        }

        return $out;
    }

    private function dayLoad(string $start, int $days): array
    {
        try {
            return app(BookingService::class)->dayLoad($this->tenant, $start, $days);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
