<?php

namespace App\Support;

/**
 * MARKER-SECTION-ROWS — sections side by side.
 *
 * Each section has a width (content.col_width): full, half, third or
 * twothirds. Consecutive narrower sections that fit share a row; one that
 * doesn't fit starts the next row; a full-width section always stands alone.
 * A row takes its gap and alignment from its first section (row_gap,
 * row_align). On phones every row stacks. Used by both renderers
 * (intake.works and shop sites) so they lay rows out identically.
 */
class SectionRows
{
    /** Share of a 6-column row. */
    public const WEIGHTS = ['half' => 3, 'third' => 2, 'twothirds' => 4];
    public const GAPS    = ['s' => '12px', 'm' => '24px', 'l' => '40px'];
    public const ALIGNS  = ['start' => 'start', 'center' => 'center', 'stretch' => 'stretch'];

    public static function width($section): string
    {
        if (in_array($section->section_type ?? '', ['nav', 'footer'], true)) return 'full';
        $v = (string) (($section->content ?? [])['col_width'] ?? 'full');
        return isset(self::WEIGHTS[$v]) ? $v : 'full';
    }

    /**
     * @param iterable $sections in page order
     * @param callable $renders  fn($section): bool — does this renderer draw it?
     * @return array<string, array{open?:array, cell?:bool, close?:bool, filler?:int}>
     */
    public static function plan(iterable $sections, callable $renders): array
    {
        $out = []; $row = []; $sum = 0;
        $flush = function () use (&$row, &$sum, &$out) {
            if (! $row) return;
            $first = $row[0]; $last = $row[count($row) - 1];
            $c = (array) ($first->content ?? []);
            $cols = array_map(fn ($s) => self::WEIGHTS[self::width($s)] . 'fr', $row);
            $fill = 6 - $sum;
            if ($fill > 0) $cols[] = $fill . 'fr';
            $out[(string) $first->id]['open'] = [
                'gap'   => self::GAPS[$c['row_gap'] ?? 'm'] ?? '24px',
                'align' => self::ALIGNS[$c['row_align'] ?? 'stretch'] ?? 'stretch',
                'cols'  => implode(' ', $cols),
            ];
            foreach ($row as $s) $out[(string) $s->id]['cell'] = true;
            $out[(string) $last->id]['close']  = true;
            $out[(string) $last->id]['filler'] = $fill;
            $row = []; $sum = 0;
        };
        foreach ($sections as $s) {
            if (! $renders($s)) continue;
            $w = self::width($s);
            if ($w === 'full') { $flush(); continue; }
            $n = self::WEIGHTS[$w];
            if ($sum + $n > 6) $flush();
            $row[] = $s; $sum += $n;
        }
        $flush();
        return $out;
    }

    public static function open(array $plan, $section): string
    {
        $p = $plan[(string) $section->id] ?? null;
        if (! $p || empty($p['cell'])) return '';
        $html = '';
        if (! empty($p['open'])) {
            $o = $p['open'];
            $html .= '<div class="pbrow pbrow-al-' . e($o['align']) . '" style="--pbrow-gap:' . e($o['gap']) . ';grid-template-columns:' . e($o['cols']) . '">';
        }
        return $html . '<div class="pbrow-cell" data-pbrow-w="' . e(self::width($section)) . '">';
    }

    public static function close(array $plan, $section): string
    {
        $p = $plan[(string) $section->id] ?? null;
        if (! $p || empty($p['cell'])) return '';
        $html = '</div>';
        if (! empty($p['close'])) {
            if (($p['filler'] ?? 0) > 0) $html .= '<div class="pbrow-filler" aria-hidden="true"></div>';
            $html .= '</div>';
        }
        return $html;
    }

    /** One stylesheet per page. $max / $gutter match the renderer's own container. */
    public static function css(array $plan, string $max, string $gutter): string
    {
        if (! $plan) return '';
        return '<style>/* MARKER-SECTION-ROWS */'
            . '.pbrow{display:grid;gap:var(--pbrow-gap,24px);max-width:calc(' . $max . ' + 2 * ' . $gutter . ');margin:0 auto;padding:var(--pbrow-gap,24px) ' . $gutter . ';box-sizing:border-box}'
            . '.pbrow-al-start{align-items:start}.pbrow-al-center{align-items:center}.pbrow-al-stretch{align-items:stretch}'
            . '.pbrow-cell{min-width:0;border-radius:14px;overflow:hidden;position:relative}'
            . '.pbrow-al-stretch>.pbrow-cell>*,.pbrow-al-stretch>.pbrow-cell>*>*:not(style),.pbrow-al-stretch>.pbrow-cell>*>*>section{height:100%}'
            . '.pbrow-cell section{border-bottom:0 !important}'
            . '@media (max-width:768px){.pbrow{grid-template-columns:1fr !important;padding-left:16px;padding-right:16px}.pbrow-filler{display:none}}'
            . '</style>';
    }
}
