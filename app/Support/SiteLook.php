<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\WebTemplate;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-WEB-TEMPLATES: a shop's look = its template (from master admin) + light or dark +
 * the shop's own changes. Shops not on a template (site_template empty or 'custom') get
 * null here and render exactly as before through DesignTokens' older path.
 *
 * Colour roles (each template has a dark and a light set):
 *   bg  page background      s1 surface (menu bar, cards, fields)   s2 second surface (alternate sections)
 *   tx  text                 mu muted text                           bd lines
 *   ac  accent               at text on the accent
 */
class SiteLook
{
    public const ROLES = [
        'bg' => ['Background', 'the page itself'],
        's1' => ['Surface', 'menu bar, cards, search box'],
        's2' => ['Second surface', 'alternate sections'],
        'tx' => ['Text', 'body copy and headings'],
        'mu' => ['Muted text', 'captions, struck-through prices'],
        'bd' => ['Lines', 'borders and dividers'],
        'ac' => ['Accent', 'buttons, badges, links'],
        'at' => ['Text on accent', 'words on a button'],
    ];

    /** [fg, bg, label, minimum ratio] */
    public const PAIRS = [
        ['tx', 'bg', 'Text on background', 4.5],
        ['tx', 's1', 'Text on surface', 4.5],
        ['mu', 'bg', 'Muted text on background', 4.5],
        ['mu', 's1', 'Muted text on surface', 4.5],
        ['at', 'ac', 'Button text on accent', 4.5],
        ['ac', 'bg', 'Accent on background', 3.0],
    ];

    public const FONTS = ['Inter', 'DM Sans', 'Barlow Condensed', 'Fraunces', 'Space Grotesk', 'Archivo', 'Playfair Display',
        'Montserrat', 'Poppins', 'Nunito', 'Lato', 'Raleway', 'Merriweather'];

    /** Weights each font has on Google Fonts. Asking for one a font lacks fails the whole request. */
    private const FONT_WEIGHTS = ['Space Grotesk' => '400;500;600;700', 'Lato' => '400;700;900', 'Merriweather' => '400;700;900'];

    /** "Font+Name:wght@..." for a Google Fonts css2 request. */
    public static function fontParam(string $font): string
    {
        return str_replace(' ', '+', $font) . ':wght@' . (self::FONT_WEIGHTS[$font] ?? '400;500;600;700;800');
    }

    /** piece => [label, help, [value => label]] */
    public const PIECES = [
        'nav'    => ['Menu bar', '', ['float' => 'Floating bar', 'solid' => 'Solid bar', 'center' => 'Centred logo', 'over' => 'Over the photo']],
        'hero'   => ['Top of the homepage', 'What the top section of a page opens with.', ['photo' => 'Full photo', 'split' => 'Photo and text', 'text' => 'Words only']],
        'cards'  => ['Cards', 'Service, product, pricing and review cards.', ['bordered' => 'Outlined', 'flat' => 'Flat', 'raised' => 'Raised', 'image' => 'Photo on top']],
        'fh'     => ['Heading font', '', null],
        'fb'     => ['Body font', '', null],
        'hw'     => ['Heading weight', '', [400 => 'Light', 500 => 'Regular', 650 => 'Bold', 700 => 'Bold+', 800 => 'Heavy']],
        'hc'     => ['Heading letters', '', ['none' => 'As typed', 'upper' => 'ALL CAPS']],
        'r'      => ['Corners', 'Cards, photos and fields.', [0 => 'Square (0px)', 6 => 'Slight (6px)', 10 => 'Soft (10px)', 18 => 'Round (18px)']],
        'btn'    => ['Main buttons', '', ['solid' => 'Solid', 'outline' => 'Outline']],
        'tiles'  => ['Behind product photos', 'White suits catalog photos shot on white.', ['white' => 'White', 'surface' => 'Surface colour']],
        'ratio'  => ['Product photo shape', '', ['1/1' => 'Square', '4/5' => 'Tall']],
        'cols'   => ['Products in a row', 'On desktop. Phones always show two.', [3 => '3', 4 => '4', 5 => '5']],
        'footer' => ['Footer', '', ['simple' => 'Simple', 'columns' => 'Columns', 'cta' => 'Big call to action']],
    ];

