<?php

namespace App\Services\Distributors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MARKER-BRAND-BUILDER: Add a brand's look at a website. Works out the store
 * type, how many products it lists, samples a few, and records what each field
 * looks like, step by step, so the page can show the work as it happens.
 * Nothing is saved but this note in the cache.
 */
class BrandSiteProbe
{
    public const SAMPLE = 5;

    private string $key;
    private array $state;

    public function __construct(private string $token)
    {
        $this->key = self::key($token);
        $this->state = ['status' => 'running', 'steps' => [], 'started' => now()->timestamp];
    }

    public static function key(string $token): string
    {
        return 'brand_probe.' . $token;
    }

    public static function state(string $token): ?array
    {
        return Cache::get(self::key($token));
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && ! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $p = parse_url($url);
        return isset($p['host']) ? ($p['scheme'] ?? 'https') . '://' . $p['host'] : '';
    }

    public function run(string $url): void
    {
        $base = self::normalize($url);
        $this->state['base'] = $base;
        try {
            if ($base === '') {
                throw new \RuntimeException('That isn\'t a web address.');
            }
            $this->step('Opening ' . parse_url($base, PHP_URL_HOST));
            $home = $this->get($base, [], 'text/html');
            $html = $home ? $home->body() : '';
            $this->state['name'] = self::siteName($html, $base);

            $platform = null;
            $shop = $this->get($base . '/products.json', ['limit' => self::SAMPLE]);
            if ($shop && is_array($shop->json('products'))) {
                $platform = 'shopify';
            } else {
                $woo = $this->get($base . '/wp-json/wc/store/v1/products', ['per_page' => self::SAMPLE]);
                if ($woo && is_array($woo->json()) && isset(($woo->json()[0] ?? [])['prices'])) {
                    $platform = 'woocommerce';
                    $this->state['total'] = (int) ($woo->header('X-WP-Total') ?: 0) ?: null;
                }
            }
            if (! $platform) {
                $this->state['platform'] = stripos($html, 'bigcommerce') !== false ? 'bigcommerce' : 'custom';
                $this->step('Store type: ' . ($this->state['platform'] === 'bigcommerce' ? 'BigCommerce' : 'a custom site') . ' (no public product feed)', 'warn');
                $this->state['status'] = 'unsupported';
                $this->save();
                return;
            }
            $this->state['platform'] = $platform;
            $this->step('Store type: ' . ($platform === 'shopify' ? 'Shopify' : 'WooCommerce') . ', with a public product feed', 'ok');

            if ($platform === 'shopify') {
                $this->state['total'] = $this->countShopify($base);
            }
            $this->step('Product list: ' . ($this->state['total'] ? number_format($this->state['total']) . ' products' : 'found (count comes with the first read)'), 'ok');

            $client = (new StoreSiteClient())->configure($base, $platform, ['label' => $this->state['name']]);
            $products = $client->sample(self::SAMPLE);
            $rows = [];
            foreach ($products as $i => $p) {
                $prow = [];
                foreach ((array) ($p['variants'] ?? []) as $v) {
                    $prow[] = StoreSiteClient::row($p, (array) $v, 'NEW', [], $base);
                }
                $rows[] = ['product' => $p, 'rows' => $prow];
                $this->step('Read ' . ($p['title'] ?? 'a product') . ' · ' . count($prow) . ' ' . (count($prow) === 1 ? 'variant' : 'variants') . ' · ' . count((array) ($p['images'] ?? [])) . ' photos', 'ok');
            }
            $this->state['fields'] = self::fields($rows);
            $this->state['samples'] = array_map(fn ($r) => [
                'title' => (string) ($r['product']['title'] ?? ''), 'image' => (string) ($r['product']['images'][0]['src'] ?? ''),
                'variants' => count($r['rows']), 'sizes' => count(array_unique(array_filter(array_column($r['rows'], 'size')))),
                'colors' => count(array_unique(array_filter(array_column($r['rows'], 'color')))),
                'price' => $r['rows'][0]['retail'] ?? null, 'type' => (string) ($r['product']['product_type'] ?? ''),
            ], $rows);
            $this->state['options'] = self::optionNames($products);
            $this->state['tags'] = self::tagCounts($products);
            $this->state['status'] = 'done';
            $this->step('Done. Nothing has been saved.', 'ok');
        } catch (\Throwable $e) {
            $this->state['status'] = 'failed';
            $this->state['error'] = $e->getMessage();
            $this->save();
        }
    }

