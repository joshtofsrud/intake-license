<?php

namespace App\Jobs;

use App\Services\Sales\OutreachAutoSend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** MARKER-OUTREACH-AUTO: one step of auto-send; queues the next step itself. */
class OutreachAutoSendTick implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 110;

    public function __construct(public string $runId) {}

    public function handle(): void
    {
        try {
            $wait = OutreachAutoSend::tick($this->runId);
        } catch (\Throwable $e) {
            \App\Support\JobFailureReporter::report(self::class, 'Outreach auto-send stopped on an error', $e);
            OutreachAutoSend::stop('Stopped on an error: ' . \Illuminate\Support\Str::limit($e->getMessage(), 200));
            return;
        }
        if ($wait !== null) {
            self::dispatch($this->runId)->delay(now()->addSeconds($wait));
        }
    }
}
