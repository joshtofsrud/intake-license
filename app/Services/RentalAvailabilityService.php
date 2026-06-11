<?php
// MARKER-PATCH-218

namespace App\Services;

use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalUnit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * RentalAvailabilityService — the single definition of "free" for rental
 * units. The desk timeline, the booking flow, the public rental site, and
 * the last-minute-extension eligibility scan ALL resolve availability here;
 * there is exactly one availability brain (same architecture as the
 * appointment side).
 *
 * Semantics for [start, end):
 *   - unit must be status 'available' (maintenance/retired block entirely),
 *     not archived, and available_for_rent
 *   - no reserved/out rental on the unit whose EFFECTIVE END + the unit's
 *     buffer_minutes overlaps the window
 *   - effective end = due_at, EXCEPT an overdue 'out' rental (due_at past,
 *     not returned) blocks rolling-forward: effective end = now(). A late
 *     return can never silently double-book the next customer.
 *
 * All instants are UTC (datetime casts) — callers convert for display only.
 *
 * NOTE on locking: this service answers "is it free?"; it does NOT reserve.
 * Writers must re-check availability INSIDE a MySQLLock::withLock critical
 * section (per tenant+unit) before inserting — same discipline as
 * BookingService. Read-then-write without the lock is a race.
 */
class RentalAvailabilityService
{
    public function isUnitAvailable(
        TenantRentalUnit $unit,
        Carbon $start,
        Carbon $end,
        ?string $excludeRentalId = null
    ): bool {
        if ($end->lte($start)) {
            return false;
        }
        if ($unit->archived_at !== null
            || $unit->status !== 'available'
            || !$unit->available_for_rent) {
            return false;
        }

        return !$this->hasConflict($unit, $start, $end, $excludeRentalId);
    }

    /**
     * Any reserved/out rental on this unit overlapping [start, end),
     * buffer-padded? Candidate set is fetched per unit (small) and decided
     * in PHP so the overdue-rolls-forward rule stays in one obvious place.
     */
    public function hasConflict(
        TenantRentalUnit $unit,
        Carbon $start,
        Carbon $end,
        ?string $excludeRentalId = null
    ): bool {
        $buffer = (int) $unit->buffer_minutes;

        $candidates = TenantRental::query()
            ->whereIn('status', ['reserved', 'out'])
            ->where('starts_at', '<', $end)
            ->when($excludeRentalId, fn ($q) => $q->where('id', '!=', $excludeRentalId))
            ->whereHas('lines', fn ($q) => $q->where('unit_id', $unit->id))
            ->get(['id', 'status', 'starts_at', 'due_at', 'returned_at']);

        foreach ($candidates as $rental) {
            $effectiveEnd = $rental->due_at?->copy() ?? $rental->starts_at->copy();

            // Overdue 'out' rentals block rolling-forward until returned.
            if ($rental->status === 'out'
                && $rental->returned_at === null
                && $effectiveEnd->isPast()) {
                $effectiveEnd = now();
            }

            if ($effectiveEnd->addMinutes($buffer)->gt($start)) {
                return true; // starts before our end, ends (padded) after our start
            }
        }

        // MARKER-PATCH-230 — lease assignments share the fleet. An active
        // lease whose season overlaps [start, end) blocks this unit exactly
        // like an out rental. Returned/cancelled leases (or returned
        // assignments) don't block.
        if ($this->unitHasLeaseConflict($unit->id, $start, $end, $buffer)) {
            return true;
        }

        return false;
    }

    /**
     * MARKER-PATCH-230 — does an active lease assignment for this unit
     * overlap [start, end)? Overdue active leases (season_end past, not
     * returned) block forward to now, mirroring overdue rentals.
     */
    protected function unitHasLeaseConflict(string $unitId, Carbon $start, Carbon $end, int $buffer): bool
    {
        $assignments = \App\Models\Tenant\LeaseAssignment::query()
            ->where('unit_id', $unitId)
            ->whereNull('returned_at')
            ->whereHas('lease', fn ($q) => $q->where('status', 'active'))
            ->with('lease:id,season_start,season_end,returned_at,status')
            ->get();

        foreach ($assignments as $a) {
            $lease = $a->lease;
            if (!$lease) {
                continue;
            }
            $seasonStart = $lease->season_start?->copy() ?? now();
            $effectiveEnd = $lease->season_end?->copy() ?? $seasonStart->copy();

            if ($lease->returned_at === null && $effectiveEnd->isPast()) {
                $effectiveEnd = now();
            }

            // Overlap test: season starts before our end AND season (padded)
            // ends after our start.
            if ($seasonStart->lt($end) && $effectiveEnd->addMinutes($buffer)->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All units free for [start, end), optionally narrowed to a category
     * and/or to units the public site may book (online_booking).
     *
     * @return Collection<TenantRentalUnit>
     */
    public function availableUnits(
        string $tenantId,
        ?string $categoryId,
        Carbon $start,
        Carbon $end,
        bool $onlineOnly = false
    ): Collection {
        $units = TenantRentalUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('archived_at')
            ->where('status', 'available')
            ->where('available_for_rent', true)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($onlineOnly, fn ($q) => $q->where('online_booking', true))
            ->with('model') // MARKER-PATCH-227 — avoid N+1 on effective*() rate reads
            ->orderBy('name')
            ->get();

        return $units->filter(
            fn (TenantRentalUnit $u) => !$this->hasConflict($u, $start, $end)
        )->values();
    }

    /**
     * Reserved/out rentals touching [start, end) for one unit — feed for
     * the availability timeline and the extension gap check.
     *
     * @return Collection<TenantRental>
     */
    public function unitScheduleForRange(string $unitId, Carbon $start, Carbon $end): Collection
    {
        return TenantRental::query()
            ->whereIn('status', ['reserved', 'out'])
            ->where('starts_at', '<', $end)
            ->where('due_at', '>', $start)
            ->whereHas('lines', fn ($q) => $q->where('unit_id', $unitId))
            ->orderBy('starts_at')
            ->get();
    }
}
