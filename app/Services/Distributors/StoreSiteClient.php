<?php

namespace App\Services\Distributors;

use App\Support\BrandKinds;
use App\Support\BrandSources;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MARKER-BRAND-BUILDER: the general reader for brands added from master
 * admin's Add a brand. A brand's site is a standard online store with a public
 * product feed (Shopify, WooCommerce); which one, and the option names for
 * size and color, are settings on the brand (brand_sources), so a new brand
 * needs no code. Everything per brand is keyed by its code.
 *
 * Cost and stock come from the brand's stock file, as for every brand sold
 * direct; this reader fills the catalog only.
 */
class StoreSiteClient implements DistributorAdapter
{
    /** Bump when what is kept per product changes, so every product is read once more. */
    public const READER_VERSION = 1;

    private string $code = '';
    private string $base = '';
    private string $platform = '';
    private array $settings = [];
    private int $timeout = 30;
    private array $only = [];
    /** @var callable|null */
    private $onBike = null;

    public function __construct(string $apiKey = '', string $region = 'us')
    {
    }

    /** Which brand this reader is for; the registry calls it when it builds the reader. */
    public function forCode(string $code): static
    {
        $src = BrandSources::get($code);
        $this->code = strtoupper($code);
        $this->base = rtrim((string) ($src->base_url ?? ''), '/');
        $this->platform = (string) ($src->platform ?? '');
        $this->settings = (array) ($src->settings ?? []);
        return $this;
    }

    /** For trying a site before it is a brand (Add a brand's check): the address and store type only. */
    public function configure(string $base, string $platform, array $settings = [], string $code = 'NEW'): static
    {
        $this->code = strtoupper($code);
        $this->base = rtrim($base, '/');
        $this->platform = $platform;
        $this->settings = $settings;
        return $this;
    }