    public const SEED = [
        ['key' => 'garage', 'name' => 'Garage', 'description' => 'Bold and workmanlike. Condensed uppercase headings, a full photo across the top, white product tiles.',
         'modes' => ['dark'  => ['bg' => '#111214', 's1' => '#1b1d20', 's2' => '#17191b', 'tx' => '#f1eee8', 'mu' => '#a19c94', 'bd' => '#2c2f33', 'ac' => '#f0a93b', 'at' => '#141414'],
                     'light' => ['bg' => '#f3f1ed', 's1' => '#ffffff', 's2' => '#e9e6e0', 'tx' => '#17181a', 'mu' => '#5d5a55', 'bd' => '#d9d5cd', 'ac' => '#b8730f', 'at' => '#17181a']],
         'pieces' => ['nav' => 'float', 'hero' => 'photo', 'cards' => 'bordered', 'fh' => 'Barlow Condensed', 'fb' => 'Inter', 'hw' => 700, 'hc' => 'upper', 'r' => 10, 'btn' => 'solid', 'tiles' => 'white', 'ratio' => '1/1', 'cols' => 4, 'footer' => 'cta'],
         'sections' => ['text_image' => 'split', 'services' => 'cards', 'features' => 'icons', 'testimonials' => 'big', 'faq' => 'lines', 'gallery' => 'grid', 'stats' => 'big', 'steps' => 'row', 'pricing' => 'cards', 'cta' => 'band', 'contact' => 'split', 'logos' => 'strip', 'products' => 'grid', 'rentals' => 'cards']],
        ['key' => 'studio', 'name' => 'Studio', 'description' => 'Warm and considered. Serif headings, a split photo and text opening, soft raised cards.',
         'modes' => ['dark'  => ['bg' => '#1b1714', 's1' => '#25201b', 's2' => '#211c18', 'tx' => '#f4ece1', 'mu' => '#b5a998', 'bd' => '#3a3229', 'ac' => '#e39a76', 'at' => '#1b1714'],
                     'light' => ['bg' => '#f6efe4', 's1' => '#fffaf2', 's2' => '#efe6d8', 'tx' => '#2b231c', 'mu' => '#6c5f52', 'bd' => '#ddd2c2', 'ac' => '#a8532f', 'at' => '#fffaf2']],
         'pieces' => ['nav' => 'center', 'hero' => 'split', 'cards' => 'raised', 'fh' => 'Fraunces', 'fb' => 'DM Sans', 'hw' => 500, 'hc' => 'none', 'r' => 18, 'btn' => 'solid', 'tiles' => 'surface', 'ratio' => '4/5', 'cols' => 3, 'footer' => 'columns'],
         'sections' => ['text_image' => 'overlap', 'services' => 'rows', 'features' => 'tiles', 'testimonials' => 'cards', 'faq' => 'two', 'gallery' => 'masonry', 'stats' => 'boxed', 'steps' => 'line', 'pricing' => 'cards', 'cta' => 'photo', 'contact' => 'centered', 'logos' => 'grid', 'products' => 'feature', 'rentals' => 'list']],
        ['key' => 'clean', 'name' => 'Clean', 'description' => 'Quiet and fast. A solid menu bar, a text-only opening and flat cards that let the products lead.',
         'modes' => ['dark'  => ['bg' => '#0b0b0c', 's1' => '#151517', 's2' => '#111113', 'tx' => '#fafafa', 'mu' => '#9b9ba1', 'bd' => '#26262a', 'ac' => '#4f8cff', 'at' => '#0b0b0c'],
                     'light' => ['bg' => '#ffffff', 's1' => '#f5f5f6', 's2' => '#f7f7f8', 'tx' => '#0b0b0c', 'mu' => '#5f6068', 'bd' => '#e3e3e6', 'ac' => '#1f5fe0', 'at' => '#ffffff']],
         'pieces' => ['nav' => 'solid', 'hero' => 'text', 'cards' => 'flat', 'fh' => 'Inter', 'fb' => 'Inter', 'hw' => 650, 'hc' => 'none', 'r' => 6, 'btn' => 'solid', 'tiles' => 'white', 'ratio' => '1/1', 'cols' => 4, 'footer' => 'simple'],
         'sections' => ['text_image' => 'split', 'services' => 'list', 'features' => 'list', 'testimonials' => 'quiet', 'faq' => 'lines', 'gallery' => 'grid', 'stats' => 'inline', 'steps' => 'row', 'pricing' => 'table', 'cta' => 'boxed', 'contact' => 'split', 'logos' => 'strip', 'products' => 'carousel', 'rentals' => 'cards']],
    ];

