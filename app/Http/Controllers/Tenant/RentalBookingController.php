<?php
// MARKER-PATCH-219

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalLine;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Models\Tenant\TenantRentalUnit;
use App\Services\RentalAvailabilityService;
use App\Support\MySQLLock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rental bookings: reserve -> check out -> check in (or cancel).
 *
 * Concurrency: every mutating action runs under ONE advisory lock per
 * tenant (intake:{t8}:rent:write) and re-checks availability INSIDE the
 * critical section — the same read-your-writes discipline as
 * BookingService. One key per tenant cannot deadlock and is plenty for a
 * single shop's desk volume.
 *
 * Money (Rail 2): recordPayment is the ONLY money writer here — ledger
 * rows in tenant_rental_payments (refunds negative), paid_cents refreshed
 * from the ledger after every write. Status never implies money.
 */
class RentalBookingController extends Controller
{
    public function __construct(
        protected RentalAvailabilityService $availability,
    ) {}

    // ------------------------------------------------------------------ list
    public function index(Request $request)
    {
        $tenant = tenant();
        $tab = in_array($request->query('tab'), ['out', 'upcoming', 'past'], true)
            ? $request->query('tab') : 'out';

        $base = TenantRental::where('tenant_id', $tenant->id)
            ->with(['customer:id,first_name,last_name', 'lines:id,rental_id,name_snapshot,kind']);

        $rentals = match ($tab) {
            'out'      => (clone $base)->where('status', 'out')->orderBy('due_at')->limit(100)->get(),
            'upcoming' => (clone $base)->where('status', 'reserved')->orderBy('starts_at')->limit(100)->get(),
            'past'     => (clone $base)->whereIn('status', ['returned', 'cancelled'])
                              ->orderByDesc('returned_at')->orderByDesc('updated_at')->limit(100)->get(),
        };

        $counts = [
            'out'      => TenantRental::where('tenant_id', $tenant->id)->where('status', 'out')->count(),
            'upcoming' => TenantRental::where('tenant_id', $tenant->id)->where('status', 'reserved')->count(),
            'past'     => TenantRental::where('tenant_id', $tenant->id)->whereIn('status', ['returned', 'cancelled'])->count(),
        ];

        return view('tenant.rentals.bookings.index', compact('rentals', 'tab', 'counts'));
    }

    public function create()
    {
        return view('tenant.rentals.bookings.create');
    }

