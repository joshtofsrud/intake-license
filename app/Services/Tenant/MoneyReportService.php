<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MoneyReportService — date-ranged financial summary for the Money tab.
 *
 * Real panels:
 *   - revenueSummary() : paid appointments + paid sales, totals
 *   - refunds()        : refund sale rows, totals
 *   - taxAndFees()     : tax_cents totals across both streams
 *
 * Stub panels (schema doesn't exist):
 *   - drawerAndTill()  : requires register session tracking
 *   - stripePayouts()  : requires either local payout cache or live Stripe API
 */
class MoneyReportService
{
    private const DELIVERED_STATUSES = ['in_progress', 'completed', 'shipped', 'closed'];

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Revenue summary — paid appointments + paid sales, with refunds noted.
     */
    public function revenueSummary(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        // MARKER-PATCH-186 — Revenue = payments received (ledger) in window,
        // categorized by the ACTUAL ITEMS that created the sum, not by the
        // payment artifact. Deposit/balance lines are "where it was paid" and
        // are ignored for categorization. For an appointment-linked sale we read
        // the appointment's real items (service items + addons = service; parts =
        // retail). For a standalone sale we read its own line items by type.
        // Each sale's payments are apportioned by that service/retail ratio;
        // anything not classifiable falls to uncategorized. The three sum to total.
        $tz = $this->tenant->timezone();
        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();

        $paidPerSale = DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$winStart, $winEnd])
            ->selectRaw('sale_id, SUM(amount_cents) as cents')
            ->groupBy('sale_id')
            ->pluck('cents', 'sale_id');

        $totalRevenue = (int) $paidPerSale->sum();
        $serviceRevenue = 0; $retailRevenue = 0; $uncategorizedRevenue = 0;

        if ($paidPerSale->isNotEmpty()) {
            $saleIds = $paidPerSale->keys()->all();

            $saleAppt = DB::table('tenant_sales')
                ->whereIn('id', $saleIds)
                ->pluck('appointment_id', 'id');

            $apptIds = collect($saleAppt)->filter()->unique()->values()->all();

            $apptSvc = []; $apptRet = [];
            if (!empty($apptIds)) {
                foreach (DB::table('tenant_appointment_items')->whereIn('appointment_id', $apptIds)
                    ->selectRaw('appointment_id, SUM(price_cents) c')->groupBy('appointment_id')->get() as $r) {
                    $apptSvc[$r->appointment_id] = ($apptSvc[$r->appointment_id] ?? 0) + (int) $r->c;
                }
                foreach (DB::table('tenant_appointment_addons')->whereIn('appointment_id', $apptIds)
                    ->selectRaw('appointment_id, SUM(price_cents) c')->groupBy('appointment_id')->get() as $r) {
                    $apptSvc[$r->appointment_id] = ($apptSvc[$r->appointment_id] ?? 0) + (int) $r->c;
                }
                foreach (DB::table('tenant_appointment_parts')->whereIn('appointment_id', $apptIds)
                    ->selectRaw('appointment_id, SUM(COALESCE(unit_price_cents_override, unit_price_cents) * quantity) c')
                    ->groupBy('appointment_id')->get() as $r) {
                    $apptRet[$r->appointment_id] = ($apptRet[$r->appointment_id] ?? 0) + (int) $r->c;
                }
            }

            $standaloneIds = collect($saleIds)->filter(fn($id) => empty($saleAppt[$id]))->values()->all();
            $saleSvc = []; $saleRet = [];
            if (!empty($standaloneIds)) {
                foreach (DB::table('tenant_sale_items')->whereIn('sale_id', $standaloneIds)
                    ->selectRaw("sale_id,
                        SUM(CASE WHEN type='service' THEN line_total_cents ELSE 0 END) svc,
                        SUM(CASE WHEN type='product' THEN line_total_cents ELSE 0 END) ret")
                    ->groupBy('sale_id')->get() as $r) {
                    $saleSvc[$r->sale_id] = (int) $r->svc;
                    $saleRet[$r->sale_id] = (int) $r->ret;
                }
            }

            foreach ($paidPerSale as $saleId => $paidCents) {
                $apptId = $saleAppt[$saleId] ?? null;
                if ($apptId) {
                    $svc = $apptSvc[$apptId] ?? 0;
                    $ret = $apptRet[$apptId] ?? 0;
                } else {
                    $svc = $saleSvc[$saleId] ?? 0;
                    $ret = $saleRet[$saleId] ?? 0;
                }
                $all = $svc + $ret;
                if ($all > 0) {
                    $s = (int) round($paidCents * ($svc / $all));
                    $rr = (int) round($paidCents * ($ret / $all));
                    $serviceRevenue       += $s;
                    $retailRevenue        += $rr;
                    $uncategorizedRevenue += ($paidCents - $s - $rr);
                } else {
                    $uncategorizedRevenue += $paidCents;
                }
            }
        }

        return [
            'service_revenue_cents'       => $serviceRevenue,
            'retail_revenue_cents'        => $retailRevenue,
            'uncategorized_revenue_cents' => $uncategorizedRevenue,
            'total_revenue_cents'         => $totalRevenue,
        ];

    }

    /**
     * Refunds — sale refund rows in range.
     * PRODUCT DECISION: only counts sale-side refunds. Appointment refunds
     * are tracked via payment_status='refunded' but don't carry separate
     * refund amounts in the current schema.
     */
    public function refunds(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $row = DB::table('tenant_sales')
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('refund_of_sale_id')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) as count, SUM(total_cents) as total')
            ->first();

        return [
            'refund_count'      => (int) ($row->count ?? 0),
            'refund_total_cents'=> (int) ($row->total ?? 0),
        ];
    }

    /**
     * Tax & fees — collected tax across both streams.
     */
    public function taxAndFees(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $apptTax = (int) DB::table('tenant_appointments')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->sum('tax_cents');

        $saleTax = (int) DB::table('tenant_sales')
            ->where('tenant_id', $this->tenant->id)
            ->where('payment_status', 'paid')
            ->whereNull('refund_of_sale_id')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->sum('tax_cents');

        return [
            'service_tax_cents' => $apptTax,
            'retail_tax_cents'  => $saleTax,
            'total_tax_cents'   => $apptTax + $saleTax,
        ];
    }

    public function drawerAndTill(): array
    {
        return [
            'is_stub' => true,
            'reason'  => 'Awaiting register-session tracking schema. Coming with multi-register feature.',
        ];
    }

    public function stripePayouts(): array
    {
        return [
            'is_stub' => true,
            'reason'  => 'Awaiting Stripe Connect integration + local payout cache. Coming after Connect goes live.',
        ];
    }
}
