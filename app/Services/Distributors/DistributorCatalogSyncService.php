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

        // MARKER-QBP-SYNC — adapters with big catalogs page by brand instead
        // of one giant fetch. The adapter declares it; nothing here names a
        // distributor.
        if (method_exists($adapter, 'pagesByBrand') && $adapter->pagesByBrand()) {
            return $this->syncIdentityByBrand($adapter, $since, $res);
        }

        // HLC's Catalog/Products ignores pageStartIndex on the public API (every
        // offset returns the first page), but it honours pageSize: a pageSize at
        // or above the catalog total returns the whole catalog in one response.
        // So pull once and process in chunks, checkpointing every 200 rows so the
        // live counter still climbs. ($maxPages is unused — offset paging is dead.)
        try {
            // MARKER-SYNC-PAGE-SIZE — per-distributor override, falling back to
            // the caller's value. HLC keeps 8000 (its API is asked for this
            // number, so changing it is a live third-party behaviour change);
            // BTI sets its own, because it reads a local file and 8000 leaves
            // barely 250 products of headroom.
            $pageSize = (int) config(
                'distributors.' . strtolower($code) . '.sync_page_size',
                $pageSize
            );

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
            // MARKER-SYNC-PAGE-SIZE — name the distributor and the env var that
            // actually applies. The old text said HLC_API_PAGE_SIZE whoever
            // tripped it, which sends the next reader to the wrong knob.
            $envVar = strtoupper($code) . '_SYNC_PAGE_SIZE';
            $res['errors'][] = "{$code} returned the full pageSize ({$pageSize}); the catalog is probably larger than one pull — raise {$envVar}.";
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

    /**
     * MARKER-QBP-SYNC — fetch, write, release, one small page of brands at a
     * time. Memory stays flat at a few brands' worth no matter how large the
     * catalog is; progress and per-brand status behave exactly like the
     * single-fetch path.
     *
     * Chunk size 10: DRW measured 7 MB / ~1,000 products, so a page tops out
     * around 70 MB of XML before parsing — well inside a worker.
     */
    // MARKER-BRAND-SYNC — refresh one brand. pagesByBrand adapters (QBP)
    // fetch only that brand; others pull the full feed and keep just this one.
    public function syncBrand(DistributorAdapter $adapter, string $brandName): array
    {
        $code = strtoupper($adapter->code());

        if (empty($this->resolver->mapsFor($code))) {
            throw new \RuntimeException("No field map for {$code}. Seed DistributorFieldMapSeeder before syncing.");
        }

        $res = [
            'code' => $code, 'pages' => 0, 'seen' => 0, 'written' => 0,
            'skipped_delta' => 0, 'map_vanished' => 0, 'msrp_vanished' => 0, 'errors' => [],
        ];

        DB::connection()->disableQueryLog();
        $this->setBrandStatus($code, $brandName, 'syncing', null);

        try {
            if (method_exists($adapter, 'pagesByBrand') && $adapter->pagesByBrand()) {
                $id = null;
                foreach ($adapter->brands() as $b) {
                    if (strcasecmp((string) ($b['name'] ?? ''), $brandName) === 0) { $id = $b['id'] ?? null; break; }
                }
                if ($id === null) {
                    $res['errors'][] = "brand '{$brandName}' not found in {$code} brand list";
                    $this->setBrandStatus($code, $brandName, 'done', 0);
                    return $res;
                }
                $products = $this->extractProducts($adapter->products(['brands' => [$id]]));
            } else {
                $all = $this->extractProducts($adapter->products());
                $products = array_values(array_filter($all, function ($prod) use ($brandName) {
                    return strcasecmp((string) ($prod['Brand'] ?? ''), $brandName) === 0;
                }));
                unset($all);
            }

            $res['pages'] = 1;
            $written = 0;
            foreach ($products as $product) {
                foreach (($product['Variants'] ?? []) as $variant) {
                    $res['seen']++;
                    try {
                        $this->upsertVariant($code, $adapter->name(), $variant, $product, $res);
                        $res['written']++;
                        $written++;
                    } catch (\Throwable $e) {
                        $res['errors'][] = ($variant['sku'] ?? $variant['VariantNo'] ?? '?') . ': ' . $e->getMessage();
                    }
                    if ($written % 100 === 0) {
                        $this->setBrandStatus($code, $brandName, 'syncing', $written);
                    }
                }
            }
            unset($products);

            $this->setBrandStatus($code, $brandName, 'done', $written);
        } catch (\Throwable $e) {
            $res['errors'][] = $e->getMessage();
            $this->setBrandStatus($code, $brandName, 'done', $res['written']);
        }

        return $res;
    }

    private function syncIdentityByBrand(DistributorAdapter $adapter, ?Carbon $since, array $res): array
    {
        $code = $res['code'];

        try {
            $brandList = $adapter->brands();
        } catch (\Throwable $e) {
            $res['errors'][] = 'brand list: ' . $e->getMessage();
            $this->recordState($code, $res);
            return $res;
        }

        // Seed every brand as pending up front so the progress panel shows
        // the full run's shape immediately, matching the existing path.
        $pendingNames = [];
        foreach ($brandList as $b) {
            $pendingNames[$b['name']] = [];
        }
        ksort($pendingNames);
        $this->seedBrandStatuses($code, $pendingNames);
        unset($pendingNames);

        foreach (array_chunk($brandList, 10) as $chunk) {
            $ids = array_column($chunk, 'id');

            try {
                $batch = $adapter->products(['brands' => $ids]);
            } catch (\Throwable $e) {
                $res['errors'][] = 'brands ' . implode(',', $ids) . ': ' . $e->getMessage();
                continue;
            }

            $products = $this->extractProducts($batch);
            unset($batch);
            $res['pages']++;

            $byBrand = [];
            foreach ($products as $product) {
                $byBrand[$product['Brand'] ?? 'Unknown'][] = $product;
            }
            unset($products);

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
                            $res['errors'][] = ($variant['sku'] ?? $variant['VariantNo'] ?? '?') . ': ' . $e->getMessage();
                        }
                        if ($brandWritten % 100 === 0) {
                            $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten);
                            $this->markProgress($code, $res['written']);
                        }
                    }
                }

                $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
                $this->markProgress($code, $res['written']);
            }

            unset($byBrand);
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

        // MARKER-DELTA-REAL — QBP states its timestamp as milliseconds since
        // epoch under modifiedTime.iMillis. Without this the key was never
        // found, isUnchanged always returned false, and --delta wrote every
        // row on every run for every distributor.
        if ($ts === null) {
            $ms = $variant['modifiedTime']['iMillis']
                ?? $product['modifiedTime']['iMillis']
                ?? null;
            if ($ms !== null && is_numeric($ms)) {
                try {
                    return Carbon::createFromTimestampMs((int) $ms)->lessThanOrEqualTo($since);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

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
            // MARKER-TITLE-TOKENS — mirrors CatalogTitleComposer::partsFromRow().
            // If these two drift, the editor preview and the real title differ.
            'item_group'    => $canonical['item_group'] ?? null,
            'size_id'       => $canonical['size_id'] ?? null,
            'color_id'      => $canonical['color_id'] ?? null,
            'case_quantity' => $canonical['case_quantity'] ?? null,
            'weight'        => $canonical['weight'] ?? null,
            'dimensions'    => is_array($canonical['dimensions'] ?? null)
                ? implode(' x ', $canonical['dimensions'])
                : ($canonical['dimensions'] ?? null),
            'upc'           => $canonical['upc'] ?? null,
            'ean'           => $canonical['ean'] ?? null,
            'variant_no'    => $canonical['distributor_variant_no'] ?? null,
            'product_no'    => $canonical['distributor_product_no'] ?? null,
        ]);
        $canonical['display_name']     = $composed['title'] !== '' ? $composed['title'] : ($canonical['name'] ?? null);
        $canonical['display_subtitle'] = $composed['subtitle'] !== '' ? $composed['subtitle'] : null;
        $canonical['search_text']      = ($composed['search'] ?? '') !== '' ? $composed['search'] : null;

        // MARKER-PATCH-372 — capture distributor product images. Public CDN URLs
        // ({Format,Url,Hash}) already embedded per-variant in the Products payload.
        //
        // MARKER-IMAGES-OVERWRITE — but ONLY when the field map produced
        // nothing. This line used to run unconditionally and wrote
        // $variant['Images'] over whatever the map had resolved. 'Images' is
        // HLC's key: QBP emits ImageFiles and BTI emits image_paths, so for
        // both of those it missed and overwrote good data with an empty array.
        //
        // Verified on QBP RM9022 — the map resolved two file names and this
        // line discarded them, which is why every QBP product showed no image
        // while the mapping page reported images <- ImageFiles, correctly.
        $mappedImages = $canonical['images'] ?? null;
        if ($mappedImages === null || $mappedImages === [] || $mappedImages === '') {
            $canonical['images'] = $variant['Images'] ?? [];
        }

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
