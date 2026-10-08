<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\ItemSearchText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild each item's search_text (name, subtitle, SKU, barcodes, MPN, brand,
 * catalog brand, colour, size, supplier part numbers, old merged codes) and
 * recent_sales (units sold at the register in the last 90 days), which staff
 * search uses to rank. Nightly, after the distributor syncs; also by hand.
 *
 *   php artisan inventory:search-text            # every shop
 *   php artisan inventory:search-text grndctrl
 */
class BuildItemSearchText extends Command
{
    protected $signature = 'inventory:search-text {tenant? : tenant uuid or subdomain}';
    protected $description = 'Rebuild inventory search text and recent sales used by staff search';

    public function handle(): int
    {
        $arg = $this->argument('tenant');
        $tenants = Tenant::query()
            ->when($arg, fn ($q) => $q->where('id', $arg)->orWhere('subdomain', $arg))
            ->get(['id', 'subdomain']);

        if ($arg && $tenants->isEmpty()) {
            $this->error("Tenant not found: {$arg}");
            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $started = microtime(true);

            $recent = DB::table('tenant_sale_items as si')
                ->join('tenant_sales as s', 's.id', '=', 'si.sale_id')
                ->where('si.tenant_id', $tenant->id)
                ->whereNotNull('si.inventory_item_id')
                ->whereNotNull('s.paid_at')
                ->where('s.paid_at', '>=', now()->subDays(90))
                ->groupBy('si.inventory_item_id')
                ->selectRaw('si.inventory_item_id as id, SUM(CASE WHEN si.quantity > 0 THEN si.quantity ELSE 0 END) as n')
                ->pluck('n', 'id')
                ->all();

            $n = 0;
            DB::table('tenant_inventory_items')
                ->where('tenant_id', $tenant->id)
                ->select(['id', 'name', 'display_subtitle', 'sku', 'catalog_upc', 'catalog_ean', 'catalog_mpn',
                          'shop_brand', 'color', 'size', 'distributor_catalog_id', 'search_text', 'recent_sales'])
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($recent, &$n) {
                    $ids = $rows->pluck('id')->all();

                    $vendorSkus = [];
                    DB::table('tenant_inventory_item_vendors')->whereIn('inventory_item_id', $ids)
                        ->whereNotNull('vendor_sku')->get(['inventory_item_id', 'vendor_sku'])
                        ->each(function ($r) use (&$vendorSkus) { $vendorSkus[$r->inventory_item_id][] = $r->vendor_sku; });

                    $aliases = [];
                    DB::table('tenant_inventory_item_aliases')->whereIn('inventory_item_id', $ids)
                        ->get(['inventory_item_id', 'code'])
                        ->each(function ($r) use (&$aliases) { $aliases[$r->inventory_item_id][] = $r->code; });

                    $catIds = $rows->pluck('distributor_catalog_id')->filter()->unique()->values()->all();
                    $makers = $catIds
                        ? DB::table('platform_distributor_catalogs')->whereIn('id', $catIds)->pluck('manufacturer', 'id')->all()
                        : [];

                    DB::transaction(function () use ($rows, $vendorSkus, $aliases, $makers, $recent, &$n) {
                        foreach ($rows as $r) {
                            $text = ItemSearchText::compose([
                                $r->name, $r->display_subtitle, $r->sku, $r->catalog_upc, $r->catalog_ean,
                                $r->catalog_mpn, $r->shop_brand, $makers[$r->distributor_catalog_id] ?? null,
                                $r->color, $r->size, $vendorSkus[$r->id] ?? [], $aliases[$r->id] ?? [],
                            ]);
                            $sold = (int) round((float) ($recent[$r->id] ?? 0));
                            if ($text !== $r->search_text || $sold !== (int) $r->recent_sales) {
                                DB::table('tenant_inventory_items')->where('id', $r->id)
                                    ->update(['search_text' => $text, 'recent_sales' => $sold]);
                                $n++;
                            }
                        }
                    });
                }, 'id');

            $this->info(($tenant->subdomain ?: $tenant->id) . ": {$n} updated in " . round(microtime(true) - $started, 1) . 's');
        }

        return self::SUCCESS;
    }
}
