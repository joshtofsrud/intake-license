<?php
// MARKER-PATCH-372

namespace App\Console\Commands;

use App\Models\PlatformDistributorCatalog;
use App\Models\PlatformDistributorConnection;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Distributors\DistributorRegistry;
use Illuminate\Console\Command;

class DistributorsBackfillImagesCommand extends Command
{
    protected $signature = 'distributors:backfill-images
        {code=HLC : Distributor code}
        {--key= : API key (falls back to stored connection / {CODE}_API_KEY env)}
        {--all : Backfill every catalog row, not just those linked to a tenant item}
        {--refresh : Re-fetch even rows that already have images}
        {--chunk=100 : SKUs per API call}';

    protected $description = 'Backfill platform_distributor_catalog.images for already-synced rows (public CDN image URLs).';

    public function handle(DistributorRegistry $registry): int
    {
        $code = strtoupper((string) $this->argument('code'));

        $key = (string) ($this->option('key')
            ?: optional(PlatformDistributorConnection::where('distributor_code', $code)->first())->api_key
            ?: config('distributors.' . strtolower($code) . '.api_key')
            ?: env($code . '_API_KEY', ''));

        if ($key === '') {
            $this->error("No API key. Pass --key= or set {$code}_API_KEY in .env.");
            return self::FAILURE;
        }

        try {
            $adapter = $registry->make($code, ['api_key' => $key, 'region' => 'us']);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $q = PlatformDistributorCatalog::query()->where('distributor_code', $code);

        if (! $this->option('all')) {
            $linkedIds = TenantInventoryItem::query()
                ->whereNotNull('distributor_catalog_id')
                ->distinct()
                ->pluck('distributor_catalog_id');
            $q->whereIn('id', $linkedIds);
        }

        if (! $this->option('refresh')) {
            $q->where(function ($w) {
                $w->whereNull('images')->orWhere('images', '[]')->orWhere('images', '');
            });
        }

        $rows = $q->get(['id', 'distributor_variant_no']);
        if ($rows->isEmpty()) {
            $this->info('Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Backfilling images for {$rows->count()} {$code} rows ...");
        $chunkSize = max(1, (int) $this->option('chunk'));
        $updated = 0;
        $noImg = 0;
        $errors = 0;

        foreach ($rows->chunk($chunkSize) as $chunk) {
            $skus = $chunk->pluck('distributor_variant_no')->filter()->values()->all();
            if (empty($skus)) {
                continue;
            }

            try {
                $resp = $adapter->images($skus);
            } catch (\Throwable $e) {
                $errors += count($skus);
                $this->warn('  chunk failed: ' . $e->getMessage());
                continue;
            }

            $byVno = [];
            foreach ((array) $resp as $r) {
                $vno = $r['VariantNo'] ?? null;
                if ($vno !== null) {
                    $byVno[(string) $vno] = $r['Images'] ?? [];
                }
            }

            foreach ($chunk as $row) {
                $imgs = $byVno[(string) $row->distributor_variant_no] ?? null;
                if (empty($imgs)) {
                    $noImg++;
                    continue;
                }
                PlatformDistributorCatalog::query()
                    ->where('id', $row->id)
                    ->update(['images' => json_encode(array_values($imgs))]);
                $updated++;
            }
        }

        $this->table(['metric', 'value'], [
            ['rows_considered', $rows->count()],
            ['updated', $updated],
            ['no_image_returned', $noImg],
            ['errored', $errors],
        ]);

        return self::SUCCESS;
    }
}
