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
            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(30)
                ->withHeaders(['Range' => 'bytes=0-2047'])
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

        $fresh = is_file($path)
            && (time() - filemtime($path)) < ($this->cacheHours * 3600)
            && filesize($path) > 1024;

        if ($fresh) {
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
                    fn ($v) => $v === null ? '' : trim((string) $v),
                    $line
                ));
            }
        } finally {
            fclose($fh);
        }
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

        return $out;
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
            $out[] = [
                'sku'        => $id,
                'available'  => (int) ($r['available'] ?? 0),
                'warehouses' => [
                    ['code' => 'santa_fe', 'available' => (int) ($r['available_santa_fe'] ?? 0)],
                    ['code' => 'reno',     'available' => (int) ($r['available_reno'] ?? 0)],
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
            $out[] = [
                'sku'         => $id,
                'cost_cents'  => (int) round(((float) ($r['your_price'] ?? 0)) * 100),
                'msrp_cents'  => (int) round(((float) ($r['msrp'] ?? 0)) * 100),
                // 0.0 means NO MAP, not a zero-dollar floor.
                'map_cents'   => $map == 0.0 ? null : (int) round($map * 100),
                'on_sale'     => (bool) ((int) ($r['is_on_sale'] ?? 0)),
                'on_closeout' => (bool) ((int) ($r['is_on_closeout'] ?? 0)),
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
                'sku'    => $id,
                'images' => array_values(array_map(
                    fn ($p) => str_starts_with($p, 'http') ? $p : $base . '/' . ltrim($p, '/'),
                    $paths
                )),
            ];
        }
        return $out;
    }
}
