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
