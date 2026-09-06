<?php
// MARKER-DUPE-REPORT

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Everything that should have folded and didn't, plus everything that did.
 * Read-only: this decides whether a merge is mechanical or needs judgement.
 */
class ReportItemDuplicates extends Command
{
    protected $signature = 'intake:report-item-duplicates
                            {--tenant= : subdomain or id; all tenants if omitted}
                            {--out=/tmp/item-duplicates.csv : where to write}';

    protected $description = 'Report inventory items that share an EAN, MPN or SKU but were never merged';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), function ($q) {
                $t = $this->option('tenant');
                $q->where('id', $t)->orWhere('subdomain', $t);
            })->get();

        $path = (string) $this->option('out');
        $fh = fopen($path, 'w');
        fputcsv($fh, ['section', 'group_key', 'reason', 'items_in_group', 'with_stock', 'with_history',
                      'tenant', 'item_id', 'sku', 'name', 'upc', 'ean', 'mpn', 'stock',
                      'sources', 'distributors', 'vendor_skus', 'costs', 'sold_qty', 'movements', 'created']);

        $totals = ['groups' => 0, 'items' => 0, 'both_stock' => 0, 'both_history' => 0, 'folded' => 0];

        foreach ($tenants as $tenant) {
            $items = DB::table('tenant_inventory_items')
                ->where('tenant_id', $tenant->id)
                ->get(['id', 'sku', 'name', 'catalog_upc', 'catalog_ean', 'catalog_mpn',
                       'computed_stock_count', 'created_at']);
            if ($items->isEmpty()) continue;

            $ids = $items->pluck('id');

            // Sources per item — the fold evidence.
            $sources = DB::table('tenant_inventory_item_vendors')
                ->whereIn('inventory_item_id', $ids)
                ->get(['inventory_item_id', 'distributor_code', 'vendor_sku', 'unit_cost_cents'])
                ->groupBy('inventory_item_id');

            $sold = DB::table('tenant_sale_items')
                ->whereIn('inventory_item_id', $ids)
                ->selectRaw('inventory_item_id, SUM(quantity) as q')
                ->groupBy('inventory_item_id')->pluck('q', 'inventory_item_id');

            $moves = DB::table('tenant_inventory_movements')
                ->whereIn('inventory_item_id', $ids)
                ->selectRaw('inventory_item_id, COUNT(*) as n')
                ->groupBy('inventory_item_id')->pluck('n', 'inventory_item_id');

            // Blank is NOT a key: "" and null must never match each other, or
            // every identifier-less item from one distributor collapses into one.
            $key = fn ($v) => ($v === null || trim((string) $v) === '') ? null : strtoupper(trim((string) $v));

            $groups = [];
            foreach ($items as $it) {
                foreach ([['ean', $it->catalog_ean], ['mpn', $it->catalog_mpn], ['sku', $it->sku]] as [$kind, $raw]) {
                    if ($k = $key($raw)) {
                        $groups[$kind . ':' . $k][] = $it;
                    }
                }
            }

            $seenPair = [];
            foreach ($groups as $gk => $rows) {
                if (count($rows) < 2) continue;

                // One pair reported once, under its strongest reason.
                $sig = implode('|', collect($rows)->pluck('id')->sort()->all());
                if (isset($seenPair[$sig])) continue;
                $seenPair[$sig] = true;

                $withStock   = collect($rows)->filter(fn ($r) => (int) $r->computed_stock_count > 0)->count();
                $withHistory = collect($rows)->filter(fn ($r) => ($sold[$r->id] ?? 0) > 0 || ($moves[$r->id] ?? 0) > 0)->count();

                $totals['groups']++;
                $totals['items'] += count($rows);
                if ($withStock > 1)   $totals['both_stock']++;
                if ($withHistory > 1) $totals['both_history']++;

                [$kind, $val] = explode(':', $gk, 2);
                $reason = match ($kind) {
                    'ean' => 'same EAN — same product, different distributor',
                    'mpn' => 'same manufacturer part number',
                    default => 'same SKU',
                };

                foreach ($rows as $r) {
                    $s = $sources[$r->id] ?? collect();
                    fputcsv($fh, ['not merged', $gk, $reason, count($rows), $withStock, $withHistory,
                        $tenant->subdomain, $r->id, $r->sku, $r->name,
                        $r->catalog_upc, $r->catalog_ean, $r->catalog_mpn,
                        (int) $r->computed_stock_count, $s->count(),
                        $s->pluck('distributor_code')->filter()->implode(' | '),
                        $s->pluck('vendor_sku')->filter()->implode(' | '),
                        $s->map(fn ($x) => $x->unit_cost_cents !== null ? number_format($x->unit_cost_cents / 100, 2) : '')->filter()->implode(' | '),
                        (float) ($sold[$r->id] ?? 0), (int) ($moves[$r->id] ?? 0), $r->created_at]);
                }
            }

            // What DID fold: one item carrying several distributor sources. A
            // fold that pulled unrelated products together shows up here.
            foreach ($sources as $itemId => $s) {
                $distinct = $s->pluck('distributor_code')->filter()->unique();
                if ($distinct->count() < 2) continue;
                $r = $items->firstWhere('id', $itemId);
                if (! $r) continue;
                $totals['folded']++;
                fputcsv($fh, ['folded', 'item:' . $itemId, 'one item, several distributor sources', 1,
                    (int) $r->computed_stock_count > 0 ? 1 : 0,
                    (($sold[$r->id] ?? 0) > 0 || ($moves[$r->id] ?? 0) > 0) ? 1 : 0,
                    $tenant->subdomain, $r->id, $r->sku, $r->name,
                    $r->catalog_upc, $r->catalog_ean, $r->catalog_mpn,
                    (int) $r->computed_stock_count, $s->count(),
                    $distinct->implode(' | '),
                    $s->pluck('vendor_sku')->filter()->implode(' | '),
                    $s->map(fn ($x) => $x->unit_cost_cents !== null ? number_format($x->unit_cost_cents / 100, 2) : '')->filter()->implode(' | '),
                    (float) ($sold[$r->id] ?? 0), (int) ($moves[$r->id] ?? 0), $r->created_at]);
            }
        }

        fclose($fh);

        $this->newLine();
        $this->info("Duplicate groups (should have merged): {$totals['groups']}  ·  items: {$totals['items']}");
        $this->line("  groups where MORE THAN ONE item holds stock:   {$totals['both_stock']}");
        $this->line("  groups where MORE THAN ONE item has history:   {$totals['both_history']}");
        $this->line("  items that DID fold (several sources on one):  {$totals['folded']}");
        $this->newLine();
        $this->info("Written to {$path}");
        $this->line('Nothing was changed.');

        return self::SUCCESS;
    }
}
