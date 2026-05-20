<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * TenantAuthPolicy
 *
 * Single source of truth for auth policy values that can be overridden per
 * tenant via the Security admin page (chunk 8). Every read site in the auth
 * flow delegates to this helper instead of reading config() directly.
 *
 * Storage: tenant.settings.security.<key>. Falls back to config('intake.auth.*')
 * when the tenant has no override.
 *
 * Why a static helper instead of an instance service: these are simple lookups
 * with no state. A static helper is cheaper to call from hot-path middleware
 * (no container resolve per request) and the call site reads cleaner:
 *   TenantAuthPolicy::idleThresholdSec($tenant)  vs
 *   app(TenantAuthPolicy::class)->idleThresholdSec($tenant)
 */
class TenantAuthPolicy
{
    /**
     * Idle threshold in seconds before the lock overlay fires.
     */
    public static function idleThresholdSec(?Tenant $tenant): int
    {
        $default = (int) config('intake.auth.pin_idle_threshold_sec', 120);

        if (! $tenant) {
            return $default;
        }

        $value = data_get($tenant->settings, 'security.pin_idle_threshold_sec');
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * How long a "Trust this device" cookie stays valid (in days), sliding
     * window. Used by DeviceTrustService::mint() and ::touch() to set
     * expires_at on the trusted_devices row.
     */
    public static function deviceTrustExpiryDays(?Tenant $tenant): int
    {
        $default = (int) config('intake.auth.device_trust_expiry_days', 90);

        if (! $tenant) {
            return $default;
        }

        $value = data_get($tenant->settings, 'security.device_trust_expiry_days');
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Sticky window for a sensitive-action gate, in seconds. 0 means
     * "always prompt." Each action has its own value.
     *
     * Today only switch_location is wired and is stored under
     * security.switch_location_sticky_sec. Future actions add their own
     * settings keys.
     */
    public static function actionStickySec(?Tenant $tenant, string $action): int
    {
        $defaults = config('intake.auth.pin_action_sticky_sec', []);
        $default = (int) ($defaults[$action] ?? 0);

        if (! $tenant) {
            return $default;
        }

        // Per-action key in settings.security.<action>_sticky_sec.
        $settingsKey = 'security.' . $action . '_sticky_sec';
        $value = data_get($tenant->settings, $settingsKey);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Client heartbeat interval. Not currently tenant-tunable (the admin
     * page doesn't expose it); kept here for symmetry and future use.
     */
    public static function heartbeatIntervalSec(?Tenant $tenant): int
    {
        return (int) config('intake.auth.pin_heartbeat_interval_sec', 60);
    }
}
