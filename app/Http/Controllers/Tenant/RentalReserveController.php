<?php
// MARKER-PATCH-240

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalLine;
use App\Models\Tenant\TenantRentalModel;
use App\Models\Tenant\TenantRentalUnit;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Models\Tenant\TenantSalePayment;
use App\Services\MySQLLock;
use App\Services\RentalAvailabilityService;
use App\Services\Tenant\DirectPaymentsService;
use App\Services\Tenant\SalePaymentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Public reservation checkout. Model + window in → reserved rental out,
 * under the same advisory lock the desk uses, with payment (when enabled)
 * recorded through the one ledger path. See patch header for the full
 * safety story.
 */
class RentalReserveController extends Controller
{
    public function __construct(
        private readonly RentalAvailabilityService $availability,
    ) {}

    // ------------------------------------------------------------- helpers
    private function window(Request $request, string $tz): array
    {
        $start = Carbon::parse((string) $request->input('starts'), $tz);
        $due   = Carbon::parse((string) $request->input('due'), $tz);
        if ($due->lessThanOrEqualTo($start)) {
            throw new RuntimeException('Return time must be after pickup.');
        }
        if ($due->copy()->subDays(60)->greaterThan($start)) {
            throw new RuntimeException('Online reservations max out at 60 days — get in touch for longer.');
        }
        return [$start->utc(), $due->utc()];
    }

    private function priceDaily(TenantRentalUnit $unit, Carbon $startUtc, Carbon $dueUtc): array
    {
        $rate = $unit->effectiveDailyCents();
        if (!$rate) {
            throw new RuntimeException('This model has no daily rate configured.');
        }
        $days = max(1, (int) ceil($startUtc->diffInMinutes($dueUtc) / 1440));
        return [(int) $rate, $days, (int) $rate * $days];
    }

    private function freeUnitOfModel(TenantRentalModel $model, Carbon $startUtc, Carbon $dueUtc): ?TenantRentalUnit
    {
        $units = TenantRentalUnit::where('tenant_id', $model->tenant_id)
            ->where('model_id', $model->id)
            ->whereNull('archived_at')
            ->where('status', 'available')
            ->where('available_for_rent', true)
            ->where('online_booking', true)
            ->with('model')
            ->orderBy('identifier')
            ->get();

        foreach ($units as $u) {
            if ($this->availability->isUnitAvailable($u, $startUtc, $dueUtc)) {
                return $u;
            }
        }
        return null;
    }

