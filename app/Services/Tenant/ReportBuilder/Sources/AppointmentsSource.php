<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Appointments — booked work by appointment date. */
class AppointmentsSource extends Source
{
    private const DONE = "('completed', 'shipped', 'closed')";
    private const DEAD = "('cancelled', 'refunded')";
    public const STATUS = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'in_progress' => 'In progress', 'completed' => 'Completed',
        'shipped' => 'Shipped', 'closed' => 'Closed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'];

    public function meta(): array
    {
        return [
            'label' => 'Appointments',
            'note'  => 'Booked work, by appointment date. Booked value is before tax and leaves out cancelled and refunded appointments.',
            'snap'  => false,
            'measures' => [
                'ap'     => ['Appointments', 'n'],
                'done'   => ['Completed', 'n'],
                'canc'   => ['Cancelled', 'n'],
                'cancp'  => ['Cancellation rate', '%'],
                'booked' => ['Booked value', '$'],
                'aval'   => ['Average value', '$'],
                'paid'   => ['Paid', '$'],
            ],
            'dims' => [
                'day' => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'],
                'svc' => ['Service', 'c'], 'res' => ['Staff or resource', 'c'], 'loc' => ['Location', 'c'], 'stat' => ['Status', 'c'],
            ],
            'filters' => ['svc' => 'Service', 'res' => 'Staff or resource', 'loc' => 'Location', 'stat' => 'Status'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'svc'  => DB::table('tenant_appointment_items as ai')->join('tenant_appointments as a', 'a.id', '=', 'ai.appointment_id')
                ->where('a.tenant_id', $this->tid())->distinct()->orderBy('ai.item_name_snapshot')
                ->pluck('ai.item_name_snapshot', 'ai.item_name_snapshot')->map(fn ($v) => (string) $v)->all(),
            'res'  => $this->names('res'),
            'loc'  => $this->names('loc'),
            'stat' => self::STATUS,
            default => [],
        };
    }

    private function base(array $filters, Carbon $from, Carbon $to, bool $items)
    {
        $q = DB::table('tenant_appointments as a')
            ->where('a.tenant_id', $this->tid())
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()]);
        if ($items) {
            $q->join('tenant_appointment_items as ai', 'ai.appointment_id', '=', 'a.id');
        }
        $f = $this->byKey($filters);
        foreach (['res' => "COALESCE(a.resource_id, '')", 'loc' => "COALESCE(a.location_id, '')", 'stat' => 'a.status'] as $k => $col) {
            if (isset($f[$k])) {
                $this->sqlFilter($q, $col, $f[$k]);
            }
        }
        if (isset($f['svc'])) {
            $spec = $f['svc'];
            if (!empty($spec['is'])) {
                $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('tenant_appointment_items as fi')
                    ->whereColumn('fi.appointment_id', 'a.id')->whereIn('fi.item_name_snapshot', $spec['is']));
            }
            if (!empty($spec['not'])) {
                $q->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('tenant_appointment_items as fi')
                    ->whereColumn('fi.appointment_id', 'a.id')->whereIn('fi.item_name_snapshot', $spec['not']));
            }
        }
        return $q;
    }

    private function selectComponents($q, bool $items)
    {
        $val = $items ? 'ai.price_cents' : 'a.subtotal_cents';
        return $q->selectRaw('COUNT(DISTINCT a.id) as c_ap')
            ->selectRaw('COUNT(DISTINCT CASE WHEN a.status IN ' . self::DONE . ' THEN a.id END) as c_done')
            ->selectRaw("COUNT(DISTINCT CASE WHEN a.status = 'cancelled' THEN a.id END) as c_canc")
            ->selectRaw('COUNT(DISTINCT CASE WHEN a.status NOT IN ' . self::DEAD . ' THEN a.id END) as c_live')
            ->selectRaw("SUM(CASE WHEN a.status NOT IN " . self::DEAD . " THEN $val ELSE 0 END) as c_booked")
            ->selectRaw($items ? 'SUM(CASE WHEN a.subtotal_cents > 0 THEN a.paid_cents * ai.price_cents / a.subtotal_cents ELSE 0 END) as c_paid' : 'SUM(a.paid_cents) as c_paid');
    }

    private static function comps(object $r): array
    {
        return ['ap' => (float) $r->c_ap, 'done' => (float) $r->c_done, 'canc' => (float) $r->c_canc, 'live' => (float) $r->c_live,
            'booked' => (float) $r->c_booked, 'paid' => (float) $r->c_paid];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $items = in_array('svc', $dims, true);
        $q = $this->base($filters, $from, $to, $items);
        $exprs = [
            'day' => 'a.appointment_date', 'week' => 'a.appointment_date', 'month' => 'a.appointment_date',
            'svc' => 'ai.item_name_snapshot', 'res' => "COALESCE(a.resource_id, '')", 'loc' => "COALESCE(a.location_id, '')", 'stat' => 'a.status',
        ];
        foreach ($dims as $n => $d) {
            $q->selectRaw(($exprs[$d] ?? "''") . " as g$n")->groupByRaw($exprs[$d] ?? "''");
        }
        $this->selectComponents($q, $items);

        $groups = [];
        foreach ($q->get() as $r) {
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                $raw = (string) $r->{"g$n"};
                if (in_array($d, ['day', 'week', 'month'], true)) {
                    $keys[$n] = $this->p->key($d, substr($raw, 0, 10));
                    $labels[$n] = $this->p->label($d, $keys[$n]);
                } else {
                    $keys[$n] = $raw;
                    $labels[$n] = match ($d) {
                        'res'  => $raw === '' ? 'Unassigned' : ($this->names('res')[$raw] ?? 'Removed'),
                        'loc'  => $raw === '' ? 'No location' : ($this->names('loc')[$raw] ?? 'Removed location'),
                        'stat' => self::STATUS[$raw] ?? ucfirst($raw),
                        default => $raw,
                    };
                }
            }
            $this->add($groups, $keys, $labels, self::comps($r));
        }
        $t = $this->selectComponents($this->base($filters, $from, $to, false), false)->first();
        return ['groups' => $groups, 'total' => $t ? self::comps($t) : []];
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['ap' => 0, 'done' => 0, 'canc' => 0, 'live' => 0, 'booked' => 0, 'paid' => 0];
        return match ($m) {
            'ap'     => (float) $c['ap'],
            'done'   => (float) $c['done'],
            'canc'   => (float) $c['canc'],
            'cancp'  => self::ratio($c['canc'], $c['ap'], 100),
            'booked' => $c['booked'] / 100,
            'aval'   => ($r = self::ratio($c['booked'], $c['live'])) === null ? null : $r / 100,
            'paid'   => $c['paid'] / 100,
            default  => null,
        };
    }
}
