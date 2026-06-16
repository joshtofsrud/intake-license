<?php
// MARKER-PATCH-HLCC

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Console\Command;

/**
 * Push recomposed catalog title + expanded subtitle onto EXISTING
 * distributor-linked tenant items. No re-import; reads the catalog row each
 * item already links to.
 *
 *   php artisan inventory:sync-titles grndctrl
 *   php artisan inventory:sync-titles --dry-run
 *   php artisan inventory:sync-titles            # all tenants
 */
class SyncTenantItemTitles extends Command
{
    protected $signature = 'inventory:sync-titles {tenant? : tenant uuid or subdomain} {--dry-run}';
    protected $description = 'Push catalog display_name + display_subtitle onto linked tenant items';

    public function handle(): int
    {
        $arg = $this->argument('tenant');
        $dry = (bool) $this->option('dry-run');

        $tenantIds = null;
        if ($arg) {
            $t = Tenant::where('id', $arg)->orWhere('subdomain', $arg)->first();
            if (! $t) { $this->error("Tenant not found: {$arg}"); return self::FAILURE; }
            $tenantIds = [$t->id];
        }

        $q = TenantInventoryItem::query()
            ->whereNotNull('distributor_catalog_id')
            ->with('distributorCatalog');
        if ($tenantIds) { $q->whereIn('tenant_id', $tenantIds); }

        $total = (clone $q)->count();
        $this->info(($dry ? '[dry-run] ' : '') . "Syncing titles on {$total} linked item(s)…");

        $n = 0; $sample = [];
        $q->chunkById(500, function ($items) use (&$n, &$sample, $dry) {
            foreach ($items as $item) {
                $cat = $item->distributorCatalog;
                if (! $cat || blank($cat->display_name)) { continue; }
                if (count($sample) < 3) {
                    $sample[] = "{$cat->display_name}  /  " . ($cat->display_subtitle ?? '');
                }
                if (! $dry) {
                    $item->forceFill([
                        'name'              => $cat->display_name,
                        'display_subtitle'  => $cat->display_subtitle,
                        'catalog_title_seen'=> $cat->display_name,
                    ])->saveQuietly();
                }
                $n++;
            }
        });

        foreach ($sample as $s) { $this->line("  e.g. {$s}"); }
        $this->info(($dry ? '[dry-run] would update ' : 'Updated ') . "{$n} item(s).");
        return self::SUCCESS;
    }
}
