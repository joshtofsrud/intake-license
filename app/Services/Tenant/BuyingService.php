<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantPurchaseOrder as PO;
use App\Models\Tenant\TenantPurchaseOrderLine as Line;
use App\Models\Tenant\TenantSpecialOrder as SO;
use App\Models\Tenant\TenantVendor;
use App\Services\Distributors\StockVelocityRecorder;
use App\Services\Tenant\ReportBuilder\ReportCatalog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-BUYING: what's selling, how long stock lasts counting what's on order,
 * what distributors have left, what was searched for and not found, and
 * Open to buy.
 *
 * "Sold" is every unit that left stock to a customer: register and online
 * sales and parts used on work orders, refunds taken back off. It reads the
 * stock ledger, so it is per location exactly as stock is.
 *
 * "On order" is what's still owed on PLACED purchase orders. Units on a line
 * that are for a customer's special order are shown, but they don't count as
 * stock: they're already spoken for. Drafts are never on order.
 */
class BuyingService
{
    public const CONSUMED = ['sale', 'refund', 'appointment', 'appointment_refund'];
    public const HISTORY_WEEKS = 26;
    public const WINDOWS = [4 => '30 days', 13 => '90 days', 26 => '6 months'];

    public static function settings(Tenant $tenant): array
    {
        $s = (array) (($tenant->settings ?? [])['buying'] ?? []);

        return [
            'pace_weeks'  => max(1, min(13, (int) ($s['pace_weeks'] ?? 4))),
            'soon_days'   => max(1, min(120, (int) ($s['soon_days'] ?? 21))),
            'over_days'   => max(30, min(730, (int) ($s['over_days'] ?? 120))),
            'cover_weeks' => max(1, min(26, (int) ($s['cover_weeks'] ?? 4))),
            'lead_days'   => max(1, min(90, (int) ($s['lead_days'] ?? 7))),
        ];
    }

