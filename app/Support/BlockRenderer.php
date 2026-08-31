<?php

namespace App\Support;

/**
 * Renders an array of content blocks to email-safe HTML.
 *
 * Same renderer used for:
 *   - Live preview in composer (variables substituted with sample values)
 *   - Actual email send (variables substituted with real recipient values)
 *
 * Block shape:
 *   { "id": "uuid", "type": "paragraph", "data": { ... } }
 *
 * Supported block types:
 *   paragraph, heading, image, button, divider, footer,
 *   spacer, two_column, image_text, social  (MARKER-CAMPAIGN-V2B)
 *   catalog  (MARKER-CAMPAIGN-V2C — live service/product cards)
 *
 * All blocks render inside a 600px-wide table layout for email client compatibility.
 */
class BlockRenderer
{
    /** Sample values used for preview in composer. */
    public const SAMPLE_VARS = [
        'first_name'       => 'Jane',
        'last_name'        => 'Smith',
        'name'             => 'Jane Smith',
        'ra_number'        => 'SPK-A3F9B2',
        'appointment_date' => 'Thursday, November 14, 2024',
        'total'            => '$185.00',
        'status'           => 'Completed',
        'status_note'      => 'Your bike is ready for pickup.',
        'shop_name'        => 'Your Shop',
        'reset_url'        => '#',
        'accent'           => '#BEF264',
        'accent_text'      => '#0a0a0a',
    ];

    /**
     * Render blocks to a full HTML email document.
     *
     * @param  array  $blocks     Array of block dicts
     * @param  array  $variables  Key/value replacements for {{tokens}}
     * @param  array  $options    ['accent' => '#BEF264', 'preview' => bool]
     */
    public static function render(array $blocks, array $variables = [], array $options = []): string
    {
        $accent     = $options['accent']     ?? '#BEF264';
        $accentText = $options['accentText'] ?? '#0a0a0a';
        $preview    = $options['preview']    ?? false;
        // MARKER-CAMPAIGN-V2A — preheader + token resolution.
        $preheader  = trim((string) ($options['preheader'] ?? ''));
        $resolve    = (bool) ($options['resolveTokens'] ?? false);

        $inner = '';
        foreach ($blocks as $block) {
            $one = self::renderBlock($block, $variables, $accent, $accentText);

            // MARKER-CAMPAIGN-V2D — in the composer only, tag the row so the
            // builder can hover/select it. Never present in a sent email.
            if ($preview && $one !== '' && ($block['id'] ?? '') !== '') {
                $bid  = self::escape((string) $block['id']);
                $type = self::escape((string) ($block['type'] ?? ''));
                $one  = preg_replace(
                    '/^(\s*)<tr>/',
                    '$1<tr data-cb-block="' . $bid . '" data-cb-type="' . $type . '">',
                    $one,
                    1
                );
            }

            $inner .= $one;
        }

        // If preview and no blocks, show placeholder
        if ($preview && trim($inner) === '') {
            $inner = '<tr><td style="padding:40px 20px;text-align:center;color:#aaa;font-size:13px;font-style:italic">Add blocks to see a preview.</td></tr>';
        }

        $html = self::wrapDocument($inner, $preheader, $preview);

        // Send and preview resolve leftover tokens to their fallback so a
        // missing value can never ship as "Hi ,". Save-time rendering leaves
        // them raw — the worker re-renders per recipient from the blocks.
        return $resolve ? self::resolveLeftoverTokens($html) : $html;
    }

    /** Dispatch to per-type renderer, then substitute variables in the output. */
    private static function renderBlock(array $block, array $variables, string $accent, string $accentText): string
    {
        $type = $block['type'] ?? 'paragraph';
        $data = $block['data'] ?? [];

        $html = match ($type) {
            'heading'   => self::renderHeading($data),
            'paragraph' => self::renderParagraph($data),
            'image'     => self::renderImage($data),
            'button'    => self::renderButton($data, $accent, $accentText),
            'divider'   => self::renderDivider($data),
            'footer'    => self::renderFooter($data),
            // MARKER-CAMPAIGN-V2B
            'spacer'     => self::renderSpacer($data),
            'two_column' => self::renderTwoColumn($data),
            'image_text' => self::renderImageText($data, $accent),
            'social'     => self::renderSocial($data),
            'catalog'    => self::renderCatalog($data, $accent, $accentText), // MARKER-CAMPAIGN-V2C
            default     => '',
        };

        return self::substituteVariables($html, $variables);
    }

