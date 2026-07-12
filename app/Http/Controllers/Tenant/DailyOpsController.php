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

    /* ------------------------------------------------------------ reconciliation (MARKER-PATCH-635) */

    public function reconciliation(Request $request)
    {
        $tenant = tenant();
        $tz = $tenant->timezone();
        $week = $request->filled('week')
            ? \Carbon\Carbon::parse($request->input('week'), $tz)->startOfWeek()
            : tnow()->startOfWeek();
        $fromUtc = $week->copy()->utc();
        $toUtc   = $week->copy()->addWeek()->utc();

        $svc = new \App\Services\Tenant\PayoutReconService($tenant);

        $payouts = \App\Models\Tenant\TenantStripePayout::where('tenant_id', $tenant->id)
            ->whereBetween('arrived_on', [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])
            ->orderByDesc('arrived_on')->get();

        // flat unmatched list across the week's payouts
        $unmatched = [];
        foreach ($payouts as $po) {
            foreach ((array) $po->items as $it) {
                if (! ($it['matched'] ?? false)) {
                    $unmatched[] = ['payout' => $po->payout_id, 'charge' => $it['charge'] ?? '', 'pi' => $it['pi'] ?? null,
                                    'amount' => (int) ($it['amount'] ?? 0), 'created' => (int) ($it['created'] ?? 0)];
                }
            }
        }

        // cash week from closed drawer days
        $cashWeek = \App\Models\Tenant\TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereBetween('day', [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])
            ->orderBy('day')->get();

        return view('tenant.reports.daily-recon', [
            'week'      => $week,
            'payouts'   => $payouts,
            'unmatched' => $unmatched,
            'cashWeek'  => $cashWeek,
            'available' => $svc->available(),
            'lastFetch' => $payouts->max('fetched_at'),
        ]);
    }

    public function reconciliationRefresh(Request $request)
    {
        $tenant = tenant();
        $tz = $tenant->timezone();
        $week = $request->filled('week')
            ? \Carbon\Carbon::parse($request->input('week'), $tz)->startOfWeek()
            : tnow()->startOfWeek();

        try {
            $n = (new \App\Services\Tenant\PayoutReconService($tenant))
                ->refreshRange($week->copy()->utc(), $week->copy()->addWeek()->utc());
        } catch (\Throwable $e) {
            logger()->warning('payout recon refresh failed', ['err' => $e->getMessage()]);
            return back()->with('error', 'Stripe fetch failed — check your Stripe keys and try again.');
        }

        return back()->with('success', $n . ' payout(s) fetched and matched.');
    }

    /** MARKER-PATCH-635 — Xero bank statement CSV from cached payouts. */
    public function exportXero(Request $request)
    {
        $tenant = tenant();
        [$from, $to, $label] = $this->rangeWindow($request);

        $payouts = \App\Models\Tenant\TenantStripePayout::where('tenant_id', $tenant->id)
            ->whereBetween('arrived_on', [tlocal_carbon($from)->toDateString(), tlocal_carbon($to)->toDateString()])
            ->orderBy('arrived_on')->get();

        return response()->streamDownload(function () use ($payouts) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Amount', 'Payee', 'Description', 'Reference']);
            foreach ($payouts as $po) {
                fputcsv($out, [
                    $po->arrived_on->format('d/m/Y'),
                    number_format($po->net_cents / 100, 2, '.', ''),
                    'Stripe',
                    'Stripe payout — gross ' . number_format($po->gross_cents / 100, 2) . ', fees ' . number_format($po->fee_cents / 100, 2),
                    $po->payout_id,
                ]);
            }
            fclose($out);
        }, 'xero-statement-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------ exports (MARKER-PATCH-634) */

    public function exports(Request $request)
    {
        [$from, $to, $label] = $this->rangeWindow($request);
        return view('tenant.reports.daily-exports', ['from' => $from, 'to' => $to, 'label' => $label]);
    }

    /**
     * QuickBooks Online journal CSV — one balanced journal entry per day.
     * Debits: per-method collected (net of that method's refunds) into its
     * deposit account. Credits: sales income (derived), tax payable, tips.
     * Income = collected − tax − tips, so debits always equal credits.
     * Account names come from each method's QB mapping when set (stage 4),
     * with sensible defaults until then.
     */
    public function exportQbJournal(Request $request)
    {
        $tenant = tenant();
        [$from, $to, $label] = $this->rangeWindow($request);

        $qbMap = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $tenant->id)->get()
            ->keyBy('method_key')->map(fn ($m) => $m->qb['deposit_account'] ?? null);

        $days = $this->dailyBreakdown($from, $to);

        return response()->streamDownload(function () use ($days, $qbMap) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description']);
            $no = 1;
            foreach ($days as $d) {
                if ($d['collected'] === 0 && $d['refunds'] === 0) continue;
                $date = $d['date'];
                $desc = 'Daily sales ' . $date;
                foreach ($d['by_method'] as $bm) {
                    $net = $bm['collected'] - $bm['refunded'];
                    if ($net === 0) continue;
                    $acct = $qbMap[$bm['method']] ?? ($bm['method'] === 'card' ? 'Stripe Clearing' : 'Undeposited Funds');
                    if ($net > 0) fputcsv($out, [$no, $date, $acct, number_format($net / 100, 2, '.', ''), '', $desc . ' — ' . $bm['label']]);
                    else          fputcsv($out, [$no, $date, $acct, '', number_format(abs($net) / 100, 2, '.', ''), $desc . ' — ' . $bm['label'] . ' (net refund)']);
                }
                $netCollected = $d['collected'] - $d['refunds'];
                $income = $netCollected - $d['tax'] - $d['tips'];
                if ($income !== 0) fputcsv($out, [$no, $date, 'Sales', $income < 0 ? number_format(abs($income) / 100, 2, '.', '') : '', $income > 0 ? number_format($income / 100, 2, '.', '') : '', $desc . ' — income']);
                if ($d['tax'] > 0)  fputcsv($out, [$no, $date, 'Sales Tax Payable', '', number_format($d['tax'] / 100, 2, '.', ''), $desc . ' — sales tax']);
                if ($d['tips'] > 0) fputcsv($out, [$no, $date, 'Tips Payable', '', number_format($d['tips'] / 100, 2, '.', ''), $desc . ' — tips']);
                $no++;
            }
            fclose($out);
        }, 'quickbooks-journal-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Every payment row with sale context — the everything file. */
    public function exportDetail(Request $request)
    {
        $tenant = tenant();
        [$from, $to, $label] = $this->rangeWindow($request);

        return response()->streamDownload(function () use ($tenant, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Recorded (local)', 'Kind', 'Method', 'Amount', 'Sale total', 'Tax', 'Tip', 'Customer', 'Source', 'Reference']);
            TenantSalePayment::where('tenant_sale_payments.tenant_id', $tenant->id)
                ->where('recorded_at', '>=', $from)->where('recorded_at', '<', $to)
                ->leftJoin('tenant_sales', 'tenant_sales.id', '=', 'tenant_sale_payments.sale_id')
                ->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_sale_payments.customer_id')
                ->orderBy('recorded_at')
                ->select(['tenant_sale_payments.*',
                          'tenant_sales.total_cents as s_total', 'tenant_sales.tax_cents as s_tax', 'tenant_sales.tip_cents as s_tip',
                          'tenant_customers.first_name as c_first', 'tenant_customers.last_name as c_last'])
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $sign = in_array($r->kind, ['refund', 'overage_refund'], true) ? -1 : 1;
                        fputcsv($out, [
                            tlocal($r->recorded_at, 'Y-m-d H:i'),
                            $r->kind,
                            tender_label($r->method),
                            number_format($sign * abs($r->amount_cents) / 100, 2, '.', ''),
                            $r->s_total !== null ? number_format($r->s_total / 100, 2, '.', '') : '',
                            $r->s_tax !== null ? number_format($r->s_tax / 100, 2, '.', '') : '',
                            $r->s_tip !== null ? number_format($r->s_tip / 100, 2, '.', '') : '',
                            trim(($r->c_first ?? '') . ' ' . ($r->c_last ?? '')),
                            $r->source,
                            $r->external_reference,
                        ]);
                    }
                });
            fclose($out);
        }, 'sales-payments-detail-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Tax by day — gross, taxable, tax collected. Shaped for the WA excise return. */
    public function exportTax(Request $request)
    {
        [$from, $to, $label] = $this->rangeWindow($request);
        $days = $this->dailyBreakdown($from, $to);

        return response()->streamDownload(function () use ($days) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Sales', 'Gross', 'Taxable (gross − tax)', 'Tax collected']);
            $tg = $tt = 0;
            foreach ($days as $d) {
                if ($d['gross'] === 0 && $d['tax'] === 0) continue;
                fputcsv($out, [$d['date'], $d['sale_count'],
                    number_format($d['gross'] / 100, 2, '.', ''),
                    number_format(($d['gross'] - $d['tax']) / 100, 2, '.', ''),
                    number_format($d['tax'] / 100, 2, '.', '')]);
                $tg += $d['gross']; $tt += $d['tax'];
            }
            fputcsv($out, ['TOTAL', '', number_format($tg / 100, 2, '.', ''), number_format(($tg - $tt) / 100, 2, '.', ''), number_format($tt / 100, 2, '.', '')]);
            fclose($out);
        }, 'tax-summary-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Per-tenant-local-day numbers across a range (reuses numbersFor per day). */
    private function dailyBreakdown($fromUtc, $toUtc): array
    {
        $tenant = tenant();
        $days = [];
        $cursor = tlocal_carbon($fromUtc)->startOfDay();
        $endLocal = tlocal_carbon($toUtc);
        while ($cursor->lt($endLocal)) {
            $dFrom = $cursor->copy()->utc();
            $dTo   = $cursor->copy()->addDay()->utc();
            $n = $this->numbersFor($dFrom, $dTo);
            $n['date'] = $cursor->toDateString();
            $days[] = $n;
            $cursor->addDay();
        }
        return $days;
    }

    /** [fromUtc, toUtc, filenameLabel] from ?from=&to= (defaults: current month). */
    private function rangeWindow(Request $request): array
    {
        $tenant = tenant();
        $tz = $tenant->timezone();
        $from = $request->filled('from')
            ? \Carbon\Carbon::parse($request->input('from'), $tz)->startOfDay()
            : tnow()->startOfMonth();
        $to = $request->filled('to')
            ? \Carbon\Carbon::parse($request->input('to'), $tz)->endOfDay()
            : tnow()->endOfDay();
        return [$from->copy()->utc(), $to->copy()->utc(), $from->format('Y-m-d') . '_' . $to->format('Y-m-d')];
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

