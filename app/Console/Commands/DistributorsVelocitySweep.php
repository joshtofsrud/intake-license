<?php

namespace App\Console\Commands;

use App\Services\Distributors\StockVelocitySweep;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** MARKER-VELOCITY-SWEEP: whole-catalog stock reading with the master admin logins, every 4 hours. */
class DistributorsVelocitySweep extends Command
{
    protected $signature = 'distributors:velocity-sweep {codes?* : Distributor codes to read now, whatever their schedule} {--max-calls=5000 : Cap on stock calls per distributor}';
    protected $description = 'Read distributor stock with the master admin logins for distributor velocity. Runs hourly; each distributor is read as often as its fetch setting says.';

    public function handle(StockVelocitySweep $sweep): int
    {
        $named = array_map('strtoupper', (array) $this->argument('codes'));
        if ($named) {
            $codes = $named; // asked for by name: read now
        } else {
            // MARKER-VELOCITY-FETCH: only the distributors due by their fetch setting
            $last = \App\Support\VelocityFetch::lastReads();
            $codes = array_values(array_filter(['BTI', 'HLC', 'QBP', 'SHIMANO'], fn ($c) => \App\Support\VelocityFetch::due($c, $last[$c] ?? null)));
            if (! $codes) {
                $this->line('No distributor is due yet.');
                return self::SUCCESS;
            }
        }
        $rows = [];
        $log = [];
        $problems = 0;
        // MARKER-VELOCITY-SWEEP: each run is logged for the velocity page and the dashboard
        $runId = DB::table('distributor_velocity_runs')->insertGetId(['started_at' => now()]);
        foreach ($codes as $code) {
            $this->info("Reading {$code} ...");
            try {
                $r = $sweep->run($code, (int) $this->option('max-calls'));
            } catch (\Throwable $e) {
                JobFailureReporter::report(self::class, 'Distributor velocity sweep failed for ' . $code, $e, ['code' => $code]);
                $r = ['code' => $code, 'items' => 0, 'read' => 0, 'counted' => 0, 'changed' => 0, 'new' => 0, 'calls' => 0, 'note' => 'Failed: ' . substr($e->getMessage(), 0, 120)];
            }
            $rows[] = [$r['code'], $r['items'], $r['calls'], $r['read'], $r['counted'], $r['changed'], $r['new'], $r['note'] ?? ''];
            $log[] = $r;
            if (! empty($r['note']) || ($r['items'] > 0 && $r['read'] === 0)) {
                $problems++;
            }
        }
        DB::table('distributor_velocity_runs')->where('id', $runId)->update([
            'finished_at' => now(), 'results' => json_encode($log), 'problems' => $problems,
        ]);
        DB::table('distributor_velocity_runs')->where('started_at', '<', now()->subDays(14))->delete();
        $this->table(['Distributor', 'Catalog items', 'Calls', 'Stock read', 'Counted', 'Changed', 'New', 'Note'], $rows);
        $this->line('"Counted" excludes items already read in the last hour.');
        return self::SUCCESS;
    }
}
