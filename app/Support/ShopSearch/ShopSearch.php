<?php

namespace App\Support\ShopSearch;

use App\Models\SearchDefault;
use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantSearchRule;
use App\Models\Tenant\TenantSearchTerm;
use App\Services\Tenant\OnlineItems;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-SEARCH-V2: the one search the shop's customers use, behind the
 * dropdown and the results page, so the two can never disagree.
 *
 *   1. the whole search matches a redirect      -> go to that page
 *   2. every word must match (name, subtitle, SKU and codes, brand, category),
 *      each word together with its synonyms and its singular
 *   3. nothing? drop stop words ("case for phone"), then fix typos, and retry
 *   4. rank in PHP: exact name, name start, whole word, brand, subtitle, SKU
 *   5. the shop's pin / boost / hide rules for this exact search
 *
 * Rules a shop made keep working whatever its plan; the plan only limits making new ones.
 */
class ShopSearch
{
    public const CANDIDATES = 400;

    /** brand as shoppers see it: the shop's own, else the catalog's maker */
    public const BRAND_SQL = "COALESCE(NULLIF(tenant_inventory_items.shop_brand, ''), (SELECT pdc.manufacturer FROM platform_distributor_catalogs pdc WHERE pdc.id = tenant_inventory_items.distributor_catalog_id))";
    public const PRICE_SQL = 'COALESCE(tenant_inventory_items.shop_sell_price_cents, tenant_inventory_items.catalog_msrp_cents)';

    public function __construct(public Tenant $tenant) {}

    public static function for(Tenant $t): self
    {
        return new self($t);
    }

