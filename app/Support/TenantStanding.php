<?php
// MARKER-TENANT-STANDING — the single answer to "what state is this shop in".
// Middleware, banner and master admin all read this, so there is one rule.

namespace App\Support;

use App\Models\BillingSettings;
use App\Models\Tenant;

class TenantStanding
{
    public const OK        = 'ok';
    public const GRACE     = 'grace';      // past due, still inside the grace window
    public const LOCKED    = 'locked';     // grace ran out
    public const SUSPENDED = 'suspended';  // an Intake decision, not a billing one

    public static function graceDays(): int
    {
        $days = (int) (BillingSettings::current()->past_due_grace_days ?? 14);
        return max(0, min(120, $days));
    }

    /** 'lock' or 'readonly' */
    public static function afterGrace(): string
    {
        return BillingSettings::current()->past_due_action === 'readonly' ? 'readonly' : 'lock';
    }

    /** @return array{state:string,days_left:?int,ends_at:?\Illuminate\Support\Carbon,reason:?string} */
    public static function of(?Tenant $tenant): array
    {
        $none = ['state' => self::OK, 'days_left' => null, 'ends_at' => null, 'reason' => null];
        if (! $tenant) {
            return $none;
        }

        if (($tenant->suspended_at ?? null) !== null) {
            return ['state' => self::SUSPENDED, 'days_left' => null, 'ends_at' => null,
                    'reason' => $tenant->suspended_reason];
        }

        if ($tenant->subscription_status !== 'past_due') {
            return $none;
        }

        // No stamp means we only just learned about it; treat now as day zero
        // rather than locking a shop out on a date we never recorded.
        $since  = $tenant->past_due_since ?: now();
        $endsAt = $since->copy()->addDays(self::graceDays());
        $left   = (int) ceil(now()->floatDiffInDays($endsAt, false));

        if (now()->greaterThanOrEqualTo($endsAt)) {
            return ['state' => self::LOCKED, 'days_left' => 0, 'ends_at' => $endsAt, 'reason' => null];
        }

        return ['state' => self::GRACE, 'days_left' => max(0, $left), 'ends_at' => $endsAt, 'reason' => null];
    }

    public static function blocksAdmin(?Tenant $tenant): bool
    {
        $s = self::of($tenant)['state'];
        if ($s === self::SUSPENDED) {
            return true;
        }
        return $s === self::LOCKED && self::afterGrace() === 'lock';
    }

    /** Read-only mode: they can look, they can't write. */
    public static function isReadOnly(?Tenant $tenant): bool
    {
        return self::of($tenant)['state'] === self::LOCKED && self::afterGrace() === 'readonly';
    }
}
