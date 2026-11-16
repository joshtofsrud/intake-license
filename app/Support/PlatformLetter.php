<?php

namespace App\Support;

use App\Models\Investor;

/**
 * master-admin campaigns written as a letter.
 *
 * Five content-first blocks (Letter, Numbers table, Screenshot + caption, Ask,
 * Signature) rendered as a plain, table-based email in Intake's own palette:
 * off-white and near-black, the lime used sparingly, Inter with system
 * fallbacks (mail clients rarely load webfonts). Master admin only — shops'
 * campaigns keep their own builder.
 *
 * Tokens: {first_name}, {shop_name}, {email}, and for investors {amount},
 * {percent}, {remaining}, {portal}. {{first_name}} style works too.
 */
class PlatformLetter
{
    public const TYPES = [
        'letter_text'      => 'Letter',
        'letter_numbers'   => 'Numbers table',
        'letter_shot'      => 'Screenshot + caption',
        'letter_ask'       => 'Ask',
        'letter_signature' => 'Signature',
        // MARKER-FEATURE-BLOCKS: the graphical blocks, in Intake's own
        // language (lime hero, receipt numbers, the dashboard's colour tiles,
        // annotated screenshots). Deliberately not the newsletter-template
        // moves (pill labels, series counters, arrow lists).
        'feat_hero'        => 'Lime hero',
        'feat_display'     => 'Display headline',
        'feat_lede'        => 'Lead paragraph',
        'feat_section'     => 'Section marker',
        'feat_headline'    => 'Headline',
        'feat_receipt'     => 'Receipt',
        'feat_tiles'       => 'Stat tiles',
        'feat_tray'        => 'Screenshot tray',
        'feat_annotated'   => 'Annotated screenshot',
        'feat_divider'     => 'Tear-off divider',
        'feat_button'      => 'Button',
    ];

    public static function isLetter(?array $blocks): bool
    {
        foreach ((array) $blocks as $b) {
            if (isset(self::TYPES[$b['type'] ?? ''])) {
                return true;
            }
        }
        return false;
    }

    /** A new block with starter content, so the editor never opens blank. */
    public static function blank(string $type): array
    {
        return ['type' => $type, 'data' => match ($type) {
            'letter_text'      => ['text' => ''],
            'letter_numbers'   => ['headers' => ['', 'Last month', 'This month', 'Change'], 'rows' => [['', '', '', '']]],
            'letter_shot'      => ['url' => '', 'caption' => ''],
            'letter_ask'       => ['title' => 'What would help', 'items' => "\n"],
            'letter_signature' => ['text' => "Thanks for backing this.\n— Josh", 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works'],
            'feat_hero'        => ['label' => '', 'text' => ''],
            'feat_display'     => ['text' => ''],
            'feat_lede'        => ['text' => ''],
            'feat_section'     => ['style' => 'square', 'num' => '01', 'text' => ''],
            'feat_headline'    => ['text' => ''],
            'feat_receipt'     => ['title' => '', 'rows' => [['', ''], ['', '']], 'total_label' => '', 'total_value' => ''],
            'feat_tiles'       => ['tiles' => [['label' => '', 'value' => '', 'note' => ''], ['label' => '', 'value' => '', 'note' => '']]],
            'feat_tray'        => ['url' => '', 'tab' => '', 'caption' => ''],
            'feat_annotated'   => ['url' => '', 'markers' => []],
            'feat_divider'     => [],
            'feat_button'      => ['label' => '', 'url' => ''],
            default            => [],
        }];
    }

    /** The starting layout for a new letter. */
    public static function starter(): array
    {
        $b = [self::blank('letter_text'), self::blank('letter_numbers'), self::blank('letter_shot'), self::blank('letter_ask'), self::blank('letter_signature')];
        $b[0]['data']['text'] = "Hi {first_name},\n\n";
        return $b;
    }

    public static function merge(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace(['{{' . $k . '}}', '{{ ' . $k . ' }}', '{' . $k . '}'], (string) $v, $text);
        }
        return $text;
    }

    /** Per-investor tokens, computed the same way the investor messages do. */
    public static function investorVars(?Investor $inv): array
    {
        if (! $inv) {
            return [];
        }
        $committed = (int) Investor::whereNull('declined_at')->whereNotNull('committed_at')->sum('amount');
        return [
            'amount'    => '$' . number_format((int) $inv->amount),
            'percent'   => $inv->percent . '%',
            'remaining' => '$' . number_format(max(0, Investor::target() - $committed)),
            'portal'    => $inv->portalUrl(),
        ];
    }

