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
        // MARKER-PATCH-184E — Revenue = payments received (ledger) in window.
        // Broken into Service (type=service), Retail (type=product), and
        // Uncategorized (everything else, e.g. open_item) by apportioning each
        // sale's payments across its line-item type composition. The three
        // buckets sum to total. Deposits/balances are cash-flow mechanics, not
        // a category — the money attributes by what the sale's lines are.
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

        $serviceRevenue = 0;
        $retailRevenue  = 0;
        $uncategorizedRevenue = 0;
        if ($paidPerSale->isNotEmpty()) {
            $composition = DB::table('tenant_sale_items')
                ->where('tenant_id', $this->tenant->id)
                ->whereIn('sale_id', $paidPerSale->keys()->all())
                ->selectRaw("sale_id,
                    SUM(CASE WHEN type = 'service' THEN line_total_cents ELSE 0 END) as service_cents,
                    SUM(CASE WHEN type = 'product' THEN line_total_cents ELSE 0 END) as retail_cents,
                    SUM(line_total_cents) as all_cents")
                ->groupBy('sale_id')
                ->get()
                ->keyBy('sale_id');

            foreach ($paidPerSale as $saleId => $paidCents) {
                $comp = $composition->get($saleId);
                $all  = (int) ($comp->all_cents ?? 0);
                if ($all > 0) {
                    $svc = (int) round($paidCents * ((int) $comp->service_cents / $all));
                    $ret = (int) round($paidCents * ((int) $comp->retail_cents / $all));
                    // Remainder (rounding + non-service/non-product lines) -> uncategorized.
                    $unc = $paidCents - $svc - $ret;
                } else {
                    // No line items at all -> uncategorized.
                    $svc = 0; $ret = 0; $unc = $paidCents;
                }
                $serviceRevenue       += $svc;
                $retailRevenue        += $ret;
                $uncategorizedRevenue += $unc;
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
