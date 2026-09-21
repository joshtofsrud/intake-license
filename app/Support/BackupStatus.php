<?php
// MARKER-BACKUP-RECORD

namespace App\Support;

use App\Models\SystemHealth;
use Carbon\Carbon;

/**
 * One reading of the nightly backup for every surface that shows it — the
 * dashboard tile, the operations row and the health widget — so they cannot
 * disagree. Written by `artisan backup:record`.
 *
 * state: none (never reported) · bad (last run failed, or no good run in 36h)
 *        warn (no good run in 30h) · ok
 */
class BackupStatus
{
    public static function read(): array
    {
        $ok   = SystemHealth::read('last_backup') ?? [];
        $fail = SystemHealth::read('last_backup_failure') ?? [];

        $okAt   = ! empty($ok['at'])   ? Carbon::parse($ok['at'])   : null;
        $failAt = ! empty($fail['at']) ? Carbon::parse($fail['at']) : null;

        $failedLast = $failAt && (! $okAt || $failAt->gt($okAt));
        $ageHours   = $okAt ? abs($okAt->diffInHours(now())) : null;

        $state = match (true) {
            ! $okAt && ! $failAt => 'none',
            $failedLast          => 'bad',
            $ageHours > 36       => 'bad',
            $ageHours > 30       => 'warn',
            default              => 'ok',
        };

        return [
            'state'     => $state,
            'at'        => $okAt,
            'age_hours' => $ageHours,
            'bytes'     => isset($ok['bytes']) ? (int) $ok['bytes'] : null,
            'mb'        => isset($ok['bytes']) ? round($ok['bytes'] / 1024 / 1024, 1) : null,
            'duration'  => $ok['duration_sec'] ?? null,
            'failed_at' => $failedLast ? $failAt : null,
            'reason'    => $failedLast ? (string) ($fail['reason'] ?? '') : null,
        ];
    }
}