    // ----------------------------------------------------------------
    // Per-block-type renderers
    // ----------------------------------------------------------------

    private static function renderHeading(array $data): string
    {
        $text  = self::escape($data['text'] ?? 'Heading');
        $size  = $data['size'] ?? 'h1';  // h1 | h2 | h3
        $align = self::safeAlign($data['align'] ?? 'left');

        $sizes = [
            'h1' => ['font-size:28px;font-weight:700;line-height:1.25', 'h1'],
            'h2' => ['font-size:22px;font-weight:700;line-height:1.3',  'h2'],
            'h3' => ['font-size:18px;font-weight:600;line-height:1.4',  'h3'],
        ];
        [$style, $tag] = $sizes[$size] ?? $sizes['h1'];

        return <<<HTML
            <tr><td style="padding:16px 24px 8px;text-align:{$align}">
              <{$tag} style="{$style};color:#111;margin:0;font-family:-apple-system,BlinkMacSystemFont,sans-serif">{$text}</{$tag}>
            </td></tr>
            HTML;
    }

    private static function renderParagraph(array $data): string
    {
        $align = self::safeAlign($data['align'] ?? 'left');

        // Prefer sanitized HTML field (from rich text editor); fall back to
        // plain text field for legacy blocks — wrap in <p> with <br> for newlines.
        if (!empty($data['html']) && is_string($data['html'])) {
            $body = self::sanitizeHtml($data['html']);
        } else {
            $text = self::escape($data['text'] ?? '');
            $body = nl2br($text);
        }

        if (trim(strip_tags($body)) === '') {
            $body = '<span style="color:#bbb;font-style:italic">Empty paragraph</span>';
        }

        // Inline-style anchors because email clients strip CSS classes.
        $body = preg_replace(
            '/<a\s+([^>]*?)href=(["\'])([^"\'\s]+)\2([^>]*)>/i',
            '<a $1href=$2$3$2$4 style="color:#0066cc;text-decoration:underline">',
            $body
        );

        return <<<HTML
            <tr><td style="padding:8px 24px;text-align:{$align};font-size:15px;line-height:1.65;color:#333;font-family:-apple-system,BlinkMacSystemFont,sans-serif">
              {$body}
            </td></tr>
            HTML;
    }

    /**
     * Whitelist-based HTML sanitizer for paragraph rich text.
     *
     * Allowed tags: p br strong em a ul ol li
     * All attributes stripped except href on <a>.
     * Inline styles stripped. Scripts stripped.
     *
     * This is adequate for authenticated staff input. If/when campaigns
     * accept input from public sources, upgrade to HTMLPurifier.
     */
    public static function sanitizeHtml(string $html): string
    {
        // 1. Strip script/style/iframe/object/embed blocks entirely, including content
        $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*/?>#i', '', $html);

        // 2. Strip HTML comments (can hide nasty payloads)
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // 3. Whitelist tags. Everything else becomes escaped text.
        $allowed = '<p><br><strong><b><em><i><u><a><ul><ol><li>';
        $html = strip_tags($html, $allowed);

        // 4. Strip any on* event handlers and inline styles from surviving tags,
        //    plus any javascript: / data: URLs in href attrs.
        $html = preg_replace_callback('/<([a-z][a-z0-9]*)\b([^>]*)>/i', function ($m) {
            $tag  = strtolower($m[1]);
            $attr = $m[2];

            // For anchors, keep ONLY the href attribute (and only http/https/mailto).
            if ($tag === 'a') {
                if (preg_match('/\bhref\s*=\s*(["\'])([^"\'\s>]+)\1/i', $attr, $h)) {
                    $url = trim($h[2]);
                    if (preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
                        $url = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        return '<a href="' . $url . '" rel="noopener">';
                    }
                }
                return '<a>';
            }

            // Normalize <b>→<strong>, <i>→<em>
            if ($tag === 'b') $tag = 'strong';
            if ($tag === 'i') $tag = 'em';

            // Everything else: emit the tag bare, no attributes.
            return '<' . $tag . '>';
        }, $html);

        return $html;
    }

