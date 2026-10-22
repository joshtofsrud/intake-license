<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Class analytics for Classes › Reports. Everything is counted from what
 * happened, over the chosen window, against the same length of time before.
 *
 *  - A class counts once it has started and wasn't cancelled.
 *  - Booked = registered + checked in + no-show. A visit = checked in, or
 *    still "registered" after the class (studios that don't check people in).
 *  - Fill = booked / capacity, averaged over classes.
 *  - Class revenue = money taken on sales for passes, memberships and
 *    drop-ins, less refunds.
 *  - Revenue by class type = drop-ins plus pack credits used (at each pack's
 *    per-class price). Membership visits aren't priced per class.
 *
 * Days and times are the studio's own. MARKER-CLASSES-P11
 */
class ClassAnalytics
{
    public const WINDOWS = [7, 30, 90];
    private const BOOKED = ['registered', 'checked_in', 'no_show'];
    private const VISIT  = ['registered', 'checked_in'];

    public function __construct(private Tenant $tenant, private int $days = 30) {}

    public static function for(Tenant $tenant, int $days): self
    {
        return new self($tenant, in_array($days, self::WINDOWS, true) ? $days : 30);
    }

    public function days(): int { return $this->days; }

    public function range(): array
    {
        $to = now();
        return [$to->copy()->subDays($this->days), $to];
    }

    private function prior(): array
    {
        [$from] = $this->range();
        return [$from->copy()->subDays($this->days), $from];
    }

    /** Started, not-cancelled classes in a window, with booking counts. */
    private function sessions(Carbon $from, Carbon $to): Collection
    {
        return TenantClassSession::where('tenant_id', $this->tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->with('template:id,name,price_cents')
            ->withCount([
                'registrations as booked_count'  => fn ($q) => $q->whereIn('status', self::BOOKED),
                'registrations as visit_count'   => fn ($q) => $q->whereIn('status', self::VISIT),
                'registrations as noshow_count'  => fn ($q) => $q->where('status', 'no_show'),
                'registrations as wait_count'    => fn ($q) => $q->where('status', 'waitlisted'),
                'registrations as late_count'    => fn ($q) => $q->where('status', 'cancelled')->where('metadata->late_cancel', true),
            ])->get();
    }

    private function summarise(Collection $s): array
    {
        $booked = (int) $s->sum('booked_count');
        $late   = (int) $s->sum('late_count');
        return [
            'classes' => $s->count(),
            'fill'    => $s->count() ? $s->avg(fn ($x) => min(1, $x->booked_count / max(1, $x->capacity_snapshot))) : null,
            'visits'  => (int) $s->sum('visit_count'),
            'noshow'  => $booked ? $s->sum('noshow_count') / $booked : null,
            'late'    => ($booked + $late) ? $late / ($booked + $late) : null,
        ];
    }

    /** The headline numbers, now and for the window before. */
    public function headline(): array
    {
        [$from, $to] = $this->range();
        [$pf, $pt]   = $this->prior();
        $now  = $this->summarise($this->sessions($from, $to));
        $then = $this->summarise($this->sessions($pf, $pt));

        $mrr = TenantCustomerMembership::where('tenant_customer_memberships.tenant_id', $this->tenant->id)
            ->whereIn('tenant_customer_memberships.status', ['active', 'past_due'])
            ->where(fn ($q) => $q->whereNull('billing_mode')->orWhere('billing_mode', '!=', 'comp'))
            ->leftJoin('tenant_class_membership_products as p', 'p.id', '=', 'tenant_customer_memberships.product_id')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(COALESCE(tenant_customer_memberships.renewal_price_cents, p.price_cents, 0)),0) as cents')
            ->first();

        $comps = TenantCustomerMembership::where('tenant_id', $this->tenant->id)
            ->whereIn('status', ['active', 'past_due'])->where('billing_mode', 'comp')->count();

        // Intro offers bought in the window, and how many of those people
        // have since taken a membership.
        $intro = TenantCustomerPack::where('tenant_customer_packs.tenant_id', $this->tenant->id)
            ->join('tenant_class_pack_products as pp', 'pp.id', '=', 'tenant_customer_packs.product_id')
            ->where('pp.is_intro', 1)
            ->whereBetween('tenant_customer_packs.created_at', [$from, $to])
            ->get(['tenant_customer_packs.customer_id', 'tenant_customer_packs.created_at']);
        $converted = $intro->filter(fn ($p) => TenantCustomerMembership::where('tenant_id', $this->tenant->id)
            ->where('customer_id', $p->customer_id)->where('created_at', '>=', $p->created_at)->exists())->count();

        return [
            'now' => $now, 'then' => $then,
            'revenue' => $this->revenue($from, $to), 'revenue_then' => $this->revenue($pf, $pt),
            'mrr_cents' => (int) $mrr->cents, 'members' => (int) $mrr->n, 'comps' => $comps,
            'intro_bought' => $intro->count(), 'intro_converted' => $converted,
        ];
    }

    /** Net money taken for classes: pass, membership and drop-in sale lines, less refunds. */
    public function revenue(Carbon $from, Carbon $to): int
    {
        $base = fn () => DB::table('tenant_sale_items as i')->join('tenant_sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.tenant_id', $this->tenant->id)
            ->whereBetween('s.created_at', [$from, $to])
            ->where(fn ($q) => $q->where('i.type', 'class_pass')
                ->orWhere(fn ($q) => $q->where('i.type', 'open_item')->where('i.name_snapshot', 'like', 'Drop-in:%')));
        $in  = (int) $base()->whereNull('s.refund_of_sale_id')->whereIn('s.payment_status', ['paid', 'partial', 'refunded'])->sum('i.line_total_cents');
        $out = (int) $base()->whereNotNull('s.refund_of_sale_id')->sum('i.line_total_cents');
        return $in - $out;
    }

    /**
     * How full each weekday × start time runs.
     * @return array{times:array<string>, cells:array<string,array<int,array{fill:float,full:bool,n:int}>>}
     */
    public function slots(): array
    {
        [$from, $to] = $this->range();
        $tz = $this->tenant->timezone();
        $cells = [];
        foreach ($this->sessions($from, $to) as $s) {
            $l   = $s->starts_at->copy()->setTimezone($tz);
            $key = $l->format('H:i');
            $dow = (int) $l->format('N'); // 1 = Monday
            $c   = $cells[$key][$dow] ?? ['sum' => 0, 'n' => 0, 'full' => false];
            $c['sum']  += min(1, $s->booked_count / max(1, $s->capacity_snapshot));
            $c['n']    += 1;
            $c['full']  = $c['full'] || ($s->booked_count >= $s->capacity_snapshot && $s->wait_count > 0);
            $cells[$key][$dow] = $c;
        }
        ksort($cells);
        foreach ($cells as $k => $row) {
            foreach ($row as $d => $c) {
                $cells[$k][$d] = ['fill' => $c['sum'] / $c['n'], 'full' => $c['full'], 'n' => $c['n']];
            }
        }
        return ['times' => array_keys($cells), 'cells' => $cells];
    }

    /** Per class type: classes, fill, no-shows, revenue (drop-ins + pack credits used). */
    public function byType(): Collection
    {
        [$from, $to] = $this->range();
        $sessions = $this->sessions($from, $to);
        $value = $this->valueBySession($sessions->pluck('id')->all());
        return $sessions->groupBy('class_template_id')->map(function ($list) use ($value) {
            $sum = $this->summarise($list);
            return [
                'name'    => $list->first()->template?->name ?? 'Class',
                'classes' => $sum['classes'],
                'fill'    => $sum['fill'],
                'noshow'  => $sum['noshow'],
                'revenue' => (int) $list->sum(fn ($s) => $value[$s->id] ?? 0),
            ];
        })->sortByDesc('revenue')->values();
    }

    /** Per instructor: classes, visits, fill, regulars (3+ visits with them in the window). */
    public function byInstructor(): Collection
    {
        [$from, $to] = $this->range();
        $sessions = $this->sessions($from, $to);
        $visits = TenantClassRegistration::whereIn('class_session_id', $sessions->pluck('id'))->whereIn('status', self::VISIT)
            ->get(['class_session_id', 'customer_id'])->groupBy('class_session_id');
        return $sessions->groupBy(fn ($s) => $s->instructor_snapshot ?: 'No instructor')->map(function ($list, $name) use ($visits) {
            $sum = $this->summarise($list);
            $people = $list->flatMap(fn ($s) => ($visits[$s->id] ?? collect())->pluck('customer_id'));
            return [
                'name'     => $name,
                'classes'  => $sum['classes'],
                'visits'   => $sum['visits'],
                'fill'     => $sum['fill'],
                'regulars' => $people->countBy()->filter(fn ($n) => $n >= 3)->count(),
            ];
        })->sortByDesc('visits')->values();
    }

    /** First-timers in the window and what they did next. */
    public function newPeople(): array
    {
        [$from, $to] = $this->range();
        $firsts = DB::table('tenant_class_registrations as r')->join('tenant_class_sessions as s', 's.id', '=', 'r.class_session_id')
            ->where('r.tenant_id', $this->tenant->id)->whereIn('r.status', self::VISIT)->where('s.starts_at', '<', now())
            ->groupBy('r.customer_id')->selectRaw('r.customer_id, MIN(s.starts_at) as first_at, COUNT(*) as n')
            ->get()->filter(fn ($x) => Carbon::parse($x->first_at)->between($from, $to));

        $back = 0; $bought = 0; $three = 0;
        foreach ($firsts as $f) {
            $first = Carbon::parse($f->first_at);
            if ($f->n >= 3) {
                $three++;
            }
            $again = DB::table('tenant_class_registrations as r')->join('tenant_class_sessions as s', 's.id', '=', 'r.class_session_id')
                ->where('r.customer_id', $f->customer_id)->whereIn('r.status', self::VISIT)
                ->where('s.starts_at', '>', $first)->where('s.starts_at', '<=', $first->copy()->addDays(14))->exists();
            if ($again) {
                $back++;
            }
            $pass = TenantCustomerPack::where('tenant_id', $this->tenant->id)->where('customer_id', $f->customer_id)->where('created_at', '>=', $first->copy()->startOfDay())->exists()
                || TenantCustomerMembership::where('tenant_id', $this->tenant->id)->where('customer_id', $f->customer_id)->where('created_at', '>=', $first->copy()->startOfDay())->exists();
            if ($pass) {
                $bought++;
            }
        }
        return ['first' => $firsts->count(), 'back' => $back, 'bought' => $bought, 'three' => $three];
    }

    /** Drop-in price for each drop-in visit, and per-class pack value for each pack visit. */
    private function valueBySession(array $sessionIds): array
    {
        if (! $sessionIds) {
            return [];
        }
        $rows = DB::table('tenant_class_registrations as r')
            ->join('tenant_class_sessions as s', 's.id', '=', 'r.class_session_id')
            ->join('tenant_class_templates as t', 't.id', '=', 's.class_template_id')
            ->leftJoin('tenant_customer_packs as cp', 'cp.id', '=', 'r.pack_id')
            ->leftJoin('tenant_class_pack_products as pp', 'pp.id', '=', 'cp.product_id')
            ->whereIn('r.class_session_id', $sessionIds)->whereIn('r.status', self::BOOKED)
            ->get(['r.class_session_id', 'r.payment_method', 't.price_cents', 'pp.price_cents as pack_price', 'pp.credit_count']);
        $out = [];
        foreach ($rows as $r) {
            $v = match ($r->payment_method) {
                'online', 'cash', 'per_class' => (int) $r->price_cents,
                'pack'  => $r->credit_count ? (int) round($r->pack_price / max(1, $r->credit_count)) : 0,
                default => 0,
            };
            $out[$r->class_session_id] = ($out[$r->class_session_id] ?? 0) + $v;
        }
        return $out;
    }
}