    public static function locations(Tenant $tenant): Collection
    {
        return TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Items that sold in the window, with everything the Moving tab and the
     * drawer need. Plain arrays, ready for the page.
     */
    public function moving(Tenant $tenant, ?string $locationId, int $weeks): array
    {
        $set = self::settings($tenant);
        $locIds = self::locations($tenant)->pluck('id')->all();
        $locs = $locationId ? [$locationId] : $locIds;
        if (! $locs) {
            return [];
        }
        $today = now()->startOfDay();
        $since = $today->copy()->subWeeks(self::HISTORY_WEEKS)->addDay();

        // weekly units out, newest week = 0
        $rows = DB::table('tenant_inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->whereIn('movement_type', self::CONSUMED)
            ->whereIn('location_id', $locs)
            ->where('created_at', '>=', $since)
            ->selectRaw('inventory_item_id as id, FLOOR(DATEDIFF(?, DATE(created_at)) / 7) as wk, SUM(-quantity_delta) as units', [$today->toDateString()])
            ->groupBy('inventory_item_id', 'wk')
            ->get();
        $series = [];
        foreach ($rows as $r) {
            $wk = (int) $r->wk;
            if ($wk < 0 || $wk >= self::HISTORY_WEEKS) {
                continue;
            }
            $series[$r->id] ??= array_fill(0, self::HISTORY_WEEKS, 0);
            $series[$r->id][self::HISTORY_WEEKS - 1 - $wk] += (int) $r->units;
        }
        // moving = sold more than came back in the window
        $series = array_filter($series, fn ($a) => array_sum(array_slice($a, -$weeks)) > 0);
        if (! $series) {
            return [];
        }
        $ids = array_keys($series);

        $items = TenantInventoryItem::where('tenant_id', $tenant->id)->whereIn('id', $ids)
            ->where('is_active', true)->whereNull('deleted_at')
            ->with('distributorCatalog:id,manufacturer')
            ->get()->keyBy('id');
        $ids = $items->keys()->all();
        if (! $ids) {
            return [];
        }

        $stock = DB::table('tenant_inventory_item_locations')
            ->whereIn('inventory_item_id', $ids)->whereIn('location_id', $locs)
            ->selectRaw('inventory_item_id as id, SUM(computed_stock_count) as n, SUM(reserved_count) as held')
            ->groupBy('inventory_item_id')->get()->keyBy('id');

        $onPo = $this->owedLines($tenant, $ids, $locationId);
        $drafts = $this->draftLines($tenant, $ids, $locationId);
        $vendors = $this->vendorChoices($tenant, $ids, $items);
        $dist = $this->distributor($ids, $items, $vendors);
        $cats = $this->rootCategories($tenant);

        $out = [];
        foreach ($ids as $id) {
            $it = $items[$id];
            $wk = $series[$id];
            $pace = array_sum(array_slice($wk, -$set['pace_weeks'])) / $set['pace_weeks'];
            $daily = $pace / 7;
            $onHand = (int) ($stock[$id]->n ?? 0);
            $held = (int) ($stock[$id]->held ?? 0);
            $lines = $onPo[$id] ?? [];
            $owed = array_sum(array_column($lines, 'qty'));
            $cust = array_sum(array_column($lines, 'cust'));
            $forStock = max(0, $owed - $cust);
            $next = null;
            foreach ($lines as $l) {
                if ($l['due'] !== null && ($next === null || $l['due'] < $next['due'])) {
                    $next = $l;
                }
            }
            $next ??= $lines[0] ?? null;
            $late = array_values(array_filter($lines, fn ($l) => $l['due'] !== null && $l['due'] < 0));
            $days = $daily > 0 ? max(0, $onHand) / $daily : null;
            $withPo = $daily > 0 ? (max(0, $onHand) + $forStock) / $daily : null;
            $choice = $vendors[$id][0] ?? null;
            $lead = $choice['lead'] ?? $set['lead_days'];
            $gap = ($next && $next['due'] !== null && $next['due'] > 0 && $days !== null && $days < $next['due']) ? (int) round($next['due'] - $days) : 0;

            if ($onHand <= 0) {
                $st = 'out';
            } elseif ($gap > 0 || ($forStock === 0 && $days !== null && $days < $lead) || ($late && $days !== null && $days < $lead)) {
                $st = 'reorder';
            } elseif ($withPo !== null && $withPo < $set['soon_days']) {
                $st = 'soon';
            } elseif ($days !== null && $days > $set['over_days']) {
                $st = 'over';
            } else {
                $st = 'ok';
            }

            $cost = $it->effectiveCostCents();
            $catId = $cats['root'][$it->category_id] ?? null;
            $out[] = [
                'id'      => $id,
                'name'    => (string) $it->name,
                'sku'     => (string) ($it->sku ?? ''),
                'brand'   => trim((string) ($it->shop_brand ?: ($it->distributorCatalog?->manufacturer ?? ''))),
                'cat'     => $catId ? ($cats['names'][$catId] ?? '') : 'Uncategorized',
                'wk'      => $wk,
                'pace'    => round($pace, 2),
                'sold'    => array_sum(array_slice($wk, -$weeks)),
                'onHand'  => $onHand,
                'held'    => $held,
                'days'    => $days === null ? null : round($days, 1),
                'withPo'  => $withPo === null ? null : round($withPo, 1),
                'lines'   => $lines,
                'owed'    => $owed,
                'cust'    => $cust,
                'forStock'=> $forStock,
                'next'    => $next,
                'late'    => count($late),
                'gap'     => $gap,
                'st'      => $st,
                'lead'    => $lead,
                'vendors' => $vendors[$id] ?? [],
                'dist'    => $dist[$id] ?? null,
                'draft'   => $drafts[$id] ?? [],
                'cost'    => $cost,
                'price'   => $it->effectiveSellPriceCents(),
            ];
        }

        $order = ['out' => 0, 'reorder' => 1, 'soon' => 2, 'ok' => 3, 'over' => 4];
        usort($out, fn ($a, $b) => [$order[$a['st']], $a['days'] ?? INF] <=> [$order[$b['st']], $b['days'] ?? INF]);

        return $out;
    }

    /** Owed on placed POs, per item: [po, id, loc, qty, cust, due(days from today, <0 late), date] */
    private function owedLines(Tenant $tenant, array $ids, ?string $locationId): array
    {
        $lines = Line::query()
            ->join('tenant_purchase_orders as po', 'po.id', '=', 'tenant_purchase_order_lines.purchase_order_id')
            ->where('po.tenant_id', $tenant->id)->whereIn('po.status', PO::OPEN)->whereNull('po.deleted_at')
            ->when($locationId, fn ($q) => $q->where('po.location_id', $locationId))
            ->whereIn('tenant_purchase_order_lines.inventory_item_id', $ids)
            ->select('tenant_purchase_order_lines.*', 'po.po_number', 'po.location_id as po_location_id', 'po.expected_date as po_expected')
            ->get();
        if ($lines->isEmpty()) {
            return [];
        }
        $cust = SO::whereIn('purchase_order_line_id', $lines->pluck('id'))->where('status', SO::STATUS_ORDERED)
            ->selectRaw('purchase_order_line_id as id, SUM(quantity) as n')->groupBy('purchase_order_line_id')->pluck('n', 'id');
        $locNames = self::locations($tenant)->pluck('name', 'id');
        $today = now()->startOfDay();
        $out = [];
        foreach ($lines as $l) {
            $owed = $l->owed();
            if ($owed <= 0) {
                continue;
            }
            $date = $l->expected_date ?? ($l->po_expected ? Carbon::parse($l->po_expected) : null);
            $out[$l->inventory_item_id][] = [
                'po'   => $l->po_number,
                'poId' => $l->purchase_order_id,
                'loc'  => $locNames[$l->po_location_id] ?? '',
                'qty'  => $owed,
                'cust' => min($owed, (int) ($cust[$l->id] ?? 0)),
                'due'  => $date ? (int) $today->diffInDays(Carbon::parse($date)->startOfDay(), false) : null,
                'date' => $date ? Carbon::parse($date)->format('M j') : null,
                'cost' => $l->unit_cost_cents,
            ];
        }

        return $out;
    }

    /** Items already on a draft PO: [po, poId, vendorId, locId, qty] */
    private function draftLines(Tenant $tenant, array $ids, ?string $locationId): array
    {
        $rows = DB::table('tenant_purchase_order_lines as l')
            ->join('tenant_purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
            ->where('po.tenant_id', $tenant->id)->where('po.status', PO::DRAFT)->whereNull('po.deleted_at')
            ->when($locationId, fn ($q) => $q->where('po.location_id', $locationId))
            ->whereIn('l.inventory_item_id', $ids)
            ->get(['l.inventory_item_id', 'l.qty_ordered', 'po.po_number', 'po.id as po_id', 'po.vendor_id', 'po.location_id']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->inventory_item_id][] = ['po' => $r->po_number, 'poId' => $r->po_id, 'vendor' => $r->vendor_id, 'loc' => $r->location_id, 'qty' => (int) $r->qty_ordered];
        }

        return $out;
    }

    /**
     * Vendors each item can come from, best first: in stock there, preferred,
     * the item's default, then cheapest. Cost is net of the vendor's program
     * discount. An item no vendor is linked to gets none; the page offers every
     * vendor for it.
     */
    private function vendorChoices(Tenant $tenant, array $ids, Collection $items): array
    {
        $vendors = TenantVendor::where('tenant_id', $tenant->id)->where('is_active', true)->get()->keyBy('id');
        $rows = DB::table('tenant_inventory_item_vendors')->whereIn('inventory_item_id', $ids)->whereIn('vendor_id', $vendors->keys())->get();
        $out = [];
        foreach ($rows as $r) {
            $v = $vendors[$r->vendor_id];
            $list = $r->live_cost_cents ?? $r->unit_cost_cents;
            $out[$r->inventory_item_id][] = [
                'id'        => $v->id,
                'name'      => $v->name,
                'code'      => $r->distributor_code ?: $v->distributor_code,
                'catalog'   => $r->distributor_catalog_id,
                'cost'      => $list !== null ? ($v->netCostCents((int) $list) ?? (int) $list) : null,
                'avail'     => $r->live_avail !== null ? (int) $r->live_avail : null,
                'preferred' => (bool) $r->is_preferred,
                'default'   => ($items[$r->inventory_item_id]->default_vendor_id ?? null) === $v->id,
                'lead'      => self::leadDays($v, $r->lead_time_days),
            ];
        }
        foreach ($out as $id => $list) {
            usort($list, fn ($a, $b) => [($b['avail'] ?? 0) > 0, $b['preferred'], $b['default'], -($a['cost'] ?? PHP_INT_MAX)]
                <=> [($a['avail'] ?? 0) > 0, $a['preferred'], $a['default'], -($b['cost'] ?? PHP_INT_MAX)]);
            $out[$id] = $list;
        }

        return $out;
    }

    public static function leadDays(TenantVendor $v, $itemLead = null): ?int
    {
        if ($v->lead_time_mode === 'learned' && (int) $v->learned_po_count >= 3 && $v->learned_lead_days !== null) {
            return (int) round((float) $v->learned_lead_days);
        }
        if ($v->lead_time_days !== null) {
            return (int) $v->lead_time_days;
        }

        return $itemLead !== null ? (int) $itemLead : null;
    }

    /**
     * What the item's distributor has left, as master admin allows shops to
     * see it (Distributor velocity > Shop visibility). Null when hidden.
     */
    private function distributor(array $ids, Collection $items, array $vendors): array
    {
        $v = ReportCatalog::velocity();
        if (! $v['report_on']) {
            return [];
        }
        $want = [];
        foreach ($ids as $id) {
            $cat = null; $code = null;
            foreach ($vendors[$id] ?? [] as $c) {
                if ($c['catalog']) { $cat = $c['catalog']; $code = $c['code']; break; }
            }
            $cat ??= $items[$id]->distributor_catalog_id;
            if ($cat) {
                $want[$id] = [$cat, $code];
            }
        }
        if (! $want) {
            return [];
        }
        $sum = DB::table('distributor_stock_summary')
            ->whereIn('distributor_catalog_id', array_unique(array_column($want, 0)))
            ->when(is_array($v['vendors']), fn ($q) => $q->whereIn('distributor_code', $v['vendors'] ?: ['-none-']))
            ->get()->groupBy('distributor_catalog_id');
        $today = now()->startOfDay();
        $out = [];
        foreach ($want as $id => [$cat, $code]) {
            $cands = $sum[$cat] ?? collect();
            $r = ($code ? $cands->firstWhere('distributor_code', $code) : null) ?? $cands->first();
            if (! $r) {
                continue;
            }
            $conf = StockVelocityRecorder::confidence($r);
            $usable = $r->rate_per_day !== null && (float) $r->rate_per_day > 0 && (! $v['good'] || $conf === 'good');
            $avail = max(0, (int) $r->avail);
            $out[$id] = [
                'code'    => (string) $r->distributor_code,
                'avail'   => $avail,
                'pace'    => $v['pace'] && $usable ? round((float) $r->rate_per_day, 2) : null,
                'days'    => $v['days'] && $usable ? (int) round($avail / (float) $r->rate_per_day) : null,
                'restock' => $v['arrival'] && $r->next_arrival_on ? Carbon::parse($r->next_arrival_on)->format('M j') : null,
                'restockIn' => $v['arrival'] && $r->next_arrival_on ? (int) $today->diffInDays(Carbon::parse($r->next_arrival_on)->startOfDay(), false) : null,
            ];
        }

        return $out;
    }

    /** category id => top-level ancestor id, and names of the top level */
    public function rootCategories(Tenant $tenant): array
    {
        $all = DB::table('tenant_inventory_categories')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->get(['id', 'parent_id', 'name', 'sort_order'])->keyBy('id');
        $root = [];
        foreach ($all as $id => $c) {
            $cur = $c; $guard = 0;
            while ($cur->parent_id && isset($all[$cur->parent_id]) && $guard++ < 10) {
                $cur = $all[$cur->parent_id];
            }
            $root[$id] = $cur->id;
        }
        $names = $all->filter(fn ($c) => ! $c->parent_id || ! isset($all[$c->parent_id]))
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])->pluck('name', 'id')->all();

        return ['root' => $root, 'names' => $names];
    }

