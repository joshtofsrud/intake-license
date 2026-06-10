<?php
// MARKER-PATCH-217

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalPayment;
use App\Models\Tenant\TenantRentalUnit;
use Illuminate\Http\Request;

/**
 * Rental Desk — live view of the fleet. This patch ships the stat row +
 * empty state; due-back table, upcoming pickups, and fleet snapshot land
 * with the Fleet/Bookings patches. All comparisons are UTC instants
 * against UTC datetime columns (display converts via tlocal()).
 */
class RentalDeskController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();

        $unitsTotal = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->where('status', '!=', 'retired')
            ->count();

        $outNow = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'out')
            ->count();

        $overdue = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'out')
            ->where('due_at', '<', now())
            ->count();

        $reserved = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'reserved')
            ->count();

        // MTD revenue from the ledger (Rail 2) — the ledger is canon, not
        // rental status columns. Month boundary is UTC for this stub; the
        // reports patch does tenant-local boundaries properly.
        $mtdRevenueCents = (int) TenantRentalPayment::where('tenant_id', $tenant->id)
            ->where('recorded_at', '>=', now()->startOfMonth())
            ->sum('amount_cents');

        // MARKER-PATCH-219 — live desk tables.
        $dueBack = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'out')
            ->with(['customer:id,first_name,last_name', 'lines:id,rental_id,name_snapshot,kind'])
            ->orderBy('due_at')
            ->limit(15)
            ->get();

        $upcoming = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'reserved')
            ->where('starts_at', '<', now()->addDays(7))
            ->with(['customer:id,first_name,last_name', 'lines:id,rental_id,name_snapshot,kind'])
            ->orderBy('starts_at')
            ->limit(15)
            ->get();

        return view('tenant.rentals.desk', [
            'unitsTotal'      => $unitsTotal,
            'outNow'          => $outNow,
            'overdue'         => $overdue,
            'reserved'        => $reserved,
            'mtdRevenueCents' => max(0, $mtdRevenueCents),
            'dueBack'         => $dueBack,
            'upcoming'        => $upcoming,
        ]);
    }
}
