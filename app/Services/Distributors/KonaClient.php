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
 *              are only on each product page, so those pages are read too,
 *              and kept until the product's updated_at changes.
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

    private string $base;
    private int $timeout;

    public function __construct(string $apiKey = '', string $region = 'us')
    {
        $this->base    = rtrim((string) config('distributors.kona.base_url', 'https://konaworld.com'), '/');
        $this->timeout = (int) config('distributors.kona.timeout', 30);
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
            $res = $this->http()->get($this->base . '/products.json', ['limit' => 1]);
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
        if (! $fresh && is_array($c = Cache::get('kona.feed'))) {
            return $c;
        }
        $size = max(1, min(250, (int) config('distributors.kona.feed_page_size', 250)));
        $max  = max(1, (int) config('distributors.kona.max_feed_pages', 40));
        $all  = [];
        for ($page = 1; $page <= $max; $page++) {
            $this->progress('feed', $page, 0);
            $res = $this->http()->get($this->base . '/products.json', ['limit' => $size, 'page' => $page]);
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
        }
        Cache::put('kona.feed', $all, now()->addHour());
        return $all;
    }

    /**
     * Spec table and geometry for each product, read from its page. A page is
     * read again only when Kona's updated_at for that product has changed;
     * the rest come from the saved copy. A page that fails keeps its last
     * good copy.
     *
     * @return array<string,array{specs:array,geometry:array}>
     */
    private function pages(array $feed): array
    {
        $file  = storage_path('app/distributors/kona-pages.json');
        $saved = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $delay = max(0, (int) config('distributors.kona.page_delay_ms', 700));

        $total = count($feed);
        $done  = 0;
        $read  = 0;
        foreach ($feed as $p) {
            $done++;
            $handle = (string) ($p['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $stamp = (string) ($p['updated_at'] ?? '');
            if (isset($saved[$handle]) && ($saved[$handle]['updated_at'] ?? null) === $stamp) {
                continue;
            }
            $this->progress('pages', $done, $total, $read);
            try {
                $res = $this->http()->get($this->base . '/products/' . rawurlencode($handle));
                if ($res->successful()) {
                    $parsed = self::parsePage($res->body());
                    $saved[$handle] = $parsed + ['updated_at' => $stamp];
                    $read++;
                    // saved as it goes, so a read that is stopped part way
                    // does not have to fetch these pages again
                    if ($read % 25 === 0) {
                        $this->savePages($file, $saved);
                    }
                }
            } catch (\Throwable) {
                // keep the last good copy; the next read tries again
            }
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
        $this->progress('pages', $total, $total, $read);

        $this->savePages($file, $saved);
        return $saved;
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

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->timeout)
            ->retry(2, 1500, throw: false)
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
