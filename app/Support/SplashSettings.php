<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * MARKER-SPLASH — one place that answers "should this visitor see the
 * splash, and which page is it".
 */
class SplashSettings
{
    public const COOKIE = 'intake_splash';

    /** Normalized settings, clamped — a bad stored value can never gate a site shut. */
    public static function config(Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        $mode = $s['splash_mode'] ?? 'overlay';
        $freq = (string) ($s['splash_frequency'] ?? 'session');
        $style = $s['splash_style'] ?? 'full';

        return [
            'enabled'   => (bool) ($s['splash_enabled'] ?? false),
            'mode'      => in_array($mode, ['overlay', 'page'], true) ? $mode : 'overlay',
            'frequency' => in_array($freq, ['session', '7', '30', 'always'], true) ? $freq : 'session',
            'style'     => in_array($style, ['full', 'sheet'], true) ? $style : 'full',
        ];
    }

    /** The published page flagged as the splash, if there is one. */
    public static function page(Tenant $tenant): ?TenantPage
    {
        return TenantPage::where('tenant_id', $tenant->id)
            ->where('is_splash', true)
            ->where('is_published', true)
            ->first();
    }

    /**
     * Whether THIS request should be shown the splash.
     *
     * Only ever called from the homepage route: a deep link is never
     * interrupted, by Josh's decision, so /shop and friends do not consult
     * this at all.
     */
    public static function shouldShow(Request $request, array $cfg): bool
    {
        if (! $cfg['enabled']) {
            return false;
        }
        if ($cfg['frequency'] === 'always') {
            return true; // no cookie is ever written in this mode
        }

        return ! $request->cookie(self::COOKIE);
    }

    /** Cookie lifetime in minutes; 0 means a session cookie. */
    public static function cookieMinutes(array $cfg): int
    {
        return match ($cfg['frequency']) {
            '7'  => 60 * 24 * 7,
            '30' => 60 * 24 * 30,
            default => 0,
        };
    }
}
