<?php

namespace App\Services\Distributors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Kona: a brand-direct supplier (see BrandDirect). Two sources, joined on
 * Kona's part number:
 *
 *   catalog    Kona's public Shopify store. /products.json lists every
 *              published bike with one variant per size (sku = Kona's part
 *              number, the same as the dealer stock file's Inventory ID),
 *              the photos and the description. The spec table and geometry
 *              are only on each product page, so those pages are read too:
 *              each once, then again after a refresh period, a limited number
 *              per run and spaced out (see pace(), set in master admin).
 *   stock      each dealer's own stock file (cost, stock, UPC, year). That is
 *              the shop side and is not read here.
 *
 * Nothing here needs a login. Kona confirmed the website is the way to get
 * product information; there is no asset feed.
 *
 * Products come back in the shape the catalog sync expects (Brand, Name,
 * Variants[]), one variant per size. Every quirk of the data (labels, the
 * vendor name, sizes without units) is left for the field map's clean-up in
 * master admin.
 */
class KonaClient implements DistributorAdapter
{
    public const CODE = 'KONA';

    /** Live progress of a catalog read, for master admin's Brand suppliers page. */
    public const PROGRESS_KEY = 'kona.catalog_read';

    /** How many bikes have a current spec page, for master admin. */
    public const PAGES_KEY = 'kona.pages_progress';

    /** The last few bikes read. */
    public const RECENT_KEY = 'kona.recent_bikes';

    /**
     * Bumped when the page reader learns a new layout, so bikes read with
     * the older reader are read again once (2: the older #kro_specs table).
     */
    public const READER_VERSION = 4;

    /** Set by the Stop button; the read stops before its next request. */
    public const STOP_KEY = 'kona.catalog_stop';

    private string $base;
    private int $timeout;

    /** @var string[] when set, only these products are read (handles), straight from their own pages */
    private array $only = [];

    /** Set when a read stopped at its time limit with bikes still to read. */
    private bool $more = false;

    /** @var null|callable(array):void */
    private $onBike = null;

    public function __construct(string $apiKey = '', string $region = 'us')
    {
        $this->base    = rtrim((string) config('distributors.kona.base_url', 'https://konaworld.com'), '/');
        $this->timeout = (int) config('distributors.kona.timeout', 30);
    }

    /**
     * Read only these products: a handle ("rove-al-b37-gn") or a product
     * page URL each. The product list is skipped, so this is one or two
     * requests per bike: the way to try one bike first.
     */
    public function only(array $handlesOrUrls): static
    {
        $this->only = array_values(array_filter(array_map(function ($h) {
            $h = trim((string) $h);
            if (preg_match('~/products/([^/?#]+)~', $h, $m)) {
                $h = $m[1];
            }
            return strtolower(trim($h, '/ '));
        }, $handlesOrUrls)));
        return $this;
    }

    public function code(): string { return self::CODE; }

    /** The cache keys this reader uses, by name, as every brand reader offers them. */
    public static function key(string $what, ?string $code = null): string
    {
        return match ($what) {
            'progress' => self::PROGRESS_KEY, 'pages' => self::PAGES_KEY,
            'recent' => self::RECENT_KEY, 'stop' => self::STOP_KEY,
            default => 'kona.' . $what,
        };
    }

    public function name(): string { return 'Kona'; }

    /** No login: the catalog is public. */
    public static function needsKey(): bool { return false; }

    /** What Kona's dealer stock file looks like (see BrandStockFile). */
    public static function stockFile(): array
    {
        return ['export' => 'Item Valuation Data', 'columns' => []];
    }

    /**
     * Whether shops can use this source. The shop's Kona box (stock file upload
     * and FTP folder) is built, so shops switched on in master admin see it.
     */
    public static function shopSideReady(): bool { return true; }

    public function testConnection(): array
    {
        try {
            $res = $this->politeGet($this->base . '/sitemap.xml', [], 'test', 0, 0);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'auth' => false, 'body' => 'Kona\'s site did not answer: ' . $e->getMessage()];
        }
        $ok = $res->successful() && str_contains($res->body(), 'sitemap_products');
        return [
            'ok' => $ok, 'status' => $res->status(), 'auth' => false,
            'body' => $ok ? 'Kona\'s sitemap answered.' : 'Kona\'s sitemap answered HTTP ' . $res->status() . '.',
        ];
    }

    public function brands(): array
    {
        return [['id' => 'Kona', 'name' => 'Kona']];
    }

    public function categories(): array
    {
        $out = [];
        foreach ($this->stored() as $p) {
            $c = $this->categoryTag($p);
            if ($c !== '') {
                $out[$c] = ['id' => $c, 'name' => $c];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Every Kona bike, one variant per size.
     *
     * The bike list is Kona's sitemap (one light request). Each bike is then
     * read the way "Read this one" reads it: its own product data, then its
     * page for the spec table, one bike every page_gap_s seconds. A bike is
     * read once and again after refresh_days; Kona's store bumps every
     * product's change date constantly, so that date says nothing. The bulk
     * product list (/products.json) is never used: Kona throttles it.
     *
     * A read stops after job_minutes and sets hasMore(); the job then hands
     * over to a fresh one that carries on (SyncDistributorCatalogJob).
     */
    public function products(array $opts = []): array
    {
        $parts = array_flip(array_map(fn ($p) => strtoupper(trim((string) $p)), $opts['vendorParts'] ?? []));

        $handles = $this->only ?: $this->sitemapHandles();
        $saved   = $this->readBikes($handles);

        $out = [];
        foreach ($handles as $handle) {
            if ($p = $this->productFor($handle, $saved[$handle] ?? null, $parts)) {
                $out[] = $p;
            }
        }
        if (! $this->more) {
            $this->progress('done', count($out), count($out));
        }
        return ['Products' => $out, 'Failures' => []];
    }

    /**
     * One stored bike in the shape the catalog sync writes (Brand, Name,
     * Variants[] with one row per size), or null when it has nothing to write.
     */
    private function productFor(string $handle, ?array $entry, array $parts = []): ?array
    {
        if (! $entry || empty($entry['product']) || ! empty($entry['gone'])) {
            return null;
        }
        $p = $entry['product'];
        $rows = [];
        foreach ((array) ($p['variants'] ?? []) as $v) {
            $sku = strtoupper(trim((string) ($v['sku'] ?? '')));
            if ($sku === '' || ($parts && ! isset($parts[$sku]))) {
                continue;
            }
            $rows[] = $this->row($p, $v, $entry);
        }
        return $rows ? ['Brand' => 'Kona', 'product_code' => $handle, 'Name' => (string) ($p['title'] ?? ''), 'Variants' => $rows] : null;
    }

    /**
     * Called with each bike as soon as it is read (the job writes it into the
     * catalog there and then), so master admin sees the catalog fill live.
     */
    public function onBike(?callable $fn): static
    {
        $this->onBike = $fn;
        return $this;
    }

    /** Products read with the current reader (handles), for master admin's counts and filters. */
    public static function currentHandles(): array
    {
        $out = [];
        foreach (self::store() as $h => $e) {
            if ((! empty($e['product']) || ! empty($e['gone'])) && (int) ($e['v'] ?? 1) >= self::READER_VERSION) {
                $out[] = (string) $h;
            }
        }
        return $out;
    }

    /** What a stored bike holds, for master admin's Inspect panel. */
    public static function storedEntry(string $handle): ?array
    {
        return self::store()[strtolower($handle)] ?? null;
    }

    /** True when the last products() stopped at its time limit with bikes still to read. */
    public function hasMore(): bool
    {
        return $this->more;
    }

    /** Cost and stock come from each dealer's stock file (shop side). */
    public function inventory(array $skus): array { return []; }

    public function prices(array $skus): array { return []; }

    public function images(array $skus): array
    {
        $want = array_flip(array_map(fn ($s) => strtoupper((string) $s), $skus));
        $out = [];
        foreach ($this->stored() as $p) {
            $imgs = array_values(array_filter(array_map(fn ($i) => (string) ($i['src'] ?? ''), (array) ($p['images'] ?? []))));
            foreach ((array) ($p['variants'] ?? []) as $v) {
                $sku = strtoupper((string) ($v['sku'] ?? ''));
                if ($sku !== '' && (! $want || isset($want[$sku]))) {
                    $out[$sku] = $imgs;
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ the record

    /** One size of one bike, flat, for the field map. */
    private function row(array $p, array $v, array $page): array
    {
        $year = $this->year($p);
        $size = trim((string) ($v['option1'] ?? $v['title'] ?? ''));
        $specs = $page['specs'] ?? [];
        if ($year !== null) {
            array_unshift($specs, ['Name' => 'Model year', 'Value' => (string) $year]);
        }
        $geo = [];
        foreach ((array) ($page['geometry'] ?? []) as $label => $bySize) {
            if (isset($bySize[$size]) && $bySize[$size] !== '') {
                $geo[] = ['Name' => $label, 'Value' => $bySize[$size]];
            }
        }
        $type = trim((string) ($p['product_type'] ?? ''));
        $tag  = $this->categoryTag($p);

        return [
            'VariantNo'        => strtoupper(trim((string) ($v['sku'] ?? ''))),
            'sku'              => strtoupper(trim((string) ($v['sku'] ?? ''))),
            'handle'           => (string) ($p['handle'] ?? ''),
            'title'            => trim((string) ($p['title'] ?? '')),
            'vendor'           => trim((string) ($p['vendor'] ?? '')),
            'size'             => $size,
            'option2'          => $v['option2'] ?? null,
            'retail'           => $v['price'] ?? null,
            'available'        => (bool) ($v['available'] ?? false),
            'description'      => $this->text((string) ($p['body_html'] ?? '')),
            'product_type'     => $type,
            'category'         => $tag !== '' ? $tag : $type,
            'category_path'    => trim($type . ($tag !== '' ? ' > ' . $tag : ''), ' >'),
            'tags'             => self::tags($p),
            'model_year'       => $year,
            'images'           => array_values(array_filter(array_map(fn ($i) => (string) ($i['src'] ?? ''), (array) ($p['images'] ?? [])))),
            'specs'            => $specs,
            'geometry'         => $geo,
            'geometry_all'     => (array) ($page['geometry'] ?? []),
            'sizing'           => self::sizingFor((array) ($page['sizing_tables'] ?? []), (string) ($p['title'] ?? '')),
            'story'            => $page['story'] ?? null,
            'paint_color'      => $this->spec($specs, 'paint color'),
            'url'              => $this->base . '/products/' . ($p['handle'] ?? ''),
            'DateLastModified' => $p['updated_at'] ?? null,
        ];
    }

    /** "2027a" in the tags is the model year. */
    private function year(array $p): ?int
    {
        foreach (self::tags($p) as $t) {
            if (preg_match('/^(20\d{2})[a-z]?$/i', trim((string) $t), $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * A product's tags as a list. The store's product list gives them as a
     * list; one product's own data gives them as one comma-separated line.
     */
    public static function tags(array $p): array
    {
        $t = $p['tags'] ?? [];
        $list = is_array($t) ? $t : explode(',', (string) $t);
        return array_values(array_filter(array_map(fn ($x) => trim((string) $x), $list), fn ($x) => $x !== ''));
    }

    /**
     * The kind of product ("Mountain Full Suspension"): the first tag, in the
     * order set on Brand suppliers, that the product carries. A setting, not a
     * guess: the store's tags also hold its own markers ("PRE-SYNC", "Tariff").
     */
    private function categoryTag(array $p): string
    {
        $have = [];
        foreach (self::tags($p) as $t) {
            $have[mb_strtolower($t)] = $t;
        }
        foreach (\App\Support\BrandKinds::for(self::CODE) as $kind) {
            if (isset($have[mb_strtolower($kind)])) {
                return $have[mb_strtolower($kind)];
            }
        }
        return '';
    }

    /** Every tag the stored products carry, with how many products carry it, for master admin. */
    public static function tagCounts(): array
    {
        $n = [];
        foreach (self::store() as $e) {
            foreach (self::tags((array) ($e['product'] ?? [])) as $t) {
                $n[$t] = ($n[$t] ?? 0) + 1;
            }
        }
        arsort($n);
        return $n;
    }

    private function spec(array $specs, string $label): ?string
    {
        foreach ($specs as $s) {
            if (mb_strtolower(trim((string) ($s['Name'] ?? ''))) === $label) {
                return (string) $s['Value'];
            }
        }
        return null;
    }

    private function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $t) ?? $t) ?? $t);
    }

    // ------------------------------------------------------------- reading

    /**
     * Every product handle in Kona's sitemap (the index, then each product
     * sitemap it lists): one or two requests for the whole list.
     *
     * @return string[]
     */
    private function sitemapHandles(): array
    {
        $this->progress('sitemap', 0, 0);
        $index = $this->politeGet($this->base . '/sitemap.xml', [], 'sitemap', 0, 0);
        if (! $index->successful()) {
            throw new \RuntimeException('Kona\'s sitemap answered HTTP ' . $index->status() . '.');
        }
        $maps = [];
        foreach (self::locs($index->body()) as $loc) {
            if (str_contains($loc, 'sitemap_products')) {
                $maps[] = $loc;
            }
        }
        $handles = [];
        foreach ($maps as $n => $map) {
            if ($n > 0) {
                $this->pause(self::pace()['page_gap_s'], 'sitemap', $n, count($maps));
            }
            $res = $this->politeGet($map, [], 'sitemap', $n + 1, count($maps));
            if (! $res->successful()) {
                throw new \RuntimeException('Kona\'s product sitemap answered HTTP ' . $res->status() . '.');
            }
            foreach (self::locs($res->body()) as $loc) {
                if (preg_match('~/products/([^/?#]+)~', $loc, $m)) {
                    $handles[strtolower($m[1])] = true;
                }
            }
        }
        if (! $handles) {
            throw new \RuntimeException('Kona\'s sitemap listed no products.');
        }
        return array_keys($handles);
    }

    /** The <loc> values of a sitemap. */
    public static function locs(string $xml): array
    {
        preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m);
        return array_map(fn ($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1] ?? []);
    }

    /**
     * Read the bikes that are due: never read first, then the oldest past
     * refresh_days ("Read this one" reads its bikes whatever their age). Each
     * bike is its product data, a short pause, then its page. Saved as it
     * goes, so a stopped or handed-over read never repeats a bike.
     *
     * @return array<string,array{product?:array,specs?:array,geometry?:array,read_at?:int,gone?:bool}>
     */
    private function readBikes(array $handles): array
    {
        $file  = self::storeFile();
        $saved = self::store();
        $pace  = self::pace();
        $fresh = now()->subDays($pace['refresh_days'])->timestamp;
        $deadline = $this->only ? PHP_INT_MAX : time() + (int) round(60 * max(0.01, (float) config('distributors.kona.job_minutes', 20)));

        $due = [];
        foreach ($handles as $h) {
            $at = (int) ($saved[$h]['read_at'] ?? 0);
            $stale = ! empty($saved[$h]['product']) && (int) ($saved[$h]['v'] ?? 1) < self::READER_VERSION;
            if ($this->only || empty($saved[$h]['product']) && empty($saved[$h]['gone']) || $at < $fresh || $stale) {
                $due[$h] = $at;
            }
        }
        asort($due);
        $due = array_keys($due);
        $total = count($due);

        foreach ($due as $n => $h) {
            if (time() >= $deadline) {
                $this->more = true;
                break;
            }
            $this->progress('bikes', $n, $total, $this->have($saved, $handles, $fresh));
            try {
                $res = $this->politeGet($this->base . '/products/' . rawurlencode($h) . '.json', [], 'bikes', $n, $total);
                if ($res->status() === 404) {
                    // listed in the sitemap but no longer published
                    $saved[$h] = ['gone' => true, 'read_at' => now()->timestamp];
                } elseif ($res->successful() && is_array($product = $res->json('product'))) {
                    $this->pause(2, 'bikes', $n, $total);
                    // the web page, not its data: asked for JSON, the store sends the product data
                    // here too and the page's tables never arrive
                    $page = $this->politeGet($this->base . '/products/' . rawurlencode($h), [], 'bikes', $n, $total, 0, 'text/html,application/xhtml+xml');
                    if ($page->successful() && str_contains(strtolower((string) $page->header('Content-Type')), 'html') && stripos($page->body(), '<html') !== false) {
                        $saved[$h] = ['product' => $product] + self::parsePage($page->body()) + ['read_at' => now()->timestamp, 'v' => self::READER_VERSION];
                    } else {
                        // the page did not load: keep what was read before, and do not mark the
                        // bike as read with this reader, so the next read tries the page again
                        if ($this->only) {
                            throw new \RuntimeException('The product page for "' . $h . '" did not come back as a web page (HTTP ' . $page->status() . ', ' . ($page->header('Content-Type') ?: 'no type') . '); nothing changed.');
                        }
                        $saved[$h] = ['product' => $product] + array_diff_key((array) ($saved[$h] ?? []), ['product' => 1, 'gone' => 1])
                            + ['read_at' => now()->timestamp, 'v' => (int) ($saved[$h]['v'] ?? 1)];
                    }
                    $this->recent($h, $saved[$h]);
                    if ($this->onBike && ($bike = $this->productFor($h, $saved[$h]))) {
                        try { ($this->onBike)($bike); } catch (\Throwable) { /* written again at the end of the read */ }
                    }
                } elseif ($this->only) {
                    throw new \RuntimeException('Kona\'s store answered HTTP ' . $res->status() . ' for "' . $h . '".');
                }
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'Stopped from master admin.' || $this->only) {
                    $this->saveStore($file, $saved);
                    throw $e;
                }
                // this bike keeps its last good copy; the next read tries again
            }
            if (($n + 1) % 5 === 0) {
                $this->saveStore($file, $saved);
            }
            if ($n < $total - 1) {
                $this->pause($pace['page_gap_s'], 'bikes', $n + 1, $total);
            }
        }
        $this->saveStore($file, $saved);
        if (! $this->only) {
            Cache::put(self::PAGES_KEY, ['have' => $this->have($saved, $handles, $fresh), 'of' => count($handles), 'at' => now()->timestamp], now()->addDays(60));
        }
        return $saved;
    }

    /** The last few bikes read, for the live line in master admin. */
    private function recent(string $handle, array $entry): void
    {
        $list = (array) Cache::get(self::RECENT_KEY, []);
        array_unshift($list, [
            'handle' => $handle,
            'title'  => (string) ($entry['product']['title'] ?? $handle),
            'sizes'  => count((array) ($entry['product']['variants'] ?? [])),
            'specs'  => count((array) ($entry['specs'] ?? [])),
            'images' => count((array) ($entry['product']['images'] ?? [])),
            'at'     => now()->timestamp,
        ]);
        Cache::put(self::RECENT_KEY, array_slice($list, 0, 6), now()->addDay());
    }

    private function have(array $saved, array $handles, int $fresh): int
    {
        $n = 0;
        foreach ($handles as $h) {
            if ((int) ($saved[$h]['read_at'] ?? 0) >= $fresh
                && (! empty($saved[$h]['gone']) || (int) ($saved[$h]['v'] ?? 1) >= self::READER_VERSION)) {
                $n++;
            }
        }
        return $n;
    }

    /** Products read so far, without touching Kona's site. */
    private function stored(): array
    {
        return array_values(array_filter(array_map(fn ($e) => $e['product'] ?? null, self::store())));
    }

    private static function storeFile(): string
    {
        return storage_path('app/distributors/kona-bikes.json');
    }

    private static function store(): array
    {
        $f = self::storeFile();
        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    /** Wait between requests, in one-second steps so Stop is honoured. */
    private function pause(int $seconds, string $phase, int $done, int $total, int $read = 0): void
    {
        for ($t = 0; $t < $seconds; $t++) {
            $this->stopIfAsked();
            sleep(1);
        }
    }

    /**
     * How gently the store is read: set in master admin (Brand suppliers),
     * falling back to config.
     *
     * @return array{page_gap_s:int,refresh_days:int}
     */
    public static function pace(): array
    {
        $saved = (array) ((\App\Models\PlatformSettings::current()->distribution_settings ?? [])['brand_read'][self::CODE] ?? []);
        $num = fn ($k, $def, $min, $max) => max($min, min($max, (int) ($saved[$k] ?? config('distributors.kona.' . $k, $def))));
        return [
            'page_gap_s'    => $num('page_gap_s', 10, 1, 600),
            'refresh_days'  => $num('refresh_days', 30, 1, 365),
        ];
    }

    private function saveStore(string $file, array $saved): void
    {
        // the page cache of the first reader, replaced by this store
        $old = storage_path('app/distributors/kona-pages.json');
        if (is_file($old)) {
            @unlink($old);
        }
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, json_encode($saved), LOCK_EX);
    }

    /**
     * The spec table (table.newspec: label | value rows) and the geometry
     * table (in #kro_geo: a "Size" header row, then label + one value per
     * size) from a product page.
     *
     * @return array{specs:array<int,array{Name:string,Value:string}>,geometry:array<string,array<string,string>>}
     */
    public static function parsePage(string $html): array
    {
        $out = ['specs' => [], 'geometry' => [], 'sizing_tables' => [], 'story' => null];
        if (trim($html) === '') {
            return $out;
        }
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($dom);
        $clean = fn ($s) => trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string) $s)) ?? '');

        // Two layouts: newer bikes use table.newspec (label | value cells);
        // older ones a plain table inside #kro_specs with td.label / td.data.
        $rows = $xp->query('//*[@id="kro_specs"]//table//tr | //table[contains(concat(" ", normalize-space(@class), " "), " newspec ")]//tr') ?: [];
        $seen = [];
        foreach ($rows as $tr) {
            $label = $xp->query('./td[contains(concat(" ", normalize-space(@class), " "), " label ")]', $tr);
            $data  = $xp->query('./td[contains(concat(" ", normalize-space(@class), " "), " data ")]', $tr);
            if ($label && $label->length && $data && $data->length) {
                $name = $clean($label->item(0)->textContent);
                $val  = $clean($data->item(0)->textContent);
            } else {
                $tds = $xp->query('./td', $tr);
                if (! $tds || $tds->length < 2) {
                    continue;
                }
                $name = $clean($tds->item(0)->textContent);
                $val  = $clean($tds->item(1)->textContent);
            }
            $key = mb_strtolower($name);
            if ($name !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $out['specs'][] = ['Name' => $name, 'Value' => $val];
            }
        }

        $sizes = [];
        foreach ($xp->query('//*[@id="kro_geo"]//table//tr') ?: [] as $tr) {
            $label = $xp->query('./td[contains(concat(" ", normalize-space(@class), " "), " label ")]', $tr);
            $data  = $xp->query('./td[contains(concat(" ", normalize-space(@class), " "), " data ")]', $tr);
            if (! $label || $label->length === 0 || ! $data) {
                continue;
            }
            $name = $clean($label->item(0)->textContent);
            $vals = [];
            foreach ($data as $td) {
                $vals[] = $clean($td->textContent);
            }
            if (mb_strtolower($name) === 'size') {
                $sizes = $vals;
                continue;
            }
            if ($sizes && $name !== '') {
                foreach ($vals as $i => $v) {
                    if (isset($sizes[$i])) {
                        $out['geometry'][$name][$sizes[$i]] = $v;
                    }
                }
            }
        }

        // MARKER-PRODUCT-DETAILS: Kona's shared sizing chart (one table per riding style, a column
        // per model family) and the bike's story section (heading, text, captioned photos).
        $cls = fn ($c) => 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
        foreach ($xp->query('//table[' . $cls('sizing_chart') . ']') ?: [] as $tbl) {
            $title = $xp->query('ancestor::div[starts-with(@class,"bike_sizing_chart")][1]/h4', $tbl);
            $heads = [];
            foreach ($xp->query('.//thead//th', $tbl) ?: [] as $th) {
                $heads[] = $clean($th->textContent);
            }
            $rows = [];
            foreach ($xp->query('.//tbody//tr', $tbl) ?: [] as $tr) {
                $cells = [];
                foreach ($xp->query('./td', $tr) ?: [] as $td) {
                    $cells[] = $clean($td->textContent);
                }
                if ($cells) {
                    $rows[] = $cells;
                }
            }
            if ($heads && $rows) {
                $out['sizing_tables'][] = ['title' => $title && $title->length ? $clean($title->item(0)->textContent) : '', 'heads' => $heads, 'rows' => $rows];
            }
        }

        $page = $xp->query('//div[' . $cls('tech-page') . ']')->item(0);
        if ($page) {
            $heading = $xp->query('//p[' . $cls('techtitle') . ']')->item(0);
            $blocks = [];
            $img = function ($node) use ($xp, $clean) {
                $src = $node->getAttribute('src');
                if ($src === '' || ! str_starts_with($src, 'https://')) {
                    return null;
                }
                $cap = $xp->query('following-sibling::p[contains(@class,"tech-caption")][1]', $node);
                return ['src' => $src, 'caption' => $cap && $cap->length ? $clean($cap->item(0)->textContent) : ''];
            };
            foreach ($page->childNodes as $n) {
                if (! $n instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($n->nodeName);
                if ($tag === 'p' && $clean($n->textContent) !== '') {
                    $blocks[] = ['t' => 'p', 'text' => $clean($n->textContent)];
                } elseif (in_array($tag, ['h2', 'h3', 'h4', 'h5'], true) && $clean($n->textContent) !== '') {
                    $blocks[] = ['t' => 'h', 'text' => $clean($n->textContent)];
                } elseif ($tag === 'img' && ($i = $img($n))) {
                    $blocks[] = ['t' => 'imgs', 'items' => [$i]];
                } elseif ($tag === 'div') {
                    foreach ($xp->query('.//div[' . $cls('tech-row') . ']|.//p[' . $cls('fw') . ']', $n) ?: [] as $row) {
                        if (strtolower($row->nodeName) === 'p') {
                            $blocks[] = ['t' => 'p', 'text' => $clean($row->textContent)];
                            continue;
                        }
                        $items = [];
                        foreach ($xp->query('.//img', $row) ?: [] as $im) {
                            if ($i = $img($im)) {
                                $items[] = $i;
                            }
                        }
                        if ($items) {
                            $blocks[] = ['t' => 'imgs', 'items' => $items];
                        }
                    }
                }
            }
            if ($blocks) {
                $out['story'] = ['title' => $heading ? $clean($heading->textContent) : '', 'blocks' => $blocks];
            }
        }

        return $out;
    }


    /**
     * Kona's sizing chart has a column per model family ("Rove", "Libre",
     * "Sutra/Ouroboros"). The column for this bike is the one whose name its
     * title starts with, kept with the four height and inseam columns. A
     * bike no column fits gets nothing: a wrong column would be worse.
     *
     * @return array{table: string, model: string, rows: array}|null
     */
    public static function sizingFor(array $tables, string $title): ?array
    {
        $norm = fn ($x) => preg_replace('/[^a-z0-9]+/', '', strtolower($x));
        $me = $norm($title);
        $best = null;
        foreach ($tables as $t) {
            foreach ($t['heads'] as $ci => $head) {
                if ($ci < 4) {
                    continue;
                }
                foreach (explode('/', $head) as $tok) {
                    $tok = $norm($tok);
                    // "Dews" is the column for the Dew, Dew-E and the like: a plural column matches its singular too
                    foreach (array_unique([$tok, rtrim($tok, 's')]) as $try) {
                        if (strlen($try) >= 3 && str_starts_with($me, $try) && (! $best || strlen($try) > $best['len'])) {
                            $best = ['len' => strlen($try), 't' => $t, 'ci' => $ci, 'head' => $head];
                        }
                    }
                }
            }
        }
        if (! $best) {
            return null;
        }
        $rows = [];
        foreach ($best['t']['rows'] as $r) {
            $rows[] = ['height_in' => $r[0] ?? '', 'height_cm' => $r[1] ?? '', 'inseam_in' => $r[2] ?? '', 'inseam_cm' => $r[3] ?? '', 'size' => $r[$best['ci']] ?? ''];
        }
        return ['table' => (string) $best['t']['title'], 'model' => (string) $best['head'], 'rows' => $rows];
    }

    /**
     * A GET that slows down when Kona's store asks it to. Shopify answers 429
     * (too many requests) to readers that go faster than it likes; this waits
     * for as long as its Retry-After says (or 5, 15, 30, 60, 120 seconds) and
     * tries again, showing the wait on master admin's progress line.
     */
    private function politeGet(string $url, array $query, string $phase, int $done, int $total, int $read = 0, ?string $accept = null): \Illuminate\Http\Client\Response
    {
        $waits = [5, 15, 30, 60, 120];
        foreach ($waits as $i => $fallback) {
            $this->stopIfAsked();
            // no query array when there is none: an empty one makes the
            // HTTP client drop the query already in the URL (the sitemap's
            // ?from=…&to=…), and Kona answers 400
            $res = ($accept ? $this->http($accept) : $this->http())->get($url, $query ?: null);
            if (! in_array($res->status(), [429, 430, 503], true)) {
                return $res;
            }
            $after = (int) $res->header('Retry-After');
            $wait = max(1, min(300, $after > 0 ? $after : $fallback));
            Cache::put(self::PROGRESS_KEY, [
                'phase' => 'wait', 'wait' => $wait, 'attempt' => $i + 1, 'of' => count($waits),
                'was' => $phase, 'done' => $done, 'total' => $total, 'read' => $read, 'at' => now()->timestamp,
            ], now()->addHours(2));
            // wait in short steps so Stop is honoured during a long wait
            for ($t = 0; $t < $wait; $t++) {
                $this->stopIfAsked();
                sleep(1);
            }
        }
        $this->stopIfAsked();
        return ($accept ? $this->http($accept) : $this->http())->get($url, $query ?: null);
    }

    private function stopIfAsked(): void
    {
        if (Cache::get(self::STOP_KEY)) {
            $this->progress('done', 0, 0);
            throw new \RuntimeException('Stopped from master admin.');
        }
    }

    private function http(string $accept = 'application/json, text/html;q=0.9'): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'User-Agent' => (string) config('distributors.kona.user_agent', 'IntakeCatalog/1.0'),
                'Accept'     => $accept,
            ]);
    }

    /** What the read is doing, for the live line in master admin. */
    private function progress(string $phase, int $done, int $total, int $read = 0): void
    {
        Cache::put(self::PROGRESS_KEY, [
            'phase' => $phase, 'done' => $done, 'total' => $total, 'read' => $read, 'at' => now()->timestamp,
        ], now()->addHours(2));
    }
}
