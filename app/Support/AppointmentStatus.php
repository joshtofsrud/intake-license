<?php
// MARKER-PATCH-283

namespace App\Support;

/**
 * AppointmentStatus — the single source of truth for what an appointment status
 * MEANS. The rest of the app keys behaviour (inventory, receipts, the sale
 * bridge, dashboards, scopes, revenue) off the ROLE, never the literal name, so
 * a shop can rename / add statuses later without breaking anything.
 *
 * Today this is a static default set. When per-tenant custom statuses ship, these
 * methods become tenant-aware (reading a config/table) and every consumer keeps
 * working unchanged.
 */
class AppointmentStatus
{
    // ---- Roles (the fixed vocabulary the system understands) ----
    public const ROLE_AWAITING  = 'awaiting';   // booked, needs confirmation
    public const ROLE_SCHEDULED = 'scheduled';  // confirmed, on the calendar
    public const ROLE_ACTIVE    = 'active';     // work in progress
    public const ROLE_DONE      = 'done';       // finished — commits + receipt + sale
    public const ROLE_CANCELLED = 'cancelled';  // terminal off-ramp

    /**
     * status => role. Includes the legacy shipped/closed/refunded so any existing
     * row still resolves to a role (they are no longer selectable, but they map).
     */
    public const ROLES = [
        'pending'     => self::ROLE_AWAITING,
        'confirmed'   => self::ROLE_SCHEDULED,
        'in_progress' => self::ROLE_ACTIVE,
        'completed'   => self::ROLE_DONE,
        'shipped'     => self::ROLE_DONE,       // legacy
        'closed'      => self::ROLE_DONE,       // legacy
        'cancelled'   => self::ROLE_CANCELLED,
        'refunded'    => self::ROLE_CANCELLED,  // legacy
    ];

    /** The trimmed, selectable default set offered to shops (no shipped/closed/refunded). */
    public const DEFAULT_SET = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

    /** Human labels for the known statuses. */
    public const LABELS = [
        'pending'     => 'Pending',
        'confirmed'   => 'Confirmed',
        'in_progress' => 'In progress',
        'completed'   => 'Completed',
        'shipped'     => 'Shipped',
        'closed'      => 'Closed',
        'cancelled'   => 'Cancelled',
        'refunded'    => 'Refunded',
    ];

    // ---- Role lookups -------------------------------------------------
    public static function role(string $status): ?string
    {
        return self::ROLES[$status] ?? null;
    }

    /** @return string[] every status carrying the given role */
    public static function statusesForRole(string $role): array
    {
        return array_keys(array_filter(self::ROLES, fn ($r) => $r === $role));
    }

    public static function isDone(string $status): bool
    {
        return self::role($status) === self::ROLE_DONE;
    }

    public static function isTerminal(string $status): bool
    {
        return self::role($status) === self::ROLE_CANCELLED;
    }

    public static function isActive(string $status): bool
    {
        return self::role($status) !== null && !self::isTerminal($status);
    }

    // ---- Convenience lists (for whereIn / whereNotIn) -----------------
    /** @return string[] ['completed','shipped','closed'] — parts-committing statuses */
    public static function doneStatuses(): array
    {
        return self::statusesForRole(self::ROLE_DONE);
    }

    /** @return string[] ['cancelled','refunded'] — excluded from active scopes */
    public static function terminalStatuses(): array
    {
        return self::statusesForRole(self::ROLE_CANCELLED);
    }

    /** @return string[] every non-terminal status */
    public static function activeStatuses(): array
    {
        return array_values(array_diff(array_keys(self::ROLES), self::terminalStatuses()));
    }

    // ---- Display ------------------------------------------------------
    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** @return array<string,string> selectable status => label (the trimmed set) */
    public static function selectable(): array
    {
        $out = [];
        foreach (self::DEFAULT_SET as $s) {
            $out[$s] = self::label($s);
        }
        return $out;
    }
}
