<?php

namespace App\Jobs;

use App\Services\Pos\PosPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * MARKER-POS-DRAIN: the one worker that sends a shop's waiting rows to its
 * Connected POS, ~90 seconds at a time, then queues itself again while anything
 * is still waiting. One per shop, so a big send never floods the shared queue.
 */
class PosDrainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public string $tenantId) {}

    public function handle(PosPushService $svc): void
    {
        Cache::forget('pos_drain_queued:' . $this->tenantId);
        $lock = Cache::lock('pos_drain:' . $this->tenantId, 140);
        if (! $lock->get()) {
            return; // another run for this shop is working
        }
        try {
            $more = $svc->drain($this->tenantId);
        } finally {
            $lock->release();
        }
        if ($more !== null && config('queue.default') !== 'sync') {
            PosPushService::kick($this->tenantId, max(5, min(300, $more + 1)));
        }
    }
}
