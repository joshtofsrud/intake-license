<?php

namespace App\Jobs;

use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosSalesSync;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** MARKER-POS-SALES-HISTORY: one step of bringing in Connected POS sales; queues the next until caught up. */
class PosSalesSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public string $connectionId) {}

    public function handle(PosSalesSync $sync): void
    {
        $lock = Cache::lock('pos_sales_state:' . $this->connectionId, 290);
        if (! $lock->get()) {
            return;
        }
        $conn = TenantPosConnection::find($this->connectionId);
        try {
            if (! $conn || $conn->status !== 'connected' || ! (($conn->sales_state ?? [])['running'] ?? false)) {
                return;
            }
            $more = $sync->step($conn);
        } catch (Throwable $e) {
            if ($conn) {
                $sync->fail($conn, $e->getMessage());
                JobFailureReporter::report(self::class, 'Bringing in Connected POS sales history failed', $e, ['adapter' => $conn->adapter_code], $conn->tenant_id);
            }
            return;
        } finally {
            $lock->release();
        }
        if ($more) {
            self::dispatch($this->connectionId);
        }
    }
}
