<?php

namespace App\Support\Dashboard;

use App\Models\DashboardBlock;
use App\Models\Tenant;
use App\Support\AppointmentStatus;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-DASH-BUILDER: blocks made in master admin (Dashboards › Blocks).
 *
 * Three kinds:
 *   data    reads one source at the shop it's on (appointments, sales,
 *           customers), keeps only what matches its rules, and shows a count,
 *           a total, an average or the records themselves
 *   action  a button to a page the app already has (New appointment, …)
 *   note    a heading and a few lines of text from master admin
 *
 * Stored on dashboard_blocks (kind, name, config). Every query is scoped to
 * one tenant_id; there is no way to read another shop. Results are cached for
 * five minutes per shop and block; editing a block changes its config, which
 * changes the cache key, so an edit shows on the next load.
 *
 * Config:
 *   data:   source, rules [[field, op, value]], measure count|sum|avg|list,
 *           field, period, compare, display number|trend|rows, line
 *   action: action, line
 *   note:   text, until (Y-m-d or null)
 */
class CustomBlocks
{
    public const KEY_PREFIX = 'x';

    /** What a block can read. 'area' is the Roles & access area its data comes from. */
    public static function sources(): array
    {
        $roleLabels = ['awaiting' => 'Waiting to confirm', 'scheduled' => 'Confirmed', 'active' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];

        return [
            'appointments' => [
                'label' => 'Appointments', 'desc' => 'Bookings and jobs, by their appointment date', 'area' => 'schedule',
                'opens' => 'tenant.appointments.index', 'opens_label' => 'Appointments', 'unit' => ['booking', 'bookings'],
                'fields' => [
                    'status'  => ['label' => 'Status', 'type' => 'enum', 'options' => $roleLabels],
                    'payment' => ['label' => 'Payment', 'type' => 'enum', 'options' => ['paid' => 'Paid', 'unpaid' => 'Unpaid', 'partial' => 'Part paid', 'refunded' => 'Refunded']],
                    'amount'  => ['label' => 'Amount', 'type' => 'money', 'sql' => 'total_cents'],
                    'owed'    => ['label' => 'Still owed', 'type' => 'money', 'sql' => '(total_cents - paid_cents)'],
                ],
                'periods' => ['today', 'next_7', 'this_week', 'last_7', 'last_30', 'this_month', 'all_time'],
            ],
            'sales' => [
                'label' => 'Sales', 'desc' => 'Completed register and online sales, by when they were paid (Reports counts payments received, so the two can differ a little)', 'area' => 'reports',
                'opens' => 'tenant.reports.index', 'opens_label' => 'Reports', 'unit' => ['sale', 'sales'],
                'fields' => [
                    'amount'   => ['label' => 'Amount', 'type' => 'money', 'sql' => 'tenant_sales.total_cents'],
                    'discount' => ['label' => 'Discount given', 'type' => 'money', 'sql' => '(tenant_sales.discount_cents + tenant_sales.sale_discount_cents)'],
                    'tip'      => ['label' => 'Tip', 'type' => 'money', 'sql' => 'tenant_sales.tip_cents'],
                    'customer' => ['label' => 'Customer', 'type' => 'enum', 'options' => ['yes' => 'Has a customer', 'no' => 'No customer (walk-in)']],
                ],
                'periods' => ['today', 'this_week', 'last_7', 'last_30', 'this_month', 'all_time'],
            ],
            'customers' => [
                'label' => 'Customers', 'desc' => 'People and businesses, by when they were added', 'area' => 'customers',
                'opens' => 'tenant.customers.index', 'opens_label' => 'Customers', 'unit' => ['customer', 'customers'],
                'fields' => [
                    'vip'   => ['label' => 'VIP', 'type' => 'enum', 'options' => ['yes' => 'VIP', 'no' => 'Not VIP']],
                    'type'  => ['label' => 'Kind', 'type' => 'enum', 'options' => ['individual' => 'Person', 'business' => 'Business']],
                    'email' => ['label' => 'Email', 'type' => 'enum', 'options' => ['yes' => 'Has an email', 'no' => 'No email']],
                ],
                'periods' => ['today', 'this_week', 'last_7', 'last_30', 'this_month', 'all_time'],
            ],
        ];
    }

    public const PERIODS = [
        'today' => 'Today', 'next_7' => 'Next 7 days', 'this_week' => 'This week', 'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days', 'this_month' => 'This month', 'all_time' => 'All time',
    ];

    public const MEASURES = ['count' => 'How many', 'sum' => 'Total of', 'avg' => 'Average of', 'list' => 'The records'];

