<?php

namespace App\Services\SiteHealth;

use App\Models\Tenant;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantSiteHealth;
use App\Models\Tenant\TenantSiteIssue;
use App\Support\ItemSeo;
use App\Support\Seo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * MARKER-SITE-HEALTH: checks a shop's public site and keeps the list of things to fix.
 *
 * What it reads:
 *  - every address in the shop's sitemap pages, fetched the way a search engine does
 *  - every item shown online, from the item's own record (no fetch)
 *  - the shop's own details (name, phone, address, logo)
 *
 * What it never reads: staff pages, carts, checkout, customer accounts, or anything
 * outside the shop's own site. Links that leave the site are not followed.
 *
 * It changes nothing on the site. A check runs in steps, keeps its place, and can be
 * stopped and resumed.
 */
class SiteChecker
{
    public const PAGES_PER_STEP = 4;
    public const ITEMS_PER_STEP = 300;
    public const LINKS_PER_STEP = 8;
    public const MAX_LINK_PROBES = 60;
    public const TITLE_MAX = 60;
    public const DESC_MIN = 50;
    public const DESC_MAX = 160;
    public const ITEM_WORDS_MIN = 12;
    public const WEIGHT = ['high' => 5, 'medium' => 3, 'low' => 1];

    private const GROUP_KIND = [
        'text' => 'Title', 'media' => 'Photos', 'business' => 'Business details', 'links' => 'Links',
    ];

    // ------------------------------------------------------------------ a run

