<?php

namespace App\Support\ShopSearch;

use App\Models\PlatformSettings;
use App\Models\Tenant;

/**
 * MARKER-SHOP-SEARCH-V2: which search features each plan gets (master admin › Search
 * defaults › Plans), and how each shop shows search (Reports › Search › Display).
 *
 * Gating is on making new things. A rule or promotion a shop already made keeps
 * working after a downgrade; it just cannot add more.
 */
class SearchConfig
{
    public const TIERS = ['starter' => 'Starter', 'branded' => 'Branded', 'scale' => 'Scale'];

    /** key => [label, starter, branded, scale] */
    public const FEATURES = [
        'dropdown'   => ['Instant dropdown with photos and category tiles', true, true, true],
        'results'    => ['Results page with filters, quick view and Add to cart', true, true, true],
        'typo'       => ['Typo correction', true, true, true],
        'synonyms'   => ['Synonyms (shop adds its own)', false, true, true],
        'redirects'  => ['Redirects', false, true, true],
        'promotions' => ['Promotions: cards and banners in search', false, true, true],
        'pbh'        => ['Pin, boost and hide results', false, false, true],
        'report'     => ['Search-to-purchase report', false, true, true],
    ];

    /** key => [label, help, default] */
    public const DISPLAY = [
        'dropdown'      => ['Instant dropdown', 'Suggestions, photos and a few products appear as the shopper types.', true],
        'photos'        => ['Photos in the dropdown', 'Product photos and category picture tiles.', true],
        'hover_photo'   => ['Second photo on hover', "Result cards show the item's next photo when the pointer is over them.", true],
        'card_add'      => ['Add to cart on result cards', 'Shoppers add without opening the item.', true],
        'quick_view'    => ['Quick view', 'A preview with quantity and Add to cart, without leaving the results.', true],
        'badges'        => ['Sale, New and stock badges', 'Sale shows when the item has a "was" price above its price; New for items added in the last 30 days.', true],
        'recent'        => ['Show recent searches', 'Shown when the shopper clicks the empty box. Kept in their own browser.', true],
        'popular'       => ['Show popular searches', 'Your top searches, or a list you choose.', true],
        'dd_categories' => ['Show categories in the dropdown', '', true],
        'hide_out'      => ['Hide out-of-stock items from search', 'Off means they show with an "Out of stock" label.', false],
    ];

    public const FACETS  = ['category' => 'Category', 'brand' => 'Brand', 'price' => 'Price', 'availability' => 'Availability', 'sale' => 'On sale'];
    public const LAYOUTS = ['grid' => 'Grid', 'list' => 'List'];

    private static ?array $planMemo = null;

    /** feature => [starter => bool, branded => bool, scale => bool], master admin's overrides merged in. */
    public static function plans(): array
    {
        if (self::$planMemo !== null) return self::$planMemo;
        $saved = [];
        try {
            $saved = (array) ((PlatformSettings::find(1)?->search_settings ?? [])['plans'] ?? []);
        } catch (\Throwable $e) { /* not migrated yet: the defaults stand */ }
        $out = [];
        foreach (self::FEATURES as $k => $f) {
            $out[$k] = [
                'starter' => (bool) ($saved[$k]['starter'] ?? $f[1]),
                'branded' => (bool) ($saved[$k]['branded'] ?? $f[2]),
                'scale'   => (bool) ($saved[$k]['scale'] ?? $f[3]),
            ];
        }
        return self::$planMemo = $out;
    }

    public static function forget(): void
    {
        self::$planMemo = null;
    }

    public static function tier(Tenant $t): string
    {
        $tier = (string) ($t->plan_tier ?? 'starter');
        return isset(self::TIERS[$tier]) ? $tier : ($tier === 'custom' ? 'scale' : 'starter');
    }

    public static function can(Tenant $t, string $feature): bool
    {
        return (bool) (self::plans()[$feature][self::tier($t)] ?? false);
    }

    /** The cheapest plan that has a feature, for the locked row ("on the Scale plan"). */
    public static function unlockedOn(string $feature): string
    {
        foreach (array_keys(self::TIERS) as $tier) {
            if (self::plans()[$feature][$tier] ?? false) return self::TIERS[$tier];
        }
        return 'a higher';
    }

    /** This shop's display choices, with defaults filled in. */
    public static function display(Tenant $t): array
    {
        $s   = (array) (($t->settings['search'] ?? []) ?: []);
        $out = [];
        foreach (self::DISPLAY as $k => $d) {
            $out[$k] = (bool) ($s[$k] ?? $d[2]);
        }
        $facets = isset($s['facets']) && is_array($s['facets']) ? array_values(array_intersect($s['facets'], array_keys(self::FACETS))) : array_keys(self::FACETS);
        $out['facets']        = $facets;
        $out['layout']        = isset(self::LAYOUTS[$s['layout'] ?? '']) ? $s['layout'] : 'grid';
        $out['popular_mode']  = in_array($s['popular_mode'] ?? '', ['top', 'list'], true) ? $s['popular_mode'] : 'top';
        $out['popular_list']  = array_values(array_filter(array_map('trim', (array) ($s['popular_list'] ?? []))));
        $out['placeholder']   = trim((string) ($s['placeholder'] ?? '')) ?: 'Search the shop';
        return $out;
    }
}