    public static function normalize(string $q): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($q)));
    }

    /** Words of two or more letters, lower case. */
    public static function words(string $q): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/u', self::normalize($q)), fn ($t) => mb_strlen($t) >= 2 || ctype_digit($t)));
    }

    // ---------------------------------------------------------------- vocabulary

    /** Cached for a minute: "A rule applies within a minute of saving". */
    public function vocab(): array
    {
        return Cache::remember('shop-search-vocab:' . $this->tenant->id, 60, function () {
            $syn = [];
            $stop = [];
            $add = function (string $a, string $b) use (&$syn) {
                $a = self::normalize($a); $b = self::normalize($b);
                if ($a === '' || $b === '' || $a === $b) return;
                $syn[$a][$b] = true;
                $syn[$b][$a] = true; // both ways: "tv" finds "television" and back
            };

            $off = [];
            try {
                $off = DB::table('tenant_search_default_offs')->where('tenant_id', $this->tenant->id)->pluck('default_id')->all();
                $defaults = SearchDefault::where('enabled', true)->whereNotIn('id', $off)->get();
            } catch (\Throwable $e) {
                $defaults = collect(TenantSearchRule::SEED_SYNONYMS)->map(fn ($to, $from) => (object) ['type' => 'synonym', 'from_term' => $from, 'to_value' => $to]);
            }

            $own = TenantSearchRule::where('tenant_id', $this->tenant->id)->where('enabled', true)
                ->whereIn('type', ['synonym', 'stop'])->get(['type', 'from_term', 'to_value']);
            // the shop's own synonym for a word wins over Intake's default for that word
            $ownWords = $own->where('type', 'synonym')->map(fn ($r) => self::normalize($r->from_term))->all();

            foreach ($defaults as $d) {
                if ($d->type === 'stop') { $stop[self::normalize($d->from_term)] = true; continue; }
                if (in_array(self::normalize($d->from_term), $ownWords, true)) continue;
                $add($d->from_term, (string) $d->to_value);
            }
            foreach ($own as $r) {
                if ($r->type === 'stop') {
                    foreach (preg_split('/\s*,\s*/', (string) $r->from_term) as $w) if ($w !== '') $stop[self::normalize($w)] = true;
                    continue;
                }
                $add($r->from_term, (string) $r->to_value);
            }

            return ['syn' => array_map('array_keys', $syn), 'stop' => array_keys($stop)];
        });
    }

    public static function forget(Tenant $t): void
    {
        Cache::forget('shop-search-vocab:' . $t->id);
    }

    /** One word and everything it may also mean. */
    private function alternatives(string $w): array
    {
        $alts = [$w];
        foreach ($this->vocab()['syn'][$w] ?? [] as $s) $alts[] = $s;
        // plurals: "chargers" also finds "charger"
        if (mb_strlen($w) > 3 && str_ends_with($w, 's') && ! str_ends_with($w, 'ss')) {
            $alts[] = str_ends_with($w, 'ies') ? mb_substr($w, 0, -3) . 'y' : mb_substr($w, 0, -1);
        }
        return array_values(array_unique($alts));
    }

    /** Multi-word synonyms ("wireless charger") are matched as a phrase. */
    private function groups(array $words): array
    {
        $q = implode(' ', $words);
        // a synonym for the whole phrase ("wireless charging" -> "wireless charger")
        if (count($words) > 1 && ! empty($this->vocab()['syn'][$q])) {
            return [array_values(array_unique(array_merge([$q], $this->vocab()['syn'][$q])))];
        }
        return array_map(fn ($w) => $this->alternatives($w), $words);
    }

    // ---------------------------------------------------------------- matching

    /** Items the shop sells online, with the shop's "hide out of stock in search" choice. */
    public function base(bool $applyHideOut = true): Builder
    {
        $q = OnlineItems::query($this->tenant);
        if ($applyHideOut && SearchConfig::display($this->tenant)['hide_out']) {
            $q->where(fn ($w) => $w->where('tenant_inventory_items.computed_stock_count', '>', 0)->orWhere('tenant_inventory_items.allow_oversell', true));
        }
        return $q;
    }

    /** AND across the groups, OR inside one group. */
    public function constrain(Builder $q, array $groups): Builder
    {
        $tid = $this->tenant->id;
        foreach ($groups as $alts) {
            $q->where(function ($g) use ($alts, $tid) {
                foreach ($alts as $a) {
                    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $a) . '%';
                    $g->orWhereRaw("LOWER(CONCAT_WS(' ', tenant_inventory_items.name, tenant_inventory_items.display_subtitle, tenant_inventory_items.sku, tenant_inventory_items.catalog_upc, tenant_inventory_items.catalog_ean, tenant_inventory_items.catalog_mpn, tenant_inventory_items.shop_brand)) LIKE ?", [$like])
                      ->orWhereIn('tenant_inventory_items.distributor_catalog_id', fn ($s) => $s->select('id')->from('platform_distributor_catalogs')->whereRaw('LOWER(manufacturer) LIKE ?', [$like]))
                      ->orWhereIn('tenant_inventory_items.category_id', fn ($s) => $s->select('id')->from('tenant_inventory_categories')->where('tenant_id', $tid)->whereNull('deleted_at')->whereRaw('LOWER(name) LIKE ?', [$like]));
                }
            });
        }
        return $q;
    }

    /**
     * Run a search.
     *
     * @return array{q:string, words:array, groups:array, corrected:?string, redirect:?array, ids:array, capped:bool, highlight:array}
     */
    public function run(string $query, bool $literal = false): array
    {
        $q = self::normalize($query);
        $out = ['q' => $q, 'words' => [], 'groups' => [], 'corrected' => null, 'redirect' => null, 'ids' => [], 'capped' => false, 'highlight' => [], 'cats' => []];
        if (mb_strlen($q) < 2) return $out;

        if (! $literal && ($rd = TenantSearchRule::redirectFor($this->tenant->id, $q))) {
            $out['redirect'] = ['label' => $rd->label ?: $rd->to_value, 'url' => $rd->to_value];
            return $out;
        }

        $words = self::words($q);
        if (! $words) return $out;

        $fetch = function (array $groups) {
            return $this->constrain($this->base(), $groups)
                ->with(['distributorCatalog:id,manufacturer'])
                ->limit(self::CANDIDATES + 1)
                ->get(['tenant_inventory_items.id', 'name', 'display_subtitle', 'sku', 'catalog_upc', 'shop_brand', 'distributor_catalog_id', 'computed_stock_count', 'category_id']);
        };

        $groups = $literal ? array_map(fn ($w) => [$w], $words) : $this->groups($words);
        $rows = $fetch($groups);

        if ($rows->isEmpty() && ! $literal) {
            // stop words only count when they are what stops a match
            $stop = $this->vocab()['stop'];
            $kept = array_values(array_filter($words, fn ($w) => ! in_array($w, $stop, true)));
            if ($kept && count($kept) < count($words)) {
                $words  = $kept;
                $groups = $this->groups($words);
                $rows   = $fetch($groups);
            }
        }

        if ($rows->isEmpty() && ! $literal && SearchConfig::can($this->tenant, 'typo')) {
            $fixed = array_map(fn ($w) => TenantSearchTerm::correct($this->tenant->id, $w) ?? $w, $words);
            if ($fixed !== $words) {
                $try = $fetch($this->groups($fixed));
                if ($try->isNotEmpty()) {
                    $out['corrected'] = implode(' ', $fixed);
                    $words  = $fixed;
                    $groups = $this->groups($fixed);
                    $rows   = $try;
                }
            }
        }

        $out['words']  = $words;
        $out['groups'] = $groups;
        $out['highlight'] = array_values(array_unique(array_merge(...array_map(fn ($g) => $g, $groups ?: [[]]))));
        $out['capped'] = $rows->count() > self::CANDIDATES;
        $rows = $rows->take(self::CANDIDATES);

        $raw = implode(' ', $words);
        $scored = $rows->map(function ($i) use ($groups, $raw) {
            $name  = mb_strtolower((string) $i->name);
            $sub   = mb_strtolower((string) $i->display_subtitle);
            $brand = mb_strtolower((string) ($i->shop_brand ?: $i->distributorCatalog?->manufacturer));
            $sku   = mb_strtolower($i->sku . ' ' . $i->catalog_upc);
            $wordsIn = preg_split('/[^\p{L}\p{N}]+/u', $name);
            $s = 0;
            if ($name === $raw) $s += 100;
            elseif (str_starts_with($name, $raw)) $s += 60;
            elseif (str_contains($name, $raw)) $s += 30;
            foreach ($groups as $alts) {
                $best = 0;
                foreach ($alts as $k => $t) {
                    $w = $k === 0 ? 1 : 0.8; // the word typed beats its synonym
                    if (in_array($t, $wordsIn, true))  $best = max($best, 20 * $w);
                    elseif (str_contains($name, $t))   $best = max($best, 10 * $w);
                    elseif (str_contains($brand, $t))  $best = max($best, 8 * $w);
                    elseif (str_contains($sub, $t))    $best = max($best, 7 * $w);
                    elseif (str_contains($sku, $t))    $best = max($best, 6 * $w);
                    else                               $best = max($best, 3 * $w); // category name
                }
                $s += $best;
            }
            if ((int) $i->computed_stock_count > 0) $s += 3;
            return ['id' => (string) $i->id, 's' => $s];
        })->sortByDesc('s')->values();

        $ids = $this->applyRules($q, $out['corrected'], $scored->all());
        $out['ids'] = $ids;
        // which categories the matches sit in, most first (the dropdown's category tiles)
        $catOf = $rows->mapWithKeys(fn ($i) => [(string) $i->id => $i->category_id ? (string) $i->category_id : null])->all();
        $cats = [];
        foreach ($ids as $id) if (! empty($catOf[$id])) $cats[$catOf[$id]] = ($cats[$catOf[$id]] ?? 0) + 1;
        arsort($cats);
        $out['cats'] = $cats;
        return $out;
    }

    /** Pin, boost and hide for this exact search (or what it was corrected to). */
    private function applyRules(string $q, ?string $corrected, array $scored): array
    {
        $terms = array_values(array_unique(array_filter([$q, $corrected])));
        $rules = TenantSearchRule::where('tenant_id', $this->tenant->id)->where('enabled', true)
            ->whereIn('type', ['pin', 'boost', 'hide'])->whereNotNull('item_id')
            ->where(function ($w) use ($terms) { foreach ($terms as $t) $w->orWhereRaw('LOWER(from_term) = ?', [$t]); })
            ->orderBy('created_at')->get();
        if ($rules->isEmpty()) return array_column($scored, 'id');

        $hide  = $rules->where('type', 'hide')->pluck('item_id')->map(fn ($x) => (string) $x)->all();
        $boost = $rules->where('type', 'boost')->pluck('item_id')->map(fn ($x) => (string) $x)->all();
        foreach ($scored as &$r) if (in_array($r['id'], $boost, true)) $r['s'] += 40;
        unset($r);
        usort($scored, fn ($a, $b) => $b['s'] <=> $a['s']);
        $ids = array_values(array_filter(array_column($scored, 'id'), fn ($id) => ! in_array($id, $hide, true)));

        // pinned items go first, in the order they were pinned, even if the words do not match them
        $pins = $rules->where('type', 'pin')->pluck('item_id')->map(fn ($x) => (string) $x)->reject(fn ($id) => in_array($id, $hide, true))->values()->all();
        if ($pins) {
            $visible = $this->base()->whereIn('tenant_inventory_items.id', $pins)->pluck('tenant_inventory_items.id')->map(fn ($x) => (string) $x)->all();
            $pins = array_values(array_filter($pins, fn ($id) => in_array($id, $visible, true)));
            $ids = array_values(array_unique(array_merge($pins, $ids)));
        }
        return $ids;
    }

    // ---------------------------------------------------------------- categories

    /** id => [name, parent_id, image] for every category, cached briefly. */
    public function categories(): array
    {
        return Cache::remember('shop-search-cats:' . $this->tenant->id, 60, function () {
            return TenantInventoryCategory::where('tenant_id', $this->tenant->id)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'image_url'])
                ->mapWithKeys(fn ($c) => [(string) $c->id => ['id' => (string) $c->id, 'name' => $c->name, 'parent' => $c->parent_id ? (string) $c->parent_id : null, 'image' => $c->image_url]])
                ->all();
        });
    }

    /** A category and everything under it. */
    public function withChildren(array $ids): array
    {
        $cats = $this->categories();
        $out = [];
        $walk = function ($id) use (&$walk, &$out, $cats) {
            if (isset($out[$id])) return;
            $out[$id] = true;
            foreach ($cats as $c) if ($c['parent'] === $id) $walk($c['id']);
        };
        foreach ($ids as $id) $walk((string) $id);
        return array_keys($out);
    }

    /** A category's picture: its own, else the first photo of an item in it. */
    public function categoryImage(string $catId): ?string
    {
        $c = $this->categories()[$catId] ?? null;
        if (! $c) return null;
        if ($c['image']) return $c['image'];
        return Cache::remember('shop-search-catimg:' . $this->tenant->id . ':' . $catId, 600, function () use ($catId) {
            $items = $this->base(false)->whereIn('category_id', $this->withChildren([$catId]))
                ->orderByDesc('computed_stock_count')->limit(6)->get();
            foreach ($items as $it) {
                if ($u = $it->primaryImageUrl()) return $u;
            }
            return null;
        });
    }

    // ---------------------------------------------------------------- promotions

    /** Showing promotions, cached for a minute. */
    public function promotionsLive()
    {
        return Cache::remember('shop-search-promos:' . $this->tenant->id, 60, function () {
            try {
                return \App\Models\Tenant\TenantSearchPromotion::where('tenant_id', $this->tenant->id)->where('enabled', true)
                    ->orderByDesc('created_at')->get()->filter(fn ($p) => $p->status() === 'showing')->values();
            } catch (\Throwable $e) {
                return collect();
            }
        });
    }

    /**
     * Promotions for these words or categories in one place. Close spellings count:
     * "chargr" finds a promotion set for "charger".
     */
    public function promotions(array $words, array $catIds, string $place, int $limit = 1)
    {
        $cats = $catIds ? $this->withChildren($catIds) : [];
        return $this->promotionsLive()->filter(function ($p) use ($words, $cats, $place) {
            if (! $p->places($place)) return false;
            foreach ((array) $p->category_ids as $c) if (in_array((string) $c, $cats, true)) return true;
            foreach ((array) $p->keywords as $k) {
                $k = self::normalize((string) $k);
                if ($k === '') continue;
                foreach ($words as $w) {
                    if ($w === $k || (mb_strlen($w) >= 3 && (str_starts_with($k, $w) || str_starts_with($w, $k)))) return true;
                    if (mb_strlen($w) >= 5 && levenshtein($w, $k) <= 1) return true;
                }
                if (str_contains(implode(' ', $words), $k) && str_contains($k, ' ')) return true;
            }
            return false;
        })->take($limit)->values();
    }

    /** Count a promotion as seen once per visit. */
    public static function seen($promos): void
    {
        if (! app()->bound('session')) return;
        $seen = (array) session('shop_search_seen', []);
        foreach ($promos as $p) {
            if (! $p || isset($seen[$p->id])) continue;
            $seen[$p->id] = 1;
            try { $p->newQuery()->whereKey($p->id)->increment('seen_count'); } catch (\Throwable $e) {}
        }
        session(['shop_search_seen' => array_slice($seen, -40, null, true)]);
    }

    public static function promoArray($p): ?array
    {
        if (! $p) return null;
        return ['id' => (string) $p->id, 'title' => $p->title, 'sub' => $p->subtitle, 'img' => $p->image_url, 'url' => $p->clickUrl(), 'button' => $p->button_label ?: 'Shop the offer'];
    }

    // ---------------------------------------------------------------- popular

    /** Popular searches: the shop's top searches that found something, or a list it chose. */
    public function popular(int $n = 6): array
    {
        $d = SearchConfig::display($this->tenant);
        if ($d['popular_mode'] === 'list' && $d['popular_list']) return array_slice($d['popular_list'], 0, $n);
        return Cache::remember('shop-search-popular:' . $this->tenant->id, 600, function () use ($n) {
            return DB::table('tenant_search_queries')->where('tenant_id', $this->tenant->id)
                ->where('created_at', '>=', now()->subDays(30))->where('results_count', '>', 0)
                ->selectRaw('LOWER(query) AS q, COUNT(*) AS n')->groupByRaw('LOWER(query)')
                ->orderByDesc('n')->limit($n)->pluck('q')->all();
        });
    }

    /** Ways to finish what the shopper is typing. */
    public function suggestions(string $q, int $n = 4): array
    {
        $q = self::normalize($q);
        $words = self::words($q);
        if (! $words) return [];
        $last = end($words);
        $head = implode(' ', array_slice($words, 0, -1));
        $out = [];
        foreach ($this->popular(20) as $p) {
            if ($p !== $q && str_starts_with($p, $q)) $out[] = $p;
        }
        try {
            $terms = TenantSearchTerm::where('tenant_id', $this->tenant->id)->where('term', 'like', $last . '%')
                ->where('term', '!=', $last)->orderByDesc('freq')->limit(8)->pluck('term');
            foreach ($terms as $t) $out[] = trim($head . ' ' . $t);
        } catch (\Throwable $e) {}
        $out = array_values(array_unique($out));
        return array_slice($out ?: [$q], 0, $n);
    }

    /** The first published page with a contact form, for "Ask us". */
    public function contactUrl(): ?string
    {
        return Cache::remember('shop-search-contact:' . $this->tenant->id, 600, function () {
            try {
                $page = \App\Models\Tenant\TenantPage::where('tenant_id', $this->tenant->id)->where('is_published', true)
                    ->whereHas('sections', fn ($s) => $s->where('section_type', 'contact_form')->where('is_visible', true))->orderByDesc('is_home')->first();
                return $page ? ($page->is_home ? '/' : '/' . $page->slug) : null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    // ---------------------------------------------------------------- shaping

    /** What a card, the dropdown and quick view need about one item. */
    public static function card(TenantInventoryItem $i, array $display, ?array $supplierBacked = null): array
    {
        $imgs = collect($i->displayImages(3))->reject(fn ($x) => $x['hidden'])->pluck('url')->values()->all();
        $price = $i->effectiveSellPriceCents();
        $was = $i->compare_at_cents && $price !== null && $i->compare_at_cents > $price ? (int) $i->compare_at_cents : null;
        $stock = (int) $i->computed_stock_count;
        $state = ($stock > 0 || isset(($supplierBacked ?? [])[$i->id])) ? 'in' : ($i->allow_oversell ? 'so' : 'out');
        return [
            'id'    => (string) $i->id,
            'name'  => $i->name,
            'brand' => $i->shop_brand ?: $i->distributorCatalog?->manufacturer,
            'url'   => $i->shopPath(),
            'img'   => $imgs[0] ?? null,
            'img2'  => $display['hover_photo'] ? ($imgs[1] ?? null) : null,
            'price' => $price,
            'was'   => $was,
            'new'   => $i->created_at && $i->created_at->gt(now()->subDays(30)),
            'state' => $state,
            'stock' => max(0, $stock),
            'cat'   => $i->category_id ? (string) $i->category_id : null,
        ];
    }

    public static function money(?int $c): string
    {
        if ($c === null) return '';
        return '$' . ($c % 100 === 0 ? number_format($c / 100) : number_format($c / 100, 2));
    }
}
