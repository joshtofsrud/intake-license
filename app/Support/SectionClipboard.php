<?php

namespace App\Support;

use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * A per-person clipboard for one page section, so a section can be copied
 * on one page and pasted on another. Lives in the app cache for 30 minutes
 * (a deploy clears the cache, which for a half-hour scratch copy is fine).
 * Paste is limited to the site the section came from: images and links
 * belong to that site.
 */
final class SectionClipboard
{
    public const TTL_MINUTES = 30;

    public static function copy(TenantPage $page, TenantPageSection $section, string $label): void
    {
        $key = self::key();
        if ($key === null) {
            return;
        }
        Cache::put($key, [
            'tenant_id'    => (string) $page->tenant_id,
            'section_type' => $section->section_type,
            'content'      => $section->content ?? [],
            'bg_color'     => $section->bg_color,
            'padding'      => $section->padding ?? 'normal',
            'is_visible'   => (bool) $section->is_visible,
            'label'        => $label,
            'from_page'    => $page->title ?: ($page->slug ?: 'home'),
            'copied_at'    => now()->timestamp,
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    /** What is on the clipboard for this site, or null. */
    public static function peek(string $tenantId): ?array
    {
        $key = self::key();
        $c = $key ? Cache::get($key) : null;
        if (! is_array($c) || ($c['tenant_id'] ?? null) !== (string) $tenantId) {
            return null;
        }
        $c['age'] = self::age((int) $c['copied_at']);
        return $c;
    }

    public static function clear(): void
    {
        if ($key = self::key()) {
            Cache::forget($key);
        }
    }

    public static function age(int $ts): string
    {
        $m = (int) floor(max(0, now()->timestamp - $ts) / 60);
        return $m < 1 ? 'just now' : ($m === 1 ? '1 min ago' : "{$m} min ago");
    }

    private static function key(): ?string
    {
        foreach (['tenant', 'web'] as $guard) {
            if (Auth::guard($guard)->check()) {
                return 'section-clipboard:' . $guard . ':' . Auth::guard($guard)->id();
            }
        }
        return null;
    }
}