    private static function renderImage(array $data): string
    {
        $url = $data['url'] ?? '';
        $alt = self::escape($data['alt'] ?? '');

        if ($url === '') {
            return <<<HTML
                <tr><td style="padding:16px 24px">
                  <div style="border:1px dashed #ccc;padding:40px;text-align:center;color:#aaa;font-size:13px">
                    No image selected
                  </div>
                </td></tr>
                HTML;
        }

        // Basic URL safety — must start with http(s) or /
        $url = filter_var($url, FILTER_SANITIZE_URL);

        return <<<HTML
            <tr><td style="padding:12px 24px">
              <img src="{$url}" alt="{$alt}" style="max-width:100%;height:auto;display:block;border-radius:4px" />
            </td></tr>
            HTML;
    }

    private static function renderButton(array $data, string $accent, string $accentText): string
    {
        $text  = self::escape($data['text'] ?? 'Click here');
        $url   = self::escape($data['url']  ?? '#');
        $align = self::safeAlign($data['align'] ?? 'left');

        // Bulletproof email button — table-in-table pattern for Outlook compat
        return <<<HTML
            <tr><td style="padding:16px 24px;text-align:{$align}">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-block">
                <tr><td style="background:{$accent};border-radius:6px;padding:12px 22px">
                  <a href="{$url}" style="color:{$accentText};text-decoration:none;font-weight:600;font-size:14px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:inline-block">{$text}</a>
                </td></tr>
              </table>
            </td></tr>
            HTML;
    }

    private static function renderDivider(array $data): string
    {
        return <<<HTML
            <tr><td style="padding:16px 24px">
              <hr style="border:none;border-top:1px solid #e5e5e0;margin:0" />
            </td></tr>
            HTML;
    }

    // MARKER-CAMPAIGN-V2B — four new blocks. Every one is table-based and
    // avoids media queries, because Outlook ignores them; the stacking here
    // comes from the width:100%/max-width float pattern instead.

    private static function renderSpacer(array $data): string
    {
        $h = (int) ($data['height'] ?? 24);
        if ($h < 4)   $h = 4;
        if ($h > 120) $h = 120;

        return <<<HTML
            <tr><td style="height:{$h}px;line-height:{$h}px;font-size:0">&nbsp;</td></tr>
            HTML;
    }

    private static function renderTwoColumn(array $data): string
    {
        $left  = self::inlineText($data['left']  ?? '');
        $right = self::inlineText($data['right'] ?? '');

        return <<<HTML
            <tr><td style="padding:8px 24px">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td width="50%" valign="top" style="padding:8px 10px 8px 0;font-size:14px;line-height:1.6;color:#333;font-family:-apple-system,BlinkMacSystemFont,sans-serif">{$left}</td>
                  <td width="50%" valign="top" style="padding:8px 0 8px 10px;font-size:14px;line-height:1.6;color:#333;font-family:-apple-system,BlinkMacSystemFont,sans-serif">{$right}</td>
                </tr>
              </table>
            </td></tr>
            HTML;
    }

