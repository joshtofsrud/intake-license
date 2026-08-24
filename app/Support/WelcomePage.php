<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * MARKER-WELCOME — the site-wide holding page.
 *
 * Distinct from Splash: a splash interrupts a visitor BEFORE a page they
 * are then allowed to see. Welcome REPLACES the site. When both are on,
 * welcome wins — there is no point splashing someone who can't reach the
 * page behind it.
 */
class WelcomePage
{
    /** Paths that stay reachable while the welcome page is up. */
    public const ALLOWABLE = [
        'book'    => ['label' => 'Booking page',     'path' => '/book'],
        'account' => ['label' => 'Customer account', 'path' => '/account'],
        'rentals' => ['label' => 'Rentals',          'path' => '/rentals'],
        'contact' => ['label' => 'Contact',          'path' => '/contact'],
    ];

    public static function settings(?Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        return [
            'enabled'   => (bool) ($s['welcome_enabled'] ?? false),
            'headline'  => trim((string) ($s['welcome_headline'] ?? '')) ?: 'Something good is coming.',
            'message'   => trim((string) ($s['welcome_message'] ?? '')),
            'cta_label' => trim((string) ($s['welcome_cta_label'] ?? '')),
            'cta_url'   => trim((string) ($s['welcome_cta_url'] ?? '')),
            // Default reflects what a not-yet-open shop still wants working.
            'allow'     => is_array($s['welcome_allow'] ?? null)
                            ? $s['welcome_allow']
                            : ['book', 'account'],
        ];
    }

    public static function enabled(?Tenant $tenant): bool
    {
        return $tenant && self::settings($tenant)['enabled'];
    }

    /** Is this request path allowed through? */
    public static function allows(?Tenant $tenant, string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::settings($tenant)['allow'] as $key) {
            $allowed = self::ALLOWABLE[$key]['path'] ?? null;
            if (! $allowed) continue;
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return true;
            }
        }
        return false;
    }
}
