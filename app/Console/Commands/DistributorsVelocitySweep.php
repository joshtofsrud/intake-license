<?php

namespace App\Console\Commands;

use App\Services\Distributors\StockVelocitySweep;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;

/** MARKER-VELOCITY-SWEEP: nightly whole-catalog stock reading with the master admin logins. */
class DistributorsVelocitySweep extends Command
{
    protected $signature = 'distributors:velocity-sweep {codes?* : Distributor codes (default: all with a master admin login)} {--max-calls=5000 : Cap on stock calls per distributor}';
    protected $description = 'Read every catalog item\'s distributor stock once, with the master admin logins, for distributor velocity.';

    public function handle(StockVelocitySweep $sweep): int
    {
        $codes = array_map('strtoupper', (array) $this->argument('codes')) ?: ['BTI', 'HLC', 'QBP', 'SHIMANO'];
        $rows = [];
        foreach ($codes as $code) {
            $this->info("Reading {$code} ...");
            try {
                $r = $sweep->run($code, (int) $this->option('max-calls'));
            } catch (\Throwable $e) {
                JobFailureReporter::report(self::class, 'Distributor velocity sweep failed for ' . $code, $e, ['code' => $code]);
                $r = ['code' => $code, 'items' => 0, 'read' => 0, 'counted' => 0, 'changed' => 0, 'new' => 0, 'calls' => 0, 'note' => 'Failed: ' . substr($e->getMessage(), 0, 120)];
            }
            $rows[] = [$r['code'], $r['items'], $r['calls'], $r['read'], $r['counted'], $r['changed'], $r['new'], $r['note'] ?? ''];
        }
        $this->table(['Distributor', 'Catalog items', 'Calls', 'Stock read', 'Counted', 'Changed', 'New', 'Note'], $rows);
        $this->line('"Counted" excludes items already read in the last 6 hours.');
        return self::SUCCESS;
    }
}
