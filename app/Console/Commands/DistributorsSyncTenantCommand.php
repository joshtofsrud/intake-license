<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\TenantDistributorSyncService;
use Illuminate\Console\Command;

class DistributorsSyncTenantCommand extends Command
{
    protected $signature = 'distributors:sync-tenant
        {subscription? : Subscription id to sync}
        {--all : Sync every active subscription}
        {--dry-run : Report what would change without writing}
        {--parallel=3 : With --all, how many shop/distributor syncs run at once (1 = one after another)}';

    protected $description = 'Tier-2 per-tenant sync: live cost + availability for linked items, sell-price seed, vanish flags.';

    public function handle(TenantDistributorSyncService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($this->argument('subscription')) {
            $subs = TenantDistributorCatalogSubscription::query()
                ->whereKey($this->argument('subscription'))->get();
        } elseif ($this->option('all')) {
            $subs = TenantDistributorCatalogSubscription::query()->where('is_active', true)->get();
        } else {
            $this->error('Pass a subscription id or --all.');
            return self::FAILURE;
        }

        // MARKER-BRAND-DIRECT: skip brand-direct sources a shop is not switched on for
        $subs = $subs->filter(fn ($s) => \App\Support\BrandDirect::allows((string) $s->tenant_id, (string) $s->distributor_code))->values();

        if ($subs->isEmpty()) {
            $this->warn('No matching subscriptions.');
            return self::SUCCESS;
        }

        // MARKER-SYNC-PARALLEL: --all hands each shop + distributor to its own
        // process, a few at a time, so one slow distributor or one big shop no
        // longer holds up everyone else (the nightly run took 9+ hours one
        // after another). Each child is the single-subscription form of this
        // same command, so its errors are reported exactly as before.
        $parallel = max(1, (int) $this->option('parallel'));
        if ($this->option('all') && ! $dry && $parallel > 1 && $subs->count() > 1) {
            return $this->runParallel($subs, $parallel);
        }

        foreach ($subs as $sub) {
            $this->info(($dry ? '[dry-run] ' : '') . "Tenant {$sub->tenant_id} · {$sub->distributor_code} ...");
            $code = strtoupper((string) $sub->distributor_code);

            // MARKER-SYNC-SLICES: in slices of 2,000 items (the "Sync now"
            // size), so a 40k-item list never sits in memory at once and
            // three can safely run side by side.
            $ids = $service->linkedPivotQuery((string) $sub->tenant_id, $code)->orderBy('id')->pluck('id');
            $chunks = $ids->isEmpty() ? [[]] : $ids->chunk(2000)->map->values()->all();
            $runId = $dry ? null : $this->runOpen($sub, $code, $ids->count());
            $after = null; $tot = ['fetch_seconds' => 0, 'write_seconds' => 0]; $done = 0; $errors = [];

            try {
                foreach ($chunks as $i => $chunk) {
                    $last = $i === count($chunks) - 1;
                    $upto = $last ? null : (string) $chunk[count($chunk) - 1];
                    $res = $service->sync($sub, $dry, $after, $upto);
                    $after = $upto;
                    $done += (int) ($res['linked'] ?? 0);
                    foreach (['fetch_seconds', 'write_seconds'] as $k) { $tot[$k] += (int) ($res[$k] ?? 0); }
                    $errors = array_merge($errors, (array) ($res['errors'] ?? []));
                    $this->runSet($runId, ['processed' => $done] + $tot);
                    if (! empty($res['note'])) { $this->line('  ' . $res['note']); }
                }
            } catch (\Throwable $e) {
                $this->error('  ' . $e->getMessage());
                $this->runSet($runId, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()]);
                // the scheduler discards this command's
                // output, so a nightly failure printed only here was silent.
                \App\Support\JobFailureReporter::report(self::class,
                    'Nightly ' . strtoupper((string) $sub->distributor_code) . ' sync failed', $e,
                    ['code' => (string) $sub->distributor_code], (string) $sub->tenant_id);
                continue;
            }
            $this->runSet($runId, ['status' => 'done', 'finished_at' => now()]);
            $this->line("  {$done} items · distributor calls {$tot['fetch_seconds']}s · writes {$tot['write_seconds']}s");
            foreach (array_slice(array_unique($errors), 0, 5) as $e) {
                $this->line("  - {$e}");
            }
        }

        return self::SUCCESS;
    }

    /** MARKER-SYNC-RUNS: one row per shop + distributor; the log never stops a sync. */
    private function runOpen($sub, string $code, int $linked): ?int
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('distributor_sync_runs')) return null;
            return (int) \Illuminate\Support\Facades\DB::table('distributor_sync_runs')->insertGetId([
                'tenant_id' => (string) $sub->tenant_id, 'subscription_id' => (string) $sub->id, 'distributor_code' => $code,
                'status' => 'running', 'linked' => $linked, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) { return null; }
    }

    private function runSet(?int $runId, array $vals): void
    {
        if (! $runId) return;
        try {
            \Illuminate\Support\Facades\DB::table('distributor_sync_runs')->where('id', $runId)->update($vals + ['updated_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function runParallel($subs, int $parallel): int
    {
        // biggest first, so the long ones start early and the short ones fill in
        $queue = $subs->sortByDesc(fn ($s) => \Illuminate\Support\Facades\DB::table('tenant_inventory_item_vendors as v')
            ->join('tenant_inventory_items as i', 'i.id', '=', 'v.inventory_item_id')
            ->where('i.tenant_id', $s->tenant_id)->where('v.distributor_code', strtoupper((string) $s->distributor_code))
            ->count())->values()->all();
        $running = [];
        $php = PHP_BINARY ?: 'php';

        while ($queue || $running) {
            while ($queue && count($running) < $parallel) {
                $sub = array_shift($queue);
                $this->info("Starting {$sub->distributor_code} for tenant {$sub->tenant_id}");
                $running[(string) $sub->id] = \Illuminate\Support\Facades\Process::path(base_path())->forever()
                    ->start([$php, 'artisan', 'distributors:sync-tenant', (string) $sub->id]);
            }
            sleep(2);
            foreach ($running as $id => $proc) {
                if (! $proc->running()) {
                    $r = $proc->wait();
                    $this->line("Finished {$id}" . ($r->successful() ? '' : ' (exit ' . $r->exitCode() . ')'));
                    unset($running[$id]);
                }
            }
        }

        return self::SUCCESS;
    }
}
