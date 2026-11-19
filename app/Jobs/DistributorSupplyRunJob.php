<?php

namespace App\Jobs;

use App\Services\Distributors\DistributorSupply;
use App\Support\BrandDirect;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SUPPLY-RUN: one distributor's shared run. Plans slices of part
 * numbers (every one any shop carries), fetched once with one login, then a
 * finishing job closes the run. Its row in distributor_sync_runs is what the
 * dashboard shows ("All shops"), live.
 */
class DistributorSupplyRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const SLICE = 2000;
    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public string $code) {}

    public function handle(): void
    {
        $code = strtoupper($this->code);
        if (BrandDirect::is($code)) {
            return; // a brand sold direct gets cost and stock from its stock file
        }
        $running = DB::table('distributor_sync_runs')->where('subscription_id', 'supply')->where('distributor_code', $code)
            ->where('status', 'running')->where('started_at', '>', now()->subHours(12))->exists();
        if ($running) {
            return; // one run at a time per distributor
        }
        [$adapter, $source] = DistributorSupply::adapterFor($code);
        $variants = DistributorSupply::variantsFor($code);
        $runId = DB::table('distributor_sync_runs')->insertGetId([
            'tenant_id' => DistributorSupply::SUPPLY_TENANT, 'subscription_id' => 'supply', 'distributor_code' => $code,
            'status' => $adapter ? 'running' : 'failed', 'linked' => count($variants), 'processed' => 0,
            'error' => $adapter ? mb_substr('Using ' . $source, 0, 300) : 'No login to fetch with: set the platform login in master admin, or connect a shop.',
            'started_at' => now(), 'finished_at' => $adapter ? null : now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $adapter) {
            return;
        }
        $jobs = [];
        foreach (array_chunk($variants, self::SLICE) as $n => $slice) {
            $jobs[] = new DistributorSupplySliceJob($code, $runId, $slice, $n + 1);
        }
        $jobs[] = new DistributorSupplyFinishJob($runId);
        try {
            Bus::chain($jobs)->dispatch();
        } catch (\Throwable $e) {
            DB::table('distributor_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now(), 'updated_at' => now()]);
            JobFailureReporter::report(self::class, $code . ' shared run could not start', $e, ['code' => $code]);
        }
    }
}
