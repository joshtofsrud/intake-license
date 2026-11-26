<?php

namespace App\Support;

/** MARKER-RENTAL-PAGES-THEME: light/dark and Stripe colours for the public rental pages. */
class RentalTheme
{
    private static function hex($v, string $d): string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $d;
    }

    public static function tokens($tenant): array
    {
        return DesignTokens::resolve($tenant);
    }

    public static function dark($tenant): bool
    {
        return ColorHelper::isDark(self::hex(self::tokens($tenant)['bg'] ?? null, '#ffffff'));
    }

    /** Appearance for Stripe's Payment Element, so the card form matches the site. */
    public static function stripe($tenant): array
    {
        $t = self::tokens($tenant);
        $dark = self::dark($tenant);
        return [
            'theme'     => $dark ? 'night' : 'stripe',
            'variables' => [
                'colorPrimary'    => self::hex($t['accent'] ?? null, '#111111'),
                'colorBackground' => self::hex($t['bg'] ?? null, $dark ? '#111214' : '#ffffff'),
                'colorText'       => self::hex($t['text'] ?? null, $dark ? '#f1eee8' : '#111111'),
                'borderRadius'    => min(14, (int) ($t['card_radius'] ?? 8)) . 'px',
                'fontFamily'      => ($t['font_body'] ?? 'Inter') . ', system-ui, sans-serif',
            ],
        ];
    }
}
