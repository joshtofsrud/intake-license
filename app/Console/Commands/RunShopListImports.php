<?php
// MARKER-BG-IMPORT: every minute, carry on loading any shop list that is still going.

namespace App\Console\Commands;

use App\Services\Sales\ShopListRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunShopListImports extends Command
{
    protected $signature = 'sales:run-imports {--budget=50 : Seconds to spend this run}';
    protected $description = 'Load queued shop lists (Find shops > Load a shop list) in the background';

    public function handle(): int
    {
        @set_time_limit(0);
        $budget = max(5, (int) $this->option('budget'));
        $start = microtime(true);
        foreach (DB::table('sales_import_runs')->whereIn('status', ['queued', 'running'])->orderBy('id')->pluck('id') as $id) {
            $left = (int) ($budget - (microtime(true) - $start));
            if ($left < 5) break;
            $this->line("run $id: " . ShopListRuns::work((int) $id, $left));
        }
        // half-finished uploads nobody came back for
        foreach (glob(storage_path('app/shop-imports/up-*')) ?: [] as $f) {
            if (filemtime($f) < time() - 86400 && ! DB::table('sales_import_runs')->where('path', $f)->exists()) @unlink($f);
        }
        return self::SUCCESS;
    }
}
