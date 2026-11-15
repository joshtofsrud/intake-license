<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SearchDefault;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantSearchDismissal;
use App\Models\Tenant\TenantSearchPromotion;
use App\Models\Tenant\TenantSearchRule;
use App\Support\ShopSearch\SearchConfig;
use App\Support\ShopSearch\SearchReport;
use App\Support\ShopSearch\ShopSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-SEARCH-V2: Reports › Search (also in the menu under Website › Search).
 *
 *   Overview    searches, clicks, adds, purchases and revenue from search; top searches
 *   No results  searches that found nothing, each with the likeliest fix
 *   Rules       the shop's synonyms, redirects and stop words; Intake's defaults; pin, boost, hide
 *   Promotions  image + headline + link, shown for words or categories
 *   Display     what shoppers see (explicit Save)
 *
 * Plans limit making new things; what a shop already made keeps working.
 */
class SearchReportController extends Controller
{
    private const TABS = ['overview', 'none', 'rules', 'promotions', 'display'];

    private function back(string $tab, string $msg, string $kind = 'success')
    {
        return redirect()->route('tenant.reports.search', ['tab' => $tab])->with($kind, $msg);
    }

    private function canEdit(): bool
    {
        $me = Auth::guard('tenant')->user();
        return $me && $me->canAccessSection('storefront');
    }

    private function guardEdit(): void
    {
        abort_unless($this->canEdit(), 403, 'Changing search needs access to E-commerce.');
    }

    private function shopOn(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon(tenant(), 'online_store');
    }

    private function forget(): void
    {
        ShopSearch::forget(tenant());
        Cache::forget('shop-search-promos:' . tenant()->id);
        Cache::forget('shop-search-cats:' . tenant()->id);
        Cache::forget('shop-search-popular:' . tenant()->id);
    }

