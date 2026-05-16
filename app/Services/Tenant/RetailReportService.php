<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RetailReportService — date-ranged retail/POS analytics for the Retail tab.
 *
 * Panels (all real, none stubbed):
 *   - salesSummary()    : total sales count + revenue + avg ticket
 *   - salesByUser()     : ranked by rang_up_by_user_id (who rang what up)
 *   - topSkus()         : best-selling inventory items by quantity
 *   - margin()          : revenue - cost on product line items (where cost known)
 *   - inventoryHealth() : low stock alerts, dead stock list
 *   - receiving()       : received shipments count + value in range
 */
class RetailReportService
{
    // PRODUCT DECISION: low stock threshold. Items with stock <= 5 are flagged.
    private const LOW_STOCK_THRESHOLD = 5;

    // PRODUCT DECISION: dead stock = no sale in 90 days AND stock > 0.
    private const DEAD_STOCK_DAYS = 90;

    private const LIST_LIMIT = 50;

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Sales summary: count + revenue + avg ticket on paid, non-refund sales.
     */
    public function salesSummary(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        // PRODUCT DECISION: counts paid sales only, excludes refund rows.
        $row = DB::table('tenant_sales')
            ->where('tenant_id', $this->tenant->id)
            ->where('payment_status', 'paid')
            ->whereNull('refund_of_sale_id')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) as count, SUM(total_cents) as revenue')
            ->first();

        $count = (int) ($row->count ?? 0);
        $revenue = (int) ($row->revenue ?? 0);
        $avg = $count > 0 ? (int) round($revenue / $count) : 0;

