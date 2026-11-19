<?php

namespace App\Console\Commands;

use App\Services\Distributors\BrandStockLoader;
use App\Services\Distributors\BrandStockStore;
use App\Support\BrandDirect;
use Illuminate\Console\Command;

/** MARKER-BRAND-STOCK: each brand sold direct, its newest stock file from its FTP folder, before the nightly per-shop sync. */
class BrandStockFetchCommand extends Command
{
    protected $signature = 'distributors:brand-stock-fetch {--code= : one brand only}';
    protected $description = 'Fetch the newest stock file from each brand\'s FTP folder';

    public function handle(): int
    {
        $codes = $this->option('code') ? [strtoupper((string) $this->option('code'))] : BrandDirect::codes();
        foreach ($codes as $code) {
            if (! BrandStockLoader::ftp($code)) {
                continue;
            }
            try {
                $r = BrandStockLoader::fetch($code);
                $this->line($code . ': ' . ($r ? 'loaded ' . $r['file'] . ' (' . $r['rows'] . ' rows, ' . $r['shops'] . ' shops)' : 'nothing new'));
            } catch (\Throwable $e) {
                BrandStockStore::failed(BrandStockStore::BRAND, $code, $e->getMessage());
                $this->warn($code . ': ' . $e->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
