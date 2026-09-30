<?php

namespace App\Support;

use App\Models\Investor;

/**
 * MARKER-PLATFORM-LETTER — master-admin campaigns written as a letter.
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
            ? ['bg' => '#0c0c0c', 'card' => '#0c0c0c', 'text' => '#f0f0f0', 'mut' => '#8a8a8a', 'rule' => '#262626', 'acc' => '#BEF264', 'soft' => '#161d0a', 'outer' => '#050505']
            : ['bg' => '#fbfbf9', 'card' => '#fbfbf9', 'text' => '#1b1b1b', 'mut' => '#6b6b66', 'rule' => '#e7e7e2', 'acc' => '#4d7c0f', 'soft' => '#f1f5e6', 'outer' => '#efefea'];
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
            }
        }

        $foot = $e($footer) . ($unsubscribeUrl ? ' <a href="' . $e($unsubscribeUrl) . '" style="color:' . $c['mut'] . '">Unsubscribe</a>' : '');
        $postal = \App\Services\Platform\PlatformMailer::postalAddress();

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="' . ($dark ? 'dark' : 'light') . '"></head>'
            . '<body style="margin:0;padding:0;background:' . $c['outer'] . ';font-family:' . $font . '">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden">' . $m($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['outer'] . ';padding:28px 12px"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:' . $c['card'] . ';border-radius:10px">'
            . '<tr><td style="padding:34px 40px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-weight:800;font-size:19px;letter-spacing:-.02em;color:' . $c['text'] . ';padding-bottom:18px;border-bottom:1px solid ' . $c['rule'] . '">intake<span style="display:inline-block;width:7px;height:7px;background:#BEF264;border-radius:2px;margin-left:3px"></span></td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="padding:26px 40px 12px;font-family:' . $font . '">' . $html . '</td></tr>'
            . '<tr><td style="padding:14px 40px 26px;border-top:1px solid ' . $c['rule'] . ';font-size:11.5px;line-height:1.6;color:' . $c['mut'] . '">'
            . $foot . ($postal ? '<br>' . $e('Intake · ' . $postal) : '') . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Why this person is getting the email, by audience source. */
    public static function footerFor(?string $source): string
    {
        return match ($source) {
            'investors'     => "You're receiving this because you're an investor in Intake Inc. Reply to reach Josh directly.",
            'tenants', 'tenant_owners' => "You're receiving this as an Intake shop owner.",
            'prospects'     => "You're receiving this because your shop has talked with Intake.",
            'reps'          => "You're receiving this as an Intake partner.",
            default         => "You're receiving this from Intake.",
        };
    }
}
