<?php
// MARKER-PATCH-HLC4A

namespace App\Console\Commands;

use App\Services\Distributors\DistributorCatalogImportService;
use Illuminate\Console\Command;

class DistributorsImportCatalogCommand extends Command
{
    protected $signature = 'distributors:import-catalog
        {tenant : Tenant id}
        {--distributor=HLC}
        {--category= : Filter by catalog category (LIKE)}
        {--brand= : Filter by manufacturer/brand (LIKE)}
        {--limit=0 : Cap rows imported (0 = no cap)}
        {--include-unsellable : Also import discontinued/unsellable rows}
        {--dry-run : Report counts without writing}';

    protected $description = 'Import distributor catalog rows into a tenant catalog (deduped on product_key, catalog-only).';

    public function handle(DistributorCatalogImportService $import): int
    {
        $filters = array_filter([
            'category' => $this->option('category'),
            'brand' => $this->option('brand'),
            'include_unsellable' => (bool) $this->option('include-unsellable'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== false);

        $res = $import->import(
            (string) $this->argument('tenant'),
            (string) $this->option('distributor'),
            $filters,
            (bool) $this->option('dry-run'),
            (int) $this->option('limit'),
        );

        $this->table(
            ['metric', 'value'],
            collect($res)->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : $v])->values()->all()
        );

        $this->info(($res['dry_run'] ? '[dry-run] ' : '') . "Pulled {$res['created']} new, merged {$res['merged']} as new {$res['code']} source, skipped {$res['skipped']} already-carried.");
        return self::SUCCESS;
    }
}
