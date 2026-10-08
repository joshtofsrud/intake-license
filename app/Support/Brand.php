<?php

namespace App\Support;

/**
 * the one source for Intake's own logo, icon, favicons and
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
        // library picks are stored as full URLs when off-site.
        return self::storagePublicUrl($page->og_image_url ?? null) ?? self::url('og');
    }

    /**
     * a stored image value as an absolute URL.
     * Accepts a full URL, a site-relative path, or a path under /storage/
     * (how Filament uploads store it). Blank means none.
     */
    public static function storagePublicUrl(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('#^https?://#i', $v)) return $v;
        if (str_starts_with($v, '//')) return 'https:' . $v;
        if (str_starts_with($v, '/')) return url($v);
        return url('/storage/' . ltrim($v, '/'));
    }

    /**
     * a picked image URL in the form it is stored:
     * the path under /storage/ when the file lives on this site (matching the
     * Filament upload format), otherwise the full http(s) URL. Null for
     * anything that is not an image address we can serve.
     */
    public static function storageRelative(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        $isAbs = (bool) preg_match('#^https?://#i', $v);
        if (! $isAbs && ! str_starts_with($v, '/') && ! str_contains($v, ':')) {
            return ltrim($v, '/');   // already stored form
        }
        $path = $isAbs ? (string) parse_url($v, PHP_URL_PATH) : $v;
        $own  = ! $isAbs || strcasecmp((string) parse_url($v, PHP_URL_HOST), (string) request()->getHost()) === 0;
        if ($own && str_starts_with($path, '/storage/')) return substr($path, strlen('/storage/'));
        return $isAbs ? $v : $path;   // site-relative path, kept as is
    }
}
