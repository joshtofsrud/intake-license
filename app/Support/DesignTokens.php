<?php

namespace App\Support;

// MARKER-TOKENS — the single source of truth for what a tenant's public site
// looks like. Everything that renders public chrome asks this, so the shells
// can't drift apart the way surface/muted did (three hardcoded copies).

use App\Models\Tenant;

class DesignTokens
{
    /** Last-resort values. Match the pre-token hardcoded CSS exactly, so a
     *  tenant with no template and no overrides renders byte-identical. */
    public const FALLBACKS = [
        'accent'            => '#BEF264',
        'text'              => '#111111',
        'bg'                => '#ffffff',
        'surface'           => 'rgba(0,0,0,.03)',
        'muted'             => 'rgba(0,0,0,.5)',
        'border'            => 'rgba(0,0,0,.1)',
        'hero_bg'           => '',        // '' = fall through to the section's own bg
        'hero_text'         => '',
        'font_heading'      => 'Inter',
        'font_body'         => 'Inter',
        'heading_weight'    => 700,
        'heading_transform' => 'none',
        'button_radius'     => 8,
        'button_style'      => 'solid',
        'card_radius'       => 8,
    ];

    /** Which token a section inherits when its bg_color is 'inherit'. */
    public const SECTION_BG = [
        'hero'               => 'hero_bg',
        'cta_banner'         => 'surface',
        'services'           => 'surface',
        'feature_grid'       => 'surface',
        'pricing_table'      => 'surface',
        'stats_row'          => 'surface',
        'faq_accordion'      => 'surface',
        'logo_bar'           => 'surface',
        'step_timeline'      => 'surface',
        'products_showcase'  => 'surface',
        'rentals_showcase'   => 'surface',
        'classes_embed'      => 'surface',
    ];
    // Anything not listed inherits the page background.

    public static function resolve(?Tenant $tenant): array
    {
        $out = self::FALLBACKS;

        if (! $tenant) {
            return $out;
        }

        // 3) the active template's own token set
        if ($tenant->site_template) {
            // tokens() returns null for a key that no longer exists.
            foreach ((SiteTemplate::tokens($tenant->site_template) ?? []) as $k => $v) {
                if ($v !== null && $v !== '') {
                    $out[$k] = $v;
                }
            }
        }

        // 2) per-tenant overrides. '_prev' is the template revert snapshot,
        //    not a token — never let it leak into the output.
        foreach ((array) ($tenant->design_tokens ?? []) as $k => $v) {
            if ($k === '_prev' || ! array_key_exists($k, $out)) {
                continue;
            }
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }

        // 1) the five discrete columns win — they are what booking, the
        //    customer portal and transactional email already read, so the
        //    site must not disagree with them.
        $columns = [
            'accent'       => $tenant->accent_color,
            'text'         => $tenant->text_color,
            'bg'           => $tenant->bg_color,
            'font_heading' => $tenant->font_heading,
            'font_body'    => $tenant->font_body,
        ];
        foreach ($columns as $k => $v) {
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }

        $out['accent_text'] = ColorHelper::accentTextColor($out['accent']);

        // A template that doesn't state hero colours just uses the page's.
        if ($out['hero_bg'] === '')   { $out['hero_bg']   = $out['bg']; }
        if ($out['hero_text'] === '') { $out['hero_text'] = $out['text']; }

        return $out;
    }

    /** The CSS custom-property block, shared by all three shells. */
    public static function cssVars(array $t, string $indent = '      '): string
    {
        $lines = [
            '--p-accent: '        . $t['accent'],
            '--p-accent-text: '   . $t['accent_text'],
            '--p-text: '          . $t['text'],
            '--p-bg: '            . $t['bg'],
            '--p-surface: '       . $t['surface'],
            '--p-muted: '         . $t['muted'],
            '--p-border: '        . $t['border'],
            '--p-hero-bg: '       . $t['hero_bg'],
            '--p-hero-text: '     . $t['hero_text'],
            "--p-font-heading: '" . $t['font_heading'] . "', -apple-system, sans-serif",
            "--p-font-body: '"    . $t['font_body']    . "', -apple-system, sans-serif",
            '--p-heading-weight: '    . (int) $t['heading_weight'],
            '--p-heading-transform: ' . $t['heading_transform'],
            '--p-btn-r: '         . (int) $t['button_radius'] . 'px',
            '--p-card-r: '        . (int) $t['card_radius'] . 'px',
        ];

        return implode("\n", array_map(fn ($l) => $indent . $l . ';', $lines));
    }

    /**
     * Resolve a section's stored bg_color. 'inherit' (the new seeded default)
     * and blank both map to the token this section type should follow;
     * anything else is an explicit choice the tenant made in the builder and
     * is returned untouched.
     */
    public static function sectionBg(?string $stored, string $sectionType, array $t): string
    {
        if ($stored !== null && $stored !== '' && $stored !== 'inherit') {
            return $stored;
        }

        $key = self::SECTION_BG[$sectionType] ?? 'bg';

        return $t[$key] ?? $t['bg'];
    }

    /**
     * MARKER-CZFIX — flatten a colour to a 6-digit hex for DISPLAY only.
     *
     * <input type="color"> can only hold hex, so an rgba() token (which is what
     * surface/muted/border fall back to) showed as white in the customizer.
     * This composites rgba over the given background so the swatch tells the
     * truth. The stored value is never changed by this.
     */
    public static function toHex(?string $value, string $over = '#ffffff'): string
    {
        $value = trim((string) $value);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $m)) {
            return '#' . preg_replace('/(.)/', '$1$1', $m[1]);
        }

        if (preg_match('/^rgba?\(([^)]+)\)$/i', $value, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            $r = (int) ($parts[0] ?? 0);
            $g = (int) ($parts[1] ?? 0);
            $b = (int) ($parts[2] ?? 0);
            $a = isset($parts[3]) ? (float) $parts[3] : 1.0;
            $a = max(0.0, min(1.0, $a));

            $bg = preg_match('/^#[0-9a-fA-F]{6}$/', $over) ? $over : '#ffffff';
            $br = hexdec(substr($bg, 1, 2));
            $bgc = hexdec(substr($bg, 3, 2));
            $bb = hexdec(substr($bg, 5, 2));

            return sprintf(
                '#%02x%02x%02x',
                (int) round($r * $a + $br * (1 - $a)),
                (int) round($g * $a + $bgc * (1 - $a)),
                (int) round($b * $a + $bb * (1 - $a))
            );
        }

        return '#ffffff';
    }
}
