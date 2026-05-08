<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantAppointment;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates appointment ↔ inventory transitions.
 *
 * Pulls the tenant's default location once, opens a single transaction,
 * and commits / reverses every part on the appointment.
 *
 * Bidirectional safe: both methods check committed_at on each part, so
 * you can call commit() on an already-committed appointment (idempotent
 * no-op) or revert() on an uncommitted one (also no-op).
 *
 * Throws InventoryStockException if commit fails for stock reasons.
 * Caller is responsible for catching and reverting any other state
 * (status change) that should be unwound.
 */
class AppointmentInventoryService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Commit (decrement) every uncommitted part on the appointment.
     * Called when entering completed / shipped / closed.
     */
    public function commitParts(TenantAppointment $appointment): void
    {
        $locationId = $this->resolveLocationId($appointment);
        if (!$locationId) {
            // No default location configured — nothing to do. Parts will
            // remain on the appointment as billable lines but won't move
            // stock until a location exists. Caller should surface this.
            return;
        }

        DB::transaction(function () use ($appointment, $locationId) {
            // Reload parts inside the transaction so we get a consistent view
            // and avoid acting on stale Eloquent state.
            $parts = $appointment->parts()->whereNull('committed_at')->get();
            foreach ($parts as $part) {
                $this->inventory->decrementForAppointmentPart($appointment, $part, $locationId);
            }
        });
    }

    /**
     * Revert (increment) every committed part on the appointment.
     * Called when leaving a committed status — refunded, cancelled from
     * completed, or any backwards move (completed → in_progress, etc).
     */
    public function revertParts(TenantAppointment $appointment): void
    {
        $locationId = $this->resolveLocationId($appointment);
        if (!$locationId) {
            return;
        }

        DB::transaction(function () use ($appointment, $locationId) {
            $parts = $appointment->parts()->whereNotNull('committed_at')->get();
            foreach ($parts as $part) {
                $this->inventory->incrementForAppointmentPart($appointment, $part, $locationId);
            }
        });
    }

    /**
     * Statuses where parts are considered "consumed" and stock is decremented.
     * Anything outside this set is "not yet consumed."
     */
    public static function isCommittedStatus(string $status): bool
    {
        return in_array($status, ['completed', 'shipped', 'closed'], true);
    }

    /**
     * Resolve which location's inventory pool to use. Per the locked design
     * decision (locations is a coming-soon add-on), we use the tenant's
     * default location. If none exists, return null and the caller treats
     * this as a no-op.
     */
    private function resolveLocationId(TenantAppointment $appointment): ?string
    {
        $tenant = $appointment->tenant;
        if (!$tenant) {
            return null;
        }
        $loc = $tenant->defaultLocation;
        return $loc?->id;
    }
}
