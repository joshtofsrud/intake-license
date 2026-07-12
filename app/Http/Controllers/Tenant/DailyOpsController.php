<?php
// MARKER-PATCH-633 — Reports → Daily ops → End of day.
// All money from the sales-as-money ledger (tenant_sale_payments) bucketed by
// tenant-local day; gross/tax/tips from the sale rows paid that day. Drawer
// reconciliation lives in tenant_drawer_days; closing the day snapshots the
// numbers so history can't drift as data changes.
// TIMEZONE: a "day" is the tenant-local calendar day converted to a UTC range.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDrawerDay;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSalePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailyOpsController extends Controller
{
    public function endOfDay(Request $request)
    {
        $tenant = tenant();
        [$day, $fromUtc, $toUtc] = $this->dayWindow($request);

        $drawer = TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->whereDate('day', $day->toDateString())
            ->first();

        // Closed days render from the snapshot — immutable history.
        if ($drawer && $drawer->isClosed() && $drawer->snapshot) {
            $n = $drawer->snapshot;
        } else {
            $n = $this->numbersFor($fromUtc, $toUtc);
        }

        $attention = $this->attention($fromUtc, $toUtc);

        return view('tenant.reports.daily', [
            'day'       => $day,
            'n'         => $n,
            'drawer'    => $drawer,
            'attention' => $attention,
            'isToday'   => $day->isSameDay(tnow()),
        ]);
    }

    public function saveDrawer(Request $request)
    {
        $tenant = tenant();
        [$day] = $this->dayWindow($request);

        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:100000'],
            'paid_out'      => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'paid_out_note' => ['nullable', 'string', 'max:200'],
            'counted'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $drawer = TenantDrawerDay::firstOrNew([
            'tenant_id'   => $tenant->id,
            'location_id' => null,
            'day'         => $day->toDateString(),
        ]);
        abort_if($drawer->isClosed(), 422, 'This day is closed.');

        $drawer->fill([
            'opening_float_cents' => (int) round($data['opening_float'] * 100),
            'paid_out_cents'      => (int) round(($data['paid_out'] ?? 0) * 100),
            'paid_out_note'       => $data['paid_out_note'] ?? null,
            'counted_cents'       => $request->filled('counted') ? (int) round($data['counted'] * 100) : null,
        ])->save();

        return back()->with('success', 'Drawer saved.');
    }

    public function closeDay(Request $request)
    {
        $tenant = tenant();
        [$day, $fromUtc, $toUtc] = $this->dayWindow($request);

        $drawer = TenantDrawerDay::firstOrNew([
            'tenant_id'   => $tenant->id,
            'location_id' => null,
            'day'         => $day->toDateString(),
        ]);
        abort_if($drawer->isClosed(), 422, 'Already closed.');
        if ($drawer->counted_cents === null) {
            return back()->with('error', 'Count the drawer before closing the day.');
        }

        $n = $this->numbersFor($fromUtc, $toUtc);
        $expected = $drawer->opening_float_cents + $n['cash_collected'] - $n['cash_refunds'] - $drawer->paid_out_cents;

        $drawer->fill([
            'expected_cents'   => $expected,
            'over_short_cents' => $drawer->counted_cents - $expected,
            'snapshot'         => $n,
            'closed_by'        => Auth::guard('tenant')->id(),
            'closed_at'        => now(),
        ])->save();

        return back()->with('success', 'Day closed — numbers locked.');
    }

    public function reopenDay(Request $request)
    {
        $tenant = tenant();
        [$day] = $this->dayWindow($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $drawer = TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereNull('location_id')->whereDate('day', $day->toDateString())->firstOrFail();

        $drawer->fill([
            'closed_at' => null,
            'closed_by' => null,
            'snapshot'  => null,
            'paid_out_note' => trim(($drawer->paid_out_note ? $drawer->paid_out_note . ' · ' : '') . 'Reopened: ' . $data['reason']),
        ])->save();

        return back()->with('success', 'Day reopened.');
    }

    public function printDay(Request $request)
    {
        $tenant = tenant();
        [$day, $fromUtc, $toUtc] = $this->dayWindow($request);
        $drawer = TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereNull('location_id')->whereDate('day', $day->toDateString())->first();
        $n = ($drawer && $drawer->isClosed() && $drawer->snapshot) ? $drawer->snapshot : $this->numbersFor($fromUtc, $toUtc);

        return view('tenant.reports.daily-print', ['day' => $day, 'n' => $n, 'drawer' => $drawer, 'tenant' => $tenant]);
    }

    /* ------------------------------------------------------------ numbers */

    private function numbersFor($fromUtc, $toUtc): array
    {
        $tenant = tenant();

        $pay = TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('recorded_at', '>=', $fromUtc)->where('recorded_at', '<', $toUtc);

        $collectKinds = [TenantSalePayment::KIND_PAYMENT, TenantSalePayment::KIND_BALANCE, TenantSalePayment::KIND_DEPOSIT];
        $refundKinds  = [TenantSalePayment::KIND_REFUND, TenantSalePayment::KIND_OVERAGE_REFUND];

        // by-method table: collected and refunded per method
        $byMethod = (clone $pay)
            ->selectRaw("COALESCE(method,'unknown') as m,
                SUM(CASE WHEN kind IN ('payment','balance','deposit') THEN amount_cents ELSE 0 END) as collected,
                SUM(CASE WHEN kind IN ('refund','overage_refund') THEN amount_cents ELSE 0 END) as refunded,
                SUM(CASE WHEN kind IN ('payment','balance','deposit') THEN 1 ELSE 0 END) as n")
            ->groupBy('m')->get()
            ->map(fn ($r) => [
                'method'    => $r->m,
                'label'     => tender_label($r->m),
                'count'     => (int) $r->n,
                'collected' => (int) $r->collected,
                'refunded'  => (int) abs($r->refunded),
            ])
            ->sortByDesc('collected')->values()->all();

        $collected = array_sum(array_column($byMethod, 'collected'));
        $refunds   = array_sum(array_column($byMethod, 'refunded'));
        $deposits  = (int) (clone $pay)->where('kind', TenantSalePayment::KIND_DEPOSIT)->sum('amount_cents');

        $cashRow = collect($byMethod)->firstWhere('method', 'cash') ?? ['collected' => 0, 'refunded' => 0];

        // gross / tax / tips from sales paid this day
        $sales = TenantSale::where('tenant_id', $tenant->id)
            ->where('paid_at', '>=', $fromUtc)->where('paid_at', '<', $toUtc)
            ->whereNull('refund_of_sale_id');
        $salesAgg = (clone $sales)->selectRaw('COUNT(*) as n, COALESCE(SUM(total_cents),0) as gross, COALESCE(SUM(tax_cents),0) as tax, COALESCE(SUM(tip_cents),0) as tips')->first();

        return [
            'gross'          => (int) $salesAgg->gross,
            'sale_count'     => (int) $salesAgg->n,
            'collected'      => $collected,
            'refunds'        => $refunds,
            'tax'            => (int) $salesAgg->tax,
            'tips'           => (int) $salesAgg->tips,
            'deposits'       => $deposits,
            'cash_collected' => (int) $cashRow['collected'],
            'cash_refunds'   => (int) $cashRow['refunded'],
            'by_method'      => $byMethod,
        ];
    }

    private function attention($fromUtc, $toUtc): array
    {
        $tenant = tenant();
        $items = [];

        // open register drafts
        $drafts = TenantSale::where('tenant_id', $tenant->id)->drafts()
            ->orderByDesc('created_at')->limit(5)->get(['id', 'total_cents', 'created_at']);
        foreach ($drafts as $d) {
            $items[] = ['label' => 'Draft sale open on the register', 'amount' => (int) $d->total_cents,
                        'tag' => 'draft', 'url' => route('tenant.register.index')];
        }

        // online orders awaiting a manual payment (631)
        $pending = TenantOrder::where('tenant_id', $tenant->id)
            ->where('status', TenantOrder::STATUS_PENDING_PAYMENT)
            ->whereNotNull('payment_method')
            ->orderByDesc('created_at')->limit(10)
            ->get(['id', 'order_number', 'total_cents', 'payment_method']);
        foreach ($pending as $o) {
            $items[] = ['label' => 'Awaiting ' . tender_label($o->payment_method) . ' — order ' . $o->order_number,
                        'amount' => (int) $o->total_cents, 'tag' => 'pending',
                        'url' => route('tenant.orders.show', $o->id)];
        }

        // completed appointments with a balance
        $unpaid = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->whereIn('status', ['completed', 'shipped', 'closed'])
            ->whereColumn('paid_cents', '<', 'total_cents')
            ->where('total_cents', '>', 0)
            ->with('customer:id,first_name,last_name')
            ->orderByDesc('appointment_date')->limit(5)
            ->get(['id', 'customer_id', 'total_cents', 'paid_cents', 'appointment_date', 'status']);
        foreach ($unpaid as $a) {
            $due = max(0, (int) $a->total_cents - (int) $a->paid_cents);
            if ($due === 0) continue;
            $who = $a->customer ? trim($a->customer->first_name . ' ' . $a->customer->last_name) : 'customer';
            $items[] = ['label' => 'Unpaid job — ' . $who,
                        'amount' => $due, 'tag' => 'unpaid',
                        'url' => route('tenant.appointments.show', $a->id)];
        }

        return array_slice($items, 0, 8);
    }

    /** [$dayCarbon(tenant-local midnight), $fromUtc, $toUtc] from ?day=YYYY-MM-DD */
    private function dayWindow(Request $request): array
    {
        $tenant = tenant();
        $day = $request->filled('day')
            ? \Carbon\Carbon::parse($request->input('day'), $tenant->timezone())->startOfDay()
            : tnow()->startOfDay();
        return [$day, $day->copy()->utc(), $day->copy()->addDay()->utc()];
    }
}

