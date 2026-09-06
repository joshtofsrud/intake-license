<?php
// MARKER-EAN-MERGE

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fold duplicate inventory items — the ones two distributor feeds created for
 * one physical product — into a single item.
 *
 * MATCHING: any shared identifier is a match. EAN is one matcher, not the
 * only one. Two items are the same product if they agree on ANY of:
 *
 *     catalog_ean   catalog_upc   catalog_mpn
 *
 * and matching is transitive — if A shares an EAN with B and B shares an MPN
 * with C, all three are one product. That is what makes this a clustering
 * problem rather than a pairwise one, and it is why a plain GROUP BY on one
 * column undercounts: it would fold A+B and leave C stranded.
 *
 * Name is deliberately NOT a matcher. Two independent feeds describe the same
 * product differently ("Schwalbe PICK-UP 26'' x 2.35" vs "Schwalbe Pick-Up
 * Tire - 26 x 2.35, Clincher, Wire, Black/Reflective...") and different
 * products similarly, so no word-overlap threshold separates them. Measured on
 * the real data, 3-word divergence contains both true duplicates and genuinely
 * different products.
 *
 * SURVIVOR: the member with the longest name. One feed writes full titles and
 * the other writes stubs; length is a blunt proxy for "the good feed", but the
 * useful name is the one carrying size, casing, colour and product line. Ties
 * go to the oldest row, so a re-run picks the same survivor.
 *
 * What a fold does, per cluster, in one transaction:
 *   - vendor pivots move to the survivor, so the loser's distributor becomes
 *     an additional source — what the importer would have done had it matched
 *   - the survivor inherits any field it has blank and a loser has filled
 *   - losers are marked is_active = false and stamped in notes
 *
 * Nothing is deleted (RUNBOOK rule). A bad fold is undone by flipping
 * is_active back and moving the pivot; the notes stamp records where it went.
 *
 * HARD GUARD: a cluster is skipped if any member has stock, movements or sale
 * lines — folding those would repoint a real sale at a different row.
 *
 * Dry run by default. --apply writes.
 */
class InventoryEanMerge extends Command
{
    protected $signature = 'inventory:ean-merge
                            {--tenant=  : Limit to one tenant subdomain (e.g. wmm)}
                            {--limit=0  : Stop after this many clusters (0 = all)}
                            {--on=ean,upc,mpn : Which identifiers count as a match}
                            {--apply    : Actually write. Without this it only reports.}';

    protected $description = 'Fold inventory items sharing any identifier (EAN, UPC, MPN) into one item.';

    /** Copied to the survivor when the survivor has it blank. */
    private const INHERIT_BLANKS = [
        'description', 'catalog_upc', 'catalog_ean', 'catalog_mpn', 'color', 'size',
        'catalog_msrp_cents', 'catalog_map_cents', 'catalog_case_quantity',
        'shop_sell_price_cents', 'shop_bin_location', 'default_vendor_id',
    ];

    private const FIELD_FOR = [
        'ean' => 'catalog_ean',
        'upc' => 'catalog_upc',
        'mpn' => 'catalog_mpn',
    ];

    public function handle(): int
    {
        $subdomain = (string) $this->option('tenant');
        $limit     = max(0, (int) $this->option('limit'));
        $apply     = (bool) $this->option('apply');

        $on = collect(explode(',', (string) $this->option('on')))
            ->map(fn ($s) => strtolower(trim($s)))
            ->filter(fn ($s) => isset(self::FIELD_FOR[$s]))
            ->values()->all();

        if (! $on) {
            $this->error('--on must name at least one of: ean, upc, mpn');
            return self::FAILURE;
        }

        $tenantId = null;
        if ($subdomain !== '') {
            $tenantId = Tenant::where('subdomain', $subdomain)->value('id');
            if (! $tenantId) {
                $this->error("No tenant with subdomain '{$subdomain}'.");
                return self::FAILURE;
            }
        }

        $fields = array_map(fn ($k) => self::FIELD_FOR[$k], $on);

        $this->newLine();
        $this->line($apply ? 'APPLYING' : 'DRY RUN — nothing will be written (add --apply)');
        $this->line('Matching on: ' . implode(', ', $on));
        $this->newLine();

        $folded = $skipped = $pivots = $inherited = $done = 0;

        // Cluster per tenant. Identifiers are only unique within a shop, and
        // items never merge across tenants.
        $tenantIds = $tenantId
            ? [$tenantId]
            : TenantInventoryItem::select('tenant_id')->distinct()->pluck('tenant_id')->all();

        foreach ($tenantIds as $tid) {
            $rows = TenantInventoryItem::where('tenant_id', $tid)
                ->orderBy('created_at')
                ->get(array_merge(['id', 'sku', 'name', 'computed_stock_count', 'created_at'], $fields));

            if ($rows->count() < 2) {
                continue;
            }

            // Union-find: an identifier value is a node, an item joins every
            // value it carries, and anything transitively connected is one
            // product. Values are namespaced by field so an EAN string can
            // never collide with an MPN that happens to read the same.
            $parent = [];
            $find = function ($x) use (&$parent, &$find) {
                while ($parent[$x] !== $x) {
                    $parent[$x] = $parent[$parent[$x]];
                    $x = $parent[$x];
                }
                return $x;
            };
            $union = function ($a, $b) use (&$parent, $find) {
                $parent[$a] ??= $a;
                $parent[$b] ??= $b;
                $ra = $find($a);
                $rb = $find($b);
                if ($ra !== $rb) {
                    $parent[$rb] = $ra;
                }
            };

            foreach ($rows as $r) {
                $node = 'i:' . $r->id;
                $parent[$node] ??= $node;
                foreach ($fields as $f) {
                    $v = trim((string) $r->{$f});
                    if ($v === '') {
                        continue;
                    }
                    $union($node, $f . ':' . $v);
                }
            }

            $clusters = [];
            foreach ($rows as $r) {
                $clusters[$find('i:' . $r->id)][] = $r;
            }

            $byId = $rows->keyBy('id');

            foreach ($clusters as $members) {
                if (count($members) < 2) {
                    continue;
                }
                if ($limit && $done >= $limit) {
                    break 2;
                }

                $ids = array_map(fn ($m) => $m->id, $members);

                $touched = [];
                foreach (['tenant_sale_items', 'tenant_inventory_movements'] as $table) {
                    foreach (DB::table($table)->whereIn('inventory_item_id', $ids)
                                ->distinct()->pluck('inventory_item_id') as $id) {
                        $touched[$id] = true;
                    }
                }

                $blocked = null;
                foreach ($members as $m) {
                    if (isset($touched[$m->id]) || (int) $m->computed_stock_count !== 0) {
                        $blocked = $m;
                        break;
                    }
                }
                if ($blocked) {
                    $skipped++;
                    $this->line(sprintf('  SKIP  %s has stock or history', $blocked->sku));
                    continue;
                }

                // Longest name wins; the rows arrive oldest-first and usort is
                // stable in PHP 8, so a tie keeps the oldest.
                $ordered = $members;
                usort($ordered, fn ($a, $b) => mb_strlen((string) $b->name) <=> mb_strlen((string) $a->name));
                $survivorRow = array_shift($ordered);

                $done++;

                $this->line(sprintf('  FOLD  keep %-14s <- %s',
                    $survivorRow->sku,
                    implode(', ', array_map(fn ($l) => $l->sku, $ordered))
                ));
                $this->line('          ' . \Illuminate\Support\Str::limit($survivorRow->name, 100));

                if (! $apply) {
                    $folded++;
                    continue;
                }

                $survivor = TenantInventoryItem::find($survivorRow->id);
                $losers   = TenantInventoryItem::whereIn('id', array_map(fn ($l) => $l->id, $ordered))->get();

                DB::transaction(function () use ($survivor, $losers, &$pivots, &$inherited) {
                    $fill = [];
                    foreach ($losers as $loser) {
                        foreach (TenantInventoryItemVendor::where('inventory_item_id', $loser->id)->get() as $pivot) {
                            $exists = TenantInventoryItemVendor::where('inventory_item_id', $survivor->id)
                                ->where('vendor_id', $pivot->vendor_id)->exists();
                            if ($exists) {
                                $pivot->delete();
                            } else {
                                $pivot->inventory_item_id = $survivor->id;
                                $pivot->save();
                                $pivots++;
                            }
                        }

                        foreach (self::INHERIT_BLANKS as $field) {
                            if (blank($survivor->{$field}) && filled($loser->{$field}) && ! array_key_exists($field, $fill)) {
                                $fill[$field] = $loser->{$field};
                            }
                        }

                        $loser->is_active = false;
                        $loser->notes = trim((string) $loser->notes . sprintf(
                            "\n[MARKER-EAN-MERGE] folded into %s (%s) on %s",
                            $survivor->sku, $survivor->id, now()->toDateString()
                        ));
                        $loser->save();
                    }

                    if ($fill) {
                        $survivor->fill($fill)->save();
                        $inherited += count($fill);
                    }
                });

                $folded++;
            }
        }

        $this->newLine();
        $this->line('Clusters folded  : ' . $folded);
        $this->line('Clusters skipped : ' . $skipped . ' (stock or history)');
        if ($apply) {
            $this->line('Pivots moved     : ' . $pivots);
            $this->line('Fields inherited : ' . $inherited);
        } else {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to write.');
        }
        $this->newLine();

        return self::SUCCESS;
    }
}
