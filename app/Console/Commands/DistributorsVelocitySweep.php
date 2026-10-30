<?php

namespace App\Console\Commands;

use App\Services\Distributors\StockVelocitySweep;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** MARKER-VELOCITY-SWEEP: whole-catalog stock reading with the master admin logins, every 4 hours. */
class DistributorsVelocitySweep extends Command
{
    protected $signature = 'distributors:velocity-sweep {codes?* : Distributor codes (default: all with a master admin login)} {--max-calls=5000 : Cap on stock calls per distributor}';
    protected $description = 'Read every catalog item\'s distributor stock with the master admin logins (runs every 4 hours) for distributor velocity.';

    public function handle(StockVelocitySweep $sweep): int
    {
        $codes = array_map('strtoupper', (array) $this->argument('codes')) ?: ['BTI', 'HLC', 'QBP', 'SHIMANO'];
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
        $this->line('"Counted" excludes items already read in the last 6 hours.');
        return self::SUCCESS;
    }
}
