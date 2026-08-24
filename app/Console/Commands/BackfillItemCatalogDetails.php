<?php
// MARKER-DETAILS-WATCH — one-time backfill: copy catalog color/size/description
// into linked items where the item's field is blank, and seed the
// catalog_details_seen baseline so the details watch flags changes, not backlog.

namespace App\Console\Commands;

use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Console\Command;

class BackfillItemCatalogDetails extends Command
{
    protected $signature = 'catalog:backfill-item-details
        {--tenant= : Limit to one tenant id}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Fill blank item color/size/description from the linked catalog row and seed the details baseline';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $q = TenantInventoryItem::query()
            ->whereNotNull('distributor_catalog_id')
            ->with('distributorCatalog');
        if ($this->option('tenant')) {
            $q->where('tenant_id', $this->option('tenant'));
        }

        $scanned = 0;
        $filled = 0;
        $seeded = 0;

        $q->chunkById(500, function ($items) use (&$scanned, &$filled, &$seeded, $dry) {
            foreach ($items as $item) {
                $scanned++;
                $cat = $item->distributorCatalog;
                if (! $cat) {
                    continue;
                }
                $dirty = false;
                foreach (['color', 'size', 'description'] as $fld) {
                    if (blank($item->{$fld}) && filled($cat->{$fld})) {
                        $item->{$fld} = $cat->{$fld};
                        $dirty = true;
                        $filled++;
                    }
                }
                if ($item->catalog_details_seen === null) {
                    $item->catalog_details_seen = [
                        'color'       => $cat->color,
                        'size'        => $cat->size,
                        'description' => $cat->description,
                    ];
                    $dirty = true;
                    $seeded++;
                }
                if ($dirty && ! $dry) {
                    $item->save();
                }
            }
        });

        $this->info(($dry ? '[dry-run] ' : '') . "{$scanned} linked items scanned, {$filled} blank fields filled, {$seeded} baselines seeded.");

        return self::SUCCESS;
    }
}
