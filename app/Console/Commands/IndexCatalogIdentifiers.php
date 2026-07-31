<?php

// MARKER-CATALOG-IDENTIFIERS

namespace App\Console\Commands;

use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogIdentifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * (Re)builds catalog_identifiers. Safe to re-run — a distributor's rows are
 * cleared and rebuilt inside a transaction, so a half-finished run can't
 * leave the index partially populated for that distributor.
 */
class IndexCatalogIdentifiers extends Command
{
    protected $signature = 'catalog:index-identifiers
        {code? : distributor code, omit for all}
        {--chunk=500}';

    protected $description = 'Rebuild the cross-distributor identifier index';

    public function handle(CatalogIdentifierService $svc): int
    {
        $code = $this->argument('code');

        $codes = $code
            ? [$code]
            : PlatformDistributorCatalog::query()
                ->select('distributor_code')->distinct()
                ->pluck('distributor_code')->all();

        foreach ($codes as $dist) {
            $total = PlatformDistributorCatalog::where('distributor_code', $dist)
                ->where('is_active', true)->count();

            $this->info("{$dist}: {$total} active rows");
            $bar = $this->output->createProgressBar($total);

            DB::table('catalog_identifiers')->where('distributor_code', $dist)->delete();

            $written = 0;
            $noneAtAll = 0;
            $byType = ['upc' => 0, 'ean' => 0, 'mpn' => 0];

            PlatformDistributorCatalog::where('distributor_code', $dist)
                ->where('is_active', true)
                ->chunkById((int) $this->option('chunk'), function ($rows) use (
                    $svc, $dist, &$written, &$noneAtAll, &$byType, $bar
                ) {
                    $batch = [];
                    $now = now();

                    foreach ($rows as $row) {
                        $ids = $svc->forRow($row);
                        if (! $ids) {
                            $noneAtAll++;          // unmatchable by any key
                        }
                        foreach ($ids as $i) {
                            $byType[$i['type']] = ($byType[$i['type']] ?? 0) + 1;
                            $batch[] = [
                                'distributor_catalog_id' => $row->id,
                                'distributor_code'       => $dist,
                                'identifier_type'        => $i['type'],
                                'value_norm'             => $i['value'],
                                'created_at'             => $now,
                                'updated_at'             => $now,
                            ];
                        }
                        $bar->advance();
                    }

                    if ($batch) {
                        // insertOrIgnore: the unique key is the guard, and a
                        // duplicate inside one feed shouldn't abort the run.
                        DB::table('catalog_identifiers')->insertOrIgnore($batch);
                        $written += count($batch);
                    }
                });

            $bar->finish();
            $this->newLine();
            $this->line("  identifiers written: {$written}"
                . "  (upc {$byType['upc']} · ean {$byType['ean']} · mpn {$byType['mpn']})");

            if ($noneAtAll > 0) {
                $this->warn("  {$noneAtAll} rows produced NO identifier — unmatchable by any key");
            }
            $this->newLine();
        }

        // What the matching pass will actually have to work with.
        $shared = DB::table('catalog_identifiers as a')
            ->join('catalog_identifiers as b', function ($j) {
                $j->on('a.identifier_type', '=', 'b.identifier_type')
                  ->on('a.value_norm', '=', 'b.value_norm')
                  ->on('a.distributor_code', '<', 'b.distributor_code');
            })
            ->distinct()
            ->count(DB::raw('CONCAT(a.identifier_type, a.value_norm)'));

        $this->info("Identifier values shared across distributors: {$shared}");
        return self::SUCCESS;
    }
}
