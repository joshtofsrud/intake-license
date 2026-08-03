#!/usr/bin/env bash
# apply-qbp-adapter-skeleton.sh
# MARKER-QBP-ADAPTER — QBP gets a box in master admin, and a probe.
#
# The key belongs where HLC's and BTI's are: master admin, stored on
# platform_distributor_connections. That page lists whatever the registry
# supports, and the registry maps a code to an adapter class — so QBP needs
# a class before it can have a box. This adds one.
#
# The adapter is deliberately HALF BUILT, and honest about which half:
#
#   testConnection()  REAL. Calls 1/brand with the key and reports what came
#                     back, so master admin's Test Connection means something
#                     the moment the key is saved.
#
#   everything else   THROWS. products(), inventory(), prices(), images(),
#                     brands(), categories() raise a clear exception rather
#                     than returning [].
#
# That choice matters. An adapter returning an empty array would let a tier-1
# sync run to completion, write nothing, and report success — which is exactly
# how BTI's cost problem hid for weeks. A thrown exception with a plain
# message cannot be mistaken for "QBP has no products".
#
# The probe then reads the stored credential — no env var, no key in a shell
# command or its history.
#
# NOTE: adding QBP to the registry makes a QBP box appear on the TENANT
# Connection & sync page too. Leave the master-admin connection inactive
# until the adapter is finished, or a shop will find a box that cannot sync.
set -e

cat <<'PHPEOF' > app/Services/Distributors/QbpClient.php
<?php

// MARKER-QBP-ADAPTER

namespace App\Services\Distributors;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * QBP Point-of-Sale API (API1).
 *
 * A skeleton. testConnection() works; the data methods do not exist yet and
 * say so loudly instead of returning empty arrays — an adapter that returns
 * nothing lets a sync "succeed" while writing no rows, which is a failure
 * that hides for weeks.
 *
 * Shape notes from QBP's developer guide, to be confirmed against a real
 * payload before any of it is relied on:
 *
 *   - Auth is a single key in an X-QBPAPI-KEY header.
 *   - A "model" groups related products (a size run, colour variants), which
 *     maps to our product/variant split: model -> distributor_product_no,
 *     sku -> distributor_variant_no.
 *   - Categories come back as a real tree, so category_path can be built
 *     properly rather than concatenated as BTI's is.
 *   - Bullet points exist at BOTH model and product level and must be
 *     combined to get the full set.
 *   - Images require a separate CLS subscription; product detail carries
 *     file names only.
 *   - Dealer cost is NOT documented as present on product detail. CLS
 *     explicitly excludes "Your Price". Confirm with the probe before
 *     designing anything that depends on cost arriving here.
 */
class QbpClient implements DistributorAdapter
{
    private string $apiKey;
    private string $base;

    public function __construct(string $apiKey, string $region = 'us')
    {
        $this->apiKey = trim($apiKey);
        $this->base = rtrim((string) config('distributors.qbp.base_url', 'https://api1.qbp.com/api/'), '/') . '/';
    }

    public function code(): string
    {
        return 'QBP';
    }

    public function name(): string
    {
        return 'QBP';
    }

    /**
     * Real. 1/brand is the smallest call that requires a valid key, so a
     * success here proves the credential rather than merely reaching a host.
     */
    public function testConnection(): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'message' => 'No API key saved for QBP.'];
        }

        try {
            $res = $this->get('1/brand');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach QBP: ' . $e->getMessage()];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'message' => 'QBP rejected the key (HTTP ' . $res->status() . ').'];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'message' => 'QBP returned HTTP ' . $res->status() . '.'];
        }

        $json = $res->json();
        $count = is_array($json) ? count($this->listish($json)) : 0;

        return [
            'ok'      => true,
            'message' => 'Connected. QBP returned ' . $count . ' brands.',
        ];
    }

    // ---------------------------------------------------------------- todo

    public function brands(): array      { throw $this->pending('brands'); }
    public function categories(): array  { throw $this->pending('categories'); }
    public function products(array $opts = []): array { throw $this->pending('products'); }
    public function inventory(array $skus): array     { throw $this->pending('inventory'); }
    public function prices(array $skus): array        { throw $this->pending('prices'); }
    public function images(array $skus): array        { throw $this->pending('images'); }

    /**
     * Loud on purpose. Returning [] here would let a catalog sync finish,
     * write zero rows and report success.
     */
    private function pending(string $method): RuntimeException
    {
        return new RuntimeException(
            "QbpClient::{$method}() is not built yet. The QBP adapter currently supports "
            . 'testing the connection only — run `php artisan qbp:probe` to capture a real '
            . 'payload, then the field map and this method can be written against it.'
        );
    }

    // ---------------------------------------------------------------- http

    private function get(string $path, array $query = [])
    {
        return Http::withHeaders([
                'X-QBPAPI-KEY' => $this->apiKey,
                'Accept'       => 'application/json',
            ])
            ->timeout((int) config('distributors.qbp.timeout', 60))
            ->get($this->base . $path, $query);
    }

    /** QBP wraps lists in a named key; find it without assuming the name. */
    private function listish(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($payload as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return [$payload];
    }
}
PHPEOF
echo "created app/Services/Distributors/QbpClient.php"

