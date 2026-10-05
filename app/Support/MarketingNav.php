<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantPage;

/**
 * MARKER-MKT-NAV — the intake.works header menu, from one place: the rows
 * edited on master admin › Site & content › Navigation. Each row is either a
 * page (follows the page's address; hidden while the page is unpublished) or
 * a custom link, with a style (link / button / outline) and a side (left /
 * right). Nothing else changes the menu.
 */
class MarketingNav
{
    public const STYLES = ['link', 'button', 'outline'];
    public const SIDES  = ['left', 'right'];

    // MARKER-MKT-NAV-FLOAT — header style and its four Floating settings.
    public const HEADER_DEFAULTS = ['style' => 'classic', 'bg' => '#0a0a0a', 'opacity' => 70, 'blur' => 14, 'pill' => '#ffffff', 'pill_strength' => 8, 'space' => 'normal', 'link' => '', 'fade' => false];

    public static function header(?array $override = null): array
    {
        $raw = $override ?? (self::platform()?->settings['marketing_header'] ?? []);
        return self::cleanHeader(is_array($raw) ? $raw : []);
    }

    // MARKER-MKT-NAV-PHONE — desktop settings plus phone overrides (only the
    // settings changed for phones are stored under 'phone').
    public static function cleanHeader(array $h): array
    {
        $desk  = self::cleanHeaderBase($h);
        $in    = is_array($h['phone'] ?? null) ? $h['phone'] : [];
        $phone = [];
        if ($in) {
            $merged = self::cleanHeaderBase(array_merge($desk, $in));
            foreach (array_keys($in) as $k) {
                if (array_key_exists($k, $desk)) $phone[$k] = $merged[$k];
            }
        }
        return $desk + ['phone' => $phone];
    }

    /** The settings phones actually use. */
    public static function phoneHeader(array $h): array
    {
        $p = $h['phone'] ?? [];
        unset($h['phone']);
        return array_merge($h, is_array($p) ? $p : []);
    }

    private static function cleanHeaderBase(array $h): array
    {
        $d   = self::HEADER_DEFAULTS;
        $hex = fn ($v, $fallback) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
        $int = fn ($v, $fallback, $max) => max(0, min($max, is_numeric($v) ? (int) $v : $fallback));
        return [
            'style'         => in_array($h['style'] ?? '', ['classic', 'float'], true) ? $h['style'] : 'classic',
            'bg'            => $hex($h['bg'] ?? null, $d['bg']),
            'opacity'       => $int($h['opacity'] ?? null, $d['opacity'], 100),
            'blur'          => $int($h['blur'] ?? null, $d['blur'], 30),
            'pill'          => $hex($h['pill'] ?? null, $d['pill']),
            'pill_strength' => $int($h['pill_strength'] ?? null, $d['pill_strength'], 30),
            // MARKER-MKT-NAV-POLISH
            'space'         => in_array($h['space'] ?? '', ['tight', 'normal', 'roomy'], true) ? $h['space'] : 'normal',
            'link'          => $hex($h['link'] ?? null, ''),
            'fade'          => filter_var($h['fade'] ?? false, FILTER_VALIDATE_BOOLEAN), // MARKER-MKT-NAV-EDGE
        ];
    }

    /** Link colour to use: the chosen one, or dark on a light floating bar. */
    public static function linkColour(array $h): ?string
    {
        if ($h['link'] !== '') return $h['link'];
        if ($h['style'] !== 'float' || $h['opacity'] < 40) return null;
        [$r, $g, $b] = sscanf($h['bg'], '#%02x%02x%02x');
        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255 > 0.6 ? '#111111' : null;
    }

    public static function platform(): ?Tenant
    {
        return Tenant::where('is_platform', true)->first();
    }

    /**
     * MARKER-MKT-HOME — the slug of the page intake.works shows at /: the
     * published page flagged Home, or the page with slug "home" if none is.
     */
    public static function homeSlug(): string
    {
        static $slug = null;
        if ($slug !== null) return $slug;
        $p = self::platform();
        if (! $p) return $slug = 'home';
        return $slug = (string) (TenantPage::where('tenant_id', $p->id)->where('is_home', true)
            ->where('is_published', true)->value('slug') ?: 'home');
    }

    /** Marketing pages a menu row can point at: [id => [title, path, published]]. */
    public static function pages(): array
    {
        $p = self::platform();
        if (! $p) return [];
        return TenantPage::where('tenant_id', $p->id)
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'page'))
            ->where('slug', 'not like', '\_\_%')
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'is_home', 'is_published'])
            ->mapWithKeys(fn ($pg) => [(string) $pg->id => [
                'title'     => (string) $pg->title,
                'path'      => $pg->slug === self::homeSlug() ? '/' : '/' . $pg->slug, // MARKER-MKT-HOME
                'published' => (bool) $pg->is_published,
            ]])->all();
    }

    /** Saved rows as plain arrays, in order — what the editor works on. */
    public static function rows(): array
    {
        $p = self::platform();
        if (! $p) return [];
        return TenantNavItem::where('tenant_id', $p->id)->orderBy('sort_order')->get()
            ->map(fn ($r) => [
                'type'  => $r->page_id ? 'page' : 'link',
                'page'  => $r->page_id ? (string) $r->page_id : null,
                'label' => (string) ($r->getRawOriginal('label') ?? ''), // MARKER-SHOP-NAV — raw, not the page title
                'url'   => (string) ($r->getRawOriginal('url') ?? ''),
                'style' => in_array($r->style, self::STYLES, true) ? $r->style : 'link',
                'side'  => in_array($r->side, self::SIDES, true) ? $r->side : 'left',
                'tab'   => (bool) $r->open_in_new_tab,
            ])->all();
    }

    /**
     * Resolve rows into what the header draws: drops page rows whose page is
     * missing or unpublished, fills page labels and addresses.
     * @return array<int, array{label:string,url:string,style:string,side:string,tab:bool}>
     */
    public static function resolve(array $rows, ?array $pages = null): array
    {
        $pages ??= self::pages();
        $out = [];
        foreach ($rows as $r) {
            $style = in_array($r['style'] ?? 'link', self::STYLES, true) ? $r['style'] : 'link';
            $side  = in_array($r['side'] ?? 'left', self::SIDES, true) ? $r['side'] : 'left';
            if (($r['type'] ?? 'link') === 'page') {
                $pg = $pages[(string) ($r['page'] ?? '')] ?? null;
                if (! $pg || ! $pg['published']) continue;
                $label = trim((string) ($r['label'] ?? '')) ?: $pg['title'];
                $url   = $pg['path'];
            } else {
                $label = trim((string) ($r['label'] ?? ''));
                $url   = trim((string) ($r['url'] ?? ''));
                if ($label === '' || $url === '') continue;
                if (! preg_match('#^(/|https?://|mailto:|tel:)#i', $url)) continue;
            }
            $out[] = ['label' => $label, 'url' => $url, 'style' => $style, 'side' => $side, 'tab' => ! empty($r['tab'])];
        }
        return $out;
    }

    /** The live menu. */
    public static function items(): array
    {
        return self::resolve(self::rows());
    }

    /** "3rd of 7", or null when the page has no row. Hidden = row exists but page unpublished. */
    public static function positionFor(string $pageId): ?array
    {
        $rows = self::rows();
        foreach ($rows as $i => $r) {
            if (($r['type'] ?? '') === 'page' && (string) $r['page'] === $pageId) {
                return ['index' => $i + 1, 'total' => count($rows)];
            }
        }
        return null;
    }

    public static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }
}