    /** Begin a check from the top. */
    public function start(Tenant $tenant): TenantSiteHealth
    {
        $h = TenantSiteHealth::for($tenant->id);
        $urls = [];
        $base = rtrim($tenant->publicUrl(), '/');
        if (Seo::tenantIndexable($tenant)) {
            $pages = TenantPage::where('tenant_id', $tenant->id)->where('is_published', true)
                ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'page'))
                ->get(['id', 'title', 'slug', 'is_home'])->keyBy(fn ($p) => $p->is_home ? '/' : '/' . $p->slug);
            foreach (Seo::tenantPageUrls($tenant) as [$loc]) {
                $path = '/' . trim((string) substr($loc, strlen($base)), '/');
                $p = $pages[$path] ?? null;
                $urls[] = [$path, $p ? ($p->title ?: 'Home') : self::routeLabel($path), $p?->id];
            }
        }
        $items = Seo::storefrontOn($tenant) ? Seo::productQuery($tenant)->count() : 0;

        $prev = (array) ($h->state ?? []);
        $h->state = [
            'running' => true, 'stopped' => false, 'error' => null, 'phase' => 'pages',
            'run' => ((int) ($prev['run'] ?? 0)) + 1,
            'urls' => $urls, 'i' => 0, 'pages' => [],
            'items_total' => $items, 'items_done' => 0, 'item_cursor' => null, 'cats' => [],
            'links' => [], 'link_i' => 0, 'canon_missing' => 0, 'unreachable' => 0,
            'total' => count($urls) + $items, 'done' => 0, 'w_total' => 0,
            'started_at' => now()->toIso8601String(), 'finished_at' => null,
        ];
        $h->save();

        return $h;
    }

    /** One step. Returns true while there is more to do. */
    public function step(Tenant $tenant, TenantSiteHealth $h): bool
    {
        $st = (array) $h->state;
        if (! ($st['running'] ?? false)) {
            return false;
        }

        match ($st['phase']) {
            'pages' => $this->stepPages($tenant, $st),
            'items' => $this->stepItems($tenant, $st),
            'links' => $this->stepLinks($tenant, $st),
            default => null,
        };

        // someone may have pressed Stop while this step ran
        $fresh = (array) (TenantSiteHealth::whereKey($h->id)->value('state') ?? []);
        if (! ($fresh['running'] ?? false)) {
            $st['running'] = false;
            $st['stopped'] = true;
            $h->state = $st;
            $h->save();

            return false;
        }

        if ($st['phase'] === 'wrap') {
            $this->finish($tenant, $h, $st);

            return false;
        }
        $h->state = $st;
        $h->save();

        return true;
    }

    public function stop(TenantSiteHealth $h): void
    {
        $st = (array) $h->state;
        if ($st['running'] ?? false) {
            $st['running'] = false;
            $st['stopped'] = true;
            $h->state = $st;
            $h->save();
        }
    }

    /** Pick up a stopped check where it left off. False when there is nothing to resume. */
    public function resume(TenantSiteHealth $h): bool
    {
        $st = (array) $h->state;
        if (! ($st['stopped'] ?? false) || ($st['phase'] ?? 'wrap') === 'wrap') {
            return false;
        }
        $st['running'] = true;
        $st['stopped'] = false;
        $st['error'] = null;
        $h->state = $st;
        $h->save();

        return true;
    }

    public function fail(TenantSiteHealth $h, string $message): void
    {
        $st = (array) $h->state;
        $st['running'] = false;
        $st['stopped'] = true; // keeps its place, so Resume carries on
        $st['error'] = Str::limit($message, 200);
        $h->state = $st;
        $h->save();
    }

    public static function percent(array $st): int
    {
        $total = max(1, (int) ($st['total'] ?? 0) + count($st['links'] ?? []));
        $done = (int) ($st['done'] ?? 0) + (int) ($st['link_i'] ?? 0);

        return (int) min(100, floor($done / $total * 100));
    }

    public static function phaseWord(array $st): string
    {
        return ['pages' => 'pages', 'items' => 'items', 'links' => 'links'][$st['phase'] ?? ''] ?? 'pages';
    }

    // ---------------------------------------------------------------- phases

    private function stepPages(Tenant $tenant, array &$st): void
    {
        $base = rtrim($tenant->publicUrl(), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);
        $phones = $this->shopPhones($tenant);
        $end = min(count($st['urls']), $st['i'] + self::PAGES_PER_STEP);

        for (; $st['i'] < $end; $st['i']++) {
            [$path, $label, $pageId] = $st['urls'][$st['i']];
            $st['done']++;
            $st['w_total'] += self::WEIGHT['high']; // the page loads
            try {
                $r = Http::timeout(12)->withHeaders(['User-Agent' => 'IntakeSiteHealth/1.0'])->get($base . ($path === '/' ? '/' : $path));
            } catch (\Throwable $e) {
                $st['unreachable']++;
                $this->put($tenant, $st, 'links', 'page_unreachable', 'page', $pageId, $label, $path, 'This page did not answer when checked', 'high', []);
                continue;
            }
            if ($r->status() >= 400) {
                $this->put($tenant, $st, 'links', 'page_error', 'page', $pageId, $label, $path, 'This page shows an error to visitors (' . $r->status() . ')', 'high', ['http' => $r->status()]);
                continue;
            }
            $doc = PageReader::read((string) $r->body());
            if ($doc['canonical'] === null) {
                $st['canon_missing']++;
            }

            // titles and descriptions: only pages the shop can word itself
            if ($pageId) {
                $st['pages'][] = ['id' => $pageId, 'label' => $label, 'path' => $path, 'title' => $doc['title'], 'desc' => $doc['description']];
                $this->checkWords($tenant, $st, $pageId, $label, $path, $doc);
            }

            // links to check later, and where each was seen
            foreach ($doc['links'] as $l) {
                $p = PageReader::internalPath($l['href'], $host);
                if ($p === null) {
                    continue;
                }
                if (! isset($st['links'][$p]) && count($st['links']) >= 400) {
                    continue;
                }
                $st['links'][$p] ??= [];
                if (count($st['links'][$p]) < 6 && ! in_array([$path, $label, $pageId, $l['text']], $st['links'][$p], true)) {
                    $st['links'][$p][] = [$path, $label, $pageId, $l['text']];
                }
            }

            // a phone number on the page that is not one of the shop's own
            $st['w_total'] += self::WEIGHT['medium'];
            $odd = $phones ? array_values(array_diff_key($doc['phones'], $phones)) : [];
            if ($odd) {
                $this->put($tenant, $st, 'business', 'phone_differs', 'page', $pageId, $label, $path,
                    'A phone number here is not one of your locations\' numbers', 'medium',
                    ['found' => array_slice($odd, 0, 4), 'yours' => array_values(array_slice($phones, 0, 4))]);
            }
        }

        if ($st['i'] >= count($st['urls'])) {
            $this->wrapPages($tenant, $st);
            $st['phase'] = $st['items_total'] > 0 ? 'items' : 'links';
            if ($st['phase'] === 'links') {
                $this->prepareLinks($st);
            }
        }
    }

    private function checkWords(Tenant $tenant, array &$st, string $pageId, string $label, string $path, array $doc): void
    {
        $st['w_total'] += self::WEIGHT['low'];
        $len = mb_strlen($doc['title']);
        if ($len > self::TITLE_MAX) {
            $this->put($tenant, $st, 'text', 'title_long', 'page', $pageId, $label, $path,
                "Title is {$len} characters; search cuts it at about " . self::TITLE_MAX, 'low',
                ['field' => 'title', 'shown' => $doc['title'], 'shown_desc' => $doc['description']]);
        }

        $st['w_total'] += self::WEIGHT['high'];
        $dlen = mb_strlen($doc['description']);
        if ($dlen === 0) {
            $this->put($tenant, $st, 'text', 'desc_missing', 'page', $pageId, $label, $path,
                'No description for search results', 'high', ['field' => 'description', 'shown' => $doc['title'], 'shown_desc' => '']);
        } elseif ($dlen < self::DESC_MIN) {
            $this->put($tenant, $st, 'text', 'desc_short', 'page', $pageId, $label, $path,
                "Description is {$dlen} characters; aim for " . self::DESC_MIN . ' to ' . self::DESC_MAX, 'low',
                ['field' => 'description', 'shown' => $doc['title'], 'shown_desc' => $doc['description']]);
        } elseif ($dlen > self::DESC_MAX) {
            $this->put($tenant, $st, 'text', 'desc_long', 'page', $pageId, $label, $path,
                "Description is {$dlen} characters; search cuts it at about " . self::DESC_MAX, 'low',
                ['field' => 'description', 'shown' => $doc['title'], 'shown_desc' => $doc['description']]);
        }
    }

    /** Once every page is read: titles and descriptions that repeat across pages. */
    private function wrapPages(Tenant $tenant, array &$st): void
    {
        foreach (['title' => 'title_same', 'desc' => 'desc_same'] as $key => $code) {
            $groups = [];
            foreach ($st['pages'] as $p) {
                if (trim($p[$key]) !== '') {
                    $groups[mb_strtolower($p[$key])][] = $p;
                }
            }
            foreach ($st['pages'] as $p) {
                $st['w_total'] += self::WEIGHT['high'];
                $same = $groups[mb_strtolower($p[$key])] ?? [];
                if (trim($p[$key]) === '' || count($same) < 2) {
                    continue;
                }
                $others = array_values(array_filter($same, fn ($o) => $o['id'] !== $p['id']));
                $word = $key === 'title' ? 'Title' : 'Description';
                $this->put($tenant, $st, 'text', $code, 'page', $p['id'], $p['label'], $p['path'],
                    $word . ' is the same as ' . count($others) . ' other ' . Str::plural('page', count($others)), 'high', [
                        'field' => $key === 'title' ? 'title' : 'description',
                        'shown' => $p['title'], 'shown_desc' => $p['desc'],
                        'same' => array_map(fn ($o) => ['label' => $o['label'], 'path' => $o['path'], 'title' => $o['title']], array_slice($others, 0, 3)),
                    ]);
            }
        }
        $st['pages'] = []; // done with them; keeps the saved state small
    }

    private function stepItems(Tenant $tenant, array &$st): void
    {
        $q = Seo::productQuery($tenant)->with(['itemImages.media', 'distributorCatalog', 'category:id,name'])->orderBy('id')->limit(self::ITEMS_PER_STEP);
        if ($st['item_cursor'] !== null) {
            $q->where('id', '>', $st['item_cursor']);
        }
        // a step that died after writing its items but before saving its place is read again: clear what it wrote
        $stale = DB::table('tenant_site_issue_items')->where('tenant_id', $tenant->id)->where('run', $st['run']);
        $st['item_cursor'] !== null ? $stale->where('item_id', '>', $st['item_cursor'])->delete() : $stale->delete();
        $items = $q->get();
        $behind = []; // every item behind an item row, so the page can list all of them

        foreach ($items as $item) {
            $st['item_cursor'] = (string) $item->id;
            $st['items_done']++;
            $st['done']++;
            $cat = (string) ($item->category_id ?: '0');
            $st['cats'][$cat] ??= ['name' => $item->category?->name ?: 'No category', 'photo_n' => 0, 'photo' => [], 'words_n' => 0, 'words' => []];

            $st['w_total'] += self::WEIGHT['medium'];
            $shown = array_filter($item->displayImages(3), fn ($i) => ! ($i['hidden'] ?? false));
            if (! $shown) {
                $behind[] = ['tenant_id' => $tenant->id, 'code' => 'items_no_photo', 'cat' => $cat, 'item_id' => (string) $item->id, 'n' => 0, 'run' => $st['run']];
                $st['cats'][$cat]['photo_n']++;
                if (count($st['cats'][$cat]['photo']) < 8) {
                    $st['cats'][$cat]['photo'][] = ['id' => (string) $item->id, 'name' => Str::limit($item->name, 60)];
                }
            }

            $st['w_total'] += self::WEIGHT['low'];
            $text = ItemSeo::plain($item->seo_description) ?: (ItemSeo::plain($item->description) ?: ItemSeo::plain($item->display_subtitle));
            $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
            if ($words < self::ITEM_WORDS_MIN) {
                $behind[] = ['tenant_id' => $tenant->id, 'code' => 'items_few_words', 'cat' => $cat, 'item_id' => (string) $item->id, 'n' => min(65000, $words), 'run' => $st['run']];
                $st['cats'][$cat]['words_n']++;
                if (count($st['cats'][$cat]['words']) < 8) {
                    $st['cats'][$cat]['words'][] = ['id' => (string) $item->id, 'name' => Str::limit($item->name, 60), 'words' => $words];
                }
            }
        }

        foreach (array_chunk($behind, 200) as $chunk) {
            DB::table('tenant_site_issue_items')->insert($chunk);
        }

        if ($items->count() < self::ITEMS_PER_STEP) {
            foreach ($st['cats'] as $catId => $c) {
                if ($c['photo_n'] > 0) {
                    $this->put($tenant, $st, 'media', 'items_no_photo', 'items', $catId, 'Shop: ' . $c['name'], '/shop' . ($catId !== '0' ? '?category=' . $catId : ''),
                        $c['photo_n'] . ' ' . Str::plural('item', $c['photo_n']) . ' online ' . ($c['photo_n'] === 1 ? 'has' : 'have') . ' no photo', 'medium',
                        ['items' => $c['photo'], 'n' => $c['photo_n']], $c['photo_n'] * self::WEIGHT['medium']);
                }
                if ($c['words_n'] > 0) {
                    $this->put($tenant, $st, 'text', 'items_few_words', 'items', $catId, 'Shop: ' . $c['name'], '/shop' . ($catId !== '0' ? '?category=' . $catId : ''),
                        $c['words_n'] . ' ' . Str::plural('item', $c['words_n']) . ' ' . ($c['words_n'] === 1 ? 'has' : 'have') . ' almost no description', 'low',
                        ['items' => $c['words'], 'n' => $c['words_n']], $c['words_n'] * self::WEIGHT['low']);
                }
            }
            $st['cats'] = [];
            $st['phase'] = 'links';
            $this->prepareLinks($st);
        }
    }

    /** Keep only the links worth asking the site about: ones that are not a page already read. */
    private function prepareLinks(array &$st): void
    {
        $known = array_flip(array_map(fn ($u) => $u[0], $st['urls']));
        $todo = [];
        foreach ($st['links'] as $path => $seen) {
            if (! isset($known[$path]) && count($todo) < self::MAX_LINK_PROBES) {
                $todo[] = [$path, $seen];
            }
        }
        $st['links'] = $todo;
        $st['link_i'] = 0;
        $st['dead'] = [];
    }

    private function stepLinks(Tenant $tenant, array &$st): void
    {
        $base = rtrim($tenant->publicUrl(), '/');
        $end = min(count($st['links']), $st['link_i'] + self::LINKS_PER_STEP);
        for (; $st['link_i'] < $end; $st['link_i']++) {
            [$path, $seen] = $st['links'][$st['link_i']];
            try {
                $code = Http::timeout(10)->withHeaders(['User-Agent' => 'IntakeSiteHealth/1.0'])->get($base . $path)->status();
            } catch (\Throwable $e) {
                continue; // a slow answer is not a dead link
            }
            if ($code === 404 || $code === 410) {
                foreach ($seen as [$src, $label, $pageId, $text]) {
                    $st['dead'][$src] ??= ['label' => $label, 'id' => $pageId, 'links' => []];
                    $st['dead'][$src]['links'][] = ['to' => $path, 'text' => $text];
                }
            }
        }

        if ($st['link_i'] >= count($st['links'])) {
            foreach ($st['urls'] as [$path]) {
                $st['w_total'] += self::WEIGHT['medium'];
            }
            foreach ($st['dead'] ?? [] as $src => $d) {
                $n = count($d['links']);
                $this->put($tenant, $st, 'links', 'dead_links', 'page', $d['id'], $d['label'], $src,
                    $n . ' ' . Str::plural('link', $n) . ' ' . ($n === 1 ? 'goes' : 'go') . ' to a page that no longer exists', 'medium', ['links' => array_slice($d['links'], 0, 8)]);
            }
            $this->checkBusiness($tenant, $st);
            $st['phase'] = 'wrap';
        }
    }

    private function checkBusiness(Tenant $tenant, array &$st): void
    {
        $st['w_total'] += 3 * self::WEIGHT['high'];
        if (! Seo::tenantIndexable($tenant)) {
            $this->put($tenant, $st, 'business', 'site_hidden', 'site', 'hidden', 'Your whole site', null,
                'Search engines are told not to list your site: ' . (Seo::tenantHiddenReason($tenant) ?: 'it is not live'), 'high', [], 3 * self::WEIGHT['high']);
        }

        $loc = TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->first();
        foreach ([
            ['no_phone', 'No phone number on your main location', trim((string) $loc?->phone) === ''],
            ['no_address', 'No address on your main location', trim((string) $loc?->address_line_1) === '' || trim((string) $loc?->city) === ''],
        ] as [$code, $problem, $bad]) {
            $st['w_total'] += self::WEIGHT['high'];
            if ($bad) {
                $this->put($tenant, $st, 'business', $code, 'site', $code, 'Business details', null, $problem, 'high', ['location' => $loc?->id]);
            }
        }

        $st['w_total'] += self::WEIGHT['low'];
        if (trim((string) $tenant->logo_url) === '') {
            $this->put($tenant, $st, 'business', 'no_logo', 'site', 'no_logo', 'Business details', null, 'No logo, so search and link previews show no picture', 'low', []);
        }
    }

    private function finish(Tenant $tenant, TenantSiteHealth $h, array $st): void
    {
        TenantSiteIssue::where('tenant_id', $tenant->id)->where('run', '<', $st['run'])->delete();
        DB::table('tenant_site_issue_items')->where('tenant_id', $tenant->id)->where('run', '<', $st['run'])->delete();

        $base = rtrim($tenant->publicUrl(), '/');
        $loc = TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->first();
        $addresses = count($st['urls']) + (int) $st['items_total'];
        $h->basics = [
            ['ok' => Seo::tenantIndexable($tenant), 'yes' => 'Search engines can read your site.', 'no' => 'Search engines are told not to list your site.'],
            ['ok' => $addresses > 0, 'yes' => 'Your sitemap is current (' . number_format($addresses) . ' ' . Str::plural('address', $addresses) . ').', 'no' => 'Your sitemap is empty.'],
            ['ok' => $loc && trim((string) $loc->phone) !== '' && trim((string) $loc->address_line_1) !== '',
                'yes' => 'Your business' . ($st['items_total'] > 0 ? ' and item' : '') . ' details are marked up for search.', 'no' => 'Your business details are incomplete, so search has less to show.'],
            ['ok' => (int) $st['canon_missing'] === 0 && count($st['urls']) > 0, 'yes' => 'Every page has one address, not several.', 'no' => 'Some pages do not name their one address.'],
        ];

        $st['running'] = false;
        $st['stopped'] = false;
        $st['finished_at'] = now()->toIso8601String();
        foreach (['urls', 'links', 'dead', 'pages', 'cats'] as $k) {
            $st[$k] = [];
        }
        $h->state = $st;
        $h->checked = (int) $st['done'];
        $h->weight_total = max(1, (int) $st['w_total']);
        $h->checked_at = now();
        $h->score = self::score($tenant->id, $h->weight_total);

        $hist = array_values(array_filter((array) ($h->history ?? []), fn ($r) => ($r[0] ?? '') !== now()->toDateString()));
        $hist[] = [now()->toDateString(), $h->score];
        $h->history = array_slice($hist, -12);
        $h->save();
    }

    /** The score from what is still open: fixed and set-aside things no longer count against it. */
    public static function score(string $tenantId, int $weightTotal): int
    {
        $lost = (int) TenantSiteIssue::where('tenant_id', $tenantId)->where('status', 'open')->sum('weight');

        return (int) max(0, min(100, round(100 * (1 - $lost / max(1, $weightTotal)))));
    }

    public static function rescore(string $tenantId): void
    {
        $h = TenantSiteHealth::where('tenant_id', $tenantId)->first();
        if ($h && $h->checked_at) {
            $h->score = self::score($tenantId, (int) $h->weight_total);
            $h->save();
        }
    }

    // ---------------------------------------------------------------- helpers

    private function put(Tenant $tenant, array &$st, string $grp, string $code, string $subjectType, ?string $subjectId, string $label, ?string $path, string $problem, string $impact, array $detail, ?int $weight = null): void
    {
        $fp = sha1($code . '|' . $subjectType . '|' . $subjectId . '|' . $path);
        $row = TenantSiteIssue::firstOrNew(['tenant_id' => $tenant->id, 'fingerprint' => $fp]);
        $kind = match (true) {
            $code === 'title_long' || $code === 'title_same' => 'Title',
            str_starts_with($code, 'desc_') || $code === 'items_few_words' => 'Description',
            default => self::GROUP_KIND[$grp],
        };
        $row->fill([
            'grp' => $grp, 'code' => $code, 'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'label' => Str::limit($label, 180), 'path' => $path, 'problem' => Str::limit($problem, 250), 'impact' => $impact,
            'weight' => $weight ?? self::WEIGHT[$impact], 'detail' => $detail, 'run' => $st['run'],
        ]);
        // found again after a fix: it is open again. Set aside by the shop: stays set aside.
        if (! $row->exists || $row->status === 'fixed') {
            $row->status = 'open';
            $row->undo = null;
        }
        $row->save();
    }

    /** digits => as written, for every active location's phone. */
    private function shopPhones(Tenant $tenant): array
    {
        $out = [];
        foreach (TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->pluck('phone') as $p) {
            $d = preg_replace('/\D+/', '', (string) $p);
            if (strlen($d) >= 7) {
                $out[$d] = (string) $p;
                $out[ltrim($d, '1')] = (string) $p; // with and without the country's leading 1
                $out['1' . ltrim($d, '1')] = (string) $p;
            }
        }

        return $out;
    }

    private static function routeLabel(string $path): string
    {
        return ['/book' => 'Booking', '/shop' => 'Shop', '/rentals' => 'Rentals', '/gift-cards' => 'Gift cards', '/classes' => 'Classes'][$path]
            ?? Str::headline(trim($path, '/'));
    }

    // ------------------------------------------------------ suggestions, fixes

    /**
     * A title built by rule from the page's own name, the business and its city.
     * Null when there is nothing better to offer than what is there.
     */
    public static function suggestTitle(Tenant $tenant, TenantPage $page, string $code, array $detail): ?string
    {
        $suffix = ' — ' . $tenant->name; // what the public page adds after the title
        $room = self::TITLE_MAX - mb_strlen($suffix);
        $city = trim((string) TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->value('city'));
        $name = trim((string) $page->title);
        $now = trim((string) ($page->meta_title ?: $page->title));

        if ($code === 'title_long') {
            $try = $room >= 12 ? ItemSeo::cut($now, $room) : null;

            if (! $try || $try === $now) {
                return null;
            }
            // don't leave it hanging on a joining word ("…forks and rear" reads cut off; "…forks" does not)
            $try = rtrim($try, " \t…-–—|,");
            $try = (string) preg_replace('/(\s+(and|or|for|the|of|in|a|an|to|with|including|plus|&)\b[^\s]*(\s+\S+)?)$/iu', '', $try, 1) ?: $try;
            $words = preg_split('/\s+/u', $try);

            return count($words) >= 2 ? rtrim($try, " \t-–—|,") : null;
        }
        if ($code === 'title_same') {
            $taken = array_map(fn ($o) => mb_strtolower((string) ($o['title'] ?? '')), $detail['same'] ?? []);
            foreach (array_filter([$city !== '' ? "{$name} in {$city}" : null, $name]) as $try) {
                if (mb_strtolower($try) !== mb_strtolower($now) && ! in_array(mb_strtolower($try . $suffix), $taken, true) && mb_strlen($try) <= max(20, $room)) {
                    return $try;
                }
            }
        }

        return null;
    }
}
