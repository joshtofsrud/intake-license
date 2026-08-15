<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * MARKER-SPLASH-2 — answers one question: does THIS visited page show a
 * splash to THIS visitor, and with what settings.
 *
 * Every value is clamped on the way out. A bad stored value must never be
 * able to gate a shop's site shut.
 */
class SplashSettings
{
    /** Per-splash so dismissing one never suppresses another. */
    public static function cookieName(string $splashPageId): string
    {
        return 'intake_splash_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $splashPageId), 0, 24);
    }

    /** Master switch, still tenant-wide: one toggle turns every splash off. */
    public static function enabled(Tenant $tenant): bool
    {
        return (bool) (((array) ($tenant->settings ?? []))['splash_enabled'] ?? false);
    }

    /**
     * The pairing for a visited page, or null. Returns the splash page plus
     * its normalized settings.
     */
    public static function forPage(Tenant $tenant, TenantPage $visited): ?array
    {
        if (! self::enabled($tenant) || ! $visited->splash_page_id) {
            return null;
        }

        // A splash never splashes itself, or the click-through loops.
        if ($visited->splash_page_id === $visited->id) {
            return null;
        }

        $splash = TenantPage::where('tenant_id', $tenant->id)
            ->where('id', $visited->splash_page_id)
            ->where('is_published', true)
            ->first();

        if (! $splash) {
            return null;
        }

        if (! self::withinWindow($tenant, $visited)) {
            return null;
        }

        return [
            'page'      => $splash,
            'mode'      => in_array($visited->splash_mode, ['overlay', 'page'], true) ? $visited->splash_mode : 'overlay',
            'style'     => in_array($visited->splash_style, ['full', 'sheet'], true) ? $visited->splash_style : 'full',
            'frequency' => in_array((string) $visited->splash_frequency, ['session', '7', '30', 'always'], true) ? (string) $visited->splash_frequency : 'session',
            'cookie'    => self::cookieName($splash->id),
        ];
    }

    /**
     * Date window, in the SHOP's timezone — an event that runs "through
     * Saturday" should end when it is Sunday where the shop is, not in UTC.
     * Both bounds are inclusive.
     */
    public static function withinWindow(Tenant $tenant, TenantPage $visited): bool
    {
        $today = $tenant->localToday()->startOfDay();

        if ($visited->splash_starts_at && $today->lt($visited->splash_starts_at->startOfDay())) {
            return false;
        }
        if ($visited->splash_ends_at && $today->gt($visited->splash_ends_at->startOfDay())) {
            return false;
        }

        return true;
    }

    /** Has this visitor already dismissed THIS splash? */
    public static function alreadySeen(Request $request, array $pairing): bool
    {
        if ($pairing['frequency'] === 'always') {
            return false; // deliberately never remembered
        }

        return (bool) $request->cookie($pairing['cookie']);
    }

    /** Cookie lifetime in minutes; 0 means a session cookie. */
    public static function cookieMinutes(array $pairing): int
    {
        return match ($pairing['frequency']) {
            '7'  => 60 * 24 * 7,
            '30' => 60 * 24 * 30,
            default => 0,
        };
    }
}
