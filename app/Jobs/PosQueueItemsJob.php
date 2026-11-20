<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * MARKER-POS-ITEM-FIELDS: retired — the send-everything-online action is gone (items are
 * sent from Inventory by choice, or when an order needs them). Kept only so a job
 * already waiting in the queue finishes quietly instead of failing on a missing class.
 */
class PosQueueItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $tenantId) {}

    public function handle(): void
    {
    }
}
