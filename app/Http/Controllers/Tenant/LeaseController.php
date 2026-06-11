<?php
// MARKER-PATCH-230

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Lease;
use App\Models\Tenant\LeaseAssignment;
use App\Models\Tenant\LeasePackage;
use App\Models\Tenant\TenantRentalUnit;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Services\RentalAvailabilityService;
use App\Services\Tenant\StaffAlertService;
use App\Support\MySQLLock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Leases — the season-long transaction. The counter flow fills a package's
 * slots from live fleet availability (auto or by serial), assigns the units
 * for the season, takes the deposit, and bridges the season total to the
 * register. Assignments block the fleet via the shared availability brain.
 *
 * Concurrency: lease creation runs under the SAME tenant write lock as
 * rentals (intake:{t8}:rent:write) and re-checks availability INSIDE the
 * lock, so a rental and a lease can't grab the same unit in a race.
 */
class LeaseController extends Controller
{
    public function __construct(
        protected RentalAvailabilityService $availability,
        protected StaffAlertService $alerts,
    ) {}

    private function guard(): void
    {
        abort_unless(tenant()->leases_enabled, 403, 'Leasing is not enabled.');
    }

    /** The lease book — paginated, season-scoped. */
    public function index(Request $request)
    {
        $this->guard();

        $leases = Lease::where('tenant_id', tenant()->id)
            ->with('customer:id,first_name,last_name')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(25)->withQueryString();

        return view('tenant.rentals.leases.index', ['leases' => $leases]);
    }