    public static function sampleVars(): array
    {
        return ['first_name' => 'Alex', 'shop_name' => 'Sample Shop', 'email' => 'alex@example.com',
                'amount' => '$25,000', 'percent' => '0.83%', 'remaining' => '$190,000', 'portal' => url('/invest/i/sample')];
    }

    public static function render(array $blocks, array $vars, string $theme = 'light', string $footer = '', ?string $unsubscribeUrl = null, string $preheader = ''): string
    {
        $dark = $theme === 'dark';
        $c = $dark
            ? ['bg' => '#0c0c0c', 'card' => '#0c0c0c', 'text' => '#f0f0f0', 'mut' => '#8a8a8a', 'rule' => '#262626', 'acc' => '#BEF264', 'soft' => '#161d0a', 'outer' => '#050505', 'body' => '#d2d2cf', 'btn' => '#BEF264', 'btnText' => '#0c0c0c']
            : ['bg' => '#fbfbf9', 'card' => '#fbfbf9', 'text' => '#1b1b1b', 'mut' => '#6b6b66', 'rule' => '#e7e7e2', 'acc' => '#4d7c0f', 'soft' => '#f1f5e6', 'outer' => '#efefea', 'body' => '#3d3d3a', 'btn' => '#0c0c0c', 'btnText' => '#BEF264'];
        $mono = "ui-monospace,'SF Mono',Menlo,Consolas,'Courier New',monospace";
        $c['tray']  = $dark ? '#161616' : '#efeee8';
        $c['paper'] = $dark ? '#141414' : '#ffffff';
        $c['ink']   = '#10140a';   // text on lime and on the tiles, both themes
        $tiles = ['#F2CC57', '#F09CC4', '#BEF264', '#8FB0F5'];
        $font = "Inter,-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $m = fn ($s) => $e(self::merge((string) $s, $vars));

        $html = '';
        foreach ($blocks as $b) {
            $d = (array) ($b['data'] ?? []);
            switch ($b['type'] ?? '') {
                case 'letter_text':
                    foreach (preg_split("/\n\s*\n/", trim(str_replace("\r", '', (string) ($d['text'] ?? '')))) as $p) {
                        if (trim($p) === '') { continue; }
                        $html .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:' . $c['text'] . '">' . nl2br($m($p)) . '</p>';
                    }
                    break;

                case 'letter_numbers':
                    $h = array_pad((array) ($d['headers'] ?? []), 4, '');
                    $rows = array_values(array_filter((array) ($d['rows'] ?? []), fn ($r) => trim(implode('', (array) $r)) !== ''));
                    if (! $rows) { break; }
                    $th = fn ($t, $al) => '<th align="' . $al . '" style="font-size:11.5px;font-weight:600;color:' . $c['mut'] . ';padding:0 0 8px;border-bottom:1px solid ' . $c['rule'] . '">' . $m($t) . '</th>';
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 22px;font-size:14.5px;color:' . $c['text'] . '"><tr>'
                        . $th($h[0], 'left') . $th($h[1], 'right') . $th($h[2], 'right') . $th($h[3], 'right') . '</tr>';
                    foreach ($rows as $r) {
                        $r = array_pad((array) $r, 4, '');
                        $td = 'padding:9px 0;border-bottom:1px solid ' . $c['rule'] . ';';
                        $html .= '<tr><td style="' . $td . '">' . $m($r[0]) . '</td><td align="right" style="' . $td . '">' . $m($r[1]) . '</td>'
                            . '<td align="right" style="' . $td . '">' . $m($r[2]) . '</td><td align="right" style="' . $td . 'color:' . $c['acc'] . ';font-weight:600">' . $m($r[3]) . '</td></tr>';
                    }
                    $html .= '</table>';
                    break;

                case 'letter_shot':
                    $url = trim((string) ($d['url'] ?? ''));
                    if ($url === '') { break; }
                    $html .= '<div style="margin:6px 0 22px"><img src="' . $e($url) . '" alt="' . $m($d['caption'] ?? 'Screenshot') . '" width="512" style="display:block;width:100%;max-width:512px;height:auto;border:1px solid ' . $c['rule'] . ';border-radius:8px">'
                        . (trim((string) ($d['caption'] ?? '')) !== '' ? '<div style="font-size:13px;color:' . $c['mut'] . ';margin-top:8px">' . $m($d['caption']) . '</div>' : '') . '</div>';
                    break;

                case 'letter_ask':
                    $items = array_values(array_filter(array_map('trim', explode("\n", (string) ($d['items'] ?? '')))));
                    if (! $items) { break; }
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 22px"><tr>'
                        . '<td style="border-left:3px solid ' . $c['acc'] . ';background:' . $c['soft'] . ';padding:14px 18px;border-radius:0 8px 8px 0;color:' . $c['text'] . ';font-size:15px;line-height:1.6">'
                        . '<div style="font-weight:650;margin-bottom:6px">' . $m($d['title'] ?? 'What would help') . '</div><ol style="margin:0;padding-left:20px">';
                    foreach ($items as $it) { $html .= '<li style="margin:4px 0">' . $m($it) . '</li>'; }
                    $html .= '</ol></td></tr></table>';
                    break;

                case 'letter_signature':
                    $html .= '<div style="margin:6px 0 10px;font-size:16px;line-height:1.6;color:' . $c['text'] . '">' . nl2br($m($d['text'] ?? ''))
                        . (trim((string) ($d['small'] ?? '')) !== '' ? '<div style="font-size:13px;color:' . $c['mut'] . ';margin-top:10px">' . $m($d['small']) . '</div>' : '') . '</div>';
                    break;
                // ---- feature blocks ----
                case 'feat_hero':
                    if (trim((string) ($d['text'] ?? '')) === '') { break; }
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 26px"><tr><td class="pl-heropad" style="background:#BEF264;border-radius:14px;padding:30px 28px 28px;color:' . $c['ink'] . '">'
                        . (trim((string) ($d['label'] ?? '')) !== '' ? '<div style="font-size:13px;font-weight:600;opacity:.7;margin-bottom:12px">' . $m($d['label']) . '</div>' : '')
                        . '<div class="pl-hero" style="font-family:' . $font . ';font-size:44px;line-height:1.04;font-weight:900;letter-spacing:-1.5px">' . nl2br($m($d['text'])) . '</div></td></tr></table>';
                    break;

                case 'feat_display':
                    if (trim((string) ($d['text'] ?? '')) === '') { break; }
                    $html .= '<h1 class="pl-display" style="margin:4px 0 22px;font-family:' . $font . ';font-size:44px;line-height:1.05;font-weight:800;letter-spacing:-1.4px;color:' . $c['text'] . '">' . nl2br($m($d['text'])) . '</h1>';
                    break;

                case 'feat_lede':
                    foreach (preg_split("/\n\s*\n/", trim(str_replace("\r", '', (string) ($d['text'] ?? '')))) as $p) {
                        if (trim($p) === '') { continue; }
                        $html .= '<p style="margin:0 0 18px;font-size:18px;line-height:1.6;color:' . $c['body'] . '">' . nl2br($m($p)) . '</p>';
                    }
                    break;

                case 'feat_section':
                    $num = trim((string) ($d['num'] ?? '')); $txt = trim((string) ($d['text'] ?? ''));
                    if ($num === '' && $txt === '') { break; }
                    $style = $d['style'] ?? 'square';
                    $lab = '<span style="font-family:' . $font . ';font-size:14px;font-weight:700;color:' . $c['text'] . '">';
                    if ($style === 'bars') {
                        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:40px 0 14px"><tr>'
                            . '<td style="width:10px;height:32px;background:#BEF264"></td><td style="width:4px"></td>'
                            . '<td style="width:10px;height:32px;background:#BEF264;opacity:.55"></td><td style="width:4px"></td>'
                            . '<td style="width:10px;height:32px;background:#BEF264;opacity:.25"></td>'
                            . '<td style="padding-left:14px">' . $lab . ($num !== '' ? $m($num) . '&nbsp;&nbsp;' : '') . $m($txt) . '</span></td></tr></table>';
                    } elseif ($style === 'rule') {
                        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:42px 0 12px"><tr><td style="width:46px;height:6px;background:' . $c['text'] . '"></td><td style="width:22px;height:6px;background:#BEF264"></td></tr></table>'
                            . '<div style="margin:0 0 12px">' . $lab . ($num !== '' ? $m($num) . ' / ' : '') . $m($txt) . '</span></div>';
                    } else {
                        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:40px 0 14px"><tr>'
                            . ($num !== '' ? '<td style="width:34px;height:34px;background:#BEF264;border-radius:7px;text-align:center;font-family:' . $font . ';font-size:15px;line-height:34px;font-weight:800;color:' . $c['ink'] . '">' . $m($num) . '</td>' : '')
                            . '<td style="padding-left:' . ($num !== '' ? '12' : '0') . 'px">' . $lab . $m($txt) . '</span></td></tr></table>';
                    }
                    break;

                case 'feat_headline':
                    if (trim((string) ($d['text'] ?? '')) === '') { break; }
                    $html .= '<h2 class="pl-h2" style="margin:0 0 14px;font-family:' . $font . ';font-size:32px;line-height:1.1;font-weight:800;letter-spacing:-1px;color:' . $c['text'] . '">' . nl2br($m($d['text'])) . '</h2>';
                    break;

                case 'feat_receipt':
                    $rows = array_values(array_filter((array) ($d['rows'] ?? []), fn ($r) => trim(implode('', (array) $r)) !== ''));
                    if (! $rows && trim((string) ($d['total_value'] ?? '')) === '') { break; }
                    $dot = 'border-bottom:1px dotted ' . ($dark ? '#4a4a4a' : '#bdbdb6');
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 26px"><tr><td style="background:' . $c['paper'] . ';border:1px solid ' . $c['rule'] . ';border-radius:6px;padding:18px 22px;font-family:' . $mono . ';font-size:14px;line-height:1.8;color:' . $c['text'] . '">';
                    if (trim((string) ($d['title'] ?? '')) !== '') {
                        $html .= '<div style="text-align:center;font-weight:700;letter-spacing:2px;text-transform:uppercase;font-size:13px">' . $m($d['title']) . '</div>'
                            . '<div style="border-top:1px dashed ' . $c['rule'] . ';margin:10px 0"></div>';
                    }
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:' . $mono . ';font-size:14px;color:' . $c['text'] . '">';
                    foreach ($rows as $r) {
                        $r = array_pad((array) $r, 2, '');
                        $html .= '<tr><td style="white-space:nowrap;padding:3px 0">' . $m($r[0]) . '</td><td style="width:100%;' . $dot . '"></td><td align="right" style="white-space:nowrap;padding:3px 0 3px 8px">' . $m($r[1]) . '</td></tr>';
                    }
                    $html .= '</table>';
                    if (trim((string) ($d['total_value'] ?? '')) !== '') {
                        $html .= '<div style="border-top:1px dashed ' . $c['rule'] . ';margin:10px 0"></div>'
                            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="font-family:' . $mono . ';font-weight:800;font-size:15px;text-transform:uppercase;color:' . $c['text'] . '">' . $m($d['total_label'] ?? 'Total') . '</td>'
                            . '<td align="right"><span style="background:#BEF264;color:' . $c['ink'] . ';font-family:' . $mono . ';font-weight:800;font-size:15px;padding:2px 7px">' . $m($d['total_value']) . '</span></td></tr></table>';
                    }
                    $html .= '</td></tr></table>';
                    break;

                case 'feat_tiles':
                    $ts = array_values(array_filter((array) ($d['tiles'] ?? []), fn ($t) => trim((string) ($t['value'] ?? '')) !== '' || trim((string) ($t['label'] ?? '')) !== ''));
                    if (! $ts) { break; }
                    $tile = function ($t, $k) use ($m, $c, $font, $tiles) {
                        return '<td class="pl-tile" valign="top" style="width:50%;padding:5px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:' . $tiles[$k % 4] . ';border-radius:18px;padding:18px 20px 16px;color:' . $c['ink'] . ';font-family:' . $font . '">'
                            . '<div style="font-size:13px;font-weight:600">' . $m($t['label'] ?? '') . '</div>'
                            . '<div style="font-size:40px;line-height:1.1;font-weight:700;letter-spacing:-1.4px;margin-top:18px">' . $m($t['value'] ?? '') . '</div>'
                            . (trim((string) ($t['note'] ?? '')) !== '' ? '<div style="font-size:12.5px;opacity:.72;margin-top:2px">' . $m($t['note']) . '</div>' : '')
                            . '</td></tr></table></td>';
                    };
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px">';
                    foreach (array_chunk($ts, 2, true) as $pair) {
                        $html .= '<tr>';
                        foreach ($pair as $k => $t) { $html .= $tile($t, $k); }
                        if (count($pair) === 1) { $html .= '<td class="pl-tile" style="width:50%"></td>'; }
                        $html .= '</tr>';
                    }
                    $html .= '</table>';
                    break;

                case 'feat_tray':
                    $url = trim((string) ($d['url'] ?? ''));
                    if ($url === '') { break; }
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 24px"><tr><td style="background:' . $c['tray'] . ';border-radius:12px;padding:12px 12px 12px">'
                        . (trim((string) ($d['tab'] ?? '')) !== '' ? '<span style="display:inline-block;background:#BEF264;color:' . $c['ink'] . ';font-family:' . $font . ';font-size:11.5px;font-weight:700;letter-spacing:.5px;padding:4px 11px;border-radius:6px 6px 0 0">' . $m($d['tab']) . '</span>' : '')
                        . '<img src="' . $e($url) . '" alt="' . $m($d['tab'] ?? 'Screenshot') . '" width="516" style="display:block;width:100%;max-width:516px;height:auto;border:0;border-radius:' . (trim((string) ($d['tab'] ?? '')) !== '' ? '0 8px 8px 8px' : '8px') . '">'
                        . '</td></tr></table>'
                        . (trim((string) ($d['caption'] ?? '')) !== '' ? '<div style="font-size:13px;color:' . $c['mut'] . ';margin:-14px 0 24px">' . $m($d['caption']) . '</div>' : '');
                    break;

                case 'feat_annotated':
                    $url = trim((string) ($d['url'] ?? ''));
                    if ($url === '') { break; }
                    $marks = array_values((array) ($d['markers'] ?? []));
                    $img = $marks ? (AnnotatedShot::url($url, $marks) ?? $url) : $url;
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px"><tr><td style="background:' . $c['ink'] . ';border-radius:14px;padding:14px">'
                        . '<img src="' . $e($img) . '" alt="Annotated screenshot" width="512" style="display:block;width:100%;max-width:512px;height:auto;border:0;border-radius:8px"></td></tr></table>';
                    if ($marks) {
                        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px">';
                        foreach ($marks as $k => $mk) {
                            $t = trim((string) ($mk['title'] ?? '')); $n = trim((string) ($mk['note'] ?? ''));
                            $html .= '<tr><td valign="top" style="width:40px;padding:0 0 16px"><div style="width:26px;height:26px;border-radius:50%;background:#BEF264;color:' . $c['ink'] . ';font-family:' . $font . ';font-size:13px;line-height:26px;font-weight:800;text-align:center">' . ($k + 1) . '</div></td>'
                                . '<td style="padding:0 0 16px;font-size:16px;line-height:1.6;color:' . $c['body'] . '">' . ($t !== '' ? '<b style="color:' . $c['text'] . '">' . $m($t) . '</b> ' : '') . $m($n) . '</td></tr>';
                        }
                        $html .= '</table>';
                    }
                    break;

                case 'feat_divider':
                    $html .= '<div style="margin:34px 0 8px;border-top:2px dashed ' . ($dark ? '#2e2e2e' : '#d6d5ce') . ';height:0;line-height:0;font-size:0">&nbsp;</div>';
                    break;

                case 'feat_button':
                    $label = trim((string) ($d['label'] ?? ''));
                    $url = trim(self::merge((string) ($d['url'] ?? ''), $vars));
                    if ($label === '' || $url === '') { break; }
                    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:10px 0 28px"><tr><td style="background:' . $c['btn'] . ';border-radius:8px">'
                        . '<a href="' . $e($url) . '" style="display:inline-block;padding:14px 24px;font-family:' . $font . ';font-size:15px;font-weight:700;color:' . $c['btnText'] . ';text-decoration:none">' . $m($label) . '</a></td></tr></table>';
                    break;
            }
        }

        $foot = $e($footer) . ($unsubscribeUrl ? ' <a href="' . $e($unsubscribeUrl) . '" style="color:' . $c['mut'] . '">Unsubscribe</a>' : '');
        $postal = \App\Services\Platform\PlatformMailer::postalAddress();

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="' . ($dark ? 'dark' : 'light') . '">'
            // narrow screens: smaller display type, tighter gutters, tiles stacked
            . '<style>@media (max-width:520px){.pl-pad{padding-left:22px!important;padding-right:22px!important}'
            . '.pl-display,.pl-hero{font-size:32px!important;letter-spacing:-1px!important}.pl-h2{font-size:25px!important}'
            . '.pl-heropad{padding:24px 20px 22px!important}.pl-tile{display:block!important;width:100%!important;box-sizing:border-box}}</style></head>'
            . '<body style="margin:0;padding:0;background:' . $c['outer'] . ';font-family:' . $font . '">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden">' . $m($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['outer'] . ';padding:28px 12px"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:' . $c['card'] . ';border-radius:10px">'
            . '<tr><td class="pl-pad" style="padding:34px 40px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            // the real logo from master admin › Brand: the email slot
            // (dark wordmark) on light cards, the light wordmark on the dark theme.
            . '<td style="padding-bottom:18px;border-bottom:1px solid ' . $c['rule'] . '"><img src="' . $e(\App\Support\Brand::url($dark ? 'logo' : 'email')) . '" alt="Intake" height="26" style="display:block;height:26px;width:auto;border:0;outline:none"></td>'
            . '</tr></table></td></tr>'
            . '<tr><td class="pl-pad" style="padding:26px 40px 12px;font-family:' . $font . '">' . $html . '</td></tr>'
            . '<tr><td class="pl-pad" style="padding:14px 40px 26px;border-top:1px solid ' . $c['rule'] . ';font-size:11.5px;line-height:1.6;color:' . $c['mut'] . '">'
            . $foot . ($postal ? '<br>' . $e('Intake · ' . $postal) : '') . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** One line of what a block holds, for its folded header in the composer. */
    public static function summary(array $b): string
    {
        $d = (array) ($b['data'] ?? []);
        $one = fn ($t) => trim(preg_replace('/\s+/', ' ', (string) $t));
        $out = match ($b['type'] ?? '') {
            'letter_text', 'feat_display', 'feat_lede', 'feat_headline' => $one($d['text'] ?? ''),
            'feat_hero'        => $one(($d['label'] ?? '') !== '' ? $d['label'] . ' · ' . ($d['text'] ?? '') : ($d['text'] ?? '')),
            'letter_numbers'   => count(array_filter((array) ($d['rows'] ?? []), fn ($r) => trim(implode('', (array) $r)) !== '')) . ' rows',
            'letter_shot', 'feat_tray' => ($d['url'] ?? '') !== '' ? $one(($d['tab'] ?? '') ?: ($d['caption'] ?? '') ?: 'Image chosen') : 'No image yet',
            'feat_annotated'   => ($d['url'] ?? '') !== '' ? count((array) ($d['markers'] ?? [])) . ' markers' : 'No image yet',
            'letter_ask'       => $one($d['title'] ?? ''),
            'letter_signature' => $one($d['text'] ?? ''),
            'feat_section'     => $one(trim(($d['num'] ?? '') . ' ' . ($d['text'] ?? ''))),
            'feat_receipt'     => $one(($d['title'] ?? '') ?: 'Receipt') . (($d['total_value'] ?? '') !== '' ? ' · ' . $d['total_label'] . ' ' . $d['total_value'] : ''),
            'feat_tiles'       => implode(' · ', array_filter(array_map(fn ($t) => $one($t['value'] ?? ''), (array) ($d['tiles'] ?? [])))),
            'feat_button'      => $one($d['label'] ?? ''),
            'feat_divider'     => 'Perforated line',
            default            => '',
        };
        return $out !== '' ? mb_strimwidth($out, 0, 90, '…') : 'Empty';
    }

    /** Why this person is getting the email, by audience source. */
    public static function footerFor(?string $source): string
    {
        return match ($source) {
            'investors'     => "You're receiving this because you're an investor in Intake Inc. Reply to reach Josh directly.",
            'inv_leads'     => "You're receiving this because you asked for updates about Intake.",
            'tenants', 'tenant_owners' => "You're receiving this as an Intake shop owner.",
            'prospects'     => "You're receiving this because your shop has talked with Intake.",
            'reps'          => "You're receiving this as an Intake partner.",
            default         => "You're receiving this from Intake.",
        };
    }
}
