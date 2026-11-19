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

    /** Set by the Stop button; the read stops before its next request. */
    public const STOP_KEY = 'kona.catalog_stop';

    private string $base;
    private int $timeout;

    /** @var string[] when set, only these products are read (handles), straight from their own pages */
    private array $only = [];

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

    public function name(): string { return 'Kona'; }

    /** No login: the catalog is public. */
    public static function needsKey(): bool { return false; }

    /**
     * Whether shops can use this source yet. The shop side (stock file in the
     * Kona box) is a later build, so shops switched on in master admin do not
     * see a Kona box until it ships.
     */
    public static function shopSideReady(): bool { return false; }

    public function testConnection(): array
    {
        try {
            $res = $this->politeGet($this->base . '/products.json', ['limit' => 1], 'test', 0, 0);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'auth' => false, 'body' => 'Kona\'s site did not answer: ' . $e->getMessage()];
        }
        $n = count((array) ($res->json('products') ?? []));
        $ok = $res->successful() && $n > 0;
        return [
            'ok' => $ok, 'status' => $res->status(), 'auth' => false,
            'body' => $ok ? 'Kona\'s product feed answered.' : 'Kona\'s product feed answered HTTP ' . $res->status() . ' with no products.',
        ];
    }

    public function brands(): array
    {
        return [['id' => 'Kona', 'name' => 'Kona']];
    }

    public function categories(): array
    {
        $out = [];
        foreach ($this->feed() as $p) {
            $c = $this->categoryTag($p);
            if ($c !== '') {
                $out[$c] = ['id' => $c, 'name' => $c];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Every published Kona bike, one variant per size. Filters (upcs,
     * vendorParts) narrow to matching part numbers without reading pages for
     * the rest.
     */
    public function products(array $opts = []): array
    {
        $parts = array_flip(array_map(fn ($p) => strtoupper(trim((string) $p)), $opts['vendorParts'] ?? []));

        $feed = $this->feed(true);
        if ($parts) {
            $feed = array_values(array_filter($feed, function ($p) use ($parts) {
                foreach ((array) ($p['variants'] ?? []) as $v) {
                    if (isset($parts[strtoupper((string) ($v['sku'] ?? ''))])) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $pages = $this->pages($feed);

        $out = [];
        foreach ($feed as $p) {
            $handle = (string) ($p['handle'] ?? '');
            $page   = $pages[$handle] ?? ['specs' => [], 'geometry' => []];
            $rows   = [];
            foreach ((array) ($p['variants'] ?? []) as $v) {
                $sku = strtoupper(trim((string) ($v['sku'] ?? '')));
                if ($sku === '' || ($parts && ! isset($parts[$sku]))) {
                    continue;
                }
                $rows[] = $this->row($p, $v, $page);
            }
            if ($rows) {
                $out[] = [
                    'Brand'        => 'Kona',
                    'product_code' => $handle,
                    'Name'         => (string) ($p['title'] ?? ''),
                    'Variants'     => $rows,
                ];
            }
        }
        $this->progress('done', count($out), count($out));
        return ['Products' => $out, 'Failures' => []];
    }

    /** Cost and stock come from each dealer's stock file (shop side). */
    public function inventory(array $skus): array { return []; }

    public function prices(array $skus): array { return []; }

    public function images(array $skus): array
    {
        $want = array_flip(array_map(fn ($s) => strtoupper((string) $s), $skus));
        $out = [];
        foreach ($this->feed() as $p) {
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
            'tags'             => array_values((array) ($p['tags'] ?? [])),
            'model_year'       => $year,
            'images'           => array_values(array_filter(array_map(fn ($i) => (string) ($i['src'] ?? ''), (array) ($p['images'] ?? [])))),
            'specs'            => $specs,
            'geometry'         => $geo,
            'paint_color'      => $this->spec($specs, 'paint color'),
            'url'              => $this->base . '/products/' . ($p['handle'] ?? ''),
            'DateLastModified' => $p['updated_at'] ?? null,
        ];
    }

    /** "2027a" in the tags is the model year. */
    private function year(array $p): ?int
    {
        foreach ((array) ($p['tags'] ?? []) as $t) {
            if (preg_match('/^(20\d{2})[a-z]?$/i', trim((string) $t), $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Kona's riding category ("Drop Bar / Gravel") among the tags: the first
     * that is not the year, not the product type, not the model family and
     * not one of the store's own markers. The field map can rename it.
     */
    private function categoryTag(array $p): string
    {
        $type   = mb_strtolower(trim((string) ($p['product_type'] ?? '')));
        $family = mb_strtolower(strtok(trim((string) ($p['title'] ?? '')), ' ') ?: '');
        foreach ((array) ($p['tags'] ?? []) as $t) {
            $t = trim((string) $t);
            $l = mb_strtolower($t);
            if ($t === '' || preg_match('/^20\d{2}[a-z]?$/i', $t)) continue;
            if ($l === $type || $l === $type . 's' || rtrim($l, 's') === $type) continue;
            if ($family !== '' && $l === $family) continue;
            if (str_contains($l, 'product')) continue;
            return $t;
        }
        return '';
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
     * The whole product list from /products.json, page by page. Kept for an
     * hour so the image and category reads don't fetch it again; a catalog
     * sync always reads it fresh.
     */
    private function feed(bool $fresh = false): array
    {
        if ($this->only) {
            $out = [];
            foreach ($this->only as $i => $handle) {
                $this->progress('feed', $i + 1, count($this->only));
                $res = $this->politeGet($this->base . '/products/' . rawurlencode($handle) . '.json', [], 'feed', $i + 1, count($this->only));
                if (! $res->successful()) {
                    throw new \RuntimeException('Kona\'s store answered HTTP ' . $res->status() . ' for "' . $handle . '".');
                }
                if (is_array($p = $res->json('product'))) {
                    $out[] = $p;
                }
                if ($i < count($this->only) - 1) {
                    $this->pause(self::pace()['page_gap_s'], 'feed', $i + 1, count($this->only));
                }
            }
            return $out;
        }
        if (! $fresh && is_array($c = Cache::get('kona.feed'))) {
            return $c;
        }
        $size = max(1, min(250, (int) config('distributors.kona.feed_page_size', 250)));
        $max  = max(1, (int) config('distributors.kona.max_feed_pages', 40));
        $all  = [];
        for ($page = 1; $page <= $max; $page++) {
            $this->progress('feed', $page, 0);
            $res = $this->politeGet($this->base . '/products.json', ['limit' => $size, 'page' => $page], 'feed', $page, 0);
            if (! $res->successful()) {
                throw new \RuntimeException('Kona\'s product feed answered HTTP ' . $res->status() . ' on page ' . $page . '.');
            }
            $batch = (array) ($res->json('products') ?? []);
            if (! $batch) {
                break;
            }
            array_push($all, ...$batch);
            if (count($batch) < $size) {
                break;
            }
            $this->pause(self::pace()['list_gap_s'], 'feed', $page, 0);
        }
        Cache::put('kona.feed', $all, now()->addHour());
        return $all;
    }

    /**
     * Spec table and geometry for each product, read from its page. A page is
     * read once and kept; it is read again after the refresh period. Each run
     * reads at most pages_per_run, spaced page_gap_s apart, so the first full
     * pass spreads over several runs. A page that fails keeps its last good
     * copy.
     *
     * @return array<string,array{specs:array,geometry:array}>
     */
    private function pages(array $feed): array
    {
        $file  = storage_path('app/distributors/kona-pages.json');
        $saved = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $pace  = self::pace();

        // Kona's store bumps every product's updated_at constantly, so it
        // can't say what changed. A page is read once, then again only after
        // the refresh period; never-read bikes go first, then the oldest.
        // "Read this one" always reads its bike's page.
        $due = [];
        $fresh = now()->subDays($pace['refresh_days'])->timestamp;
        foreach ($feed as $p) {
            $handle = (string) ($p['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $readAt = (int) ($saved[$handle]['read_at'] ?? 0);
            if ($this->only || $readAt < $fresh) {
                $due[$handle] = $readAt;
            }
        }
        asort($due);
        $todo = array_slice(array_keys($due), 0, $this->only ? count($due) : $pace['pages_per_run']);

        $total = count($todo);
        $read  = 0;
        foreach ($todo as $n => $handle) {
            $this->progress('pages', $n, $total, $read);
            try {
                $res = $this->politeGet($this->base . '/products/' . rawurlencode($handle), [], 'pages', $n, $total, $read);
                if ($res->successful()) {
                    $saved[$handle] = self::parsePage($res->body()) + ['read_at' => now()->timestamp];
                    $read++;
                    // saved as it goes, so a read that is stopped part way
                    // does not have to fetch these pages again
                    if ($read % 10 === 0) {
                        $this->savePages($file, $saved);
                    }
                }
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'Stopped from master admin.') {
                    $this->savePages($file, $saved);
                    throw $e;
                }
                // keep the last good copy; the next read tries again
            }
            if ($n < $total - 1) {
                $this->pause($pace['page_gap_s'], 'pages', $n + 1, $total, $read);
            }
        }
        $this->progress('pages', $total, $total, $read);
        $this->savePages($file, $saved);

        // for master admin: how far the spec pages have got
        $have = 0;
        foreach ($feed as $p) {
            if ((int) ($saved[(string) ($p['handle'] ?? '')]['read_at'] ?? 0) >= $fresh) {
                $have++;
            }
        }
        Cache::put(self::PAGES_KEY, ['have' => $have, 'of' => count($feed), 'at' => now()->timestamp], now()->addDays(60));
        return $saved;
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
     * @return array{list_gap_s:int,page_gap_s:int,pages_per_run:int,refresh_days:int}
     */
    public static function pace(): array
    {
        $saved = (array) ((\App\Models\PlatformSettings::current()->distribution_settings ?? [])['brand_read'][self::CODE] ?? []);
        $num = fn ($k, $def, $min, $max) => max($min, min($max, (int) ($saved[$k] ?? config('distributors.kona.' . $k, $def))));
        return [
            'list_gap_s'    => $num('list_gap_s', 30, 1, 600),
            'page_gap_s'    => $num('page_gap_s', 10, 1, 600),
            'pages_per_run' => $num('pages_per_run', 100, 1, 1000),
            'refresh_days'  => $num('refresh_days', 30, 1, 365),
        ];
    }

    private function savePages(string $file, array $saved): void
    {
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
        $out = ['specs' => [], 'geometry' => []];
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

        foreach ($xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " newspec ")]//tr') ?: [] as $tr) {
            $tds = $xp->query('./td', $tr);
            if ($tds && $tds->length >= 2) {
                $name = $clean($tds->item(0)->textContent);
                $val  = $clean($tds->item(1)->textContent);
                if ($name !== '') {
                    $out['specs'][] = ['Name' => $name, 'Value' => $val];
                }
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
        return $out;
    }

    /**
     * A GET that slows down when Kona's store asks it to. Shopify answers 429
     * (too many requests) to readers that go faster than it likes; this waits
     * for as long as its Retry-After says (or 5, 15, 30, 60, 120 seconds) and
     * tries again, showing the wait on master admin's progress line.
     */
    private function politeGet(string $url, array $query, string $phase, int $done, int $total, int $read = 0): \Illuminate\Http\Client\Response
    {
        $waits = [5, 15, 30, 60, 120];
        foreach ($waits as $i => $fallback) {
            $this->stopIfAsked();
            $res = $this->http()->get($url, $query);
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
        return $this->http()->get($url, $query);
    }

    private function stopIfAsked(): void
    {
        if (Cache::get(self::STOP_KEY)) {
            $this->progress('done', 0, 0);
            throw new \RuntimeException('Stopped from master admin.');
        }
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'User-Agent' => (string) config('distributors.kona.user_agent', 'IntakeCatalog/1.0'),
                'Accept'     => 'application/json, text/html;q=0.9',
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