    private static array $memo = [];

    // ------------------------------------------------------------------ resolving

    /**
     * The shop's look, or null when it isn't on a template. Staff previewing a draft from
     * Website › Templates (?look_preview=1, same signed-in session) see the draft instead.
     */
    public static function for(?Tenant $tenant): ?array
    {
        if (! $tenant) return null;
        $key = (string) $tenant->id;
        $preview = self::previewDraft($tenant);
        if (! $preview && array_key_exists($key, self::$memo)) return self::$memo[$key];

        $tplKey = $preview['tpl'] ?? $tenant->site_template;
        $tpl = $tplKey ? WebTemplate::cached($tplKey) : null;
        if (! $tpl) return self::$memo[$key] = null;

        $mode   = $preview['mode'] ?? (in_array($tenant->site_mode, ['dark', 'light'], true) ? $tenant->site_mode : 'dark');
        $custom = $preview ? ['c' => $preview['c'] ?? [], 'p' => $preview['p'] ?? []] : (array) ($tenant->site_custom ?? []);
        $look = self::build($tpl->live(), $mode, $custom, $preview ? null : $tenant);
        if ($preview) return $look;
        return self::$memo[$key] = $look;
    }

    public static function forget(): void { self::$memo = []; }

    /**
     * $data = a template's published (or draft) fields: modes, pieces, sections.
     * Returns ['key','name','mode','roles' => [dark=>[..], light=>[..]], 'pieces', 'sections', 'custom'].
     */
    public static function build(array $data, string $mode, array $custom = [], ?Tenant $tenant = null): array
    {
        $roles = [];
        foreach (['dark', 'light'] as $m) {
            $base = (array) ($data['modes'][$m] ?? []);
            $mine = (array) (((array) ($custom['c'] ?? []))[$m] ?? []);
            foreach (self::ROLES as $r => $_) {
                $v = $mine[$r] ?? $base[$r] ?? null;
                $roles[$m][$r] = self::hex($v) ?? '#000000';
            }
        }
        $pieces = (array) ($data['pieces'] ?? []);
        foreach ((array) ($custom['p'] ?? []) as $k => $v) {
            if (array_key_exists($k, self::PIECES)) $pieces[$k] = $v;
        }
        $pieces = self::cleanPieces($pieces);

        // A colour or font changed outside Templates (Settings › Branding writes the same
        // columns) counts as the shop's own change.
        if ($tenant) {
            $w = (array) (((array) ($tenant->site_custom ?? []))['written'] ?? []);
            if ($w) {
                $diff = fn ($col) => ($tenant->{$col} ?? null) && strtolower((string) $tenant->{$col}) !== strtolower((string) ($w[$col] ?? ''));
                if ($diff('accent_color') && self::hex($tenant->accent_color)) $roles[$mode]['ac'] = strtolower($tenant->accent_color);
                if ($diff('text_color') && self::hex($tenant->text_color))     $roles[$mode]['tx'] = strtolower($tenant->text_color);
                if ($diff('bg_color') && self::hex($tenant->bg_color))         $roles[$mode]['bg'] = strtolower($tenant->bg_color);
                if ($diff('font_heading')) $pieces['fh'] = $tenant->font_heading;
                if ($diff('font_body'))    $pieces['fb'] = $tenant->font_body;
            }
        }

        return [
            'key' => $data['key'] ?? null, 'name' => $data['name'] ?? null,
            'mode' => $mode === 'light' ? 'light' : 'dark',
            'roles' => $roles, 'pieces' => $pieces,
            'sections' => (array) ($data['sections'] ?? []),
        ];
    }

    public static function cleanPieces(array $p): array
    {
        $def = self::SEED[0]['pieces'];
        $out = [];
        foreach (self::PIECES as $k => [$label, $help, $opts]) {
            $v = $p[$k] ?? $def[$k];
            if ($k === 'fh' || $k === 'fb') { $out[$k] = is_string($v) && preg_match('/^[A-Za-z0-9 ]{2,40}$/', $v) ? $v : $def[$k]; continue; }
            if ($k === 'r')  { $out[$k] = max(0, min(24, (int) $v)); continue; }
            if ($k === 'hw') { $out[$k] = max(300, min(900, (int) $v)); continue; }
            if ($k === 'cols') { $out[$k] = in_array((int) $v, [3, 4, 5], true) ? (int) $v : 4; continue; }
            $out[$k] = array_key_exists((string) $v, $opts) ? (string) $v : (string) $def[$k];
        }
        return $out;
    }

