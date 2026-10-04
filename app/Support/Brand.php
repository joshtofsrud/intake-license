<?php

namespace App\Support;

/**
 * MARKER-BRAND — the one source for Intake's own logo, icon, favicons and
 * share image. Every page and email asks this class for a URL; master admin
 * › Brand sets what it returns. Anything not uploaded falls back to the file
 * shipped in public/, so nothing breaks before the first upload.
 */
class Brand
{
    /** Fallbacks: the files shipped with the app. */
    public const DEFAULTS = [
        'logo'       => '/logo.svg',          // for dark backgrounds (light wordmark)
        'logo_light' => '/logo-dark.svg',     // for light backgrounds (dark wordmark)
        'email'      => '/logo-dark.svg',     // PNG preferred: many inboxes don't show SVG
        'icon'       => '/icon.svg',
        'favicon'    => '/favicon.svg',
        'favicon_16' => '/favicon-16.png',
        'favicon_32' => '/favicon-32.png',
        'apple'      => '/apple-touch-icon.png',
        'og'         => '/og-image.png',
    ];

    private static ?array $current = null;

    public static function current(): array
    {
        if (self::$current === null) {
            try {
                self::$current = (array) (\App\Models\SiteSettings::current()->brand ?? []);
            } catch (\Throwable $e) {
                self::$current = [];
            }
        }
        return self::$current;
    }

    public static function flush(): void
    {
        self::$current = null;
    }

    /** Absolute URL for a brand slot. */
    public static function url(string $slot): string
    {
        $b = self::current();
        // The favicon is the icon unless one was never uploaded.
        $key = $slot === 'favicon' ? 'icon' : $slot;
        if (! empty($b[$key])) {
            return url('/storage/' . ltrim((string) $b[$key], '/'));
        }
        return url(self::DEFAULTS[$slot] ?? '/icon.svg');
    }

    /** A builder page's own share image, else the Brand default. */
    public static function shareImageFor($page): string
    {
        $own = trim((string) ($page->og_image_url ?? ''));
        return $own !== '' ? url('/storage/' . ltrim($own, '/')) : self::url('og');
    }
}
