#!/bin/bash
# bti-adapter — BTI catalog integration.
#
#   BTI is a bulk download, not a query API: HTTP Basic against
#   https://www.bti-usa.com/inventory, ?full=true for the catalog and no flag
#   for stock+prices. Both are offered as CSV or JSON.
#
#   CSV, deliberately. The full JSON is 43 MB, which json_decode expands to
#   roughly 400-500 MB of PHP arrays — over the usual memory_limit, and it
#   grows with BTI's catalog. fgetcsv streams at constant memory and the CSV
#   header is identical to the JSON keys (verified, all 43 columns, same
#   names and order), so one field map serves either format.
#
#   The 43 MB download is cached to storage for six hours, because
#   products() is called once per page and re-fetching per page would pull
#   43 MB every time. Cache is per (endpoint, day-hour bucket); stale files
#   are cleaned on write.
#
#   Credentials ride in the existing api_key slot as "username:password".
#   That keeps DistributorRegistry::make() and forSubscription() unchanged —
#   they hand every adapter (apiKey, region) — rather than widening the
#   contract for one distributor.
#
#   Field maps included, from the real feed record. Notable: attributes zip
#   the two parallel pipe strings; category_path concatenates the two
#   category levels via a single-spec coalesce; map uses cents_zero_null
#   because BTI writes 0.0 to mean NO MAP; vendor_item_id is trimmed.
# NO MIGRATION (seeder only). After deploy:
#   php artisan db:seed --class=BtiFieldMapSeeder --force
#   php artisan distributors:test BTI   (if that command exists) or sync
set -e
if [ -f app/Services/Distributors/BtiClient.php ]; then
  echo "bti-adapter already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-BTI-TRANSFORMS" app/Services/Distributors/DistributorMapResolver.php; then
  echo "distributor-map-transforms-bti must be applied first — aborting."; exit 1
fi

# ------------------------------------------------------------------ config
python3 - <<'BTI_0_EOF'
import io
p = 'config/distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """        'page_size'      => (int) env('HLC_API_PAGE_SIZE', 8000),
    ],

];"""
assert s.count(old) == 1, s.count(old)
new = """        'page_size'      => (int) env('HLC_API_PAGE_SIZE', 8000),
    ],

    // MARKER-BTI-ADAPTER \u2014 bulk download over HTTP Basic, not a query API.
    // Tenant credentials still live encrypted on the subscription row; only
    // transport settings belong here.
    'bti' => [
        'name'           => 'BTI',
        'base_url'       => env('BTI_BASE', 'https://www.bti-usa.com'),
        // Where relative image_path values hang off.
        'image_base'     => env('BTI_IMAGE_BASE', 'https://www.bti-usa.com/images'),
        'timeout'        => (int) env('BTI_TIMEOUT', 600),   // 43 MB download
        'retries'        => (int) env('BTI_RETRIES', 2),
        'retry_sleep_ms' => (int) env('BTI_RETRY_SLEEP', 1000),
        'page_size'      => (int) env('BTI_PAGE_SIZE', 2000),
        // How long a downloaded feed is reused before re-fetching.
        'cache_hours'    => (int) env('BTI_CACHE_HOURS', 6),
    ],

];"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('config ok')
BTI_0_EOF

# ------------------------------------------------------------------ client
cat > 'app/Services/Distributors/BtiClient.php' <<'BTI_1_EOF'
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
BTI_1_EOF

# ------------------------------------------------------------------ registry
python3 - <<'BTI_2_EOF'
import io
p = 'app/Services/Distributors/DistributorRegistry.php'
s = io.open(p, encoding='utf-8').read()

old = """    private const ADAPTERS = [
        'HLC' => HlcClient::class,
    ];"""