        return [
            'count'        => $count,
            'revenue_cents'=> $revenue,
            'avg_ticket_cents' => $avg,
        ];
    }

    /**
     * Sales by user — who rang up what.
     * PRODUCT DECISION: keyed on rang_up_by_user_id (the cashier/operator).
     */
    public function salesByUser(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        if ($aggregatesOnly) {
            return ['list' => []];
        }

        $list = DB::table('tenant_sales as s')
            ->leftJoin('tenant_users as u', 'u.id', '=', 's.rang_up_by_user_id')
            ->where('s.tenant_id', $this->tenant->id)
            ->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                s.rang_up_by_user_id,
                u.first_name, u.last_name,
                COUNT(*) as sale_count,
                SUM(s.total_cents) as revenue
            ')
            ->groupBy('s.rang_up_by_user_id', 'u.first_name', 'u.last_name')
            ->orderByDesc('revenue')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn($r) => [
                'user_id'    => $r->rang_up_by_user_id,
                'name'       => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '(deleted user)',
                'sale_count' => (int) $r->sale_count,
                'revenue_cents' => (int) $r->revenue,
            ])
            ->all();

        return ['list' => $list];
    }

    /**
     * Top SKUs — best-selling inventory items by quantity.
     * PRODUCT DECISION: ranked by quantity sold, not revenue. Operators
     * want "what am I moving" more than "what's earning."
     */
    public function topSkus(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        if ($aggregatesOnly) {
            return ['list' => []];
        }

        $list = DB::table('tenant_sale_items as si')
            ->join('tenant_sales as s', 's.id', '=', 'si.sale_id')
            ->where('si.tenant_id', $this->tenant->id)
            ->where('si.type', 'product')
            ->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                si.name_snapshot as name,
                SUM(si.quantity) as qty,
                SUM(si.line_total_cents) as revenue
            ')
            ->groupBy('si.name_snapshot')
            ->orderByDesc('qty')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn($r) => [
                'name'    => $r->name,
                'qty'     => (int) $r->qty,
                'revenue_cents' => (int) $r->revenue,
            ])
            ->all();

        return ['list' => $list];
    }

    /**
     * Margin — revenue vs cost on product line items.
     * PRODUCT DECISION: includes only rows where cost_cents_snapshot is set.
     * Rows missing cost are excluded from both sides (not zero-cost imputed).
     */
    public function margin(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $row = DB::table('tenant_sale_items as si')
            ->join('tenant_sales as s', 's.id', '=', 'si.sale_id')
            ->where('si.tenant_id', $this->tenant->id)
            ->where('si.type', 'product')
            ->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')
            ->whereNotNull('si.cost_cents_snapshot')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                SUM(si.line_total_cents) as revenue,
                SUM(si.cost_cents_snapshot * si.quantity) as cost
            ')
            ->first();

        $revenue = (int) ($row->revenue ?? 0);
        $cost = (int) ($row->cost ?? 0);
        $margin = $revenue - $cost;
        $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 1) : 0.0;

        return [
            'revenue_cents' => $revenue,
            'cost_cents'    => $cost,
            'margin_cents'  => $margin,
            'margin_pct'    => $marginPct,
        ];
    }

    /**
     * Inventory health — low stock + dead stock counts and lists.
     */
    public function inventoryHealth(bool $aggregatesOnly = false): array
    {
        $lowQuery = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('stock_quantity', '<=', self::LOW_STOCK_THRESHOLD)
            ->where('stock_quantity', '>', 0);

        $lowCount = (clone $lowQuery)->count();

        // Dead stock: stock > 0 AND no sale via tenant_sale_items in window
        $deadCutoff = $this->tenant->localToday()->copy()->subDays(self::DEAD_STOCK_DAYS);
        $deadQuery = DB::table('tenant_inventory_items as i')
            ->where('i.tenant_id', $this->tenant->id)
            ->where('i.stock_quantity', '>', 0)
            ->whereNotExists(function ($q) use ($deadCutoff) {
                $q->select(DB::raw(1))
                  ->from('tenant_sale_items as si')
                  ->join('tenant_sales as s', 's.id', '=', 'si.sale_id')
                  ->whereColumn('si.inventory_item_id', 'i.id')
                  ->where('s.sale_date', '>=', $deadCutoff->toDateString());
            });

        $deadCount = (clone $deadQuery)->count();

        if ($aggregatesOnly) {
            return [
                'low_threshold' => self::LOW_STOCK_THRESHOLD,
                'low_count'     => $lowCount,
                'dead_days'     => self::DEAD_STOCK_DAYS,
                'dead_count'    => $deadCount,
                'low_list'      => [],
                'dead_list'     => [],
            ];
        }

        $lowList = (clone $lowQuery)
            ->orderBy('stock_quantity')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'name', 'sku', 'stock_quantity'])
            ->map(fn($i) => [
                'name'  => $i->name,
                'sku'   => $i->sku,
                'stock' => (int) $i->stock_quantity,
            ])
            ->all();

        $deadList = (clone $deadQuery)
            ->orderByDesc('i.stock_quantity')
            ->limit(self::LIST_LIMIT)
            ->get(['i.id', 'i.name', 'i.sku', 'i.stock_quantity'])
            ->map(fn($i) => [
                'name'  => $i->name,
                'sku'   => $i->sku,
                'stock' => (int) $i->stock_quantity,
            ])
            ->all();

        return [
            'low_threshold' => self::LOW_STOCK_THRESHOLD,
            'low_count'     => $lowCount,
            'dead_days'     => self::DEAD_STOCK_DAYS,
            'dead_count'    => $deadCount,
            'low_list'      => $lowList,
            'dead_list'     => $deadList,
        ];
    }

    /**
     * Receiving — shipments received in range.
     */
    public function receiving(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $row = DB::table('tenant_inventory_receive_shipments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('received_at', [$from->toDateString() . ' 00:00:00', $to->toDateString() . ' 23:59:59'])
            ->selectRaw('COUNT(*) as count, SUM(total_cost_cents) as cost')
            ->first();

        return [
            'shipment_count' => (int) ($row->count ?? 0),
            'total_cost_cents' => (int) ($row->cost ?? 0),
        ];
    }
}
