<?php
// MARKER-PATCH-HLC3

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogTitleComposer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tier-1: platform identity sync. Pulls a distributor's paginated catalog and
 * upserts canonical rows (one per distributor+variant) into the SHARED
 * platform_distributor_catalogs, via the editable field map.
 *
 * Cost is intentionally nulled on the shared row — it's per-tenant (tier 2).
 * MAP/MSRP/identity/media/status are universal and refresh every run.
 */
class DistributorCatalogSyncService
{
    public function __construct(
        private readonly DistributorMapResolver $resolver,
        private readonly CatalogTitleComposer $composer,
    ) {}

    /**
     * @return array{code:string,pages:int,seen:int,written:int,skipped_delta:int,map_vanished:int,msrp_vanished:int,errors:array<string>}
     */
    public function syncIdentity(
        DistributorAdapter $adapter,
        ?Carbon $since = null,
        int $pageSize = 8000,
        int $maxPages = 2000
    ): array {
        $code = strtoupper($adapter->code());

        // Empty-map guard: refuse rather than silently write broken rows.
        if (empty($this->resolver->mapsFor($code))) {
            throw new \RuntimeException("No field map for {$code}. Seed DistributorFieldMapSeeder before syncing.");
        }

        $res = [
            'code' => $code, 'pages' => 0, 'seen' => 0, 'written' => 0,
            'skipped_delta' => 0, 'map_vanished' => 0, 'msrp_vanished' => 0, 'errors' => [],
        ];

        // A query is logged per variant otherwise; over ~48k rows that
        // alone OOM-kills the worker. We don't need the log here.
        DB::connection()->disableQueryLog();

        $this->markProgress($code, 0, true);

        // HLC's Catalog/Products ignores pageStartIndex on the public API (every
        // offset returns the first page), but it honours pageSize: a pageSize at
        // or above the catalog total returns the whole catalog in one response.
        // So pull once and process in chunks, checkpointing every 200 rows so the
        // live counter still climbs. ($maxPages is unused — offset paging is dead.)
        try {
            $batch = $adapter->products(['pageStartIndex' => 1, 'pageSize' => $pageSize]);
        } catch (\Throwable $e) {
            $res['errors'][] = 'catalog fetch: ' . $e->getMessage();
            $this->recordState($code, $res);
            return $res;
        }

        $products = $this->extractProducts($batch);
        unset($batch);
        $res['pages'] = 1;

        if (count($products) >= $pageSize) {
            // Returned exactly the cap — the catalog may be larger than one pull.
            $res['errors'][] = "catalog returned the full pageSize ({$pageSize}); it may be truncated — raise HLC_API_PAGE_SIZE.";
        }

        // Group by brand so we can write brand-by-brand and report per-brand
        // progress (HLC has no server-side brand filter, so we slice here).
        $byBrand = [];
        foreach ($products as $product) {
            $bn = $product['Brand'] ?? 'Unknown';
            $byBrand[$bn][] = $product;
        }
        unset($products);
        ksort($byBrand);
        $this->seedBrandStatuses($code, $byBrand);

        foreach ($byBrand as $brandName => $brandProducts) {
            $this->setBrandStatus($code, $brandName, 'syncing', null);
            $brandWritten = 0;

            foreach ($brandProducts as $product) {
                foreach (($product['Variants'] ?? []) as $variant) {
                    $res['seen']++;
                    if ($since !== null && $this->isUnchanged($variant, $product, $since)) {
                        $res['skipped_delta']++;
                        continue;
                    }
                    try {
                        $this->upsertVariant($code, $adapter->name(), $variant, $product, $res);
                        $res['written']++;
                        $brandWritten++;
                    } catch (\Throwable $e) {
                        $res['errors'][] = ($variant['VariantNo'] ?? '?') . ': ' . $e->getMessage();
                    }
                    if ($brandWritten % 100 === 0) {
                        $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten);
                        $this->markProgress($code, $res['written']);
                    }
                }
            }

            $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
            $this->markProgress($code, $res['written']);
            unset($brandProducts);
            gc_collect_cycles();
        }