    private function resolvePublicCustomer(string $tenantId, Request $request): TenantCustomer
    {
        $email = strtolower(trim((string) $request->input('email')));
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

    private function generateOnlineSaleNumber(string $tenantId): string
    {
        $prefix = 'RO-' . now()->format('Ymd') . '-';
        $max = DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderByDesc('sale_number')
            ->value('sale_number');
        $next = 1;
        if ($max) {
            $parts = explode('-', $max);
            $next = ((int) end($parts)) + 1;
        }
        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    // ----------------------------------------------------------------- show
    public function show(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rentals_visible, 404);

        $tz = $tenant->timezone();

        $model = TenantRentalModel::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->where('id', (string) $request->query('model'))
            ->with('category:id,name')
            ->first();

        try {
            [$startUtc, $dueUtc] = $this->window($request, $tz);
        } catch (\Throwable $e) {
            return redirect()->route('tenant.rentals.browse');
        }

        if (!$model) {
            return redirect()->route('tenant.rentals.browse', [
                'starts' => $request->query('starts'), 'due' => $request->query('due'),
            ]);
        }

        $unit = $this->freeUnitOfModel($model, $startUtc, $dueUtc);
        if (!$unit) {
            return redirect()->route('tenant.rentals.browse', [
                'starts' => $request->query('starts'), 'due' => $request->query('due'),
            ])->with('browse_error', $model->name . ' just sold out for that window.');
        }

        [$rate, $days, $subtotal] = $this->priceDaily($unit, $startUtc, $dueUtc);
        $taxRate = (float) ($tenant->default_tax_rate ?? 0);
        $tax     = (int) round($subtotal * $taxRate / 100);

        $payments = new DirectPaymentsService($tenant);

        return view('public.rental-reserve', [
            'model'      => $model,
            'startLocal' => $startUtc->copy()->setTimezone($tz),
            'dueLocal'   => $dueUtc->copy()->setTimezone($tz),
            'starts'     => $request->query('starts'),
            'due'        => $request->query('due'),
            'rateCents'  => $rate,
            'days'       => $days,
            'subtotal'   => $subtotal,
            'tax'        => $tax,
            'total'      => $subtotal + $tax,
            'payOnline'  => $payments->isEnabled(),
        ]);
    }

    // ---------------------------------------------------------------- store
    public function store(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rentals_visible, 404);

        $request->validate([
            'model_id'   => ['required', 'uuid'],
            'starts'     => ['required', 'string'],
            'due'        => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name'  => ['nullable', 'string', 'max:120'],
            'email'      => ['required', 'email', 'max:190'],
            'phone'      => ['nullable', 'string', 'max:40'],
        ]);

        $tz = $tenant->timezone();
        try {
            [$startUtc, $dueUtc] = $this->window($request, $tz);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if ($startUtc->lessThan(now()->subHour())) {
            return response()->json(['ok' => false, 'error' => 'That pickup time is in the past.'], 422);
        }

        $model = TenantRentalModel::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')->where('id', $request->input('model_id'))->first();
        if (!$model) {
            return response()->json(['ok' => false, 'error' => 'That model is no longer offered.'], 422);
        }

        $payments  = new DirectPaymentsService($tenant);
        $payOnline = $payments->isEnabled();

        $lock = app(MySQLLock::class);
        $lockKey = 'intake:' . substr($tenant->id, 0, 8) . ':rent:write';

        try {
            $result = $lock->withLock($lockKey, function () use ($tenant, $request, $model, $startUtc, $dueUtc, $payOnline) {
                return DB::transaction(function () use ($tenant, $request, $model, $startUtc, $dueUtc, $payOnline) {
                    // Pick + re-verify the unit INSIDE the lock.
                    $unit = $this->freeUnitOfModel($model, $startUtc, $dueUtc);
                    if (!$unit) {
                        throw new RuntimeException($model->name . ' just sold out for that window — try different dates.');
                    }

                    [$rate, $days, $subtotal] = $this->priceDaily($unit, $startUtc, $dueUtc);
                    $taxRate = (float) ($tenant->default_tax_rate ?? 0);
                    $tax     = (int) round($subtotal * $taxRate / 100);
                    $total   = $subtotal + $tax;

                    $customer = $this->resolvePublicCustomer($tenant->id, $request);

                    $rental = TenantRental::create([
                        'tenant_id'      => $tenant->id,
                        'customer_id'    => $customer->id,
                        'rental_number'  => TenantRental::generateRentalNumber($tenant->id),
                        'status'         => 'reserved',
                        'source'         => 'online',
                        'starts_at'      => $startUtc,
                        'due_at'         => $dueUtc,
                        'subtotal_cents' => $subtotal,
                        'tax_cents'      => $tax,
                        'total_cents'    => $total,
                        'paid_cents'     => 0,
                        'notes'          => 'Reserved online.',
                    ]);

                    TenantRentalLine::create([
                        'rental_id'           => $rental->id,
                        'kind'                => 'unit',
                        'unit_id'             => $unit->id,
                        'name_snapshot'       => $unit->name . ($unit->identifier ? " ({$unit->identifier})" : ''),
                        'rate_mode_snapshot'  => 'daily',
                        'rate_cents_snapshot' => $rate,
                        'quantity'            => 1,
                        'duration_units'      => $days,
                        'line_total_cents'    => $subtotal,
                        'sort_order'          => 10,
                    ]);

                    $sale = null;
                    $pi   = null;
                    if ($payOnline && $total > 0) {
                        $payments = new DirectPaymentsService($tenant);
                        $pi = $payments->createPaymentIntent($total, 'usd', [
                            'tenant_id' => $tenant->id,
                            'rental_id' => $rental->id,
                            'purpose'   => 'rental_online_reservation',
                        ]);

                        // Draft sale born WITH the PI id — webhook safety net
                        // and payment-link promotion semantics both line up.
                        $sale = TenantSale::create([
                            'id'                       => (string) Str::uuid(),
                            'tenant_id'                => $tenant->id,
                            'sale_number'              => $this->generateOnlineSaleNumber($tenant->id),
                            'sale_date'                => now()->toDateString(),
                            'status'                   => 'pending',
                            'payment_status'           => 'draft',
                            'customer_id'              => $customer->id,
                            'rental_id'                => $rental->id,
                            'stripe_payment_intent_id' => $pi->id,
                            'subtotal_cents'           => $subtotal,
                            'tax_cents'                => $tax,
                            'total_cents'              => $total,
                            'notes'                    => 'Online reservation for rental ' . $rental->rental_number,
                        ]);
                        TenantSaleItem::create([
                            'id'               => (string) Str::uuid(),
                            'tenant_id'        => $tenant->id,
                            'sale_id'          => $sale->id,
                            'type'             => 'open_item',
                            'name_snapshot'    => 'Rental ' . $rental->rental_number . ' — ' . $unit->name,
                            'quantity'         => 1,
                            'unit_price_cents' => $subtotal,
                            'line_total_cents' => $subtotal,
                            'is_taxable'       => $tax > 0,
                            'position'         => 0,
                        ]);
                    }

                    return [$rental, $sale, $pi];
                });
            });
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            Log::error('rental_reserve.store_failed', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not create the reservation — try again.'], 500);
        }

        [$rental, $sale, $pi] = $result;
        $request->session()->put('public_rental_id', $rental->id);

        // MARKER-PATCH-247 — online reservations ping the staff bell.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'rental.reserved_online', [
            'title' => 'Online reservation — ' . $rental->rental_number,
            'body'  => trim(($request->input('first_name') . ' ' . $request->input('last_name')))
                . ' · pickup ' . tlocal_datetime($rental->starts_at, 'D M j, g:i A')
                . ($pi ? ' · paying by card now' : ' · pays at pickup'),
            'link'  => route('tenant.rentals.bookings.show', $rental->id),
            'meta'  => ['rental_id' => $rental->id],
        ]);

        if ($pi) {
            $payments = new DirectPaymentsService($tenant);
            return response()->json([
                'ok'              => true,
                'mode'            => 'pay',
                'rental_id'       => $rental->id,
                'client_secret'   => $pi->client_secret,
                'payment_intent'  => $pi->id,
                'publishable_key' => $payments->publishableKey(),
            ]);
        }

        return response()->json([
            'ok'   => true,
            'mode' => 'done',
            'next' => route('tenant.rentals.reserved'),
        ]);
    }

