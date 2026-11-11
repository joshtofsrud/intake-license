<?php

namespace App\Console\Commands;

use App\Services\Distributors\WarehouseRegistry;
use Illuminate\Console\Command;

/** MARKER-DISTRIBUTION-WAREHOUSES: register every warehouse found in the stored supplier snapshots. */
class DistributionScanWarehouses extends Command
{
    protected $signature = 'distribution:scan-warehouses';
    protected $description = 'Find supplier warehouses in stored feed data and list them in master admin (new ones start hidden and off)';

    public function handle(): int
    {
        $n = WarehouseRegistry::scan();
        $this->info($n . ' new warehouse' . ($n === 1 ? '' : 's') . ' found.');
        return self::SUCCESS;
    }
}
