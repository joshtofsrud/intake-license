<?php
// MARKER-EAN-DUPES

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report inventory items that share an EAN — the duplicates the catalog
 * importer created before MARKER-IMPORT-EAN-MERGE taught it to match on one.
 *
 * READ ONLY. Nothing here writes, so it is safe during business hours.
 *
 * Groups are sorted into three buckets, because they need three different
 * answers:
 *
 *   safe      Every member is catalog-only — no stock, no movements, no sale
 *             lines — and they agree on name and size. Pure import artifacts.
 *             These are the ones a bulk fold can take without a human.
 *
 *   history   Somebody has stocked, counted or sold one of them. Folding these
 *             moves rows in tenant_sale_items / tenant_inventory_movements and
 *             changes what a past sale points at. Needs a person.
 *
 *   differs   Members disagree on name or size. This is the variant-parent
 *             case: some feeds put ONE EAN across a whole size range, so
 *             "same EAN" is not "same product". Folding these would destroy
 *             a shop's ability to stock 27.5x2.5 apart from 29x2.4. Never
 *             auto-merge these — it is the failure mode that matters.
 *
 * The `differs` count is the number that decides whether a bulk repair is
 * viable at all. If it is large, EAN is not an identity in these feeds.
 */
class InventoryEanDuplicates extends Command
{
    protected $signature = 'inventory:ean-duplicates
                            {--tenant= : Limit to one tenant subdomain (e.g. wmm)}
                            {--list=0  : Also print this many example groups per bucket}';

    protected $description = 'Report inventory items sharing an EAN, bucketed by whether they are safe to fold.';

    public function handle(): int
    {
        $subdomain = (string) $this->option('tenant');
        $listN     = max(0, (int) $this->option('list'));

        $tenantId = null;
        if ($subdomain !== '') {
            $tenantId = Tenant::where('subdomain', $subdomain)->value('id');
            if (! $tenantId) {
                $this->error("No tenant with subdomain '{$subdomain}'.");
                return self::FAILURE;
            }
        }

        // UUID lists get long; the 1024-byte default would silently truncate
        // a group and make it look smaller than it is.
        DB::statement('SET SESSION group_concat_max_len = 1000000');

        $sql = "SELECT tenant_id, catalog_ean,
                       COUNT(*) AS items,
                       COUNT(DISTINCT name) AS names,
                       COUNT(DISTINCT COALESCE(size, '')) AS sizes,
                       COALESCE(SUM(computed_stock_count), 0) AS stock,
                       GROUP_CONCAT(id) AS ids,
                       GROUP_CONCAT(DISTINCT sku) AS skus,
                       MIN(name) AS example_name
                FROM tenant_inventory_items
                WHERE catalog_ean IS NOT NULL AND TRIM(catalog_ean) <> ''";

        $bind = [];
        if ($tenantId) {
            $sql .= " AND tenant_id = ?";
            $bind[] = $tenantId;
        }
        $sql .= " GROUP BY tenant_id, catalog_ean HAVING items > 1";

        $groups = DB::select($sql, $bind);

        if (! $groups) {
            $this->info('No items share an EAN.' . ($tenantId ? " (tenant {$subdomain})" : ''));
            return self::SUCCESS;
        }

        // Which of these items have been stocked, counted or sold?
        $ids = [];
        foreach ($groups as $g) {
            foreach (explode(',', $g->ids) as $id) {
                $ids[] = $id;
            }
        }

        $touched = [];
        foreach (['tenant_sale_items', 'tenant_inventory_movements'] as $table) {
            foreach (array_chunk($ids, 5000) as $chunk) {
                foreach (DB::table($table)->whereIn('inventory_item_id', $chunk)
                            ->distinct()->pluck('inventory_item_id') as $id) {
                    $touched[$id] = true;
                }
            }
        }

        $buckets   = ['safe' => [], 'history' => [], 'differs' => []];
        $redundant = 0;

        foreach ($groups as $g) {
            $redundant += ((int) $g->items) - 1;

            $hasHistory = false;
            foreach (explode(',', $g->ids) as $id) {
                if (isset($touched[$id])) {
                    $hasHistory = true;
                    break;
                }
            }

            if ($g->names > 1 || $g->sizes > 1) {
                $buckets['differs'][] = $g;
            } elseif ($hasHistory || $g->stock > 0) {
                $buckets['history'][] = $g;
            } else {
                $buckets['safe'][] = $g;
            }
        }

        $subs = Tenant::whereIn('id', array_unique(array_column($groups, 'tenant_id')))
            ->pluck('subdomain', 'id');

        $this->newLine();
        $this->line('EAN duplicate groups     : ' . count($groups));
        $this->line('Redundant items          : ' . $redundant);
        $this->line('  safe to fold           : ' . count($buckets['safe']));
        $this->line('  have stock or history  : ' . count($buckets['history']));
        $this->line('  differ by name or size : ' . count($buckets['differs']) . '   <- never auto-merge');
        $this->newLine();

        // Per tenant, so a number that is really one shop's problem reads
        // as one shop's problem.
        $perTenant = [];
        foreach ($groups as $g) {
            $key = $subs[$g->tenant_id] ?? $g->tenant_id;
            $perTenant[$key] = ($perTenant[$key] ?? 0) + 1;
        }
        arsort($perTenant);
        $this->line('By tenant:');
        foreach ($perTenant as $name => $n) {
            $this->line(sprintf('  %-24s %d', $name, $n));
        }

        if ($listN > 0) {
            foreach ($buckets as $label => $rows) {
                if (! $rows) {
                    continue;
                }
                $this->newLine();
                $this->line(strtoupper($label) . ' — first ' . min($listN, count($rows)) . ' of ' . count($rows) . ':');

                // Print every member in full. A truncated name cannot tell you
                // whether two rows are the same product written twice or two
                // genuinely different products — which is the only question
                // that matters here, and it is a judgement call, not a metric.
                foreach (array_slice($rows, 0, $listN) as $g) {
                    $this->newLine();
                    $this->line(sprintf(
                        '  %s  EAN %s  (%d items, stock %d)',
                        $subs[$g->tenant_id] ?? '?',
                        $g->catalog_ean,
                        $g->items,
                        $g->stock
                    ));
                    $members = DB::table('tenant_inventory_items')
                        ->whereIn('id', explode(',', $g->ids))
                        ->orderBy('sku')
                        ->get(['sku', 'size', 'color', 'name', 'distributor_catalog_id']);
                    foreach ($members as $m) {
                        $this->line(sprintf(
                            '      %-14s size=%-10s color=%-10s %s',
                            $m->sku,
                            $m->size ?: '-',
                            $m->color ?: '-',
                            $m->name
                        ));
                    }
                }
            }
        }

        $this->newLine();
        return self::SUCCESS;
    }
}
