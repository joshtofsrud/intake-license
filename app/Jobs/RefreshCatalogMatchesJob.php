<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Rebuild one distributor's identifier index, then re-run cross-distributor
 * matching, straight after a catalog sync started by hand.
 *
 * The nightly chain (sync 04:00-04:45, index 05:30, match 06:00) does this for
 * scheduled syncs. A sync run from master admin during the day used to leave
 * its rows unindexed until the next morning, so an import that day could not
 * see that a row was the same part another distributor already supplies, and
 * created a second item for it. Shimano's first sync was exactly that case.
 */
class RefreshCatalogMatchesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;
    public int $uniqueFor = 1800;

    public function __construct(public string $distributorCode) {}

    public function uniqueId(): string
    {
        return 'catalog-matches-refresh-' . strtoupper($this->distributorCode);
    }

    public function handle(): void
    {
        try {
            Artisan::call('catalog:index-identifiers', ['code' => strtoupper($this->distributorCode)]);
            Artisan::call('catalog:match');
        } catch (\Throwable $e) {
            \App\Support\JobFailureReporter::report(
                self::class, strtoupper($this->distributorCode) . ' catalog index refresh failed after a sync', $e,
                ['code' => strtoupper($this->distributorCode)]
            );
            throw $e;
        }
    }
}
