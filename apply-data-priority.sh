#!/bin/bash
# apply-data-priority — the tenant's distributor order finally does something.
#
#   Until now data_priority was stored and read by nothing, so the arrows on
#   the connection page had no visible effect — which is most of why they
#   looked broken even once they worked.
#
#   An item that carries two distributor sources already has a PRIMARY
#   catalog row: tenant_inventory_items.distributor_catalog_id, set when the
#   item was created and never revisited. That's what
#   inventory:sync-titles reads, and what $item->distributorCatalog resolves
#   everywhere else. So priority doesn't need a new mechanism — it needs to
#   decide which source is primary.
#
#   Row-level rather than per-field, deliberately. Per-field ("take HLC's
#   attributes and BTI's description") produces an item whose data came from
#   two places, which nobody can predict by looking at the settings — the
#   same objection that ruled out richest-wins earlier. One distributor is
#   the source; the order says which.
#
#   Consequence worth knowing: BTI writes real marketing copy while HLC
#   writes a spec dump, so putting HLC first means losing that copy on the
#   4,440 shared products. That's a reason to place BTI first, not a reason
#   to merge fields.
#
#   Only auto and confirmed links are followed, matching the importer.
#   Changing the primary row does not move stock, cost or vendor records —
#   those live per source on tenant_item_vendors and are untouched.
# NO MIGRATION. After deploy:
#   php artisan catalog:apply-priority --dry-run
#   php artisan catalog:apply-priority && php artisan inventory:sync-titles
set -e
if [ -f app/Console/Commands/ApplyDataPriority.php ]; then
  echo "apply-data-priority already applied — aborting."; exit 1
fi

cat > 'app/Console/Commands/ApplyDataPriority.php' <<'ADP_0_EOF'
<?php

// MARKER-APPLY-PRIORITY

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use Illuminate\Console\Command;

/**
 * Points each item at the catalog row from the distributor the tenant placed
 * highest.
 *
 * An item with two sources already has a primary catalog row — it was just
 * set once at creation and never reconsidered. inventory:sync-titles and
 * every $item->distributorCatalog read follow it, so choosing the primary IS
 * applying the priority; no per-field merging is involved.
 */
class ApplyDataPriority extends Command
{
    protected $signature = 'catalog:apply-priority
        {tenant? : tenant uuid or subdomain, omit for all}
        {--dry-run}';

    protected $description = "Point items at the highest-priority distributor's catalog row";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $arg = $this->argument('tenant');

        $tenants = Tenant::query()
            ->when($arg, fn ($q) => $q->where('id', $arg)->orWhere('subdomain', $arg))
            ->get(['id', 'subdomain']);

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched.');
            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $priority = TenantDistributorCatalogSubscription::where('tenant_id', $tenant->id)
                ->pluck('data_priority', 'distributor_code')
                ->map(fn ($p) => (int) $p)
                ->all();

            if (count($priority) < 2) {
                $this->line("{$tenant->subdomain}: fewer than two distributors — nothing to decide");
                continue;
            }

            // Sources grouped per item. Only items with more than one have a
            // decision to make.
            $sources = TenantInventoryItemVendor::query()
                ->whereNotNull('distributor_catalog_id')
                ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenant->id))
                ->get(['inventory_item_id', 'distributor_code', 'distributor_catalog_id'])
                ->groupBy('inventory_item_id');

            $changed = 0;
            $already = 0;
            $noPriority = 0;

            foreach ($sources as $itemId => $rows) {
                if ($rows->count() < 2) {
                    continue;
                }

                // Lowest number wins. A source whose distributor the tenant
                // has no subscription for sorts last rather than crashing.
                $best = $rows->sortBy(fn ($r) => $priority[$r->distributor_code] ?? 999)->first();

                if (! isset($priority[$best->distributor_code])) {
                    $noPriority++;
                    continue;
                }

                $item = TenantInventoryItem::find($itemId);
                if (! $item) {
                    continue;
                }

                if ($item->distributor_catalog_id === $best->distributor_catalog_id) {
                    $already++;
                    continue;
                }

                if (! $dry) {
                    // saveQuietly: this is a data-source change, not an edit
                    // anyone made, and it shouldn't fire item observers.
                    $item->forceFill([
                        'distributor_catalog_id' => $best->distributor_catalog_id,
                    ])->saveQuietly();
                }
                $changed++;
            }

            $this->line(
                "{$tenant->subdomain}: {$changed} repointed · {$already} already correct"
                . ($noPriority ? " · {$noPriority} skipped (no subscription)" : '')
            );
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing written.');
        } else {
            $this->newLine();
            $this->info('Run inventory:sync-titles to push the new titles onto the items.');
        }

        return self::SUCCESS;
    }
}
ADP_0_EOF

php -l app/Console/Commands/ApplyDataPriority.php

echo
echo "apply-data-priority applied."