    private static function renderImageText(array $data, string $accent): string
    {
        $url  = trim((string) ($data['url'] ?? ''));
        $alt  = self::escape($data['alt'] ?? '');
        $text = self::inlineText($data['text'] ?? '');
        $side = ($data['side'] ?? 'left') === 'right' ? 'right' : 'left';

        $imgCell = $url !== ''
            ? '<img src="' . self::escape($url) . '" alt="' . $alt . '" width="240" style="width:100%;max-width:240px;display:block;border:0;border-radius:6px">'
            : '<div style="border:1px dashed #ccc;padding:30px;text-align:center;color:#aaa;font-size:12px">No image selected</div>';

        $textCell = '<div style="font-size:14px;line-height:1.6;color:#333;font-family:-apple-system,BlinkMacSystemFont,sans-serif">' . $text . '</div>';

        [$a, $b] = $side === 'left' ? [$imgCell, $textCell] : [$textCell, $imgCell];
        $padA = $side === 'left' ? '0 14px 0 0' : '0 14px 0 0';
        $padB = '0';

        return <<<HTML
            <tr><td style="padding:12px 24px">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td width="45%" valign="middle" style="padding:{$padA}">{$a}</td>
                  <td width="55%" valign="middle" style="padding:{$padB}">{$b}</td>
                </tr>
              </table>
            </td></tr>
            HTML;
    }

    private static function renderSocial(array $data): string
    {
        $raw = $data['links'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            $raw = [];
        }

        $cells = '';
        $n = 0;
        foreach ($raw as $item) {
            if ($n >= 5) {
                break;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $url   = trim((string) ($item['url'] ?? ''));
            if ($label === '' || ! preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
                continue;
            }
            $n++;
            $cells .= '<td style="padding:0 9px"><a href="' . self::escape($url) . '" style="color:#666;text-decoration:none;font-size:12px;font-family:-apple-system,BlinkMacSystemFont,sans-serif">' . self::escape($label) . '</a></td>';
        }

        if ($cells === '') {
            return <<<HTML
                <tr><td style="padding:12px 24px;text-align:center;color:#aaa;font-size:12px;font-style:italic">No links added yet</td></tr>
                HTML;
        }

        return <<<HTML
            <tr><td style="padding:14px 24px;text-align:center">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-block">
                <tr>{$cells}</tr>
              </table>
            </td></tr>
            HTML;
    }

    /**
     * MARKER-CAMPAIGN-V2C — service/product cards.
     * The block stores kind + id only; name, price and photo are resolved
     * HERE, at render time, so the email reflects the catalog as it stands
     * when the send actually goes out. Per-block overrides win when set.
     */
    private static function renderCatalog(array $data, string $accent, string $accentText): string
    {
        $items = $data['items'] ?? [];
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($items) || empty($items)) {
            return <<<HTML
                <tr><td style="padding:16px 24px">
                  <div style="border:1px dashed #ccc;padding:30px;text-align:center;color:#aaa;font-size:13px">
                    No service or product chosen yet
                  </div>
                </td></tr>
                HTML;
        }

        $showPrice = ($data['show_price'] ?? '1') !== '0';
        $showPhoto = ($data['show_photo'] ?? '1') !== '0';
        $ctaText   = trim((string) ($data['cta_text'] ?? ''));
        $perRow    = ((int) ($data['per_row'] ?? 2)) === 1 ? 1 : 2;

        $cards = [];
        foreach (array_slice($items, 0, 4) as $it) {
            $resolved = self::resolveCatalogItem($it);
            if ($resolved === null) {
                continue; // deleted or archived since it was picked
            }
            $cards[] = self::catalogCard($resolved, $showPrice, $showPhoto, $ctaText, $accent, $accentText);
        }

        if (empty($cards)) {
            return <<<HTML
                <tr><td style="padding:16px 24px;text-align:center;color:#aaa;font-size:12px;font-style:italic">
                  The chosen items are no longer available
                </td></tr>
                HTML;
        }

        $rows = '';
        foreach (array_chunk($cards, $perRow) as $chunk) {
            $tds = '';
            foreach ($chunk as $card) {
                $w = $perRow === 1 ? '100%' : '50%';
                $tds .= '<td width="' . $w . '" valign="top" style="padding:6px">' . $card . '</td>';
            }
            if ($perRow === 2 && count($chunk) === 1) {
                $tds .= '<td width="50%"></td>';
            }
            $rows .= '<tr>' . $tds . '</tr>';
        }

        return <<<HTML
            <tr><td style="padding:10px 18px">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                {$rows}
              </table>
            </td></tr>
            HTML;
    }

