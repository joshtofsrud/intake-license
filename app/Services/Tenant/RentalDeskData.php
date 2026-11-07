<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalCategory;
use App\Models\Tenant\TenantRentalExtensionOffer;
use App\Models\Tenant\TenantRentalUnit;
use App\Services\RentalExtensionOfferService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-RENTAL-DESK-V2: everything the rental desk draws, in the Studio
 * design: four tiles, the month shaded by how much of the fleet is booked,
 * today's handovers (pickups and returns, overdue first), the bookings list
 * with each booking's details, and the fleet by category.
 *
 * Every figure is worked out from rentals and the payment ledger, as before.
 * Extension offers are read for all listed rentals in one query (the old desk
 * asked once per row); the eligibility check only runs for rentals due back
 * today, the only ones that can be extended.
 */
class RentalDeskData
{
    private Carbon $now;      // UTC
    private Carbon $local;    // shop time
    private string $tz;

    public function __construct(private readonly Tenant $tenant)
    {
        $this->tz    = $tenant->timezone();
        $this->now   = now();
        $this->local = Carbon::now($this->tz);
    }

    public function build(): array
    {
        $tid = $this->tenant->id;
        $todayStart = $this->local->copy()->startOfDay()->utc();
        $todayEnd   = $this->local->copy()->endOfDay()->utc();

        $rentable = TenantRentalUnit::where('tenant_id', $tid)->whereNull('archived_at')
            ->where('status', '!=', 'retired')->where('available_for_rent', true)->count();

        // the rentals the desk is about: out now, or starting within a week, or handed over / back today
        $rentals = TenantRental::where('tenant_id', $tid)
            ->where(function ($q) use ($todayStart, $todayEnd) {
                $q->where('status', 'out')
                  ->orWhere(fn ($x) => $x->where('status', 'reserved')->where('starts_at', '<', $this->now->copy()->addDays(7)))
                  ->orWhere(fn ($x) => $x->where('status', 'returned')->whereBetween('returned_at', [$todayStart, $todayEnd]));
            })
            ->with(['customer:id,first_name,last_name,phone', 'lines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('starts_at')
            ->limit(300)
            ->get();

        $out      = $rentals->where('status', 'out');
        $overdue  = $out->filter(fn ($r) => $r->due_at && $r->due_at->lt($this->now));
        $dueToday = $out->filter(fn ($r) => $r->due_at && $r->due_at->between($todayStart, $todayEnd));
        $backToday = $rentals->where('status', 'returned');
        $pickToday = $rentals->where('status', 'reserved')->filter(fn ($r) => $r->starts_at && $r->starts_at->between($todayStart, $todayEnd));
        $outToday  = $out->filter(fn ($r) => $r->starts_at && $r->starts_at->between($todayStart, $todayEnd));
        $unitsOut  = (int) $out->sum(fn ($r) => max(1, $r->lines->where('kind', 'unit')->count()));

        $offers = $this->offers($rentals->pluck('id'));
        $nextPick = $rentals->where('status', 'reserved')->filter(fn ($r) => $r->starts_at && $r->starts_at->gte($this->now))->sortBy('starts_at')->first();

        [$mtd, $prevSpan, $daily, $prevDaily] = $this->revenue();
        $pct = $prevSpan > 0 ? (int) round(($mtd - $prevSpan) / $prevSpan * 100) : null;
        $holds = TenantRental::where('tenant_id', $tid)->where('deposit_status', 'authorized')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(deposit_hold_cents), 0) as c')->first();

        $tiles = [
            ['key' => 'out', 'title' => 'Going out today', 'tone' => 1, 'value' => $pickToday->count() + $outToday->count(), 'pre' => '',
             'sub' => $nextPick ? 'next at ' . $nextPick->starts_at->copy()->setTimezone($this->tz)->format('g:i A') : ($pickToday->count() + $outToday->count() ? 'all handed over' : 'no pickups today'),
             'link' => route('tenant.rentals.bookings.index'), 'chart' => $this->pickupBars($pickToday->concat($outToday))],
            ['key' => 'back', 'title' => 'Coming back today', 'tone' => 2, 'value' => $dueToday->count() + $backToday->count() + $overdue->diff($dueToday)->count(), 'pre' => '',
             'sub' => trim(($overdue->count() ? $overdue->count() . ' overdue · ' : '') . $backToday->count() . ' back already', ' ·'),
             'link' => route('tenant.rentals.bookings.index', ['tab' => 'out']),
             'chart' => ['type' => 'stack', 'parts' => [
                 ['label' => 'Back', 'n' => $backToday->count()],
                 ['label' => 'Due later', 'n' => $dueToday->diff($overdue)->count()],
                 ['label' => 'Overdue', 'n' => $overdue->count(), 'bad' => true],
             ]]],
            ['key' => 'fleet', 'title' => 'Out right now', 'tone' => 3, 'value' => $unitsOut, 'pre' => '',
             'sub' => 'of ' . $rentable . ' rentable ' . Str::plural('unit', $rentable), 'link' => route('tenant.rentals.bookings.index', ['tab' => 'out']),
             'chart' => ['type' => 'ring', 'pct' => $this->utilization($rentable), 'label' => 'used over the last 7 days']],
            ['key' => 'money', 'title' => 'Rental revenue · ' . $this->local->format('F'), 'tone' => 4, 'value' => (int) round($mtd / 100), 'pre' => '$',
             'sub' => $pct === null ? 'from the payment ledger' : (($pct >= 0 ? '+' : '−') . abs($pct) . '% vs ' . $this->local->copy()->subMonthNoOverflow()->format('F')),
             'link' => route('tenant.reports.money'),
             'chart' => ['type' => 'spark', 'cur' => $daily, 'prev' => $prevDaily, 'slots' => max(2, (int) $this->local->daysInMonth),
                 'axis' => ['$' . number_format(((int) ($holds->c ?? 0)) / 100) . ' in deposits held · ' . (int) ($holds->n ?? 0) . ' ' . Str::plural('hold', (int) ($holds->n ?? 0)), '']]],
        ];

        return [
            'summary'   => $this->local->format('l, F j') . ' · ' . ($pickToday->count() + $outToday->count()) . ' going out, ' . ($dueToday->count() + $backToday->count()) . ' coming back' . ($overdue->count() ? ', ' . $overdue->count() . ' overdue' : '') . '.',
            'tiles'     => $tiles,
            'calendar'  => $this->calendar($rentable),
            'handovers' => $this->handovers($rentals, $todayStart, $todayEnd),
            'bookings'  => $this->bookings($rentals, $offers, $dueToday),
            'fleet'     => $this->fleet(),
            'rentable'  => $rentable,
        ];
    }

    // ---------------------------------------------------------------- parts

    private function pickupBars(Collection $list): array
    {
        $byHour = [];
        foreach ($list as $r) {
            $h = (int) $r->starts_at->copy()->setTimezone($this->tz)->format('G');
            $byHour[$h] = ($byHour[$h] ?? 0) + 1;
        }
        $open = min(8, $byHour ? min(array_keys($byHour)) : 8);
        $close = max(18, $byHour ? max(array_keys($byHour)) + 1 : 18);
        $max = max(1, $byHour ? max($byHour) : 1);
        $nowH = (int) $this->local->format('G');
        $bars = [];
        for ($h = $open; $h < $close; $h++) {
            $bars[] = ['h' => ($byHour[$h] ?? 0) / $max, 'n' => $byHour[$h] ?? 0, 'now' => $h === $nowH, 'label' => $this->hour($h)];
        }

        return ['type' => 'bars', 'bars' => $bars, 'axis' => [$this->hour($open), $this->hour((int) floor(($open + $close) / 2)), $this->hour($close)]];
    }

    /** Today's pickups and returns in time order; overdue returns first. Next up is the first one still to happen. */
    private function handovers(Collection $rentals, Carbon $start, Carbon $end): array
    {
        $rows = [];
        foreach ($rentals as $r) {
            $who = $this->who($r);
            $units = $r->lines->where('kind', 'unit');
            $what = $this->unitsLabel($units);
            if ($r->status === 'reserved' && $r->starts_at && $r->starts_at->between($start, $end)) {
                $rows[] = ['t' => $r->starts_at, 'dir' => 'out', 'who' => $who, 'what' => $what, 'state' => 'todo', 'late' => false, 'url' => route('tenant.rentals.bookings.checkout.flow', $r->id)];
            }
            if ($r->status === 'out' && $r->starts_at && $r->starts_at->between($start, $end)) {
                $rows[] = ['t' => $r->starts_at, 'dir' => 'out', 'who' => $who, 'what' => $what, 'state' => 'done', 'late' => false, 'url' => route('tenant.rentals.bookings.show', $r->id)];
            }
            if ($r->status === 'out' && $r->due_at && $r->due_at->lte($end)) {
                $late = $r->due_at->lt($this->now);
                $rows[] = ['t' => $r->due_at, 'dir' => 'back', 'who' => $who, 'what' => $late ? $what . ' · ' . $this->lateBy($r->due_at) . ' overdue' : $what, 'state' => 'todo', 'late' => $late, 'url' => route('tenant.rentals.bookings.return.flow', $r->id)];
            }
            if ($r->status === 'returned' && $r->returned_at && $r->returned_at->between($start, $end)) {
                $rows[] = ['t' => $r->returned_at, 'dir' => 'back', 'who' => $who, 'what' => $what, 'state' => 'done', 'late' => false, 'url' => route('tenant.rentals.bookings.show', $r->id)];
            }
        }
        usort($rows, fn ($a, $b) => [$b['late'], $a['t']] <=> [$a['late'], $b['t']]);
        $next = false;
        foreach ($rows as &$row) {
            if (! $next && $row['state'] === 'todo' && ! $row['late'] && $row['t']->gte($this->now)) {
                $row['state'] = 'next';
                $next = true;
            }
            $row['time'] = $row['late'] && $row['t']->lt($start) ? $row['t']->copy()->setTimezone($this->tz)->format('D') : $row['t']->copy()->setTimezone($this->tz)->format('g:i A');
            unset($row['t']);
        }
        unset($row);

        return ['rows' => $rows, 'left' => count(array_filter($rows, fn ($r) => $r['state'] !== 'done'))];
    }

    private function bookings(Collection $rentals, array $offers, Collection $dueToday): array
    {
        $list = $rentals->whereIn('status', ['out', 'reserved'])
            ->sortBy(function ($r) {
                $late = $r->status === 'out' && $r->due_at && $r->due_at->lt($this->now);
                $when = $r->status === 'out' ? $r->due_at : $r->starts_at;

                return ($late ? '0' : '1') . ($when ? $when->format('YmdHis') : '9');
            })->values();

        $eligible = [];
        if ($this->tenant->rental_extensions_enabled) {
            $svc = app(RentalExtensionOfferService::class);
            foreach ($dueToday as $r) {
                if (isset($offers[$r->id])) continue;
                try {
                    $why = null;
                    $eligible[$r->id] = (bool) $svc->eligibility($this->tenant, $r, $why);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $counts = ['due' => 0, 'late' => 0, 'going' => 0, 'out' => 0];
        $rows = [];
        foreach ($list as $r) {
            $late = $r->status === 'out' && $r->due_at && $r->due_at->lt($this->now);
            $dueSoon = $r->status === 'out' && ! $late && $r->due_at && $r->due_at->lte($this->local->copy()->endOfDay()->utc());
            $group = $late ? 'late' : ($r->status === 'reserved' ? 'going' : ($dueSoon ? 'due' : 'out'));
            $counts[$group]++;
            if ($group === 'due' || $group === 'late') $counts['out']++;
            if (count($rows) >= 60) continue;

            $units = $r->lines->where('kind', 'unit')->values();
            $win = $this->window($r->starts_at, $r->due_at);
            $offer = $offers[$r->id] ?? null;
            $ext = $offer === 'paid' ? 'Extended' : ($offer === 'sent' ? 'Extension offered' : (($eligible[$r->id] ?? false) ? 'Can be extended' : null));
            $status = $late ? $this->lateBy($r->due_at) . ' overdue' : ($r->status === 'reserved' ? 'Reserved' : ($dueSoon ? 'Due ' . $r->due_at->copy()->setTimezone($this->tz)->format('g:i A') : 'Out'));
            $action = $r->status === 'reserved'
                ? ['label' => 'Check out', 'url' => route('tenant.rentals.bookings.checkout.flow', $r->id), 'primary' => true]
                : ($late || $dueSoon
                    ? ['label' => 'Start return', 'url' => route('tenant.rentals.bookings.return.flow', $r->id), 'primary' => $late]
                    : ['label' => 'Open', 'url' => route('tenant.rentals.bookings.show', $r->id), 'primary' => false]);

            $kv = [];
            $kv[] = ['Agreement', $r->agreement_signed_at ? 'Signed ' . $r->agreement_signed_at->copy()->setTimezone($this->tz)->format('M j') : 'Not signed yet'];
            $kv[] = ['Deposit', $r->deposit_hold_cents ? ('$' . number_format($r->deposit_hold_cents / 100) . ' ' . ($r->deposit_status === 'authorized' ? 'held' : ($r->deposit_status ?: 'due at pickup'))) : 'None'];
            if ($ext) $kv[] = ['Extension', $ext];
            if ($r->total_cents) $kv[] = ['Total', '$' . number_format($r->total_cents / 100, 2) . ($r->paid_cents < $r->total_cents ? ' · $' . number_format(($r->total_cents - $r->paid_cents) / 100, 2) . ' due' : ' · paid')];
            if (trim((string) $r->notes) !== '') $kv[] = ['Notes', Str::limit(trim($r->notes), 200)];

            $acts = [$action + ['primary' => true]];
            $acts[0]['primary'] = true;
            if ($action['label'] !== 'Open') $acts[] = ['label' => 'Open booking', 'url' => route('tenant.rentals.bookings.show', $r->id)];
            $phone = $r->customer->phone ?? null;
            if ($phone) $acts[] = ['label' => 'Text ' . Str::before($this->who($r), ' '), 'url' => 'sms:' . preg_replace('/[^\d+]/', '', $phone)];

            $rows[] = [
                'id' => $r->id, 'group' => $group, 'who' => $this->who($r), 'initials' => $this->initials($r),
                'number' => $r->rental_number ?: Str::upper(Str::substr($r->id, 0, 6)),
                'first' => $units[0]->name_snapshot ?? 'No unit', 'win' => $win, 'status' => $status, 'late' => $late,
                'action' => $action,
                'assets' => $units->count() > 1 ? $units->map(fn ($u) => ['name' => $u->name_snapshot, 'services' => $this->rateLabel($u), 'when' => $win])->all() : [],
                // what the detail panel draws
                'title' => $this->unitsLabel($units), 'tags' => array_values(array_filter([$status, $win])), 'kv' => $kv, 'actions' => $acts,
                'units' => $units->map(fn ($u) => ['name' => $u->name_snapshot, 'note' => $this->rateLabel($u)])->all(),
            ];
        }

        return ['rows' => $rows, 'counts' => $counts, 'more' => max(0, $list->count() - count($rows))];
    }

    private function fleet(): array
    {
        $tid = $this->tenant->id;
        $cats = TenantRentalCategory::where('tenant_id', $tid)->whereNull('archived_at')->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $agg = TenantRentalUnit::where('tenant_id', $tid)->whereNull('archived_at')->where('status', '!=', 'retired')
            ->selectRaw("category_id, COUNT(*) as total, SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maint")
            ->groupBy('category_id')->get()->keyBy('category_id');
        $outBy = DB::table('tenant_rental_lines')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_lines.rental_id')
            ->join('tenant_rental_units', 'tenant_rental_units.id', '=', 'tenant_rental_lines.unit_id')
            ->where('tenant_rentals.tenant_id', $tid)->where('tenant_rentals.status', 'out')->where('tenant_rental_lines.kind', 'unit')
            ->selectRaw('tenant_rental_units.category_id as c, COUNT(DISTINCT tenant_rental_units.id) as n')
            ->groupBy('tenant_rental_units.category_id')->pluck('n', 'c');

        return $cats->map(function ($c) use ($agg, $outBy) {
            $total = (int) ($agg[$c->id]->total ?? 0);
            $maint = (int) ($agg[$c->id]->maint ?? 0);
            $out = (int) ($outBy[$c->id] ?? 0);
            $free = max(0, $total - $maint - $out);
            $tag = $free === 0 ? ['full', 'None free'] : ($free * 4 <= $total ? ['tight', 'Tight'] : ['ok', 'Healthy']);

            return ['name' => $c->name, 'total' => $total, 'free' => $free, 'out' => $out, 'maint' => $maint, 'tag' => $tag];
        })->filter(fn ($c) => $c['total'] > 0)->values()->all();
    }

    /** The month, each day shaded by how much of the fleet is booked that day. */
    private function calendar(int $rentable): array
    {
        $first = $this->local->copy()->startOfMonth();
        $last = $this->local->copy()->endOfMonth();
        $list = TenantRental::where('tenant_id', $this->tenant->id)->whereIn('status', ['reserved', 'out', 'returned'])
            ->where('starts_at', '<=', $last->copy()->utc())
            ->where(fn ($q) => $q->where('due_at', '>=', $first->copy()->utc())->orWhere('status', 'out'))
            ->withCount(['lines as units' => fn ($q) => $q->where('kind', 'unit')])
            ->get(['id', 'status', 'starts_at', 'due_at', 'returned_at']);
        $days = [];
        for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
            $s = $d->copy()->startOfDay()->utc();
            $e = $d->copy()->endOfDay()->utc();
            $units = 0;
            foreach ($list as $r) {
                $end = $r->status === 'returned' ? ($r->returned_at ?? $r->due_at) : ($r->status === 'out' && $r->due_at && $r->due_at->lt($this->now) ? $this->now : $r->due_at);
                if ($r->starts_at && $r->starts_at->lte($e) && $end && $end->gte($s)) $units += max(1, (int) $r->units);
            }
            $days[] = ['d' => (int) $d->format('j'), 'date' => $d->toDateString(), 'n' => $units, 'fill' => $rentable ? round(min(1, $units / $rentable), 2) : 0,
                'today' => $d->isSameDay($this->local), 'url' => route('tenant.rentals.availability.timeline', ['date' => $d->toDateString()])];
        }

        return ['month' => $this->local->format('F Y'), 'lead' => (int) $first->format('N') - 1, 'days' => $days];
    }

    private function utilization(int $rentable): int
    {
        if ($rentable < 1) return 0;
        $from = $this->now->copy()->subDays(7);
        $used = 0;
        $list = TenantRental::where('tenant_id', $this->tenant->id)->whereIn('status', ['out', 'returned'])
            ->where('starts_at', '<', $this->now)
            ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>', $from))
            ->withCount(['lines as units' => fn ($q) => $q->where('kind', 'unit')])
            ->get(['id', 'status', 'starts_at', 'returned_at']);
        foreach ($list as $r) {
            $a = $r->starts_at->gt($from) ? $r->starts_at : $from;
            $b = $r->status === 'returned' ? ($r->returned_at ?? $this->now) : $this->now;
            if ($b->gt($this->now)) $b = $this->now;
            if ($b->gt($a)) $used += $a->diffInMinutes($b) * max(1, (int) $r->units);
        }

        return (int) min(100, round($used / ($rentable * 7 * 24 * 60) * 100));
    }

    /** Month to date, the same span last month, and running totals by day for both (cents). */
    private function revenue(): array
    {
        $tid = $this->tenant->id;
        $mStart = $this->local->copy()->startOfMonth();
        $pStart = $this->local->copy()->subMonthNoOverflow()->startOfMonth();
        $pEnd = $pStart->copy()->addSeconds($mStart->diffInSeconds($this->local));
        $offset = $this->local->format('P');
        $q = fn (Carbon $from, Carbon $to) => DB::table('tenant_sale_payments')
            ->join('tenant_sales', 'tenant_sales.id', '=', 'tenant_sale_payments.sale_id')
            ->whereNotNull('tenant_sales.rental_id')->where('tenant_sale_payments.tenant_id', $tid)
            ->where('tenant_sale_payments.recorded_at', '>=', $from->copy()->utc())->where('tenant_sale_payments.recorded_at', '<', $to->copy()->utc())
            ->selectRaw("DAY(CONVERT_TZ(tenant_sale_payments.recorded_at, '+00:00', ?)) as d, SUM(tenant_sale_payments.amount_cents) as c", [$offset])
            ->groupByRaw('d')->pluck('c', 'd')->all();
        $cur = $q($mStart, $this->local);
        $prevAll = $q($pStart, $pStart->copy()->endOfMonth());
        $run = function (array $by, int $days) {
            $t = 0; $out = [];
            for ($i = 1; $i <= $days; $i++) { $t += (int) ($by[$i] ?? 0); $out[] = max(0, (int) round($t / 100)); }
            return $out;
        };
        $today = (int) $this->local->format('j');
        $prevDays = min((int) $pStart->daysInMonth, (int) $this->local->daysInMonth);
        $prevSpan = 0;
        foreach ($prevAll as $d => $c) {
            if ((int) $d < $today || ((int) $d === $today && $pEnd->format('j') == $today)) $prevSpan += (int) $c;
        }

        return [max(0, array_sum($cur)), max(0, $prevSpan), $run($cur, $today), $run($prevAll, $prevDays)];
    }

    /** sent / paid per rental, in one query. */
    private function offers(Collection $ids): array
    {
        if ($ids->isEmpty()) return [];
        try {
            return TenantRentalExtensionOffer::whereIn('rental_id', $ids->all())->whereIn('status', ['sent', 'paid'])
                ->orderBy('sent_at')->get(['rental_id', 'status'])->mapWithKeys(fn ($o) => [$o->rental_id => $o->status])->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    // ------------------------------------------------------------- helpers

    private function who(TenantRental $r): string
    {
        $n = trim(($r->customer->first_name ?? '') . ' ' . ($r->customer->last_name ?? ''));

        return $n !== '' ? $n : 'Walk-in';
    }

    private function initials(TenantRental $r): string
    {
        $w = preg_split('/\s+/', $this->who($r));

        return Str::upper(Str::substr($w[0] ?? '', 0, 1) . Str::substr($w[1] ?? '', 0, 1)) ?: '·';
    }

    private function unitsLabel(Collection $units): string
    {
        $n = $units->count();
        if ($n === 0) return 'No unit';

        return $n === 1 ? (string) $units->first()->name_snapshot : $n . ' units';
    }

    private function rateLabel($line): string
    {
        $n = (int) ($line->duration_units ?? 0);

        return match ($line->rate_mode_snapshot) {
            'hourly'  => $n . ' ' . Str::plural('hour', $n),
            'daily'   => $n . ' ' . Str::plural('day', $n),
            'weekend' => 'weekend',
            default   => '',
        };
    }

    private function window(?Carbon $a, ?Carbon $b): string
    {
        if (! $a || ! $b) return '';
        $a = $a->copy()->setTimezone($this->tz);
        $b = $b->copy()->setTimezone($this->tz);

        return $a->format('D g:i A') . ' → ' . ($a->isSameDay($b) ? $b->format('g:i A') : $b->format('D g:i A'));
    }

    private function lateBy(Carbon $due): string
    {
        $m = (int) $due->diffInMinutes($this->now);

        return $m >= 1440 ? floor($m / 1440) . 'd' : ($m >= 60 ? floor($m / 60) . 'h' : $m . 'm');
    }

    private function hour(int $h): string
    {
        $h = max(0, min(24, $h));

        return ((($h + 11) % 12) + 1) . ($h < 12 || $h === 24 ? 'a' : 'p');
    }
}
