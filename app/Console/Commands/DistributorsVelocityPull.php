<?php

namespace App\Console\Commands;

use App\Services\Distributors\StockVelocityRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-VELOCITY: one-off after deploy (safe to re-run).
 *  1. Seeds a summary for every distributor item from the readings that
 *     existed before the build, and keeps that reading as the baseline.
 *  2. Runs the normal per-shop sync once, which takes a fresh reading and
 *     measures it against the baseline.
 */
class DistributorsVelocityPull extends Command
{
    protected $signature = 'distributors:velocity-pull {--seed-only : Seed from existing readings without calling the distributors}';
    protected $description = 'Seed distributor velocity from pre-build readings, then take a fresh reading from every distributor.';

    public function handle(StockVelocityRecorder $rec): int
    {
        $this->info('Seeding from the readings taken before this build ...');
        $s = $rec->seedFromSnapshots();
        $this->line("  {$s['seeded']} items seeded, {$s['skipped']} already had a summary.");

        if (! $this->option('seed-only')) {
            $this->info('Taking a fresh reading (the nightly per-shop sync, run once now) ...');
            Artisan::call('distributors:sync-tenant', ['--all' => true], $this->output);
        }

        $this->newLine();
        $this->info('Compared with before the build:');
        $rows = DB::table('distributor_stock_summary')
            ->selectRaw('distributor_code,
                COUNT(*) AS items,
                SUM(baseline_avail IS NOT NULL AND readings > 1) AS reread,
                SUM(baseline_avail IS NOT NULL AND readings > 1 AND avail < baseline_avail) AS dropped,
                SUM(baseline_avail IS NOT NULL AND readings > 1 AND avail > baseline_avail) AS restocked,
                SUM(next_arrival_on IS NOT NULL) AS with_arrival,
                MIN(baseline_at) AS since')
            ->groupBy('distributor_code')->orderBy('distributor_code')->get();
        $this->table(['Distributor', 'Items', 'Read again', 'Went down', 'Went up', 'With arrival date', 'Baseline from'],
            $rows->map(fn ($r) => [$r->distributor_code, $r->items, (int) $r->reread, (int) $r->dropped, (int) $r->restocked, (int) $r->with_arrival, $r->since])->all());
        $this->line('Readings within ' . StockVelocityRecorder::READING_GAP_HOURS . ' hours of the baseline are not counted twice, so "Read again" fills in from tonight\'s sync if the baseline is recent.');

        return self::SUCCESS;
    }
}