    // -------------------------------------------------- availability (JSON)
    public function availability(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'starts_at' => ['required', 'string'],
            'due_at'    => ['required', 'string'],
        ]);

        [$start, $due] = $this->parseWindow($request->input('starts_at'), $request->input('due_at'));
        if ($due->lte($start)) {
            return response()->json(['success' => false, 'message' => 'Return time must be after the start time.'], 422);
        }

        $units = $this->availability
            ->availableUnits($tenant->id, null, $start, $due)
            ->map(fn (TenantRentalUnit $u) => [
                'id'                 => $u->id,
                'name'               => $u->name,
                'identifier'         => $u->identifier,
                'size'               => $u->size,
                'category'           => $u->category?->name,
                'hourly_rate_cents'  => $u->hourly_rate_cents,
                'daily_rate_cents'   => $u->daily_rate_cents,
                'weekend_rate_cents' => $u->weekend_rate_cents,
                'deposit_cents'      => $u->deposit_cents,
            ])->values();

        return response()->json(['success' => true, 'units' => $units]);
    }

    // ----------------------------------------------------------------- store
    public function store(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'customer_id'       => ['nullable', 'string', 'uuid'],
            'first_name'        => ['required_without:customer_id', 'nullable', 'string', 'max:120'],
            'last_name'         => ['nullable', 'string', 'max:120'],
            'email'             => ['required_without:customer_id', 'nullable', 'email', 'max:190'],
            'phone'             => ['nullable', 'string', 'max:40'],
            'starts_at'         => ['required', 'string'],
            'due_at'            => ['required', 'string'],
            'units'             => ['required', 'array', 'min:1', 'max:20'],
            'units.*.unit_id'   => ['required', 'string', 'uuid'],
            'units.*.rate_mode' => ['required', 'in:hourly,daily,weekend'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ]);

        [$start, $due] = $this->parseWindow($request->input('starts_at'), $request->input('due_at'));
        if ($due->lte($start)) {
            return back()->withInput()->withErrors(['due_at' => 'Return time must be after the start time.']);
        }

        $lock = app(MySQLLock::class);
        $lockKey = 'intake:' . substr($tenant->id, 0, 8) . ':rent:write';

        try {
            $rental = $lock->withLock($lockKey, function () use ($tenant, $request, $start, $due) {
                return DB::transaction(function () use ($tenant, $request, $start, $due) {
                    $customer = $this->resolveCustomer($tenant->id, $request);

                    // Re-check every unit INSIDE the lock — this is what
                    // makes the lock meaningful.
                    $lines = [];
                    $subtotal = 0;
                    $sort = 10;

                    foreach ($request->input('units') as $sel) {
                        $unit = TenantRentalUnit::where('tenant_id', $tenant->id)
                            ->where('id', $sel['unit_id'])
                            ->first();

                        if (!$unit || !$this->availability->isUnitAvailable($unit, $start, $due)) {
                            throw new RuntimeException(
                                ($unit?->name ?? 'A selected unit') . ' is no longer available for that window.'
                            );
                        }

                        [$mode, $rateCents, $durationUnits, $lineTotal] =
                            $this->priceUnit($unit, $sel['rate_mode'], $start, $due);

                        $lines[] = [
                            'unit'           => $unit,
                            'mode'           => $mode,
                            'rate_cents'     => $rateCents,
                            'duration_units' => $durationUnits,
                            'line_total'     => $lineTotal,
                            'sort'           => $sort,
                        ];
                        $sort += 10;
                        $subtotal += $lineTotal;
                    }

                    $taxRate = (float) ($tenant->default_tax_rate ?? 0);
                    $tax     = (int) round($subtotal * $taxRate / 100);

                    $rental = TenantRental::create([
                        'tenant_id'      => $tenant->id,
                        'location_id'    => $request->session()->get('current_location_id'),
                        'customer_id'    => $customer->id,
                        'rental_number'  => TenantRental::generateRentalNumber($tenant->id),
                        'status'         => 'reserved',
                        'source'         => 'desk',
                        'starts_at'      => $start,
                        'due_at'         => $due,
                        'subtotal_cents' => $subtotal,
                        'tax_cents'      => $tax,
                        'total_cents'    => $subtotal + $tax,
                        'paid_cents'     => 0,
                        'notes'          => $request->input('notes'),
                    ]);

                    foreach ($lines as $l) {
                        TenantRentalLine::create([
                            'rental_id'           => $rental->id,
                            'kind'                => 'unit',
                            'unit_id'             => $l['unit']->id,
                            'name_snapshot'       => $l['unit']->name
                                . ($l['unit']->identifier ? " ({$l['unit']->identifier})" : ''),
                            'rate_mode_snapshot'  => $l['mode'],
                            'rate_cents_snapshot' => $l['rate_cents'],
                            'quantity'            => 1,
                            'duration_units'      => $l['duration_units'],
                            'line_total_cents'    => $l['line_total'],
                            'sort_order'          => $l['sort'],
                        ]);
                    }

                    return $rental;
                });
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['units' => $e->getMessage()]);
        }

        return redirect()->route('tenant.rentals.bookings.show', $rental->id)
            ->with('flash', "Reservation {$rental->rental_number} created.");
    }

    // ------------------------------------------------------------------ show
    public function show(string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with([
                'customer',
                'lines',
                // MARKER-PATCH-219B — money = linked register sales.
                'sales' => fn ($q) => $q->orderBy('created_at')->with([
                    'payments' => fn ($p) => $p->orderBy('recorded_at'),
                ]),
            ])
            ->firstOrFail();

        return view('tenant.rentals.bookings.show', compact('rental'));
    }

    // --------------------------------------------------------- transitions
    public function checkOut(Request $request, string $id)
    {
        return $this->transition($id, 'reserved', function (TenantRental $rental) {
            $rental->update(['status' => 'out']);
            return 'Checked out.';
        });
    }

    public function checkIn(Request $request, string $id)
    {
        return $this->transition($id, 'out', function (TenantRental $rental) {
            $rental->update(['status' => 'returned', 'returned_at' => now()]);
            return 'Returned. Unit is available again.';
        });
    }

    public function cancel(Request $request, string $id)
    {
        return $this->transition($id, 'reserved', function (TenantRental $rental) {
            $rental->update(['status' => 'cancelled']);
            return 'Reservation cancelled.';
        });
    }

    /** Status transitions run under the same tenant write lock as store. */
    private function transition(string $id, string $requiredStatus, callable $apply)
    {
        $tenant = tenant();
        $lock = app(MySQLLock::class);
        $lockKey = 'intake:' . substr($tenant->id, 0, 8) . ':rent:write';

        $message = $lock->withLock($lockKey, function () use ($tenant, $id, $requiredStatus, $apply) {
            $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

            if ($rental->status !== $requiredStatus) {
                return null;
            }

            return $apply($rental);
        });

        if ($message === null) {
            return back()->withErrors(['status' => 'That action is not valid for this rental\'s current status.']);
        }

        return back()->with('flash', $message);
    }

    // ---------------------------------------------------- payments (Rail 2)
    /**
     * MARKER-PATCH-219B — the sales-as-money bridge. Mirrors the
     * appointment record_deposit flow byte-for-byte in spirit: creates a
     * one-line draft sale linked to this rental and sends staff to the
     * register to actually take the money (cash, card, Stripe link — every
     * register channel works). On payment, SalePaymentService::recalcStatus
     * cascades the rental's paid cache. Refunds happen through the
     * register's existing refund flows against the linked sale.
     */
    public function collectPayment(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999'],
        ]);

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        if ($rental->status === 'cancelled') {
            return back()->withErrors(['amount' => 'This rental is cancelled.']);
        }

        $amountCents = (int) round(((float) $request->input('amount')) * 100);
        $balanceDue  = max(0, (int) $rental->total_cents - (int) $rental->paid_cents);
        if ($balanceDue > 0 && $amountCents > $balanceDue) {
            return back()->withErrors([
                'amount' => "Amount can't exceed the remaining balance of " . format_money($balanceDue) . '.',
            ]);
        }

        $sale = DB::transaction(function () use ($tenant, $rental, $amountCents) {
            $sale = TenantSale::create([
                'id'                 => (string) Str::uuid(),
                'tenant_id'          => $tenant->id,
                'sale_number'        => $this->generateRentalSaleNumber($tenant->id),
                'sale_date'          => now()->toDateString(),
                'status'             => 'pending',
                'payment_status'     => 'draft',
                'customer_id'        => $rental->customer_id,
                'rental_id'          => $rental->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'subtotal_cents'     => $amountCents,
                'tax_cents'          => 0,
                'total_cents'        => $amountCents,
                'notes'              => 'Payment collection for rental ' . $rental->rental_number,
            ]);

            TenantSaleItem::create([
                'id'               => (string) Str::uuid(),
                'tenant_id'        => $tenant->id,
                'sale_id'          => $sale->id,
                'type'             => 'open_item',
                'name_snapshot'    => 'Rental ' . $rental->rental_number,
                'quantity'         => 1,
                'unit_price_cents' => $amountCents,
                'line_total_cents' => $amountCents,
                'is_taxable'       => false, // rental tax already lives on the rental totals
                'position'         => 0,
                'notes'            => 'Auto-created rental collection line; payment cascades to the rental ledger cache.',
            ]);

            return $sale;
        });

        return redirect(route('tenant.register.index') . '?resume=' . $sale->id)
            ->with('flash', "Sale {$sale->sale_number} created — take payment in the register.");
    }

    /** RD-YYYYMMDD-NNN — same shape as the appointment DP- generator. */
    private function generateRentalSaleNumber(string $tenantId): string
    {
        $prefix = 'RD-' . now()->format('Ymd') . '-';
        $maxNumber = DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderByDesc('sale_number')
            ->value('sale_number');

        $next = 1;
        if ($maxNumber) {
            $parts = explode('-', $maxNumber);
            $next = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ internals
    /** Naive tenant-local datetime-local strings -> UTC instants. */
    private function parseWindow(string $startsAt, string $dueAt): array
    {
        $tz = tenant()->timezone();
        return [
            Carbon::parse($startsAt, $tz)->utc(),
            Carbon::parse($dueAt, $tz)->utc(),
        ];
    }

    /**
     * Customer resolution mirrors the platform canon: verified customer_id
     * wins; otherwise find-by-email within the tenant; otherwise create.
     */
    private function resolveCustomer(string $tenantId, Request $request): TenantCustomer
    {
        $claimedId = $request->input('customer_id');
        if (!empty($claimedId)) {
            $existing = TenantCustomer::where('tenant_id', $tenantId)->where('id', $claimedId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $email = strtolower(trim((string) $request->input('email')));
        if ($email === '') {
            throw new RuntimeException('Pick a customer or enter details for a new one.');
        }

        $existing = TenantCustomer::where('tenant_id', $tenantId)->where('email', $email)->first();
        if ($existing) {
            if (empty($existing->phone) && $request->filled('phone')) {
                $existing->update(['phone' => $request->input('phone')]);
            }
            return $existing;
        }

        return TenantCustomer::create([
            'id'         => (string) Str::uuid(),
            'tenant_id'  => $tenantId,
            'first_name' => $request->input('first_name'),
            'last_name'  => $request->input('last_name'),
            'email'      => $email,
            'phone'      => $request->input('phone'),
        ]);
    }

    /**
     * Authoritative pricing. Duration units: hourly = ceil(minutes/60),
     * daily = ceil(hours/24), weekend = flat 1. A mode the unit doesn't
     * offer (null rate) is rejected.
     */
    private function priceUnit(TenantRentalUnit $unit, string $mode, Carbon $start, Carbon $due): array
    {
        $rateCents = match ($mode) {
            'hourly'  => $unit->hourly_rate_cents,
            'daily'   => $unit->daily_rate_cents,
            'weekend' => $unit->weekend_rate_cents,
        };

        if ($rateCents === null) {
            throw new RuntimeException("{$unit->name} has no {$mode} rate configured.");
        }

        $minutes = $start->diffInMinutes($due);

        $durationUnits = match ($mode) {
            'hourly'  => max(1, (int) ceil($minutes / 60)),
            'daily'   => max(1, (int) ceil($minutes / 1440)),
            'weekend' => 1,
        };

        return [$mode, (int) $rateCents, $durationUnits, (int) $rateCents * $durationUnits];
    }
}
