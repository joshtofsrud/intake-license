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
use App\Models\Tenant\TenantRentalAgreementTemplate;
use App\Models\Tenant\TenantRentalConditionCheck;
use App\Models\Tenant\TenantRegister; // MARKER-RENTAL-WAIVER-DISPLAY-BE
use App\Services\Tenant\RentalAgreementService; // MARKER-RENTAL-WAIVER-DISPLAY-BE
use Illuminate\Support\Facades\Storage;
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
        // MARKER-PATCH-234 — triage-first list. "Needs attention" = overdue,
        // or balance due on a reservation starting today. Search spans
        // rental #, customer name, and unit/line names; filters layer on
        // every tab.
        $tenant = tenant();
        $tab = in_array($request->query('tab'), ['attention', 'out', 'upcoming', 'done', 'all'], true)
            ? $request->query('tab') : 'attention';
        $q        = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');
        $when     = in_array($request->query('when'), ['today', 'week'], true) ? $request->query('when') : '';

        $todayStartUtc = tnow()->startOfDay()->clone()->utc();
        $todayEndUtc   = tnow()->endOfDay()->clone()->utc();

        $base = TenantRental::where('tenant_id', $tenant->id)
            ->with(['customer:id,first_name,last_name', 'lines:id,rental_id,name_snapshot,kind']);

        if ($q !== '') {
            $base->where(function ($w) use ($q) {
                $w->where('rental_number', 'like', '%' . $q . '%')
                    ->orWhereHas('customer', function ($c) use ($q) {
                        $c->where('first_name', 'like', '%' . $q . '%')
                          ->orWhere('last_name', 'like', '%' . $q . '%');
                    })
                    ->orWhereHas('lines', fn ($l) => $l->where('name_snapshot', 'like', '%' . $q . '%'));
            });
        }
        if ($category !== '') {
            $base->whereHas('lines.unit', fn ($u) => $u->where('category_id', $category));
        }
        if ($when === 'today') {
            $base->where(function ($w) use ($todayStartUtc, $todayEndUtc) {
                $w->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                  ->orWhereBetween('due_at', [$todayStartUtc, $todayEndUtc]);
            });
        } elseif ($when === 'week') {
            $weekEndUtc = tnow()->addDays(7)->endOfDay()->clone()->utc();
            $base->where(function ($w) use ($todayStartUtc, $weekEndUtc) {
                $w->whereBetween('starts_at', [$todayStartUtc, $weekEndUtc])
                  ->orWhereBetween('due_at', [$todayStartUtc, $weekEndUtc]);
            });
        }

        $attention = function ($query) use ($todayStartUtc, $todayEndUtc) {
            return $query->where(function ($w) use ($todayStartUtc, $todayEndUtc) {
                $w->where(fn ($o) => $o->where('status', 'out')->where('due_at', '<', now()))
                  ->orWhere(fn ($r) => $r->where('status', 'reserved')
                      ->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                      ->whereColumn('total_cents', '>', 'paid_cents'));
            });
        };

        $rentals = match ($tab) {
            'attention' => $attention(clone $base)->orderBy('due_at')->orderBy('starts_at')->limit(200)->get(),
            'out'       => (clone $base)->where('status', 'out')->orderBy('due_at')->limit(200)->get(),
            'upcoming'  => (clone $base)->where('status', 'reserved')->orderBy('starts_at')->limit(200)->get(),
            'done'      => (clone $base)->whereIn('status', ['returned', 'cancelled'])
                               ->orderByDesc('returned_at')->orderByDesc('updated_at')->limit(200)->get(),
            'all'       => (clone $base)->orderByDesc('created_at')->limit(200)->get(),
        };

        $countBase = TenantRental::where('tenant_id', $tenant->id);
        $counts = [
            'attention' => $attention(clone $countBase)->count(),
            'out'       => (clone $countBase)->where('status', 'out')->count(),
            'upcoming'  => (clone $countBase)->where('status', 'reserved')->count(),
            'done'      => (clone $countBase)->whereIn('status', ['returned', 'cancelled'])->count(),
        ];

        $categories = \App\Models\Tenant\TenantRentalCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get(['id', 'name']);

        return view('tenant.rentals.bookings.index', compact('rentals', 'tab', 'counts', 'q', 'category', 'when', 'categories'));
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
                'conditionChecks' => fn ($q) => $q->with('unit')->orderBy('performed_at'), // MARKER-PATCH-234
            ])
            ->firstOrFail();

        // MARKER-PATCH-234 — derived activity feed. No events table: every
        // line is rebuilt from timestamps, checks, and ledger payments, so
        // it can never drift from the record.
        $feed = collect();
        $feed->push(['at' => $rental->created_at, 'dot' => 'dim',
            'text' => 'Reserved — ' . $rental->lines->where('kind', 'unit')->count() . ' unit(s)']);
        if ($rental->agreement_signed_at) {
            $feed->push(['at' => $rental->agreement_signed_at, 'dot' => 'lime',
                'text' => 'Agreement v' . $rental->agreement_template_version . ' signed at the desk']);
        }
        foreach ($rental->conditionChecks as $check) {
            $unitLabel = $check->unit?->identifier ?: 'unit';
            $feed->push([
                'at'   => $check->performed_at,
                'dot'  => $check->flagged ? 'red' : 'blue',
                'text' => ($check->phase === 'check_out' ? 'Out-check — ' : 'In-check — ') . $unitLabel
                    . ($check->flagged ? ' (flagged)' : '')
                    . ($check->notes ? ' · "' . \Illuminate\Support\Str::limit($check->notes, 80) . '"' : ''),
            ]);
        }
        if ($rental->checked_out_at) {
            $feed->push(['at' => $rental->checked_out_at, 'dot' => 'blue', 'text' => 'Checked out']);
        }
        foreach ($rental->sales as $sale) {
            foreach ($sale->payments as $p) {
                $feed->push([
                    'at'   => $p->recorded_at,
                    'dot'  => $p->amount_cents < 0 ? 'red' : 'lime',
                    'text' => ($p->amount_cents < 0 ? 'Refund ' : 'Payment ') . format_money(abs($p->amount_cents))
                        . ' — ' . $sale->sale_number . ($p->method ? ' · ' . $p->method : ''),
                ]);
            }
        }
        if ($rental->returned_at) {
            $feed->push(['at' => $rental->returned_at, 'dot' => 'lime', 'text' => 'Returned']);
        }
        if ($rental->cancelled_at) {
            $feed->push(['at' => $rental->cancelled_at, 'dot' => 'red', 'text' => 'Cancelled']);
        }
        $feed = $feed->filter(fn ($e) => $e['at'])->sortByDesc('at')->values();

        return view('tenant.rentals.bookings.show', compact('rental', 'feed'));
    }

    // ------------------------------------------ check-out flow (PATCH-232)
    /**
     * MARKER-PATCH-232 — the guided counter flow for reserved → out.
     * Verify → Agreement → Condition → Deposit & go. Each write step is its
     * own POST so the flow is resumable: reload the page and done steps
     * stay done. The quick one-click checkOut() above remains untouched as
     * the skip-the-ceremony path.
     */
    public function checkOutFlow(string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with([
                'customer',
                'lines' => fn ($q) => $q->orderBy('sort_order'),
                'lines.unit.conditionTemplate',
                'conditionChecks' => fn ($q) => $q->where('phase', 'check_out'),
            ])
            ->firstOrFail();

        if ($rental->status !== 'reserved') {
            return redirect()->route('tenant.rentals.bookings.show', $rental->id)
                ->withErrors(['status' => 'Only reserved rentals can be checked out.']);
        }

        $agreementTemplate = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->orderByDesc('version')->first();

        // Units on the rental, each paired with its out-check (if done).
        $unitLines = $rental->lines->where('kind', 'unit')->values();
        $checksByUnit = $rental->conditionChecks->keyBy('unit_id');

        $balanceCents = max(0, (int) $rental->total_cents - (int) $rental->paid_cents);

        return view('tenant.rentals.bookings.check-out', [
            'rental'            => $rental,
            'agreementTemplate' => $agreementTemplate,
            'unitLines'         => $unitLines,
            'checksByUnit'      => $checksByUnit,
            'balanceCents'      => $balanceCents,
            'agreementSigned'   => (bool) $rental->agreement_signed_at,
        ]);
    }

    /**
     * Counter signature: customer signs on the staff screen by typed name +
     * confirm. Snapshots the template version AND a rendered PDF — editing
     * the template later never changes what was signed (PATCH-217 intent).
     */
    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — push the waiver to the screen paired
     * with the staff member's current register.
     *
     * Every refusal returns a code the check-out page can act on, so the
     * staff member is never left with a button that silently does nothing.
     */
    public function sendAgreementToDisplay(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with('customer')->firstOrFail();

        if ($rental->agreement_signed_at) {
            return response()->json(['ok' => false, 'code' => 'already_signed'], 200);
        }
        if ($rental->status !== 'reserved') {
            return response()->json(['ok' => false, 'code' => 'not_reserved'], 200);
        }

        $template = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->orderByDesc('version')->first();
        if (! $template) {
            return response()->json(['ok' => false, 'code' => 'no_template'], 200);
        }

        $registerId = (int) $request->session()->get('current_register_id', 0);
        $register = $registerId
            ? TenantRegister::where('tenant_id', $tenant->id)->where('is_active', true)->find($registerId)
            : null;

        if (! $register) {
            // Hand back the pickable registers so the page can offer the fix
            // inline instead of dead-ending on "no register selected".
            $request->session()->forget('current_register_id');
            return response()->json([
                'ok'        => false,
                'code'      => 'no_register',
                'registers' => TenantRegister::where('tenant_id', $tenant->id)
                    ->where('is_active', true)->orderBy('number')
                    ->get(['id', 'number', 'name'])
                    ->map(fn ($r) => ['id' => $r->id, 'label' => 'Register ' . $r->number . ' · ' . $r->name])
                    ->all(),
            ], 200);
        }

        $nonce = Str::random(40);
        $register->update([
            'display_mode'       => 'agreement',
            'display_rental_id'  => $rental->id,
            'display_mode_at'    => now(),
            'display_sign_nonce' => $nonce,
        ]);

        return response()->json([
            'ok'       => true,
            'register' => ['number' => $register->number, 'name' => $register->name],
        ]);
    }

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — take the waiver back off the screen.
     *
     * Clears every register pointing at this rental, not just the session's
     * one: staff can switch registers mid-flow, and a waiver left live on an
     * abandoned screen is exactly the stranded state this must not allow.
     */
    public function recallAgreementFromDisplay(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        TenantRegister::where('tenant_id', $tenant->id)
            ->where('display_rental_id', $rental->id)
            ->get()
            ->each(fn (TenantRegister $r) => $r->clearDisplayMode());

        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — status poll for the check-out page.
     *
     * Drives the live flip from "waiting" to "signed" without a reload, and
     * tells staff when a push has aged out so the screen state and the page
     * state can never disagree.
     */
    public function agreementStatus(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $register = TenantRegister::where('tenant_id', $tenant->id)
            ->where('display_rental_id', $rental->id)->first();

        $displayState = 'none';
        if ($register) {
            $displayState = $register->agreementIsLive() ? 'waiting' : 'expired';
        }

        return response()->json([
            'ok'           => true,
            'signed'       => (bool) $rental->agreement_signed_at,
            'signer_name'  => $rental->agreement_signer_name,
            'method'       => $rental->agreement_method,
            'version'      => $rental->agreement_template_version,
            'signed_at'    => $rental->agreement_signed_at
                ? tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') : null,
            'pdf_url'      => $rental->agreement_pdf_path
                ? Storage::disk('public')->url($rental->agreement_pdf_path) : null,
            'signature_url' => $rental->agreement_signature_path
                ? Storage::disk('public')->url($rental->agreement_signature_path) : null,
            'display'      => $displayState,
            'register'     => $register ? ('Register ' . $register->number . ' · ' . $register->name) : null,
        ]);
    }

    public function signAgreement(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate([
            'signer_name' => ['required', 'string', 'max:160'],
            'agreed'      => ['required', 'accepted'],
        ]);

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with('customer')->firstOrFail();

        if ($rental->status !== 'reserved') {
            return back()->withErrors(['agreement' => 'Only reserved rentals can sign.']);
        }
        if ($rental->agreement_signed_at) {
            return back()->with('flash', 'Agreement was already signed.');
        }

        $template = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->orderByDesc('version')->first();
        if (!$template) {
            return back()->withErrors(['agreement' => 'No agreement template is configured.']);
        }

        $pdfPath = null;
        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tenant.rentals.agreement-pdf', [
                'tenant'     => $tenant,
                'rental'     => $rental,
                'template'   => $template,
                'signerName' => $request->input('signer_name'),
                'signedAt'   => now(),
            ])->setPaper('letter');
            $pdfPath = 'tenants/' . $tenant->id . '/rental-agreements/'
                . $rental->rental_number . '-v' . $template->version . '.pdf';
            Storage::disk('public')->put($pdfPath, $pdf->output());
        } catch (\Throwable $e) {
            // PDF is a nicety; the signature stamp is the record. Never let
            // a renderer hiccup block the counter.
            \Log::error('rental_agreement.pdf_failed', ['rental_id' => $rental->id, 'error' => $e->getMessage()]);
            $pdfPath = null;
        }

        $rental->update([
            'agreement_template_version' => $template->version,
            'agreement_signed_at'        => now(),
            'agreement_method'           => 'desk',
            'agreement_signer_name'      => $request->input('signer_name'), // MARKER-RENTAL-WAIVER-DISPLAY-BE
            'agreement_pdf_path'         => $pdfPath,
            'notes'                      => trim(($rental->notes ? $rental->notes . "\n" : '')
                . 'Agreement v' . $template->version . ' signed at desk by ' . $request->input('signer_name') . '.'),
        ]);

        return back()->with('flash', 'Agreement signed.');
    }

    /**
     * One condition check per unit per phase. results = {item_key: ok|flag},
     * flagged rolls up any flag. Photos (≤4, images only) land on the public
     * disk under the tenant directory — the check-in flow (PATCH-233)
     * replays them side-by-side.
     */
    public function storeConditionCheck(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate([
            'unit_id'   => ['required', 'uuid'],
            'phase'     => ['required', 'in:check_out,check_in'],
            'results'   => ['nullable', 'array'],
            'results.*' => ['in:ok,flag'],
            'notes'     => ['nullable', 'string', 'max:2000'],
            'photos'    => ['nullable', 'array', 'max:4'],
            'photos.*'  => ['image', 'max:5120'],
        ]);

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with('lines')->firstOrFail();

        $phase = $request->input('phase');
        if ($phase === 'check_out' && $rental->status !== 'reserved') {
            return back()->withErrors(['condition' => 'Out-checks happen before check-out.']);
        }
        if ($phase === 'check_in' && $rental->status !== 'out') {
            return back()->withErrors(['condition' => 'In-checks happen while the rental is out.']);
        }

        $unitId = $request->input('unit_id');
        if (!$rental->lines->where('kind', 'unit')->pluck('unit_id')->contains($unitId)) {
            return back()->withErrors(['condition' => 'That unit is not on this rental.']);
        }

        $existing = TenantRentalConditionCheck::where('rental_id', $rental->id)
            ->where('unit_id', $unitId)->where('phase', $phase)->first();
        if ($existing) {
            return back()->with('flash', 'Condition check already recorded for that unit.');
        }

        $photoPaths = [];
        foreach ((array) $request->file('photos', []) as $photo) {
            try {
                $photoPaths[] = Storage::disk('public')->putFile(
                    'tenants/' . $tenant->id . '/rental-checks', $photo
                );
            } catch (\Throwable $e) {
                \Log::error('rental_check.photo_failed', ['rental_id' => $rental->id, 'error' => $e->getMessage()]);
            }
        }

        $results = (array) $request->input('results', []);

        TenantRentalConditionCheck::create([
            'rental_id'            => $rental->id,
            'unit_id'              => $unitId,
            'phase'                => $phase,
            'results'              => $results,
            'flagged'              => in_array('flag', $results, true),
            'notes'                => $request->input('notes'),
            'photos'               => $photoPaths ?: null,
            'performed_by_user_id' => auth('tenant')->id(),
            'performed_at'         => now(),
        ]);

        return back()->with('flash', 'Condition check saved.');
    }

    /**
     * The flow's finalizer. Same locked reserved→out flip as checkOut(),
     * plus one server-side gate: when an agreement template exists, an
     * unsigned rental cannot go out through the flow. (The quick path stays
     * gate-free on purpose — it IS the escape hatch.)
     */
    public function completeCheckOut(Request $request, string $id)
    {
        $tenant = tenant();

        $hasTemplate = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)->exists();
        if ($hasTemplate) {
            $unsigned = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
                ->whereNull('agreement_signed_at')->exists();
            if ($unsigned) {
                return back()->withErrors(['agreement' => 'Sign the agreement before completing check-out.']);
            }
        }

        return $this->transition($id, 'reserved', function (TenantRental $rental) {
            $rental->update(['status' => 'out', 'checked_out_at' => now()]); // MARKER-PATCH-234
            return 'Checked out — ' . $rental->rental_number . ' is on its way.';
        });
    }

    // -------------------------------------------- return flow (PATCH-233)
    /**
     * MARKER-PATCH-233 — guided out → returned. Inspect (in-checks beside
     * the 232 out-checks) → Charges (policy-suggested late fee + damage,
     * collected through the register) → Close (deposit decision + per-unit
     * routing + the locked status flip).
     */
    public function returnFlow(string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with([
                'customer',
                'lines' => fn ($q) => $q->orderBy('sort_order'),
                'lines.unit.conditionTemplate',
                'conditionChecks',
            ])
            ->firstOrFail();

        if ($rental->status !== 'out') {
            return redirect()->route('tenant.rentals.bookings.show', $rental->id)
                ->withErrors(['status' => 'Only out rentals can start a return.']);
        }

        $unitLines    = $rental->lines->where('kind', 'unit')->values();
        $outChecks    = $rental->conditionChecks->where('phase', 'check_out')->keyBy('unit_id');
        $inChecks     = $rental->conditionChecks->where('phase', 'check_in')->keyBy('unit_id');
        $balanceCents = max(0, (int) $rental->total_cents - (int) $rental->paid_cents);

        [$lateMinutes, $suggestedLateFeeCents, $policy] = $this->lateFeeSuggestion($rental);

        return view('tenant.rentals.bookings.return', [
            'rental'        => $rental,
            'unitLines'     => $unitLines,
            'outChecks'     => $outChecks,
            'inChecks'      => $inChecks,
            'balanceCents'  => $balanceCents,
            'lateMinutes'   => $lateMinutes,
            'suggestedLateFeeCents' => $suggestedLateFeeCents,
            'latePolicy'    => $policy,
        ]);
    }

    /**
     * Policy lives in tenant settings (editable on the Rental Settings
     * page, this patch). Grace forgives entirely; past grace, full hours
     * from the due time are billed; cap-at-day-rate uses the largest
     * effective daily rate among the rental's units.
     */
    private function lateFeeSuggestion(TenantRental $rental): array
    {
        $s = tenant()->settings ?? [];
        $policy = [
            'grace_minutes' => (int) ($s['rental_late_grace_minutes'] ?? 30),
            'per_hour_cents' => (int) ($s['rental_late_fee_cents_per_hour'] ?? 0),
            'cap'           => (string) ($s['rental_late_fee_cap'] ?? 'day_rate'), // day_rate | none
        ];

        $lateMinutes = $rental->due_at && now()->greaterThan($rental->due_at)
            ? (int) $rental->due_at->diffInMinutes(now())
            : 0;

        $suggested = 0;
        if ($lateMinutes > $policy['grace_minutes'] && $policy['per_hour_cents'] > 0) {
            $suggested = (int) ceil($lateMinutes / 60) * $policy['per_hour_cents'];
            if ($policy['cap'] === 'day_rate') {
                $dayCap = (int) $rental->lines->where('kind', 'unit')
                    ->max(fn ($l) => (int) ($l->unit?->effectiveDailyCents() ?? 0));
                if ($dayCap > 0) {
                    $suggested = min($suggested, $dayCap);
                }
            }
        }

        return [$lateMinutes, $suggested, $policy];
    }

    /**
     * Counter-collection path: late fee + damage become rental lines (the
     * totals stay truthful), then one linked draft sale opens in the
     * register with a return_to back to this flow (PATCH-232B). The OTHER
     * path — taking charges from the deposit — is captureDeposit (PATCH-220),
     * which writes its own line + sale. One or the other, never both.
     */
    public function addReturnCharges(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate([
            'late_fee'         => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'damage_labels'    => ['nullable', 'array', 'max:10'],
            'damage_labels.*'  => ['nullable', 'string', 'max:200'],
            'damage_amounts'   => ['nullable', 'array', 'max:10'],
            'damage_amounts.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        if ($rental->status !== 'out') {
            return back()->withErrors(['charges' => 'Charges are added during an active return.']);
        }

        $charges = [];
        $lateFeeCents = (int) round(((float) $request->input('late_fee', 0)) * 100);
        if ($lateFeeCents > 0) {
            $charges[] = ['label' => 'Late return fee', 'cents' => $lateFeeCents];
        }
        $labels  = (array) $request->input('damage_labels', []);
        $amounts = (array) $request->input('damage_amounts', []);
        foreach ($labels as $i => $label) {
            $cents = (int) round(((float) ($amounts[$i] ?? 0)) * 100);
            $label = trim((string) $label);
            if ($label !== '' && $cents > 0) {
                $charges[] = ['label' => $label, 'cents' => $cents];
            }
        }

        if (!count($charges)) {
            return back()->withErrors(['charges' => 'Nothing to charge — enter an amount or skip this step.']);
        }

        $chargeTotal = array_sum(array_column($charges, 'cents'));

        $sale = DB::transaction(function () use ($tenant, $rental, $charges, $chargeTotal) {
            $sort = 900;
            foreach ($charges as $c) {
                TenantRentalLine::create([
                    'rental_id'           => $rental->id,
                    'kind'                => 'addon',
                    'name_snapshot'       => $c['label'],
                    'rate_mode_snapshot'  => 'flat',
                    'rate_cents_snapshot' => $c['cents'],
                    'quantity'            => 1,
                    'duration_units'      => 1,
                    'line_total_cents'    => $c['cents'],
                    'sort_order'          => $sort++,
                ]);
            }
            $rental->update([
                'subtotal_cents' => (int) $rental->subtotal_cents + $chargeTotal,
                'total_cents'    => (int) $rental->total_cents + $chargeTotal,
            ]);

            $sale = TenantSale::create([
                'id'                 => (string) Str::uuid(),
                'tenant_id'          => $tenant->id,
                'sale_number'        => $this->generateRentalSaleNumber($tenant->id),
                'sale_date'          => tnow()->toDateString(), // MARKER-TZ-WAVE1 — tenant-local business date
                'status'             => 'pending',
                'payment_status'     => 'draft',
                'customer_id'        => $rental->customer_id,
                'rental_id'          => $rental->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'subtotal_cents'     => $chargeTotal,
                'tax_cents'          => 0,
                'total_cents'        => $chargeTotal,
                'notes'              => 'Return charges for rental ' . $rental->rental_number,
            ]);
            $pos = 0;
            foreach ($charges as $c) {
                TenantSaleItem::create([
                    'id'               => (string) Str::uuid(),
                    'tenant_id'        => $tenant->id,
                    'sale_id'          => $sale->id,
                    'type'             => 'open_item',
                    'name_snapshot'    => $c['label'] . ' — ' . $rental->rental_number,
                    'quantity'         => 1,
                    'unit_price_cents' => $c['cents'],
                    'line_total_cents' => $c['cents'],
                    'is_taxable'       => false,
                    'position'         => $pos++,
                    'notes'            => 'Return-flow charge; payment cascades to the rental ledger cache.',
                ]);
            }

            return $sale;
        });

        $returnTo = '/admin/rentals/bookings/' . $rental->id . '/return-flow';

        return redirect(route('tenant.register.index') . '?resume=' . $sale->id . '&return_to=' . urlencode($returnTo))
            ->with('flash', "Sale {$sale->sale_number} created — take payment in the register.");
    }

    /**
     * The flow's finalizer: deposit decision + per-unit routing + the same
     * locked out→returned flip. Unlike quick check-in, NOTHING is automatic
     * here — release only happens when staff chose it.
     */
    public function completeReturn(Request $request, string $id)
    {
        $tenant = tenant();

        $request->validate([
            'deposit_action'  => ['nullable', 'in:release,hold'],
            'routing'         => ['nullable', 'array'],
            'routing.*'       => ['in:available,maintenance'],
            'routing_note'    => ['nullable', 'array'],
            'routing_note.*'  => ['nullable', 'string', 'max:500'],
        ]);

        $routing      = (array) $request->input('routing', []);
        $routingNotes = (array) $request->input('routing_note', []);
        $depositAction = $request->input('deposit_action');

        return $this->transition($id, 'out', function (TenantRental $rental) use ($tenant, $routing, $routingNotes, $depositAction) {
            $message = 'Returned.';

            // Deposit: explicit decision only.
            if ($rental->deposit_status === 'authorized' && $rental->stripe_deposit_intent_id) {
                if ($depositAction === 'release') {
                    try {
                        (new \App\Services\Tenant\DirectPaymentsService($tenant))
                            ->cancelDepositHold($rental->stripe_deposit_intent_id);
                        $rental->deposit_status = 'released';
                        $message = 'Returned. Deposit hold released.';
                    } catch (\Throwable $e) {
                        \Log::error('rental_deposit.release_on_return_failed', [
                            'rental_id' => $rental->id, 'error' => $e->getMessage(),
                        ]);
                        $message = 'Returned. Deposit hold could NOT be released — use the booking page or the Stripe dashboard.';
                    }
                } else {
                    $message = 'Returned. Deposit still on hold — release or capture from the booking page.';
                }
            }

            // Unit routing: available (default, derived anyway) or maintenance.
            foreach ($rental->lines->where('kind', 'unit') as $line) {
                $unit = $line->unit;
                if (!$unit) {
                    continue;
                }
                $route = $routing[$unit->id] ?? 'available';
                $note  = trim((string) ($routingNotes[$unit->id] ?? ''));
                if ($route === 'maintenance') {
                    $unit->status = 'maintenance';
                    if ($note !== '') {
                        $unit->notes = trim(($unit->notes ? $unit->notes . "\n" : '')
                            . '[' . now()->format('Y-m-d') . '] Return routing: ' . $note);
                    }
                    $unit->save();
                } elseif ($unit->status === 'maintenance' && $route === 'available') {
                    // Returning a unit that was somehow flagged: leave
                    // maintenance alone — clearing it is a deliberate fleet
                    // action, not a return side-effect.
                }
            }

            $rental->status = 'returned';
            $rental->returned_at = now();
            $rental->save();

            return $message;
        });
    }

    // --------------------------------------------------------- transitions
    public function checkOut(Request $request, string $id)
    {
        return $this->transition($id, 'reserved', function (TenantRental $rental) {
            $rental->update(['status' => 'out', 'checked_out_at' => now()]); // MARKER-PATCH-234
            return 'Checked out.';
        });
    }

    public function checkIn(Request $request, string $id)
    {
        return $this->transition($id, 'out', function (TenantRental $rental) {
            // MARKER-PATCH-220 — clean return auto-releases a live hold.
            // Stripe failure does NOT block the return: holds self-expire,
            // and the panel keeps a Release button while status=authorized.
            // MARKER-PATCH-237 — unless the tenant turned auto-release off.
            $autoRelease = (bool) ((tenant()->settings['rental_deposit_autorelease_quick'] ?? true));
            $message = 'Returned. Unit is available again.';
            if (!$autoRelease && $rental->deposit_status === 'authorized') {
                $message = 'Returned. Deposit still on hold — release or capture from the booking page.';
            }
            if ($autoRelease && $rental->deposit_status === 'authorized' && $rental->stripe_deposit_intent_id) {
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
            $rental->update(['status' => 'cancelled', 'cancelled_at' => now()]); // MARKER-PATCH-234
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
                'sale_date'          => tnow()->toDateString(), // MARKER-TZ-WAVE1 — tenant-local business date
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

        // MARKER-PATCH-232B — round-trip: callers pass return_to so the
        // register hands staff back after payment. Local paths only.
        $returnTo = (string) $request->input('return_to', '');
        $suffix = '';
        if ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && strlen($returnTo) <= 500) {
            $suffix = '&return_to=' . urlencode($returnTo);
        }

        return redirect(route('tenant.register.index') . '?resume=' . $sale->id . $suffix)
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
                'sale_date'          => tnow()->toDateString(), // MARKER-TZ-WAVE1 — tenant-local business date
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
        $prefix = 'RD-' . tnow()->format('Ymd') . '-'; // MARKER-TZ-WAVE1
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
