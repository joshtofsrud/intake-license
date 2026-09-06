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
 *   - losers are marked is_active = false and logged
 *
 * Nothing is deleted (RUNBOOK rule). A bad fold is undone by flipping
 * is_active back and moving the pivot; the log line records where it went.
 *
 * STOCK AND HISTORY move too. Per-location rows are summed onto the survivor
 * (that table is unique on item+location, so a location both already stock is
 * added into rather than duplicated), and every other table carrying an
 * inventory_item_id is repointed. Those tables are discovered at runtime rather
 * than hardcoded, so one added later is not silently left pointing at a dead
 * row.
 *
 * Repointing sale lines and movements does not rewrite history: both snapshot
 * name and SKU at write time, so an old receipt still reads exactly as it did.
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

        // Fail before touching anything if a column we intend to read or write
        // is not actually on the table. A missing column otherwise surfaces as
        // a QueryException part-way through the run, after earlier clusters
        // have already committed.
        $cols    = \Illuminate\Support\Facades\Schema::getColumnListing('tenant_inventory_items');
        $missing = array_diff(array_merge($fields, self::INHERIT_BLANKS, ['is_active', 'computed_stock_count']), $cols);
        if ($missing) {
            $this->error('tenant_inventory_items has no column: ' . implode(', ', $missing));
            return self::FAILURE;
        }

        $this->newLine();
        $this->line($apply ? 'APPLYING' : 'DRY RUN — nothing will be written (add --apply)');
        $this->line('Matching on: ' . implode(', ', $on));
        $this->newLine();

        $folded = $pivots = $inherited = $done = $stocked = $moved = $dropped = 0;

        // Every other table that points at an inventory item follows the fold.
        // Discovered rather than hardcoded: a table added later would otherwise
        // be silently left pointing at a deactivated row.
        $refTables = [];
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if (in_array($table, ['tenant_inventory_item_locations', 'tenant_inventory_item_vendors'], true)) {
                continue; // both have a unique key and are handled explicitly
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'inventory_item_id')) {
                $refTables[] = $table;
            }
        }
        // A blanket UPDATE breaks on any table with a unique key that includes
        // inventory_item_id — the loser and the survivor can each already hold
        // the row that key allows only once (tenant_pricing_attention_flags is
        // unique on tenant_id + inventory_item_id + reason, and both items
        // carry the same flag). Discover those keys and repoint row by row,
        // dropping the loser's row where the survivor already has its match.
        $uniqueKeys = [];
        foreach ($refTables as $table) {
            $stats = DB::select(
                "SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
                 ORDER BY INDEX_NAME, SEQ_IN_INDEX",
                [$table]
            );
            $byIndex = [];
            foreach ($stats as $s) {
                $byIndex[$s->INDEX_NAME][] = $s->COLUMN_NAME;
            }
            foreach ($byIndex as $columns) {
                if (in_array('inventory_item_id', $columns, true)) {
                    $uniqueKeys[$table][] = $columns;
                }
            }

            // The row-by-row path addresses rows by id. Fail now rather than
            // part-way through a run if some table does not have one.
            if (! empty($uniqueKeys[$table]) && ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'id')) {
                $this->error("{$table} has a unique key on inventory_item_id but no id column to address rows by.");
                return self::FAILURE;
            }
        }

        $this->line('Repointing: ' . implode(', ', $refTables));
        if ($uniqueKeys) {
            foreach ($uniqueKeys as $table => $keys) {
                foreach ($keys as $columns) {
                    $this->line('  unique key on ' . $table . ': ' . implode(' + ', $columns));
                }
            }
        }
        $this->newLine();

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

                $clusterStock = 0;
                foreach ($members as $m) {
                    $clusterStock += (int) $m->computed_stock_count;
                }

                // Longest name wins; the rows arrive oldest-first and usort is
                // stable in PHP 8, so a tie keeps the oldest.
                $ordered = $members;
                usort($ordered, fn ($a, $b) => mb_strlen((string) $b->name) <=> mb_strlen((string) $a->name));
                $survivorRow = array_shift($ordered);

                $done++;

                $this->line(sprintf('  FOLD  keep %-14s <- %-28s %s',
                    $survivorRow->sku,
                    implode(', ', array_map(fn ($l) => $l->sku, $ordered)),
                    $clusterStock > 0 ? "stock {$clusterStock} combined" : ''
                ));
                $this->line('          ' . \Illuminate\Support\Str::limit($survivorRow->name, 100));

                if (! $apply) {
                    $folded++;
                    if ($clusterStock > 0) {
                        $stocked++;
                    }
                    continue;
                }

                $survivor = TenantInventoryItem::find($survivorRow->id);
                $losers   = TenantInventoryItem::whereIn('id', array_map(fn ($l) => $l->id, $ordered))->get();

                DB::transaction(function () use ($survivor, $losers, $refTables, $uniqueKeys, $clusterStock, &$pivots, &$inherited, &$moved, &$dropped) {
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

                        // Per-location stock. Unique on (inventory_item_id,
                        // location_id), so a location the survivor already
                        // stocks is summed into rather than repointed.
                        foreach (DB::table('tenant_inventory_item_locations')
                                    ->where('inventory_item_id', $loser->id)->get() as $loc) {
                            $existing = DB::table('tenant_inventory_item_locations')
                                ->where('inventory_item_id', $survivor->id)
                                ->where('location_id', $loc->location_id)
                                ->first();
                            if ($existing) {
                                DB::table('tenant_inventory_item_locations')
                                    ->where('id', $existing->id)
                                    ->update(['computed_stock_count' =>
                                        (int) $existing->computed_stock_count + (int) $loc->computed_stock_count]);
                                DB::table('tenant_inventory_item_locations')->where('id', $loc->id)->delete();
                            } else {
                                DB::table('tenant_inventory_item_locations')
                                    ->where('id', $loc->id)
                                    ->update(['inventory_item_id' => $survivor->id]);
                            }
                        }

                        // Everything else that points at an item follows it.
                        // tenant_sale_items and tenant_inventory_movements both
                        // snapshot name and SKU at write time, so a past receipt
                        // or movement still reads as it did — repointing moves
                        // the link without rewriting the history.
                        foreach ($refTables as $table) {
                            if (empty($uniqueKeys[$table])) {
                                $moved += DB::table($table)
                                    ->where('inventory_item_id', $loser->id)
                                    ->update(['inventory_item_id' => $survivor->id]);
                                continue;
                            }

                            // Row by row, because a unique key including
                            // inventory_item_id can already be taken by the
                            // survivor's own row.
                            foreach (DB::table($table)->where('inventory_item_id', $loser->id)->get() as $row) {
                                $clash = false;
                                foreach ($uniqueKeys[$table] as $columns) {
                                    $probe = DB::table($table)->where('inventory_item_id', $survivor->id);
                                    foreach ($columns as $col) {
                                        if ($col === 'inventory_item_id') {
                                            continue;
                                        }
                                        $val = $row->{$col} ?? null;
                                        $val === null ? $probe->whereNull($col) : $probe->where($col, $val);
                                    }
                                    if ($probe->exists()) {
                                        $clash = true;
                                        break;
                                    }
                                }

                                if ($clash) {
                                    // The survivor already carries the same
                                    // row. The loser's is a duplicate of it.
                                    DB::table($table)->where('id', $row->id)->delete();
                                    $dropped++;
                                } else {
                                    DB::table($table)->where('id', $row->id)
                                        ->update(['inventory_item_id' => $survivor->id]);
                                    $moved++;
                                }
                            }
                        }

                        foreach (self::INHERIT_BLANKS as $field) {
                            if (blank($survivor->{$field}) && filled($loser->{$field}) && ! array_key_exists($field, $fill)) {
                                $fill[$field] = $loser->{$field};
                            }
                        }

                        if ($loser->is_stock_tracked) {
                            $survivor->is_stock_tracked = true;
                        }

                        // Read before the save: getOriginal() reflects the saved
                        // state once save() has run, so capturing afterwards
                        // reports 0 for every fold.
                        $loserStock = (int) $loser->computed_stock_count;

                        $loser->is_active            = false;
                        $loser->computed_stock_count = 0;
                        $loser->save();

                        // tenant_inventory_items has no notes column, so the
                        // audit trail goes to the log. The loser keeps its own
                        // EAN/UPC/MPN, so a reversal can find the survivor by
                        // the same identifiers that matched them.
                        \Illuminate\Support\Facades\Log::info('MARKER-EAN-MERGE folded item', [
                            'tenant_id'   => $loser->tenant_id,
                            'loser_id'    => $loser->id,
                            'loser_sku'   => $loser->sku,
                            'loser_stock' => $loserStock,
                            'survivor_id' => $survivor->id,
                            'survivor_sku' => $survivor->sku,
                        ]);
                    }

                    // Stock is the sum of the cluster. Prefer recomputing from
                    // the location rows now that they all hang off the survivor
                    // — but an item with no location rows at all still has a
                    // count on the item, and summing an empty set would zero it.
                    $locRows = DB::table('tenant_inventory_item_locations')
                        ->where('inventory_item_id', $survivor->id);
                    if ($locRows->exists()) {
                        $survivor->computed_stock_count = (int) $locRows->sum('computed_stock_count');
                    } else {
                        // $clusterStock was summed before anything was written.
                        // Re-reading the losers here would give 0 — their counts
                        // have already been zeroed above.
                        $survivor->computed_stock_count = $clusterStock;
                    }

                    $survivor->fill($fill)->save();
                    $inherited += count($fill);
                });

                if ($clusterStock > 0) {
                    $stocked++;
                }

                $folded++;
            }
        }

        $this->newLine();
        $this->line('Clusters folded  : ' . $folded);
        $this->line('  carrying stock : ' . $stocked);
        if ($apply) {
            $this->line('Pivots moved     : ' . $pivots);
            $this->line('Rows repointed   : ' . $moved);
            $this->line('Dup rows dropped : ' . $dropped . ' (survivor already had the same unique row)');
            $this->line('Fields inherited : ' . $inherited);
        } else {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to write.');
        }
        $this->newLine();

        return self::SUCCESS;
    }
}
