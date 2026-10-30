<?php

namespace App\Console\Commands;

use App\Services\Distributors\StockVelocityRecorder;
use Illuminate\Console\Command;

/** MARKER-VELOCITY: drop stock-change history older than 60 days. The per-item summary is kept. */
class DistributorsVelocityPrune extends Command
{
    protected $signature = 'distributors:velocity-prune';
    protected $description = 'Remove distributor stock-change history older than 60 days.';

    public function handle(StockVelocityRecorder $rec): int
    {
        $this->info($rec->prune() . ' old change rows removed.');
        return self::SUCCESS;
    }
}