    /** For each field, on how many sampled products it was found, and an example. */
    public static function fields(array $sampled): array
    {
        $n = count($sampled);
        $checks = [
            'name' => ['Name', fn ($r) => $r[0]['title'] ?? ''],
            'variants' => ['Variants', fn ($r) => count($r) > 1 ? count($r) . ' (' . implode(', ', array_slice(array_map(fn ($x) => implode(' / ', $x['options']), $r), 0, 3)) . ')' : ''],
            'price' => ['Price', fn ($r) => isset($r[0]['retail']) && $r[0]['retail'] !== null && $r[0]['retail'] !== '' ? '$' . $r[0]['retail'] : ''],
            'size' => ['Sizes', fn ($r) => implode(' · ', array_slice(array_unique(array_filter(array_column($r, 'size'))), 0, 6))],
            'color' => ['Colors', fn ($r) => implode(' · ', array_slice(array_unique(array_filter(array_column($r, 'color'))), 0, 4))],
            'images' => ['Photos', fn ($r) => count($r[0]['images'] ?? []) ? count($r[0]['images']) . ' photos' : ''],
            'description' => ['Description', fn ($r) => mb_substr((string) ($r[0]['description'] ?? ''), 0, 80)],
            'sku' => ['Part number', fn ($r) => count(array_filter($r, fn ($x) => $x['has_sku'])) === count($r) && $r ? $r[0]['sku'] : ''],
            'type' => ['Product type', fn ($r) => $r[0]['product_type'] ?? ''],
            'tags' => ['Tags (kinds)', fn ($r) => implode(', ', array_slice((array) ($r[0]['tags'] ?? []), 0, 5))],
            'year' => ['Model year', fn ($r) => (string) ($r[0]['model_year'] ?? '')],
        ];
        $out = [];
        foreach ($checks as $k => [$label, $fn]) {
            $found = 0;
            $example = '';
            foreach ($sampled as $s) {
                $v = $s['rows'] ? (string) $fn($s['rows']) : '';
                if ($v !== '') {
                    $found++;
                    $example = $example ?: $v;
                }
            }
            $out[$k] = ['label' => $label, 'found' => $found, 'of' => $n, 'example' => $example];
        }
        return $out;
    }

    private static function optionNames(array $products): array
    {
        $n = [];
        foreach ($products as $p) {
            foreach ((array) ($p['options'] ?? []) as $o) {
                $name = trim((string) ($o['name'] ?? ''));
                if ($name !== '' && mb_strtolower($name) !== 'title') {
                    $n[$name] = ($n[$name] ?? 0) + 1;
                }
            }
        }
        arsort($n);
        return $n;
    }

    private static function tagCounts(array $products): array
    {
        $n = [];
        foreach ($products as $p) {
            foreach ((array) ($p['tags'] ?? []) as $t) {
                $n[$t] = ($n[$t] ?? 0) + 1;
            }
        }
        arsort($n);
        return array_slice($n, 0, 40, true);
    }

    public static function siteName(string $html, string $base): string
    {
        foreach (['~<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)~i', '~<title[^>]*>([^<]+)</title>~i'] as $re) {
            if (preg_match($re, $html, $m)) {
                $t = trim(html_entity_decode($m[1], ENT_QUOTES));
                $t = trim(preg_split('/\s+[|–—-]\s+/u', $t)[0] ?? $t);
                if ($t !== '') {
                    return mb_substr($t, 0, 60);
                }
            }
        }
        $host = preg_replace('/^www\./', '', (string) parse_url($base, PHP_URL_HOST));
        return ucfirst(explode('.', $host)[0] ?? $host);
    }

    /** A Shopify store's product count, from its product sitemaps (a few requests). */
    private function countShopify(string $base): ?int
    {
        $idx = $this->get($base . '/sitemap.xml', [], 'application/xml');
        if (! $idx) {
            return null;
        }
        $n = 0;
        foreach (array_slice(self::locs($idx->body()), 0, 12) as $loc) {
            if (str_contains($loc, 'sitemap_products')) {
                sleep(1);
                $m = $this->get($loc, [], 'application/xml');
                $n += $m ? substr_count($m->body(), '/products/') : 0;
            }
        }
        return $n ?: null;
    }

    /** The <loc> values of a sitemap. */
    public static function locs(string $xml): array
    {
        preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m);
        return array_map(fn ($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1] ?? []);
    }

    private function get(string $url, array $query = [], string $accept = 'application/json'): ?\Illuminate\Http\Client\Response
    {
        try {
            $res = Http::timeout(20)->withHeaders(['User-Agent' => (string) config('distributors.brand_reader.user_agent', 'IntakeCatalog/1.0'), 'Accept' => $accept])
                ->get($url, $query ?: null);
            return $res->successful() ? $res : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function step(string $text, string $tone = ''): void
    {
        $this->state['steps'][] = ['text' => $text, 'tone' => $tone, 'at' => now()->timestamp];
        $this->save();
    }

    private function save(): void
    {
        Cache::put($this->key, $this->state, now()->addHours(6));
    }
}
