<?php

namespace App\Listeners;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-TASK-HEALTH — writes a row for every scheduled run.
 *
 * Recording must never be the reason a task fails, so every write is wrapped:
 * a broken health table would otherwise take the campaign worker down with it,
 * which is precisely the failure this feature exists to notice.
 */
class RecordScheduledTask
{
    private array $open = [];

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->safely(function () use ($event) {
            $row = ScheduledTaskRun::create([
                'command'    => $this->name($event),
                'started_at' => now(),
            ]);
            $this->open[$this->name($event)] = $row->id;
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->close($event, true, null, (int) round($event->runtime * 1000));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->close($event, false, mb_substr((string) $event->exception?->getMessage(), 0, 500), null);
    }

    private function close($event, bool $ok, ?string $failure, ?int $ms): void
    {
        $this->safely(function () use ($event, $ok, $failure, $ms) {
            $name = $this->name($event);
            $id   = $this->open[$name] ?? null;

            $row = $id
                ? ScheduledTaskRun::find($id)
                : ScheduledTaskRun::where('command', $name)->whereNull('finished_at')
                    ->latest('started_at')->first();

            if (! $row) return;

            $row->update([
                'finished_at' => now(),
                'duration_ms' => $ms ?? (int) round(now()->diffInMilliseconds($row->started_at)),
                'ok'          => $ok,
                'failure'     => $failure,
            ]);

            unset($this->open[$name]);
        });
    }

    private function name($event): string
    {
        $c = $event->task->command ?? $event->task->description ?? 'unknown';
        // "'/usr/bin/php' 'artisan' campaigns:process-sends" → the command
        return trim(preg_replace("/^.*'artisan'\s*/", '', (string) $c));
    }

    private function safely(callable $fn): void
    {
        try {
            if (! Schema::hasTable('scheduled_task_runs')) return;
            $fn();
        } catch (\Throwable $e) {
            // Deliberately swallowed: health recording is not worth failing a
            // real job over.
            logger()->warning('MARKER-TASK-HEALTH could not record a run', ['error' => $e->getMessage()]);
        }
    }
}