    /** Fulfillment screen — choose a package, then fill its slots. */
    public function create(Request $request)
    {
        $this->guard();
        $tenant = tenant();

        $packages = LeasePackage::active()->where('tenant_id', $tenant->id)
            ->with(['slots.category'])->orderBy('name')->get();

        $selected = null;
        $slotOptions = [];
        if ($request->filled('package')) {
            $selected = $packages->firstWhere('id', $request->input('package'));
            if ($selected) {
                // For each slot, the available units (category + size match)
                // the counter can pick from right now.
                foreach ($selected->slots as $slot) {
                    $slotOptions[$slot->id] = $this->availableUnitsForSlot($tenant->id, $slot);
                }
            }
        }

        // Season window default from tenant settings (MM-DD -> next occurrence).
        $s = $tenant->settings ?? [];
        [$seasonStart, $seasonEnd] = $this->defaultSeasonWindow($s['season_start'] ?? '11-01', $s['season_end'] ?? '04-15');

        return view('tenant.rentals.leases.create', [
            'packages'    => $packages,
            'selected'    => $selected,
            'slotOptions' => $slotOptions,
            'seasonStart' => $seasonStart,
            'seasonEnd'   => $seasonEnd,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int,TenantRentalUnit> */
    private function availableUnitsForSlot(string $tenantId, $slot)
    {
        $q = TenantRentalUnit::where('tenant_id', $tenantId)
            ->where('category_id', $slot->category_id)
            ->whereNull('archived_at')
            ->where('status', 'available')
            ->where('available_for_rent', true);
        if ($slot->size_filter) {
            $q->where('size', 'like', '%' . $slot->size_filter . '%');
        }
        return $q->orderBy('size')->orderBy('name')->get(['id', 'name', 'identifier', 'size']);
    }

    public function store(Request $request)
    {
        $this->guard();
        $tenant = tenant();

        $data = $request->validate([
            'package_id'        => ['required', 'string'],
            'customer_id'       => ['required', 'string'],
            'season_start'      => ['required', 'date'],
            'season_end'        => ['required', 'date', 'after:season_start'],
            'assignments'       => ['required', 'array', 'min:1'],
            'assignments.*.slot_id' => ['required', 'string'],
            'assignments.*.unit_id' => ['required', 'string'],
            'deposit_cents'     => ['nullable', 'integer', 'min:0', 'max:9999900'],
        ]);

        $package = LeasePackage::where('tenant_id', $tenant->id)->findOrFail($data['package_id']);
        $start = Carbon::parse($data['season_start']);
        $end   = Carbon::parse($data['season_end']);

        $lock = app(MySQLLock::class);
        $lockKey = 'intake:' . substr($tenant->id, 0, 8) . ':rent:write';

        try {
            $lease = $lock->withLock($lockKey, function () use ($tenant, $package, $data, $start, $end) {
                // Re-check every chosen unit is still free for the season
                // INSIDE the lock — a rental or another lease may have taken
                // it between page load and submit.
                $unitIds = collect($data['assignments'])->pluck('unit_id')->all();
                $units = TenantRentalUnit::where('tenant_id', $tenant->id)
                    ->whereIn('id', $unitIds)->with('model')->get()->keyBy('id');

                foreach ($data['assignments'] as $a) {
                    $unit = $units->get($a['unit_id']);
                    if (!$unit) {
                        throw new \RuntimeException('A selected unit no longer exists.');
                    }
                    if ($unit->status !== 'available' || $unit->archived_at) {
                        throw new \RuntimeException("{$unit->name} is no longer available.");
                    }
                    if ($this->availability->hasConflict($unit, $start, $end)) {
                        throw new \RuntimeException("{$unit->name} was just taken for an overlapping period.");
                    }
                }

                $priceCents = (int) $package->season_price_cents;
                $depositCents = (int) ($data['deposit_cents'] ?? $package->deposit_cents);

                $lease = Lease::create([
                    'id'                    => (string) Str::uuid(),
                    'tenant_id'             => $tenant->id,
                    'customer_id'           => $data['customer_id'],
                    'package_id'            => $package->id,
                    'lease_number'          => $this->generateLeaseNumber($tenant->id),
                    'package_name_snapshot' => $package->name,
                    'season_start'          => $start,
                    'season_end'            => $end,
                    'status'                => 'active',
                    'subtotal_cents'        => $priceCents,
                    'tax_cents'             => 0,
                    'total_cents'           => $priceCents,
                    'paid_cents'            => 0,
                    'deposit_hold_cents'    => $depositCents,
                    'deposit_status'        => 'none',
                ]);

                foreach ($data['assignments'] as $a) {
                    $unit = $units->get($a['unit_id']);
                    LeaseAssignment::create([
                        'id'                     => (string) Str::uuid(),
                        'tenant_id'              => $tenant->id,
                        'lease_id'               => $lease->id,
                        'slot_id'                => $a['slot_id'],
                        'unit_id'                => $unit->id,
                        'unit_name_snapshot'     => $unit->name,
                        'unit_serial_snapshot'   => $unit->identifier,
                        'category_name_snapshot' => optional($unit->category)->name,
                    ]);
                }

                return $lease;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['assignments' => $e->getMessage()]);
        }

        // Staff alert (best-effort, afterCommit inside the service).
        $this->alerts->emit($tenant, 'lease.created', [
            'title' => 'New lease — ' . $lease->lease_number,
            'body'  => $lease->package_name_snapshot,
            'link'  => route('tenant.rentals.leases.show', $lease->id),
            'meta'  => ['lease_id' => $lease->id],
        ]);

        // Bridge the season total to the register as an LS- draft sale.
        $sale = $this->createLeaseSale($tenant, $lease);

        return redirect(route('tenant.register.index') . '?resume=' . $sale->id)
            ->with('flash', "Lease {$lease->lease_number} created — collect {$lease->package_name_snapshot} in the register.");
    }

    public function show(string $id)
    {
        $this->guard();

        $lease = Lease::where('tenant_id', tenant()->id)
            ->with(['customer', 'assignments'])
            ->findOrFail($id);

        return view('tenant.rentals.leases.show', ['lease' => $lease]);
    }

    // ---- helpers ----

    private function createLeaseSale($tenant, Lease $lease): TenantSale
    {
        return DB::transaction(function () use ($tenant, $lease) {
            $sale = TenantSale::create([
                'id'                 => (string) Str::uuid(),
                'tenant_id'          => $tenant->id,
                'sale_number'        => $this->generateLeaseSaleNumber($tenant->id),
                'sale_date'          => now()->toDateString(),
                'status'             => 'pending',
                'payment_status'     => 'draft',
                'customer_id'        => $lease->customer_id,
                'lease_id'           => $lease->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'subtotal_cents'     => $lease->total_cents,
                'tax_cents'          => 0,
                'total_cents'        => $lease->total_cents,
                'notes'              => 'Season lease ' . $lease->lease_number,
            ]);

            TenantSaleItem::create([
                'id'               => (string) Str::uuid(),
                'tenant_id'        => $tenant->id,
                'sale_id'          => $sale->id,
                'type'             => 'open_item',
                'name_snapshot'    => $lease->package_name_snapshot . ' (season lease)',
                'quantity'         => 1,
                'unit_price_cents' => $lease->total_cents,
                'line_total_cents' => $lease->total_cents,
                'is_taxable'       => false,
                'position'         => 0,
                'notes'            => 'Auto-created lease collection line; payment cascades to the lease ledger cache.',
            ]);

            return $sale;
        });
    }

    private function generateLeaseNumber(string $tenantId): string
    {
        $prefix = 'LS-' . now()->format('Ymd') . '-';
        $max = DB::table('leases')->where('tenant_id', $tenantId)
            ->where('lease_number', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(lease_number, ?) AS UNSIGNED)) AS m', [strlen($prefix) + 1])
            ->value('m');
        return $prefix . str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);
    }

    private function generateLeaseSaleNumber(string $tenantId): string
    {
        $prefix = 'LS-' . now()->format('Ymd') . '-';
        $max = DB::table('tenant_sales')->where('tenant_id', $tenantId)
            ->where('sale_number', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(sale_number, ?) AS UNSIGNED)) AS m', [strlen($prefix) + 1])
            ->value('m');
        return $prefix . str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);
    }

    /** MM-DD strings -> next sensible [start,end] datetime pair. */
    private function defaultSeasonWindow(string $startMd, string $endMd): array
    {
        $now = now();
        try {
            $start = Carbon::createFromFormat('m-d', $startMd)->setTime(0, 0);
        } catch (\Throwable $e) {
            $start = $now->copy();
        }
        try {
            $end = Carbon::createFromFormat('m-d', $endMd)->setTime(23, 59);
        } catch (\Throwable $e) {
            $end = $now->copy()->addMonths(5);
        }
        // If the window has already ended this year, roll to next season.
        if ($end->lt($now)) {
            $start->addYear();
            $end->addYear();
        }
        if ($end->lt($start)) {
            $end->addYear();
        }
        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }
}
