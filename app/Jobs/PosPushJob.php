<?php

namespace App\Jobs;

use App\Services\Pos\PosPushService;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MARKER-CONNECTED-POS-PUSH: one send to a Connected POS. Retried twice (1 and 5
 * minutes later); a final failure is marked on the send and reported to Issues,
 * and the Connected POS tab offers Retry.
 */
class PosPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300];
    public int $timeout = 120;

    public function __construct(public int $pushId) {}

    public function handle(PosPushService $svc): void
    {
        $lock = Cache::lock('pos_push:' . $this->pushId, 110);
        if (! $lock->get()) {
            $this->release(30);
            return;
        }
        try {
            $svc->run($this->pushId);
        } catch (Throwable $e) {
            DB::table('tenant_pos_pushes')->where('id', $this->pushId)->update(['last_error' => mb_substr($e->getMessage(), 0, 900), 'updated_at' => now()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        $push = DB::table('tenant_pos_pushes')->find($this->pushId);
        app(PosPushService::class)->mark($this->pushId, 'failed', null, $e->getMessage());
        JobFailureReporter::report(self::class, 'Connected POS send failed: ' . ($push->label ?? $this->pushId), $e, ['push' => $this->pushId], $push->tenant_id ?? null);
    }
}