    // -------------------------------------------------------------- confirm
    public function confirm(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rentals_visible, 404);

        $request->validate(['payment_intent' => ['required', 'string', 'max:120']]);
        $piId = $request->input('payment_intent');

        $rentalId = $request->session()->get('public_rental_id');
        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', $piId)
            ->when($rentalId, fn ($q) => $q->where('rental_id', $rentalId))
            ->first();
        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Reservation not found.'], 404);
        }

        // Idempotent: the webhook or a double-click may have beaten us here.
        $already = TenantSalePayment::where('sale_id', $sale->id)
            ->where('external_reference', $piId)->exists();
        if ($already) {
            return response()->json(['ok' => true, 'next' => route('tenant.rentals.reserved')]);
        }

        try {
            $payments = new DirectPaymentsService($tenant);
            $pi = $payments->retrievePaymentIntent($piId);
        } catch (\Throwable $e) {
            Log::error('rental_reserve.confirm_retrieve_failed', ['pi' => $piId, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not verify the payment.'], 502);
        }

        if ($pi->status !== 'succeeded') {
            return response()->json(['ok' => false, 'error' => 'Payment has not completed.'], 422);
        }
        if ((int) $pi->amount !== (int) $sale->total_cents) {
            Log::error('rental_reserve.amount_mismatch', ['pi' => $piId, 'sale' => $sale->id]);
            return response()->json(['ok' => false, 'error' => 'Payment amount mismatch — contact the shop.'], 422);
        }

        app(SalePaymentService::class)->record(
            $sale,
            (int) $pi->amount,
            TenantSalePayment::KIND_PAYMENT,
            TenantSalePayment::SOURCE_BOOKING_FLOW,
            'card',
            null,
            $piId,
            'Online rental reservation payment.',
        );

        return response()->json(['ok' => true, 'next' => route('tenant.rentals.reserved')]);
    }

    // -------------------------------------------------------- confirmation
    public function confirmation(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rentals_visible, 404);

        $rental = null;
        if ($id = $request->session()->get('public_rental_id')) {
            $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
                ->with('lines')->first();
        }
        if (!$rental) {
            return redirect()->route('tenant.rentals.browse');
        }

        return view('public.rental-confirmed', ['rental' => $rental]);
    }
}
