<?php

namespace App\Jobs;

use App\Models\Tenant\TenantInventoryItem;
use App\Services\Pos\PosPushService;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MARKER-POS-SEND-ITEMS-BG: queues "create in the Connected POS" for every active
 * item not there yet, in the background — doing it inside the click timed out
 * (504) on a large inventory. Each item becomes its own send under Recent sends.
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
            $linked = DB::table('tenant_pos_items')->where('tenant_id', $this->tenantId)->whereNotNull('inventory_item_id')->whereNull('removed_at')->select('inventory_item_id');
            $queued = DB::table('tenant_pos_pushes')->where('tenant_id', $this->tenantId)->where('kind', 'item_create')->whereIn('status', ['pending', 'done'])->select('ref_id');
            TenantInventoryItem::where('tenant_id', $this->tenantId)->where('is_active', true)
                ->whereNotIn('id', $linked)->whereNotIn('id', $queued)
                ->select(['id', 'name'])->orderBy('id')
                ->chunkById(200, function ($items) use ($push, $conn, &$done) {
                    foreach ($items as $i) {
                        $push->queue($this->tenantId, 'item_create', (string) $i->id, 'New item ' . $i->name);
                        $done++;
                    }
                    $this->progress($conn, ['queued' => $done]);
                });
            $this->progress($conn, ['queued' => $done, 'running' => false, 'finished_at' => now()->toIso8601String()]);
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
