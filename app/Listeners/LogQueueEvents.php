<?php

namespace App\Listeners;

use App\Services\DebugLogService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Hooks queue lifecycle events into the debug panel. We log:
 *   - processing (debug level — usually noise unless you're debugging)
 *   - processed  (info)
 *   - failed     (error with full exception)
 *
 * Retries show up as a fresh 'processing' row with a higher attempts count.
 */
class LogQueueEvents
{
    public function __construct(protected DebugLogService $log) {}

    public function handleProcessing(JobProcessing $event): void
    {
        $this->log->job(
            'processing',
            $event->job->resolveName(),
            $event->connectionName . ':' . $event->job->getQueue(),
            $event->job->attempts(),
            [],
            'debug',
        );
    }

    public function handleProcessed(JobProcessed $event): void
    {
        if ($event->job->hasFailed()) return; // failed branch handles it

        $this->log->job(
            'completed',
            $event->job->resolveName(),
            $event->connectionName . ':' . $event->job->getQueue(),
            $event->job->attempts(),
            [],
            'info',
        );
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->log->job(
            'failed',
            $event->job->resolveName(),
            $event->connectionName . ':' . $event->job->getQueue(),
            $event->job->attempts(),
            [
                'exception' => get_class($event->exception),
                'message'   => $event->exception->getMessage(),
                'file'      => $event->exception->getFile(),
                'line'      => $event->exception->getLine(),
            ],
            'error',
        );
    }

    public function subscribe(): array
    {
        return [
            JobProcessing::class => 'handleProcessing',
            JobProcessed::class  => 'handleProcessed',
            JobFailed::class     => 'handleFailed',
        ];
    }
}
