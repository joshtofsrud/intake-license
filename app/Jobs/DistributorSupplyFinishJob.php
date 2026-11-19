<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** MARKER-SUPPLY-RUN: closes a distributor's shared run once its last slice is in. */
class DistributorSupplyFinishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        DB::table('distributor_sync_runs')->where('id', $this->runId)->where('status', 'running')
            ->update(['status' => 'done', 'finished_at' => now(), 'updated_at' => now()]);
    }
}
