<?php

namespace App\Console\Commands;

use App\Jobs\DistributorSupplyRunJob;
use App\Services\Distributors\DistributorRegistry;
use App\Support\BrandDirect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SUPPLY-RUN: start each distributor's shared run: cost and stock for
 * every part number any shop carries, fetched once. Shops then copy from it.
 */
class DistributorsSupplyRunCommand extends Command
{
    protected $signature = 'distributors:supply-run {code? : one distributor only}';
    protected $description = 'Fetch each distributor\'s cost and stock once for every shop (the shared run)';

    public function handle(DistributorRegistry $registry): int
    {
        $codes = $this->argument('code') ? [strtoupper((string) $this->argument('code'))] : $registry->supported();
        foreach ($codes as $code) {
            if (BrandDirect::is($code)) {
                continue;
            }
            $active = DB::table('tenant_distributor_catalog_subscriptions')->where('distributor_code', $code)->where('is_active', true)->exists();
            if (! $active) {
                continue;
            }
            DistributorSupplyRunJob::dispatch($code);
            $this->line($code . ': shared run queued');
        }
        return self::SUCCESS;
    }
}