    /** The colours shown to visitors (the shop's chosen version). */
    public static function colours(array $look): array
    {
        return $look['roles'][$look['mode']];
    }

    /** DesignTokens' token names, for everything that already reads them. */
    public static function tokens(array $look): array
    {
        $c = self::colours($look);
        $p = $look['pieces'];
        return [
            'accent' => $c['ac'], 'accent_text' => $c['at'], 'text' => $c['tx'], 'bg' => $c['bg'],
            'surface' => $c['s1'], 'surface_2' => $c['s2'], 'muted' => $c['mu'], 'border' => $c['bd'],
            'hero_bg' => $c['bg'], 'hero_text' => $c['tx'],
            'font_heading' => $p['fh'], 'font_body' => $p['fb'],
            'heading_weight' => $p['hw'], 'heading_transform' => $p['hc'] === 'upper' ? 'uppercase' : 'none',
            'button_radius' => $p['btn'] === 'solid' && $p['r'] >= 16 ? 99 : min($p['r'], 12),
            'card_radius' => $p['r'], 'button_style' => $p['btn'],
        ];
    }

    /** Extra CSS variables only a template site has (cards, shop photos, second surface). */
    public static function extraVars(array $look, string $indent = '      '): string
    {
        $c = self::colours($look);
        $p = $look['pieces'];
        $dark = $look['mode'] === 'dark';
        [$cardBg, $cardBd, $cardSh] = match ($p['cards']) {
            'flat'   => [$c['s1'], 'transparent', 'none'],
            'raised' => [$c['s1'], 'transparent', '0 1px 2px rgba(0,0,0,.08), 0 12px 30px rgba(0,0,0,' . ($dark ? '.45' : '.09') . ')'],
            'image'  => [$c['s1'], 'transparent', 'none'],
            default  => ['transparent', $c['bd'], 'none'],
        };
        $lines = [
            '--p-surface-2: ' . $c['s2'],
            '--p-card-bg: ' . $cardBg,
            '--p-card-border: ' . $cardBd,
            '--p-card-shadow: ' . $cardSh,
            '--p-tile-bg: ' . ($p['tiles'] === 'white' ? '#ffffff' : $c['s1']),
            '--p-tile-ratio: ' . $p['ratio'],
            '--p-shop-cols: ' . $p['cols'],
            '--p-field-bg: ' . $c['s1'],
            '--p-muted-real: ' . $c['mu'],
            '--p-shop-card: ' . ($p['cards'] === 'bordered' ? $c['bg'] : $c['s1']),
        ];
        return implode("\n", array_map(fn ($l) => $indent . $l . ';', $lines));
    }

