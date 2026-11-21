<?php

namespace App\Jobs;

use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosCustomerSync;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** MARKER-POS-CUSTOMERS-IN: one step of bringing in customers; queues the next until caught up. */
class PosCustomerSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public string $connectionId) {}

    public function handle(PosCustomerSync $sync): void
    {
        $lock = Cache::lock('pos_customer_sync:' . $this->connectionId, 290);
        if (! $lock->get()) {
            return;
        }
        $conn = TenantPosConnection::find($this->connectionId);
        try {
            if (! $conn || ! $conn->customers_in || $conn->status !== 'connected' || ! (($conn->customer_sync ?? [])['running'] ?? false)) {
                return;
            }
            $more = $sync->step($conn);
        } catch (Throwable $e) {
            if ($conn) {
                $sync->fail($conn, $e->getMessage());
                JobFailureReporter::report(self::class, 'Bringing in Connected POS customers failed', $e, ['adapter' => $conn->adapter_code], $conn->tenant_id);
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