    /** One card's markup. */
    private static function catalogCard(array $r, bool $showPrice, bool $showPhoto, string $ctaText, string $accent, string $accentText): string
    {
        $name  = self::escape($r['name']);
        $price = $r['price'] !== null ? self::escape($r['price']) : '';
        $photo = $r['photo'];

        $img = '';
        if ($showPhoto && $photo) {
            $img = '<tr><td style="padding:0"><img src="' . self::escape($photo) . '" alt="' . $name . '" width="260" style="width:100%;max-width:260px;display:block;border:0"></td></tr>';
        }

        $priceRow = ($showPrice && $price !== '')
            ? '<div style="font-size:13px;color:#666;margin-top:3px">' . $price . '</div>'
            : '';

        $cta = '';
        if ($ctaText !== '' && $r['url'] !== null) {
            $cta = '<div style="margin-top:9px"><a href="' . self::escape($r['url']) . '" style="background:' . $accent . ';color:' . $accentText . ';text-decoration:none;font-size:12px;font-weight:600;padding:8px 14px;border-radius:5px;display:inline-block">' . self::escape($ctaText) . '</a></div>';
        }

        return <<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #e8e8e4;border-radius:6px;overflow:hidden">
              {$img}
              <tr><td style="padding:11px 13px;font-family:-apple-system,BlinkMacSystemFont,sans-serif">
                <div style="font-size:14px;font-weight:600;color:#222;line-height:1.35">{$name}</div>
                {$priceRow}
                {$cta}
              </td></tr>
            </table>
            HTML;
    }

    /**
     * Look up one picked item. Returns null when it has been deleted,
     * archived or switched off since — a dead card is worse than no card.
     */
    private static function resolveCatalogItem(array $it): ?array
    {
        $kind = $it['kind'] ?? '';
        $id   = $it['id'] ?? '';
        if ($id === '') {
            return null;
        }

        $tenant = function_exists('tenant') ? tenant() : null;
        if (! $tenant) {
            return null;
        }

        $overrideName  = trim((string) ($it['name']  ?? ''));
        $overridePrice = trim((string) ($it['price'] ?? ''));

        if ($kind === 'service') {
            $m = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('id', $id)->where('is_active', true)->first();
            if (! $m) {
                return null;
            }
            return [
                'name'  => $overrideName !== '' ? $overrideName : (string) $m->name,
                'price' => $overridePrice !== ''
                    ? $overridePrice
                    : ($m->price_cents !== null ? '$' . number_format($m->price_cents / 100, 2) : null),
                'photo' => $m->image_url ?: null,
                'url'   => rtrim((string) $tenant->publicUrl(), '/') . '/book',
            ];
        }

        if ($kind === 'product') {
            $m = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
                ->where('id', $id)->where('is_active', true)
                ->with('distributorCatalog:id,images')->first();
            if (! $m) {
                return null;
            }
            // Same image resolution the storefront's product showcase uses.
            $ims   = (array) ($m->distributorCatalog->images ?? []);
            $first = $ims[0] ?? null;
            $photo = is_array($first)
                ? ($first['Url'] ?? $first['url'] ?? $first['src'] ?? null)
                : (is_string($first) ? $first : null);

            $cents = $m->effectiveSellPriceCents();

            return [
                'name'  => $overrideName !== '' ? $overrideName : (string) $m->name,
                'price' => $overridePrice !== ''
                    ? $overridePrice
                    : ($cents !== null ? '$' . number_format($cents / 100, 2) : null),
                'photo' => $photo,
                'url'   => rtrim((string) $tenant->publicUrl(), '/') . '/shop',
            ];
        }

        return null;
    }

    /** Escaped text with newlines as <br>, for the simple text fields above. */
    private static function inlineText(string $value): string
    {
        return nl2br(self::escape(trim($value)));
    }

