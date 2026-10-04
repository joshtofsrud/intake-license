<?php

namespace App\Support;

use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantPage;

/**
 * MARKER-SHOP-NAV — a shop's menu, edited in one place: the Nav section in
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
        return [
            'page_id' => $pid, 'label' => $label, 'url' => $url, 'style' => $style, 'side' => $side,
            'open_in_new_tab' => $tab, 'is_external' => (bool) preg_match('#^https?://#i', $url),
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
}
