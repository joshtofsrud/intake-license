<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tenant\InventoryController;
use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Console\Command;

/**
 * MARKER-AUTOLINK — link already-created items to the catalog by identifier.
 *
 * Calls the same method the save path uses, so the rules cannot drift: never
 * overwrites an existing link, requires exactly one match, active
 * subscriptions only.
 */
class InventoryAutolinkCatalogCommand extends Command
{
    protected $signature = 'inventory:autolink-catalog
        {--tenant= : Subdomain of one tenant, or omit for all}
        {--apply : Write the links. Without this, only lists candidates}';

    protected $description = 'Link unlinked inventory items to distributor catalog rows by UPC, EAN or MPN.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('subdomain', $this->option('tenant')))
            ->get(['id', 'subdomain']);

        $total = 0;

        foreach ($tenants as $tenant) {
            $items = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->whereNull('distributor_catalog_id')
                ->where(fn ($w) => $w
                    ->whereNotNull('catalog_upc')
                    ->orWhereNotNull('catalog_ean')
                    ->orWhereNotNull('catalog_mpn'))
                ->get();

            $linked = 0;

            foreach ($items as $item) {
                if (! $apply) {
                    $this->line(sprintf('    %-18s upc %-15s ean %-15s mpn %s',
                        $item->sku,
                        $item->catalog_upc ?: '—',
                        $item->catalog_ean ?: '—',
                        $item->catalog_mpn ?: '—'));

                    continue;
                }

                if (InventoryController::autoLinkByIdentifiers($item)) {
                    $linked++;
                    $this->line('    linked ' . $item->sku);
                }
            }

            $this->line(sprintf('  %-12s %d candidate%s%s',
                $tenant->subdomain,
                $items->count(),
                $items->count() === 1 ? '' : 's',
                $apply ? ", {$linked} linked" : ''));

            $total += $apply ? $linked : $items->count();
        }

        $this->newLine();

        if (! $apply) {
            $this->warn("{$total} item(s) carry an identifier and no link. Nothing written — re-run with --apply.");
        } else {
            $this->info("{$total} item(s) linked.");
        }

        return self::SUCCESS;
    }
}
