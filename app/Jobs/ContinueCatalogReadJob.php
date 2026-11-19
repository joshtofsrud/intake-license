<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Hands a long catalog read (Kona's, one bike at a time) over to a fresh
 * SyncDistributorCatalogJob. It exists because that job is unique per
 * distributor: dispatching the next one from inside the running one is
 * dropped while the running one still holds the lock. This runs a few
 * seconds later, after the lock is gone. A Stop pressed in between wins.
 */
class ContinueCatalogReadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $distributorCode) {}

    public function handle(\App\Services\Distributors\DistributorRegistry $registry): void
    {
        $code = strtoupper($this->distributorCode);
        $class = $registry->adapterClass($code);
        if ($class && defined($class . '::STOP_KEY') && Cache::get($class::STOP_KEY)) {
            return;
        }
        Cache::put('brand_suppliers.queued.' . $code, now()->timestamp, now()->addHour());
        SyncDistributorCatalogJob::dispatch($code);
    }
}
