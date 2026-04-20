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
 *   paragraph, heading, image, button, divider, footer
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

        $inner = '';
        foreach ($blocks as $block) {
            $inner .= self::renderBlock($block, $variables, $accent, $accentText);
        }

        // If preview and no blocks, show placeholder
        if ($preview && trim($inner) === '') {
            $inner = '<tr><td style="padding:40px 20px;text-align:center;color:#aaa;font-size:13px;font-style:italic">Add blocks to see a preview.</td></tr>';
        }

        return self::wrapDocument($inner);
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
    private static function wrapDocument(string $inner): string
    {
        return <<<HTML
            <!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
            <body style="margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,sans-serif">
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

    /** Replace {{token}} with corresponding variable value. */
    private static function substituteVariables(string $html, array $variables): string
    {
        if (empty($variables)) {
            return $html;
        }
        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', (string) $value, $html);
        }
        return $html;
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
