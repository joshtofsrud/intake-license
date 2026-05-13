<?php

namespace App\Support;

use App\Services\ThemeSettingsService;

/**
 * ThemeOverrideHelper — emits a <style> block for the active theme.
 *
 * Reads published values from ThemeSettingsService and writes them as
 * CSS variables under the appropriate `html.ia-theme-X` selector. Higher
 * specificity than the `body.ia-theme-X` rules in the static CSS files,
 * so DB values win when present. Empty array = empty output = no override.
 *
 * Output is plain text containing escaped values; not user input, but we
 * still strip quotes/newlines defensively.
 */
class ThemeOverrideHelper
{
    public static function styleTag(): string
    {
        $service = app(ThemeSettingsService::class);
        $all = $service->published();

        if (empty($all)) {
            return '';
        }

        $css = '';
        foreach ($all as $theme => $tokens) {
            if (empty($tokens)) {
                continue;
            }
            $css .= "html.ia-theme-{$theme} {\n";
            foreach ($tokens as $key => $value) {
                $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
                $safeValue = self::sanitizeValue($value);
                if ($safeKey === '' || $safeValue === '') {
                    continue;
                }
                $css .= "  --{$safeKey}: {$safeValue};\n";
            }
            $css .= "}\n";
        }

        return "<style data-theme-overrides>\n{$css}</style>";
    }

    /**
     * Strip anything that doesn't belong in a CSS value: quotes, semicolons,
     * angle brackets, line breaks. Hex/rgba/var()/keywords pass through.
     */
    private static function sanitizeValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        // Reject anything with characters that would break the style block.
        if (preg_match('/[<>"\'{};]/', $value)) {
            return '';
        }
        // Reject linebreaks.
        if (preg_match('/[\r\n]/', $value)) {
            return '';
        }
        return $value;
    }
}