    public const DISPLAYS = ['number' => 'Number', 'trend' => 'Number + trend', 'rows' => 'Rows'];

    /** Buttons an action block can show: pages that already exist. */
    public const ACTIONS = [
        'new_appointment' => ['label' => 'New appointment', 'route' => 'tenant.appointments.index', 'params' => ['new' => 1]],
        'register'        => ['label' => 'Open register', 'route' => 'tenant.register.index', 'params' => []],
        'walk_in'         => ['label' => 'Start a walk-in', 'route' => 'tenant.register.walk-in', 'params' => []],
        'special_orders'  => ['label' => 'Special orders', 'route' => 'tenant.special-orders.index', 'params' => []],
        'customers'       => ['label' => 'Find a customer', 'route' => 'tenant.customers.index', 'params' => []],
        'class_checkin'   => ['label' => 'Class check-in', 'route' => 'tenant.classes.checkin', 'params' => []],
    ];

    private static ?array $defs = null;

    // ---- the blocks themselves ---------------------------------------------

    /** Every made-here block as a registry entry (see BlockRegistry::all()). Read once per request. */
    public static function definitions(): array
    {
        if (self::$defs !== null) return self::$defs;
        $out = [];
        try {
            foreach (DashboardBlock::where('is_builtin', false)->orderBy('id')->get() as $b) {
                $cfg = self::normalize($b->kind, (array) $b->config);
                $out[$b->key] = [
                    'name'   => $b->name,
                    'origin' => 'custom',
                    'view'   => 'tenant.dashboard.blocks.custom',
                    'reads'  => self::reads($b->kind, $cfg),
                    'desc'   => self::sentence($b->kind, $cfg),
                    'access' => self::access($b->kind, $cfg),
                    'needs'  => [],
                    'custom' => true,
                    'kind'   => $b->kind,
                    'config' => $cfg,
                    'id'     => $b->id,
                ];
            }
        } catch (\Throwable $e) {
            // table not there yet: no made-here blocks
        }

        return self::$defs = $out;
    }

    public static function flush(): void
    {
        self::$defs = null;
    }

    /** The areas a block needs, in BlockAccess's terms. */
    public static function access(string $kind, array $cfg): array
    {
        if ($kind === 'data') {
            $area = self::sources()[$cfg['source']]['area'] ?? null;
            return $area ? ['all' => [$area]] : [];
        }
        if ($kind === 'action') {
            $area = \App\Support\SectionRegistry::sectionForRoute(self::ACTIONS[$cfg['action']]['route'] ?? null);
            return $area ? ['all' => [$area]] : [];
        }

        return [];
    }

    public static function reads(string $kind, array $cfg): string
    {
        return match ($kind) {
            'data'   => self::sources()[$cfg['source']]['label'] ?? '—',
            'action' => 'Button: ' . (self::ACTIONS[$cfg['action']]['label'] ?? '—'),
            default  => 'A note',
        };
    }

    /**
     * A config fit to store and run: known values only. Anything unknown
     * falls back to a safe default rather than failing.
     */
    public static function normalize(string $kind, array $c): array
    {
        if ($kind === 'action') {
            return ['action' => isset(self::ACTIONS[$c['action'] ?? '']) ? $c['action'] : 'new_appointment', 'line' => mb_substr(trim((string) ($c['line'] ?? '')), 0, 140)];
        }
        if ($kind === 'note') {
            $until = $c['until'] ?? null;
            $until = is_string($until) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) ? $until : null;
            return ['text' => mb_substr(trim((string) ($c['text'] ?? '')), 0, 600), 'until' => $until];
        }

        $sources = self::sources();
        $source = isset($sources[$c['source'] ?? '']) ? $c['source'] : 'appointments';
        $src = $sources[$source];
        $money = array_keys(array_filter($src['fields'], fn ($f) => $f['type'] === 'money'));
        $measure = isset(self::MEASURES[$c['measure'] ?? '']) ? $c['measure'] : 'count';
        if (in_array($measure, ['sum', 'avg'], true) && ! $money) $measure = 'count';
        $field = in_array($c['field'] ?? null, $money, true) ? $c['field'] : ($money[0] ?? null);
        $period = in_array($c['period'] ?? '', $src['periods'], true) ? $c['period'] : 'last_30';
        $display = isset(self::DISPLAYS[$c['display'] ?? '']) ? $c['display'] : 'number';
        if ($measure === 'list') $display = 'rows';
        if ($measure !== 'list' && $display === 'rows') $display = 'number';
        if ($display === 'trend' && in_array($period, ['today', 'all_time'], true)) $display = 'number';