    /**
     * Searches that found nothing in stock, last $days days, not dismissed
     * (or searched again since). Location: that register plus the online store.
     */
    public function searches(Tenant $tenant, ?string $locationId, int $days = 90): array
    {
        $since = now()->subDays($days - 1)->toDateString();
        $rows = DB::table('tenant_buying_searches')
            ->where('tenant_id', $tenant->id)->where('day', '>=', $since)
            ->when($locationId, fn ($q) => $q->where(fn ($w) => $w->where('location_id', $locationId)->orWhere('source', 'online')))
            ->orderBy('last_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $dismissed = DB::table('tenant_buying_dismissals')->where('tenant_id', $tenant->id)->pluck('dismissed_at', 'query_key');
        $groups = [];
        foreach ($rows as $r) {
            $d = $dismissed[$r->query_key] ?? null;
            if ($d && Carbon::parse($r->last_at)->lte(Carbon::parse($d))) {
                continue; // counted before it was dismissed
            }
            $g = &$groups[$r->query_key];
            $g ??= ['key' => $r->query_key, 'query' => $r->query, 'times' => 0, 'where' => [], 'last' => null, 'item' => null];
            $g['times'] += (int) $r->times;
            $g['where'][$r->source] = true;
            $g['query'] = $r->query;
            $g['last'] = $r->last_at;
            if ($r->closest_item_id) {
                $g['item'] = $r->closest_item_id;
            }
            unset($g);
        }
        $itemIds = array_values(array_filter(array_column($groups, 'item')));
        $items = $itemIds ? TenantInventoryItem::where('tenant_id', $tenant->id)->whereIn('id', $itemIds)->where('is_active', true)->get()->keyBy('id') : collect();
        $vendors = $items->isNotEmpty() ? $this->vendorChoices($tenant, $items->keys()->all(), $items) : [];
        $locs = $locationId ? [$locationId] : self::locations($tenant)->pluck('id')->all();
        $stock = $itemIds ? DB::table('tenant_inventory_item_locations')->whereIn('inventory_item_id', $itemIds)->whereIn('location_id', $locs)
            ->selectRaw('inventory_item_id as id, SUM(computed_stock_count) as n')->groupBy('inventory_item_id')->pluck('n', 'id') : collect();

        $out = [];
        foreach ($groups as $g) {
            $it = $g['item'] ? ($items[$g['item']] ?? null) : null;
            $out[] = [
                'key'    => $g['key'],
                'query'  => $g['query'],
                'times'  => $g['times'],
                'where'  => implode(' · ', array_map(fn ($s) => $s === 'online' ? 'Online store' : 'Register', array_keys($g['where']))),
                'last'   => Carbon::parse($g['last'])->diffForHumans(),
                'lastTs' => Carbon::parse($g['last'])->timestamp,
                'itemId' => $it?->id,
                'item'   => $it ? $it->name : null,
                'sku'    => $it ? (string) ($it->sku ?? '') : null,
                'cost'   => $it ? $it->effectiveCostCents() : null,
                'vendors'=> $it ? ($vendors[$it->id] ?? []) : [],
                'stock'  => $it ? (int) ($stock[$it->id] ?? 0) : null,
            ];
        }
        usort($out, fn ($a, $b) => [$b['times'], $b['lastTs']] <=> [$a['times'], $a['lastTs']]);

        return $out;
    }

    /**
     * Open to buy by top-level category. Money in cents.
     * onHand: stock × effective cost. onOrder: owed on placed POs × line cost.
     * drafts: draft lines × line cost (shown, never counted). goal: per location;
     * all locations is the sum.
     */
    public function openToBuy(Tenant $tenant, ?string $locationId): array
    {
        $locs = $locationId ? [$locationId] : self::locations($tenant)->pluck('id')->all();
        $cats = $this->rootCategories($tenant);
        $rows = [];
        $blank = fn ($name) => ['name' => $name, 'onHand' => 0, 'units' => 0, 'onOrder' => 0, 'drafts' => 0, 'goal' => 0];
        foreach ($cats['names'] as $id => $n) {
            $rows[$id] = $blank($n);
        }
        $rows[''] = $blank('Uncategorized');
        $bucket = fn ($catId) => $catId ? ($cats['root'][$catId] ?? '') : '';

        if ($locs) {
            $stock = DB::table('tenant_inventory_item_locations as il')
                ->join('tenant_inventory_items as it', 'it.id', '=', 'il.inventory_item_id')
                ->where('it.tenant_id', $tenant->id)->whereNull('it.deleted_at')->where('it.is_active', true)
                ->whereIn('il.location_id', $locs)->where('il.computed_stock_count', '>', 0)
                ->selectRaw('it.category_id, SUM(il.computed_stock_count) as units, SUM(il.computed_stock_count * COALESCE(it.received_cost_cents, it.shop_cost_cents, it.catalog_cost_cents, 0)) as cents')
                ->groupBy('it.category_id')->get();
            foreach ($stock as $s) {
                $k = $bucket($s->category_id);
                $rows[$k] ??= $blank('Removed category');
                $rows[$k]['onHand'] += (int) $s->cents;
                $rows[$k]['units'] += (int) $s->units;
            }

            $lines = DB::table('tenant_purchase_order_lines as l')
                ->join('tenant_purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
                ->leftJoin('tenant_inventory_items as it', 'it.id', '=', 'l.inventory_item_id')
                ->where('po.tenant_id', $tenant->id)->whereNull('po.deleted_at')
                ->whereIn('po.status', array_merge(PO::OPEN, [PO::DRAFT]))->whereIn('po.location_id', $locs)
                ->get(['po.status', 'it.category_id', 'l.qty_ordered', 'l.qty_received', 'l.qty_cancelled', 'l.unit_cost_cents']);
            foreach ($lines as $l) {
                $k = $bucket($l->category_id);
                $rows[$k] ??= $blank('Removed category');
                if ($l->status === PO::DRAFT) {
                    $rows[$k]['drafts'] += (int) $l->qty_ordered * (int) $l->unit_cost_cents;
                } else {
                    $owed = max(0, (int) $l->qty_ordered - (int) $l->qty_received - (int) $l->qty_cancelled);
                    $rows[$k]['onOrder'] += $owed * (int) $l->unit_cost_cents;
                }
            }

            $goals = DB::table('tenant_buying_goals')->where('tenant_id', $tenant->id)->whereIn('location_id', $locs)
                ->selectRaw('category_id, SUM(goal_cents) as g')->groupBy('category_id')->pluck('g', 'category_id');
            foreach ($goals as $cat => $g) {
                if (isset($rows[$cat])) {
                    $rows[$cat]['goal'] = (int) $g;
                }
            }
        }
        $rows = array_filter($rows, fn ($r, $k) => $k !== '' || $r['onHand'] || $r['onOrder'] || $r['drafts'] || $r['goal'], ARRAY_FILTER_USE_BOTH);
        $openPos = PO::where('tenant_id', $tenant->id)->whereIn('status', PO::OPEN)->when($locs, fn ($q) => $q->whereIn('location_id', $locs))->count();
        $out = [];
        foreach ($rows as $k => $r) {
            $out[] = ['id' => (string) $k] + $r;
        }

        return ['rows' => $out, 'openPos' => $openPos];
    }

    public function saveGoals(Tenant $tenant, string $locationId, array $goals, ?string $userId): int
    {
        $valid = array_keys($this->rootCategories($tenant)['names']);
        $n = 0;
        foreach ($goals as $cat => $cents) {
            $cat = (string) $cat;
            if ($cat !== '' && ! in_array($cat, $valid, true)) {
                continue;
            }
            $cents = max(0, (int) $cents);
            $match = ['tenant_id' => $tenant->id, 'location_id' => $locationId, 'category_id' => $cat];
            $row = DB::table('tenant_buying_goals')->where($match)->first(['id']);
            if ($row) {
                DB::table('tenant_buying_goals')->where('id', $row->id)->update(['goal_cents' => $cents, 'updated_by_user_id' => $userId, 'updated_at' => now()]);
            } else {
                DB::table('tenant_buying_goals')->insert($match + ['id' => (string) Str::uuid(), 'goal_cents' => $cents, 'updated_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $n++;
        }

        return $n;
    }

    /** Every draft PO (optionally at one location), with its lines. */
    public function drafts(Tenant $tenant, ?string $locationId): array
    {
        return PO::where('tenant_id', $tenant->id)->where('status', PO::DRAFT)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->with(['vendor:id,name', 'location:id,name', 'lines'])
            ->orderBy('created_at')->get()
            ->map(fn ($po) => [
                'id' => $po->id, 'po' => $po->po_number,
                'vendor' => $po->vendor?->name ?? '—', 'vendorId' => $po->vendor_id,
                'loc' => $po->location?->name ?? '—', 'locId' => $po->location_id,
                'total' => (int) $po->lines->sum(fn ($l) => $l->qty_ordered * (int) $l->unit_cost_cents),
                'lines' => $po->lines->map(fn ($l) => ['name' => $l->name_snapshot, 'sku' => $l->vendor_sku, 'qty' => (int) $l->qty_ordered, 'cost' => $l->unit_cost_cents, 'item' => $l->inventory_item_id])->values()->all(),
            ])->values()->all();
    }

    /**
     * Put $qty of an item on the draft PO for vendor + location (starting one if
     * there isn't one), or raise the line if it's already there.
     */
    public function addToPo(Tenant $tenant, string $itemId, string $vendorId, string $locationId, int $qty): array
    {
        if ($qty < 1) {
            throw new PurchaseOrderException('Quantity must be at least 1.');
        }
        $svc = app(PurchaseOrderService::class);

        return DB::transaction(function () use ($tenant, $itemId, $vendorId, $locationId, $qty, $svc) {
            $existing = PO::where('tenant_id', $tenant->id)->where('status', PO::DRAFT)
                ->where('vendor_id', $vendorId)->where('location_id', $locationId)->exists();
            $po = $svc->draftFor($tenant, $vendorId, $locationId);
            $line = $po->lines()->where('inventory_item_id', $itemId)->first();
            if ($line) {
                $svc->updateLine($line, (int) $line->qty_ordered + $qty, null, false);
                $raised = true;
            } else {
                $line = $svc->addLine($po, $itemId, null, $qty, null);
                $raised = false;
            }
            $svc->event($po, ($raised ? 'Raised by ' : 'Added ') . "{$qty} × {$line->name_snapshot} from Buying.", auth('tenant')->id());

            return ['po' => $po->fresh(), 'made' => ! $existing, 'raised' => $raised];
        });
    }
}
