<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Console\Command;

/**
 * MARKER-IDENT-IN-SKU — copy barcode-shaped SKUs into catalog_upc.
 *
 * The add-item form has a SKU field and no UPC field, so a shop entering stock
 * by hand puts the barcode in the SKU box. Those items then match nothing: no
 * catalog link, no cost updates, no vendor, and an import creates a duplicate
 * rather than adopting them.
 *
 * COPY, never move. The SKU is on the shelf labels by now, and a copy can be
 * undone by clearing one column where a move cannot be undone at all.
 */
class InventoryBarcodeSkuToUpcCommand extends Command
{
    protected $signature = 'inventory:barcode-sku-to-upc
        {--tenant= : Subdomain of one tenant, or omit for all}
        {--apply : Write the changes. Without this, only reports}';

    protected $description = 'Copy barcode-shaped SKUs into catalog_upc so catalog imports adopt the items instead of duplicating them.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('subdomain', $this->option('tenant')))
            ->get(['id', 'subdomain', 'name']);

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched.');

            return self::FAILURE;
        }

        $grand = 0;

        foreach ($tenants as $tenant) {
            // 12 to 14 digits covers UPC-A (12), EAN-13 and GTIN-14. Shorter
            // strings are far more likely to be a real shop SKU that happens
            // to be numeric, and are deliberately left alone.
            $items = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->whereRaw('sku REGEXP "^[0-9]{12,14}$"')
                ->where(fn ($w) => $w->whereNull('catalog_upc')->orWhere('catalog_upc', ''))
                ->get(['id', 'name', 'sku']);

            if ($items->isEmpty()) {
                $this->line(sprintf('  %-12s nothing to do', $tenant->subdomain));

                continue;
            }

            $this->line(sprintf('  %-12s %d item%s',
                $tenant->subdomain, $items->count(), $items->count() === 1 ? '' : 's'));

            foreach ($items as $item) {
                $this->line(sprintf('      %-46s sku %s',
                    \Illuminate\Support\Str::limit((string) $item->name, 44), $item->sku));

                if ($apply) {
                    $item->forceFill(['catalog_upc' => $item->sku])->save();
                }
            }

            $grand += $items->count();
        }

        $this->newLine();

        if (! $apply) {
            $this->warn(sprintf('%d item%s would be updated. Nothing written — re-run with --apply.',
                $grand, $grand === 1 ? '' : 's'));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d item%s updated. SKUs were left unchanged.',
            $grand, $grand === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
