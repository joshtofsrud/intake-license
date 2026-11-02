<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PaymentsReceived — money that actually arrived in a date range, from the payments ledger.
 * (MARKER-REPORT-TABS-ENGINE)
 *
 * This is the one number the report engine doesn't have: Sales counts sale lines by the day of the sale,
 * the ledger counts payments by the day they were recorded. The Money tab shows both and reconciles them.
 * Every figure is whole cents.
 */
class PaymentsReceived
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * @return array{total: int, retail: int, service: int, other: int}
     *   retail / service are the payments apportioned by what the sale actually contained; "other" is
     *   anything that can't be classified (a sale with no lines, for example). The three add up to total.
     */
    public function split(Carbon $from, Carbon $to): array
    {
        $tz = $this->tenant->timezone();
        $a = Carbon::parse($from->toDateString() . ' 00:00:00', $tz)->utc();
        $b = Carbon::parse($to->toDateString() . ' 23:59:59', $tz)->utc();

        $paid = DB::table('tenant_sale_payments')->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$a->toDateTimeString(), $b->toDateTimeString()])
            ->selectRaw('sale_id, SUM(amount_cents) as cents')->groupBy('sale_id')->pluck('cents', 'sale_id');

        $total = (int) $paid->sum();
        $svc = 0; $ret = 0; $oth = 0;
        if ($paid->isNotEmpty()) {
            $ids = $paid->keys()->all();
            $saleAppt = DB::table('tenant_sales')->whereIn('id', $ids)->pluck('appointment_id', 'id');
            $apptIds = collect($saleAppt)->filter()->unique()->values()->all();

            $apptSvc = []; $apptRet = [];
            if ($apptIds) {
                foreach (['tenant_appointment_items', 'tenant_appointment_addons'] as $t) {
                    foreach (DB::table($t)->whereIn('appointment_id', $apptIds)->selectRaw('appointment_id, SUM(price_cents) c')->groupBy('appointment_id')->get() as $r) {
                        $apptSvc[$r->appointment_id] = ($apptSvc[$r->appointment_id] ?? 0) + (int) $r->c;
                    }
                }
                foreach (DB::table('tenant_appointment_parts')->whereIn('appointment_id', $apptIds)
                    ->selectRaw('appointment_id, SUM(COALESCE(unit_price_cents_override, unit_price_cents) * quantity) c')->groupBy('appointment_id')->get() as $r) {
                    $apptRet[$r->appointment_id] = (int) $r->c;
                }
            }

            $alone = collect($ids)->filter(fn ($id) => empty($saleAppt[$id]))->values()->all();
            $saleSvc = []; $saleRet = [];
            if ($alone) {
                foreach (DB::table('tenant_sale_items')->whereIn('sale_id', $alone)
                    ->selectRaw("sale_id, SUM(CASE WHEN type = 'service' THEN line_total_cents ELSE 0 END) svc, SUM(CASE WHEN type = 'product' THEN line_total_cents ELSE 0 END) ret")
                    ->groupBy('sale_id')->get() as $r) {
                    $saleSvc[$r->sale_id] = (int) $r->svc; $saleRet[$r->sale_id] = (int) $r->ret;
                }
            }
            foreach ($paid as $sid => $cents) {
                $appt = $saleAppt[$sid] ?? null;
                $s = $appt ? ($apptSvc[$appt] ?? 0) : ($saleSvc[$sid] ?? 0);
                $r = $appt ? ($apptRet[$appt] ?? 0) : ($saleRet[$sid] ?? 0);
                if ($s + $r > 0) {
                    $x = (int) round($cents * ($s / ($s + $r)));
                    $y = (int) round($cents * ($r / ($s + $r)));
                    $svc += $x; $ret += $y; $oth += $cents - $x - $y;
                } else {
                    $oth += (int) $cents;
                }
            }
        }
        return ['total' => $total, 'retail' => $ret, 'service' => $svc, 'other' => $oth];
    }

    /**
     * Tips and surcharges on the paid sales dated in the range (refunds taken off), in cents.
     * Both are paid with the sale but are not revenue, so the reconciliation names them.
     * @return array{tips: int, surcharge: int}
     */
    public function extras(Carbon $from, Carbon $to): array
    {
        $r = DB::table('tenant_sales')->where('tenant_id', $this->tenant->id)->where('payment_status', 'paid')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(CASE WHEN refund_of_sale_id IS NULL THEN 1 ELSE -1 END * tip_cents) as tips')
            ->selectRaw('SUM(CASE WHEN refund_of_sale_id IS NULL THEN 1 ELSE -1 END * surcharge_cents) as sur')->first();
        return ['tips' => (int) ($r->tips ?? 0), 'surcharge' => (int) ($r->sur ?? 0)];
    }

    /**
     * The newest refunds in the range, for the Money tab's list.
     * @return array{count: int, rows: array<int, array>}
     */
    public function refundList(Carbon $from, Carbon $to, int $limit = 8): array
    {
        $base = fn () => DB::table('tenant_sales as s')->where('s.tenant_id', $this->tenant->id)->whereNotNull('s.refund_of_sale_id')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()]);
        $count = (int) $base()->count();
        $rows = $base()->leftJoin('tenant_users as u', 'u.id', '=', 's.rang_up_by_user_id')
            ->orderByDesc('s.sale_date')->orderByDesc('s.sale_number')->limit($limit)
            ->get(['s.id', 's.sale_number', 's.sale_date', 'u.name as by'])->all();
        // The amount is the lines refunded, before tax: the same figure the engine's Refunds measure adds up.
        $items = []; $cents = [];
        if ($rows) {
            foreach (DB::table('tenant_sale_items')->whereIn('sale_id', array_map(fn ($r) => $r->id, $rows))->orderBy('position')
                ->get(['sale_id', 'name_snapshot', 'unit_price_cents', 'quantity', 'discount_cents']) as $i) {
                $items[$i->sale_id][] = (string) $i->name_snapshot;
                $cents[$i->sale_id] = ($cents[$i->sale_id] ?? 0) + (int) round($i->unit_price_cents * $i->quantity - $i->discount_cents);
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $names = $items[$r->id] ?? [];
            $out[] = ['date' => (string) $r->sale_date, 'number' => (string) $r->sale_number, 'by' => (string) ($r->by ?? ''),
                'items' => $names ? implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') : '—',
                'cents' => (int) ($cents[$r->id] ?? 0)];
        }
        return ['count' => $count, 'rows' => $out];
    }
}