        $this->recordState($code, $res);
        return $res;
    }

    private function extractProducts(mixed $batch): array
    {
        if (! is_array($batch)) {
            return [];
        }
        if (isset($batch['Products']) && is_array($batch['Products'])) {
            return $batch['Products'];
        }
        if (isset($batch['Items']) && is_array($batch['Items'])) {
            return $batch['Items'];
        }
        return array_is_list($batch) ? $batch : [];
    }

    private function isUnchanged(array $variant, array $product, Carbon $since): bool
    {
        $ts = $variant['DateLastModified'] ?? $product['DateLastModified'] ?? null;
        if (! $ts) {
            return false; // unknown modified date -> always sync
        }
        try {
            return Carbon::parse($ts)->lessThanOrEqualTo($since);
        } catch (\Throwable) {
            return false;
        }
    }

    private function upsertVariant(string $code, string $name, array $variant, array $product, array &$res): void
    {
        $canonical = $this->resolver->resolve($code, $variant, $product);
        $vno = $canonical['distributor_variant_no'] ?? ($variant['VariantNo'] ?? null);
        if (! $vno) {
            throw new \RuntimeException('variant has no identity');
        }

        $existing = PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)
            ->where('distributor_variant_no', $vno)
            ->first();

        $prevMap  = $existing?->map_cents;
        $prevMsrp = $existing?->msrp_cents;
        $newMap   = $canonical['map_cents'] ?? null;
        $newMsrp  = $canonical['msrp_cents'] ?? null;

        if ($prevMap !== null && $newMap === null) {
            $res['map_vanished']++;
        }
        if ($prevMsrp !== null && $newMsrp === null) {
            $res['msrp_vanished']++;
        }

        // Shared catalog never holds tenant cost (cost is per-tenant — tier 2).
        $canonical['cost_cents']      = null;
        $canonical['prev_map_cents']  = $prevMap;
        $canonical['prev_msrp_cents'] = $prevMsrp;
        $canonical['distributor_name'] = $name;
        $canonical['source_raw']      = $variant;
        $canonical['last_synced_at']  = now();
        $canonical['is_active']       = true;

        $composed = $this->composer->compose($code, [
            'brand'         => $canonical['manufacturer'] ?? null,
            'model'         => $canonical['name'] ?? null,
            'mpn'           => $canonical['manufacturer_sku'] ?? null,
            'description'   => $canonical['description'] ?? ($variant['Description'] ?? ''),
            'attributes'    => $variant['Attributes'] ?? ($canonical['attributes'] ?? []),
            'category'      => $canonical['category'] ?? null,
            'category_path' => $canonical['category_path'] ?? null,
            'unit'          => $canonical['uom'] ?? null,
        ]);
        $canonical['display_name']     = $composed['title'] !== '' ? $composed['title'] : ($canonical['name'] ?? null);
        $canonical['display_subtitle'] = $composed['subtitle'] !== '' ? $composed['subtitle'] : null;
        $canonical['search_text']      = ($composed['search'] ?? '') !== '' ? $composed['search'] : null;

        PlatformDistributorCatalog::query()->updateOrCreate(
            ['distributor_code' => $code, 'distributor_variant_no' => $vno],
            $canonical
        );
    }

    /** Reset and seed one status row per brand for this run. */
    private function seedBrandStatuses(string $code, array $byBrand): void
    {
        DB::table('distributor_brand_sync_status')->where('distributor_code', $code)->delete();
        $rows = [];
        foreach ($byBrand as $bn => $ps) {
            $total = 0;
            foreach ($ps as $p) {
                $total += count($p['Variants'] ?? []);
            }
            $rows[] = [
                'distributor_code' => $code, 'brand_name' => (string) $bn,
                'total' => $total, 'written' => 0, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('distributor_brand_sync_status')->insert($chunk);
        }
    }

    private function setBrandStatus(string $code, string $brandName, string $status, ?int $written): void
    {
        $vals = ['status' => $status, 'updated_at' => now()];
        if ($written !== null) {
            $vals['written'] = $written;
        }
        DB::table('distributor_brand_sync_status')
            ->where('distributor_code', $code)->where('brand_name', $brandName)
            ->update($vals);
    }

    private function recordState(string $code, array $res): void
    {
        DB::table('distributor_sync_state')->updateOrInsert(
            ['distributor_code' => $code, 'source_ref' => 'catalog'],
            [
                'last_synced_at' => now(),
                'last_run_at'    => now(),
                'last_status'    => empty($res['errors']) ? 'ok' : 'partial',
                'last_count'     => $res['written'],
                'last_error'     => $res['errors'] ? json_encode(array_slice($res['errors'], 0, 5)) : null,
                'updated_at'     => now(),
                'created_at'     => now(),
            ]
        );
    }

    /**
     * Write a running progress checkpoint. Updates the live count + activity
     * time without touching last_synced_at (the delta watermark, which only
     * advances on successful completion in recordState).
     */
    private function markProgress(string $code, int $written, bool $start = false): void
    {
        $vals = [
            'last_status' => 'running',
            'last_count'  => $written,
            'last_run_at' => now(),     // also doubles as "last activity"
            'updated_at'  => now(),
            'created_at'  => now(),
        ];
        if ($start) {
            $vals['last_error'] = null;
        }

        DB::table('distributor_sync_state')->updateOrInsert(
            ['distributor_code' => $code, 'source_ref' => 'catalog'],
            $vals
        );
    }
}
