<?php

namespace App\Support;

/**
 * Header style for shop sites, read from the nav section's content.
 * Same settings as the intake.works header (App\Support\MarketingNav), kept
 * as flat keys on the section so the builder saves them like any field:
 *
 *   hdr_style            classic | float          (desktop)
 *   fl_*                 desktop bar settings
 *   flp_own              phone uses its own bar settings (flp_*)
 *   flp_btn_pos/_dist    phone-only: where the header buttons sit
 *   flp_menu_bg/_link    phone menu colors (both styles)
 *
 * Nothing here changes a site until a setting is changed: a nav with none of
 * these keys resolves to Classic with no overrides, and renders as before.
 */
class ShopNavHeader
{
    public const BREAK = 768; // matches the shop nav's own phone breakpoint

    private const SPACE_D = [
        'tight'  => ['10px', '8px 10px 8px 18px'],
        'normal' => ['18px', '12px 14px 12px 24px'],
        'roomy'  => ['26px', '16px 18px 16px 30px'],
    ];
    private const SPACE_P = [
        'tight'  => ['8px',  '6px 6px 6px 14px'],
        'normal' => ['10px', '8px 8px 8px 16px'],
        'roomy'  => ['14px', '10px 10px 10px 18px'],
    ];

    /** ['d' => desktop settings, 'p' => phone settings] */
    public static function resolve(array $c): array
    {
        $baseBg = self::hex($c['bg_color'] ?? null, '#ffffff');
        $d = self::clean($c, 'fl_', self::str($c['hdr_style'] ?? ''), $baseBg);

        $own = self::on($c['flp_own'] ?? false);
        $p = $own ? self::clean($c, 'flp_', self::str($c['flp_style'] ?? ''), $baseBg) : $d;
        // shared by both screens, whatever the phone override says
        foreach (['link_weight', 'link_bright', 'btn_text', 'btn_fill'] as $k) $p[$k] = $d[$k];

        // phone-only settings
        $p['btn_pos']   = ($c['flp_btn_pos'] ?? '') === 'menu' ? 'menu' : 'bar';
        $p['btn_dist']  = self::int($c['flp_btn_dist'] ?? null, 0, 200, 0);
        $p['menu_bg']   = self::hex($c['flp_menu_bg'] ?? null, '');
        $p['menu_link'] = self::hex($c['flp_menu_link'] ?? null, '');
        $p['own']       = $own;

        return ['d' => $d, 'p' => $p];
    }

    private static function clean(array $c, string $px, string $style, string $baseBg): array
    {
        $bg = self::hex($c[$px . 'bg'] ?? null, $baseBg);
        $topGap = $c[$px . 'top_gap'] ?? -1;
        return [
            'style'         => $style === 'float' ? 'float' : 'classic',
            'bg'            => $bg,
            'opacity'       => self::int($c[$px . 'opacity'] ?? null, 0, 100, 70),
            'blur'          => self::int($c[$px . 'blur'] ?? null, 0, 30, 14),
            'pill'          => self::hex($c[$px . 'pill'] ?? null, ColorHelper::isDark($bg) ? '#ffffff' : '#000000'),
            'pill_strength' => self::int($c[$px . 'pill_strength'] ?? null, 0, 30, 6),
            'space'         => in_array($c[$px . 'space'] ?? '', ['tight', 'normal', 'roomy'], true) ? $c[$px . 'space'] : 'normal',
            'top_gap'       => (is_numeric($topGap) && (int) $topGap >= 0) ? min(80, (int) $topGap) : -1, // -1 = preset
            'pad_x'         => self::int($c[$px . 'pad_x'] ?? null, 0, 60, 0), // 0 = preset
            'fade'          => self::on($c[$px . 'fade'] ?? false),
            'link_weight'   => in_array((string) ($c['fl_link_weight'] ?? ''), ['400', '500', '600'], true) ? (string) $c['fl_link_weight'] : '500',
            'link_bright'   => ($c['fl_link_bright'] ?? '') === 'bright' ? 'bright' : 'soft',
            'btn_text'      => self::hex($c['fl_btn_text'] ?? null, ''),
            'btn_fill'      => self::hex($c['fl_btn_fill'] ?? null, ''),
        ];
    }

    /** Space above the bar and the bar's own padding, for desktop or phone. */
    public static function spacing(array $h, bool $phone): array
    {
        [$out, $pad] = ($phone ? self::SPACE_P : self::SPACE_D)[$h['space']];
        if ($h['pad_x']) {
            $p = explode(' ', $pad);
            $p[1] = $p[3] = $h['pad_x'] . 'px';
            $pad = implode(' ', $p);
        }
        if ($h['top_gap'] >= 0) $out = $h['top_gap'] . 'px';
        return [$out, $pad];
    }

    /**
     * Link color when the tenant left it on auto: dark on a light floating bar
     * that is solid enough to read against, light on a dark one. Null = keep
     * the nav's existing automatic color.
     */
    public static function autoLink(array $h): ?string
    {
        if ($h['style'] !== 'float' || $h['opacity'] < 40) return null;
        return ColorHelper::isDark($h['bg']) ? '#ffffff' : '#0a0a0a';
    }

    /** The CSS custom properties one screen size uses. */
    public static function vars(array $h, bool $phone, string $link): string
    {
        [$out, $pad] = self::spacing($h, $phone);
        return '--pf-bg:' . $h['bg'] . ';--pf-op:' . $h['opacity'] . '%;--pf-blur:' . $h['blur'] . 'px;'
            . '--pf-pill:color-mix(in srgb, ' . $h['pill'] . ' ' . $h['pill_strength'] . '%, transparent);'
            . '--pf-edge:' . (ColorHelper::isDark($h['bg']) ? '#ffffff' : '#000000') . ';'
            . '--pf-out:' . $out . ';--pf-pad:' . $pad . ';--pf-link:' . $link . ';'
            . '--pf-fade:' . ($h['fade'] ? '1' : '0') . ';';
    }

    private static function hex($v, string $fallback): string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
    }

    private static function int($v, int $min, int $max, int $fallback): int
    {
        return max($min, min($max, is_numeric($v) ? (int) $v : $fallback));
    }

    private static function on($v): bool
    {
        return ! empty($v) && ! in_array((string) $v, ['0', 'false'], true);
    }

    private static function str($v): string
    {
        return is_string($v) ? $v : '';
    }
}
