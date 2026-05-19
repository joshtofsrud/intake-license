<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantTrustedDevice;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DeviceTrustService
 *
 * The API for Layer 1 of the auth refactor (auth-refactor-spec-v2.md §3).
 *
 * Trust model: when a user opts in at sign-in, we mint a long-lived cookie
 * whose value is a 64-char random string. We persist SHA-256(value) in
 * tenant_trusted_devices, never the value itself. On subsequent requests
 * the EnsureTrustedDevice middleware (chunk 3) hashes the cookie and looks
 * up the row.
 *
 * Expiration: 90-day sliding window. last_used_at is touched on every
 * authenticated request; expires_at is pushed to last_used_at + 90 days.
 *
 * Threats this defends against:
 *   - DB leak: hash makes the cookie unusable without the plaintext
 *   - Cookie theft: standard HttpOnly/Secure/SameSite=Lax mitigations
 *   - Stale devices: 90-day idle expiry + manual revoke
 */
class DeviceTrustService
{
    /** Cookie name. Scoped to .intake.works so all tenants share the same name. */
    public const COOKIE_NAME = 'intake_device_trust';

    /** Sliding-window expiry in days. */
    public const EXPIRY_DAYS = 90;

    /** Cookie lifetime in minutes (matches EXPIRY_DAYS). */
    public const COOKIE_MINUTES = 60 * 24 * 90;

    /**
     * Mint a new trusted-device row + return the cookie value to set.
     *
     * Caller is responsible for actually setting the cookie on the response
     * — this method only generates and persists. Returns the plaintext
     * cookie value (the only time it exists in memory outside the cookie).
     *
     * @return string  The plaintext cookie value to set on the response.
     */
    public function mint(Tenant $tenant, Request $request, ?string $label = null): string
    {
        $plaintext = Str::random(64);
        $hash = hash('sha256', $plaintext);

        TenantTrustedDevice::create([
            'tenant_id'         => $tenant->id,
            'device_token_hash' => $hash,
            'label'             => $label,
            'user_agent_seen'   => substr((string) $request->userAgent(), 0, 1000),
            'ip_first_seen'     => $request->ip(),
            'ip_last_seen'      => $request->ip(),
            'trusted_at'        => now(),
            'last_used_at'      => now(),
            'expires_at'        => now()->addDays(self::EXPIRY_DAYS),
        ]);

        return $plaintext;
    }

    /**
     * Validate a cookie value against the tenant's active trusted devices.
     *
     * Returns the matched device row, or null if no match / expired /
     * revoked.
     */
    public function validate(Tenant $tenant, ?string $cookieValue): ?TenantTrustedDevice
    {
        if (! $cookieValue || ! is_string($cookieValue)) {
            return null;
        }

        // Length sanity check — short-circuits malformed cookies without
        // hitting the DB.
        if (strlen($cookieValue) !== 64) {
            return null;
        }

        $hash = hash('sha256', $cookieValue);

        $device = TenantTrustedDevice::activeForTenant($tenant->id)
            ->where('device_token_hash', $hash)
            ->first();

        return $device;
    }

    /**
     * Touch a device on each authenticated request: bump last_used_at and
     * push expires_at forward (sliding window). Also updates ip_last_seen
     * if the IP changed.
     *
     * Cheap operation — no DB query if the row was already touched within
     * the last hour (avoids hammering on every request).
     */
    public function touch(TenantTrustedDevice $device, Request $request): void
    {
        // Skip if touched recently. Hour granularity is plenty for a
        // 90-day sliding window.
        if ($device->last_used_at && $device->last_used_at->diffInMinutes(now()) < 60) {
            return;
        }

        $device->forceFill([
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(self::EXPIRY_DAYS),
            'ip_last_seen' => $request->ip(),
        ])->save();
    }

    /**
     * Revoke a single device. Used by:
     *   - "Sign out this device" (sets revoked_by_user_id to the current user)
     *   - Trusted-devices admin screen (owner revoking another device)
     */
    public function revoke(TenantTrustedDevice $device, ?TenantUser $byUser = null): void
    {
        if ($device->revoked_at !== null) {
            return; // already revoked, idempotent
        }

        $device->forceFill([
            'revoked_at'         => now(),
            'revoked_by_user_id' => $byUser?->id,
        ])->save();

        Log::info('DeviceTrust.revoke', [
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'by_user'   => $byUser?->id,
        ]);
    }

    /**
     * Revoke every active device for a tenant. The "lost iPad — revoke
     * everything" admin action.
     */
    public function revokeAllForTenant(Tenant $tenant, ?TenantUser $byUser = null): int
    {
        $count = TenantTrustedDevice::activeForTenant($tenant->id)
            ->update([
                'revoked_at'         => now(),
                'revoked_by_user_id' => $byUser?->id,
                'updated_at'         => now(),
            ]);

        Log::info('DeviceTrust.revokeAll', [
            'tenant_id' => $tenant->id,
            'count'     => $count,
            'by_user'   => $byUser?->id,
        ]);

        return $count;
    }
}
