<?php
// MARKER-DATA-RETENTION

namespace App\Console\Commands;

use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly retention for tables that keep history nobody reads after a while.
 * Platform-wide maintenance: these deletes span every tenant on purpose.
 * Each rule stands alone — one failing is reported to Issues and the rest
 * still run. Deletes go in batches so no single statement holds locks long.
 *
 *   scheduled_task_runs             14 days   Task Health reads only the last 6 runs per task
 *   tenant_pricing_attention_flags  resolved flags, 30 days after resolution
 *                                             (nothing reads resolved flags; a problem that
 *                                             returns simply opens a new flag)
 *   catalog_change_batches          90 days   the Catalog changes / Undo history; its items
 *                                             go with it (foreign key cascade)
 *   tenant_import_ledger            30 days after the import finished or was last touched.
 *                                             Finished screens read tenant_import_rows and the
 *                                             error file, never the ledger; an old draft whose
 *                                             ledger is cleared re-runs its preview when opened.
 *
 * debug_logs has its own retention (debug-log:prune), funnel events theirs (funnel:prune).
 */
class DataPrune extends Command
{
    protected $signature = 'data:prune {--dry : count what would be deleted, delete nothing}';

    protected $description = 'Apply retention to history tables: task runs, resolved flags, catalog undo history, import ledger';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $this->prune('task runs, 14 days', 5000, $dry, fn () => DB::table('scheduled_task_runs')
            ->where('started_at', '<', now()->subDays(14)));

        $this->prune('resolved attention flags, 30 days', 5000, $dry, fn () => DB::table('tenant_pricing_attention_flags')
            ->where('status', 'resolved')
            ->where(fn ($q) => $q->where('resolved_at', '<', now()->subDays(30))
                ->orWhere(fn ($q) => $q->whereNull('resolved_at')->where('updated_at', '<', now()->subDays(30)))));

        // Small batches: each batch row cascades to all of its items.
        $this->prune('catalog undo history, 90 days', 20, $dry, fn () => DB::table('catalog_change_batches')
            ->where('created_at', '<', now()->subDays(90)));

        $this->pruneImportLedger($dry);

        return self::SUCCESS;
    }

    private function prune(string $label, int $batch, bool $dry, \Closure $query): void
    {
        try {
            if ($dry) {
                $n = $query()->count();
            } else {
                $n = 0;
                do {
                    $deleted = $query()->limit($batch)->delete();
                    $n += $deleted;
                } while ($deleted === $batch);
            }
            $this->line(sprintf('  %-36s %s %s', $label, number_format($n), $dry ? 'would be deleted' : 'deleted'));
        } catch (\Throwable $e) {
            $this->error("  {$label}: {$e->getMessage()}");
            JobFailureReporter::report(self::class, "Retention failed: {$label}", $e);
        }
    }

    private function pruneImportLedger(bool $dry): void
    {
        $label = 'import ledger, 30 days after finish';
        try {
            $ids = DB::table('tenant_imports')
                ->where('status', '!=', 'running')
                ->whereRaw('COALESCE(finished_at, updated_at) < ?', [now()->subDays(30)])
                ->whereIn('id', DB::table('tenant_import_ledger')->select('import_id')->distinct())
                ->pluck('id');

            $n = 0;
            foreach ($ids as $id) {
                $query = fn () => DB::table('tenant_import_ledger')->where('import_id', $id);
                if ($dry) {
                    $n += $query()->count();
                    continue;
                }
                do {
                    $deleted = $query()->limit(5000)->delete();
                    $n += $deleted;
                } while ($deleted === 5000);
            }
            $this->line(sprintf('  %-36s %s %s (%d imports)', $label, number_format($n), $dry ? 'would be deleted' : 'deleted', $ids->count()));
        } catch (\Throwable $e) {
            $this->error("  {$label}: {$e->getMessage()}");
            JobFailureReporter::report(self::class, "Retention failed: {$label}", $e);
        }
    }
}
