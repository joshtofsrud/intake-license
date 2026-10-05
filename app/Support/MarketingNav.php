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
    public const HEADER_DEFAULTS = ['style' => 'classic', 'bg' => '#0a0a0a', 'opacity' => 70, 'blur' => 14, 'pill' => '#ffffff', 'pill_strength' => 8, 'space' => 'normal', 'link' => '', 'fade' => false, 'btn_pos' => 'bar', 'pad_x' => 0, 'top_gap' => -1, 'btn_text' => '', 'btn_fill' => '', 'btn_dist' => 0, 'menu_bg' => '', 'menu_link' => ''];

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
            'btn_pos'       => ($h['btn_pos'] ?? '') === 'menu' ? 'menu' : 'bar', // MARKER-MKT-NAV-BUTTONS — old Beside/Centre become 'bar'
            'btn_text'      => $hex($h['btn_text'] ?? null, ''),
            'btn_fill'      => $hex($h['btn_fill'] ?? null, ''),
            'btn_dist'      => $int($h['btn_dist'] ?? null, 0, 200),
            'menu_bg'       => $hex($h['menu_bg'] ?? null, ''),   // MARKER-MKT-NAV-MENU
            'menu_link'     => $hex($h['menu_link'] ?? null, ''),
            'pad_x'         => $int($h['pad_x'] ?? null, 0, 60), // MARKER-MKT-NAV-EDGEROOM — 0 = preset
            'top_gap'       => (isset($h['top_gap']) && is_numeric($h['top_gap']) && (int) $h['top_gap'] >= 0) ? min(80, (int) $h['top_gap']) : -1, // MARKER-MKT-NAV-TOP — -1 = preset
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

    // ===================== MARKER-MKT-FOOTER =====================
    // The intake.works footer: a tagline, up to 4 link columns, a legal row
    // and a copyright line, edited on Site & content › Navigation. Rows are
    // pages (follow the page, hidden while unpublished), links, or the plan
    // finder. Until it's saved, the footer shows today's links.

    public static function footerDefault(): array
    {
        $bySlug = [];
        foreach (self::pages() as $id => $p) $bySlug[ltrim($p['path'], '/') ?: 'home'] = $id;
        $pg  = fn ($slug, $label) => isset($bySlug[$slug]) ? ['type' => 'page', 'page' => $bySlug[$slug], 'label' => $label, 'url' => '', 'tab' => false] : ['type' => 'link', 'page' => null, 'label' => $label, 'url' => '/' . $slug, 'tab' => false];
        $app = 'https://app.' . config('intake.domain', 'intake.works');
        return [
            'tagline'   => 'Online booking, work orders, and customer management for service shops.',
            'columns'   => [
                ['title' => 'Product', 'rows' => [$pg('features', 'Features'), $pg('pricing', 'Pricing'), $pg('roadmap', 'Roadmap'), $pg('changelog', 'Changelog'), $pg('docs', 'Docs')]],
                ['title' => 'Company', 'rows' => [$pg('contact', 'Contact')]],
                ['title' => 'Get started', 'rows' => [
                    ['type' => 'link', 'page' => null, 'label' => 'Free trial', 'url' => $app . '/signup', 'tab' => false],
                    ['type' => 'link', 'page' => null, 'label' => 'Sign in', 'url' => $app . '/login', 'tab' => false],
                    ['type' => 'quiz', 'page' => null, 'label' => 'Which plan is right for me?', 'url' => '', 'tab' => false],
                ]],
            ],
            'legal'     => [$pg('privacy', 'Privacy'), $pg('terms', 'Terms'), $pg('cookies', 'Cookies'), $pg('acceptable-use', 'Acceptable use')],
            'copyright' => '© {year} Intake. All rights reserved.',
        ];
    }

    public static function footer(?array $override = null): array
    {
        $raw = $override ?? (self::platform()?->settings['marketing_footer'] ?? null);
        return is_array($raw) ? self::cleanFooter($raw) : self::footerDefault();
    }

    public static function cleanFooter(array $f): array
    {
        $txt = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        $row = function ($r) use ($txt) {
            if (! is_array($r)) return null;
            $type = in_array($r['type'] ?? '', ['page', 'link', 'quiz'], true) ? $r['type'] : 'link';
            return ['type' => $type, 'page' => $type === 'page' ? (string) ($r['page'] ?? '') : null,
                    'label' => $txt($r['label'] ?? '', 60), 'url' => $type === 'link' ? $txt($r['url'] ?? '', 255) : '', 'tab' => ! empty($r['tab'])];
        };
        $cols = [];
        foreach (array_slice(array_values((array) ($f['columns'] ?? [])), 0, 4) as $c) {
            if (! is_array($c)) continue;
            $cols[] = ['title' => $txt($c['title'] ?? '', 40), 'rows' => array_values(array_filter(array_map($row, array_slice((array) ($c['rows'] ?? []), 0, 10))))];
        }
        return [
            'tagline'   => $txt($f['tagline'] ?? '', 200),
            'columns'   => $cols,
            'legal'     => array_values(array_filter(array_map($row, array_slice((array) ($f['legal'] ?? []), 0, 8)))),
            'copyright' => $txt($f['copyright'] ?? '', 120),
        ];
    }

    /** One footer row as the page draws it, or null (unpublished/missing page, empty link). */
    public static function footerLink(array $r, ?array $pages = null): ?array
    {
        $pages ??= self::pages();
        if ($r['type'] === 'quiz') return ['label' => $r['label'] ?: 'Which plan is right for me?', 'url' => '#', 'quiz' => true, 'tab' => false];
        if ($r['type'] === 'page') {
            $p = $pages[$r['page']] ?? null;
            if (! $p || ! $p['published']) return null;
            return ['label' => $r['label'] ?: $p['title'], 'url' => $p['path'], 'quiz' => false, 'tab' => $r['tab']];
        }
        if ($r['label'] === '' || ! preg_match('#^(/|https?://|mailto:|tel:)#i', $r['url'])) return null;
        return ['label' => $r['label'], 'url' => $r['url'], 'quiz' => false, 'tab' => $r['tab']];
    }
}
