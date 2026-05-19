<?php

namespace App\Services;

use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * PinService
 *
 * The API for Layer 2 of the auth refactor (auth-refactor-spec-v2.md §4).
 *
 * PINs are 4 digits. Stored as bcrypt hashes — the threat model isn't
 * "hash leaks" (4 digits = 10K combinations, instantly brute-forceable
 * from a leak) but "someone tries codes on the live device." Bcrypt
 * makes hash-leak scenarios merely bad, not catastrophic.
 *
 * Lockout policy is in config('intake.auth') — see comments there.
 */
class PinService
{
    /**
     * Set or reset a user's PIN. Caller is responsible for any second-
     * factor check (e.g. device-password re-auth) — this method just
     * persists.
     *
     * Resets the failure counter and clears any lockout when a new PIN
     * is set.
     */
    public function setPin(TenantUser $user, string $digits): void
    {
        if (! $this->validateDigits($digits)) {
            throw new \InvalidArgumentException('PIN must be exactly 4 digits.');
        }

        $user->forceFill([
            'pin_hash'          => Hash::make($digits),
            'pin_set_at'        => now(),
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
        ])->save();

        Log::info('Pin.set', [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
        ]);
    }

    /**
     * Verify a PIN attempt. Returns true on success, false on failure
     * (failure counter and lockout state are updated accordingly).
     *
     * Throws \DomainException if the user is currently locked out — the
     * caller should check isLocked() first, but throw is defense in depth.
     */
    public function verifyPin(TenantUser $user, string $digits): bool
    {
        if ($this->isLocked($user)) {
            throw new \DomainException('PIN entry locked out for this user.');
        }

        if (! $user->pin_hash) {
            // No PIN set — caller should route to setInitialPin instead.
            return false;
        }

        if (! $this->validateDigits($digits)) {
            return $this->recordFailure($user);
        }

        if (! Hash::check($digits, $user->pin_hash)) {
            return $this->recordFailure($user);
        }

        // Success — reset counter, bump last-used.
        $user->forceFill([
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
            'pin_last_used_at'  => now(),
        ])->save();

        return true;
    }

    /**
     * Is the user's PIN entry currently locked out?
     * (Soft-locked card after too many failures.)
     */
    public function isLocked(TenantUser $user): bool
    {
        if (! $user->pin_locked_until) {
            return false;
        }
        return $user->pin_locked_until->isFuture();
    }

    /**
     * Owner action: clear lockout state on a user. Doesn't change the PIN.
     */
    public function unlockUser(TenantUser $user, ?TenantUser $byUser = null): void
    {
        $user->forceFill([
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
        ])->save();

        Log::info('Pin.unlock', [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'by_user'   => $byUser?->id,
        ]);
    }

    /**
     * Owner action: force the user to set a new PIN on next sign-in.
     */
    public function forceReset(TenantUser $user, ?TenantUser $byUser = null): void
    {
        $user->forceFill([
            'pin_hash'          => null,
            'pin_set_at'        => null,
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
        ])->save();

        Log::info('Pin.forceReset', [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'by_user'   => $byUser?->id,
        ]);
    }

    /**
     * Record a failed PIN attempt + apply the cooldown ladder.
     */
    protected function recordFailure(TenantUser $user): bool
    {
        $ladder = config('intake.auth.pin_cooldown_ladder', [0, 0, 5, 30, 0]);
        $maxFailures = count($ladder); // 5 by default

        $newCount = $user->pin_failed_count + 1;

        $update = ['pin_failed_count' => $newCount];

        // If we've burned through the ladder, the card is soft-locked
        // until owner unlock or email reset.
        if ($newCount >= $maxFailures) {
            $update['pin_locked_until'] = now()->addYears(99); // effectively infinite
        } else {
            // Otherwise, apply the cooldown from the ladder.
            $cooldownSec = (int) ($ladder[$newCount - 1] ?? 0);
            if ($cooldownSec > 0) {
                $update['pin_locked_until'] = now()->addSeconds($cooldownSec);
            }
        }

        $user->forceFill($update)->save();

        Log::info('Pin.failure', [
            'tenant_id'    => $user->tenant_id,
            'user_id'      => $user->id,
            'failed_count' => $newCount,
        ]);

        return false;
    }

    /**
     * Is this string exactly 4 digits?
     */
    protected function validateDigits(string $digits): bool
    {
        return (bool) preg_match('/^\d{4}$/', $digits);
    }
}
