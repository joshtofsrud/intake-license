<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\BrandStockStore;
use App\Services\Distributors\BrandStockLoader;
use Illuminate\Console\Command;

/** MARKER-BRAND-STOCK: each switched-on shop's newest stock file for a brand sold direct from its FTP folder, before the nightly per-shop sync. */
class BrandStockFetchCommand extends Command
{
    protected $signature = 'distributors:brand-stock-fetch {--tenant= : one shop only} {--code= : one brand only}';
    protected $description = 'Fetch the newest stock file from each shop\'s FTP folder, for every brand sold direct';

    public function handle(): int
    {
        $subs = TenantDistributorCatalogSubscription::query()->whereIn('distributor_code', $this->option('code') ? [strtoupper((string) $this->option('code'))] : \App\Support\BrandDirect::codes())->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))->get();
        foreach ($subs as $sub) {
            if (! BrandStockLoader::ftp($sub)) {
                continue;
            }
            try {
                $r = BrandStockLoader::fetch($sub);
                $this->line($sub->tenant_id . ' ' . $sub->distributor_code . ': ' . ($r ? 'loaded ' . $r['file'] . ' (' . $r['rows'] . ' rows)' : 'nothing new'));
            } catch (\Throwable $e) {
                BrandStockStore::failed((string) $sub->tenant_id, (string) $sub->distributor_code, $e->getMessage());
                $this->warn($sub->tenant_id . ' ' . $sub->distributor_code . ': ' . $e->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
