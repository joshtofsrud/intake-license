<?php

namespace App\Support;

/**
 * Per-section design settings for shop sites, ported from intake.works
 * (marketing/page.blade.php): appear on scroll, background blend (opacity,
 * gradient end, fade out, continue from the section above), button effects,
 * hide on tablet and a divider line.
 *
 * The shop renderer wraps a section in a .pbw div only when one of these is
 * set on it, so a page that never touched them renders exactly as before.
 * The one exception: when any section on a page continues the gradient above,
 * every section on that page is wrapped, because joining gradients needs to
 * see each section in order.
 */
class ShopSectionDesign
{
    // shop section types whose renderer paints a color/gradient background
    public const BLEND_TYPES = [
        'contact_form', 'cta_banner', 'faq_accordion', 'feature_grid', 'footer', 'hero', 'logo_bar',
        'pricing_table', 'rental_browse', 'rental_categories', 'rental_spotlight', 'rentals_showcase',
        'services', 'stats_row', 'step_timeline', 'text_image',
        'features_accordion', 'feature_tiles', 'comparison_table', // MARKER-SHOP-PORT4
    ];

    public static function on($v): bool
    {
        return ! empty($v) && ! in_array((string) $v, ['0', 'false'], true);
    }

    public static function supportsBlend(string $type): bool
    {
        return in_array($type, self::BLEND_TYPES, true);
    }

    /** Does any section on this page continue the gradient above it? */
    public static function pageContinues($sections): bool
    {
        foreach ($sections as $s) {
            if (! ($s->is_visible ?? true)) continue;
            $c = $s->content ?? [];
            if (self::supportsBlend((string) $s->section_type) && ($c['bg_mode'] ?? '') === 'gradient' && self::on($c['bg_continue'] ?? false)) return true;
        }
        return false;
    }

    /**
     * The wrapper for one section, or null when it needs none.
     * $c is the content the renderer uses (bg_color already resolved).
     * Returns ['open' => html, 'close' => html].
     */
    public static function wrap($section, array $c, bool $forceWrap): ?array
    {
        $type   = (string) $section->section_type;
        $appear = in_array($c['appear'] ?? '', ['fade', 'up'], true) ? $c['appear'] : '';
        $fx     = in_array($c['btn_fx'] ?? '', ['arrow', 'roll', 'sweep', 'glow'], true) ? $c['btn_fx'] : '';
        $ripple = self::on($c['btn_ripple'] ?? false);
        $tablet = self::on($c['hide_on_tablet'] ?? false);
        $divide = self::on($c['divider_below'] ?? false);

        $blend  = self::supportsBlend($type) ? self::blend($c) : ['css' => '', 'grad' => '', 'end' => '', 'cont' => ''];
        $any    = $appear || $fx || $ripple || $tablet || $divide || $blend['css'] !== '' || $blend['cont'] !== '';
        if (! $any && ! $forceWrap) return null;

        $id = 'pbw-' . substr(md5((string) $section->id), 0, 8);
        $class = 'pbw ' . $id
            . (self::on($c['hide_on_mobile'] ?? false) ? ' pbw-hide-m' : '')
            . (self::on($c['hide_on_desktop'] ?? false) ? ' pbw-hide-d' : '')
            . ($tablet ? ' pbw-hide-t' : '')
            . ($divide ? ' pbw-divider' : '')
            . ($appear ? ' pbw-appear' . ($appear === 'up' ? ' pbw-appear-up' : '') : '');

        $attrs = ' class="' . e($class) . '"';
        if ($fx !== '') $attrs .= ' data-btn-fx="' . e($fx) . '"';
        if ($ripple) $attrs .= ' data-btn-ripple="1"';
        if ($appear) {
            $attrs .= ' style="--pb-appear-delay: ' . max(0, min(1000, (int) ($c['appear_delay'] ?? 0))) . 'ms; '
                . '--pb-appear-dur: ' . max(200, min(2000, (int) ($c['appear_duration'] ?? 700))) . 'ms"';
        }
        if ($blend['grad'] !== '') $attrs .= ' data-bg-grad="' . e($blend['grad']) . '"';
        if ($blend['cont'] !== '') $attrs .= ' data-bg-cont="1" data-bg-cont-css="' . e($blend['cont']) . '"';
        $attrs .= ' data-bg-end="' . e($blend['end']) . '"';

        $css = $blend['css'] !== ''
            ? '<style>.' . $id . ' > section, .' . $id . ' > footer { background: ' . $blend['css'] . ' !important; }</style>'
            : '';

        return ['open' => $css . '<div' . $attrs . '>', 'close' => '</div>'];
    }

    /**
     * MARKER-HERO-IMAGE-FADE: the color a section starts with at its top edge, so the
     * section above can fade into it. $c is the section's content with bg_color
     * already resolved by the layout; $pageBg is the page background.
     */
    public static function startColor(array $c, string $pageBg): string
    {
        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
        $mode = $c['bg_mode'] ?? 'none';
        if ($mode === 'gradient') return $hex($c['bg_gradient_from'] ?? null) ?: '#ffffff';
        if (in_array($mode, ['color', 'solid', 'image'], true)) return $hex($c['bg_color'] ?? null) ?: $pageBg;
        return $pageBg;
    }

    /**
     * css:  a background override, only when a blend setting differs from the default
     * grad: this section's gradient, for joining with sections that continue it
     * end:  the color this section ends on (what a continuing section starts from)
     * cont: this section's own gradient with {PREV} for the color above it
     */
    public static function blend(array $c): array
    {
        $out  = ['css' => '', 'grad' => '', 'end' => '', 'cont' => ''];
        $mode = $c['bg_mode'] ?? 'none';
        $hex  = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
        $op   = max(0, min(100, (int) ($c['bg_opacity'] ?? 100)));
        $fade = fn ($col) => $op >= 100 ? $col : 'color-mix(in srgb, ' . $col . ' ' . $op . '%, transparent)';

        if ($mode === 'gradient') {
            $from  = $hex($c['bg_gradient_from'] ?? null) ?: '#ffffff';
            $to    = $hex($c['bg_gradient_to'] ?? null) ?: '#fafafa';
            $angle = (int) ($c['bg_gradient_angle'] ?? 135);
            $end   = max(20, min(100, (int) ($c['bg_grad_end'] ?? 100)));
            $out_  = self::on($c['bg_fade_out'] ?? false);
            $toCss = $out_ ? 'color-mix(in srgb, ' . $to . ' 0%, transparent)' : $fade($to);
            $grad  = 'linear-gradient(' . $angle . 'deg, ' . $fade($from) . ' 0%, ' . $toCss . ' ' . $end . '%)';
            $out['grad'] = $grad;
            $out['end']  = $out_ ? '' : $fade($to);
            if ($op < 100 || $end < 100 || $out_) $out['css'] = $grad;
            if (self::on($c['bg_continue'] ?? false)) {
                $out['cont'] = 'linear-gradient(180deg, {PREV} 0%, ' . $toCss . ' ' . $end . '%)';
            }
        } elseif ($mode === 'color') {
            $col = $hex($c['bg_color'] ?? null);
            if ($col) {
                $out['end'] = $fade($col);
                if ($op < 100) $out['css'] = $fade($col);
            }
        }
        return $out;
    }
}
