<?php
// MARKER-PATCH-247

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantStaffAlert;
use App\Services\Tenant\StaffAlertService;
use Illuminate\Console\Command;

/**
 * rentals:overdue-sweep — overdue is DERIVED (status=out AND due_at < now,
 * never stored), so nothing emitted the registered rental.overdue alert
 * until this sweep existed. Runs every 15 minutes; each rental alerts
 * exactly once per overdue episode (dedupe: an alert row with this
 * rental_id in meta already exists). Idempotent and cheap: one query for
 * candidates, one existence check each.
 */
class RentalsOverdueSweep extends Command
{
    protected $signature = 'rentals:overdue-sweep';
    protected $description = 'Emit staff alerts for rentals that have gone overdue.';

    public function handle(StaffAlertService $alerts): int
    {
        $overdue = TenantRental::query()
            ->where('status', 'out')
            ->whereNull('returned_at')
            ->where('due_at', '<', now())
            ->get(['id', 'tenant_id', 'rental_number', 'due_at', 'customer_id']);

        $emitted = 0;
        $tenants = [];

        foreach ($overdue as $rental) {
            $already = TenantStaffAlert::where('tenant_id', $rental->tenant_id)
                ->where('event', 'rental.overdue')
                ->where('meta->rental_id', $rental->id)
                ->exists();
            if ($already) {
                continue;
            }

            $tenants[$rental->tenant_id] ??= Tenant::find($rental->tenant_id);
            $tenant = $tenants[$rental->tenant_id];
            if (!$tenant) {
                continue;
            }

            $alerts->emit($tenant, 'rental.overdue', [
                'title' => 'Rental overdue — ' . $rental->rental_number,
                'body'  => 'Due back ' . tlocal_datetime($rental->due_at, 'D M j, g:i A') . ' and still out.',
                'link'  => '/admin/rentals/bookings/' . $rental->id,
                'meta'  => ['rental_id' => $rental->id],
            ]);
            $emitted++;
        }

        $this->info("Swept " . $overdue->count() . " overdue rentals, emitted {$emitted} new alerts.");
        return self::SUCCESS;
    }
}
