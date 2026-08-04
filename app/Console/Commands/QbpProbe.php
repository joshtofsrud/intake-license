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
        {--raw : Print whole payloads instead of trimmed ones}
        {--negotiate : Try several header combinations and report which QBP answers}';

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

        // MARKER-QBP-NEGOTIATE — run this when the normal probe 406s.
        if ($this->option('negotiate')) {
            return $this->negotiate();
        }

        $this->line('Base URL: ' . $this->base);
        $this->line('Key: from master admin (' . strlen($this->key) . ' chars, not shown)');
        $this->newLine();

        // MARKER-QBP-PATHS — brands.brand, by name.
        $brands = $this->probeGet('1/brand', 'Brands');
        if (is_array($brands)) {
            $list = $this->asList($brands['brands']['brand'] ?? null);
            $this->line('  ' . count($list) . ' brands. First three:');
            foreach (array_slice($list, 0, 3) as $b) {
                $this->line('    ' . json_encode($b));
            }
        }
        $this->newLine();

        // MARKER-QBP-PATHS — a FLAT list of nodes, each naming its parent and
        // its children by id. Not a nested tree, so category_path has to be
        // assembled by walking parent links rather than read off a node.
        $cats = $this->probeGet('1/category', 'Categories');
        if (is_array($cats)) {
            $list = $this->asList($cats['productCategories']['productCategory'] ?? null);
            $this->line('  ' . count($list) . ' category nodes (flat; parent links, not nesting).');
            $this->line('  First one:');
            $this->line('    ' . substr((string) json_encode($list[0] ?? null), 0, 500));
            $roots = array_values(array_filter($list, fn ($c) => ($c['parent'] ?? '') === ''));
            $this->line('  ' . count($roots) . ' root node(s).');
        }
        $this->newLine();

        // MARKER-QBP-PATHS — skus.sku is a list of plain strings.
        $sku  = (string) $this->argument('sku');
        $skus = $this->probeGet('1/product/skulist', 'SKU list');
        if (is_array($skus)) {
            $list = $this->asList($skus['skus']['sku'] ?? null);
            $this->line('  ' . count($list) . ' SKUs.');
            $this->line('  First five: ' . json_encode(array_slice($list, 0, 5)));

            // Take the first entry that is genuinely a string. Guarding here
            // because handing an array to a string cast is exactly what
            // killed the previous run before it reached product detail.
            if ($sku === '') {
                foreach ($list as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $sku = trim($candidate);
                        break;
                    }
                }
            }
        }
        $this->newLine();

        if ($sku === '') {
            $this->warn('No SKU to fetch. Pass one: php artisan qbp:probe BB1001');
            return self::SUCCESS;
        }

        $this->line('=== FULL PRODUCT DETAIL — ' . $sku . ' ===');
        $product = $this->probeGet('1/product/sku/' . rawurlencode($sku), 'Product detail', true);

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

        $this->probeGet('1/availability/sku/' . rawurlencode($sku), 'Availability for ' . $sku, true);

        $this->newLine();
        $this->info('Done. Nothing was written.');
        return self::SUCCESS;
    }

    /**
     * MARKER-QBP-PROBE-CLASH — NOT named call(). Illuminate\Console\Command
     * declares a public call() for invoking other artisan commands, and a
     * private override is a fatal at class load.
     *
     * A 404 here is information, not a failure — this never throws.
     */
    /**
     * MARKER-QBP-NEGOTIATE — the same call with different headers.
     *
     * A 406 means content negotiation failed, so the variable is the headers
     * and nothing else. Printing every combination's status side by side
     * turns "which header does it want" into one command rather than a
     * sequence of deploys.
     */
    private function negotiate(): int
    {
        $variants = [
            'documented json'      => ['Accept' => 'application/json'],
            'json + charset'       => ['Accept' => 'application/json; charset=utf-8'],
            'anything'             => ['Accept' => '*/*'],
            'no accept header'     => [],
            'xml'                  => ['Accept' => 'application/xml'],
            'json + user-agent'    => ['Accept' => 'application/json', 'User-Agent' => 'Intake/1.0'],
            'browserish'           => [
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; Intake/1.0)',
            ],
        ];

        // Two paths: one that needs the key, one that should 404 regardless —
        // if BOTH 406, the refusal is happening before routing and the answer
        // is a gateway, not the endpoint.
        $paths = ['1/brand', '1/definitely-not-a-real-endpoint'];

        foreach ($paths as $path) {
            $this->newLine();
            $this->line('=== ' . $this->base . $path);

            foreach ($variants as $label => $headers) {
                $h = $headers + ['X-QBPAPI-KEY' => $this->key];

                try {
                    $res = Http::withHeaders($h)
                        ->timeout((int) config('distributors.qbp.timeout', 60))
                        ->get($this->base . $path);

                    $body = trim((string) $res->body());
                    $this->line(sprintf(
                        '  %-20s HTTP %-4s %6s bytes  %s',
                        $label,
                        $res->status(),
                        strlen($body),
                        mb_substr(preg_replace('/\s+/', ' ', $body), 0, 70)
                    ));
                } catch (\Throwable $e) {
                    $this->line(sprintf('  %-20s failed: %s', $label, mb_substr($e->getMessage(), 0, 70)));
                }
            }
        }

        $this->newLine();
        $this->comment('Read it this way:');
        $this->line('  Any row returning 200 names the headers to use.');
        $this->line('  If 1/brand 406s but the fake path 404s, the endpoint is refusing the');
        $this->line('  Accept header. If BOTH 406, something in front of QBP is rejecting the');
        $this->line('  request before it reaches the API and the headers are not the problem.');
        $this->line('  If every row is 401, the key is wrong rather than the headers.');

        return self::SUCCESS;
    }

    private function probeGet(string $path, string $label, bool $dump = false): mixed
    {
        $this->line('--- ' . $label . '  (' . $path . ')');

        try {
            // MARKER-QBP-XML — XML, measured. JSON 406s on every endpoint.
            $res = Http::withHeaders([
                    'X-QBPAPI-KEY' => $this->key,
                    'Accept'       => 'application/xml',
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

        // MARKER-QBP-XML — dump the RAW XML, not a converted array. The field
        // map is written against QBP's own element names, and a conversion
        // step between what they send and what is on screen is exactly the
        // gap a mapping bug hides in.
        $body = (string) $res->body();

        if ($dump) {
            $pretty = $this->prettyXml($body);
            $this->line($this->option('raw') ? $pretty : mb_substr($pretty, 0, 6000));
            if (! $this->option('raw') && mb_strlen($pretty) > 6000) {
                $this->comment('  … trimmed. Re-run with --raw for all of it.');
            }
        }

        $arr = $this->xmlToArray($body);
        if ($arr === null) {
            $this->warn('  not XML: ' . mb_substr($body, 0, 200));
            return null;
        }

        return $arr;
    }

    /** MARKER-QBP-XML — indent the XML so a nested product is readable. */
    private function prettyXml(string $body): string
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $ok = $doc->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? (string) $doc->saveXML() : $body;
    }

    /** MARKER-QBP-XML — attributes kept, prefixed with @. */
    private function xmlToArray(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($sx === false) {
            return null;
        }

        $walk = function (\SimpleXMLElement $el) use (&$walk): array {
            $out = [];
            foreach ($el->attributes() as $k => $v) {
                $out['@' . $k] = (string) $v;
            }
            foreach ($el->children() as $name => $child) {
                $value = ($child->count() > 0 || $child->attributes()->count() > 0)
                    ? $walk($child)
                    : trim((string) $child);
                if (array_key_exists($name, $out)) {
                    if (! is_array($out[$name]) || ! array_is_list($out[$name])) {
                        $out[$name] = [$out[$name]];
                    }
                    $out[$name][] = $value;
                } else {
                    $out[$name] = $value;
                }
            }
            return $out;
        };

        return $walk($sx);
    }

    /**
     * MARKER-QBP-PATHS — one child or many.
     *
     * SimpleXML hands back an object for a single child and a list for two,
     * so every collection read goes through this. Replaces listish(), which
     * searched a payload for any list it could find — a JSON habit that has
     * no place against XML, where the collection is named.
     *
     * @return array<int,mixed>
     */
    private function asList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        return [$value];
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