    /**
     * Which route reaches a Shopify store's products: the open product list,
     * the all-products collection, or the sitemap and each product's own data.
     * Null when none does.
     */
    public function findShopifyRoute(): ?string
    {
        foreach (['products', 'collection'] as $route) {
            try {
                $res = $this->get($this->base . self::SHOPIFY_ROUTES[$route], ['limit' => 1]);
                if (! empty($res->json('products'))) {
                    return $route;
                }
            } catch (\Throwable) {
                // try the next route
            }
        }
        try {
            $this->settings['shopify_route'] = 'sitemap';
            $this->sitemapHandles = null;
            if ($this->sitemapPage(1, 1)) {
                return 'sitemap';
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /** The first few products of the feed, in the shape kept. */
    public function sample(int $n = 5): array
    {
        return array_slice($this->listPage(1, $n), 0, $n);
    }

    public function code(): string { return $this->code; }

    public function name(): string { return (string) (BrandSources::get($this->code)->label ?? ($this->settings['label'] ?? 'The brand')); }

    public static function needsKey(): bool { return false; }

    public static function shopSideReady(): bool { return true; }

    public static function stockFile(): array { return ['export' => 'stock file', 'columns' => []]; }

    /** Cache and file keys, per brand. */
    public static function key(string $what, ?string $code = null): string
    {
        return 'brand.' . strtolower((string) $code) . '.' . $what;
    }

    public static function pace(?string $code = null): array
    {
        $saved = (array) ((\App\Models\PlatformSettings::current()->distribution_settings ?? [])['brand_read'][strtoupper((string) $code)] ?? []);
        $num = fn ($k, $def, $min, $max) => max($min, min($max, (int) ($saved[$k] ?? $def)));
        return ['page_gap_s' => $num('page_gap_s', 5, 1, 600), 'refresh_days' => $num('refresh_days', 30, 1, 365)];
    }

    // ------------------------------------------------------------ reading

    public function only(array $handlesOrUrls): static
    {
        $this->only = array_values(array_filter(array_map(function ($h) {
            $h = trim((string) $h);
            if (preg_match('~/(?:products|product)/([^/?#]+)~', $h, $m)) {
                $h = $m[1];
            }
            return strtolower(trim($h, '/ '));
        }, $handlesOrUrls)));
        return $this;
    }

    public function onBike(?callable $fn): static
    {
        $this->onBike = $fn;
        return $this;
    }

    public function hasMore(): bool { return false; }

    public function testConnection(): array
    {
        try {
            $list = $this->listPage(1, 1);
            return ['ok' => $list !== [], 'message' => $list ? 'Product feed answered.' : 'The product feed answered with no products.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function products(array $opts = []): array
    {
        $store = self::store($this->code);
        $products = [];
        if ($this->only) {
            foreach ($this->only as $h) {
                $p = $this->one($h);
                if (! $p) {
                    throw new \RuntimeException('No product "' . $h . '" on ' . $this->name() . '\'s store.');
                }
                $products[] = $p;
            }
        } else {
            $page = 1;
            $pace = self::pace($this->code);
            while ($page <= 400) {
                $this->progress('bikes', count($products), max(count($products) + 1, (int) ($this->settings['estimate'] ?? 0)));
                $batch = $this->listPage($page, $this->platform === 'woocommerce' ? 100 : 250);
                if (! $batch) {
                    break;
                }
                foreach ($batch as $p) {
                    $products[] = $p;
                    $this->keep($store, $p);
                }
                $page++;
                $this->pause($pace['page_gap_s'], count($products));
            }
        }

        $out = [];
        foreach ($products as $p) {
            $this->keep($store, $p);
            if ($bike = $this->productFor($p)) {
                $out[] = $bike;
            }
        }
        self::saveStore($this->code, $store);
        $this->progress('done', count($out), count($out));
        return ['Products' => $out, 'Failures' => []];
    }

    private function keep(array &$store, array $p): void
    {
        $h = (string) ($p['handle'] ?? '');
        if ($h === '') {
            return;
        }
        $store[$h] = ['product' => $p, 'read_at' => now()->timestamp, 'v' => self::READER_VERSION];
        $this->recent($p);
        if ($this->onBike && ($bike = $this->productFor($p))) {
            try { ($this->onBike)($bike); } catch (\Throwable) { /* written again at the end of the read */ }
        }
    }

    /** The Shopify routes to a store's products, in the order they're tried. */
    public const SHOPIFY_ROUTES = [
        'products'   => '/products.json',
        'collection' => '/collections/all/products.json',
        'sitemap'    => null, // the product sitemap, then each product's own data
    ];

    /** Handles from the product sitemap, kept for the read (the sitemap route). */
    private ?array $sitemapHandles = null;

    /** One page of the product feed, in one shape whatever the store. */
    private function listPage(int $page, int $per): array
    {
        if ($this->platform === 'shopify') {
            $route = (string) ($this->settings['shopify_route'] ?? 'products');
            if ($route === 'sitemap') {
                return $this->sitemapPage($page, min($per, 25));
            }
            $path = self::SHOPIFY_ROUTES[$route] ?? '/products.json';
            $res = $this->get($this->base . $path, ['limit' => $per, 'page' => $page]);
            return array_map([self::class, 'fromShopify'], (array) ($res->json('products') ?? []));
        }
        if ($this->platform === 'woocommerce') {
            $res = $this->get($this->base . '/wp-json/wc/store/v1/products', ['per_page' => $per, 'page' => $page]);
            $items = is_array($res->json()) ? $res->json() : [];
            return array_map(fn ($i) => $this->fromWoo((array) $i), $items);
        }
        throw new \RuntimeException('This brand\'s store type ("' . $this->platform . '") is read by the custom-site setup, which is not built yet.');
    }

    /**
     * A store whose product lists are hidden: its sitemap lists every product
     * page, and each product's own data is read one at a time, with the pace.
     */
    private function sitemapPage(int $page, int $per): array
    {
        if ($this->sitemapHandles === null) {
            $this->sitemapHandles = [];
            $idx = $this->get($this->base . '/sitemap.xml', []);
            foreach (BrandSiteProbe::locs($idx->body()) as $loc) {
                if (! str_contains($loc, 'sitemap_products')) {
                    continue;
                }
                foreach (BrandSiteProbe::locs($this->get($loc, [])->body()) as $u) {
                    if (preg_match('~/products/([^/?#]+)~', $u, $m)) {
                        $this->sitemapHandles[strtolower($m[1])] = true;
                    }
                }
            }
            $this->sitemapHandles = array_keys($this->sitemapHandles);
        }
        $out = [];
        $gap = self::pace($this->code)['page_gap_s'];
        foreach (array_slice($this->sitemapHandles, ($page - 1) * $per, $per) as $n => $h) {
            if ($n > 0) {
                $this->pause($gap, 0);
            }
            try {
                if ($p = $this->one($h)) {
                    $out[] = $p;
                }
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'Stopped from master admin.') {
                    throw $e;
                }
                // a product that won't answer is skipped; the next read tries again
            }
        }
        return $out;
    }

    private function one(string $handle): ?array
    {
        if ($this->platform === 'shopify') {
            $res = $this->get($this->base . '/products/' . rawurlencode($handle) . '.json', []);
            return is_array($res->json('product')) ? self::fromShopify($res->json('product')) : null;
        }
        if ($this->platform === 'woocommerce') {
            $res = $this->get($this->base . '/wp-json/wc/store/v1/products', ['slug' => $handle]);
            $i = (array) (($res->json() ?? [])[0] ?? []);
            return $i ? $this->fromWoo($i) : null;
        }
        return null;
    }

    // ------------------------------------------------------------ shapes

    /** A Shopify product as kept: its own fields, tags as a list. */
    public static function fromShopify(array $p): array
    {
        $tags = $p['tags'] ?? [];
        $p['tags'] = array_values(array_filter(array_map('trim', is_array($tags) ? $tags : explode(',', (string) $tags))));
        $p['options'] = array_values(array_map(fn ($o) => ['name' => (string) ($o['name'] ?? ''), 'values' => array_values((array) ($o['values'] ?? []))], (array) ($p['options'] ?? [])));
        $p['images'] = array_values(array_map(fn ($i) => ['src' => (string) ($i['src'] ?? '')], (array) ($p['images'] ?? [])));
        return $p;
    }

    /** A WooCommerce product in the same shape as a Shopify one. */
    private function fromWoo(array $i): array
    {
        $minor = (int) ($i['prices']['currency_minor_unit'] ?? 2);
        $money = fn ($v) => $v === null || $v === '' ? null : number_format(((int) $v) / (10 ** $minor), 2, '.', '');
        $attrs = array_values(array_filter((array) ($i['attributes'] ?? []), fn ($a) => ! empty($a['has_variations'])));
        $options = array_map(fn ($a) => ['name' => (string) ($a['name'] ?? ''), 'values' => array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) ($a['terms'] ?? []))], $attrs);
        $variants = [];
        $varIds = array_map(fn ($v) => (int) ($v['id'] ?? 0), (array) ($i['variations'] ?? []));
        if ($varIds) {
            $byId = [];
            foreach (array_chunk($varIds, 100) as $chunk) {
                $res = $this->get($this->base . '/wp-json/wc/store/v1/products', ['include' => implode(',', $chunk), 'per_page' => 100, 'type' => 'variation']);
                foreach ((array) $res->json() as $vv) {
                    $byId[(int) ($vv['id'] ?? 0)] = (array) $vv;
                }
            }
            foreach ((array) ($i['variations'] ?? []) as $v) {
                $full = $byId[(int) ($v['id'] ?? 0)] ?? [];
                $row = ['id' => $v['id'] ?? null, 'sku' => (string) ($full['sku'] ?? ''), 'price' => $money($full['prices']['price'] ?? null),
                    'available' => (bool) ($full['is_in_stock'] ?? false), 'title' => ''];
                foreach ($options as $n => $o) {
                    $val = '';
                    foreach ((array) ($v['attributes'] ?? []) as $va) {
                        if (mb_strtolower((string) ($va['name'] ?? '')) === mb_strtolower($o['name'])) {
                            $val = (string) ($va['value'] ?? '');
                        }
                    }
                    $row['option' . ($n + 1)] = $val;
                }
                $row['title'] = implode(' / ', array_filter([$row['option1'] ?? '', $row['option2'] ?? '', $row['option3'] ?? '']));
                $variants[] = $row;
            }
        } else {
            $variants[] = ['id' => $i['id'] ?? null, 'sku' => (string) ($i['sku'] ?? ''), 'price' => $money($i['prices']['price'] ?? null),
                'available' => (bool) ($i['is_in_stock'] ?? false), 'title' => 'Default Title'];
        }
        $cats = array_map(fn ($c) => (string) ($c['name'] ?? ''), (array) ($i['categories'] ?? []));
        return [
            'id' => $i['id'] ?? null, 'handle' => (string) ($i['slug'] ?? ''), 'title' => html_entity_decode((string) ($i['name'] ?? ''), ENT_QUOTES),
            'body_html' => (string) ($i['description'] ?: ($i['short_description'] ?? '')), 'vendor' => '',
            'product_type' => $cats[0] ?? '', 'tags' => array_values(array_filter(array_merge(array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) ($i['tags'] ?? [])), array_slice($cats, 1)))),
            'images' => array_map(fn ($im) => ['src' => (string) ($im['src'] ?? '')], (array) ($i['images'] ?? [])),
            'options' => $options, 'variants' => $variants, 'url' => (string) ($i['permalink'] ?? ''), 'updated_at' => null,
        ];
    }

    /** The option positions (1-based) holding size and color, from the brand's settings or the option names. */
    public static function optionSlots(array $p, array $settings = []): array
    {
        $size = null;
        $color = null;
        foreach (array_values((array) ($p['options'] ?? [])) as $n => $o) {
            $name = mb_strtolower(trim((string) ($o['name'] ?? '')));
            $wantSize = mb_strtolower((string) ($settings['size_option'] ?? ''));
            $wantColor = mb_strtolower((string) ($settings['color_option'] ?? ''));
            if ($size === null && ($wantSize !== '' ? $name === $wantSize : preg_match('/\b(size|frame size|frame)\b/', $name))) {
                $size = $n + 1;
            } elseif ($color === null && ($wantColor !== '' ? $name === $wantColor : preg_match('/\b(colou?r|finish|paint)\b/', $name))) {
                $color = $n + 1;
            }
        }
        return ['size' => $size, 'color' => $color];
    }

    /** One stored product in the shape the catalog sync writes, one row per variant. */
    public function productFor(array $p): ?array
    {
        $rows = [];
        foreach ((array) ($p['variants'] ?? []) as $v) {
            $rows[] = self::row($p, (array) $v, $this->code, $this->settings, $this->base);
        }
        return $rows ? ['Brand' => $this->name(), 'product_code' => (string) ($p['handle'] ?? ''), 'Name' => (string) ($p['title'] ?? ''), 'Variants' => $rows] : null;
    }

    /** One variant, flat, for the field map. */
    public static function row(array $p, array $v, string $code, array $settings = [], string $base = ''): array
    {
        $slots = self::optionSlots($p, $settings);
        $opt = fn ($n) => $n ? trim((string) ($v['option' . $n] ?? '')) : '';
        $size = $opt($slots['size']);
        $color = $opt($slots['color']);
        $sku = strtoupper(trim((string) ($v['sku'] ?? '')));
        $id = $sku !== '' ? $sku : strtoupper(($p['handle'] ?? 'p') . '-' . ($v['id'] ?? '0'));
        $tags = (array) ($p['tags'] ?? []);
        $year = null;
        foreach ($tags as $t) {
            if (preg_match('/^(20\d{2})[a-z]?$/i', trim((string) $t), $m)) {
                $year = (int) $m[1];
            }
        }
        $type = trim((string) ($p['product_type'] ?? ''));
        $kind = '';
        $have = [];
        foreach ($tags as $t) {
            $have[mb_strtolower((string) $t)] = (string) $t;
        }
        foreach (BrandKinds::for($code) as $k) {
            if (isset($have[mb_strtolower($k)])) {
                $kind = $have[mb_strtolower($k)];
                break;
            }
        }
        $specs = $year !== null ? [['Name' => 'Model year', 'Value' => (string) $year]] : [];
        $imgs = array_values(array_filter(array_map(fn ($i) => (string) ($i['src'] ?? ''), (array) ($p['images'] ?? []))));
        return [
            'VariantNo'        => $id,
            'sku'              => $sku !== '' ? $sku : $id,
            'has_sku'          => $sku !== '',
            'handle'           => (string) ($p['handle'] ?? ''),
            'title'            => trim((string) ($p['title'] ?? '')),
            'vendor'           => trim((string) ($p['vendor'] ?? '')),
            'size'             => $size,
            'color'            => $color,
            'options'          => array_filter([$v['option1'] ?? null, $v['option2'] ?? null, $v['option3'] ?? null]),
            'retail'           => $v['price'] ?? null,
            'available'        => (bool) ($v['available'] ?? false),
            'description'      => self::text((string) ($p['body_html'] ?? '')),
            'product_type'     => $type,
            'category'         => $kind !== '' ? $kind : $type,
            'category_path'    => trim($type . ($kind !== '' ? ' > ' . $kind : ''), ' >'),
            'tags'             => $tags,
            'model_year'       => $year,
            'images'           => $imgs,
            'specs'            => $specs,
            'url'              => (string) ($p['url'] ?? '') ?: ($base . '/products/' . ($p['handle'] ?? '')),
            'DateLastModified' => $p['updated_at'] ?? null,
        ];
    }

    public static function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/li|/h\d)\s*/?>~i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5);
        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $t) ?? '') ?? '');
    }

    // ------------------------------------------------------------ store

    private static function storeFile(string $code): string
    {
        return storage_path('app/distributors/brand-' . strtolower($code) . '.json');
    }

    public static function store(?string $code = null): array
    {
        $f = self::storeFile((string) $code);
        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    private static function saveStore(string $code, array $store): void
    {
        $f = self::storeFile($code);
        @mkdir(dirname($f), 0775, true);
        file_put_contents($f . '.tmp', json_encode($store));
        rename($f . '.tmp', $f);
    }

    public static function storedEntry(string $handle, ?string $code = null): ?array
    {
        return self::store($code)[strtolower($handle)] ?? self::store($code)[$handle] ?? null;
    }

    public static function currentHandles(?string $code = null): array
    {
        $out = [];
        foreach (self::store($code) as $h => $e) {
            if (! empty($e['product']) && (int) ($e['v'] ?? 0) >= self::READER_VERSION) {
                $out[] = (string) $h;
            }
        }
        return $out;
    }

    public static function tagCounts(?string $code = null): array
    {
        $n = [];
        foreach (self::store($code) as $e) {
            foreach ((array) ($e['product']['tags'] ?? []) as $t) {
                $n[$t] = ($n[$t] ?? 0) + 1;
            }
        }
        arsort($n);
        return $n;
    }

    // ------------------------------------------------------------ manners

    private function get(string $url, array $query): \Illuminate\Http\Client\Response
    {
        foreach ([5, 15, 30, 60] as $i => $wait) {
            $this->stopIfAsked();
            $res = Http::timeout($this->timeout)->withHeaders([
                'User-Agent' => (string) config('distributors.brand_reader.user_agent', 'IntakeCatalog/1.0'),
                'Accept' => 'application/json',
            ])->get($url, $query ?: null);
            if (! in_array($res->status(), [429, 430, 503], true)) {
                if (! $res->successful()) {
                    throw new \RuntimeException($this->name() . '\'s store answered HTTP ' . $res->status() . ' for ' . parse_url($url, PHP_URL_PATH) . '.');
                }
                return $res;
            }
            $after = (int) $res->header('Retry-After');
            $w = max(1, min(300, $after > 0 ? $after : $wait));
            Cache::put(self::key('progress', $this->code), ['phase' => 'wait', 'wait' => $w, 'attempt' => $i + 1, 'of' => 4, 'at' => now()->timestamp], now()->addHours(2));
            for ($t = 0; $t < $w; $t++) {
                $this->stopIfAsked();
                sleep(1);
            }
        }
        throw new \RuntimeException($this->name() . '\'s store kept asking us to slow down; try again later.');
    }

    private function pause(int $seconds, int $done): void
    {
        for ($t = 0; $t < $seconds; $t++) {
            $this->stopIfAsked();
            sleep(1);
        }
    }

    private function stopIfAsked(): void
    {
        if (Cache::get(self::key('stop', $this->code))) {
            throw new \RuntimeException('Stopped from master admin.');
        }
    }

    private function progress(string $phase, int $done, int $total): void
    {
        Cache::put(self::key('progress', $this->code), ['phase' => $phase, 'done' => $done, 'total' => $total, 'at' => now()->timestamp], now()->addHours(2));
    }

    private function recent(array $p): void
    {
        $k = self::key('recent', $this->code);
        $list = (array) Cache::get($k, []);
        array_unshift($list, ['handle' => $p['handle'] ?? '', 'title' => (string) ($p['title'] ?? ''), 'sizes' => count((array) ($p['variants'] ?? [])),
            'specs' => 0, 'images' => count((array) ($p['images'] ?? [])), 'at' => now()->timestamp]);
        Cache::put($k, array_slice($list, 0, 6), now()->addDay());
    }

    // ------------------------------------------------------------ not used for brands sold direct

    public function brands(): array { return []; }
    public function categories(): array { return []; }
    public function inventory(array $skus): array { return []; }
    public function prices(array $skus): array { return []; }
    public function images(array $skus): array { return []; }
}
