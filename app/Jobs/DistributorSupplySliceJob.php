<?php

namespace App\Jobs;

use App\Services\Distributors\DistributorSupply;
use App\Services\Distributors\StockVelocityRecorder;
use App\Services\Distributors\TenantDistributorSyncService;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** MARKER-SUPPLY-RUN: one slice of a distributor's shared run: fetch, save, count progress. */
class DistributorSupplySliceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public string $code, public int $runId, public array $variants, public int $slice) {}

    public function handle(TenantDistributorSyncService $sync): void
    {
        [$adapter] = DistributorSupply::adapterFor($this->code);
        if (! $adapter) {
            throw new \RuntimeException('The login used to fetch ' . $this->code . ' is gone.');
        }
        $base = (int) DB::table('distributor_sync_runs')->where('id', $this->runId)->value('processed');
        $sync->onProgress(function (string $phase, int $done, int $total) use ($base) {
            // cost then stock, each over the slice: count the slice's halves toward the run's total
            $part = $phase === 'cost' ? $done / 2 : ($phase === 'stock' ? count($this->variants) / 2 + $done / 2 : 0);
            DB::table('distributor_sync_runs')->where('id', $this->runId)->update(['processed' => $base + (int) $part, 'updated_at' => now()]);
        });
        $t0 = microtime(true);
        $got = $sync->fetchSupply($adapter, $this->code, $this->variants);
        DistributorSupply::store($this->code, $this->variants, $got);

        // stock history once per run, not once per shop
        try {
            $today = now()->toDateString();
            $readings = [];
            foreach ($got['avail'] as $vno => $qty) {
                $readings[$vno] = ['avail' => $qty, 'catalog_id' => null, 'has_eta_info' => isset($got['wh'][$vno]),
                    'eta' => StockVelocityRecorder::earliestEta($got['wh'][$vno] ?? null, $today)];
            }
            if ($readings) {
                app(StockVelocityRecorder::class)->record($this->code, $readings, null, 'run');
            }
        } catch (\Throwable $e) {
            JobFailureReporter::report(self::class, 'Distributor velocity recording failed for ' . $this->code, $e, ['code' => $this->code]);
        }

        $errs = array_slice($got['errors'], 0, 3);
        DB::table('distributor_sync_runs')->where('id', $this->runId)->update([
            'processed' => $base + count($this->variants),
            'fetch_seconds' => DB::raw('COALESCE(fetch_seconds,0) + ' . (int) round(microtime(true) - $t0)),
            'error' => $errs ? mb_substr(implode(' · ', $errs), 0, 300) : DB::raw('error'),
            'updated_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        DB::table('distributor_sync_runs')->where('id', $this->runId)->update([
            'status' => 'failed', 'error' => mb_substr('Stopped at slice ' . $this->slice . ': ' . $e->getMessage(), 0, 300), 'finished_at' => now(), 'updated_at' => now(),
        ]);
        JobFailureReporter::report(self::class, $this->code . ' shared run slice ' . $this->slice . ' stopped', $e, ['code' => $this->code]);
    }
}
