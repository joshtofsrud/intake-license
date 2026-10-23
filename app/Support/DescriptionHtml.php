<?php

namespace App\Support;

/**
 * A distributor's plain-text description, laid out for reading.
 *
 * Feeds send paragraphs and feature lines separated by line breaks; printed
 * into one <p> they ran together into a single block. This keeps the breaks:
 *
 *   - a blank line starts a new paragraph;
 *   - a short first paragraph that is not a sentence ("Reliable SPD
 *     Performance for Everyday Riding") is a headline, shown bold at the start
 *     of the text that follows it;
 *   - a run of short lines becomes a list, and a line that starts with a short
 *     label and a colon ("Proven SPD Performance: Fast and easy…") shows the
 *     label in bold.
 *
 * Everything is escaped; no feed HTML is ever passed through.
 */
class DescriptionHtml
{
    public static function render(?string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
        if ($text === '') {
            return '';
        }

        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $paras = array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", $text)), fn ($p) => $p !== ''));

        $headline = null;
        if (count($paras) > 1 && ! str_contains($paras[0], "\n")
            && mb_strlen($paras[0]) <= 90 && ! preg_match('/[.!?]$/u', $paras[0])) {
            $headline = array_shift($paras);
        }

        $out = [];
        foreach ($paras as $i => $p) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $p)), fn ($l) => $l !== ''));

            if (count($lines) > 1) {
                $items = array_map(function ($l) use ($e) {
                    if (preg_match('/^([^:.]{2,40}):\s+(.+)$/u', $l, $m)) {
                        return '<li><b>' . $e($m[1]) . ':</b> ' . $e($m[2]) . '</li>';
                    }
                    return '<li>' . $e($l) . '</li>';
                }, $lines);
                $out[] = '<ul class="ia-desc-list">' . implode('', $items) . '</ul>';
                continue;
            }

            $body = $e($lines[0]);
            if ($i === 0 && $headline !== null) {
                $body = '<b>' . $e($headline) . '.</b> ' . $body;
                $headline = null;
            }
            $out[] = '<p>' . $body . '</p>';
        }
        if ($headline !== null) {           // a headline with nothing after it
            array_unshift($out, '<p><b>' . $e($headline) . '</b></p>');
        }

        return implode('', $out);
    }
}
