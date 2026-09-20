<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT-QUEUE — write progress the screen can read.
 *
 * Throttled to one write per 400 rows or per second, whichever comes first:
 * an UPDATE per row on an 18k file is 18k writes for a bar that moves in
 * pixels. progress_seen_at is the heartbeat — the modal calls a run stalled
 * when it stops moving, which is how a dead worker becomes visible instead of
 * looking like a slow one.
 */
trait ReportsProgress
{
    protected int $progressDone = 0;
    protected float $progressLastWrite = 0;

    protected function progressStart(string $stage, int $total): void
    {
        $this->progressDone = 0;
        $this->progressLastWrite = microtime(true);
        $this->import->forceFill([
            'progress_stage'      => $stage,
            'progress_total'      => $total,
            'progress_done'       => 0,
            'progress_seen_at'    => now(),
            'cancel_requested_at' => null,
        ])->save();
    }

    protected function progressTick(int $by = 1, array $counts = []): void
    {
        $this->progressDone += $by;

        $now = microtime(true);
        if ($this->progressDone % 400 !== 0 && ($now - $this->progressLastWrite) < 1.0) {
            return;
        }
        $this->progressLastWrite = $now;

        $patch = ['progress_done' => $this->progressDone, 'progress_seen_at' => now()];
        if ($counts) {
            $patch['totals'] = array_merge((array) $this->import->totals, ['live' => $counts]);
        }
        $this->import->forceFill($patch)->save();
    }

    protected function progressDone(string $stage): void
    {
        $this->import->forceFill([
            'progress_stage'   => $stage,
            'progress_done'    => $this->progressDone,
            'progress_seen_at' => now(),
        ])->save();
    }

    /** Checked between chunks — a cancel that actually stops the work. */
    protected function cancelRequested(): bool
    {
        return (bool) $this->import->newQuery()
            ->whereKey($this->import->id)
            ->value('cancel_requested_at');
    }

    /** Rows in the file, for the denominator. */
    protected function rowTotal(): int
    {
        // MARKER-IMPORT-PROGRESS-FIX — the uploader puts it in options.
        return $this->import->rowCount();
    }
}
