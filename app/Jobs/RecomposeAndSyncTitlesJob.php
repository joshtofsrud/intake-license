<?php
// MARKER-PATCH-HLCE

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Rebuild catalog titles from the saved templates, then push them onto tenant
 * items — in order, in one background job.
 */
class RecomposeAndSyncTitlesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min — covers a full ~14k recompose + item sync

    public function __construct(public ?string $code = null) {}

    public function handle(): void
    {
        Artisan::call('distributor:recompose', $this->code ? ['code' => $this->code] : []);
        Artisan::call('inventory:sync-titles');
    }
}
