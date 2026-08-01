<?php

// MARKER-BTI-ADAPTER

namespace App\Services\Distributors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BTI (Bicycle Technologies International) adapter.
 *
 *   Auth : HTTP Basic
 *   Full : {base}/inventory?full=true        (catalog, ~43 MB CSV)
 *   Light: {base}/inventory                  (stock + prices only)
 *
 * Bulk downloads rather than a query API, so this adapter's job is fetch,
 * cache, and stream. CSV is used over JSON on purpose: the JSON is 43 MB,
 * which json_decode turns into roughly half a gigabyte of PHP arrays, while
 * fgetcsv holds one row at a time. The CSV header is identical to the JSON
 * keys, so the field map is the same either way.
 *
 * Credentials arrive in the api_key slot as "username:password" so the
 * registry's (apiKey, region) shape covers this distributor too.
 */
class BtiClient implements DistributorAdapter
{
    private string $base;
    private string $user = '';
    private string $pass = '';
    private int $timeout;
    private int $retries;
    private int $retrySleepMs;
    private int $pageSize;
    private int $cacheHours;

    /**
     * MARKER-CACHE-PER-RUN — feeds already downloaded by THIS adapter.
     *
     * The cache used to be time-based (six hours), which meant a manual sync
     * could import a file from hours earlier and, during an outage, succeed
     * without ever reaching the distributor. An adapter is built per run, so
     * scoping to the instance gives one download per run and no repeats
     * inside it.
     *
     * @var array<string,bool>
     */
    private array $fetchedThisRun = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $region = 'us',
        ?array $config = null,
    ) {
        $cfg = $config ?? config('distributors.bti', []);

        $this->base         = rtrim((string) ($cfg['base_url'] ?? 'https://www.bti-usa.com'), '/');
        $this->timeout      = (int) ($cfg['timeout'] ?? 600);
        $this->retries      = (int) ($cfg['retries'] ?? 2);
        $this->retrySleepMs = (int) ($cfg['retry_sleep_ms'] ?? 1000);
        $this->pageSize     = (int) ($cfg['page_size'] ?? 2000);
        $this->cacheHours   = (int) ($cfg['cache_hours'] ?? 6);

        // "username:password" — split on the FIRST colon only, since the
        // password is a GUID and could in principle contain one.
        if (str_contains($apiKey, ':')) {
            [$this->user, $this->pass] = explode(':', $apiKey, 2);
        } else {
            $this->user = $apiKey;
        }
    }

    public function code(): string { return 'BTI'; }
    public function name(): string { return 'BTI'; }

    // ---------------------------------------------------------------- probe

    /**
     * Asks for the light feed and reads only enough to know auth worked.
     * Deliberately not the full feed — a connectivity check shouldn't pull
     * 43 MB.
     */
    public function testConnection(): array
    {
        try {
            // MARKER-BTI-PROBE-TRUTH — measured, not inferred (Aug 1, from the
            // production server):
            //
            //     range      200, 3,848,122 bytes, ~22s
            //     no range   200, 3,848,122 bytes, ~22s
            //     HEAD       200, ~19.5s
            //     ttfb       24.8s of a 28.4s total
            //
            // The previous note here said BTI answers Range with a 503. It
            // does not — it IGNORES Range and sends the whole body. That 503
            // was almost certainly an outage misread as a Range rejection.
            //
            // What the ttfb line means: BTI renders the entire feed before
            // sending a byte, so NOTHING client-side makes this fast. Range,
            // HEAD and streaming all still wait ~25s for generation. An
            // authenticated request to a nonexistent path returns in 0.49s
            // but 404s for bad credentials too, so it proves nothing.
            //
            // There is no cheap authenticated endpoint. Don't go looking
            // again — set expectations in the UI instead. The light feed
            // (stock and prices) is what's used here; the full catalog is
            // an order of magnitude bigger.
            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(60)
                ->get($this->base . '/inventory', ['type' => 'json']);

            $body = substr((string) $res->body(), 0, 400);

            // BTI answers a bad login with an HTML page and a 200 in some
            // paths, so status alone is not proof.
            $looksLikeData = str_starts_with(ltrim($body), '[')
                || str_starts_with(ltrim($body), '{')
                || str_contains($body, 'vendor_item_id');

            return [
                'ok'     => $res->successful() && $looksLikeData,
                'status' => $res->status(),
                // 401/403 means the credentials are wrong. Anything else
                // means BTI didn't serve us — a different problem with a
                // different fix.
                'auth'   => in_array($res->status(), [401, 403], true),
                'body'   => $looksLikeData ? 'feed reachable' : $body,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------- feed

    /**
     * Path to a cached copy of a feed, downloading it when absent or stale.
     * Six hours by default: products() is called once per page and each call
     * would otherwise re-pull the whole file.
     */
    private function feedFile(bool $full): string
    {
        $dir = storage_path('app/distributor-cache');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = 'bti-' . ($full ? 'full' : 'light') . '.csv';
        $path = $dir . '/' . $name;

        // MARKER-CACHE-PER-RUN — reuse only within this run.
        //
        // Was: any file younger than cacheHours. That let a sync import a
        // stale copy, and let one "succeed" while the distributor was down.
        // The file still lands on disk so fgetcsv can stream it without
        // holding 43 MB in memory; it just isn't reused across runs.
        $key = $full ? 'full' : 'light';

        if (! empty($this->fetchedThisRun[$key])
            && is_file($path) && filesize($path) > 1024) {
            return $path;
        }

        $query = $full ? ['full' => 'true'] : [];
        $tmp   = $path . '.part';

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                // sink() streams to disk — the response never lands in memory.
                $res = Http::withBasicAuth($this->user, $this->pass)
                    ->timeout($this->timeout)
                    ->sink($tmp)
                    ->get($this->base . '/inventory', $query);

                if (! $res->successful()) {
                    throw new \RuntimeException('BTI feed HTTP ' . $res->status());
                }
                if (! is_file($tmp) || filesize($tmp) < 1024) {
                    throw new \RuntimeException('BTI feed came back empty');
                }

                // Rename only after a complete download, so a failed attempt
                // can never be served as a valid cache.
                rename($tmp, $path);
                $this->fetchedThisRun[$key] = true;   // MARKER-CACHE-PER-RUN
                return $path;
            } catch (\Throwable $e) {
                @unlink($tmp);
                if ($attempt > $this->retries) {
                    Log::error('bti.feed_download_failed', ['error' => $e->getMessage()]);
                    throw $e;
                }
                usleep($this->retrySleepMs * 1000);
            }
        }
    }

    /**
     * Streams a feed as associative rows. Constant memory: one row is held
     * at a time regardless of file size.
     *
     * @return \Generator<int,array<string,string>>
     */
    private function rows(bool $full): \Generator
    {
        $path = $this->feedFile($full);
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return;
        }

        try {
            $header = fgetcsv($fh);
            if (! is_array($header)) {
                return;
            }
            $header = array_map(fn ($h) => trim((string) $h), $header);

            while (($line = fgetcsv($fh)) !== false) {
                if ($line === [null] || $line === false) {
                    continue;                      // blank line
                }
                if (count($line) !== count($header)) {
                    // Ragged row — skip rather than mis-key every field.
                    continue;
                }
                yield array_combine($header, array_map(
                    fn ($v) => $v === null ? '' : $this->utf8(trim((string) $v)),
                    $line
                ));
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * MARKER-BTI-ENCODING — BTI serves Windows-1252, not UTF-8.
     *
     * Found via a single row whose vendor_item_id began with a bare 0xA0
     * (a Windows-1252 non-breaking space; the UTF-8 form is 0xC2 0xA0).
     * MySQL rejected the insert outright. That one row is not the extent of
     * it — every curly quote, en dash, degree sign and accented brand name
     * in the feed carries the same defect, so the conversion happens here,
     * on every value, rather than at the field that happened to fail first.
     *
     * Conditional on purpose: anything already valid UTF-8 is returned
     * untouched, so if BTI switches encoding this needs no change and
     * double-encoding can't happen.
     */
    private function utf8(string $v): string
    {
        if ($v === '' || mb_check_encoding($v, 'UTF-8')) {
            return $v;
        }
        return mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }

    // ---------------------------------------------------------------- api

    /**
     * BTI has no brand endpoint; brands are derived from the catalog. Cheap
     * because it streams — no more memory than the distinct list itself.
     */
    public function brands(): array
    {
        $out = [];
        foreach ($this->rows(true) as $r) {
            $id = $r['manufacturer_id'] ?? '';
            if ($id !== '' && ! isset($out[$id])) {
                $out[$id] = ['id' => $id, 'name' => $r['manufacturer_name'] ?? $id];
            }
        }
        return array_values($out);
    }

    /** Two levels, flattened to the same "A > B" path the catalog stores. */
    public function categories(): array
    {
        $out = [];
        foreach ($this->rows(true) as $r) {
            $cid = $r['category_id'] ?? '';
            $sid = $r['sub_category_id'] ?? '';
            $key = $cid . '/' . $sid;
            if ($cid === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'id'        => $sid !== '' ? $sid : $cid,
                'name'      => $r['sub_category_name'] ?? '',
                'parent_id' => $cid,
                'path'      => trim(($r['category_name'] ?? '') . ' > ' . ($r['sub_category_name'] ?? ''), ' >'),
            ];
        }
        return array_values($out);
    }

    /**
     * @param array{pageStartIndex?: int, pageSize?: int, upcs?: array, vendorParts?: array} $opts
     */
    public function products(array $opts = []): array
    {
        $start = max(0, (int) ($opts['pageStartIndex'] ?? 0));
        $size  = (int) ($opts['pageSize'] ?? $this->pageSize);

        $upcs  = array_flip(array_map('strval', $opts['upcs'] ?? []));
        $parts = array_flip(array_map(
            fn ($p) => strtoupper(trim((string) $p)),
            $opts['vendorParts'] ?? []
        ));
        $filtered = $upcs || $parts;

        $out = [];
        $i = 0;

        foreach ($this->rows(true) as $r) {
            if ($filtered) {
                $hitUpc  = $upcs  && isset($upcs[$r['upc'] ?? '']);
                $hitPart = $parts && isset($parts[strtoupper(trim((string) ($r['vendor_item_id'] ?? '')))]);
                if (! $hitUpc && ! $hitPart) {
                    continue;
                }
            }

            if ($i++ < $start) {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $size) {
                break;
            }
        }

        return $this->groupIntoProducts($out);
    }

    /**
     * MARKER-BTI-PRODUCT-SHAPE
     *
     * The sync layer is written around HLC's nested shape: it groups on
     * $product['Brand'] and iterates $product['Variants']. BTI ships flat
     * item rows, so without this the sync sees rows and counts none of them.
     *
     * BTI already has the grouping: group_id is the product, id is the
     * variant. Rows keep every column, so the field map's flat paths still
     * resolve — resolve() merges variant and product into one context.
     *
     * A group straddling a page boundary produces two entries with the same
     * group_id. That is fine: upsertVariant keys on distributor_variant_no,
     * so each row is written exactly once regardless.
     *
     * @param  array<int,array<string,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function groupIntoProducts(array $rows): array
    {
        $byGroup = [];

        foreach ($rows as $r) {
            // Fall back to the item id so a row with no group still syncs
            // as a product of one, rather than being silently dropped.
            $gid = ($r['group_id'] ?? '') !== '' ? $r['group_id'] : ($r['id'] ?? '');
            if ($gid === '') {
                continue;
            }

            if (! isset($byGroup[$gid])) {
                $byGroup[$gid] = [
                    // What the sync groups on. Unknown keeps a nameless row
                    // out of a bucket it doesn't belong in.
                    'Brand'             => ($r['manufacturer_name'] ?? '') !== ''
                        ? $r['manufacturer_name']
                        : 'Unknown',
                    'group_id'          => $gid,
                    'group_description' => $r['group_description'] ?? '',
                    'group_text'        => $r['group_text'] ?? '',
                    'manufacturer_id'   => $r['manufacturer_id'] ?? '',
                    'manufacturer_name' => $r['manufacturer_name'] ?? '',
                    'category_id'       => $r['category_id'] ?? '',
                    'category_name'     => $r['category_name'] ?? '',
                    'sub_category_id'   => $r['sub_category_id'] ?? '',
                    'sub_category_name' => $r['sub_category_name'] ?? '',
                    'Variants'          => [],
                ];
            }

            $byGroup[$gid]['Variants'][] = $r;
        }

        return array_values($byGroup);
    }

    /**
     * Per-warehouse availability from the LIGHT feed — stock and prices only,
     * so this can run far more often than the catalog pull.
     */
    public function inventory(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $out = [];

        foreach ($this->rows(false) as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($want && ! isset($want[$id])) {
                continue;
            }
            // MARKER-BTI-SYNC-SHAPES — the platform's key names, not BTI's.
            // TenantDistributorSyncService::normalizeInventory looks for
            // VariantNo and TotalQtyAvailable; lowercase 'sku'/'available'
            // matched nothing and every row was skipped without an error.
            $out[] = [
                'VariantNo'          => $id,
                'TotalQtyAvailable'  => (int) ($r['available'] ?? 0),
                // Kept in the shape the service's fallback branch reads, so
                // the two warehouses still sum if the total is ever missing.
                'Warehouses' => [
                    ['Code' => 'santa_fe', 'QtyAvailable' => (int) ($r['available_santa_fe'] ?? 0)],
                    ['Code' => 'reno',     'QtyAvailable' => (int) ($r['available_reno'] ?? 0)],
                ],
            ];
        }
        return $out;
    }

    public function prices(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $out = [];

        foreach ($this->rows(false) as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($want && ! isset($want[$id])) {
                continue;
            }
            $map = (float) ($r['map'] ?? 0);

            // MARKER-BTI-SYNC-SHAPES — VariantNo plus a Prices[] array, which
            // is what fetchCosts() reads. HLC sends tiers there, so BTI's
            // single dealer price is emitted as one tier rather than as a
            // differently-named field the service would ignore.
            $out[] = [
                'VariantNo' => $id,
                'Prices'    => [[
                    'PriceTypeId' => 1,
                    'PriceType'   => 'Base',
                    'Price'       => (float) ($r['your_price'] ?? 0),
                ]],
                'MSRP' => (float) ($r['msrp'] ?? 0),
                // 0.0 means NO MAP, not a zero-dollar floor.
                'MAP'  => $map == 0.0 ? null : $map,
                'OnSale'     => (bool) ((int) ($r['is_on_sale'] ?? 0)),
                'OnCloseout' => (bool) ((int) ($r['is_on_closeout'] ?? 0)),
            ];
        }
        return $out;
    }

    public function images(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $base = rtrim((string) config('distributors.bti.image_base', $this->base), '/');
        $out = [];

        foreach ($this->rows(true) as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($want && ! isset($want[$id])) {
                continue;
            }
            $paths = array_filter(array_map('trim', explode('|', (string) ($r['image_paths'] ?? ''))));
            if (! $paths) {
                continue;
            }
            $out[] = [
                // MARKER-BTI-SYNC-SHAPES — consistent with the others.
                'VariantNo' => $id,
                'sku'       => $id,
                'images' => array_values(array_map(
                    fn ($p) => str_starts_with($p, 'http') ? $p : $base . '/' . ltrim($p, '/'),
                    $paths
                )),
            ];
        }
        return $out;
    }
}