    /**
     * A section's fallback colour: the template's role on a template site, the section's own
     * old default everywhere else (so shops not on a template look exactly as before).
     */
    public static function c(string $role, string $fallback): string
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        if (! $tenant || ! self::for($tenant)) return $fallback;
        return match ($role) {
            'text' => 'var(--p-text)', 'muted' => 'var(--p-muted)', 'accent' => 'var(--p-accent)',
            'accent_text' => 'var(--p-accent-text)', 'bg' => 'var(--p-bg)', 'surface' => 'var(--p-surface)',
            'border' => 'var(--p-border)', default => $fallback,
        };
    }

    /**
     * A section card's colour when the section has no colour of its own: the template's card
     * style on a template site, the section's old default everywhere else.
     */
    public static function card(string $what, string $fallback): string
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        if (! $tenant || ! self::for($tenant)) return $fallback;
        return match ($what) {
            'bg' => 'var(--p-card-bg)', 'border' => 'var(--p-card-border)', 'shadow' => 'var(--p-card-shadow)',
            'radius' => 'var(--p-card-r)',
            default => $fallback,
        };
    }

    // ------------------------------------------------------------------ section designs that follow the template

    /**
     * Which content keys make up each section's design. A section "follows the template" until
     * the shop changes one of these in the page builder; from then on its own settings win and
     * a template change never touches it.
     */
    public const DESIGN_KEYS = [
        'nav'    => ['layout', 'bg_mode', 'hdr_style', 'border_bottom'],
        'hero'   => ['hero_layout', 'text_align', 'height'],
        'footer' => ['layout', 'cta_band'],
    ];

    /** The builder's original values for those keys (what an untouched section has). */
    public const DESIGN_DEFAULTS = [
        'nav'    => ['layout' => 'standard', 'bg_mode' => 'solid', 'hdr_style' => 'classic', 'border_bottom' => 'hairline'],
        'hero'   => ['hero_layout' => '', 'text_align' => 'left', 'height' => 'large'],
        'footer' => ['layout' => 'columns', 'cta_band' => false],
    ];

    /** Settings each piece value gives the section. */
    public static function preset(string $type, string $value): array
    {
        return match ($type) {
            'nav' => match ($value) {
                'float'  => ['layout' => 'standard', 'bg_mode' => 'solid', 'hdr_style' => 'float', 'border_bottom' => 'none'],
                'center' => ['layout' => 'centered', 'bg_mode' => 'solid', 'hdr_style' => 'classic', 'border_bottom' => 'hairline'],
                'over'   => ['layout' => 'standard', 'bg_mode' => 'transparent', 'hdr_style' => 'classic', 'border_bottom' => 'none'],
                default  => ['layout' => 'standard', 'bg_mode' => 'solid', 'hdr_style' => 'classic', 'border_bottom' => 'hairline'],
            },
            'hero' => match ($value) {
                'split'  => ['hero_layout' => 'split', 'text_align' => 'left', 'height' => 'medium'],
                'text'   => ['hero_layout' => 'text', 'text_align' => 'center', 'height' => 'medium'],
                default  => ['hero_layout' => 'photo', 'text_align' => 'left', 'height' => 'large'],
            },
            'footer' => match ($value) {
                'simple' => ['layout' => 'minimal', 'cta_band' => false],
                'cta'    => ['layout' => 'columns', 'cta_band' => true],
                default  => ['layout' => 'columns', 'cta_band' => false],
            },
            default => [],
        };
    }

    /** True when a section's stored settings are the builder's untouched ones for its design. */
    public static function untouched(string $type, array $content): bool
    {
        foreach (self::DESIGN_DEFAULTS[$type] ?? [] as $k => $v) {
            if (self::norm($content[$k] ?? $v, $v) !== self::norm($v, $v)) return false;
        }
        return true;
    }

    /** Compare builder values the way they're meant: a checkbox as on/off, everything else as text. */
    private static function norm($v, $def): string
    {
        if (is_bool($def)) return in_array($v, [true, 1, '1', 'on', 'true'], true) ? '1' : '0';
        if ($def === 'classic' && ($v === '' || $v === null)) return 'classic'; // an unset header style is Classic
        return (string) $v;
    }

    /** Does this section take its design from the template? */
    public static function follows(string $type, array $content): bool
    {
        if (! isset(self::DESIGN_KEYS[$type])) return false;
        $d = $content['design'] ?? null;
        if ($d === 'own') return false;
        if ($d === 'template') return true;
        return self::untouched($type, $content); // sections from before templates: only if never changed
    }

    /**
     * Saving a section from the page builder. An explicit "design" choice wins; otherwise
     * changing any layout setting pins the section to its own design, so a template change
     * never undoes what the shop set.
     */
    public static function pin(string $type, array $old, array $new): array
    {
        if (! isset(self::DESIGN_KEYS[$type])) return $new;
        $was = $old['design'] ?? (self::untouched($type, $old) ? 'template' : 'own');
        if (isset($new['design']) && in_array($new['design'], ['template', 'own'], true) && $new['design'] !== $was) return $new;
        foreach (self::DESIGN_KEYS[$type] as $k) {
            if (! array_key_exists($k, $new)) continue;
            $def = self::DESIGN_DEFAULTS[$type][$k] ?? null;
            $o = $old[$k] ?? $def;
            if (self::norm($o, $def) !== self::norm($new[$k], $def)) {
                $new['design'] = 'own';
                return $new;
            }
        }
        $new['design'] = $was;
        return $new;
    }

    /**
     * The section's content with the template's design laid over it, for rendering. Content
     * (words, photos, links, colours the shop set) is never touched, only the design keys.
     */
    public static function applyDesign(string $type, array $content, ?array $look): array
    {
        if (! $look || ! self::follows($type, $content)) return $content;
        $piece = $look['pieces'][$type] ?? null;
        if (! $piece) return $content;
        $out = array_merge($content, self::preset($type, $piece));
        $c = self::colours($look);

        if ($type === 'nav') {
            // the bar takes the surface colour; a floating bar is a solid-enough surface to read on
            if (($content['bg_color'] ?? 'inherit') === 'inherit' || ($content['bg_color'] ?? '') === '') $out['bg_color'] = $c['s1'];
            if ($piece === 'float') { $out['fl_bg'] = $c['s1']; $out['fl_opacity'] = $out['fl_opacity'] ?? 92; }
            if ($piece === 'over')  $out['text_color'] = ($content['text_color'] ?? '') ?: '#ffffff';
        }
        if ($type === 'hero') {
            $hasPhoto = ($content['bg_image_url'] ?? '') !== '';
            if ($piece === 'text') {
                $out['bg_mode'] = 'color';
            }
            if ($piece === 'split' && ($content['bg_mode'] ?? '') === 'image') {
                $out['hero_photo'] = $content['bg_image_url'] ?? '';
                $out['bg_mode'] = 'color';
            }
            // words over the page colour use the template's text colour, not the white a photo needs
            if ($out['bg_mode'] !== 'image' || ! $hasPhoto) {
                if (($content['text_color'] ?? '#ffffff') === '#ffffff' || ($content['text_color'] ?? '') === '') $out['text_color'] = $c['tx'];
                if (($content['bg_color'] ?? 'inherit') === 'inherit') $out['bg_color'] = $c['bg'];
            }
        }
        if ($type === 'footer' && $piece === 'cta') {
            // a call to action needs words; the shop's own win, these fill a blank band
            $out['cta_heading']      = ($content['cta_heading'] ?? '') ?: 'Ready when you are.';
            $out['cta_button_label'] = ($content['cta_button_label'] ?? '') ?: 'Book now';
            $out['cta_button_url']   = ($content['cta_button_url'] ?? '') ?: '/book';
        }
        return $out;
    }

    /**
     * Older sections saved a white text colour by default (the hero and call-to-action banner
     * assumed a dark background). On a template site a white that the shop never chose, sitting
     * on the page colour rather than a photo, becomes the template's text colour.
     */
    public static function tidy(string $type, array $content, ?array $look): array
    {
        if (! $look || ! in_array($type, ['hero', 'cta_banner'], true)) return $content;
        $onPhoto = ($content['bg_mode'] ?? '') === 'image' && ($content['bg_image_url'] ?? '') !== '';
        $ownBg = ($content['bg_color'] ?? 'inherit') !== 'inherit' && ($content['bg_color'] ?? '') !== '';
        if (! $onPhoto && ! $ownBg && strtolower((string) ($content['text_color'] ?? '')) === '#ffffff') $content['text_color'] = '';
        return $content;
    }

    // ------------------------------------------------------------------ colour maths

    public static function hex($v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
    }

    public static function lum(string $h): float
    {
        $v = array_map(fn ($i) => hexdec(substr($h, $i, 2)) / 255, [1, 3, 5]);
        $v = array_map(fn ($c) => $c <= .03928 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4, $v);
        return .2126 * $v[0] + .7152 * $v[1] + .0722 * $v[2];
    }

    public static function ratio(string $a, string $b): float
    {
        [$x, $y] = [self::lum($a), self::lum($b)];
        return (max($x, $y) + .05) / (min($x, $y) + .05);
    }

    /** Every pair that is hard to read: [[label, mode, ratio, need], ...] */
    public static function fails(array $modes): array
    {
        $out = [];
        foreach (['dark', 'light'] as $m) {
            foreach (self::PAIRS as [$a, $b, $label, $need]) {
                $x = self::hex($modes[$m][$a] ?? null); $y = self::hex($modes[$m][$b] ?? null);
                if (! $x || ! $y) { $out[] = [$label, $m, 0, $need]; continue; }
                $r = self::ratio($x, $y);
                if ($r < $need) $out[] = [$label, $m, round($r, 1), $need];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ preview of unsaved changes

    public const PREVIEW_KEY = 'web_template_preview';

    private static function previewDraft(Tenant $tenant): ?array
    {
        try {
            $r = request();
            if (! $r || ! $r->boolean('look_preview')) return null;
            if (! Auth::guard('tenant')->check()) return null;
            $d = session(self::PREVIEW_KEY);
            if (! is_array($d) || ($d['tenant'] ?? null) !== (string) $tenant->id) return null;
            return $d;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function previewing(): bool
    {
        try { return request()->boolean('look_preview') && Auth::guard('tenant')->check(); } catch (\Throwable $e) { return false; }
    }
}