        $rules = [];
        foreach (array_slice((array) ($c['rules'] ?? []), 0, 6) as $r) {
            [$f, $op, $v] = array_pad(array_values((array) $r), 3, null);
            $def = $src['fields'][$f] ?? null;
            if (! $def) continue;
            if ($def['type'] === 'enum') {
                if (! in_array($op, ['is', 'is_not'], true) || ! isset($def['options'][$v])) continue;
            } else {
                if (! in_array($op, ['more', 'less'], true) || ! is_numeric($v)) continue;
                $v = round((float) $v, 2);
            }
            $rules[] = [$f, $op, $v];
        }

        return [
            'source'  => $source,
            'rules'   => $rules,
            'measure' => $measure,
            'field'   => in_array($measure, ['sum', 'avg'], true) ? $field : null,
            'period'  => $period,
            'compare' => $measure !== 'list' && $period !== 'all_time' && ! empty($c['compare']),
            'display' => $display,
            'line'    => mb_substr(trim((string) ($c['line'] ?? '')), 0, 140),
        ];
    }

    /** One plain sentence: what the block shows. */
    public static function sentence(string $kind, array $c): string
    {
        if ($kind === 'action') return 'A button that opens ' . (self::ACTIONS[$c['action']]['label'] ?? 'a page') . '.';
        if ($kind === 'note') return 'A note to shops' . ($c['until'] ? ', shown until ' . Carbon::parse($c['until'])->format('M j') : '') . '.';

        $src = self::sources()[$c['source']];
        $what = match ($c['measure']) {
            'count' => 'How many ' . strtolower($src['label']),
            'sum'   => 'The total ' . strtolower($src['fields'][$c['field']]['label'] ?? '') . ' of ' . strtolower($src['label']),
            'avg'   => 'The average ' . strtolower($src['fields'][$c['field']]['label'] ?? '') . ' of ' . strtolower($src['label']),
            default => 'The ' . strtolower($src['label']),
        };
        $rules = array_map(function ($r) use ($src) {
            $f = $src['fields'][$r[0]];
            if ($f['type'] === 'enum') return strtolower($f['label']) . ($r[1] === 'is_not' ? ' is not ' : ' is ') . $f['options'][$r[2]];
            return strtolower($f['label']) . ($r[1] === 'more' ? ' over ' : ' under ') . '$' . number_format((float) $r[2], 2);
        }, $c['rules']);

        return $what . ($rules ? ' where ' . implode(' and ', $rules) : '') . ', ' . strtolower(self::PERIODS[$c['period']])
            . ($c['compare'] ? ', compared with the period before' : '') . '.';
    }

    // ---- figures -------------------------------------------------------------

    /**
     * The block's figures at one shop, cached for five minutes.
     *
     * @return array{kind: string, ...}
     */
    public static function result(string $key, Tenant $tenant): ?array
    {
        $def = self::definitions()[$key] ?? null;
        if (! $def) return null;
        if ($def['kind'] !== 'data') return ['kind' => $def['kind']];

        $ck = 'dashcb:' . $tenant->id . ':' . $key . ':' . md5(json_encode($def['config']));

        return Cache::remember($ck, 300, fn () => self::compute($def['config'], $tenant));
    }

    /** The figures, uncached (the builder's preview uses this directly). */
    public static function compute(array $c, Tenant $tenant): array
    {
        $tz = $tenant->timezone();
        [$from, $to, $pFrom, $pTo] = self::range($c['period'], $tz);
        $out = ['kind' => 'data', 'measure' => $c['measure'], 'display' => $c['display'], 'money' => in_array($c['measure'], ['sum', 'avg'], true)];

        if ($c['measure'] === 'list') {
            $out['total'] = (clone self::query($c, $tenant, $from, $to))->count();
            $out['rows'] = self::rows($c, $tenant, $from, $to);
            return $out;
        }

        $out['value'] = self::aggregate($c, $tenant, $from, $to);
        $out['prev'] = $c['compare'] && $pFrom ? self::aggregate($c, $tenant, $pFrom, $pTo) : null;
        $out['pct'] = ($out['prev'] !== null && $out['prev'] != 0) ? (int) round(($out['value'] - $out['prev']) / abs($out['prev']) * 100) : null;
        if ($c['display'] === 'trend' && $from) {
            $out['series'] = self::series($c, $tenant, $from, $to);
        }

        return $out;
    }

    /** [from, to, prevFrom, prevTo] as tenant-local Carbons; nulls for all time. */
    public static function range(string $period, string $tz): array
    {
        $today = Carbon::now($tz)->startOfDay();
        switch ($period) {
            case 'today':      $f = $today->copy(); $t = $today->copy(); break;
            case 'next_7':     $f = $today->copy(); $t = $today->copy()->addDays(6); break;
            case 'this_week':  $f = $today->copy()->startOfWeek(); $t = $f->copy()->addDays(6); break;
            case 'last_7':     $f = $today->copy()->subDays(6); $t = $today->copy(); break;
            case 'this_month': $f = $today->copy()->startOfMonth(); $t = $today->copy()->endOfMonth()->startOfDay();
                return [$f, $t, $f->copy()->subMonthNoOverflow()->startOfMonth(), $f->copy()->subDay()];
            case 'all_time':   return [null, null, null, null];
            default:           $f = $today->copy()->subDays(29); $t = $today->copy();
        }
        $days = $f->diffInDays($t) + 1;

        return [$f, $t, $f->copy()->subDays($days), $f->copy()->subDay()];
    }

    /** The source's rows at this shop, with its rules and the date range applied. */
    private static function query(array $c, Tenant $tenant, ?Carbon $from, ?Carbon $to): Builder
    {
        $tid = $tenant->id;
        switch ($c['source']) {
            case 'sales':
                $q = DB::table('tenant_sales')->where('tenant_sales.tenant_id', $tid)
                    ->where('tenant_sales.status', 'completed')->whereNull('tenant_sales.refund_of_sale_id')->whereNotNull('tenant_sales.paid_at');
                if ($from) $q->whereBetween('tenant_sales.paid_at', [$from->copy()->utc(), $to->copy()->endOfDay()->utc()]);
                break;
            case 'customers':
                $q = DB::table('tenant_customers')->where('tenant_customers.tenant_id', $tid)->whereNull('tenant_customers.erased_at');
                if ($from) $q->whereBetween('tenant_customers.created_at', [$from->copy()->utc(), $to->copy()->endOfDay()->utc()]);
                break;
            default:
                $q = DB::table('tenant_appointments')->where('tenant_appointments.tenant_id', $tid);
                if ($from) $q->whereBetween('tenant_appointments.appointment_date', [$from->toDateString(), $to->toDateString()]);
        }

        $fields = self::sources()[$c['source']]['fields'];
        foreach ($c['rules'] as [$f, $op, $v]) {
            $def = $fields[$f];
            if ($def['type'] === 'money') {
                $q->whereRaw($def['sql'] . ($op === 'more' ? ' > ?' : ' < ?'), [(int) round($v * 100)]);
                continue;
            }
            $not = $op === 'is_not';
            switch ($c['source'] . '.' . $f) {
                case 'appointments.status':
                    $q->{$not ? 'whereNotIn' : 'whereIn'}('tenant_appointments.status', AppointmentStatus::statusesForRole($v));
                    break;
                case 'appointments.payment':
                    $not ? $q->where(fn ($w) => $w->where('tenant_appointments.payment_status', '!=', $v)->orWhereNull('tenant_appointments.payment_status'))
                         : $q->where('tenant_appointments.payment_status', $v);
                    break;
                case 'sales.customer':
                    ($v === 'yes') !== $not ? $q->whereNotNull('tenant_sales.customer_id') : $q->whereNull('tenant_sales.customer_id');
                    break;
                case 'customers.vip':
                    $q->where('tenant_customers.is_vip', (($v === 'yes') !== $not) ? 1 : 0);
                    break;
                case 'customers.type':
                    $not ? $q->where(fn ($w) => $w->where('tenant_customers.customer_type', '!=', $v)->orWhereNull('tenant_customers.customer_type'))
                         : $q->where('tenant_customers.customer_type', $v);
                    break;
                case 'customers.email':
                    ($v === 'yes') !== $not
                        ? $q->whereNotNull('tenant_customers.email')->where('tenant_customers.email', '!=', '')
                        : $q->where(fn ($w) => $w->whereNull('tenant_customers.email')->orWhere('tenant_customers.email', ''));
                    break;
            }
        }

        return $q;
    }

    private static function aggExpr(array $c): string
    {
        if ($c['measure'] === 'count') return 'COUNT(*)';
        $sql = self::sources()[$c['source']]['fields'][$c['field']]['sql'];

        return ($c['measure'] === 'avg' ? 'AVG(' : 'SUM(') . $sql . ')';
    }

    private static function aggregate(array $c, Tenant $tenant, ?Carbon $from, ?Carbon $to): float
    {
        return (float) (self::query($c, $tenant, $from, $to)->selectRaw(self::aggExpr($c) . ' as v')->value('v') ?? 0);
    }

    /** One value per day across the period, for the sparkline. */
    private static function series(array $c, Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($d = $from->copy(); $d->lte($to) && count($days) < 62; $d->addDay()) $days[$d->toDateString()] = 0;
        $q = self::query($c, $tenant, $from, $to);
        if ($c['source'] === 'appointments') {
            $q->selectRaw('DATE(tenant_appointments.appointment_date) as d, ' . self::aggExpr($c) . ' as v');
        } else {
            $col = $c['source'] === 'sales' ? 'tenant_sales.paid_at' : 'tenant_customers.created_at';
            [$expr, $bind] = tenant_tz_offset_expr($col, $tenant->timezone(), $from->copy()->utc(), $to->copy()->endOfDay()->utc());
            $q->selectRaw("DATE({$expr}) as d, " . self::aggExpr($c) . ' as v', $bind);
        }
        foreach ($q->groupBy('d')->pluck('v', 'd') as $d => $v) {
            if (array_key_exists($d, $days)) $days[$d] = (float) $v;
        }

        return array_values($days);
    }

    /** Up to six records, each [title, sub, right, route, id]. */
    private static function rows(array $c, Tenant $tenant, ?Carbon $from, ?Carbon $to): array
    {
        $tz = $tenant->timezone();
        $sym = $tenant->currency_symbol ?? '$';
        $money = fn ($cents) => self::money((int) $cents, $sym);
        $q = self::query($c, $tenant, $from, $to)->limit(6);

        switch ($c['source']) {
            case 'sales':
                return $q->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_sales.customer_id')
                    ->orderByDesc('tenant_sales.paid_at')
                    ->get(['tenant_sales.id', 'tenant_sales.sale_number', 'tenant_sales.paid_at', 'tenant_sales.total_cents', 'tenant_customers.first_name', 'tenant_customers.last_name'])
                    ->map(fn ($r) => [
                        trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Walk-in',
                        '#' . $r->sale_number . ' · ' . Carbon::parse($r->paid_at, 'UTC')->setTimezone($tz)->format('M j, g:i A'),
                        $money($r->total_cents), 'tenant.register.sales.receipt', $r->id,
                    ])->all();
            case 'customers':
                return $q->orderByDesc('tenant_customers.created_at')
                    ->get(['id', 'first_name', 'last_name', 'business_name', 'email', 'phone', 'created_at'])
                    ->map(fn ($r) => [
                        trim($r->first_name . ' ' . $r->last_name) ?: ($r->business_name ?: 'No name'),
                        $r->email ?: ($r->phone ?: 'No contact details'),
                        'Added ' . Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->format('M j'), 'tenant.customers.show', $r->id,
                    ])->all();
            default:
                return $q->orderBy('appointment_date')->orderBy('appointment_time')
                    ->get(['id', 'customer_first_name', 'customer_last_name', 'appointment_date', 'appointment_time', 'status', 'total_cents'])
                    ->map(fn ($r) => [
                        trim($r->customer_first_name . ' ' . $r->customer_last_name) ?: 'No name',
                        Carbon::parse($r->appointment_date)->format('D M j') . ($r->appointment_time ? ' · ' . Carbon::parse($r->appointment_time)->format('g:i A') : '')
                            . ' · ' . (AppointmentStatus::LABELS[$r->status] ?? Str::headline((string) $r->status)),
                        $money($r->total_cents), 'tenant.appointments.show', $r->id,
                    ])->all();
        }
    }

    public static function money(int $cents, string $sym = '$'): string
    {
        return ($cents < 0 ? '-' : '') . $sym . number_format(abs($cents) / 100, abs($cents) >= 100000 ? 0 : 2);
    }

    /** The headline figure as text: "42", "$1,280". */
    public static function figure(array $r, ?Tenant $tenant = null): string
    {
        if (! empty($r['money'])) return self::money((int) round($r['value'] ?? 0), $tenant?->currency_symbol ?? '$');

        return number_format((float) ($r['value'] ?? 0));
    }

    /** The unit after a count: "bookings". */
    public static function unit(array $cfg, float $n): string
    {
        $u = self::sources()[$cfg['source']]['unit'] ?? ['', ''];

        return (int) $n === 1 ? $u[0] : $u[1];
    }

    /** "Last 30 days · vs the 30 days before" */
    public static function meta(array $cfg): string
    {
        $p = self::PERIODS[$cfg['period']] ?? '';
        if (! $cfg['compare']) return $p;

        return $p . ' · vs ' . match ($cfg['period']) {
            'today' => 'yesterday', 'this_week' => 'last week', 'this_month' => 'last month', 'next_7' => 'the 7 days before',
            'last_7' => 'the 7 days before', default => 'the 30 days before',
        };
    }
}
