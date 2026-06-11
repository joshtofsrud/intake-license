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
            ->load('model') // MARKER-PATCH-227 — effective*() reads through model
            ->map(fn (TenantRentalUnit $u) => [
                'id'                 => $u->id,
                'name'               => $u->name,
                'identifier'         => $u->identifier,
                'size'               => $u->size,
                'category'           => $u->category?->name,
                // MARKER-PATCH-227 — read through the model (rates moved up).
                'hourly_rate_cents'   => $u->effectiveHourlyCents(),
                'daily_rate_cents'    => $u->effectiveDailyCents(),
                'weekend_rate_cents'  => $u->effectiveWeekendCents(),
                'seasonal_rate_cents' => $u->effectiveSeasonalCents(), // MARKER-PATCH-228
                'deposit_cents'       => $u->effectiveDepositCents(),
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
            'units.*.rate_mode' => ['required', 'in:hourly,daily,weekend,seasonal'], // MARKER-PATCH-228
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
                            ->with('model') // MARKER-PATCH-227
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
            // MARKER-PATCH-220 — clean return auto-releases a live hold.
            // Stripe failure does NOT block the return: holds self-expire,
            // and the panel keeps a Release button while status=authorized.
            $message = 'Returned. Unit is available again.';
            if ($rental->deposit_status === 'authorized' && $rental->stripe_deposit_intent_id) {
                try {
                    (new \App\Services\Tenant\DirectPaymentsService(tenant()))
                        ->cancelDepositHold($rental->stripe_deposit_intent_id);
                    $rental->deposit_status = 'released';
                    $message = 'Returned. Deposit hold released.';
                } catch (\Throwable $e) {
                    \Log::error('rental_deposit.release_on_checkin_failed', [
                        'rental_id' => $rental->id, 'error' => $e->getMessage(),
                    ]);
                    $message = 'Returned. Deposit hold could NOT be released — use the Release button or the Stripe dashboard.';
                }
            }
            $rental->status = 'returned';
            $rental->returned_at = now();
            $rental->save();
            return $message;
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

    // ------------------------------------------------- deposits (PATCH-220)
    /**
     * Create a manual-capture PaymentIntent for the deposit hold.
     * AN AUTHORIZATION IS NOT MONEY — nothing is written to the ledger
     * here or at confirm/release. Only capture (damage) creates money.
     */
    public function createDepositIntent(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with('lines.unit')
            ->firstOrFail();

        if (!in_array($rental->status, ['reserved', 'out'], true)) {
            return response()->json(['ok' => false, 'error' => 'Deposits only apply to reserved or out rentals.'], 422);
        }
        if ($rental->deposit_status === 'authorized') {
            return response()->json(['ok' => false, 'error' => 'A hold is already authorized on this rental.'], 422);
        }

        $request->validate(['amount_cents' => ['nullable', 'integer', 'min:50', 'max:9999900']]);
        $amountCents = (int) ($request->input('amount_cents') ?: $this->defaultDepositCents($rental));
        if ($amountCents < 50) {
            return response()->json(['ok' => false, 'error' => 'No deposit amount configured for these units.'], 422);
        }

        $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
        if (!$direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments are not enabled for this tenant.'], 422);
        }

        try {
            $pi = $direct->createDepositHold($amountCents, [
                'intake_rental_id'     => $rental->id,
                'intake_rental_number' => $rental->rental_number,
            ]);
        } catch (\Throwable $e) {
            \Log::error('rental_deposit.create_hold_failed', ['rental_id' => $rental->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not start the deposit hold.'], 500);
        }

        return response()->json([
            'ok'              => true,
            'client_secret'   => $pi->client_secret,
            'payment_intent'  => $pi->id,
            'publishable_key' => $direct->publishableKey(),
            'amount_cents'    => $amountCents,
        ]);
    }

    /**
     * Verify the confirmed intent with Stripe (never trust the client) and
     * stamp the rental. requires_capture = a live authorization.
     */
    public function confirmDepositIntent(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate(['payment_intent' => ['required', 'string', 'max:64']]);

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);

        try {
            $pi = $direct->retrievePaymentIntent($request->input('payment_intent'));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not verify the hold with Stripe.'], 500);
        }

        if (($pi->metadata['intake_rental_id'] ?? null) !== $rental->id) {
            return response()->json(['ok' => false, 'error' => 'That payment does not belong to this rental.'], 422);
        }
        if ($pi->status !== 'requires_capture') {
            return response()->json(['ok' => false, 'error' => "Hold is not authorized yet (status: {$pi->status})."], 422);
        }

        $rental->update([
            'deposit_status'           => 'authorized',
            'deposit_hold_cents'       => (int) $pi->amount,
            'stripe_deposit_intent_id' => $pi->id,
        ]);

        return response()->json(['ok' => true, 'amount_cents' => (int) $pi->amount]);
    }

    /** Clean return path: cancel the hold. NO ledger row — an auth is not money. */
    public function releaseDeposit(Request $request, string $id)
    {
        $tenant = tenant();
        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        if ($rental->deposit_status !== 'authorized' || !$rental->stripe_deposit_intent_id) {
            return back()->withErrors(['deposit' => 'No authorized hold to release.']);
        }

        try {
            (new \App\Services\Tenant\DirectPaymentsService($tenant))
                ->cancelDepositHold($rental->stripe_deposit_intent_id);
        } catch (\Throwable $e) {
            \Log::error('rental_deposit.release_failed', ['rental_id' => $rental->id, 'error' => $e->getMessage()]);
            return back()->withErrors(['deposit' => 'Stripe could not release the hold — try again or release it from the Stripe dashboard.']);
        }

        $rental->update(['deposit_status' => 'released']);

        return back()->with('flash', 'Deposit hold released.');
    }

    /**
     * Damage path: capture part or all of the hold. Captured money flows
     * through the sales-as-money model — a damage line is added to the
     * rental (totals stay truthful) and a completed RD- sale carries the
     * payment row; recalcStatus cascades the rental paid cache.
     */
    public function captureDeposit(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.50'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        if ($rental->deposit_status !== 'authorized' || !$rental->stripe_deposit_intent_id) {
            return back()->withErrors(['deposit' => 'No authorized hold to capture.']);
        }

        $amountCents = (int) round(((float) $request->input('amount')) * 100);
        if ($amountCents > (int) $rental->deposit_hold_cents) {
            return back()->withErrors(['deposit' => 'Capture exceeds the held amount of ' . format_money($rental->deposit_hold_cents) . '.']);
        }

        $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);

        try {
            $pi = $direct->captureDepositHold($rental->stripe_deposit_intent_id, $amountCents);
        } catch (\Throwable $e) {
            \Log::error('rental_deposit.capture_failed', ['rental_id' => $rental->id, 'error' => $e->getMessage()]);
            return back()->withErrors(['deposit' => 'Stripe could not capture the hold.']);
        }

        $captured = (int) ($pi->amount_received ?? $amountCents);
        $reason   = trim((string) $request->input('reason')) ?: 'Damage — deposit capture';

        DB::transaction(function () use ($tenant, $rental, $captured, $reason, $pi) {
            // Damage line on the rental: totals stay truthful (paid == total
            // nets out once the sale payment lands).
            TenantRentalLine::create([
                'rental_id'           => $rental->id,
                'kind'                => 'addon',
                'name_snapshot'       => $reason,
                'rate_mode_snapshot'  => 'flat',
                'rate_cents_snapshot' => $captured,
                'quantity'            => 1,
                'duration_units'      => 1,
                'line_total_cents'    => $captured,
                'sort_order'          => 900,
            ]);
            $rental->update([
                'subtotal_cents' => (int) $rental->subtotal_cents + $captured,
                'total_cents'    => (int) $rental->total_cents + $captured,
                'deposit_status' => $captured >= (int) $rental->deposit_hold_cents
                    ? 'captured' : 'partially_captured',
            ]);

            // Sales-as-money: completed sale + ledger row carry the capture.
            $sale = TenantSale::create([
                'id'                 => (string) Str::uuid(),
                'tenant_id'          => $tenant->id,
                'sale_number'        => $this->generateRentalSaleNumber($tenant->id),
                'sale_date'          => now()->toDateString(),
                'status'             => 'completed',
                'payment_status'     => 'unpaid', // record() flips it via recalcStatus
                'customer_id'        => $rental->customer_id,
                'rental_id'          => $rental->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'subtotal_cents'     => $captured,
                'tax_cents'          => 0,
                'total_cents'        => $captured,
                'notes'              => 'Deposit capture for rental ' . $rental->rental_number,
            ]);

            TenantSaleItem::create([
                'id'               => (string) Str::uuid(),
                'tenant_id'        => $tenant->id,
                'sale_id'          => $sale->id,
                'type'             => 'open_item',
                'name_snapshot'    => $reason . ' (' . $rental->rental_number . ')',
                'quantity'         => 1,
                'unit_price_cents' => $captured,
                'line_total_cents' => $captured,
                'is_taxable'       => false,
                'position'         => 0,
            ]);

            app(\App\Services\Tenant\SalePaymentService::class)->record(
                sale:              $sale,
                amountCents:       $captured,
                kind:              \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT,
                source:            \App\Models\Tenant\TenantSalePayment::SOURCE_DIRECT_PAYMENT_LINK,
                method:            'card',
                externalReference: $pi->id,
                notes:             'Captured from deposit hold',
            );
        });

        // MARKER-PATCH-225 — critical staff alert (bypasses the addon gate).
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'rental.damage_flagged', [
            'title' => 'Deposit captured — ' . $rental->rental_number,
            'body'  => format_money($captured) . ' captured: ' . $reason,
            'link'  => route('tenant.rentals.bookings.show', $rental->id),
            'meta'  => ['rental_id' => $rental->id, 'amount_cents' => $captured],
        ]);

        return back()->with('flash', 'Captured ' . format_money($captured) . ' from the deposit hold.');
    }

    /** Default hold = sum of deposit_cents across the rental's units. */
    private function defaultDepositCents(TenantRental $rental): int
    {
        // MARKER-PATCH-227 — deposit lives on the model now.
        return (int) $rental->lines
            ->where('kind', 'unit')
            ->sum(fn ($line) => (int) ($line->unit?->effectiveDepositCents() ?? 0));
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
        // MARKER-PATCH-227 — model-backed rates. PATCH-228 adds seasonal
        // (flat for the whole window, like weekend).
        $rateCents = match ($mode) {
            'hourly'   => $unit->effectiveHourlyCents(),
            'daily'    => $unit->effectiveDailyCents(),
            'weekend'  => $unit->effectiveWeekendCents(),
            'seasonal' => $unit->effectiveSeasonalCents(),
        };

        if ($rateCents === null) {
            throw new RuntimeException("{$unit->name} has no {$mode} rate configured.");
        }

        $minutes = $start->diffInMinutes($due);

        $durationUnits = match ($mode) {
            'hourly'   => max(1, (int) ceil($minutes / 60)),
            'daily'    => max(1, (int) ceil($minutes / 1440)),
            'weekend'  => 1,
            'seasonal' => 1,
        };

        return [$mode, (int) $rateCents, $durationUnits, (int) $rateCents * $durationUnits];
    }
}
