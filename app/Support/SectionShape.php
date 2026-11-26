<?php

namespace App\Support;

/**
 * MARKER-SECTION-SHAPE: a shape along the bottom edge of a section — wave,
 * swell, curve, tilt or peaks — drawn in the color of the section below so
 * the edge reads as a shaped boundary instead of a straight line. An optional
 * stripe runs along the edge in a two-color gradient.
 *
 * Shared by shop sites (ShopSectionDesign) and intake.works (marketing/page).
 * Content keys: shape_below, shape_height, shape_height_phone, shape_color
 * (blank = the next section's color), shape_stripe_from, shape_stripe_to,
 * shape_flip.
 */
class SectionShape
{
    public const SHAPES = ['none' => 'None', 'wave' => 'Wave', 'swell' => 'Swell', 'curve' => 'Curve', 'tilt' => 'Tilt', 'peaks' => 'Peaks'];

    // viewBox 0 0 1440 100; every path closes along the bottom edge
    private const PATHS = [
        'wave'  => 'M0,58 C240,100 480,100 720,58 C960,16 1200,16 1440,58 L1440,100 L0,100 Z',
        'swell' => 'M0,92 C420,96 900,70 1440,8 L1440,100 L0,100 Z',
        'curve' => 'M0,0 Q720,150 1440,0 L1440,100 L0,100 Z',
        'tilt'  => 'M0,100 L1440,0 L1440,100 Z',
        'peaks' => 'M0,100 L200,52 L380,78 L640,14 L900,66 L1140,30 L1440,84 L1440,100 Z',
    ];

    // the stripe: the same edge, lifted, as a band above the fill
    private const STRIPES = [
        'wave'  => 'M0,44 C240,86 480,86 720,44 C960,2 1200,2 1440,44 L1440,58 C1200,16 960,16 720,58 C480,100 240,100 0,58 Z',
        'swell' => 'M0,78 C420,82 900,52 1440,0 L1440,8 C900,70 420,96 0,92 Z',
        'curve' => 'M0,0 Q720,130 1440,0 L1440,0 Q720,150 0,0 Z',
        'tilt'  => 'M0,88 L1440,0 L1440,0 L0,100 Z',
        'peaks' => 'M0,86 L200,38 L380,64 L640,0 L900,52 L1140,16 L1440,70 L1440,84 L1140,30 L900,66 L640,14 L380,78 L200,52 L0,100 Z',
    ];

    public static function shape(array $c): string
    {
        $s = (string) ($c['shape_below'] ?? 'none');
        return isset(self::PATHS[$s]) ? $s : 'none';
    }

    public static function on(array $c): bool
    {
        return self::shape($c) !== 'none';
    }

    private static function color($v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
    }

    /**
     * The markup that sits inside the section's (position:relative) wrapper.
     * $autoColor is the next section's start color, used when no color is set.
     */
    public static function html(array $c, string $autoColor, string $uid): string
    {
        $shape = self::shape($c);
        if ($shape === 'none') return '';

        $h     = max(20, min(240, (int) ($c['shape_height'] ?? 90)));
        $hp    = max(10, min(240, (int) ($c['shape_height_phone'] ?? (int) round($h * 0.5))));
        $fill  = self::color($c['shape_color'] ?? null) ?: (self::color($autoColor) ?: $autoColor);
        $from  = self::color($c['shape_stripe_from'] ?? null);
        $to    = self::color($c['shape_stripe_to'] ?? null) ?: $from;
        $flip  = ! empty($c['shape_flip']) && ! in_array((string) $c['shape_flip'], ['0', 'false'], true);
        $gid   = 'pbsg-' . $uid;
        $cls   = 'pbshape-' . $uid;

        $svg = '<svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        if ($from) {
            $svg .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="1" y2="0">'
                . '<stop offset="0" stop-color="' . e($from) . '"/><stop offset="1" stop-color="' . e($to) . '"/></linearGradient></defs>'
                . '<path d="' . self::STRIPES[$shape] . '" fill="url(#' . $gid . ')"/>';
        }
        $svg .= '<path d="' . self::PATHS[$shape] . '" fill="' . e($fill) . '"/></svg>';

        return '<style>.' . $cls . '{position:absolute;left:0;right:0;bottom:-1px;height:' . $h . 'px;pointer-events:none;z-index:1;line-height:0'
            . ($flip ? ';transform:scaleX(-1)' : '') . '}'
            . '.' . $cls . ' svg{display:block;width:100%;height:100%}'
            . '@media (max-width:768px){.' . $cls . '{height:' . $hp . 'px}}</style>'
            . '<div class="' . $cls . '">' . $svg . '</div>';
    }
}
