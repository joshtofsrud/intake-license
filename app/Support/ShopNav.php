<?php

namespace App\Support;

use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantPage;

/**
 * a shop's menu, edited in one place: the Nav section in
 * the page builder. Rows for the editor, the pages a row can point at, and
 * a preview collection built from unsaved rows.
 */
class ShopNav
{
    public const STYLES = ['link', 'button', 'outline'];
    public const SIDES  = ['left', 'right'];

    public static function pages(string $tenantId): array
    {
        return TenantPage::where('tenant_id', $tenantId)
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'page'))
            ->where(fn ($w) => $w->where('is_splash', false)->orWhereNull('is_splash'))
            ->orderByDesc('is_home')->orderBy('title')
            ->get(['id', 'title', 'slug', 'is_home', 'is_published'])
            ->mapWithKeys(fn ($p) => [(string) $p->id => [
                't' => (string) $p->title, 'path' => $p->is_home ? '/' : '/' . $p->slug, 'pub' => (bool) $p->is_published,
            ]])->all();
    }

    public static function rows(string $tenantId): array
    {
        return TenantNavItem::where('tenant_id', $tenantId)->orderBy('sort_order')->get()
            ->map(fn ($r) => [
                'type'  => $r->page_id ? 'page' : 'link',
                'page'  => $r->page_id ? (string) $r->page_id : null,
                'label' => (string) ($r->getRawOriginal('label') ?? ''),
                'url'   => (string) ($r->getRawOriginal('url') ?? ''),
                'style' => in_array($r->style, self::STYLES, true) ? $r->style : 'link',
                'side'  => in_array($r->side, self::SIDES, true) ? $r->side : 'left',
                'tab'   => (bool) $r->open_in_new_tab,
                'group' => (string) ($r->group_key ?? ''),   // MARKER-SHOP-MENU-GROUPS
                'icon'  => (string) ($r->icon ?? ''),
                'desc'  => (string) ($r->description ?? ''),
            ])->all();
    }

    /** Clean a posted row; null when it can't be saved. */
    public static function clean(array $r, array $pages): ?array
    {
        $style = in_array($r['style'] ?? '', self::STYLES, true) ? $r['style'] : 'link';
        $side  = in_array($r['side'] ?? '', self::SIDES, true) ? $r['side'] : 'left';
        $label = mb_substr(trim((string) ($r['label'] ?? '')), 0, 60);
        $pid   = (string) ($r['page_id'] ?? $r['page'] ?? '');
        if ($pid !== '') {
            if (! isset($pages[$pid])) return null;
            $url = $pages[$pid]['path'];
        } else {
            $pid = null;
            $url = mb_substr(trim((string) ($r['url'] ?? '')), 0, 255);
            if ($label === '' || ! preg_match('#^(/|https?://|mailto:|tel:)#i', $url)) return null;
        }
        $tab = filter_var($r['open_in_new_tab'] ?? $r['tab'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // MARKER-SHOP-MENU-GROUPS: only a left-side Link item joins a group
        $grp  = ($side === 'left' && $style === 'link') ? mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($r['group_key'] ?? $r['group'] ?? ''))), 0, 20) : '';
        $icon = (string) ($r['icon'] ?? '');
        if ($grp === '' || ! isset(self::icons()[$icon])) $icon = '';
        $desc = $grp === '' ? '' : mb_substr(trim((string) ($r['description'] ?? $r['desc'] ?? '')), 0, 80);
        return [
            'page_id' => $pid, 'label' => $label, 'url' => $url, 'style' => $style, 'side' => $side,
            'open_in_new_tab' => $tab, 'is_external' => (bool) preg_match('#^https?://#i', $url),
            'group_key' => $grp ?: null, 'description' => $desc ?: null, 'icon' => $icon ?: null,
        ];
    }

    /** Unsaved rows as the header's collection, for the builder preview only. */
    public static function previewItems(string $tenantId, array $rows)
    {
        $pages = self::pages($tenantId);
        $byId  = TenantPage::where('tenant_id', $tenantId)->get()->keyBy(fn ($p) => (string) $p->id);
        return collect($rows)->map(fn ($r) => is_array($r) ? self::clean($r, $pages) : null)->filter()
            ->filter(fn ($r) => ! $r['page_id'] || ($pages[$r['page_id']]['pub'] ?? false))
            ->map(function ($r) use ($tenantId, $byId) {
                $i = new TenantNavItem($r + ['tenant_id' => $tenantId]);
                if ($r['page_id']) $i->setRelation('page', $byId[$r['page_id']] ?? null);
                return $i;
            })->values();
    }

    // ------------------------------------------------------------------
    // MARKER-SHOP-MENU-GROUPS: dropdown groups. Left-side links can sit in a
    // group; the bar shows the group once, where its first link is, as a
    // dropdown (desktop) or an accordion (phone menus). Groups live in
    // settings.shop_menu.groups (same shape as intake.works'), each link's
    // group / icon / description on its row. No groups = the menu as before.
    // ------------------------------------------------------------------

    /** Icons a grouped link or featured card can show (intake.works' set, minus the WordPress mark). */
    public static function icons(): array
    {
        $i = MarketingNav::ICONS;
        unset($i['wp']);
        return $i;
    }

    public static function iconSvg(?string $key): string
    {
        $i = self::icons();
        return ($key && isset($i[$key])) ? '<svg viewBox="0 0 24 24" aria-hidden="true">' . $i[$key] . '</svg>' : '';
    }

    /** The shop's groups: the builder preview's unsaved ones when present, else saved. */
    public static function groups($tenant): array
    {
        if (app()->bound('shopnav.preview_groups')) return app('shopnav.preview_groups');
        $raw = $tenant->settings['shop_menu']['groups'] ?? [];
        return self::cleanGroups(is_array($raw) ? $raw : []);
    }

    public static function cleanGroups(array $groups): array
    {
        $out = [];
        foreach (MarketingNav::cleanGroups($groups) as $g) {
            if ($g['feature'] && ! isset(self::icons()[$g['feature']['icon']])) $g['feature']['icon'] = 'star';
            $out[] = $g;
        }
        return $out;
    }

    /**
     * Items in order, with each group placed once at its first link. Only
     * left-side Link-style items join a group; a group with no links is left
     * out. Returns [['kind' => 'link', 'item' => $i] | ['kind' => 'group', 'group' => [...+items]]].
     */
    public static function bar($items, array $groups): array
    {
        $byKey = [];
        foreach ($groups as $g) $byKey[$g['key']] = $g + ['items' => []];
        $key = function ($i) use ($byKey) {
            $k = (string) ($i->group_key ?? '');
            return ($k !== '' && isset($byKey[$k]) && ($i->side ?? 'left') !== 'right' && ($i->style ?? 'link') === 'link') ? $k : '';
        };
        foreach ($items as $i) if (($k = $key($i)) !== '') $byKey[$k]['items'][] = $i;
        $out = []; $placed = [];
        foreach ($items as $i) {
            $k = $key($i);
            if ($k === '') { $out[] = ['kind' => 'link', 'item' => $i]; continue; }
            if (isset($placed[$k])) continue;
            $placed[$k] = true;
            $out[] = ['kind' => 'group', 'group' => $byKey[$k]];
        }
        return $out;
    }
}
