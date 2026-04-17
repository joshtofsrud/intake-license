<?php

namespace App\Console\Commands;

use App\Models\DebugLog;
use Illuminate\Console\Command;

/**
 * Prune old debug_log rows per channel retention policy.
 *
 * Schedule once per day in routes/console.php.
 */
class PruneDebugLogs extends Command
{
    protected $signature = 'debug-log:prune
                            {--dry-run : Show how many rows would be deleted without deleting}';

    protected $description = 'Delete old debug_logs rows per config/debug.php retention settings';

    public function handle(): int
    {
        $retention = (array) config('debug.retention_days', []);
        $dry       = (bool) $this->option('dry-run');
        $total     = 0;

        $this->info($dry ? 'DRY RUN — no rows will be deleted' : 'Pruning debug_logs...');

        foreach ($retention as $channel => $days) {
            if ($channel === 'resolved_error') continue; // handled below
            if (! is_numeric($days) || $days <= 0)  continue;

            $cutoff = now()->subDays((int) $days);

            $q = DebugLog::where('channel', $channel)
                ->where('created_at', '<', $cutoff);

            $count = $q->count();

            if ($count === 0) {
                $this->line(sprintf('  %-14s  %4d days  nothing to prune', $channel, $days));
                continue;
            }

            if (! $dry) {
                $q->delete();
            }

            $total += $count;
            $this->line(sprintf('  %-14s  %4d days  %s rows', $channel, $days, number_format($count)));
        }

        // Resolved errors get shorter retention than open ones.
        if ($resolvedDays = (int) ($retention['resolved_error'] ?? 0)) {
            $cutoff = now()->subDays($resolvedDays);
            $q = DebugLog::where('channel', 'error')
                ->where('is_resolved', true)
                ->where('resolved_at', '<', $cutoff);

            $count = $q->count();
            if ($count > 0) {
                if (! $dry) $q->delete();
                $total += $count;
                $this->line(sprintf('  %-14s  %4d days  %s rows', 'resolved_err', $resolvedDays, number_format($count)));
            }
        }

        $this->newLine();
        $this->info(($dry ? 'Would prune ' : 'Pruned ') . number_format($total) . ' total rows.');

        return self::SUCCESS;
    }
}