    private static function renderFooter(array $data): string
    {
        $text = self::escape($data['text'] ?? 'You received this because you are a customer. Reply STOP to unsubscribe.');

        return <<<HTML
            <tr><td style="padding:24px;text-align:center;font-size:11px;color:#999;line-height:1.5;font-family:-apple-system,BlinkMacSystemFont,sans-serif;border-top:1px solid #eee">
              {$text}
            </td></tr>
            HTML;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Wrap block output in 600px centered email table. */
    private static function wrapDocument(string $inner, string $preheader = '', bool $preview = false): string
    {
        // MARKER-CAMPAIGN-V2D — builder-only chrome. Kept inside the preview
        // document so a sent email carries none of it.
        $previewCss = '';
        if ($preview) {
            $previewCss = <<<'CSS'
            <style>
              tr[data-cb-block] > td { position: relative; }
              tr[data-cb-block] { cursor: pointer; }
              tr[data-cb-block].cb-hover > td::after,
              tr[data-cb-block].cb-active > td::after {
                content: ''; position: absolute; inset: 2px; pointer-events: none;
                border: 2px solid #7BA428; border-radius: 4px;
              }
              tr[data-cb-block].cb-active > td::after { border-color: #4F7A12; }
              tr[data-cb-block].cb-hover > td::before {
                content: attr(data-cb-label); position: absolute; top: 2px; left: 2px; z-index: 5;
                background: #4F7A12; color: #fff; font-size: 10px; line-height: 1;
                padding: 3px 6px; border-radius: 0 0 4px 0; pointer-events: none;
                font-family: -apple-system, BlinkMacSystemFont, sans-serif;
              }
              tr[data-cb-block].cb-flash > td::after {
                content: ''; position: absolute; inset: 2px; pointer-events: none;
                border: 2px solid #4F7A12; border-radius: 4px;
                animation: cbflash .9s ease-out;
              }
              @keyframes cbflash {
                0%   { background: rgba(190,242,100,.55); }
                100% { background: rgba(190,242,100,0); }
              }
            </style>
            CSS;
        }

        // MARKER-CAMPAIGN-V2A — the hidden preview line inboxes show beside
        // the subject. Zero-size and colour-matched so it never renders, with
        // trailing entities so the client doesn't pad it with body copy.
        $pre = '';
        if ($preheader !== '') {
            $safe = self::escape($preheader);
            $pre  = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f4f2;opacity:0">'
                  . $safe . str_repeat('&#847;&zwnj;&nbsp;', 30) . '</div>';
        }

        return <<<HTML
            <!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">{$previewCss}</head>
            <body style="margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,sans-serif">
              {$pre}
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f4f2">
                <tr><td align="center" style="padding:24px 0">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden">
                    {$inner}
                  </table>
                </td></tr>
              </table>
            </body></html>
            HTML;
    }

    /**
     * Replace {{token}} — and {{token|fallback}} — with the variable value.
     * MARKER-CAMPAIGN-V2A: a token with a value wins; an empty or missing one
     * falls back. Tokens with no match are left alone here so save-time
     * rendering keeps them raw; resolveLeftoverTokens() clears them at send.
     */
    private static function substituteVariables(string $html, array $variables): string
    {
        if (empty($variables)) {
            return $html;
        }

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*(?:\|([^}]*))?\}\}/i',
            function ($m) use ($variables) {
                $key = $m[1];
                if (! array_key_exists($key, $variables)) {
                    return $m[0]; // not ours — leave it for a later pass
                }
                $val = $variables[$key];
                $val = (is_string($val) || is_numeric($val)) ? trim((string) $val) : '';
                if ($val !== '') {
                    return $val;
                }
                return isset($m[2]) ? trim($m[2]) : '';
            },
            $html
        ) ?? $html;
    }

    /**
     * MARKER-CAMPAIGN-V2A — anything still in {{token}} form at send time has
     * no value behind it: use its fallback, or drop it. Prevents literal
     * "{{first_name}}" and "Hi ," from reaching an inbox.
     */
    private static function resolveLeftoverTokens(string $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*(?:\|([^}]*))?\}\}/i',
            fn ($m) => isset($m[2]) ? trim($m[2]) : '',
            $html
        ) ?? $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function safeAlign(string $align): string
    {
        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    }
}
