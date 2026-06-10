<?php
// MARKER-PATCH-217 / MARKER-PATCH-219 / MARKER-PATCH-222 — Rental Desk.
// 222 rewrote this controller to power the mockup's live view.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalCategory;
use App\Models\Tenant\TenantRentalUnit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rental Desk — the live view: what's out, what's due, what's free.
 * Every number is derived (rentals + the sales-as-money ledger); nothing
 * here is a stored counter that can go stale.
 */
class RentalDeskController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $tz     = $tenant->timezone();
        $nowUtc = now();

        $todayStart = Carbon::now($tz)->startOfDay()->utc();
        $todayEnd   = Carbon::now($tz)->endOfDay()->utc();

        // ------------------------------------------------------ fleet base
        $rentableUnits = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->where('status', '!=', 'retired')
            ->where('available_for_rent', true)
            ->count();

        // ------------------------------------------------------ stat row
        $outNow = TenantRental::where('tenant_id', $tenant->id)->where('status', 'out')->count();

        $overdueCount = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'out')->where('due_at', '<', $nowUtc)->count();

        $dueTodayCount = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'out')
            ->whereBetween('due_at', [$todayStart, $todayEnd])
            ->count();

        $pickupsTodayCount = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'reserved')
            ->whereBetween('starts_at', [$todayStart, $todayEnd])
            ->count();

        $nextPickupAt = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'reserved')
            ->where('starts_at', '>=', $nowUtc)
            ->orderBy('starts_at')
            ->value('starts_at');

        // MTD revenue from the ledger (sales-as-money, MARKER-PATCH-219B),
        // compared against the SAME elapsed span of last month.
        $monthStart     = Carbon::now($tz)->startOfMonth()->utc();
        $prevMonthStart = Carbon::now($tz)->subMonthNoOverflow()->startOfMonth()->utc();
        $prevSpanEnd    = $prevMonthStart->copy()->addSeconds($monthStart->diffInSeconds($nowUtc));

        $ledger = fn ($from, $to) => (int) DB::table('tenant_sale_payments')
            ->join('tenant_sales', 'tenant_sales.id', '=', 'tenant_sale_payments.sale_id')
            ->whereNotNull('tenant_sales.rental_id')
            ->where('tenant_sale_payments.tenant_id', $tenant->id)
            ->where('tenant_sale_payments.recorded_at', '>=', $from)
            ->where('tenant_sale_payments.recorded_at', '<', $to)
            ->sum('tenant_sale_payments.amount_cents');

        $mtdRevenueCents  = max(0, $ledger($monthStart, $nowUtc));
        $prevSpanCents    = max(0, $ledger($prevMonthStart, $prevSpanEnd));
        $revenueDeltaPct  = $prevSpanCents > 0
            ? (int) round((($mtdRevenueCents - $prevSpanCents) / $prevSpanCents) * 100)
            : null;
        $prevMonthLabel   = Carbon::now($tz)->subMonthNoOverflow()->format('M');

        $heldDeposits = TenantRental::where('tenant_id', $tenant->id)
            ->where('deposit_status', 'authorized')
            ->selectRaw('COUNT(*) as holds, COALESCE(SUM(deposit_hold_cents), 0) as cents')
            ->first();

        // Utilization 7d — actual rented unit-minutes over rentable capacity.
        $winStart = $nowUtc->copy()->subDays(7);
        $usageRentals = TenantRental::where('tenant_id', $tenant->id)
            ->whereIn('status', ['out', 'returned'])
            ->where('starts_at', '<', $nowUtc)
            ->where(function ($q) use ($winStart) {
                $q->whereNull('returned_at')->orWhere('returned_at', '>', $winStart);
            })
            ->withCount(['lines as unit_count' => fn ($q) => $q->where('kind', 'unit')])
            ->get(['id', 'status', 'starts_at', 'returned_at']);

        $usedMinutes = 0;
        foreach ($usageRentals as $r) {
            $useStart = $r->starts_at->greaterThan($winStart) ? $r->starts_at : $winStart;
            $useEnd   = $r->status === 'returned' ? ($r->returned_at ?? $nowUtc) : $nowUtc;
            if ($useEnd->greaterThan($nowUtc)) {
                $useEnd = $nowUtc;
            }
            if ($useEnd->greaterThan($useStart)) {
                $usedMinutes += $useStart->diffInMinutes($useEnd) * max(1, (int) $r->unit_count);
            }
        }
        $capacityMinutes = $rentableUnits * 7 * 24 * 60;
        $utilizationPct  = $capacityMinutes > 0
            ? min(100, (int) round(($usedMinutes / $capacityMinutes) * 100))
            : 0;

        // ----------------------------------------------------- two tables
        $dueBack = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'out')
            ->where(function ($q) use ($nowUtc, $todayEnd) {
                $q->where('due_at', '<', $nowUtc)            // already overdue (any day)
                  ->orWhere('due_at', '<=', $todayEnd);      // or due today
            })
            ->with(['customer:id,first_name,last_name', 'lines:id,rental_id,name_snapshot,kind'])
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $pickups = TenantRental::where('tenant_id', $tenant->id)
            ->where('status', 'reserved')
            ->where('starts_at', '<', $nowUtc->copy()->addDays(7))
            ->with([
                'customer:id,first_name,last_name',
                'lines' => fn ($q) => $q->orderBy('sort_order'),
            ])
            ->orderBy('starts_at')
            ->limit(8)
            ->get();

        // -------------------------------------------------- fleet snapshot
        $categories = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $unitAgg = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->where('status', '!=', 'retired')
            ->selectRaw("category_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maint")
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        // Units physically out right now, per category.
        $outByCategory = DB::table('tenant_rental_lines')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_lines.rental_id')
            ->join('tenant_rental_units', 'tenant_rental_units.id', '=', 'tenant_rental_lines.unit_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->where('tenant_rentals.status', 'out')
            ->where('tenant_rental_lines.kind', 'unit')
            ->selectRaw('tenant_rental_units.category_id, COUNT(DISTINCT tenant_rental_units.id) as out_units')
            ->groupBy('tenant_rental_units.category_id')
            ->pluck('out_units', 'category_id');

        $fleetSnapshot = $categories->map(function ($cat) use ($unitAgg, $outByCategory) {
            $agg   = $unitAgg->get($cat->id);
            $total = (int) ($agg->total ?? 0);
            $maint = (int) ($agg->maint ?? 0);
            $out   = (int) ($outByCategory[$cat->id] ?? 0);
            $avail = max(0, $total - $maint - $out);

            if ($maint > 0) {
                [$badge, $label] = ['maint', 'Attention'];
            } elseif ($total > 0 && $avail * 3 <= $total) {
                [$badge, $label] = ['tight', 'Tight'];
            } else {
                [$badge, $label] = ['healthy', 'Healthy'];
            }

            return [
                'id'    => $cat->id,
                'name'  => $cat->name,
                'total' => $total,
                'avail' => $avail,
                'maint' => $maint,
                'out'   => $out,
                'badge' => $badge,
                'label' => $label,
            ];
        })->filter(fn ($c) => $c['total'] > 0)->values();

        return view('tenant.rentals.desk', [
            'rentableUnits'     => $rentableUnits,
            'outNow'            => $outNow,
            'overdueCount'      => $overdueCount,
            'dueTodayCount'     => $dueTodayCount,
            'pickupsTodayCount' => $pickupsTodayCount,
            'nextPickupAt'      => $nextPickupAt,
            'mtdRevenueCents'   => $mtdRevenueCents,
            'revenueDeltaPct'   => $revenueDeltaPct,
            'prevMonthLabel'    => $prevMonthLabel,
            'heldDepositCents'  => (int) ($heldDeposits->cents ?? 0),
            'heldDepositCount'  => (int) ($heldDeposits->holds ?? 0),
            'utilizationPct'    => $utilizationPct,
            'dueBack'           => $dueBack,
            'pickups'           => $pickups,
            'fleetSnapshot'     => $fleetSnapshot,
        ]);
    }
}
