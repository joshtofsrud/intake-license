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

        // tenant_users has a single `name` column (see migration 000002), NOT
        // first_name / last_name. Those columns live on tenant_customers.
        $list = DB::table('tenant_sales as s')
            ->leftJoin('tenant_users as u', 'u.id', '=', 's.rang_up_by_user_id')
            ->where('s.tenant_id', $this->tenant->id)
            ->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                s.rang_up_by_user_id,
                u.name as user_name,
                COUNT(*) as sale_count,
                SUM(s.total_cents) as revenue
            ')
            ->groupBy('s.rang_up_by_user_id', 'u.name')
            ->orderByDesc('revenue')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn($r) => [
                'user_id'    => $r->rang_up_by_user_id,
                'name'       => $r->user_name ?: '(deleted user)',
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
        // Schema reminders (verified against migration 2026_05_01_000004):
        //   tenant_inventory_items has computed_stock_count (NOT stock_quantity),
        //   shop_reorder_threshold (per-item, nullable), is_active boolean, and
        //   soft-deletes (deleted_at).
        // Low-stock matches the filter in InventoryController.index:
        //   threshold not null AND computed_stock_count <= shop_reorder_threshold.
        // Items with no threshold fall back to LOW_STOCK_THRESHOLD as a default
        // floor so freshly-imported items still surface as low when near zero.
        $lowQuery = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('computed_stock_count', '>', 0)
            ->where(function ($q) {
                $q->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold')
                  ->orWhere(function ($q2) {
                      $q2->whereNull('shop_reorder_threshold')
                         ->where('computed_stock_count', '<=', self::LOW_STOCK_THRESHOLD);
                  });
            });

        $lowCount = (clone $lowQuery)->count();

        // Dead stock: in stock AND no sale via tenant_sale_items in the window.
        $deadCutoff = $this->tenant->localToday()->copy()->subDays(self::DEAD_STOCK_DAYS);
        $deadQuery = DB::table('tenant_inventory_items as i')
            ->where('i.tenant_id', $this->tenant->id)
            ->where('i.is_active', true)
            ->whereNull('i.deleted_at')
            ->where('i.computed_stock_count', '>', 0)
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
            ->orderBy('computed_stock_count')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'name', 'sku', 'computed_stock_count', 'shop_reorder_threshold'])
            ->map(fn($i) => [
                'name'      => $i->name,
                'sku'       => $i->sku,
                'stock'     => (int) $i->computed_stock_count,
                'threshold' => $i->shop_reorder_threshold !== null ? (int) $i->shop_reorder_threshold : null,
            ])
            ->all();

        $deadList = (clone $deadQuery)
            ->orderByDesc('i.computed_stock_count')
            ->limit(self::LIST_LIMIT)
            ->get(['i.id', 'i.name', 'i.sku', 'i.computed_stock_count'])
            ->map(fn($i) => [
                'name'  => $i->name,
                'sku'   => $i->sku,
                'stock' => (int) $i->computed_stock_count,
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
        // Schema reminders (verified against migrations 000006 + 000007):
        //   tenant_inventory_receive_shipments    has received_date (DATE), NOT received_at.
        //   tenant_inventory_receive_shipment_items has total_cost_cents per line. The
        //     parent shipment table does NOT store a roll-up cost.
        // So count from the parent (one row per shipment) and sum cost from items.
        $shipmentCount = (int) DB::table('tenant_inventory_receive_shipments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('received_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', 'committed')
            ->count();

        $totalCost = (int) DB::table('tenant_inventory_receive_shipment_items as ii')
            ->join('tenant_inventory_receive_shipments as s', 's.id', '=', 'ii.shipment_id')
            ->where('s.tenant_id', $this->tenant->id)
            ->whereBetween('s.received_date', [$from->toDateString(), $to->toDateString()])
            ->where('s.status', 'committed')
            ->sum('ii.total_cost_cents');

        return [
            'shipment_count'   => $shipmentCount,
            'total_cost_cents' => $totalCost,
        ];
    }
}
