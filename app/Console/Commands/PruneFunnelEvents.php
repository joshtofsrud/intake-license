<?php
// MARKER-PATCH-151C

namespace App\Console\Commands;

use App\Models\Tenant\TenantFunnelEvent;
use Illuminate\Console\Command;

/**
 * Prune tenant_funnel_events older than 90 days.
 *
 * Aligned with the privacy notice — we keep aggregate analytics in
 * tenants' own dashboards for a fixed 90-day window. Older rows have
 * little reporting value and add storage bloat.
 *
 * Chunked delete (5k rows per pass) so we don't hold a single huge
 * row-lock or transaction. Safe to run during business hours.
 */
class PruneFunnelEvents extends Command
{
    protected $signature = 'funnel:prune
                            {--days=90  : Retention window in days (default 90)}
                            {--chunk=5000 : Max rows per delete pass}
                            {--dry-run : Show how many rows would be deleted without deleting}';

    protected $description = 'Delete tenant_funnel_events older than --days (default 90).';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $chunk = max(100, (int) $this->option('chunk'));
        $dry   = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);

        $this->info($dry ? 'DRY RUN — no rows will be deleted' : 'Pruning tenant_funnel_events...');
        $this->line(sprintf('  cutoff:  %s (%d days)', $cutoff->toDateTimeString(), $days));

        $total = 0;
        $passes = 0;

        while (true) {
            // Use a subquery to limit the delete batch. Eloquent's ->limit()
            // on delete() is supported in MySQL but not portable; this works.
            $ids = TenantFunnelEvent::where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) break;

            $count = $ids->count();

            if (! $dry) {
                TenantFunnelEvent::whereIn('id', $ids)->delete();
            }

            $total  += $count;
            $passes += 1;
            $this->line(sprintf('  pass %d: %s rows', $passes, number_format($count)));

            // Safety: never run more than 200 passes (= 1M rows by default).
            if ($passes >= 200) {
                $this->warn('  hit safety cap of 200 passes — stopping. Re-run to continue.');
                break;
            }

            // In dry-run, the rows aren't actually deleted, so we'd loop forever.
            if ($dry) break;
        }

        $this->info(sprintf('%s %s rows in %d %s.',
            $dry ? 'Would prune' : 'Pruned',
            number_format($total),
            $passes,
            $passes === 1 ? 'pass' : 'passes'
        ));

        return self::SUCCESS;
    }
}