python3 <<'PY'
import io

# ---------------------------------------------------------------- registry
p = 'app/Services/Distributors/DistributorRegistry.php'
s = io.open(p, encoding='utf-8').read()

assert 'QbpClient' not in s, 'already registered'

old = """        'BTI' => BtiClient::class,
    ];"""
assert s.count(old) == 1, 'R1 adapters map anchor'
s = s.replace(old, """        'BTI' => BtiClient::class,
        // MARKER-QBP-ADAPTER — present so master admin can hold the key and
        // test it. Only testConnection() works; the data methods throw.
        'QBP' => QbpClient::class,
    ];""")

old = """use InvalidArgumentException;"""
assert s.count(old) == 1, 'R2 use anchor'
# QbpClient is in the same namespace, so no import is needed — but make the
# absence deliberate rather than accidental.
s = s.replace(old, """use InvalidArgumentException;
// QbpClient, HlcClient and BtiClient share this namespace, so no import.""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- config
p = 'config/distributors.php'
s = io.open(p, encoding='utf-8').read()

old = "    'bti' => ["
assert s.count(old) == 1, 'C1 bti config anchor'
s = s.replace(old, """    // MARKER-QBP-ADAPTER — host only. The KEY is not here: it belongs on
    // platform_distributor_connections, entered in master admin like every
    // other distributor's.
    'qbp' => [
        'base_url' => env('QBP_BASE_URL', 'https://api1.qbp.com/api/'),
        'timeout'  => (int) env('QBP_TIMEOUT', 60),
    ],

    'bti' => [""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'PHPEOF' > app/Console/Commands/QbpProbe.php
<?php

// MARKER-QBP-ADAPTER

namespace App\Console\Commands;

use App\Models\PlatformDistributorConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Print what QBP's POS API actually returns, using the key saved in master
 * admin. Read-only: no table is touched.
 *
 * Exists because a field map written from a developer guide is a guess. BTI's
 * cost resolved null for weeks because the map matched the documentation and
 * not the data.
 */
class QbpProbe extends Command
{
    protected $signature = 'qbp:probe
        {sku? : SKU to fetch in full. Omitted, one is taken from the SKU list}
        {--raw : Print whole payloads instead of trimmed ones}';

    protected $description = 'Read-only probe of the QBP POS API. Prints responses; writes nothing.';

    private string $base;
    private string $key;

    public function handle(): int
    {
        $conn = PlatformDistributorConnection::where('distributor_code', 'QBP')->first();
        $this->key = trim((string) ($conn->api_key ?? ''));

        if ($this->key === '') {
            $this->error('No QBP key saved.');
            $this->line('Master admin -> Distribution -> Distributors -> QBP, enter the API key, save.');
            return self::FAILURE;
        }

        $this->base = rtrim((string) config('distributors.qbp.base_url'), '/') . '/';
        $this->line('Base URL: ' . $this->base);
        $this->line('Key: from master admin (' . strlen($this->key) . ' chars, not shown)');
        $this->newLine();

        $brands = $this->call('1/brand', 'Brands');
        if (is_array($brands)) {
            $list = $this->listish($brands);
            $this->line('  ' . count($list) . ' brands. First three:');
            foreach (array_slice($list, 0, 3) as $b) {
                $this->line('    ' . json_encode($b));
            }
        }
        $this->newLine();

        $cats = $this->call('1/category', 'Categories');
        if (is_array($cats)) {
            $list = $this->listish($cats);
            $this->line('  ' . count($list) . ' top-level nodes. First one:');
            $this->line('    ' . substr((string) json_encode($list[0] ?? null), 0, 600));
        }
        $this->newLine();

        $sku  = (string) $this->argument('sku');
        $skus = $this->call('1/product/skulist', 'SKU list');
        if (is_array($skus)) {
            $list = $this->listish($skus);
            $this->line('  ' . count($list) . ' SKUs.');
            $this->line('  First five: ' . json_encode(array_slice($list, 0, 5)));
            if ($sku === '' && $list) {
                $first = $list[0];
                $sku = is_array($first)
                    ? (string) ($first['sku'] ?? $first['Sku'] ?? reset($first))
                    : (string) $first;
            }
        }
        $this->newLine();

        if ($sku === '') {
            $this->warn('No SKU to fetch. Pass one: php artisan qbp:probe BB1001');
            return self::SUCCESS;
        }

        $this->line('=== FULL PRODUCT DETAIL — ' . $sku . ' ===');
        $product = $this->call('1/product/sku/' . rawurlencode($sku), 'Product detail', true);

        if (is_array($product)) {
            $this->newLine();
            $this->line('--- top-level keys ---');
            $this->line('  ' . implode(', ', array_keys($this->firstAssoc($product))));

            $this->newLine();
            $this->line('--- anything price-shaped ---');
            $hits = [];
            $this->findPriceish($product, '', $hits);
            if ($hits) {
                foreach ($hits as $path => $val) {
                    $this->line('  ' . str_pad($path, 46) . ' = ' . substr((string) json_encode($val), 0, 80));
                }
            } else {
                $this->warn('  NOTHING FOUND. Dealer cost is not on product detail, so it has to');
                $this->warn('  come from elsewhere — that decides the shape of the integration.');
            }
        }
        $this->newLine();

        $this->call('1/availability/sku/' . rawurlencode($sku), 'Availability for ' . $sku, true);

        $this->newLine();
        $this->info('Done. Nothing was written.');
        return self::SUCCESS;
    }

    /** A 404 here is information, not a failure — never throws. */
    private function call(string $path, string $label, bool $dump = false): mixed
    {
        $this->line('--- ' . $label . '  (' . $path . ')');

        try {
            $res = Http::withHeaders([
                    'X-QBPAPI-KEY' => $this->key,
                    'Accept'       => 'application/json',
                ])
                ->timeout((int) config('distributors.qbp.timeout', 60))
                ->get($this->base . $path);
        } catch (\Throwable $e) {
            $this->error('  request failed: ' . $e->getMessage());
            return null;
        }

        $this->line('  HTTP ' . $res->status() . '  ' . strlen($res->body()) . ' bytes');

        if (! $res->successful()) {
            $this->warn('  ' . substr($res->body(), 0, 300));
            return null;
        }

        $json = $res->json();
        if ($json === null) {
            $this->warn('  not JSON: ' . substr($res->body(), 0, 200));
            return null;
        }

        if ($dump) {
            $pretty = (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->line($this->option('raw') ? $pretty : substr($pretty, 0, 6000));
            if (! $this->option('raw') && strlen($pretty) > 6000) {
                $this->comment('  … trimmed. Re-run with --raw for all of it.');
            }
        }

        return $json;
    }

    private function listish(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($payload as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return [$payload];
    }

    private function firstAssoc(array $payload): array
    {
        if (! array_is_list($payload)) {
            return $payload;
        }
        $first = $payload[0] ?? [];
        return is_array($first) ? $first : [];
    }

    /** Match on the KEY, because what QBP calls cost is the open question. */
    private function findPriceish(mixed $node, string $path, array &$hits, int $depth = 0): void
    {
        if ($depth > 6 || ! is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            $here = $path === '' ? (string) $k : $path . '.' . $k;
            if (is_string($k) && preg_match('/price|cost|msrp|map|retail|dealer|wholesale/i', $k)) {
                $hits[$here] = is_array($v)
                    ? '[' . implode(', ', array_slice(array_keys($v), 0, 6)) . ']'
                    : $v;
            }
            $this->findPriceish($v, $here, $hits, $depth + 1);
        }
    }
}
PHPEOF
echo "created app/Console/Commands/QbpProbe.php"

echo
echo "--- QBP registered ---"
grep -n "QBP\|QbpClient" app/Services/Distributors/DistributorRegistry.php | head -5

echo
echo "--- adapter implements every interface method ---"
python3 - <<'PY'
import io, re
iface = io.open('app/Services/Distributors/DistributorAdapter.php', encoding='utf-8').read()
impl  = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
need = set(re.findall(r'public function (\w+)\(', iface))
have = set(re.findall(r'public function (\w+)\(', impl))
missing = sorted(need - have)
print('  interface methods:', len(need))
print('  missing          :', missing or 'none')
assert not missing
PY

echo
echo "--- no key in config, no env read for it ---"
grep -c "QBP_API_KEY" config/distributors.php app/Console/Commands/QbpProbe.php app/Services/Distributors/QbpClient.php || echo "  0 — key comes from master admin only"

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php',
          'app/Services/Distributors/DistributorRegistry.php',
          'app/Console/Commands/QbpProbe.php',
          'config/distributors.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-32s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-adapter-skeleton: OK"
