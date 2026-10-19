<?php

namespace App\Support;

/**
 * The common outer settings of a shop section ported from intake.works:
 * spacing (padding_override), background (none / color / gradient), text
 * color that reads on that background, accent, anchor, custom classes and
 * hide on phone/desktop. Returns what a renderer needs so the four ported
 * sections (features accordion, feature tiles, feature groups, comparison
 * table) don't each repeat it.
 */
class ShopSectionShell
{
    public static function make(array $c, $section, string $prefix): array
    {
        $id  = $prefix . '-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) ? strtolower(trim($v)) : null;

        $pad = [
            'compact'  => 'clamp(40px, 5vw, 56px)',
            'normal'   => 'clamp(56px, 8vw, 96px)',
            'spacious' => 'clamp(80px, 10vw, 140px)',
        ][$c['padding_override'] ?? 'normal'] ?? 'clamp(56px, 8vw, 96px)';

        $mode = $c['bg_mode'] ?? 'none';
        $bg = '';
        $under = null; // the color text sits on, when we know it
        if ($mode === 'color' && ($col = $hex($c['bg_color'] ?? null))) {
            $bg = $col; $under = $col;
        } elseif ($mode === 'gradient') {
            $from = $hex($c['bg_gradient_from'] ?? null) ?: '#0a0f1a';
            $to   = $hex($c['bg_gradient_to'] ?? null) ?: '#0f1828';
            $bg   = 'linear-gradient(' . (int) ($c['bg_gradient_angle'] ?? 135) . 'deg, ' . $from . ' 0%, ' . $to . ' 100%)';
            $under = $from;
        }
        $text = $under ? ColorHelper::accentTextColor($under) : 'var(--p-text, #0a0a0a)';

        $accent = $hex($c['accent_color'] ?? null) ?: 'var(--p-accent, #0a0a0a)';
        $accentText = $hex($c['accent_color'] ?? null)
            ? ColorHelper::accentTextColor($hex($c['accent_color']))
            : 'var(--p-accent-text, #ffffff)';

        $on = fn ($k) => ! empty($c[$k]) && ! in_array((string) $c[$k], ['0', 'false'], true);

        $css = '.' . $id . ' { padding: ' . $pad . ' 0; color: ' . $text . ';' . ($bg !== '' ? ' background: ' . $bg . ';' : '') . ' }';
        if ($on('hide_on_mobile'))  $css .= ' @media (max-width: 768px) { .' . $id . ' { display: none; } }';
        if ($on('hide_on_desktop')) $css .= ' @media (min-width: 769px) { .' . $id . ' { display: none; } }';

        $anchor  = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
        $classes = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($c['custom_classes'] ?? '')));

        $heading = e($c['heading'] ?? '');
        $aw = trim((string) ($c['accent_words'] ?? ''));
        if ($aw !== '' && stripos($heading, e($aw)) !== false) {
            $heading = preg_replace('/' . preg_quote(e($aw), '/') . '/i', '<span style="color:' . $accent . '">$0</span>', $heading, 1);
        }

        return [
            'id'          => $id,
            'css'         => $css,
            'attrs'       => 'class="' . e(trim($id . ' p-sec ' . $classes)) . '"' . ($anchor !== '' ? ' id="' . e($anchor) . '"' : ''),
            'text'        => $text,
            'accent'      => $accent,
            'accentText'  => $accentText,
            'heading'     => $heading,
            'dark'        => $under ? ColorHelper::isDark($under) : null,
        ];
    }
}
