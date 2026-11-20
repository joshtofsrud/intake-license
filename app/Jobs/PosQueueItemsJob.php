<?php

namespace App\Jobs;

use App\Services\Pos\PosPushService;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * MARKER-POS-SEND-ITEMS-BG: queues "create in the Connected POS" for every item the
 * shop sells online that isn't there yet, in the background (doing it inside the
 * click timed out). Each item is a row; the shop's one PosDrainJob sends them.
 */
class PosQueueItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 1200;

    public function __construct(public string $tenantId) {}

    public function handle(PosPushService $push): void
    {
        $conn = \App\Models\Tenant\TenantPosConnection::where('tenant_id', $this->tenantId)->first();
        $done = 0;
        try {
            $push->unsentOnlineQuery($this->tenantId)  // MARKER-POS-TAB-V2: items sold online only
                ->select(['id', 'name'])->orderBy('id')
                ->chunkById(200, function ($items) use ($push, $conn, &$done) {
                    $conn?->refresh();
                    if ($conn && ! ((($conn->sync_state ?? [])['send_items']['running'] ?? false))) {
                        return false; // stopped by the shop (MARKER-POS-STOP-SENDS)
                    }
                    foreach ($items as $i) {
                        $push->queue($this->tenantId, 'item_create', (string) $i->id, 'New item ' . $i->name, false);
                        $done++;
                    }
                    PosPushService::kick($this->tenantId); // MARKER-POS-DRAIN: one worker, not one job per item
                    $this->progress($conn, ['queued' => $done]);
                });
            $conn?->refresh();
            if ($conn && ((($conn->sync_state ?? [])['send_items']['running'] ?? false))) {
                $this->progress($conn, ['queued' => $done, 'running' => false, 'finished_at' => now()->toIso8601String()]);
            }
        } catch (Throwable $e) {
            $this->progress($conn, ['queued' => $done, 'running' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()->toIso8601String()]);
            JobFailureReporter::report(self::class, 'Queuing items for the Connected POS failed', $e, [], $this->tenantId);
            throw $e;
        }
    }

    /** Live state the Connected POS tab shows while items are being prepared. */
    private function progress(?\App\Models\Tenant\TenantPosConnection $conn, array $patch): void
    {
        if (! $conn) {
            return;
        }
        $conn->refresh();
        $st = (array) ($conn->sync_state ?? []);
        $st['send_items'] = array_merge((array) ($st['send_items'] ?? []), $patch);
        $conn->forceFill(['sync_state' => $st])->save();
    }
}
