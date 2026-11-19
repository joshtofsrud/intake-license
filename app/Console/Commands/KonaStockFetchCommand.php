<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\BrandStockStore;
use App\Services\Distributors\KonaStockLoader;
use Illuminate\Console\Command;

/** MARKER-BRAND-STOCK: each switched-on shop's newest Kona stock file from its FTP folder, before the nightly per-shop sync. */
class KonaStockFetchCommand extends Command
{
    protected $signature = 'distributors:kona-stock-fetch {--tenant= : one shop only}';
    protected $description = 'Fetch the newest Kona stock file from each shop\'s FTP folder';

    public function handle(): int
    {
        $subs = TenantDistributorCatalogSubscription::query()->where('distributor_code', KonaStockLoader::CODE)->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))->get();
        foreach ($subs as $sub) {
            if (! KonaStockLoader::ftp($sub)) {
                continue;
            }
            try {
                $r = KonaStockLoader::fetch($sub);
                $this->line($sub->tenant_id . ': ' . ($r ? 'loaded ' . $r['file'] . ' (' . $r['rows'] . ' rows)' : 'nothing new'));
            } catch (\Throwable $e) {
                BrandStockStore::failed((string) $sub->tenant_id, KonaStockLoader::CODE, $e->getMessage());
                $this->warn($sub->tenant_id . ': ' . $e->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
