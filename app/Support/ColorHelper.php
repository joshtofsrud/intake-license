<?php

namespace App\Support;

/**
 * ColorHelper
 *
 * Utility methods for working with tenant accent colors.
 * Used in the layout shell to inject runtime CSS variables.
 */
class ColorHelper
{
    /**
     * Return black or white text color depending on accent luminance.
     * Ensures the text on the accent-colored button is always readable.
     */
    public static function accentTextColor(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        // Relative luminance (WCAG formula)
        $luminance = 0.2126 * self::linearize($r / 255)
                   + 0.7152 * self::linearize($g / 255)
                   + 0.0722 * self::linearize($b / 255);

        return $luminance > 0.179 ? '#0a0a0a' : '#ffffff';
    }

    /**
     * Return a low-opacity rgba version of the accent for focus rings.
     */
    public static function accentSoft(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        return "rgba({$r},{$g},{$b},.15)";
    }

    // -------------------------------------------------------------------------

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function linearize(float $c): float
    {
        return $c <= 0.03928
            ? $c / 12.92
            : (($c + 0.055) / 1.055) ** 2.4;
    }
}