    public function index(Request $request)
    {
        $tenant = tenant();
        $tab  = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $days = (int) $request->query('days', 30);
        $rep  = new SearchReport($tenant, $days);

        $data = [
            'tab'      => $tab,
            'days'     => $rep->days,
            'shopOn'   => $this->shopOn(),
            'canEdit'  => $this->canEdit(),
            'can'      => collect(array_keys(SearchConfig::FEATURES))->mapWithKeys(fn ($k) => [$k => SearchConfig::can($tenant, $k)])->all(),
            'unlock'   => collect(array_keys(SearchConfig::FEATURES))->mapWithKeys(fn ($k) => [$k => SearchConfig::unlockedOn($k)])->all(),
            'zeroN'    => $rep->zeroCount(),
            'display'  => SearchConfig::display($tenant),
        ];

        if ($tab === 'overview') {
            $data['ov']  = $rep->overview();
            $data['top'] = $rep->top();
        }
        if ($tab === 'none') {
            $data['zero'] = $rep->zero();
        }
        if ($tab === 'rules' || $tab === 'overview' || $tab === 'none') {
            $data['rules'] = TenantSearchRule::where('tenant_id', $tenant->id)->with('item:id,name,sku')
                ->orderBy('type')->orderBy('from_term')->get();
            $off = DB::table('tenant_search_default_offs')->where('tenant_id', $tenant->id)->pluck('default_id')->all();
            $data['defaults'] = SearchDefault::where('enabled', true)->orderBy('type')->orderBy('sort')->get()
                ->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'from' => $d->from_term, 'to' => $d->to_value, 'on' => ! in_array($d->id, $off)]);
            $data['pages'] = \App\Models\Tenant\TenantPage::where('tenant_id', $tenant->id)->where('is_published', true)
                ->orderByDesc('is_home')->orderBy('title')->get(['id', 'title', 'slug', 'is_home'])
                ->map(fn ($p) => ['title' => $p->title, 'url' => $p->is_home ? '/' : '/' . $p->slug])->values();
        }
        if ($tab === 'promotions') {
            $data['promos'] = TenantSearchPromotion::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();
            $data['pages'] = \App\Models\Tenant\TenantPage::where('tenant_id', $tenant->id)->where('is_published', true)
                ->orderByDesc('is_home')->orderBy('title')->get(['title', 'slug', 'is_home'])
                ->map(fn ($p) => ['title' => $p->title, 'url' => $p->is_home ? '/' : '/' . $p->slug])->values();
            $data['brands'] = $this->brands();
        }
        if ($tab === 'promotions' || $tab === 'display') {
            $search = ShopSearch::for($tenant);
            $data['cats'] = collect($search->categories())->values()->map(function ($c) use ($search, $tab) {
                $c['auto'] = $tab === 'display' && ! $c['image'] ? $search->categoryImage($c['id']) : null;
                return $c;
            })->all();
        }

        return view('tenant.reports.search', $data);
    }

    private function brands(): array
    {
        try {
            return ShopSearch::for(tenant())->base(false)->toBase()->reorder()
                ->selectRaw(ShopSearch::BRAND_SQL . ' AS b')->groupBy('b')->orderBy('b')
                ->pluck('b')->filter()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---------------------------------------------------------------- rules

    public function ruleSave(Request $request)
    {
        $this->guardEdit();
        $tenant = tenant();
        $data = $request->validate([
            'id'        => ['nullable', 'uuid'],
            'type'      => ['required', 'in:synonym,redirect,stop,pin,boost,hide'],
            'from_term' => ['required', 'string', 'max:120'],
            'to_value'  => ['nullable', 'string', 'max:300'],
            'item_id'   => ['nullable', 'uuid'],
            'label'     => ['nullable', 'string', 'max:120'],
            'back'      => ['nullable', 'in:overview,none,rules'],
        ]);
        $tab  = $data['back'] ?? 'rules';
        $type = $data['type'];
        $existing = ! empty($data['id']) ? TenantSearchRule::where('tenant_id', $tenant->id)->findOrFail($data['id']) : null;

        // what the plan includes decides what can be made; an existing rule can still be edited
        $feature = ['synonym' => 'synonyms', 'stop' => 'synonyms', 'redirect' => 'redirects', 'pin' => 'pbh', 'boost' => 'pbh', 'hide' => 'pbh'][$type];
        if (! $existing && ! SearchConfig::can($tenant, $feature)) {
            return $this->back($tab, ucfirst(TenantSearchRule::TYPES[$type]) . ' rules are on the ' . SearchConfig::unlockedOn($feature) . ' plan.', 'error');
        }

        $from = ShopSearch::normalize($data['from_term']);
        $to   = trim((string) ($data['to_value'] ?? ''));
        if ($type === 'synonym') {
            if ($to === '') return $this->back($tab, 'Say what "' . $from . '" should be treated as.', 'error');
            $to = ShopSearch::normalize($to);
            if ($to === $from) return $this->back($tab, 'A word cannot be a synonym of itself.', 'error');
        }
        if ($type === 'redirect') {
            if ($to === '') return $this->back($tab, 'Pick where the search should go.', 'error');
            if (! str_starts_with($to, '/') && ! preg_match('~^https?://~i', $to)) $to = '/' . ltrim($to, '/');
        }
        if ($type === 'stop') {
            $from = implode(', ', array_values(array_unique(array_filter(array_map(fn ($w) => ShopSearch::normalize($w), preg_split('/[,\n]+/', $data['from_term']))))));
            $to = null;
        }
        $itemId = null;
        if (in_array($type, ['pin', 'boost', 'hide'], true)) {
            $itemId = $data['item_id'] ?? null;
            abort_unless($itemId && TenantInventoryItem::where('tenant_id', $tenant->id)->whereKey($itemId)->exists(), 422, 'Pick an item.');
            $to = null;
        }

        $attrs = ['type' => $type, 'from_term' => $from, 'to_value' => $to ?? '', 'item_id' => $itemId, 'label' => $data['label'] ?? null, 'enabled' => true];
        if ($existing) {
            $existing->update($attrs);
        } elseif (in_array($type, ['synonym', 'redirect'], true)) {
            // one synonym and one redirect per word; saving again replaces it
            TenantSearchRule::updateOrCreate(['tenant_id' => $tenant->id, 'type' => $type, 'from_term' => $from], $attrs + ['created_by' => Auth::guard('tenant')->id()]);
        } else {
            TenantSearchRule::create($attrs + ['tenant_id' => $tenant->id, 'created_by' => Auth::guard('tenant')->id()]);
        }

        // fixing a no-results search takes it off that list
        if ($tab === 'none' || in_array($type, ['synonym', 'redirect'], true)) {
            TenantSearchDismissal::firstOrCreate(['tenant_id' => $tenant->id, 'query' => $from], ['dismissed_by' => Auth::guard('tenant')->id()]);
            Cache::forget('shop-search-fix:' . $tenant->id . ':' . md5($from));
        }
        $this->forget();

        $what = TenantSearchRule::TYPES[$type];
        return $this->back($tab, $what . ($existing ? ' saved.' : ' added.') . ' It applies within a minute.');
    }

    public function ruleDelete(string $id)
    {
        $this->guardEdit();
        $r = TenantSearchRule::where('tenant_id', tenant()->id)->findOrFail($id);
        $r->delete();
        $this->forget();
        return $this->back('rules', 'Rule removed. It stops applying within a minute.');
    }

    public function defaultsSave(Request $request)
    {
        $this->guardEdit();
        $on  = array_map('intval', (array) $request->input('on', []));
        $all = SearchDefault::where('enabled', true)->pluck('id')->all();
        $tid = tenant()->id;
        DB::transaction(function () use ($all, $on, $tid) {
            DB::table('tenant_search_default_offs')->where('tenant_id', $tid)->delete();
            $rows = [];
            foreach (array_diff($all, $on) as $id) $rows[] = ['tenant_id' => $tid, 'default_id' => $id, 'created_at' => now(), 'updated_at' => now()];
            if ($rows) DB::table('tenant_search_default_offs')->insert($rows);
        });
        $this->forget();
        return $this->back('rules', 'Intake defaults saved.');
    }

    public function dismiss(Request $request)
    {
        $this->guardEdit();
        $q = ShopSearch::normalize((string) $request->validate(['q' => ['required', 'string', 'max:190']])['q']);
        TenantSearchDismissal::firstOrCreate(['tenant_id' => tenant()->id, 'query' => $q], ['dismissed_by' => Auth::guard('tenant')->id()]);
        return $this->back('none', '"' . $q . '" dismissed. It stays counted in the totals.');
    }

    public function items(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json(['items' => []]);
        $search = ShopSearch::for(tenant());
        $ids = array_slice($search->run($q, true)['ids'], 0, 12);
        if (! $ids) {
            $ids = $search->base(false)->where(fn ($w) => $w->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%'))->limit(12)->pluck('id')->all();
        }
        $items = TenantInventoryItem::whereIn('id', $ids)->with(['distributorCatalog:id,images,distributor_code', 'itemImages.media'])->get(['id', 'name', 'sku', 'distributor_catalog_id', 'tenant_id']);
        return response()->json(['items' => $items->map(fn ($i) => ['id' => (string) $i->id, 'name' => $i->name, 'sku' => $i->sku, 'img' => $i->primaryImageUrl()])->values()]);
    }

    // ---------------------------------------------------------------- promotions

    public function promoSave(Request $request)
    {
        $this->guardEdit();
        $tenant = tenant();
        $data = $request->validate([
            'id'           => ['nullable', 'uuid'],
            'title'        => ['required', 'string', 'max:120'],
            'subtitle'     => ['nullable', 'string', 'max:160'],
            'image_url'    => ['nullable', 'string', 'max:500'],
            'image_media_id' => ['nullable', 'uuid'],
            'link_type'    => ['required', 'in:category,page,brand,item,url'],
            'link_value'   => ['nullable', 'string', 'max:500'],
            'button_label' => ['nullable', 'string', 'max:40'],
            'keywords'     => ['nullable', 'string', 'max:2000'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['uuid'],
            'placements'   => ['required', 'array', 'min:1'],
            'placements.*' => ['in:dropdown,banner,card'],
            'starts_on'    => ['nullable', 'date'],
            'ends_on'      => ['nullable', 'date', 'after_or_equal:starts_on'],
            'enabled'      => ['nullable', 'boolean'],
        ], [
            'placements.required' => 'Pick at least one place for it to show.',
            'ends_on.after_or_equal' => 'The end date is before the start date.',
        ]);
        $existing = ! empty($data['id']) ? TenantSearchPromotion::where('tenant_id', $tenant->id)->findOrFail($data['id']) : null;
        if (! $existing && ! SearchConfig::can($tenant, 'promotions')) {
            return $this->back('promotions', 'Promotions are on the ' . SearchConfig::unlockedOn('promotions') . ' plan.', 'error');
        }
        $kw = array_values(array_unique(array_filter(array_map(fn ($w) => ShopSearch::normalize($w), preg_split('/[,\n]+/', (string) ($data['keywords'] ?? ''))))));
        $cats = TenantInventoryCategory::where('tenant_id', $tenant->id)->whereIn('id', (array) ($data['category_ids'] ?? []))->pluck('id')->map(fn ($x) => (string) $x)->all();
        if (! $kw && ! $cats) {
            return back()->withInput()->withErrors(['keywords' => 'Add a word or pick a category, so it knows when to show.'])->with('promo_open', $data['id'] ?? 'new');
        }
        $link = trim((string) ($data['link_value'] ?? ''));
        if ($data['link_type'] === 'url' && $link !== '' && ! preg_match('~^(https?://|/)~i', $link)) $link = 'https://' . $link;
        if ($link === '' && $data['link_type'] !== 'url') {
            return back()->withInput()->withErrors(['link_value' => 'Pick where the promotion links to.'])->with('promo_open', $data['id'] ?? 'new');
        }
        if ($data['link_type'] === 'item') {
            abort_unless(TenantInventoryItem::where('tenant_id', $tenant->id)->whereKey($link)->exists(), 422);
        }
        $img = trim((string) ($data['image_url'] ?? ''));
        if ($img !== '' && ! preg_match('~^(https?://|/)~i', $img)) $img = '';

        $attrs = [
            'title' => trim($data['title']), 'subtitle' => trim((string) ($data['subtitle'] ?? '')) ?: null,
            'image_url' => $img ?: null, 'image_media_id' => $data['image_media_id'] ?? null,
            'link_type' => $data['link_type'], 'link_value' => $link ?: null, 'button_label' => trim((string) ($data['button_label'] ?? '')) ?: null,
            'keywords' => $kw, 'category_ids' => $cats, 'placements' => array_values(array_unique($data['placements'])),
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
            'enabled' => $request->boolean('enabled'),
        ];
        if ($existing) {
            $existing->update($attrs);
        } else {
            TenantSearchPromotion::create($attrs + ['tenant_id' => $tenant->id, 'created_by' => Auth::guard('tenant')->id()]);
        }
        $this->forget();
        return $this->back('promotions', 'Promotion ' . ($existing ? 'saved' : 'added') . '. ' . ($attrs['enabled'] ? 'It shows within a minute.' : 'It is off until you turn it on.'));
    }

    public function promoDelete(string $id)
    {
        $this->guardEdit();
        TenantSearchPromotion::where('tenant_id', tenant()->id)->findOrFail($id)->delete();
        $this->forget();
        return $this->back('promotions', 'Promotion deleted. It stopped showing straight away.');
    }

    // ---------------------------------------------------------------- display

    public function displaySave(Request $request)
    {
        $this->guardEdit();
        $tenant = tenant();
        $in = $request->validate([
            'facets'       => ['nullable', 'array'],
            'facets.*'     => ['in:' . implode(',', array_keys(SearchConfig::FACETS))],
            'layout'       => ['required', 'in:grid,list'],
            'popular_mode' => ['required', 'in:top,list'],
            'popular_list' => ['nullable', 'string', 'max:1000'],
            'placeholder'  => ['nullable', 'string', 'max:60'],
            'cat_img'      => ['nullable', 'array'],
            'cat_img.*'    => ['nullable', 'string', 'max:500'],
        ]);
        $s = [];
        foreach (array_keys(SearchConfig::DISPLAY) as $k) $s[$k] = $request->boolean($k);
        $s['facets']       = array_values((array) ($in['facets'] ?? []));
        $s['layout']       = $in['layout'];
        $s['popular_mode'] = $in['popular_mode'];
        $s['popular_list'] = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) ($in['popular_list'] ?? '')))));
        $s['placeholder']  = trim((string) ($in['placeholder'] ?? ''));

        $settings = $tenant->settings ?? [];
        $settings['search'] = $s;
        $tenant->settings = $settings;
        $tenant->save();

        // category pictures: a chosen picture, or empty for "use an item's photo"
        foreach ((array) ($in['cat_img'] ?? []) as $catId => $url) {
            $url = trim((string) $url);
            if ($url !== '' && ! preg_match('~^(https?://|/)~i', $url)) continue;
            TenantInventoryCategory::where('tenant_id', $tenant->id)->whereKey($catId)->update(['image_url' => $url ?: null]);
            Cache::forget('shop-search-catimg:' . $tenant->id . ':' . $catId);
        }
        $this->forget();
        return $this->back('display', 'Search display saved.');
    }
}