assert s.count(old) == 1, s.count(old)
new = """    private const ADAPTERS = [
        'HLC' => HlcClient::class,
        // MARKER-BTI-ADAPTER \u2014 credentials ride in api_key as
        // "username:password"; BtiClient splits on the first colon, so the
        // shared (apiKey, region) constructor shape still holds.
        'BTI' => BtiClient::class,
    ];"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('registry ok')
BTI_2_EOF

# ------------------------------------------------------------------ seeder
cat > 'database/seeders/BtiFieldMapSeeder.php' <<'BTI_3_EOF'
<?php

// MARKER-BTI-ADAPTER

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BTI field map. Column names are identical in the CSV and JSON feeds, so
 * these paths serve both.
 *
 * updateOrInsert on (distributor_code, canonical_field) — safe to re-run and
 * it won't clobber master-admin edits to fields it doesn't list.
 */
class BtiFieldMapSeeder extends Seeder
{
    public function run(): void
    {
        $code = 'BTI';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            // identity
            ['distributor_variant_no', 'id', 'direct', null, null, 'BTI item number — the unique key'],
            ['distributor_product_no', 'group_id', 'direct', null, null, 'groups variants'],
            ['upc', 'upc', 'direct', null, null, null],
            ['ean', 'ean', 'direct', null, null, null],
            ['manufacturer_sku', 'vendor_item_id', 'direct', ['cast' => 'trim'], null, 'ships with a leading space'],

            // descriptive
            ['name', 'item_description', 'direct', null, null, null],
            ['description', 'group_text', 'direct', null, null, 'real marketing copy, unlike HLC'],
            ['manufacturer', 'manufacturer_name', 'direct', null, null, null],
            ['brand_id', 'manufacturer_id', 'direct', ['cast' => 'string'], null, null],

            // classification — two levels, flattened to a path
            ['category', 'sub_category_name', 'direct', null, null, 'leaf'],
            ['category_id', 'sub_category_id', 'direct', ['cast' => 'string'], null, null],
            ['category_path', null, 'coalesce', ['order' => [
                ['concat' => ['category_name', 'sub_category_name'], 'sep' => ' > '],
            ]], null, 'single-spec coalesce == always concat'],

            // attributes — two parallel pipe strings zipped into {Name,Value}
            ['attributes', null, 'zip_pipe', [
                'keys'   => 'attribute_keys',
                'values' => 'attribute_values',
                'sep'    => '|',
            ], null, 'Model|Color|Size + Snapback Hat|Gray|One Size'],

            // money
            ['cost_cents', 'your_price', 'direct', ['cast' => 'cents'], null, 'dealer cost'],
            ['msrp_cents', 'msrp', 'direct', ['cast' => 'cents'], null, null],
            ['map_cents', 'map', 'direct', ['cast' => 'cents_zero_null'], null, '0.0 means NO MAP'],

            // media
            ['image_urls', 'image_paths', 'split_pipe', [
                'sep'    => '|',
                'prefix' => 'https://www.bti-usa.com/images',
            ], null, 'relative paths need a host'],
        ];

        $now = now();

        foreach ($rows as [$field, $path, $transform, $args, $lookup, $notes]) {
            $payload = [
                'source_path'    => $path,
                'transform'      => $transform,
                'transform_args' => $args ? json_encode($args) : null,
                'lookup_table'   => $lookup ? json_encode($lookup) : null,
                'notes'          => $notes,
                'is_active'      => true,
                'updated_at'     => $now,
            ];

            $existing = DB::table('distributor_field_maps')
                ->where('distributor_code', $code)
                ->where('canonical_field', $field)
                ->first();

            if ($existing) {
                DB::table('distributor_field_maps')->where('id', $existing->id)->update($payload);
            } else {
                $payload['id'] = (string) Str::uuid();
                $payload['distributor_code'] = $code;
                $payload['canonical_field'] = $field;
                $payload['created_at'] = $now;
                DB::table('distributor_field_maps')->insert($payload);
            }
        }

        $this->command?->info('BtiFieldMapSeeder: seeded ' . count($rows) . ' BTI field maps.');
    }
}
BTI_3_EOF

php -l app/Services/Distributors/BtiClient.php
php -l app/Services/Distributors/DistributorRegistry.php
php -l database/seeders/BtiFieldMapSeeder.php
php -l config/distributors.php

echo
echo "bti-adapter applied."
