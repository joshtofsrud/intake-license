<?php
// MARKER-ADMIN-ROLES — the fixed role/area matrix from the approved mockup.
// Levels: 'full', 'view', or absent (no access). Fixed per role — no custom
// permission editor in v1.

namespace App\Support;

use App\Models\User;

class AdminAccess
{
    public const STAFF_ROLES = ['owner', 'admin', 'support', 'sales'];

    public const MATRIX = [
        'owner' => [
            'dashboard' => 'full', 'tenants' => 'full', 'impersonation' => 'full',
            'features' => 'full', 'domains' => 'full', 'catalog' => 'full',
            'crm' => 'full', 'reps' => 'full', 'marketing' => 'full',
            'analytics' => 'full', 'logs' => 'full', 'config' => 'full',
            'raise' => 'full', 'team' => 'full',
            'scheduling' => 'full', // MARKER-SCHED-ADMIN
        ],
        'admin' => [
            'dashboard' => 'full', 'tenants' => 'full', 'impersonation' => 'full',
            'features' => 'full', 'domains' => 'full', 'catalog' => 'full',
            'crm' => 'full', 'reps' => 'full', 'marketing' => 'full',
            'analytics' => 'full', 'logs' => 'full', 'config' => 'full',
            'team' => 'view',
            'scheduling' => 'full', // MARKER-SCHED-ADMIN
        ],
        'support' => [
            'dashboard' => 'view', 'tenants' => 'full', 'impersonation' => 'full',
            'features' => 'full', 'domains' => 'full', 'catalog' => 'view',
            'logs' => 'full',
        ],
        'sales' => [
            'dashboard' => 'view', 'tenants' => 'view',
            'crm' => 'full', 'reps' => 'full', 'analytics' => 'view',
            'scheduling' => 'full', // MARKER-SCHED-ADMIN
        ],
    ];

    /** 'full' | 'view' | null */
    public static function level(?User $user, string $area): ?string
    {
        $role = $user?->roleName();
        if ($role === null) {
            return null;
        }
        return self::MATRIX[$role][$area] ?? null;
    }

    public static function allows(?User $user, string $area): bool
    {
        return self::level($user, $area) !== null;
    }

    /**
     * Map the first URL segment after /admin to an area. null = unmapped,
     * which the middleware treats as owner/admin only (safe-closed default
     * for any page added later without a mapping).
     */
    public static function areaForAdminPath(string $segment): ?string
    {
        if ($segment === '' ) {
            return 'dashboard';
        }
        if ($segment === 'distributors' || $segment === 'distributor-field-maps'
            || str_starts_with($segment, 'catalog-')) {
            return 'catalog';
        }
        return match ($segment) {
            'tenants', 'password-editor'                     => 'tenants',
            'tenant-domains'                                 => 'domains',
            'sales-channels', 'sales-prospects'              => 'crm',
            'sales-agencies'                                 => 'reps',
            'marketing-pages', 'platform-nav-items',
            'changelog-entries', 'roadmap-entries',
            'site-settings', 'section-libraries',
            'changelog-import-preview'                       => 'marketing',
            'marketing-traffic'                              => 'analytics',
            'debug-logs'                                     => 'logs',
            'theme-editor', 'billing-configuration',
            'email-health', 'platform-email',
            'customers', 'licenses', 'activations'           => 'config',
            'raise', 'raise-setup', 'investor-record'        => 'raise',
            'team-roles'                                     => 'team',
            'scheduling', 'scheduling-availability',
            'scheduling-types'                               => 'scheduling', // MARKER-SCHED-ADMIN
            // roles-access is a read-only reference every staff role may
            // open; dashboard is the one area all four roles hold.
            'roles-access'                                   => 'dashboard',
            default                                          => null,
        };
    }
}
