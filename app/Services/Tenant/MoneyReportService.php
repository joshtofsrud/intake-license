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
        // Service revenue: paid + delivered appointments
        $apptRevenue = (int) DB::table('tenant_appointments')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->where('payment_status', 'paid')
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_cents');

        // Retail revenue: paid, non-refund sales
        $saleRevenue = (int) DB::table('tenant_sales')
            ->where('tenant_id', $this->tenant->id)
            ->where('payment_status', 'paid')
            ->whereNull('refund_of_sale_id')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_cents');

        $totalRevenue = $apptRevenue + $saleRevenue;

        return [
            'service_revenue_cents' => $apptRevenue,
            'retail_revenue_cents'  => $saleRevenue,
            'total_revenue_cents'   => $totalRevenue,
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
