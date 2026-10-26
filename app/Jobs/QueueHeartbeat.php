<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-QUEUE-WATCHDOG: the queue watchdog's test job. A worker running it
 * is the proof that workers are running. It only stamps the time.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        DB::table('platform_settings')->where('id', 1)->update(['queue_heartbeat_at' => now()]);
    }
}
