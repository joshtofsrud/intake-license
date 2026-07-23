#!/bin/bash
# booking-hold-release-and-failed-paid — the two remaining booking-flow gaps.
#   HOLD RELEASE: a failed card payment beacons /book/release-hold with its
#   pending token; the hold flips to released instantly, so retries never
#   collide with their own ghost and a day with open capacity just fills.
#   Endpoint is token-authenticated, capacity-freeing only, CSRF-exempt
#   (sendBeacon), and released rows reap on the normal schedule.
#   FAILED-PAID: any verified-paid pending that cannot materialize (browser
#   or webhook path, any reason) flips to permanent status failed_paid —
#   excluded from the reaper forever — and emits ONE staff bell alert
#   (guarded against the browser/webhook race) with customer name, contact,
#   date, amount, failure reason, and the Stripe PaymentIntent id.
#   ROUTES: this script inserts ONE line into routes/web.php surgically via
#   python (never a full-file write).
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-HOLD-RELEASE" app/Http/Controllers/Tenant/BookingController.php; then
  echo "already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-PREPAY-FK-FIX" app/Services/BookingService.php; then
  echo "prepay-fk-fix not applied — wrong base, aborting."; exit 1
fi

python3 - <<'PYROUTE'
s = open('routes/web.php').read()
if 'release-hold' in s:
    print('route already present')
else:
    old = "    Route::post('/book/finalize',        [TenantControllers\\BookingController::class, 'finalize'])->name('tenant.booking.finalize'); // MARKER-PATCH-385"
    assert s.count(old) == 1, 'finalize route anchor not found — routes/web.php differs, aborting'
    s = s.replace(old, old + chr(10) + "    Route::post('/book/release-hold',    [TenantControllers\\BookingController::class, 'releaseHold'])->name('tenant.booking.release-hold'); // MARKER-HOLD-RELEASE")
    open('routes/web.php', 'w').write(s)
    print('route inserted')
PYROUTE

cat > 'app/Http/Controllers/Tenant/BookingController.php' <<'HOLDREL_0_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Exceptions\LockAcquisitionException;
use App\Services\BookingService;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use RuntimeException;

class BookingController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        // MARKER-PATCH-599 — prep extracted to BookingFormData so the
        // booking_embed page-builder section renders from the same source.
        extract(\App\Services\Tenant\BookingFormData::for($tenant));

        // MARKER-FLOW-3 — booking flow mode (advanced | simple | choice).
        // Simple and choice are lighter front-ends onto the same endpoints;
        // advanced is unchanged. A single-item Simple booking submits the exact
        // same payload as a one-item advanced booking, so no backend change.
        $flowSvc  = app(\App\Services\Tenant\BookingFlowService::class);
        $flowMode = $flowSvc->mode($tenant);
        $flow     = request()->query('flow');

        if ($flowSvc->showFork($flowMode, $flow)) {
            return view('public.booking-choice', compact('bk', 'flowMode', 'bookingSections')); // MARKER-PATCH-604
        }

        $simpleServices = $flowSvc->simpleServices($tenant);
        $view = $flowSvc->useSimpleView($flowMode, $flow) ? 'public.booking-simple' : 'public.booking';

        return view($view, compact(
            'catalog', 'formSections', 'receivingMethods',
            'stripeEnabled', 'paypalEnabled', 'stripePublishableKey', 'paypalClientId',
            'bookingMode', 'resources', 'bk', 'simpleServices', 'flowMode',
            'bookingSections' // MARKER-PATCH-604
        ));
    }

    public function availability(Request $request)
    {
        $request->validate([
            'year'       => ['required', 'integer', 'min:2024', 'max:2030'],
            'month'      => ['required', 'integer', 'min:1', 'max:12'],
            'service_id' => ['nullable', 'string', 'uuid'],
        ]);

        $tenant     = tenant();
        $mode       = $tenant->booking_mode ?? 'drop_off';
        $booking    = app(BookingService::class);
        $year       = (int) $request->input('year');
        $month      = (int) $request->input('month');
        $serviceId  = $request->input('service_id');

        $capacityMap = []; // MARKER-PATCH-517
        $dates = $booking->availableDates($tenant, $year, $month, $serviceId, $capacityMap);

        $available   = array_flip($dates);
        $unavailable = [];
        $windowDays     = $tenant->booking_window_days ?? 60;
        $minNoticeHours = $tenant->min_notice_hours    ?? 24;
        $earliestDay = now()->addHours($minNoticeHours)->startOfDay();
        $latestDay   = now()->addDays($windowDays)->endOfDay();
        $monthStart  = \Carbon\Carbon::create($year, $month, 1)->max($earliestDay);
        $monthEnd    = \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->min($latestDay);
        if ($monthStart->lte($monthEnd)) {
            $cur = $monthStart->copy();
            while ($cur->lte($monthEnd)) {
                $ds = $cur->toDateString();
                if (!isset($available[$ds])) $unavailable[] = $ds;
                $cur->addDay();
            }
        }

        $earliest = null;
        if (!empty($dates)) {
            $earliest = ['date' => $dates[0], 'time' => null];
        }

        // MARKER-PATCH-511 — Pickup & delivery: per-date route windows with
        // remaining stop counts. Only present when the tenant runs routes;
        // the P2b frontend renders it, older frontends ignore the key.
        $pdWindows = [];
        if ($tenant->deliveries_enabled) {
            $windows = \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $tenant->id)
                ->active()->get();
            if ($windows->isNotEmpty()) {
                foreach ($dates as $date) {
                    $day = \Carbon\Carbon::parse($date);
                    $dayWindows = [];
                    foreach ($windows as $w) {
                        if (! $w->runsOn($day)) continue;
                        $remaining = $w->remainingStops($day);
                        $dayWindows[] = [
                            'id'        => $w->id,
                            'label'     => $w->label,
                            'starts_at' => substr((string) $w->starts_at, 0, 5),
                            'ends_at'   => substr((string) $w->ends_at, 0, 5),
                            'remaining' => $remaining,
                            'full'      => $remaining === 0,
                        ];
                    }
                    if (! empty($dayWindows)) $pdWindows[$date] = $dayWindows;
                }
            }
        }

        $slots = [];
        $slotResources = [];

        if ($mode === 'time_slots') {
            // Load active resources once; reused for every slot's availability check.
            $resources = $tenant->resources()->where('is_active', true)->get(['id']);

            // Default slot duration falls back to the day's slot_interval inside
            // resourceIsFreeDuring; passing 0 here lets the service decide. We
            // instead pass a real duration (interval) per-day so prep/cleanup math
            // stays consistent with availableSlotsForDate.
            foreach ($dates as $date) {
                $daySlots = $booking->availableSlotsForDate($tenant, $date);
                $slots[$date] = $daySlots;

                if (empty($daySlots) || $resources->isEmpty()) {
                    continue;
                }

                // Pull the rule once per day for slot duration.
                $dow = \Carbon\Carbon::parse($date)->dayOfWeek;
                $rule = \App\Models\Tenant\TenantCapacityRule::where('tenant_id', $tenant->id)
                    ->where('rule_type', 'default')->where('day_of_week', $dow)->first();
                $interval = (int) ($rule->slot_interval_minutes ?? 60);

                $slotResources[$date] = [];
                foreach ($daySlots as $time) {
                    $freeIds = [];
                    foreach ($resources as $res) {
                        $conflict = $booking->resourceIsFreeDuring(
                            $tenant->id, $res->id, $date, $time . ':00', $interval
                        );
                        if ($conflict === null) {
                            $freeIds[] = $res->id;
                        }
                    }
                    $slotResources[$date][$time] = $freeIds;
                }
            }

            // Fill in earliest.time from the first available day's first slot.
            // We walk forward in case the first day happens to have no slots
            // (rare edge case — e.g. all slots were just booked but the day
            // hasn't fully tipped to 'unavailable' yet in the cap math).
            if ($earliest !== null) {
                foreach ($dates as $d) {
                    if (!empty($slots[$d])) {
                        $earliest['date'] = $d;
                        $earliest['time'] = $slots[$d][0];
                        break;
                    }
                }
            }
        }

        return response()->json([
            'dates'             => $dates,
            'unavailable_dates' => $unavailable,
            'earliest'          => $earliest,
            'slots'             => $slots,
            'pd_windows'        => $pdWindows, // MARKER-PATCH-511
            'capacity'          => $capacityMap, // MARKER-PATCH-517
            'pd_need_by'        => ! empty($pdWindows) && (bool) (((array) ($tenant->settings ?? []))['pd_need_by_enabled'] ?? true), // MARKER-PATCH-519
            'pd_lead_days'      => (int) (((array) ($tenant->settings ?? []))['pd_pickup_lead_days'] ?? 1), // MARKER-PATCH-520
            'pd_allow_day_of'   => (bool) (((array) ($tenant->settings ?? []))['pd_allow_day_of'] ?? false), // MARKER-PATCH-524
            'slot_resources'    => $slotResources,
            'mode'              => $mode,
        ]);
    }

    public function submit(Request $request)
    {
        $request->validate([
            'first_name'              => ['required', 'string', 'max:100'],
            'last_name'               => ['required', 'string', 'max:100'],
            'email'                   => ['required', 'email', 'max:191'],
            'phone'                   => ['nullable', 'string', 'max:32'],
            'date'                    => ['required', 'date', 'after_or_equal:today'],
            'route_window_id'         => ['nullable', 'uuid'], // MARKER-PATCH-512
            'pickup_outreach'         => ['nullable', 'boolean'], // MARKER-PICKUP-OUTREACH
            'pickup_date'             => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:date'], // MARKER-PATCH-520
            'need_by'                 => ['nullable', 'date', 'after_or_equal:date'], // MARKER-PATCH-512
            'appointment_time'        => ['nullable', 'string'],
            'resource_id'             => ['nullable', 'string', 'uuid'],
            'receiving_method'        => ['nullable', 'string'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.service_item_id' => ['required', 'string'],
            'items.*.addon_ids'       => ['nullable', 'array'],
            'items.*.addon_ids.*'     => ['string'],
            'responses'               => ['nullable', 'array'],
            'response_labels'         => ['nullable', 'array'],
            'payment_method'          => ['required', 'in:stripe,paypal,none'],
            // MARKER-PATCH-216 — multi-asset booking persistence
            'customer_id'                => ['nullable', 'string', 'uuid'],
            'items.*.asset_client_key'   => ['nullable', 'string', 'max:64'],
            'assets'                     => ['nullable', 'array', 'max:25'],
            'assets.*.client_key'        => ['required_with:assets', 'string', 'max:64'],
            'assets.*.name_snapshot'     => ['nullable', 'string', 'max:200'],
            'assets.*.customer_asset_id' => ['nullable', 'string', 'uuid'],
        ]);

        $tenant = tenant();

        // MARKER-PATCH-385 — Card deposits use charge-then-create: reserve a slot
        // and a PaymentIntent now, and materialize the appointment only after the
        // card clears (see finalize()). Non-card paths fall through to the
        // original create-then-charge flow below.
        if ($request->input('payment_method') === 'stripe') {
            $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
            if (! $direct->isEnabled()) {
                return response()->json(['success' => false, 'message' => 'Card payments are not set up yet.'], 422);
            }

            try {
                $pending = app(BookingService::class)->reserve($request->all(), $tenant->id);
            } catch (LockAcquisitionException $e) {
                return response()->json(['success' => false, 'code' => 'lock_timeout', 'message' => 'We couldn\'t hold this slot in time. Please try again.'], 409);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'just taken')) {
                    return response()->json(['success' => false, 'code' => 'slot_taken', 'message' => $e->getMessage()], 409);
                }
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            // Zero-total booking that routed to card -> no charge, materialize now.
            if ((int) $pending->total_cents === 0) {
                $appt = app(BookingService::class)->materialize($pending);
                return response()->json(['success' => true, 'redirect' => url("/confirm?ra={$appt->ra_number}"), 'ra_number' => $appt->ra_number]);
            }

            try {
                $pi = $direct->createPaymentIntent(
                    (int) $pending->total_cents,
                    strtolower((string) ($tenant->currency ?? 'usd')),
                    ['pending_booking_id' => $pending->id, 'pending_token' => $pending->token]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('booking.payment_intent_failed', ['tenant_id' => $tenant->id, 'pending_id' => $pending->id, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'Could not start the card payment. Please try again.'], 422);
            }

            $pending->update(['stripe_payment_intent_id' => $pi->id]);

            return response()->json([
                'success' => true, 'payment' => 'stripe',
                'client_secret' => $pi->client_secret,
                'pending_token' => $pending->token,
            ]);
        }

        // Concurrency-protected booking creation.
        // Lock timeout → slot likely contended right now; caller should retry.
        // Slot-just-taken → someone else grabbed this exact time between
        //   the customer loading the picker and submitting.
        // Both return 409 Conflict with specific messages so the frontend can
        //   either auto-retry (timeout) or refresh the picker (slot taken).
        try {
            $appointment = app(BookingService::class)->createAppointment($request->all(), $tenant->id);
        } catch (LockAcquisitionException $e) {
            return response()->json([
                'success' => false,
                'code'    => 'lock_timeout',
                'message' => 'We couldn\'t hold this slot in time. Please try again.',
            ], 409);
        } catch (RuntimeException $e) {
            // Distinguish "slot just taken" from other runtime errors by checking
            // the canonical message. Other RuntimeExceptions (invalid service id,
            // missing email, etc.) are client-input errors and surface as 422-ish.
            if (str_contains($e->getMessage(), 'just taken')) {
                return response()->json([
                    'success' => false,
                    'code'    => 'slot_taken',
                    'message' => $e->getMessage(),
                ], 409);
            }
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // patch-93 booking soft SO — detect a customer-typed part request and
        // create a status=needed SO. Triggers when a custom field with
        // field_key='so_request' (case-insensitive) has a non-empty response.
        // Tenants enable this by adding such a field to their booking form.
        try {
            $responses = (array) $request->input('responses', []);
            $soRequestText = null;

            foreach ($responses as $fieldKey => $value) {
                if (strtolower((string) $fieldKey) === 'so_request') {
                    $trimmed = trim((string) $value);
                    if ($trimmed !== '') {
                        $soRequestText = $trimmed;
                    }
                    break;
                }
            }

            if ($soRequestText !== null && $appointment) {
                $soSvc = app(\App\Services\Tenant\SpecialOrderService::class);
                $soSvc->create([
                    'tenant_id'           => $tenant->id,
                    'inventory_item_id'   => null,
                    'item_name_snapshot'  => mb_substr($soRequestText, 0, 255),
                    'quantity'            => 1,
                    'customer_id'         => $appointment->customer_id,
                    'appointment_id'      => $appointment->id,
                    'status'              => \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED,
                    'created_from'        => 'booking',
                ]);
            }
        } catch (\Throwable $e) {
            // Soft SO creation is best-effort — never block booking completion.
            \Illuminate\Support\Facades\Log::warning('Booking soft SO creation failed', [
                'tenant_id' => $tenant->id,
                'appointment_id' => $appointment->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        $paymentMethod = $request->input('payment_method');

        if ($paymentMethod === 'none' || $appointment->total_cents === 0) {
            return response()->json([
                'success' => true,
                'redirect' => url("/confirm?ra={$appointment->ra_number}"),
                'ra_number' => $appointment->ra_number,
            ]);
        }

        // MARKER-PATCH-385 — card path is handled at the top of submit()
        // (charge-then-create); the old StripeService branch is retired.

        if ($paymentMethod === 'paypal') {
            $paypal = new PayPalService($tenant);
            if (!$paypal->isConfigured()) {
                return response()->json(['success' => false, 'message' => 'PayPal is not configured.'], 422);
            }
            $order = $paypal->createOrder($appointment);
            return response()->json([
                'success' => true, 'payment' => 'paypal',
                'approve_url' => $order['approve_url'],
                'ra_number' => $appointment->ra_number,
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Unknown payment method.'], 422);
    }

    /**
     * MARKER-PATCH-385 — Materialize a paid booking hold into an appointment.
     * Called by the browser after the card confirms. Verifies the PaymentIntent
     * actually succeeded against Stripe (never trusts the client), then writes
     * the appointment via BookingService::materialize (idempotent).
     */
    /**
     * MARKER-HOLD-RELEASE — a failed payment releases its hold immediately,
     * so a retry can never collide with its own ghost. Token-authenticated
     * (same secret the browser holds for finalize); releasing is only ever
     * capacity-freeing, so the endpoint is safe to expose.
     */
    public function releaseHold(Request $request)
    {
        $tenant = tenant();
        $token  = (string) $request->input('pending_token');
        if ($token === '') return response()->json(['ok' => false], 422);

        \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenant->id)
            ->where('token', $token)
            ->where('status', 'pending')
            ->update(['status' => 'released', 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function finalize(Request $request)
    {
        $tenant = tenant();
        $token  = (string) $request->input('pending_token');

        $pending = \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenant->id)
            ->where('token', $token)
            ->first();
        if (! $pending) {
            return response()->json(['success' => false, 'message' => 'Booking session not found.'], 404);
        }

        // Idempotent: already materialized.
        if ($pending->status === 'materialized' && $pending->appointment_id) {
            $appt = \App\Models\Tenant\TenantAppointment::find($pending->appointment_id);
            if ($appt) {
                return response()->json(['success' => true, 'redirect' => url("/confirm?ra={$appt->ra_number}"), 'ra_number' => $appt->ra_number]);
            }
        }

        // Never trust the browser — confirm the charge with Stripe directly.
        $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
        try {
            $pi = $direct->retrievePaymentIntent((string) $pending->stripe_payment_intent_id);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not verify payment.'], 422);
        }
        if (($pi->status ?? null) !== 'succeeded') {
            return response()->json(['success' => false, 'message' => 'Payment not completed.'], 402);
        }

        try {
            $appt = app(BookingService::class)->materialize($pending);
        } catch (RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error('booking.materialize_failed', ['tenant_id' => $tenant->id, 'pending_id' => $pending->id, 'pi' => $pending->stripe_payment_intent_id, 'error' => $e->getMessage()]);
            \App\Services\BookingService::recordFailedPaid($pending, $e->getMessage()); // MARKER-FAILED-PAID
            return response()->json(['success' => false, 'message' => 'Your payment went through but that time was just taken. We will reach out to reschedule.'], 409);
        }

        return response()->json(['success' => true, 'redirect' => url("/confirm?ra={$appt->ra_number}"), 'ra_number' => $appt->ra_number]);
    }

    public function paypalReturn(Request $request)
    {
        $orderId = $request->query('token');
        if (!$orderId) return redirect('/book')->with('error', 'PayPal payment was cancelled.');

        try {
            $appointment = PayPalService::handleReturn(tenant(), $orderId);
            return redirect("/confirm?ra={$appointment->ra_number}");
        } catch (\Throwable $e) {
            logger()->error('PayPal return error: ' . $e->getMessage());
            return redirect('/book')->with('error', 'Payment could not be completed. Please try again.');
        }
    }

    public function paypalWebhook(Request $request)
    {
        logger()->info('PayPal webhook received', $request->all());
        return response('ok');
    }
}
HOLDREL_0_EOF

cat > 'app/Services/BookingService.php' <<'HOLDREL_1_EOF'
<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentAddon;
use App\Models\Tenant\TenantAppointmentItem;
use App\Models\Tenant\TenantCalendarBreak;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantServiceAddon;
use App\Models\Tenant\TenantWalkinHold;
use App\Support\MySQLLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Jobs\SendBookingConfirmationJob;
use RuntimeException;

class BookingService
{
    /**
     * Create a booking appointment with concurrency protection.
     *
     * Lock scopes (all go through MySQLLock::withLock):
     *   time-slot + resource:  intake:{tenant}:booking:{resource}:{date}-{time}
     *   time-slot + any:       intake:{tenant}:booking:anyresource:{date}-{time}
     *   drop-off:              intake:{tenant}:dropoff:{date}
     *
     * Drop-off still gets a lock — at 500+ tenants in peak season, two
     * simultaneous submits against a nearly-full capacity race the
     * slot_weight sum and cause subtle overbooking. Lock scope is
     * per-tenant-per-day, which is wider than the time-slot lock but
     * fires rarely enough that contention is a non-issue.
     */
    public function createAppointment(array $data, string $tenantId): TenantAppointment
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one service is required.');
        }

        $plan = $this->buildBookingPlan($data['items'], $tenantId);

        $totalCents          = 0;
        $totalDuration       = 0;
        $customerFacingDur   = 0;  // duration excluding prep/cleanup — what the customer "sees"
        $slotWeight          = 0;

        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents        += (int) ($row['effective_price_cents'] ?? $service->price_cents);
            $customerFacingDur += (int) $service->duration_minutes;
            $totalDuration     += (int) $service->prep_before_minutes
                                + (int) $service->duration_minutes
                                + (int) $service->cleanup_after_minutes;
            $slotWeight        += (int) ($service->slot_weight ?? 1);

            foreach ($row['addons'] as $addonRow) {
                $totalCents        += (int) $addonRow['effective_price_cents'];
                $totalDuration     += (int) $addonRow['effective_duration'];
                $customerFacingDur += (int) $addonRow['effective_duration'];
            }
        }

        $appointmentTime    = !empty($data['appointment_time']) ? $data['appointment_time'] : null;
        $resourceId         = !empty($data['resource_id'])      ? $data['resource_id']      : null;

        // Validate resource belongs to this tenant and is active. Prevents
        // cross-tenant id submission and bookings against archived resources.
        // The slot re-check inside the lock catches "just taken", but does not
        // catch "this resource was deactivated 30 seconds ago" — that case
        // would slip through silently otherwise.
        if ($resourceId !== null) {
            $resourceOk = \App\Models\Tenant\TenantResource::where('id', $resourceId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();
            if (!$resourceOk) {
                throw new RuntimeException('That selection is no longer available. Please pick another.');
            }
        }

        $appointmentEndTime = null;
        if ($appointmentTime && $totalDuration > 0) {
            $start = new \DateTimeImmutable($appointmentTime);
            $end = $start->modify("+{$totalDuration} minutes");
            $appointmentEndTime = $end->format('H:i:s');
        }

        $tenant   = Tenant::findOrFail($tenantId);
        $mode     = $tenant->booking_mode ?? 'drop_off';

        // ------------------------------------------------------------------
        // Drop-off mode: resolve resource via auto-fallback.
        //
        // Walks the eligible-resources list (per service) and picks the
        // first one with remaining daily capacity. Skips resources whose
        // per-resource cap is already met. If no resource has space, throws
        // a clean "shop full today" error.
        //
        // For multi-service bookings, each service has its own eligibility
        // list; the chosen resource must satisfy the intersection. We use
        // the FIRST service's eligibility as the base and intersect with
        // each subsequent service's list. Empty intersection = "no single
        // resource can do all selected services" — also a hard reject.
        //
        // If $resourceId was provided up-front (e.g. customer picked one
        // explicitly), we honor that pick if it's eligible AND has capacity.
        // Otherwise we fall through. This keeps the explicit-pick path
        // working without forcing every drop-off booking to auto-assign.
        // ------------------------------------------------------------------
        if ($mode === 'drop_off' && $resourceId === null) {
            // Eligibility intersection across all selected services.
            $candidateIds = null;
            foreach ($plan as $row) {
                $serviceId = $row['service']->id;
                $svcEligible = $this->eligibleResourcesForService($tenantId, $serviceId);
                if ($candidateIds === null) {
                    $candidateIds = $svcEligible;
                } else {
                    $candidateIds = array_values(array_intersect($candidateIds, $svcEligible));
                }
                if (empty($candidateIds)) break;
            }

            if (empty($candidateIds)) {
                throw new RuntimeException(
                    'No staff member can perform every selected service. '
                    . 'Please pick a different combination.'
                );
            }

            // Pull caps for candidates in one query, then walk them in
            // sort_order to pick the first with remaining quota.
            $candidates = \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereIn('id', $candidateIds)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'max_appointments_per_day']);

            $picked = null;
            foreach ($candidates as $cand) {
                $cap = $cand->max_appointments_per_day;
                if ($cap === null) {
                    // Unbounded — always pick first unbounded resource.
                    $picked = $cand->id;
                    break;
                }
                $used = $this->resourceUsedSlotsForDate($tenantId, $cand->id, $data['date']);
                if (($used + $slotWeight) <= (int) $cap) {
                    $picked = $cand->id;
                    break;
                }
            }

            if ($picked === null) {
                throw new RuntimeException('All staff are fully booked on that date. Please pick another day.');
            }

            $resourceId = $picked;
        }
        $lockKey  = $this->computeLockKey($mode, $tenantId, $data['date'], $appointmentTime, $resourceId);
        $lock     = app(MySQLLock::class);

        return $lock->withLock($lockKey, function () use (
            $tenant, $mode, $data, $tenantId, $plan,
            $totalCents, $totalDuration, $customerFacingDur, $slotWeight,
            $appointmentTime, $appointmentEndTime, $resourceId
        ) {
            // Re-check availability inside the lock. This is the read-your-writes
            // step that makes the lock meaningful — without it, we'd just be
            // serializing inserts without actually preventing double-booking.
            if ($mode === 'time_slots' && $appointmentTime) {
                $openSlots = $this->availableSlotsForDate(
                    $tenant,
                    $data['date'],
                    $resourceId,
                    $customerFacingDur
                );
                // appointment_time may be HH:MM:SS; availableSlotsForDate returns HH:MM.
                $wanted = substr($appointmentTime, 0, 5);
                if (!in_array($wanted, $openSlots, true)) {
                    throw new RuntimeException('That time slot was just taken. Please pick another.');
                }
            }
            // Drop-off mode: the existing availableDates logic already consults
            // capacity, so we trust that. If drop-off capacity races become a real
            // problem we add a re-check here similar to the time-slot path.

            return DB::transaction(function () use (
                $data, $tenantId, $plan,
                $totalCents, $totalDuration, $slotWeight,
                $appointmentTime, $appointmentEndTime, $resourceId
            ) {
                $customer = $this->upsertCustomer($data, $tenantId);
                $raNumber = TenantAppointment::generateRaNumber($tenantId, $data['date'] ?? null);

                // Resolve location: caller-provided wins; otherwise tenant's default.
                $locationId = $data['location_id'] ?? null;
                if (! $locationId) {
                    $locationId = \App\Models\Tenant\TenantLocation::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', 1)
                        ->orderByDesc('is_default')
                        ->orderBy('created_at')
                        ->value('id');
                }

                $appointment = TenantAppointment::create([
                    'id'                       => (string) Str::uuid(),
                    'tenant_id'                => $tenantId,
                    'customer_id'              => $customer->id,
                    'resource_id'              => $resourceId,
                    'location_id'              => $locationId,
                    'ra_number'                => $raNumber,
                    'customer_first_name'      => $data['first_name'] ?? '',
                    'customer_last_name'       => $data['last_name']  ?? '',
                    'customer_email'           => strtolower(trim($data['email'] ?? '')),
                    'customer_phone'           => $data['phone']      ?? null,
                    'appointment_date'         => $data['date'],
                    'appointment_time'         => $appointmentTime,
                    'appointment_end_time'     => $appointmentEndTime,
                    'total_duration_minutes'   => $totalDuration,
                    'slot_weight'              => $slotWeight,
                    'slot_weight_auto'         => $slotWeight,
                    'slot_weight_overridden'   => false,
                    'receiving_method_snapshot'=> $data['receiving_method'] ?? null,
                    'status'                   => 'pending',
                    'payment_status'           => 'unpaid',
                    'payment_method'           => $data['payment_method']   ?? null,
                    'subtotal_cents'           => $totalCents,
                    'tax_cents'                => 0,
                    'total_cents'              => $totalCents,
                    'paid_cents'               => 0,
                ]);

                // MARKER-PATCH-216 — multi-asset persistence. Create the
                // appointment-asset rows first so each item/addon can be
                // tagged with appointment_asset_id at insert time. Empty map
                // in single-asset mode -> $rowAssetId stays null and the
                // write path is identical to pre-216.
                $assetMap     = $this->persistAppointmentAssets($appointment, $customer, $data, $tenantId);
                $firstAssetId = !empty($assetMap) ? array_values($assetMap)[0]->id : null;

                foreach ($plan as $row) {
                    $service = $row['service'];

                    $rowAssetId = null;
                    if (!empty($assetMap)) {
                        $key        = $row['asset_client_key'] ?? null;
                        $rowAssetId = ($key !== null && isset($assetMap[$key]))
                            ? $assetMap[$key]->id   // tagged + known key
                            : $firstAssetId;        // untagged / orphan key -> first asset (WP parity)
                    }

                    TenantAppointmentItem::create([
                        'appointment_asset_id'           => $rowAssetId, // MARKER-PATCH-216
                        'id'                             => (string) Str::uuid(),
                        'appointment_id'                 => $appointment->id,
                        'service_item_id'                => $service->id,
                        'item_name_snapshot'             => $service->name,
                        'price_cents'                    => $service->price_cents,
                        'price_cents_override'           => $row['price_override_cents'] ?? null,
                        'duration_minutes_snapshot'      => $service->duration_minutes,
                        'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                        'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
                    ]);
                    foreach ($row['addons'] as $addonRow) {
                        TenantAppointmentAddon::create([
                            'id'                        => (string) Str::uuid(),
                            'appointment_id'            => $appointment->id,
                            'appointment_asset_id'      => $rowAssetId, // MARKER-PATCH-216
                            'addon_id'                  => $addonRow['addon']->id,
                            'addon_name_snapshot'       => $addonRow['addon']->name,
                            'price_cents'               => $addonRow['effective_price_cents'],
                            'duration_minutes_snapshot' => $addonRow['effective_duration'],
                        ]);
                    }
                }

                // MARKER-PATCH-216 — denormalized per-asset rollups for the
                // admin right-rail. Small N (bikes on one booking), in-txn.
                foreach ($assetMap as $aa) {
                    $aa->refreshSubtotal();
                }

                $this->persistResponses($appointment, $data);

                // Dispatch the booking-confirmation notification.
                // afterCommit() ensures the job only fires if the DB transaction
                // actually commits — never send a confirmation for a phantom
                // appointment that got rolled back by a later error in the chain.
                SendBookingConfirmationJob::dispatch($appointment->id)->afterCommit();

                // MARKER-PATCH-225 — staff alert. emit() defers its own work
                // to afterCommit, so a rolled-back booking emits nothing.
                $tenantModel = \App\Models\Tenant::find($tenantId);
                if ($tenantModel) {
                    $custName = trim(($appointment->customer->first_name ?? '') . ' ' . ($appointment->customer->last_name ?? '')) ?: 'A customer';
                    $whenLabel = $appointment->appointment_date
                        ? \Illuminate\Support\Carbon::parse($appointment->appointment_date)->format('M j')
                            . ($appointment->appointment_time ? ' at ' . \Illuminate\Support\Carbon::parse($appointment->appointment_time)->format('g:i A') : '')
                        : 'soon';
                    app(\App\Services\Tenant\StaffAlertService::class)->emit($tenantModel, 'booking.created', [
                        'title' => 'New booking',
                        'body'  => $custName . ' booked ' . $whenLabel,
                        'link'  => route('tenant.appointments.show', $appointment->id),
                        'meta'  => ['appointment_id' => $appointment->id],
                    ]);
                }

                // MARKER-PATCH-512 — Pickup & delivery: the booked pickup stop.
                // Runs for both public paths (direct + pending->materialize) since
                // both funnel the raw payload through createAppointment.
                if (!empty($data['route_window_id'])) {
                    $this->createPickupStop($appointment, (string) $data['route_window_id'], (array) $data);
                } elseif (!empty($data['pickup_outreach'])) {
                    // MARKER-PICKUP-OUTREACH — customer skipped the window
                    // choice and asked to be contacted about pickup.
                    $appointment->forceFill(['pickup_outreach_pending' => true])->save();
                    $outreachTenant = \App\Models\Tenant::find($tenantId);
                    if ($outreachTenant) {
                        $outreachName = trim(($appointment->customer->first_name ?? '') . ' ' . ($appointment->customer->last_name ?? '')) ?: 'A customer';
                        app(\App\Services\Tenant\StaffAlertService::class)->emit($outreachTenant, 'booking.pickup_outreach', [
                            'title' => 'Pickup to arrange',
                            'body'  => $outreachName . ' asked you to reach out about pickup for their booking',
                            'link'  => route('tenant.appointments.show', $appointment->id),
                            'meta'  => ['appointment_id' => $appointment->id],
                        ]);
                    }
                }
                if (!empty($data['need_by']) && (bool) (((array) $tenant->settings)['pd_need_by_enabled'] ?? true)) {
                    $appointment->forceFill(['need_by' => $data['need_by']])->save();
                }

                return $appointment->fresh(['items', 'addons', 'customer', 'responses']);
            });
        });
    }

    /**
     * MARKER-PATCH-384 — Reserve a slot with a pending hold (charge-then-create
     * step). Mirrors createAppointment's prep exactly, via the same helpers, but
     * writes a TenantPendingBooking instead of an appointment. The appointment is
     * only materialized after payment succeeds.
     */
    public function reserve(array $data, string $tenantId): \App\Models\Tenant\TenantPendingBooking
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one service is required.');
        }

        $plan = $this->buildBookingPlan($data['items'], $tenantId);

        $totalCents        = 0;
        $totalDuration     = 0;
        $customerFacingDur = 0;
        $slotWeight        = 0;
        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents        += (int) ($row['effective_price_cents'] ?? $service->price_cents);
            $customerFacingDur += (int) $service->duration_minutes;
            $totalDuration     += (int) $service->prep_before_minutes
                                + (int) $service->duration_minutes
                                + (int) $service->cleanup_after_minutes;
            $slotWeight        += (int) ($service->slot_weight ?? 1);
            foreach ($row['addons'] as $addonRow) {
                $totalCents        += (int) $addonRow['effective_price_cents'];
                $totalDuration     += (int) $addonRow['effective_duration'];
                $customerFacingDur += (int) $addonRow['effective_duration'];
            }
        }

        $appointmentTime = !empty($data['appointment_time']) ? $data['appointment_time'] : null;
        $resourceId      = !empty($data['resource_id'])      ? $data['resource_id']      : null;

        if ($resourceId !== null) {
            $resourceOk = \App\Models\Tenant\TenantResource::where('id', $resourceId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();
            if (!$resourceOk) {
                throw new RuntimeException('That selection is no longer available. Please pick another.');
            }
        }

        $tenant = Tenant::findOrFail($tenantId);
        $mode   = $tenant->booking_mode ?? 'drop_off';

        if ($mode === 'drop_off' && $resourceId === null) {
            $candidateIds = null;
            foreach ($plan as $row) {
                $svcEligible  = $this->eligibleResourcesForService($tenantId, $row['service']->id);
                $candidateIds = $candidateIds === null
                    ? $svcEligible
                    : array_values(array_intersect($candidateIds, $svcEligible));
                if (empty($candidateIds)) break;
            }
            if (empty($candidateIds)) {
                throw new RuntimeException(
                    'No staff member can perform every selected service. '
                    . 'Please pick a different combination.'
                );
            }
            $candidates = \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereIn('id', $candidateIds)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'max_appointments_per_day']);
            $picked = null;
            foreach ($candidates as $cand) {
                if ($cand->max_appointments_per_day === null) { $picked = $cand->id; break; }
                $used = $this->resourceUsedSlotsForDate($tenantId, $cand->id, $data['date']);
                if (($used + $slotWeight) <= (int) $cand->max_appointments_per_day) { $picked = $cand->id; break; }
            }
            if ($picked === null) {
                throw new RuntimeException('All staff are fully booked on that date. Please pick another day.');
            }
            $resourceId = $picked;
        }

        $lockKey = $this->computeLockKey($mode, $tenantId, $data['date'], $appointmentTime, $resourceId);
        $lock    = app(MySQLLock::class);

        return $lock->withLock($lockKey, function () use (
            $tenant, $mode, $data, $tenantId, $resourceId, $appointmentTime,
            $customerFacingDur, $slotWeight, $totalDuration, $totalCents
        ) {
            if ($mode === 'time_slots' && $appointmentTime) {
                $openSlots = $this->availableSlotsForDate($tenant, $data['date'], $resourceId, $customerFacingDur);
                $wanted = substr($appointmentTime, 0, 5);
                if (!in_array($wanted, $openSlots, true)) {
                    throw new RuntimeException('That time slot was just taken. Please pick another.');
                }
            }

            return \App\Models\Tenant\TenantPendingBooking::create([
                'id'                     => (string) Str::uuid(),
                'tenant_id'              => $tenantId,
                'token'                  => (string) Str::uuid(),
                'status'                 => 'pending',
                'resource_id'            => $resourceId,
                'booking_date'           => $data['date'],
                'appointment_time'       => $appointmentTime,
                'slot_weight'            => $slotWeight,
                'total_duration_minutes' => $totalDuration,
                'total_cents'            => $totalCents,
                'payload'                => $data,
                'contact_email'          => strtolower(trim((string) ($data['email'] ?? ''))),
                'contact_name'           => trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? ''))),
                'expires_at'             => now()->addMinutes(20),
            ]);
        });
    }

    /**
     * MARKER-PATCH-384 — Materialize a paid hold into a real appointment. Wraps
     * the unchanged createAppointment so the appointment write stays on the
     * battle-tested path. Idempotent: a hold already materialized returns its
     * appointment. The hold is flipped to 'materialized' before the write so the
     * slot re-check inside createAppointment doesn't see this booking's own hold
     * as a conflict; a failed write rolls the flip back.
     */
    /**
     * MARKER-PATCH-512 — create the pickup route stop for a public booking.
     * Capacity re-checked under an advisory lock keyed tenant+window+date;
     * a full window throws (surfaces as booking failed, same as slot_taken).
     */
    protected function createPickupStop(TenantAppointment $appointment, string $windowId, array $data): void
    {
        $window = \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $appointment->tenant_id)
            ->where('is_active', true)
            ->find($windowId);
        if (! $window) {
            throw new RuntimeException('That pickup window is no longer available.');
        }

        // MARKER-PATCH-520 — the stop lands on pickup_date (a lead day),
        // falling back to the service date for older payloads.
        $date = \Carbon\Carbon::parse($data['pickup_date'] ?? $data['date']);
        if (! $window->runsOn($date)) {
            throw new RuntimeException('That pickup window does not run on the selected day.');
        }

        // MARKER-PATCH-524 — same-day pickups are opt-in; reject a pickup dated
        // on the service day itself when the tenant hasn't enabled them.
        $tenantSettings = (array) (\App\Models\Tenant::find($appointment->tenant_id)?->settings ?? []);
        $allowDayOf = (bool) ($tenantSettings['pd_allow_day_of'] ?? false);
        if (! $allowDayOf && isset($data['date']) && $date->toDateString() === \Carbon\Carbon::parse($data['date'])->toDateString()) {
            throw new RuntimeException('Same-day pickup is not available — please pick an earlier window.');
        }

        $lockKey = 'pdwin:' . $appointment->tenant_id . ':' . $window->id . ':' . $date->toDateString();
        app(MySQLLock::class)->withLock($lockKey, function () use ($window, $date, $appointment) {
            if ($window->remainingStops($date) < 1) {
                throw new RuntimeException('That pickup window just filled up — please pick another.');
            }

            \App\Models\Tenant\TenantDelivery::create([
                'tenant_id'      => $appointment->tenant_id,
                'type'           => 'pickup',
                'status'         => 'scheduled',
                // MARKER-PATCH-513 — scheduled_at is a UTC instant; build the
                // wall-clock moment in the tenant tz, then convert.
                'scheduled_at'   => \Carbon\Carbon::parse($date->toDateString() . ' ' . (string) $window->starts_at,
                                        \App\Models\Tenant::find($appointment->tenant_id)?->timezone() ?? config('app.timezone'))->utc(),
                'window_minutes' => max(15, \Carbon\Carbon::parse((string) $window->starts_at)
                                        ->diffInMinutes(\Carbon\Carbon::parse((string) $window->ends_at))),
                'customer_id'    => $appointment->customer_id,
                'appointment_id' => $appointment->id,
                'address'        => $appointment->customer?->address ?: null,
                'notes'          => 'Booked online — ' . $window->label,
            ]);
        });
    }

    public function materialize(\App\Models\Tenant\TenantPendingBooking $pending): TenantAppointment
    {
        // Fast path: already materialized (no lock needed for the common case).
        if ($pending->status === 'materialized' && $pending->appointment_id) {
            return TenantAppointment::findOrFail($pending->appointment_id);
        }

        return DB::transaction(function () use ($pending) {
            // MARKER-PATCH-474 — lock the hold row so the browser finalize() and the
            // Stripe payment_intent.succeeded webhook can't both materialize the same
            // booking. Without this row lock, two concurrent calls each read status
            // 'pending' and each write an appointment (the double-booking bug).
            $locked = \App\Models\Tenant\TenantPendingBooking::where('id', $pending->id)
                ->lockForUpdate()
                ->first();

            if ($locked && $locked->status === 'materialized' && $locked->appointment_id) {
                return TenantAppointment::findOrFail($locked->appointment_id);
            }

            $locked->update(['status' => 'materialized']);
            $appointment = $this->createAppointment((array) $locked->payload, $locked->tenant_id);
            $locked->update(['appointment_id' => $appointment->id]);

            // MARKER-PATCH-474 — record the prepaid card charge through the same path a
            // backend appointment uses, so booking revenue reconciles into the one ledger.
            $this->recordPrepaidDeposit($locked, $appointment);

            return $appointment;
        });
    }

    /**
     * MARKER-PATCH-474 — Record a booking's prepaid card charge as a sale payment on
     * the appointment's balance sale, mirroring a backend-created appointment exactly:
     * AppointmentRegisterBridgeService builds the linked balance sale and
     * SalePaymentService writes the payment into tenant_sale_payments (the single
     * revenue ledger MoneyReportService reads). The appointment's paid state is then a
     * derived cache recomputed from that ledger, so it reads 'paid' the same way a
     * register/checkout payment would.
     *
     * Idempotent: keyed on the PaymentIntent id (reference_payment_id), so the browser
     * finalize() and the Stripe webhook can each reach this without double-posting.
     * No-ops for zero-total / pay-in-person holds (no PaymentIntent, nothing to record).
     */
    protected function recordPrepaidDeposit(
        \App\Models\Tenant\TenantPendingBooking $pending,
        TenantAppointment $appointment
    ): void {
        $piId   = (string) ($pending->stripe_payment_intent_id ?? '');
        $amount = (int) $pending->total_cents;

        if ($piId === '' || $amount <= 0) {
            return;
        }

        // Already on the ledger? Nothing to do (idempotent across finalize + webhook).
        if (\App\Models\Tenant\TenantSalePayment::where('tenant_id', $pending->tenant_id)
                ->where('reference_payment_id', $piId)
                ->exists()) {
            return;
        }

        // Build the balance-collection sale exactly as the backend does when an
        // appointment enters a committed status.
        $bridge = app(\App\Services\Tenant\AppointmentRegisterBridgeService::class);
        $result = $bridge->onAppointmentEnteringCommittedStatus($appointment);

        $sale = null;
        if (($result['action'] ?? null) === 'sale_created' && !empty($result['sale_id'])) {
            $sale = \App\Models\Tenant\TenantSale::find($result['sale_id']);
        }
        if (! $sale) {
            $sale = $appointment->sales()
                ->whereNotIn('status', ['cancelled', 'closed'])
                ->orderByDesc('created_at')
                ->first();
        }
        if (! $sale) {
            \Illuminate\Support\Facades\Log::error('booking.deposit_no_sale', [
                'tenant_id'      => $pending->tenant_id,
                'appointment_id' => $appointment->id,
                'pi'             => $piId,
            ]);
            return;
        }

        // MARKER-PREPAY-FK-FIX — $piId was passed positionally into
        // referencePaymentId (a self-FK to tenant_sale_payments.id). Harmless
        // until the overage-refund FK constraint landed; after it, EVERY
        // card-prepaid booking failed materialize with an FK violation and
        // the customer saw "time was just taken" while staying charged.
        // The PaymentIntent id belongs in externalReference. Named args so
        // this class of slip can't recur here.
        app(\App\Services\Tenant\SalePaymentService::class)->record(
            sale:               $sale,
            amountCents:        $amount,
            kind:               \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT,
            source:             \App\Models\Tenant\TenantSalePayment::SOURCE_BOOKING_FLOW,
            method:             'stripe',
            externalReference:  $piId,
            notes:              'Prepaid at online booking',
        );
    }

    /**
     * Computes the advisory-lock key for this booking attempt.
     *
     * Key must be <= 64 chars; MySQLLock normalizes via sha1 if it overflows.
     * UUIDs in the key push us close to the limit, so we use a compact format.
     */
    protected function computeLockKey(
        string $mode,
        string $tenantId,
        string $date,
        ?string $appointmentTime,
        ?string $resourceId
    ): string {
        // Trim tenant UUID to 8 chars for readability — still unique enough
        // that lock key collision between tenants is vanishingly unlikely,
        // and MySQLLock normalizes via sha1 anyway if this ever overflows.
        $tenantShort = substr($tenantId, 0, 8);

        if ($mode === 'time_slots' && $appointmentTime) {
            $resource = $resourceId ? substr($resourceId, 0, 8) : 'any';
            $slotKey  = str_replace([':', '-', ' '], '', $date . substr($appointmentTime, 0, 5));
            return "intake:{$tenantShort}:book:{$resource}:{$slotKey}";
        }

        // Drop-off mode or any path without appointment_time: per-day lock.
        $dayKey = str_replace('-', '', $date);
        return "intake:{$tenantShort}:drop:{$dayKey}";
    }

    // MARKER-PATCH-517 — optional $capacity out-param: per-date ['left','max'] (null = unbounded)
    public function availableDates(Tenant $tenant, int $year, int $month, ?string $serviceId = null, ?array &$capacity = null): array
    {
        $windowDays     = $tenant->booking_window_days ?? 60;
        $minNoticeHours = $tenant->min_notice_hours    ?? 24;
        $mode           = $tenant->booking_mode        ?? 'drop_off';

        // MARKER-TZ-WAVE1 — availability math runs on the TENANT's clock.
        // Bare now() (UTC) cut Pacific tenants off from the current business
        // day at 5 PM local and mis-rolled the min-notice boundary.
        $bkTz = $tenant->timezone();
        $earliest = now($bkTz)->addHours($minNoticeHours)->startOfDay();
        $latest   = now($bkTz)->addDays($windowDays)->endOfDay();
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $bkTz)->max($earliest);
        $end   = Carbon::create($year, $month, 1, 0, 0, 0, $bkTz)->endOfMonth()->min($latest);
        if ($start->gt($end)) return [];

        $defaults  = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')->get()->keyBy('day_of_week');
        $overrides = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn($r) => $r->specific_date->toDateString());

        // Service-aware path: when a service is passed, the relevant capacity
        // is only what its eligible resources can absorb. We scope both the
        // resource_cap_sum AND the per-day used count by those resources.
        // When no service is passed (legacy callers), behavior is unchanged.
        $eligibleResourceIds = null;
        if ($serviceId) {
            $eligibleResourceIds = $this->eligibleResourcesForService($tenant->id, $serviceId);
            if (empty($eligibleResourceIds)) {
                // Service has no eligible (active) resources — nothing is bookable.
                return [];
            }
        }

        // Sum of per-resource daily caps. When service-aware, only sum the caps
        // of eligible resources for that service.
        $resourceCapQuery = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereNotNull('max_appointments_per_day');
        if ($eligibleResourceIds !== null) {
            $resourceCapQuery->whereIn('id', $eligibleResourceIds);
        }
        $resourceCapSum = (int) $resourceCapQuery->sum('max_appointments_per_day');

        $available = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $dow = $cursor->dayOfWeek;
            $rule = $overrides[$dateStr] ?? $defaults[$dow] ?? null;
            if (!$rule) { $cursor->addDay(); continue; }

            // is_closed short-circuits all other capacity logic — closed means closed.
            if (!empty($rule->is_closed)) { $cursor->addDay(); continue; }

            // max_appointments meaning depends on mode:
            //   - drop_off:    shop-wide cap override (NULL = use resource sum)
            //   - time_slots:  optional cap on grid-derived capacity (NULL = no override)
            $shopOverride = isset($rule->max_appointments) && $rule->max_appointments !== null
                ? (int) $rule->max_appointments
                : null;

            if ($mode === 'drop_off') {
                // Effective cap = min(shop_override, resource_cap_sum) when both set.
                // If neither is set, day is unbounded (treated as 'no cap' — still available).
                $effectiveCap = null;
                if ($shopOverride !== null && $resourceCapSum > 0) {
                    $effectiveCap = min($shopOverride, $resourceCapSum);
                } elseif ($shopOverride !== null) {
                    $effectiveCap = $shopOverride;
                } elseif ($resourceCapSum > 0) {
                    $effectiveCap = $resourceCapSum;
                }
                // Cap of 0 means closed for this day.
                if ($effectiveCap === 0) { $cursor->addDay(); continue; }

                $usedQuery = TenantAppointment::where('tenant_id', $tenant->id)
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->where('appointment_date', $dateStr);
                if ($eligibleResourceIds !== null) {
                    $usedQuery->whereIn('resource_id', $eligibleResourceIds);
                }
                $used = (int) $usedQuery->sum('slot_weight');
                // null effectiveCap = unbounded, which keeps the day available.
                if ($effectiveCap === null || $used < $effectiveCap) {
                    $available[] = $dateStr;
                    // MARKER-PATCH-517
                    if ($capacity !== null) {
                        $capacity[$dateStr] = [
                            'left' => $effectiveCap === null ? null : max(0, $effectiveCap - $used),
                            'max'  => $effectiveCap,
                        ];
                    }
                }
            } else {
                // time_slots: availableSlotsForDate already honors $rule.
                // The shop override (if any) is applied by capping how many
                // distinct slots remain bookable, but the grid math — not
                // a shop-wide weight sum — is the primary gating factor.
                $slots = $this->availableSlotsForDate($tenant, $dateStr, null, 0, $rule);
                if (!empty($slots)) {
                    $available[] = $dateStr;
                    // MARKER-PATCH-517 — for slot mode, "left" = open times that day
                    if ($capacity !== null) {
                        $capacity[$dateStr] = ['left' => count($slots), 'max' => null];
                    }
                }
            }
            $cursor->addDay();
        }
        return $available;
    }

    /**
     * Service→Resource eligibility filter.
     *
     * Returns the active resources that can perform the given service item,
     * scoped to the tenant. EMPTY eligibility table for a service means
     * "any active resource" — specialization is opt-in. This lets the
     * BookingService fall through to whichever resource is free at lock
     * time without forcing tenants to maintain a per-service mapping if
     * they don't need one.
     *
     * Returns an array of resource UUIDs (not models) to keep the result
     * cheaply cacheable per request.
     */
    public function eligibleResourcesForService(string $tenantId, string $serviceId): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('tenant_service_resource_eligibility')
            ->where('tenant_id', $tenantId)
            ->where('service_item_id', $serviceId)
            ->pluck('resource_id')
            ->all();

        if (!empty($rows)) {
            // Filter to active only — a resource may have been deactivated
            // after the eligibility row was written, in which case we should
            // not propose it for new bookings.
            return \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereIn('id', $rows)
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        // Empty eligibility = all active resources are eligible.
        return \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }

    /**
     * Returns the count of active appointments using a given resource on a
     * given date (sum of slot_weight — services with weight > 1 cost more
     * against the resource's daily quota). Used by drop-off auto-fallback
     * to find a resource with remaining capacity.
     */
    public function resourceUsedSlotsForDate(string $tenantId, string $resourceId, string $date): int
    {
        // MARKER-PATCH-383 — count committed appointments plus active holds.
        $appt = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->sum('slot_weight');

        $held = (int) \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('booking_date', $date)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->sum('slot_weight');

        return $appt + $held;
    }

    /**
     * Returns an array of available start times for a given date.
     *
     * Scope:
     *  - If $resourceId is provided, returns slots where that specific resource is free.
     *  - If $resourceId is null, returns slots where ANY active resource is free.
     *    (single-resource shops and legacy callers get backward-compatible behavior)
     *  - $requiredMinutes ensures a slot can actually hold the full service duration,
     *    not just that the start time happens to be unoccupied.
     *
     * At 10K+ tenants this gets called hundreds of times per second on peak days.
     * Every query is tenant-scoped + date-scoped + (optionally) resource-scoped to
     * hit the composite index added in migration M2.
     */
    public function availableSlotsForDate(
        Tenant $tenant,
        string $date,
        ?string $resourceId = null,
        int $requiredMinutes = 0,
        $rule = null
    ): array {
        if (!$rule) {
            $dow = Carbon::parse($date)->dayOfWeek;
            $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')->where('day_of_week', $dow)->first();
        }
        if (!$rule || !$rule->open_time || !$rule->close_time) return [];

        $interval = (int) ($rule->slot_interval_minutes ?? 60);
        // A slot can only "hold" a service whose total time fits before close.
        // If caller didn't supply a minimum, fall back to one slot width.
        $effectiveRequired = $requiredMinutes > 0 ? $requiredMinutes : $interval;

        $open  = Carbon::parse($date . ' ' . $rule->open_time);
        $close = Carbon::parse($date . ' ' . $rule->close_time);

        // Pull all appointments touching this date, optionally scoped to one resource.
        // Index hit: (tenant_id, resource_id, appointment_date) when $resourceId is set,
        //            (tenant_id, appointment_date) when it's not.
        $bookedQuery = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time');

        if ($resourceId !== null) {
            $bookedQuery->where('resource_id', $resourceId);
        }

        $booked = $bookedQuery->get([
            'resource_id', 'appointment_time', 'appointment_end_time',
            'total_duration_minutes', 'prep_before_minutes_snapshot',
            'cleanup_after_minutes_snapshot',
        ]);

        // MARKER-PATCH-383 — active pending holds occupy their slot during the
        // card window. Shape them like booked rows (end derived from duration,
        // no prep/cleanup tail) and fold them into the overlap set.
        $holdQuery = \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenant->id)
            ->where('booking_date', $date)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereNotNull('appointment_time');
        if ($resourceId !== null) {
            $holdQuery->where('resource_id', $resourceId);
        }
        $holds = $holdQuery->get(['resource_id', 'appointment_time', 'total_duration_minutes'])
            ->map(function ($h) {
                return (object) [
                    'resource_id'                    => $h->resource_id,
                    'appointment_time'               => $h->appointment_time,
                    'appointment_end_time'           => null,
                    'total_duration_minutes'         => (int) $h->total_duration_minutes,
                    'prep_before_minutes_snapshot'   => 0,
                    'cleanup_after_minutes_snapshot' => 0,
                ];
            });
        $booked = $booked->concat($holds);

        // Gather breaks and walk-in holds that apply on this date.
        // Both return arrays of ['starts_at' => Carbon, 'ends_at' => Carbon, 'resource_id' => ?string].
        $breakWindows = $this->breaksForDate($tenant->id, $date, $resourceId);
        $holdWindows  = $this->holdsForDate($tenant->id, $date, $resourceId);

        // When caller did not specify a resource, the caller wants "any resource works".
        // Count active resources so we know how many concurrent bookings are tolerable
        // per slot before that slot is fully occupied.
        $activeResourceCount = 1;
        if ($resourceId === null) {
            $activeResourceCount = \App\Models\Tenant\TenantResource::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->count();
            // Defensive: at least 1, so a freshly-installed tenant without resources
            // still gets computable slots instead of an empty list.
            $activeResourceCount = max($activeResourceCount, 1);
        }

        $slots = [];
        $cursor = $open->copy();
        while ($cursor->lt($close)) {
            $slotStart = $cursor->copy();
            // Check whether the FULL required duration fits before close.
            $slotEnd = $slotStart->copy()->addMinutes($effectiveRequired);
            if ($slotEnd->gt($close)) break;

            $overlapping = $booked->filter(function ($appt) use ($date, $slotStart, $slotEnd, $interval) {
                $apptStart = Carbon::parse($date . ' ' . $appt->appointment_time);

                // Bookend-aware end: an appointment "occupies" its core duration
                // PLUS its cleanup tail. The prep tail is before appt_time so it
                // doesn't extend the end, but prep would affect the start of the
                // next appointment — which is handled by its own $apptStart shift below.
                $apptEnd = $appt->appointment_end_time
                    ? Carbon::parse($date . ' ' . $appt->appointment_end_time)
                    : $apptStart->copy()->addMinutes($appt->total_duration_minutes ?: $interval);
                $apptEnd = $apptEnd->copy()->addMinutes((int) ($appt->cleanup_after_minutes_snapshot ?? 0));

                // Shift apptStart back by any prep bookend — the resource is effectively
                // unavailable during prep, so the "occupied window" is wider than
                // [appt_time, appt_end].
                $apptStart = $apptStart->copy()->subMinutes((int) ($appt->prep_before_minutes_snapshot ?? 0));

                return $slotStart->lt($apptEnd) && $slotEnd->gt($apptStart);
            });

            // When resource-scoped: any overlap = slot blocked.
            // When any-resource: slot is blocked only when ALL resources are busy.
            if ($resourceId !== null) {
                $blocked = $overlapping->isNotEmpty();
            } else {
                $busyResourceIds = $overlapping->pluck('resource_id')->filter()->unique();
                $blocked = $busyResourceIds->count() >= $activeResourceCount;
            }

            // Breaks: if ANY break window for this resource (or shop-wide) overlaps
            // the slot, the slot is blocked regardless of appointment count.
            // A shop-wide break (resource_id = null) blocks every resource.
            if (!$blocked) {
                foreach ($breakWindows as $bw) {
                    if ($resourceId !== null
                        && $bw['resource_id'] !== null
                        && $bw['resource_id'] !== $resourceId) {
                        continue;  // this break is for a different specific resource
                    }
                    if ($slotStart->lt($bw['ends_at']) && $slotEnd->gt($bw['starts_at'])) {
                        $blocked = true;
                        break;
                    }
                }
            }

            // Walk-in holds: reserve capacity for walk-in customers. Online
            // bookings cannot claim a hold window until the hold is released
            // (auto_release_at in the past) or converted.
            if (!$blocked) {
                foreach ($holdWindows as $hw) {
                    if ($resourceId !== null && $hw['resource_id'] !== $resourceId) {
                        continue;
                    }
                    if ($slotStart->lt($hw['ends_at']) && $slotEnd->gt($hw['starts_at'])) {
                        $blocked = true;
                        break;
                    }
                }
            }

            if (!$blocked) $slots[] = $slotStart->format('H:i');
            $cursor->addMinutes($interval);
        }

        return $slots;
    }

    /**
     * Walk forward day-by-day to find the earliest available slot for a service
     * of the given duration. Optionally scoped to a specific resource. Cached
     * in Redis (60s TTL) keyed by tenant + duration + resource.
     *
     * Returns: ['date' => 'Y-m-d', 'time' => 'H:i', 'resource_id' => ?string]
     *          or null if nothing fits within $maxDaysAhead.
     */
    public function nextAvailableSlot(
        Tenant $tenant,
        int $requiredMinutes,
        ?string $resourceId = null,
        ?int $maxDaysAhead = null
    ): ?array {
        $maxDaysAhead = $maxDaysAhead ?? ($tenant->booking_window_days ?? 60);

        $cacheKey = sprintf(
            'avail:next:%s:%d:%s',
            $tenant->id,
            $requiredMinutes,
            $resourceId ?? 'any'
        );

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'NULL_SENTINEL' ? null : $cached;
        }

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $earliest = now($tenant->timezone())->addHours($minNoticeHours); // MARKER-TZ-WAVE1

        $cursor = $earliest->copy()->startOfDay();
        $stopAt = $earliest->copy()->addDays($maxDaysAhead);

        while ($cursor->lte($stopAt)) {
            $date = $cursor->toDateString();
            $slots = $this->availableSlotsForDate(
                $tenant,
                $date,
                $resourceId,
                $requiredMinutes
            );

            if ($cursor->isSameDay($earliest)) {
                $earliestTime = $earliest->format('H:i');
                $slots = array_values(array_filter($slots, fn($t) => $t >= $earliestTime));
            }

            if (!empty($slots)) {
                $result = [
                    'date'        => $date,
                    'time'        => $slots[0],
                    'resource_id' => $resourceId,
                ];
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 60);
                return $result;
            }
            $cursor->addDay();
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, 'NULL_SENTINEL', 60);
        return null;
    }

    /**
     * Like nextAvailableSlot, but returns one entry per active resource.
     * Sorted by earliest-available first.
     */
    public function nextAvailablePerResource(
        Tenant $tenant,
        int $requiredMinutes,
        ?int $maxDaysAhead = null
    ): array {
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        $out = [];
        foreach ($resources as $r) {
            $slot = $this->nextAvailableSlot($tenant, $requiredMinutes, $r->id, $maxDaysAhead);
            if ($slot) {
                $out[] = [
                    'resource_id' => $r->id,
                    'name'        => $r->name,
                    'date'        => $slot['date'],
                    'time'        => $slot['time'],
                ];
            }
        }

        usort($out, function ($a, $b) {
            if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
            return strcmp($a['time'], $b['time']);
        });

        return $out;
    }

    /**
     * For a window of N days starting from $startDate, return the count of
     * available slots per day plus a status (open/closed/past/full/beyond_window).
     */
    public function dayCounts(
        Tenant $tenant,
        int $requiredMinutes,
        string $startDate,
        int $days = 7
    ): array {
        $cacheKey = sprintf(
            'avail:counts:%s:%d:%s:%d',
            $tenant->id,
            $requiredMinutes,
            $startDate,
            $days
        );

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $minNoticeAt = now()->addHours($minNoticeHours);
        $windowDays = (int) ($tenant->booking_window_days ?? 60);
        $windowEnd = now()->addDays($windowDays);

        $cursor = \Carbon\Carbon::parse($startDate)->startOfDay();
        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->toDateString();
            $entry = ['date' => $date, 'count' => 0, 'status' => 'open'];

            if ($cursor->isPast() && !$cursor->isToday()) {
                $entry['status'] = 'past';
            }
            elseif ($cursor->gt($windowEnd)) {
                $entry['status'] = 'beyond_window';
            }
            else {
                $slots = $this->availableSlotsForDate($tenant, $date, null, $requiredMinutes);

                if ($cursor->isToday()) {
                    $earliestTime = $minNoticeAt->format('H:i');
                    $slots = array_values(array_filter($slots, fn($t) => $t >= $earliestTime));
                }

                $count = count($slots);
                $entry['count'] = $count;

                if ($count === 0) {
                    $entry['status'] = $cursor->isToday() ? 'full' : 'closed';
                }
            }

            $out[] = $entry;
            $cursor->addDay();
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, $out, 60);
        return $out;
    }

    /**
     * Find the first active resource that's free for the given window.
     * Used by the day-strip auto-assign flow.
     */
    public function resolveResourceForSlot(
        Tenant $tenant,
        string $date,
        string $time,
        int $requiredMinutes
    ): ?string {
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id']);

        foreach ($resources as $r) {
            $slots = $this->availableSlotsForDate($tenant, $date, $r->id, $requiredMinutes);
            if (in_array($time, $slots, true)) {
                return $r->id;
            }
        }

        return null;
    }

    /**
     * Expand break records into concrete time windows for a given date.
     * Handles one-offs and recurring (daily/weekly/monthly) records.
     *
     * Returns: array of ['starts_at' => Carbon, 'ends_at' => Carbon, 'resource_id' => ?string]
     *
     * At scale: queries are indexed by (tenant_id, resource_id, starts_at).
     * The recurrence expansion is O(breaks) per call — fine for the <50 breaks
     * any single tenant will have. If a tenant ever has 500+ breaks we revisit.
     */
    /**
     * Focused availability check: is $resourceId free for the given window
     * on the given date? Used by AppointmentController::change_resource to
     * detect conflicts when reassigning an appointment to a different
     * resource without loading the full slot list.
     *
     * Window is [startTime, startTime + durationMinutes), expressed as
     * an H:i:s string + integer minutes. Excludes $excludeAppointmentId
     * so the appointment doesn't conflict with itself.
     *
     * Returns null if the slot is free, or the conflicting appointment
     * (lightweight payload) if not. Caller decides how to surface that.
     *
     * Conflicts checked against:
     *   - Other active appointments on this resource overlapping the window
     *   - Breaks scoped to this resource (or shop-wide breaks)
     *   - Walk-in holds on this resource
     *
     * NOT checked: business hours, slot interval alignment. Caller already
     * has those validated by virtue of the appointment existing — moving
     * resources doesn't change the time, so hours/intervals don't need
     * re-validation.
     */
    public function resourceIsFreeDuring(
        string $tenantId,
        string $resourceId,
        string $date,
        string $startTime,
        int $durationMinutes,
        ?string $excludeAppointmentId = null
    ): ?array {
        $windowStart = Carbon::parse($date . ' ' . $startTime);
        $windowEnd   = $windowStart->copy()->addMinutes(max(1, $durationMinutes));

        // Active appointments on this resource for this date
        $query = TenantAppointment::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time');

        if ($excludeAppointmentId) {
            $query->where('id', '!=', $excludeAppointmentId);
        }

        $candidates = $query->get([
            'id', 'ra_number', 'customer_first_name', 'customer_last_name',
            'appointment_time', 'appointment_end_time', 'total_duration_minutes',
            'cleanup_after_minutes_snapshot',
        ]);

        foreach ($candidates as $appt) {
            $apptStart = Carbon::parse($date . ' ' . $appt->appointment_time);

            // End = end_time if set; otherwise compute from start + duration + cleanup tail.
            // Mirrors the bookend-aware overlap logic in availableSlotsForDate.
            if ($appt->appointment_end_time) {
                $apptEnd = Carbon::parse($date . ' ' . $appt->appointment_end_time);
            } else {
                $totalMin = (int) $appt->total_duration_minutes
                          + (int) ($appt->cleanup_after_minutes_snapshot ?? 0);
                $apptEnd = $apptStart->copy()->addMinutes(max(1, $totalMin));
            }

            // Overlap = (windowStart < apptEnd) AND (windowEnd > apptStart)
            if ($windowStart->lt($apptEnd) && $windowEnd->gt($apptStart)) {
                return [
                    'kind'              => 'appointment',
                    'id'                => $appt->id,
                    'ra_number'         => $appt->ra_number,
                    'customer_name'     => trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')),
                    'starts_at'         => $apptStart->format('g:i a'),
                    'ends_at'           => $apptEnd->format('g:i a'),
                ];
            }
        }

        // Breaks — pull just the ones for this resource (or shop-wide) on this date
        $breaks = $this->breaksForDate($tenantId, $date, $resourceId);
        foreach ($breaks as $br) {
            $brStart = Carbon::parse($br['starts_at']);
            $brEnd   = Carbon::parse($br['ends_at']);
            if ($windowStart->lt($brEnd) && $windowEnd->gt($brStart)) {
                return [
                    'kind'      => 'break',
                    'label'     => $br['label'] ?? 'Break',
                    'starts_at' => $brStart->format('g:i a'),
                    'ends_at'   => $brEnd->format('g:i a'),
                ];
            }
        }

        // Walk-in holds — same shape as breaks
        $holds = $this->holdsForDate($tenantId, $date, $resourceId);
        foreach ($holds as $h) {
            $hStart = Carbon::parse($h['starts_at']);
            $hEnd   = Carbon::parse($h['ends_at']);
            if ($windowStart->lt($hEnd) && $windowEnd->gt($hStart)) {
                return [
                    'kind'      => 'hold',
                    'label'     => $h['label'] ?? 'Walk-in hold',
                    'starts_at' => $hStart->format('g:i a'),
                    'ends_at'   => $hEnd->format('g:i a'),
                ];
            }
        }

        return null;
    }

    protected function breaksForDate(string $tenantId, string $date, ?string $resourceId): array
    {
        $target = Carbon::parse($date);

        // Fetch all potentially-applicable breaks: one-offs on this date,
        // plus any recurring break still active (recurrence_until >= date or null).
        // We filter by recurrence matching in PHP — doing it in SQL would require
        // JSON operators that vary by MySQL version and kill portability.
        $query = TenantCalendarBreak::where('tenant_id', $tenantId)
            ->where(function ($q) use ($target) {
                $q->where(function ($q2) use ($target) {
                    // One-off on this specific date
                    $q2->where('is_recurring', false)
                       ->whereDate('starts_at', $target->toDateString());
                })->orWhere(function ($q2) use ($target) {
                    // Recurring, still within its active window
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $target->copy()->endOfDay())
                       ->where(function ($q3) use ($target) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $target->toDateString());
                       });
                });
            });

        // Narrow to resource-specific + shop-wide breaks.
        // Shop-wide (resource_id IS NULL) always apply.
        if ($resourceId !== null) {
            $query->where(function ($q) use ($resourceId) {
                $q->whereNull('resource_id')->orWhere('resource_id', $resourceId);
            });
        }

        $records = $query->get([
            'resource_id', 'starts_at', 'ends_at',
            'is_recurring', 'recurrence_type', 'recurrence_config',
        ]);

        $windows = [];
        foreach ($records as $br) {
            if (!$br->is_recurring) {
                // One-off: use the stored datetimes directly.
                $windows[] = [
                    'starts_at'   => $br->starts_at,
                    'ends_at'     => $br->ends_at,
                    'resource_id' => $br->resource_id,
                ];
                continue;
            }

            if (!$this->recurrenceAppliesOnDate($br->recurrence_type, $br->recurrence_config, $target)) {
                continue;
            }

            // Shift the stored time-of-day onto the target date.
            $origStart = Carbon::parse($br->starts_at);
            $origEnd   = Carbon::parse($br->ends_at);
            $windows[] = [
                'starts_at'   => $target->copy()->setTimeFromTimeString($origStart->format('H:i:s')),
                'ends_at'     => $target->copy()->setTimeFromTimeString($origEnd->format('H:i:s')),
                'resource_id' => $br->resource_id,
            ];
        }

        return $windows;
    }

    /**
     * Walk-in holds for a date, excluding converted ones and released ones.
     * Same recurrence logic as breaks; resource_id is never null for holds
     * (holds are always tied to a specific resource).
     */
    protected function holdsForDate(string $tenantId, string $date, ?string $resourceId): array
    {
        $target = Carbon::parse($date);
        $now    = now();

        $query = TenantWalkinHold::where('tenant_id', $tenantId)
            ->whereNull('converted_at')  // converted holds don't block — they became appointments
            ->where(function ($q) use ($now) {
                // Not auto-released yet (or no auto-release set)
                $q->whereNull('auto_release_at')->orWhere('auto_release_at', '>', $now);
            })
            ->where(function ($q) use ($target) {
                $q->where(function ($q2) use ($target) {
                    $q2->where('is_recurring', false)
                       ->whereDate('starts_at', $target->toDateString());
                })->orWhere(function ($q2) use ($target) {
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $target->copy()->endOfDay())
                       ->where(function ($q3) use ($target) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $target->toDateString());
                       });
                });
            });

        if ($resourceId !== null) {
            $query->where('resource_id', $resourceId);
        }

        $records = $query->get([
            'resource_id', 'starts_at', 'ends_at',
            'is_recurring', 'recurrence_type', 'recurrence_config',
        ]);

        $windows = [];
        foreach ($records as $hw) {
            if (!$hw->is_recurring) {
                $windows[] = [
                    'starts_at'   => $hw->starts_at,
                    'ends_at'     => $hw->ends_at,
                    'resource_id' => $hw->resource_id,
                ];
                continue;
            }

            if (!$this->recurrenceAppliesOnDate($hw->recurrence_type, $hw->recurrence_config, $target)) {
                continue;
            }

            $origStart = Carbon::parse($hw->starts_at);
            $origEnd   = Carbon::parse($hw->ends_at);
            $windows[] = [
                'starts_at'   => $target->copy()->setTimeFromTimeString($origStart->format('H:i:s')),
                'ends_at'     => $target->copy()->setTimeFromTimeString($origEnd->format('H:i:s')),
                'resource_id' => $hw->resource_id,
            ];
        }

        return $windows;
    }

    /**
     * Does a recurrence record apply on the given target date?
     * Supports: daily, weekly (days of week), monthly (day of month).
     *
     * recurrence_config shapes:
     *   daily:   null  (or {}; we treat as "every day")
     *   weekly:  {"days": ["mon", "tue", "thu"]}
     *   monthly: {"day_of_month": 15}
     */
    protected function recurrenceAppliesOnDate(?string $type, $config, Carbon $target): bool
    {
        if ($type === 'daily') {
            return true;
        }

        if ($type === 'weekly') {
            $days = is_array($config) ? ($config['days'] ?? []) : [];
            if (!is_array($days) || empty($days)) return false;
            $targetDow = strtolower($target->format('D'));  // 'mon','tue','wed',...
            $targetDow = substr($targetDow, 0, 3);
            $normalized = array_map(fn($d) => strtolower(substr((string) $d, 0, 3)), $days);
            return in_array($targetDow, $normalized, true);
        }

        if ($type === 'monthly') {
            $dayOfMonth = is_array($config) ? (int) ($config['day_of_month'] ?? 0) : 0;
            if ($dayOfMonth < 1 || $dayOfMonth > 31) return false;
            return (int) $target->format('j') === $dayOfMonth;
        }

        return false;
    }

    protected function buildBookingPlan(array $items, string $tenantId): array
    {
        $plan = [];
        foreach ($items as $idx => $sel) {
            if (empty($sel['service_item_id'])) {
                throw new RuntimeException("Item #{$idx} missing service_item_id.");
            }
            $service = TenantServiceItem::where('id', $sel['service_item_id'])
                ->where('tenant_id', $tenantId)->where('is_active', true)
                ->with(['serviceAddons.addon'])->first();
            if (!$service) {
                throw new RuntimeException("Service not found or inactive: {$sel['service_item_id']}");
            }

            $addonIds = isset($sel['addon_ids']) && is_array($sel['addon_ids'])
                ? array_values(array_unique($sel['addon_ids'])) : [];
            $addonRows = [];

            if ($addonIds) {
                $pivotsByAddonId = $service->serviceAddons->keyBy('addon_id');
                foreach ($addonIds as $addonId) {
                    $pivot = $pivotsByAddonId->get($addonId);
                    if (!$pivot || !$pivot->addon) {
                        throw new RuntimeException("Add-on {$addonId} is not available for service {$service->name}.");
                    }
                    if (!$pivot->addon->is_active) {
                        throw new RuntimeException("Add-on {$pivot->addon->name} is not active.");
                    }
                    $addonRows[] = [
                        'addon'                 => $pivot->addon,
                        'pivot'                 => $pivot,
                        'effective_price_cents' => (int) $pivot->effectivePriceCents(),
                        'effective_duration'    => (int) $pivot->effectiveDuration(),
                    ];
                }
            }
            // Optional per-item price override (admin/staff-create flow).
            // Null means use service catalog price. Negative or > 9999999 rejected.
            $override = $sel['price_override_cents'] ?? null;
            if ($override !== null) {
                $override = (int) $override;
                if ($override < 0 || $override > 9999999) {
                    throw new RuntimeException("Item #{$idx} price override out of range.");
                }
            }
            $effectivePrice = $override ?? (int) $service->price_cents;

            $plan[] = [
                // MARKER-PATCH-216 — which bike/asset this item belongs to.
                'asset_client_key'       => isset($sel['asset_client_key']) && $sel['asset_client_key'] !== ''
                                                ? (string) $sel['asset_client_key'] : null,
                'service'                => $service,
                'addons'                 => $addonRows,
                'price_override_cents'   => $override,
                'effective_price_cents'  => $effectivePrice,
            ];
        }
        return $plan;
    }

    /**
     * MARKER-PATCH-216 — persist the multi-asset payload for a public booking.
     *
     * For each entry in assets[]:
     *   - customer_asset_id present -> verify ownership (this tenant + this
     *     customer + not archived). Failed verification is treated as a NEW
     *     asset rather than an error (mirrors the WP REST handler).
     *   - otherwise -> create a persistent TenantCustomerAsset.
     *
     * Creates one TenantAppointmentAsset per entry (name/identifier snapshot,
     * sort_order 10/20/30...) and returns clientKey -> model so the caller can
     * tag items/addons. Entries without a client_key are skipped — nothing in
     * items[] could reference them. Returns [] in single-asset mode.
     */
    protected function persistAppointmentAssets(
        TenantAppointment $appointment,
        TenantCustomer $customer,
        array $data,
        string $tenantId
    ): array {
        $assets = $data['assets'] ?? null;
        if (!is_array($assets) || empty($assets)) {
            return [];
        }

        $map  = [];
        $sort = 10;

        foreach ($assets as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $clientKey = isset($entry['client_key']) ? trim((string) $entry['client_key']) : '';
            if ($clientKey === '' || isset($map[$clientKey])) {
                continue;
            }

            $name = trim((string) ($entry['name_snapshot'] ?? ''));

            $customerAsset = null;
            $claimed = $entry['customer_asset_id'] ?? null;
            if (!empty($claimed)) {
                $customerAsset = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenantId)
                    ->where('customer_id', $customer->id)
                    ->where('id', $claimed)
                    ->whereNull('archived_at')
                    ->first();
            }

            if ($customerAsset) {
                // Existing asset: the account name is canon (the booking UI
                // renders it read-only). Keep last-seen fresh.
                $customerAsset->update([
                    'last_seen_at'        => now(),
                    'last_appointment_id' => $appointment->id,
                ]);
                $snapshotName = $customerAsset->name;
            } else {
                if ($name === '') {
                    $name = 'Item ' . (count($map) + 1);
                }
                $customerAsset = \App\Models\Tenant\TenantCustomerAsset::create([
                    'tenant_id'           => $tenantId,
                    'customer_id'         => $customer->id,
                    'name'                => $name,
                    'last_seen_at'        => now(),
                    'last_appointment_id' => $appointment->id,
                ]);
                $snapshotName = $name;
            }

            $map[$clientKey] = \App\Models\Tenant\TenantAppointmentAsset::create([
                'tenant_id'           => $tenantId,
                'appointment_id'      => $appointment->id,
                'customer_asset_id'   => $customerAsset->id,
                'asset_name_snapshot' => $snapshotName,
                'identifier_snapshot' => $customerAsset->identifier,
                'sort_order'          => $sort,
                'subtotal_cents'      => 0,
            ]);
            $sort += 10;
        }

        return $map;
    }

    protected function upsertCustomer(array $data, string $tenantId): TenantCustomer
    {
        // MARKER-PATCH-392 — standardize the phone once so every write below stores E.164.
        $data['phone'] = \App\Support\PhoneNumber::normalize($data['phone'] ?? null);

        $email = strtolower(trim($data['email'] ?? ''));
        if ($email === '') throw new RuntimeException('Customer email is required.');

        // MARKER-PATCH-216 — returning-customer path. The pre-flow lookup
        // hands the client a customer_id; verify it belongs to this tenant
        // before trusting it. Failed verification falls through to the email
        // canon below — the claimed id alone proves nothing.
        $claimedId = $data['customer_id'] ?? null;
        if (!empty($claimedId)) {
            $verified = TenantCustomer::where('tenant_id', $tenantId)
                ->where('id', $claimedId)
                ->first();
            if ($verified) {
                // Details fields are locked client-side for returning
                // customers, so name/email arrive unchanged. Phone stays
                // editable when the account has none — capture it.
                if (empty($verified->phone) && !empty($data['phone'])) {
                    $verified->phone = $data['phone'];
                    $verified->save();
                }
                return $verified;
            }
        }

        $customer = TenantCustomer::where('tenant_id', $tenantId)->where('email', $email)->first();
        if ($customer) {
            $customer->fill([
                'first_name' => $data['first_name'] ?? $customer->first_name,
                'last_name'  => $data['last_name']  ?? $customer->last_name,
                'phone'      => $data['phone']      ?? $customer->phone,
            ])->save();
            return $customer;
        }
        return TenantCustomer::create([
            'id'         => (string) Str::uuid(),
            'tenant_id'  => $tenantId,
            'first_name' => $data['first_name'] ?? '',
            'last_name'  => $data['last_name']  ?? '',
            'email'      => $email,
            'phone'      => $data['phone']      ?? null,
        ]);
    }

    protected function persistResponses(TenantAppointment $appointment, array $data): void
    {
        $responses = $data['responses'] ?? null;
        if (!is_array($responses) || empty($responses)) return;
        $labels = $data['response_labels'] ?? [];

        foreach ($responses as $questionKey => $value) {
            if ($value === null || $value === '' || $value === []) continue;
            \App\Models\Tenant\TenantAppointmentResponse::create([
                'id'                   => (string) Str::uuid(),
                'appointment_id'       => $appointment->id,
                'field_key_snapshot'   => (string) $questionKey,
                'field_label_snapshot' => $labels[$questionKey] ?? (string) $questionKey,
                'response_value'       => is_scalar($value) ? (string) $value : json_encode($value),
            ]);
        }
    }

    /**
     * MARKER-FAILED-PAID — a verified-paid pending that could not
     * materialize becomes a PERMANENT record plus a staff alert, whatever
     * the failure reason. Status transition is guarded so the browser and
     * webhook racing the same failure produce exactly one alert, and the
     * reap job never deletes failed_paid rows.
     */
    public static function recordFailedPaid(\App\Models\Tenant\TenantPendingBooking $pending, string $reason): void
    {
        try {
            $flipped = \App\Models\Tenant\TenantPendingBooking::whereKey($pending->id)
                ->where('status', '!=', 'failed_paid')
                ->update(['status' => 'failed_paid', 'updated_at' => now()]);
            if (! $flipped) return; // other finalize path already recorded it

            $tenant = \App\Models\Tenant::find($pending->tenant_id);
            if (! $tenant) return;
            $payload = is_array($pending->payload) ? $pending->payload : (json_decode((string) $pending->payload, true) ?: []);
            $name  = trim(($payload['first_name'] ?? '') . ' ' . ($payload['last_name'] ?? '')) ?: 'A customer';
            $email = $payload['email'] ?? null;
            $phone = $payload['phone'] ?? null;
            $date  = $payload['date'] ?? $pending->booking_date ?? 'unknown date';
            $cents = (int) ($pending->amount_cents ?? 0);

            app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'booking.failed_paid', [
                'title' => 'Paid booking needs attention',
                'body'  => sprintf(
                    '%s paid $%s for %s but the booking could not be completed (%s). Contact: %s%s · Stripe: %s',
                    $name,
                    number_format($cents / 100, 2),
                    $date,
                    \Illuminate\Support\Str::limit($reason, 90),
                    $email ?: 'no email',
                    $phone ? ' / ' . $phone : '',
                    $pending->stripe_payment_intent_id ?: 'n/a'
                ),
                'link'  => null,
                'meta'  => ['pending_id' => $pending->id, 'pi' => $pending->stripe_payment_intent_id],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('booking.failed_paid_record_error', ['pending_id' => $pending->id, 'error' => $e->getMessage()]);
        }
    }
}
HOLDREL_1_EOF

cat > 'app/Http/Controllers/Webhooks/DirectPaymentsWebhookController.php' <<'HOLDREL_2_EOF'
<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * MARKER-PATCH-170 — Direct Payments Session 2A.
 *
 * Path-scoped webhook: /webhooks/stripe-direct/{tenantId}
 *
 * Each tenant has their own Stripe account with its own webhook signing
 * secret. The URL carries the tenant ID so we know which secret to verify
 * the signature against.
 *
 * For 2A we mostly use this as a safety net — the primary success path
 * is the front-end calling /payment-intent/confirm synchronously after
 * Stripe.js confirms the card. The webhook ensures that if the user
 * closes their browser at the wrong moment, the sale still gets marked
 * paid when Stripe asynchronously confirms.
 *
 * Handles: payment_intent.succeeded. More handlers in 2B/2C.
 */
class DirectPaymentsWebhookController extends Controller
{
    public function handle(Request $request, string $tenantId)
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->direct_payments_enabled) {
            Log::warning('direct_payments_webhook.tenant_not_found_or_disabled', [
                'tenant_id' => $tenantId,
            ]);
            return response()->json(['error' => 'unknown tenant'], 404);
        }

        $s = $tenant->settings ?? [];
        $secret = $s['register_payments_webhook_secret'] ?? null;
        if (! $secret) {
            Log::error('direct_payments_webhook.no_secret', ['tenant_id' => $tenantId]);
            return response()->json(['error' => 'webhook not configured'], 500);
        }

        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('direct_payments_webhook.bad_signature', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'bad signature'], 400);
        } catch (\Throwable $e) {
            Log::error('direct_payments_webhook.parse_failed', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'parse failed'], 400);
        }

        try {
            $this->dispatch($event, $tenant);
        } catch (\Throwable $e) {
            Log::error('direct_payments_webhook.handler_failed', [
                'event_type' => $event->type ?? 'unknown',
                'event_id'   => $event->id ?? null,
                'tenant_id'  => $tenantId,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json(['received' => true]);
    }

    protected function dispatch(\Stripe\Event $event, Tenant $tenant): void
    {
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->onPaymentIntentSucceeded($event, $tenant);
                break;

            // MARKER-PATCH-171 — refunds initiated outside Intake (Stripe
            // dashboard, direct API call, our own refundCharge) all emit
            // charge.refunded. Sync state so a sale paid via card can\'t
            // show "paid" in Intake when Stripe says it\'s been refunded.
            case 'charge.refunded':
                $this->onChargeRefunded($event, $tenant);
                break;

            // MARKER-PATCH-172 — send-payment-link flow. When the customer
            // completes payment via the Stripe Checkout URL, we promote the
            // matching draft sale to paid.
            case 'checkout.session.completed':
                $this->onCheckoutSessionCompleted($event, $tenant);
                break;

            // Async payment success (Klarna, etc.) — same handler.
            case 'checkout.session.async_payment_succeeded':
                $this->onCheckoutSessionCompleted($event, $tenant);
                break;

            // MARKER-PATCH-193 — the link lapsed without payment. Mark the still-
            // unpaid sale expired so it stops showing as a live pending link.
            case 'checkout.session.expired':
                $this->onCheckoutSessionExpired($event, $tenant);
                break;

            default:
                Log::info('direct_payments_webhook.ignored_event', [
                    'type'      => $event->type,
                    'tenant_id' => $tenant->id,
                ]);
        }
    }

    /**
     * Safety net only. If the user\'s browser confirmed the payment and
     * called /payment-intent/confirm synchronously, the sale row already
     * has the stripe_payment_intent_id and we have nothing to do.
     *
     * If for some reason that flow didn\'t complete (browser closed, network
     * dropped) and the customer\'s card still authorized successfully, we
     * arrive here later. In that case the sale doesn\'t exist yet — we log
     * for investigation but don\'t create the sale automatically. Sales are
     * the source of truth and must originate from the register flow.
     */
    protected function onPaymentIntentSucceeded(\Stripe\Event $event, Tenant $tenant): void
    {
        $pi = $event->data->object;
        $piId = $pi->id;

        // MARKER-PATCH-566 — online store orders: the browser return leg
        // usually finalizes first; this is the backstop for closed tabs.
        // finalize() is lock-guarded idempotent, so double-delivery is safe.
        if (!empty($pi->metadata->intake_order_id ?? null)) {
            $order = \App\Models\Tenant\TenantOrder::where('tenant_id', $tenant->id)
                ->where('id', $pi->metadata->intake_order_id)
                ->with('items')->first();
            if ($order && ! $order->sale_id) {
                try {
                    $full = (new \App\Services\Tenant\DirectPaymentsService($tenant))->retrievePaymentIntent($piId);
                    \App\Services\Tenant\OrderService::forTenant($tenant)->finalize($order, $full);
                } catch (\Throwable $e) {
                    Log::error('online_order.webhook_finalize_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
                }
            }
            return; // online-order PIs never fall into the register/booking logic
        }

        // MARKER-PATCH-386 — Booking deposit backstop. If the card confirmed but
        // the browser never reached finalize() (closed tab, dropped network), the
        // appointment was never written. The PI carries pending_booking_id in
        // metadata; materialize the held booking here. Idempotent, and booking
        // PIs return early so they never fall into the sale logic below.
        $pendingId = $pi->metadata->pending_booking_id ?? null;
        if (!empty($pendingId)) {
            $pending = \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenant->id)
                ->where('stripe_payment_intent_id', $piId)
                ->first();
            if (!$pending) {
                Log::warning('direct_payments_webhook.booking_hold_not_found', [
                    'tenant_id' => $tenant->id, 'pi' => $piId, 'pending_id' => $pendingId,
                ]);
                return;
            }
            if ($pending->status === 'materialized') {
                return; // finalize() already handled it
            }
            try {
                app(\App\Services\BookingService::class)->materialize($pending);
                Log::info('direct_payments_webhook.booking_materialized_via_webhook', [
                    'tenant_id' => $tenant->id, 'pi' => $piId, 'pending_id' => $pending->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('direct_payments_webhook.booking_materialize_failed', [
                    'tenant_id' => $tenant->id, 'pi' => $piId, 'pending_id' => $pending->id,
                    'error'     => $e->getMessage(),
                ]);
                \App\Services\BookingService::recordFailedPaid($pending, $e->getMessage()); // MARKER-FAILED-PAID
            }
            return; // booking handled — not a sale
        }

        // Is this already linked to a sale?
        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', $piId)
            ->first();

        if ($sale) {
            // Already recorded — nothing to do
            return;
        }

        // Not linked yet — log for investigation. Possibilities:
        //   - The browser flow is still mid-confirm and hasn\'t called us yet
        //     (in which case it will, and the sale row will be created normally)
        //   - The flow failed client-side but Stripe still succeeded
        //     (rare; the customer was charged but we have no sale)
        Log::warning('direct_payments_webhook.pi_succeeded_no_sale', [
            'tenant_id' => $tenant->id,
            'pi'        => $piId,
            'amount'    => $pi->amount,
            'metadata'  => $pi->metadata ?? null,
        ]);
    }

    /**
     * MARKER-PATCH-171 — charge.refunded fires for every refund, whether
     * initiated from Intake or from the Stripe dashboard.
     *
     * Intake-initiated refunds already have the refund row + stripe_refund_id
     * stored synchronously (storeRefund + fireStripeRefund). We just log.
     *
     * Stripe-dashboard refunds arrive here without a corresponding Intake
     * refund row. We update the ORIGINAL sale\'s payment_status to 'refunded'
     * so the sale doesn\'t show stale "paid" in Intake. We do NOT auto-create
     * a refund row — that\'s a real reverse-inventory + accounting operation
     * the staff should do explicitly via the register.
     */
    protected function onChargeRefunded(\Stripe\Event $event, Tenant $tenant): void
    {
        $charge = $event->data->object;
        $piId = $charge->payment_intent ?? null;
        if (! $piId) {
            Log::info('direct_payments_webhook.charge_refunded_no_pi', [
                'tenant_id' => $tenant->id,
                'charge_id' => $charge->id ?? null,
            ]);
            return;
        }

        $original = TenantSale::where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', $piId)
            ->whereNull('refund_of_sale_id') // only update the original, not refund rows
            ->first();

        if (! $original) {
            Log::info('direct_payments_webhook.charge_refunded_unknown_sale', [
                'tenant_id' => $tenant->id,
                'pi'        => $piId,
            ]);
            return;
        }

        // Check for an Intake-recorded refund covering this charge. If one
        // already exists, this event is a duplicate (we initiated it) and we
        // don\'t need to mutate state.
        $hasIntakeRefund = TenantSale::where('tenant_id', $tenant->id)
            ->where('refund_of_sale_id', $original->id)
            ->exists();

        if ($hasIntakeRefund) {
            return;
        }

        // Stripe-dashboard refund with no matching Intake row. Mark the
        // original as refunded so it doesn\'t look paid anymore.
        $refundedAmount = (int) ($charge->amount_refunded ?? 0);
        $totalAmount    = (int) ($charge->amount ?? 0);
        // MARKER-PATCH-172C — 'partial' is in the enum; 'partial_refund' is not.
        $original->payment_status = ($refundedAmount >= $totalAmount) ? 'refunded' : 'partial';
        $original->save();

        Log::warning('direct_payments_webhook.external_refund_detected', [
            'tenant_id'       => $tenant->id,
            'original_sale'   => $original->id,
            'pi'              => $piId,
            'refunded_amount' => $refundedAmount,
            'total_amount'    => $totalAmount,
        ]);

        // MARKER-PATCH-247 — money left via the Stripe dashboard with no
        // Intake action. Critical: staff must reconcile.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'payment.refund_external', [
            'title' => 'Refund issued outside Intake — ' . $original->sale_number,
            'body'  => format_money($refundedAmount) . ' refunded from the Stripe dashboard. The sale is marked '
                . $original->payment_status . '; check the ledger.',
            'link'  => '/admin/register/reconciliation',
            'meta'  => ['sale_id' => $original->id, 'refunded_cents' => $refundedAmount],
        ]);
    }

    /**
     * MARKER-PATCH-172 — promote a draft sale to paid when its Checkout
     * Session completes.
     *
     * Idempotent: if the sale is already paid (duplicate webhook delivery,
     * polling-promoted, etc.) we no-op.
     */
    protected function onCheckoutSessionCompleted(\Stripe\Event $event, Tenant $tenant): void
    {
        $session = $event->data->object;
        $sessionId = $session->id ?? null;
        if (! $sessionId) return;

        // Resolve the PaymentIntent id early — used both as a fallback match key
        // and (below) for card details.
        $piId = is_string($session->payment_intent ?? null)
            ? $session->payment_intent
            : ($session->payment_intent?->id ?? null);

        // MARKER-PATCH-193 — match the sale by checkout_session_id first, then
        // FALL BACK to the PaymentIntent id. The session-only match stranded
        // payments when a sale's checkout_session_id was null/mismatched (e.g.
        // after a premature cancel) even though the money landed in Stripe.
        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('checkout_session_id', $sessionId)
            ->first();

        if (! $sale && $piId) {
            $sale = TenantSale::where('tenant_id', $tenant->id)
                ->where('stripe_payment_intent_id', $piId)
                ->first();
        }

        if (! $sale) {
            // Genuinely unmatchable — the money is in Stripe with no home in
            // Intake. Log loudly so the reconciliation report can surface it.
            Log::warning('direct_payments_webhook.checkout_no_sale', [
                'tenant_id'  => $tenant->id,
                'session_id' => $sessionId,
                'payment_intent' => $piId,
            ]);
            return;
        }

        if ($sale->payment_status === 'paid') {
            // Already promoted — duplicate delivery.
            return;
        }

        // Pull card details + charge from the session ($piId resolved above).
        $brand = null; $last4 = null; $funding = null; $chargeId = null;

        if ($piId) {
            try {
                $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
                $pi = $direct->retrievePaymentIntent($piId);
                $details = $direct->extractCardDetails($pi);
                $brand    = $details['brand'];
                $last4    = $details['last4'];
                $funding  = $details['funding'];
                $chargeId = $details['charge_id'];
            } catch (\Throwable $e) {
                Log::warning('direct_payments_webhook.checkout_card_extract_failed', [
                    'tenant_id' => $tenant->id,
                    'pi'        => $piId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $sale->status                    = 'completed';
        $sale->payment_status            = 'paid';
        $sale->paid_at                   = now();
        $sale->stripe_payment_intent_id  = $piId;
        $sale->stripe_charge_id          = $chargeId;
        $sale->card_brand                = $brand;
        $sale->card_last4                = $last4;
        $sale->card_funding              = $funding;
        $sale->payment_reference         = ($brand && $last4) ? ($brand . ' ····' . $last4) : 'Paid via link';
        $sale->save();

        // MARKER-PATCH-178B — record the payment on the SALE ledger (this was
        // missing: the webhook flipped status=paid but never wrote a ledger
        // row, so link-paid sales never reconciled). Idempotent: skip if a
        // payment row for this charge already exists. Then refresh the linked
        // appointment's cache so it shows paid.
        try {
            $already = \App\Models\Tenant\TenantSalePayment::where('sale_id', $sale->id)
                ->where('external_reference', $piId)
                ->exists();
            if (! $already && $piId) {
                $hasPrior = $sale->payments()->count() > 0;
                app(\App\Services\Tenant\SalePaymentService::class)->record(
                    sale:               $sale,
                    amountCents:        (int) $sale->total_cents,
                    kind:               $hasPrior
                        ? \App\Models\Tenant\TenantSalePayment::KIND_BALANCE
                        : ($sale->appointment_id
                            ? \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT
                            : \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT),
                    source:             \App\Models\Tenant\TenantSalePayment::SOURCE_DIRECT_PAYMENT_LINK,
                    method:             'card',
                    externalReference:  $piId,
                    notes:              'Paid via payment link',
                );
                // MARKER-PATCH-219C — appointment paid cache cascades
                // centrally in SalePaymentService::recalcStatus().
            }
        } catch (\Throwable $e) {
            Log::error('direct_payments_webhook.ledger_write_failed', [
                'tenant_id' => $tenant->id,
                'sale_id'   => $sale->id,
                'error'     => $e->getMessage(),
            ]);
        }

        Log::info('direct_payments_webhook.checkout_completed', [
            'tenant_id'  => $tenant->id,
            'sale_id'    => $sale->id,
            'session_id' => $sessionId,
        ]);

        // MARKER-PATCH-247 — the register has long since moved on; the bell
        // is how staff find out the money landed and fulfillment can happen.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'payment.link_completed', [
            'title' => 'Payment link completed — ' . $sale->sale_number,
            'body'  => format_money((int) $sale->total_cents) . ' paid by card via link.',
            'link'  => '/admin/register/history',
            'meta'  => ['sale_id' => $sale->id, 'amount_cents' => (int) $sale->total_cents],
        ]);
    }

    /**
     * MARKER-PATCH-193 — checkout.session.expired. Stripe fires this when a
     * Checkout Session lapses (default 24h) without completing. Mark the
     * matching sale expired ONLY if it's still unpaid — never touch a sale that
     * was already paid (a completed event may race ahead of expiry).
     */
    protected function onCheckoutSessionExpired(\Stripe\Event $event, Tenant $tenant): void
    {
        $session = $event->data->object;
        $sessionId = $session->id ?? null;
        if (! $sessionId) return;

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('checkout_session_id', $sessionId)
            ->first();

        if (! $sale) {
            return; // nothing to expire
        }

        // Already paid (e.g. completed event won the race) — leave it alone.
        if ($sale->payment_status === 'paid' || $sale->payments()->count() > 0) {
            return;
        }

        // Mark the sale expired. payment_status stays unpaid (enum has no
        // 'expired'); status carries the lifecycle state.
        if ($sale->status !== 'cancelled') {
            $sale->status = 'cancelled';
            $sale->payment_reference = 'Payment link expired';
            $sale->save();
        }

        Log::info('direct_payments_webhook.checkout_expired', [
            'tenant_id'  => $tenant->id,
            'sale_id'    => $sale->id,
            'session_id' => $sessionId,
        ]);

        // MARKER-PATCH-247 — the customer never paid; staff should follow up.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'payment.link_expired', [
            'title' => 'Payment link expired — ' . $sale->sale_number,
            'body'  => format_money((int) $sale->total_cents) . ' was never paid; the sale auto-cancelled.',
            'link'  => '/admin/register/history',
            'meta'  => ['sale_id' => $sale->id],
        ]);
    }
}
HOLDREL_2_EOF

cat > 'app/Console/Commands/BookingsReapHolds.php' <<'HOLDREL_3_EOF'
<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantPendingBooking;
use Illuminate\Console\Command;

/**
 * MARKER-PATCH-387 — bookings:reap-holds.
 *
 * Clears out charge-then-create booking holds:
 *  - abandoned: status 'pending' AND expires_at more than 2h ago. The buffer
 *    beyond the 20-minute hold window guarantees that any genuinely-paid hold
 *    whose payment_intent.succeeded webhook is delayed has already been
 *    materialized by the webhook backstop before we delete anything.
 *  - stale materialized: status 'materialized' older than 30 days. The
 *    appointment exists and the webhook-idempotency window is long closed.
 */
class BookingsReapHolds extends Command
{
    protected $signature   = 'bookings:reap-holds';
    protected $description = 'Delete abandoned booking holds and prune old materialized ones.';

    public function handle(): int
    {
        // MARKER-FAILED-PAID — released holds reap on the same schedule as
        // abandoned ones; failed_paid rows are permanent evidence of a
        // charged customer and are NEVER deleted here.
        $abandoned = TenantPendingBooking::whereIn('status', ['pending', 'released'])
            ->where('expires_at', '<', now()->subHours(2))
            ->delete();

        $pruned = TenantPendingBooking::where('status', 'materialized')
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Reaped {$abandoned} abandoned holds, pruned {$pruned} old materialized holds.");

        return self::SUCCESS;
    }
}
HOLDREL_3_EOF

cat > 'public/js/booking.js' <<'HOLDREL_4_EOF'
/**
 * Intake SaaS — Booking Form JS
 * 4-step flow: Services → Schedule → Details → Review + Payment
 */
(function () {
  'use strict';

  var d    = window.BkData || {};
  var csrf = d.csrf   || '';

  // =========================================================================
  // State
  // =========================================================================
  var state = {
    step:       1,
    // Services
    selections: {},   // { serviceId: {...} } — multi-asset: the ACTIVE bike's set
    assetSel: {},     // MARKER-PATCH-214b — multi-asset: { assetKey: { serviceId: {...} } }
    activeAsset: null,// MARKER-PATCH-214b — multi-asset: active bike clientKey
    // Schedule
    date:       null,
    appointmentTime: null,
    resourceId: null,
    receivingMethod: null,
    // Details
    firstName:  '',
    lastName:   '',
    email:      '',
    phone:      '',
    responses:  {},   // { fieldKey: value }
    responseLabels: {}, // { fieldKey: label }
    // Payment
    paymentMethod: d.stripeEnabled ? 'stripe' : (d.paypalEnabled ? 'paypal' : 'none'),
  };

  // Calendar state
  var calYear, calMonth, calAvailable = {}, calUnavailable = {}, calEarliest = null, calTimeSlots = {}, calSlotResources = {};
  var calPdWindows = {}; // MARKER-PATCH-512 — pickup & delivery route windows per date
  var calCapacity = {}, calView = 'month'; // MARKER-PATCH-518 — day/week/month
  var calPdNeedBy = false; // MARKER-PATCH-519
  var calPdLead = 1, calWeekStart = null; // MARKER-PATCH-520
  var calPdAllowDayOf = false; // MARKER-PATCH-524
  var bookingMode = d.bookingMode || 'drop_off';
  var today = new Date();
  calYear  = today.getFullYear();
  calMonth = today.getMonth() + 1;

  // MARKER-PATCH-526 — refresh persistence
  var bkStoreKey = 'bk-state:' + location.host + ':' + location.pathname + ':' + (location.search || '');

  // MARKER-FUNNEL-SESSION-FIX — entering the flow (directly or after the
  // choice page) emits started with the shared client-minted session id;
  // duplicate events per session are fine — the tile counts distinct sessions.
  try {
    if (window.__intakeFunnel) window.__intakeFunnel.send('booking_started');
  } catch (e) {}
  var bkRestoring = false;

  function bkSnap() {
    if (bkRestoring) return;
    try {
      sessionStorage.setItem(bkStoreKey, JSON.stringify({
        v: 1,
        assets: window.BkAssets || null,
        customer: window.BkCustomer || null,
        assetSel: state.assetSel,
        activeAsset: state.activeAsset,
        selections: state.selections,
        date: state.date,
        appointmentTime: state.appointmentTime,
        resourceId: state.resourceId,
        receivingMethod: state.receivingMethod,
        pdWindowId: state.pdWindowId || null,
        pdPickupDate: state.pdPickupDate || null,
        pdOutreach: state.pdOutreach || false, // MARKER-WINDOW-MINISTEP
        needBy: state.needBy || null,
        step: state.step,
      }));
    } catch (e) {}
  }
  window.__bkClearSnap = function () { try { sessionStorage.removeItem(bkStoreKey); } catch (e) {} };

  function bkRestore() {
    var raw = null;
    try { raw = sessionStorage.getItem(bkStoreKey); } catch (e) {}
    if (!raw) return;
    var snap = null;
    try { snap = JSON.parse(raw); } catch (e) { return; }
    if (!snap || snap.v !== 1) return;
    var hasServices = snap.selections && Object.keys(snap.selections).length;
    var hasAssetSel = snap.assetSel && Object.keys(snap.assetSel).some(function (k) { return Object.keys(snap.assetSel[k] || {}).length; });
    if (!hasServices && !hasAssetSel && !snap.date) return;

    bkRestoring = true;
    try {
      if (snap.assets && snap.assets.length) { window.BkAssets = snap.assets; }
      if (snap.customer) {
        window.BkCustomer = snap.customer;
        // MARKER-RETURNING-PREFILL — a mid-flow refresh restored the customer
        // for submit but left the Details fields blank; re-apply the prefill.
        if (typeof window.bkApplyReturningCustomer === 'function') window.bkApplyReturningCustomer(snap.customer);
      }
      state.assetSel = snap.assetSel || {};
      state.activeAsset = snap.activeAsset || null;
      state.selections = snap.selections || {};
      state.receivingMethod = snap.receivingMethod || null;
      state.pdWindowId = snap.pdWindowId || null;
      state.pdPickupDate = snap.pdPickupDate || null;
      state.pdOutreach = snap.pdOutreach || false; // MARKER-WINDOW-MINISTEP
      state.needBy = snap.needBy || null;

      // Skip the preflow when it was already completed.
      var pastPre = (d.multiAsset && snap.assets && snap.assets.length) || (parseInt(snap.step, 10) || 1) > 1 || hasServices || hasAssetSel;
      if (pastPre) {
        var pre = document.getElementById('bk-preflow');
        if (pre) pre.classList.remove('active');
        document.querySelectorAll('.bk-step--pre').forEach(function (dot) { dot.classList.remove('active'); dot.classList.add('done'); });
      }
      if (d.multiAsset && snap.assets && snap.assets.length) {
        if (typeof initAssetServices === 'function') initAssetServices();
      } else if (typeof syncRowsToSelections === 'function') {
        syncRowsToSelections();
      }
      updateSidebar();
      if (typeof updateNext1 === 'function') updateNext1();

      // Aim the first availability fetch at the saved date's month, and
      // finish the date/window/slot restore once it lands.
      if (snap.date) {
        var sd = new Date(snap.date + 'T12:00:00');
        calYear = sd.getFullYear(); calMonth = sd.getMonth() + 1;
        window.__bkPending = {
          date: snap.date,
          time: snap.appointmentTime || null,
          resourceId: snap.resourceId || null,
          winId: snap.pdWindowId || null,
          outreach: snap.pdOutreach || false, // MARKER-WINDOW-MINISTEP
          winDate: snap.pdPickupDate || null,
        };
      }

      var rcv = document.getElementById('bk-receiving');
      if (rcv && snap.receivingMethod) rcv.value = snap.receivingMethod;

      var step = Math.min(Math.max(parseInt(snap.step, 10) || 1, 1), 3);
      if (pastPre) setStep(step);
    } finally {
      bkRestoring = false;
    }
  }

  function bkApplyPending() {
    var pend = window.__bkPending;
    if (!pend) return;
    window.__bkPending = null;
    if (!calAvailable[pend.date]) { bkSnap(); return; }
    selectDate(pend.date);
    if (pend.winId) {
      // MARKER-WINDOW-MINISTEP — windows are cards (divs) now, not buttons
      var wb = document.querySelector('#bk-pd-windows [data-win-id="' + pend.winId + '"][data-win-date="' + (pend.winDate || '') + '"]');
      if (wb && !wb.classList.contains('full')) wb.click();
    } else if (pend.outreach) {
      var sk = document.querySelector('#bk-pd-windows .bk-pdw-skip');
      if (sk) sk.click();
    }
    if (pend.time) {
      var tb = null;
      document.querySelectorAll('#bk-time-slots button').forEach(function (b) { if (b.dataset.slot === pend.time) tb = b; });
      if (tb) tb.click();
      if (pend.resourceId) {
        var rb = document.querySelector('#bk-resource-picker button[data-resource-id="' + pend.resourceId + '"]');
        if (rb) rb.click();
      }
    }
  }

  // Stripe state
  var stripe, stripeElements, stripeCard;

  // =========================================================================
  // Boot
  // =========================================================================
  document.addEventListener('DOMContentLoaded', function () {
    bindAddButtons();
    bindServiceAddonCheckboxes();
    bindSearch();
    bindCatPills();
    bindCalNav();
    bindReceiving();
    bkRestore(); // MARKER-PATCH-526 — before initCalendar so the fetch targets the saved month
    initCalendar();
    initS2Rail(); // MARKER-PATCH-525
    if (d.multiAsset) window.__bkInitAssetServices = initAssetServices; // MARKER-PATCH-214c (run at pre-flow handoff, not boot)
    if (d.stripeEnabled && d.stripePk) initStripe();
    if (d.paypalEnabled && window.paypal) initPayPal();
  });

  // =========================================================================
  // Step navigation
  // =========================================================================
  window.goTo = function (step) {
    if (step === 2 && !canProceedStep1()) return;
    if (step === 3 && !canProceedStep2()) return;
    if (step === 4) return; // use goToReview()
    setStep(step);
  };

  window.goToReview = function () {
    if (!canProceedStep3()) return;
    collectDetails();
    renderReview();
    setStep(4);
  };

  function setStep(step) {
    state.step = step;

    // Sections
    document.querySelectorAll('.bk-section').forEach(function (s) {
      s.classList.remove('active');
    });
    var el = document.getElementById('bk-step-' + step);
    if (el) el.classList.add('active');

    if (step === 3) populateStep3Recap();
    bkSnap(); // MARKER-PATCH-526

    // Progress dots
    document.querySelectorAll('.bk-step').forEach(function (dot) {
      var ds = parseInt(dot.getAttribute('data-step'), 10);
      dot.classList.remove('active', 'done');
      if (ds === step) dot.classList.add('active');
      if (ds < step)  dot.classList.add('done');
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });

    // MARKER-RESET-PLACEMENT — dock beside the active step's title. Any
    // main step counts as progress (pre-steps or services already chosen).
    window.bkResetDock(document.getElementById('bk-step-' + step), true);
  }

  // MARKER-RESET-PLACEMENT — dock the start-over control on the active
  // panel's title row; visible on every screen past the first.
  window.bkResetDock = function (panelEl, visible) {
    var rst = document.getElementById('bk-reset');
    var conf = document.getElementById('bk-reset-confirm');
    if (!rst) return;
    rst.style.display = visible ? 'inline-flex' : 'none';
    if (!visible || !panelEl) return;
    var title = panelEl.querySelector('.bk-section-title');
    if (!title) return;
    var row = title.parentElement;
    if (!row.classList.contains('bk-title-row')) {
      var wrap = document.createElement('div');
      wrap.className = 'bk-title-row';
      title.parentNode.insertBefore(wrap, title);
      wrap.appendChild(title);
      row = wrap;
    }
    if (rst.parentElement !== row) row.appendChild(rst);
    if (conf && conf.parentElement !== row) { row.style.position = 'relative'; row.appendChild(conf); }
  };

  // MARKER-BOOKING-RESET
  window.bkResetToggle = function (e) {
    if (e) e.stopPropagation();
    var c = document.getElementById('bk-reset-confirm');
    if (c) c.classList.toggle('open');
  };
  window.bkResetConfirm = function () {
    try { sessionStorage.removeItem(bkStoreKey); } catch (err) {}
    // Same session — the once-per-session booking_started guard stays.
    location.reload();
  };
  document.addEventListener('click', function (e) {
    var c = document.getElementById('bk-reset-confirm');
    if (c && c.classList.contains('open') && !e.target.closest('#bk-reset-confirm') && !e.target.closest('#bk-reset')) {
      c.classList.remove('open');
    }
  });

  // =========================================================================
  // Step 1 — Services
  // =========================================================================
  function bindAddButtons() {
    document.querySelectorAll('.bk-service-add-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var serviceId = btn.getAttribute('data-service-id');
        if (!serviceId) return;
        if (state.selections[serviceId]) {
          deselectService(serviceId);
        } else {
          selectService(btn);
        }
      });
    });
  }

  function bindServiceAddonCheckboxes() {
    document.querySelectorAll('.bk-service-addon-check').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var serviceId = cb.getAttribute('data-service-id');
        var addonId   = cb.getAttribute('data-addon-id');
        if (!serviceId || !addonId) return;

        if (cb.checked && !state.selections[serviceId]) {
          var row = document.querySelector('.bk-service-row[data-service-id="' + serviceId + '"]');
          var btn = row ? row.querySelector('.bk-service-add-btn') : null;
          if (btn) selectService(btn);
        }

        var sel = state.selections[serviceId];
        if (!sel) return;

        if (cb.checked) {
          if (sel.addonIds.indexOf(addonId) === -1) sel.addonIds.push(addonId);
        } else {
          sel.addonIds = sel.addonIds.filter(function (id) { return id !== addonId; });
        }
        updateSidebar();
      });
    });
  }

  function selectService(btn) {
    var serviceId   = btn.getAttribute('data-service-id');
    var serviceName = btn.getAttribute('data-service-name');
    var priceCents  = parseInt(btn.getAttribute('data-service-price-cents'), 10) || 0;
    var row         = btn.closest('.bk-service-row');
    var duration    = row ? parseInt(row.getAttribute('data-service-duration'), 10) || 0 : 0;

    state.selections[serviceId] = {
      serviceId: serviceId, serviceName: serviceName,
      priceCents: priceCents, durationMinutes: duration, addonIds: [],
    };
    if (row) row.classList.add('is-selected');
    btn.textContent = '✓ Added';
    updateNext1();
    updateSidebar();
  }

  function deselectService(serviceId) {
    delete state.selections[serviceId];
    var row = document.querySelector('.bk-service-row[data-service-id="' + serviceId + '"]');
    if (row) {
      row.classList.remove('is-selected');
      var btn = row.querySelector('.bk-service-add-btn');
      if (btn) btn.textContent = 'Add to booking';
      row.querySelectorAll('.bk-service-addon-check').forEach(function (cb) { cb.checked = false; });
    }
    updateNext1();
    updateSidebar();
  }

  // MARKER-PATCH-265 — category pills + search share one filter.
  var bkActiveCat = 'all';

  function applyCatalogFilter() {
    var input = document.getElementById('bk-search');
    var q = input ? input.value.toLowerCase().trim() : '';
    document.querySelectorAll('.bk-cat-group').forEach(function (group) {
      var gcat = group.getAttribute('data-cat') || '';
      if (bkActiveCat !== 'all' && gcat !== bkActiveCat) {
        group.style.display = 'none';
        return;
      }
      var anyVisible = false;
      group.querySelectorAll('.bk-service-row').forEach(function (row) {
        var name = (row.getAttribute('data-service-name') || '').toLowerCase();
        var show = (!q || name.includes(q));
        row.style.display = show ? '' : 'none';
        if (show) anyVisible = true;
      });
      group.style.display = anyVisible ? '' : 'none';
    });
  }

  function bindSearch() {
    var input = document.getElementById('bk-search');
    if (!input) return;
    input.addEventListener('input', applyCatalogFilter);
  }

  function bindCatPills() {
    var rail = document.getElementById('bk-cat-rail');
    if (!rail) return;
    rail.querySelectorAll('.bk-cat-pill').forEach(function (pill) {
      pill.addEventListener('click', function () {
        bkActiveCat = pill.getAttribute('data-cat') || 'all';
        rail.querySelectorAll('.bk-cat-pill').forEach(function (p) { p.classList.remove('is-active'); });
        pill.classList.add('is-active');
        applyCatalogFilter();
      });
    });
  }

  function canProceedStep1() {
    if (d.multiAsset) {
      if (Object.keys(state.selections).length) return true; // active bike's live picks (pre-sync)
      var any = false;
      Object.keys(state.assetSel).forEach(function (k) { if (Object.keys(state.assetSel[k]).length) any = true; });
      return any;
    }
    return Object.keys(state.selections).length > 0;
  }

  function updateNext1() {
    var btn = document.getElementById('bk-next-1');
    if (btn) btn.disabled = !canProceedStep1();
  }

  // =========================================================================
  // Step 2 — Calendar
  // =========================================================================
  function bindCalNav() {
    var prev = document.getElementById('cal-prev');
    var next = document.getElementById('cal-next');
    // MARKER-PATCH-520 — arrows follow the active view
    function stepMonth(dir) {
      calMonth += dir;
      if (calMonth < 1)  { calMonth = 12; calYear--; }
      if (calMonth > 12) { calMonth = 1;  calYear++; }
      state.date = null;
      updateNext2();
      loadMonth();
    }
    function syncMonthTo(ds) {
      var d = new Date(ds + 'T12:00:00');
      if (d.getFullYear() !== calYear || (d.getMonth() + 1) !== calMonth) {
        calYear = d.getFullYear(); calMonth = d.getMonth() + 1;
        loadMonth();
        return true;
      }
      return false;
    }
    function stepView(dir) {
      if (calView === 'week') {
        var w = new Date((calWeekStart || (calYear + '-' + pad(calMonth) + '-01')) + 'T12:00:00');
        w.setDate(w.getDate() + 7 * dir);
        var tm = new Date(); tm.setHours(0,0,0,0);
        if (w < tm) w = new Date();
        calWeekStart = w.getFullYear() + '-' + pad(w.getMonth() + 1) + '-' + pad(w.getDate());
        if (!syncMonthTo(calWeekStart)) renderCalendar();
      } else if (calView === 'day') {
        var keys = Object.keys(calAvailable).sort();
        if (!keys.length) return stepMonth(dir);
        var cur = state.date || keys[0];
        var idx = keys.indexOf(cur);
        var nxt = (idx === -1) ? keys[0] : keys[idx + dir];
        if (!nxt) return stepMonth(dir);
        selectDate(nxt);
        if (!syncMonthTo(nxt)) renderCalendar();
      } else {
        stepMonth(dir);
      }
    }
    if (prev) prev.addEventListener('click', function () { stepView(-1); });
    if (next) next.addEventListener('click', function () { stepView(1); });
  }

  function populateStep3Recap() {
    var card = document.getElementById('bk-step3-recap');
    var whenEl = document.getElementById('bk-step3-recap-when');
    var metaEl = document.getElementById('bk-step3-recap-meta');
    var changeBtn = document.getElementById('bk-step3-recap-change');
    if (!card || !whenEl || !metaEl) return;

    if (!state.date) {
      card.style.display = 'none';
      return;
    }

    // Format the primary line: 'Wednesday, April 30 at 9:00 AM' (time-slot)
    // or 'Wednesday, April 30' (drop-off without time).
    var dt = parseDateString(state.date);
    var dayLabel = dt.toLocaleDateString(undefined, {
      weekday: 'long', month: 'long', day: 'numeric'
    });
    var primary = dayLabel;
    if (state.appointmentTime) {
      primary += ' at ' + formatTime12h(state.appointmentTime);
    }
    whenEl.textContent = primary;

    // Meta line: receiving method (drop-off) and/or selected service summary.
    var metaParts = [];
    if (state.receivingMethod) metaParts.push(state.receivingMethod);
    if (d.multiAsset) {
      // MARKER-PATCH-214e — aggregate across all bikes, not just the active one
      var bikeCount = (window.BkAssets || []).length;
      var svcCount = 0;
      Object.keys(state.assetSel).forEach(function (k) { svcCount += Object.keys(state.assetSel[k]).length; });
      if (bikeCount) metaParts.push(bikeCount + ' bike' + (bikeCount > 1 ? 's' : ''));
      if (svcCount)  metaParts.push(svcCount + ' service' + (svcCount > 1 ? 's' : ''));
    } else {
      var sels = Object.values(state.selections || {});
      if (sels.length) {
        var firstName = sels[0].serviceName || '';
        if (firstName) {
          if (sels.length === 1) metaParts.push(firstName);
          else                    metaParts.push(firstName + ' + ' + (sels.length - 1) + ' more');
        }
      }
    }
    metaEl.textContent = metaParts.join(' · ') || ' ';

    card.style.display = '';

    // Wire Change button once. Goes back to step 2.
    if (changeBtn && !changeBtn.__bkBound) {
      changeBtn.__bkBound = true;
      changeBtn.addEventListener('click', function () {
        window.goTo(2);
      });
    }
  }

  function renderEarliestPill() {
    var pill = document.getElementById('bk-earliest');
    var text = document.getElementById('bk-earliest-text');
    var legend = document.getElementById('bk-cal-legend');
    if (!pill || !text) return;

    if (legend) legend.style.display = (calEarliest || Object.keys(calAvailable).length) ? '' : 'none';

    if (!calEarliest || state.date) {
      pill.style.display = 'none';
      return;
    }

    var dt = parseDateString(calEarliest.date);
    var dayLabel = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    var label;
    if (calEarliest.time) {
      var timeLabel = formatTime12h(calEarliest.time);
      label = 'Earliest available: <strong>' + dayLabel + ' at ' + timeLabel + '</strong>';
    } else {
      label = 'Earliest available: <strong>' + dayLabel + '</strong>';
    }
    text.innerHTML = label;
    pill.style.display = '';

    if (!pill.__bkBound) {
      pill.__bkBound = true;
      pill.addEventListener('click', function () {
        if (!calEarliest) return;
        var targetDt = parseDateString(calEarliest.date);
        if (targetDt.getFullYear() !== calYear || (targetDt.getMonth() + 1) !== calMonth) {
          calYear = targetDt.getFullYear();
          calMonth = targetDt.getMonth() + 1;
          loadMonth();
          // Wait for loadMonth to finish before selecting + advancing.
          setTimeout(function () {
            selectDate(calEarliest.date);
            applyEarliestTime();
            tryAdvanceFromPill();
          }, 250);
          return;
        }
        selectDate(calEarliest.date);
        applyEarliestTime();
        // applyEarliestTime sets a 50ms timer for time-slot picking, so we
        // wait a bit longer here so the time has actually been applied
        // before we check whether Continue is unblocked.
        setTimeout(tryAdvanceFromPill, 100);
      });
    }
  }

  function tryAdvanceFromPill() {
    var nextBtn = document.getElementById('bk-next-2');
    if (nextBtn && !nextBtn.disabled) {
      nextBtn.click();
      return;
    }
    // Continue is blocked — most likely because a receiving method is
    // required and not yet picked. Scroll the dropdown into view and
    // pulse it so the customer sees what's blocking them.
    var receiving = document.getElementById('bk-receiving');
    if (receiving) {
      receiving.scrollIntoView({ behavior: 'smooth', block: 'center' });
      receiving.classList.add('bk-flash-attention');
      receiving.focus({ preventScroll: true });
      setTimeout(function () { receiving.classList.remove('bk-flash-attention'); }, 1800);

      // Show a brief inline note above the dropdown so the reason is
      // explicit, not just a flash. Replace any existing note first.
      var existingNote = document.getElementById('bk-earliest-blocker-note');
      if (existingNote) existingNote.remove();
      var note = document.createElement('div');
      note.id = 'bk-earliest-blocker-note';
      note.className = 'bk-earliest-blocker-note';
      note.textContent = 'Pick how you\'re dropping off to continue.';
      receiving.parentNode.insertBefore(note, receiving);
      setTimeout(function () {
        if (note && note.parentNode) note.parentNode.removeChild(note);
      }, 4000);
    }
  }

  function applyEarliestTime() {
    if (calEarliest && calEarliest.time && bookingMode === 'time_slots') {
      setTimeout(function () {
        var btn = document.querySelector('[data-bk-time="' + calEarliest.time + '"]');
        if (btn) btn.click();
      }, 50);
    }
  }

  function parseDateString(s) {
    var parts = s.split('-');
    return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
  }

  function formatTime12h(hhmm) {
    var parts = hhmm.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + m + ' ' + ampm;
  }

  function initCalendar() {
    loadMonth();
  }

  function loadMonth() {
    var label = document.getElementById('cal-month-label');
    var loading = document.getElementById('cal-loading');
    var grid    = document.getElementById('cal-grid');
    if (!label || !grid) return;

    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    label.textContent = months[calMonth - 1] + ' ' + calYear;

    if (loading) loading.style.display = 'block';

    // Clear day cells (keep day name headers)
    var headers = Array.from(grid.querySelectorAll('.bk-cal-day-name'));
    grid.innerHTML = '';
    headers.forEach(function (h) { grid.appendChild(h); });

    fetch(d.availUrl + '?year=' + calYear + '&month=' + calMonth, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
      if (loading) loading.style.display = 'none';
      calAvailable = {};
      (resp.dates || []).forEach(function (dt) { calAvailable[dt] = true; });
      calUnavailable = {};
      (resp.unavailable_dates || []).forEach(function (dt) { calUnavailable[dt] = true; });
      calEarliest = resp.earliest || null;
      calTimeSlots = resp.slots || {};
      calPdWindows = resp.pd_windows || {}; // MARKER-PATCH-512
      calCapacity  = resp.capacity || {};   // MARKER-PATCH-518
      calPdNeedBy  = !!resp.pd_need_by;     // MARKER-PATCH-519
      calPdLead    = (resp.pd_lead_days === undefined) ? 1 : (resp.pd_lead_days | 0); // MARKER-PATCH-520
      calPdAllowDayOf = !!resp.pd_allow_day_of; // MARKER-PATCH-524
      calSlotResources = resp.slot_resources || {};
      renderCalendar();
      renderEarliestPill();
      bkApplyPending(); // MARKER-PATCH-526
    })
    .catch(function () {
      if (loading) loading.style.display = 'none';
      renderCalendar();
    });
  }

  // ======================================================================
  // MARKER-PATCH-518 — Day / Week / Month customer views
  // ======================================================================
  function capLabel(ds) {
    var c = calCapacity[ds];
    if (!c) return null;
    if (c.left === null || c.left === undefined) return 'open';
    return bookingMode === 'time_slots'
      ? c.left + (c.left === 1 ? ' time' : ' times')
      : c.left + ' left';
  }

  function ensureViewBar() {
    if (document.getElementById('bk-viewbar')) return;
    var grid = document.getElementById('cal-grid');
    if (!grid || !grid.parentElement) return;
    var bar = document.createElement('div');
    bar.id = 'bk-viewbar';
    bar.style.cssText = 'display:flex;gap:4px;margin:0 0 12px;background:rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:3px;width:fit-content';
    ['day', 'week', 'month'].forEach(function (v) {
      var b = document.createElement('button');
      b.type = 'button';
      b.dataset.view = v;
      b.textContent = v.charAt(0).toUpperCase() + v.slice(1);
      b.style.cssText = 'font-size:12px;font-weight:600;padding:6px 14px;border-radius:7px;border:0;cursor:pointer;background:transparent;color:var(--p-text);font-family:inherit;opacity:.65';
      b.addEventListener('click', function () { calView = v; paintViewBar(); renderCalendar(); });
      bar.appendChild(b);
    });
    grid.parentElement.insertBefore(bar, grid);
    paintViewBar();
  }

  function paintViewBar() {
    var bar = document.getElementById('bk-viewbar');
    if (!bar) return;
    bar.querySelectorAll('button').forEach(function (b) {
      var on = b.dataset.view === calView;
      b.style.background = on ? 'var(--p-accent)' : 'transparent';
      b.style.color      = on ? 'var(--p-accent-text)' : 'var(--p-text)';
      b.style.opacity    = on ? '1' : '.65';
    });
  }

  function altContainer() {
    var el = document.getElementById('bk-altview');
    if (!el) {
      el = document.createElement('div');
      el.id = 'bk-altview';
      var grid = document.getElementById('cal-grid');
      grid.parentElement.insertBefore(el, grid.nextSibling);
    }
    return el;
  }

  function fmtDayLabel(d) {
    return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
  }

  function renderWeekView() {
    var alt = altContainer();
    alt.innerHTML = '';
    // MARKER-PATCH-520 — stable week anchor; only the arrows move it
    if (!calWeekStart) {
      var a0 = state.date ? new Date(state.date + 'T12:00:00') : new Date();
      if (a0 < today) a0 = new Date();
      calWeekStart = a0.getFullYear() + '-' + pad(a0.getMonth() + 1) + '-' + pad(a0.getDate());
    }
    var anchor = new Date(calWeekStart + 'T12:00:00');
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:7px';
    for (var i = 0; i < 7; i++) {
      var d = new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate() + i);
      var ds = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
      var open = !!calAvailable[ds];
      var c = calCapacity[ds];
      var col = document.createElement('div');
      var sel = ds === state.date;
      col.style.cssText = 'text-align:center;padding:10px 4px;border:1.5px solid ' + (sel ? 'var(--p-accent)' : 'rgba(0,0,0,.1)') + ';border-radius:10px;cursor:' + (open ? 'pointer' : 'default') + ';opacity:' + (open ? '1' : '.38') + (sel ? ';background:color-mix(in srgb, var(--p-accent) 12%, transparent)' : '');
      var pct = (c && c.max) ? Math.max(0, Math.min(1, ((c.max - c.left) / c.max))) : null;
      col.innerHTML =
        '<div style="font-size:10px;opacity:.6">' + fmtDayLabel(d) + '</div>' +
        '<div style="font-size:15px;font-weight:600;margin:1px 0 6px">' + d.getDate() + '</div>' +
        (open
          ? (pct !== null
              ? '<div style="height:5px;border-radius:99px;background:rgba(0,0,0,.1);overflow:hidden"><div style="height:100%;width:' + Math.round(pct * 100) + '%;background:var(--p-accent)"></div></div><div style="font-size:9.5px;margin-top:4px;opacity:.7">' + capLabel(ds) + '</div>'
              : '<div style="font-size:9.5px;opacity:.7">' + (capLabel(ds) || 'open') + '</div>')
          : '<div style="font-size:9.5px;opacity:.7">—</div>');
      if (open) (function (dstr) { col.addEventListener('click', function () { selectDate(dstr); renderCalendar(); }); })(ds);
      row.appendChild(col);
    }
    alt.appendChild(row);
  }

  function renderDayView() {
    var alt = altContainer();
    alt.innerHTML = '';
    var ds = state.date || (calEarliest && calEarliest.date);
    if (!ds) { alt.innerHTML = '<div style="font-size:13px;opacity:.6;padding:10px 0">Pick a date from the month view first.</div>'; return; }
    var d = new Date(ds + 'T12:00:00');
    var c = calCapacity[ds];
    var head = document.createElement('div');
    head.style.cssText = 'font-size:14px;font-weight:600;margin-bottom:10px';
    head.textContent = fmtDayLabel(d) + ', ' + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    alt.appendChild(head);

    if (bookingMode === 'drop_off') {
      var card = document.createElement('div');
      card.style.cssText = 'border:1.5px solid var(--p-accent);border-radius:12px;padding:16px;background:color-mix(in srgb, var(--p-accent) 10%, transparent)';
      var leftTxt = c ? (c.left === null ? 'Open' : c.left + (c.max ? ' <span style="font-size:13px;opacity:.6;font-weight:500">of ' + c.max + '</span>' : '')) : '—';
      card.innerHTML =
        '<div style="font-size:11.5px;opacity:.7;margin-bottom:2px">' + ((calPdWindows[ds] || []).length ? 'Pickup spots left' : 'Drop-off spots left') + '</div>' +
        '<div style="font-size:26px;font-weight:700;letter-spacing:-.02em">' + leftTxt + '</div>';
      alt.appendChild(card);
      // window picker / receiving flow continues below via selectDate's DOM
    } else {
      renderTimeSlots(ds); // reuses the existing picker under the grid
    }
  }

  function renderCalendar() {
    var grid = document.getElementById('cal-grid');
    if (!grid) return;

    // Remove existing day cells
    Array.from(grid.querySelectorAll('.bk-cal-day')).forEach(function (d) { d.remove(); });

    var firstDay  = new Date(calYear, calMonth - 1, 1).getDay(); // 0=Sun
    var daysInMonth = new Date(calYear, calMonth, 0).getDate();
    var todayStr  = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

    // Empty cells for offset
    for (var i = 0; i < firstDay; i++) {
      var empty = document.createElement('div');
      empty.className = 'bk-cal-day';
      grid.appendChild(empty);
    }

    for (var day = 1; day <= daysInMonth; day++) {
      var dateStr = calYear + '-' + pad(calMonth) + '-' + pad(day);
      var cell    = document.createElement('div');
      cell.textContent = day;
      cell.className   = 'bk-cal-day';

      if (dateStr === todayStr) cell.classList.add('today');

      if (calAvailable[dateStr]) {
        cell.classList.add('available');
        if (dateStr === state.date) cell.classList.add('selected');
        // MARKER-PATCH-518 — capacity chip
        var capInfo = capLabel(dateStr);
        if (capInfo) {
          var chip = document.createElement('span');
          chip.textContent = capInfo;
          chip.style.cssText = 'display:block;font-size:8.5px;font-weight:600;line-height:1;margin-top:2px;opacity:.75';
          cell.appendChild(chip);
        }
        (function (ds) {
          cell.addEventListener('click', function () { selectDate(ds); });
        })(dateStr);
      } else if (calUnavailable[dateStr]) {
        cell.classList.add('unavailable');
      }

      grid.appendChild(cell);
    }

    // MARKER-PATCH-518 — view routing: month shows the grid, week/day swap it out
    ensureViewBar();
    var altEl = document.getElementById('bk-altview');
    if (calView === 'month') {
      grid.style.display = '';
      if (altEl) altEl.innerHTML = '';
    } else {
      grid.style.display = 'none';
      if (calView === 'week') renderWeekView(); else renderDayView();
    }
  }

  function selectDate(dateStr) {
    state.date = dateStr;
    state.appointmentTime = null;
    state.resourceId = null;
    var existingPicker = document.getElementById('bk-resource-picker');
    if (existingPicker) existingPicker.remove();
    document.querySelectorAll('.bk-cal-day').forEach(function (c) {
      c.classList.toggle('selected', c.textContent == parseInt(dateStr.split('-')[2], 10) && calAvailable[dateStr]);
    });
    renderCalendar();
    renderRailDay(dateStr); // MARKER-PATCH-525

    // Time slot mode — show time picker
    if (bookingMode === 'time_slots') {
      renderTimeSlots(dateStr);
    }

    // MARKER-PATCH-512 — pickup & delivery: window picker on drop_off dates
    state.pdWindowId = null;
    state.pdPickupDate = null; // MARKER-PATCH-520
    state.pdOutreach = false; // MARKER-WINDOW-MINISTEP
    var pdExisting = document.getElementById('bk-pd-windows');
    if (pdExisting) pdExisting.remove();
    if (bookingMode === 'drop_off' && (calPdWindows[dateStr] || []).length) {
      renderPdWindows(dateStr);
    }

    renderEarliestPill();
    updateNext2();
  }

  // MARKER-PATCH-512 — pickup window picker (mirrors renderTimeSlots)
  function renderPdWindows(dateStr) {
    // MARKER-PATCH-520 — windows from (dateStr - lead) through dateStr
    var windows = [];
    (function () {
      var end = new Date(dateStr + 'T12:00:00');
      var todayMid = new Date(); todayMid.setHours(0,0,0,0);
      for (var off = calPdLead; off >= (calPdAllowDayOf ? 0 : 1); off--) { // MARKER-PATCH-524
        var d = new Date(end.getFullYear(), end.getMonth(), end.getDate() - off);
        if (d < todayMid) continue;
        var ds = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        (calPdWindows[ds] || []).forEach(function (w) {
          windows.push({
            id: w.id, label: w.label, remaining: w.remaining, full: w.full, date: ds,
            dayLabel: (off === 0 ? 'Day of' : ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()] + ' ' + (d.getMonth() + 1) + '/' + d.getDate()),
          });
        });
      }
    })();
    // MARKER-WINDOW-MINISTEP-EMPTYFIX — a date can be gated on pickup (its
    // own windows exist) while having NO usable lead-day windows (e.g. the
    // earliest bookable day). Rendering nothing used to deadlock Continue;
    // now the mini-step renders with just the reach-out option.
    var gatedNoWindows = !windows.length
      && bookingMode === 'drop_off'
      && ((calPdWindows[dateStr] || []).length > 0);
    if (!windows.length && !gatedNoWindows) return;
    // MARKER-WINDOW-MINISTEP — the window choice is its own focused mini-step:
    // stepper (date done -> window pending), radio cards, and a "reach out to
    // me" skip that satisfies the requirement and flags outreach for staff.
    var wrap = document.createElement('div');
    wrap.id = 'bk-pd-windows';
    wrap.className = 'bk-pdw';

    var stepper = document.createElement('div');
    stepper.className = 'bk-pdw-stepper';
    stepper.innerHTML = '<span class="bk-pdw-s done"></span><span class="bk-pdw-s" id="bk-pdw-s2"></span>';
    wrap.appendChild(stepper);

    var label = document.createElement('div');
    label.className = 'bk-pdw-title';
    label.textContent = 'When should we come get it?';
    wrap.appendChild(label);
    var sub = document.createElement('div');
    sub.className = 'bk-pdw-sub';
    sub.textContent = gatedNoWindows
      ? 'No pickup windows fit before this date — we\'ll arrange pickup with you directly.'
      : 'Pick one window — this is how your ' + (window.BkAssetSingular || 'item') + ' reaches us.';
    wrap.appendChild(sub);

    function markDone() {
      var s2 = document.getElementById('bk-pdw-s2');
      if (s2) s2.classList.toggle('done', !!(state.pdWindowId || state.pdOutreach));
    }
    function clearCards() {
      wrap.querySelectorAll('.bk-pdw-card').forEach(function (c) { c.classList.remove('sel'); });
    }

    windows.forEach(function (w) {
      var card = document.createElement('div');
      card.className = 'bk-pdw-card' + (w.full ? ' full' : '');
      card.dataset.winId = w.id; card.dataset.winDate = w.date; // MARKER-PATCH-526
      card.setAttribute('role', 'radio');
      card.innerHTML = '<span class="bk-pdw-radio"></span>'
        + '<span class="bk-pdw-d">' + w.dayLabel + ' · ' + w.label + '</span>'
        + '<span class="bk-pdw-spots">' + (w.full ? 'full' : w.remaining + (w.remaining === 1 ? ' stop left' : ' stops left')) + '</span>';
      if (!w.full) card.addEventListener('click', function () {
        state.pdWindowId = w.id;
        state.pdPickupDate = w.date; // MARKER-PATCH-520
        state.pdOutreach = false;
        clearCards(); card.classList.add('sel');
        markDone(); updateNext2();
      });
      wrap.appendChild(card);
    });

    var skip = document.createElement('div');
    skip.className = 'bk-pdw-card bk-pdw-skip';
    skip.setAttribute('role', 'radio');
    skip.innerHTML = '<span class="bk-pdw-radio"></span>'
      + '<span class="bk-pdw-d">None of these work — reach out to me</span>'
      + '<span class="bk-pdw-sub2">Skip for now. We\'ll contact you to arrange pickup after you book.</span>';
    skip.addEventListener('click', function () {
      state.pdWindowId = null;
      state.pdPickupDate = null;
      state.pdOutreach = true;
      clearCards(); skip.classList.add('sel');
      markDone(); updateNext2();
    });
    wrap.appendChild(skip);
    markDone();

    // MARKER-PATCH-519 — optional "need it back by" under the window picker
    if (calPdNeedBy) {
      // MARKER-NEEDBY-POLISH — styled to match the mini-step cards
      var nb = document.createElement('div');
      nb.className = 'bk-pdw-needby';
      var nbl = document.createElement('label');
      nbl.className = 'bk-pdw-needby-l';
      nbl.innerHTML = 'Need it back by a certain date? <span>optional</span>';
      var nbi = document.createElement('input');
      nbi.type = 'date';
      nbi.min = dateStr;
      nbi.className = 'bk-pdw-needby-i';
      nbi.addEventListener('change', function () {
        state.needBy = nbi.value || null;
        nbi.classList.toggle('has-value', !!nbi.value);
      });
      nb.appendChild(nbl); nb.appendChild(nbi);
      wrap.appendChild(nb);
    }

    // MARKER-PATCH-525 — mount in the schedule rail when present, else legacy anchor
    var mnt = s2Mount();
    if (mnt) {
      mnt.appendChild(wrap);
    } else {
      var anchorEl = document.getElementById('bk-altview') || document.getElementById('cal-grid');
      if (anchorEl && anchorEl.parentNode) {
        anchorEl.parentNode.insertBefore(wrap, anchorEl.nextSibling);
      } else {
        var cal = document.getElementById('bk-calendar');
        if (cal && cal.parentElement) cal.parentElement.appendChild(wrap);
      }
    }
    updateNext2();
  }

  function renderTimeSlots(dateStr) {
    var existing = document.getElementById('bk-time-slots');
    if (existing) existing.remove();

    var slots = calTimeSlots[dateStr] || [];
    if (slots.length === 0) return;

    var wrap = document.createElement('div');
    wrap.id = 'bk-time-slots';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Available times';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    slots.forEach(function(slot) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.slot = slot; // MARKER-PATCH-526
      btn.textContent = formatTime(slot);
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text)';
      btn.addEventListener('click', function() {
        state.appointmentTime = slot;
        grid.querySelectorAll('button').forEach(function(b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background   = 'var(--p-accent)';
        btn.style.borderColor  = 'var(--p-accent)';
        btn.style.color        = 'var(--p-accent-text)';
        renderResourcePicker(dateStr, slot);
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    var mntTs = s2Mount(); // MARKER-PATCH-525
    if (mntTs) mntTs.appendChild(wrap); else document.getElementById('bk-calendar').after(wrap);
  }

  function renderResourcePicker(dateStr, time) {
    var existing = document.getElementById('bk-resource-picker');
    if (existing) existing.remove();
    state.resourceId = null;

    var resources = (d.resources || []);
    if (resources.length < 2) return; // single-resource: auto-assign server-side

    var freeIds = ((calSlotResources[dateStr] || {})[time]) || [];
    var freeResources = resources.filter(function (r) { return freeIds.indexOf(r.id) !== -1; });
    if (freeResources.length === 0) return;

    var wrap = document.createElement('div');
    wrap.id = 'bk-resource-picker';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Choose who';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    freeResources.forEach(function (res) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.resourceId = res.id;
      btn.textContent = res.name;
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text);display:inline-flex;align-items:center;gap:8px';

      // Color swatch
      if (res.color_hex) {
        var swatch = document.createElement('span');
        swatch.style.cssText = 'width:10px;height:10px;border-radius:50%;background:' + res.color_hex;
        btn.prepend(swatch);
      }

      btn.addEventListener('click', function () {
        state.resourceId = res.id;
        grid.querySelectorAll('button').forEach(function (b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background  = 'var(--p-accent)';
        btn.style.borderColor = 'var(--p-accent)';
        btn.style.color       = 'var(--p-accent-text)';
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    var timeSlotsEl = document.getElementById('bk-time-slots');
    if (timeSlotsEl) {
      timeSlotsEl.after(wrap);
    } else {
      var mntRp = s2Mount(); // MARKER-PATCH-525
      if (mntRp) mntRp.appendChild(wrap); else document.getElementById('bk-calendar').after(wrap);
    }
  }

  // MARKER-RETURNING-PREFILL — prefill + lock Details for a returning
  // customer. Called from the items-step continue AND from snapshot restore.
  window.bkApplyReturningCustomer = function (cust) {
    if (!cust || !cust.id) return;
    var lock = function (id, val) {
      var inp = document.getElementById(id);
      if (inp && val) { inp.value = val; inp.readOnly = true; inp.classList.add('bk-locked'); }
    };
    lock('bk-first-name', cust.firstName);
    lock('bk-last-name', cust.lastName);
    lock('bk-email', cust.email);
    lock('bk-phone', cust.phone);
    var fn = document.getElementById('bk-first-name');
    if (fn && !document.getElementById('bk-returning-note')) {
      var note = document.createElement('div');
      note.id = 'bk-returning-note';
      note.className = 'bk-returning-note';
      note.innerHTML = '<strong>Welcome back' + (cust.firstName ? ', ' + esc(cust.firstName) : '') + '!</strong> Your contact details are filled in from your account.';
      var grid = fn.closest('.bk-field-grid-2'); // MARKER-PATCH-214j — note above the grid, not inside it
      if (grid && grid.parentElement) grid.parentElement.insertBefore(note, grid);
      else if (fn.parentElement && fn.parentElement.parentElement) fn.parentElement.parentElement.insertBefore(note, fn.parentElement);
    }
  };

  function formatTime(timeStr) {
    try {
      var parts = timeStr.split(':');
      var h = parseInt(parts[0], 10);
      var m = parts[1];
      var ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      return h + ':' + m + ' ' + ampm;
    } catch(e) { return timeStr; }
  }

  function bindReceiving() {
    var sel = document.getElementById('bk-receiving');
    if (!sel) return;
    sel.addEventListener('change', function () {
      state.receivingMethod = sel.value;
      updateNext2();
    });
  }

  function canProceedStep2() {
    if (!state.date) return false;
    if (bookingMode === 'time_slots' && !state.appointmentTime) return false;
    // MARKER-PATCH-512 — a date with route windows requires picking one
    if (bookingMode === 'drop_off' && (calPdWindows[state.date] || []).length && !state.pdWindowId && !state.pdOutreach) return false; // MARKER-WINDOW-MINISTEP
    if (bookingMode === 'time_slots' && (d.resources || []).length >= 2 && !state.resourceId) return false;
    if (d.hasReceiving) {
      var sel = document.getElementById('bk-receiving');
      if (sel && !sel.value) return false;
    }
    return true;
  }

  function updateNext2() {
    var btn = document.getElementById('bk-next-2');
    if (btn) btn.disabled = !canProceedStep2();
    bkSnap(); // MARKER-PATCH-526
  }

  // =========================================================================
  // Step 3 — Details
  // =========================================================================
  function canProceedStep3() {
    var fn = document.getElementById('bk-first-name');
    var ln = document.getElementById('bk-last-name');
    var em = document.getElementById('bk-email');
    if (!fn || !fn.value.trim()) { fn && fn.focus(); return false; }
    if (!ln || !ln.value.trim()) { ln && ln.focus(); return false; }
    if (!em || !em.value.trim() || !em.value.includes('@')) { em && em.focus(); return false; }

    // Required custom fields
    var missing = false;
    document.querySelectorAll('.bk-custom-field[required]').forEach(function (f) {
      if (!f.value.trim()) { missing = true; f.focus(); }
    });
    return !missing;
  }

  function collectDetails() {
    state.firstName = document.getElementById('bk-first-name')?.value.trim() || '';
    state.lastName  = document.getElementById('bk-last-name')?.value.trim()  || '';
    state.email     = document.getElementById('bk-email')?.value.trim()      || '';
    state.phone     = document.getElementById('bk-phone')?.value.trim()      || '';
    state.receivingMethod = document.getElementById('bk-receiving')?.value   || '';

    state.responses      = {};
    state.responseLabels = {};
    document.querySelectorAll('.bk-custom-field').forEach(function (f) {
      var key   = f.getAttribute('data-field-key');
      var label = f.getAttribute('data-field-label');
      var val   = f.type === 'checkbox' ? (f.checked ? 'Yes' : '') : f.value;
      if (key) {
        state.responses[key]      = val;
        state.responseLabels[key] = label;
      }
    });
  }

  // =========================================================================
  // Sidebar
  // =========================================================================
  // MARKER-PATCH-525 — schedule-rail helpers
  function s2Mount() { return document.getElementById('bk-rail-mounts'); }

  function initS2Rail() {
    var src = document.getElementById('bk-sidebar-items');
    var dst = document.getElementById('bk-rail-order-items');
    if (!src || !dst) return;
    var sync = function () { dst.innerHTML = src.innerHTML; };
    new MutationObserver(sync).observe(src, { childList: true, subtree: true });
    sync();
  }

  function renderRailDay(dateStr) {
    var el = document.getElementById('bk-rail-day');
    if (!el) return;
    if (!dateStr) { el.style.display = 'none'; return; }
    var dObj = new Date(dateStr + 'T12:00:00');
    var cap = capLabel(dateStr);
    el.style.display = '';
    el.querySelector('[data-rail-date]').textContent =
      dObj.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    el.querySelector('[data-rail-cap]').textContent = cap || '';
  }

  function updateSidebar() {
    if (d.multiAsset && state.activeAsset) { state.assetSel[state.activeAsset] = cloneSel(state.selections); renderAssetTabs(); } // MARKER-PATCH-214b/d
    bkSnap(); // MARKER-PATCH-526
    var container = document.getElementById('bk-sidebar-items');
    if (!container) return;
    if (d.multiAsset) {
      // MARKER-PATCH-214g — numbered per-bike groups (treatment C), prominent grand total
      var mHtml = '', mTotal = 0, anySvc = false, bikeNum = 0;
      (window.BkAssets || []).forEach(function (a) {
        var sels = state.assetSel[a.clientKey] || {};
        var ks = Object.keys(sels);
        if (!ks.length) return;
        anySvc = true; bikeNum++;
        var bikeSub = 0, lines = '';
        ks.forEach(function (k) {
          var sel = sels[k];
          lines += '<div class="bk-cart-line"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
          bikeSub += sel.priceCents;
          sel.addonIds.forEach(function (addonId) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
            if (!cb) return;
            var ap = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
            lines += '<div class="bk-cart-line bk-cart-line--addon"><span>+ ' + esc(cb.getAttribute('data-addon-name') || '') + '</span><span>' + fmtMoney(ap) + '</span></div>';
            bikeSub += ap;
          });
        });
        mTotal += bikeSub;
        mHtml += '<div class="bk-cart-bike">'
              +    '<div class="bk-cart-head"><span class="bk-cart-idx">' + bikeNum + '</span>'
              +      '<span class="bk-cart-name">' + esc(a.name) + '</span>'
              +      '<span class="bk-cart-sub">' + fmtMoney(bikeSub) + '</span></div>'
              +    lines
              +  '</div>';
      });
      if (!anySvc) { container.innerHTML = '<p class="bk-sidebar-empty">No items selected yet.</p>'; return; }
      mHtml += '<div class="bk-cart-total"><span>Total</span><span>' + fmtMoney(mTotal) + '</span></div>';
      container.innerHTML = mHtml;
      return;
    }
    var services = Object.values(state.selections);
    if (services.length === 0) {
      container.innerHTML = '<p class="bk-sidebar-empty">No items selected yet.</p>';
      return;
    }
    var html = ''; var total = 0;
    services.forEach(function (sel) {
      html += '<div class="bk-sidebar-line"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
      total += sel.priceCents;
      sel.addonIds.forEach(function (addonId) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
        if (!cb) return;
        var name  = cb.getAttribute('data-addon-name') || '';
        var price = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
        html += '<div class="bk-sidebar-line" style="padding-left:16px;opacity:.85"><span>+ ' + esc(name) + '</span><span>' + fmtMoney(price) + '</span></div>';
        total += price;
      });
    });
    html += '<div class="bk-sidebar-total"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
    container.innerHTML = html;
  }

  // =========================================================================
  // Review
  // =========================================================================
  function renderReview() {
    updateSidebar();

    // Services
    var svc = document.getElementById('bk-review-services');
    if (svc) {
      var html = '';
      if (d.multiAsset) {
        (window.BkAssets || []).forEach(function (a) {
          var sels = state.assetSel[a.clientKey] || {};
          var ks = Object.keys(sels);
          html += '<div class="bk-review-asset"><div class="bk-review-asset-name">' + esc(a.name) + '</div>';
          if (!ks.length) html += '<div class="bk-review-row" style="opacity:.45"><span>No services</span><span></span></div>';
          ks.forEach(function (k) {
            var sel = sels[k];
            html += '<div class="bk-review-row"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
            sel.addonIds.forEach(function (addonId) {
              var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
              if (!cb) return;
              html += '<div class="bk-review-row"><span class="bk-review-row-label">+ ' + esc(cb.getAttribute('data-addon-name') || '') + '</span><span>' + fmtMoney(parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0) + '</span></div>';
            });
          });
          html += '</div>';
        });
      } else {
        Object.values(state.selections).forEach(function (sel) {
          html += '<div class="bk-review-row"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
          sel.addonIds.forEach(function (addonId) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
            if (!cb) return;
            var name  = cb.getAttribute('data-addon-name') || '';
            var price = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
            html += '<div class="bk-review-row"><span class="bk-review-row-label">+ ' + esc(name) + '</span><span>' + fmtMoney(price) + '</span></div>';
          });
        });
      }
      var total = calcTotal();
      html += '<div class="bk-review-row" style="font-weight:700;border-top:1px solid rgba(0,0,0,.08);margin-top:8px;padding-top:8px"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
      svc.innerHTML = html || '<p style="opacity:.4;font-size:13px">None selected.</p>';
    }

    // Details
    var det = document.getElementById('bk-review-details');
    if (det) {
      var rows = [
        ['Date',    formatDate(state.date)],
        ['Name',    state.firstName + ' ' + state.lastName],
        ['Email',   state.email],
      ];
      if (state.phone)           rows.push(['Phone', state.phone]);
      if (state.receivingMethod) rows.push(['Drop-off', state.receivingMethod]);
      Object.keys(state.responses).forEach(function (k) {
        if (state.responses[k]) rows.push([state.responseLabels[k] || k, state.responses[k]]);
      });
      det.innerHTML = rows.map(function (r) {
        return '<div class="bk-review-row"><span class="bk-review-row-label">' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
      }).join('');
    }
  }

  // =========================================================================
  // Payment
  // =========================================================================
  window.selectPayment = function (method) {
    state.paymentMethod = method;
    document.querySelectorAll('.bk-payment-btn').forEach(function (b) {
      b.classList.toggle('selected', b.id === 'pay-' + method);
    });
    var sw = document.getElementById('bk-stripe-wrap');
    var pw = document.getElementById('bk-paypal-wrap');
    if (sw) sw.style.display = method === 'stripe' ? '' : 'none';
    if (pw) pw.style.display = method === 'paypal' ? '' : 'none';
  };

  function initStripe() {
    if (!window.Stripe || !d.stripePk) return;
    stripe = Stripe(d.stripePk);
    stripeElements = stripe.elements();
    stripeCard     = stripeElements.create('card', {
      style: {
        base: {
          fontFamily:  '-apple-system, sans-serif',
          fontSize:    '15px',
          color:       (getComputedStyle(document.body).color || '#111111'),
          '::placeholder': { color: '#888888' },
        },
      },
    });
    var mountEl = document.getElementById('bk-stripe-elements');
    if (mountEl) {
      // Mount after a tick so the element is visible
      setTimeout(function () { stripeCard.mount('#bk-stripe-elements'); }, 100);
    }
  }

  function initPayPal() {
    if (!window.paypal) return;
    window.paypal.Buttons({
      createOrder: function (data, actions) {
        return submitBooking('paypal', true).then(function (resp) {
          if (!resp || !resp.success) throw new Error(resp?.message || 'Booking failed');
          // PayPal expects an order ID — we get an approve_url back
          // We redirect instead of using the embedded flow to handle server-side capture
          window.location.href = resp.approve_url;
          return resp.order_id;
        });
      },
      onError: function (err) {
        showError('PayPal error: ' + err);
      },
    }).render('#bk-paypal-button-container');
  }

  window.handlePayment = function () {
    if (state.paymentMethod === 'paypal') {
      // Handled by PayPal button
      return;
    }
    if (state.paymentMethod === 'stripe') {
      handleStripe();
      return;
    }
    submitBooking('none');
  };

  function handleStripe() {
    var btn = document.getElementById('bk-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }

    submitBooking('stripe', false).then(function (resp) {
      if (!resp || !resp.success) {
        showError(resp?.message || 'Booking failed. Please try again.');
        resetSubmitBtn();
        return;
      }
      if (!resp.client_secret) {
        // Free booking
        window.location.href = resp.redirect;
        return;
      }
      stripe.confirmCardPayment(resp.client_secret, {
        payment_method: { card: stripeCard }
      }).then(function (result) {
        if (result.error) {
          // MARKER-HOLD-RELEASE — declined/typo'd/failed card: free the
          // hold instantly so the retry can't collide with its own ghost.
          try {
            navigator.sendBeacon(d.releaseHoldUrl || '/book/release-hold',
              new Blob([JSON.stringify({ pending_token: resp.pending_token })], { type: 'application/json' }));
          } catch (e) {}
          showError(result.error.message);
          resetSubmitBtn();
        } else {
          // MARKER-PATCH-385 — card cleared; materialize the appointment server-side.
          fetch(d.finalizeUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ pending_token: resp.pending_token }),
          }).then(function (r) { return r.json(); }).then(function (fin) {
            if (fin && fin.success && fin.redirect) {
              window.location.href = fin.redirect;
            } else {
              showError((fin && fin.message) || 'Your payment went through, but we could not finish the booking. Please contact us.');
              resetSubmitBtn();
            }
          }).catch(function () {
            showError('Your payment went through, but we could not finish the booking. Please contact us.');
            resetSubmitBtn();
          });
        }
      });
    });
  }

  // =========================================================================
  // Submit
  // =========================================================================
  window.submitBooking = function (paymentMethod, returnPromise) {
    var body = buildPayload(paymentMethod || state.paymentMethod);
    var promise = fetch(d.submitUrl, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/json',
        'X-CSRF-TOKEN':     csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });

    if (returnPromise) return promise;

    promise.then(function (resp) {
      if (!resp.success) { showError(resp.message || 'Booking failed.'); resetSubmitBtn(); return; }
      if (typeof window.__bkClearSnap === 'function') window.__bkClearSnap(); // MARKER-PATCH-526
      if (resp.redirect) { window.location.href = resp.redirect; return; }
      if (resp.payment === 'paypal' && resp.approve_url) { window.location.href = resp.approve_url; return; }
    }).catch(function () {
      showError('Network error. Please try again.');
      resetSubmitBtn();
    });

    return promise;
  };

  // ===== MARKER-PATCH-214b — per-asset service machinery =====
  function cloneSel(map) {
    var o = {};
    Object.keys(map || {}).forEach(function (k) {
      var s = map[k];
      o[k] = { serviceId: s.serviceId, serviceName: s.serviceName, priceCents: s.priceCents, durationMinutes: s.durationMinutes, addonIds: (s.addonIds || []).slice() };
    });
    return o;
  }
  function syncRowsToSelections() {
    document.querySelectorAll('.bk-service-row').forEach(function (row) {
      var sid = row.getAttribute('data-service-id');
      var sel = state.selections[sid];
      var btn = row.querySelector('.bk-service-add-btn');
      if (sel) { row.classList.add('is-selected'); if (btn) btn.textContent = '\u2713 Added'; }
      else { row.classList.remove('is-selected'); if (btn) btn.textContent = 'Add to booking'; }
      row.querySelectorAll('.bk-service-addon-check').forEach(function (cb) {
        cb.checked = !!(sel && sel.addonIds.indexOf(cb.getAttribute('data-addon-id')) !== -1);
      });
    });
  }
  function assetSubtotal(key) {
    var m = state.assetSel[key] || {}, t = 0;
    Object.keys(m).forEach(function (k) {
      var sel = m[k]; t += sel.priceCents;
      sel.addonIds.forEach(function (id) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + id + '"]');
        if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
      });
    });
    return t;
  }
  function renderAssetTabs() {
    var strip = document.getElementById('bk-asset-tabs');
    if (!strip) return;
    var html = '';
    (window.BkAssets || []).forEach(function (a) {
      var n = Object.keys(state.assetSel[a.clientKey] || {}).length;
      var on = a.clientKey === state.activeAsset;
      html += '<button type="button" class="bk-asset-tab' + (on ? ' on' : '') + '" data-k="' + a.clientKey + '">'
            + '<span class="bk-asset-tab-n">' + esc(a.name) + '</span>'
            + '<span class="bk-asset-tab-m">' + (n ? (n + ' service' + (n > 1 ? 's' : '') + ' \u00b7 ' + fmtMoney(assetSubtotal(a.clientKey))) : 'No services yet') + '</span>'
            + '</button>';
    });
    strip.innerHTML = html;
    strip.querySelectorAll('.bk-asset-tab').forEach(function (b) {
      b.addEventListener('click', function () { switchAsset(b.getAttribute('data-k')); });
    });
    var active = (window.BkAssets || []).filter(function (a) { return a.clientKey === state.activeAsset; })[0];
    var lbl = document.getElementById('bk-asset-choosing');
    if (lbl && active) lbl.innerHTML = 'Choosing services for <strong>' + esc(active.name) + '</strong>';
  }
  function switchAsset(key) {
    if (key === state.activeAsset) return;
    if (state.activeAsset) state.assetSel[state.activeAsset] = cloneSel(state.selections);
    state.activeAsset = key;
    state.selections = cloneSel(state.assetSel[key] || {});
    syncRowsToSelections();
    renderAssetTabs();
    updateSidebar();
  }
  function initAssetServices() {
    var assets = window.BkAssets || [];
    if (!assets.length) return;
    assets.forEach(function (a) { if (!state.assetSel[a.clientKey]) state.assetSel[a.clientKey] = {}; });
    var live = {}; assets.forEach(function (a) { live[a.clientKey] = true; });
    Object.keys(state.assetSel).forEach(function (k) { if (!live[k]) delete state.assetSel[k]; }); // MARKER-PATCH-214c prune removed bikes
    if (!live[state.activeAsset]) state.activeAsset = assets[0].clientKey;
    state.activeAsset = state.activeAsset || assets[0].clientKey;
    state.selections = cloneSel(state.assetSel[state.activeAsset]);
    var step1 = document.getElementById('bk-step-1');
    if (step1 && !document.getElementById('bk-asset-tabs')) {
      var wrap = document.createElement('div');
      wrap.innerHTML = '<div class="bk-asset-tabs" id="bk-asset-tabs"></div><div class="bk-asset-choosing" id="bk-asset-choosing"></div>';
      var toolbar = step1.querySelector('.bk-toolbar');
      if (toolbar) step1.insertBefore(wrap, toolbar);
      else step1.insertBefore(wrap, step1.children[2] || null);
    }
    renderAssetTabs();
    syncRowsToSelections();
  }

  function buildPayload(paymentMethod) {
    collectDetails();
    var items, assetsPayload = null, bkCustomerId = null;
    if (d.multiAsset) {
      items = [];
      assetsPayload = [];
      (window.BkAssets || []).forEach(function (a) {
        assetsPayload.push({ client_key: a.clientKey, name_snapshot: a.name, customer_asset_id: a.customerAssetId || null });
        var sels = state.assetSel[a.clientKey] || {};
        Object.keys(sels).forEach(function (k) {
          var s = sels[k];
          items.push({ service_item_id: s.serviceId, addon_ids: s.addonIds.slice(), asset_client_key: a.clientKey });
        });
      });
      bkCustomerId = (window.BkCustomer && window.BkCustomer.id) || null;
    } else {
      items = Object.values(state.selections).map(function (s) {
        return { service_item_id: s.serviceId, addon_ids: s.addonIds.slice() };
      });
    }
    var payload = {
      first_name: state.firstName, last_name: state.lastName,
      email: state.email, phone: state.phone,
      date: state.date, appointment_time: state.appointmentTime || null,
      route_window_id: state.pdWindowId || null, // MARKER-PATCH-512
      pickup_outreach: state.pdOutreach ? 1 : 0, // MARKER-WINDOW-MINISTEP
      need_by: state.needBy || null, // MARKER-PATCH-519
      pickup_date: state.pdPickupDate || null, // MARKER-PATCH-520
      resource_id: state.resourceId || null,
      receiving_method: state.receivingMethod,
      items: items,
      responses: state.responses, response_labels: state.responseLabels,
      payment_method: paymentMethod,
    };
    if (assetsPayload) payload.assets = assetsPayload;
    if (bkCustomerId) payload.customer_id = bkCustomerId;
    return payload;
  }

  // =========================================================================
  // Helpers
  // =========================================================================
  function calcTotal() {
    var t = 0;
    if (d.multiAsset) {
      Object.keys(state.assetSel).forEach(function (ak) {
        Object.keys(state.assetSel[ak]).forEach(function (k) {
          var sel = state.assetSel[ak][k]; t += sel.priceCents;
          sel.addonIds.forEach(function (id) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + id + '"]');
            if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
          });
        });
      });
      return t;
    }
    Object.values(state.selections).forEach(function (sel) {
      t += sel.priceCents;
      sel.addonIds.forEach(function (addonId) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
        if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
      });
    });
    return t;
  }

  function fmtMoney(cents) {
    return d.currency + (cents / 100).toFixed(2);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function fmtDate(ds) {
    if (!ds) return '';
    var dt;
    if (ds instanceof Date) {
      dt = ds;
    } else {
      var parts = String(ds).split('-');
      dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }
    return dt.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  function formatDate(ds) { return fmtDate(ds); }

  function showError(msg) {
    var el = document.getElementById('bk-form-error');
    if (el) { el.textContent = msg; el.style.display = ''; el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }

  function resetSubmitBtn() {
    var btn = document.getElementById('bk-submit-btn');
    if (btn) { btn.disabled = false; btn.textContent = state.paymentMethod === 'none' ? 'Confirm booking' : 'Pay & confirm'; }
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

}());


/* ===== MARKER-PATCH-214 — multi-asset pre-flow (You + Bikes) ===== */
(function () {
  var d = window.BkData || {};
  if (!d.multiAsset) return;
  var pre = document.getElementById('bk-preflow');
  if (!pre) return;

  var path = 'new', customerId = null, firstName = '', lastName = '', custEmail = '', custPhone = '';
  var assets = [];
  var kn = 0;
  function nk() { return 'a' + (++kn); }
  function el(id) { return document.getElementById(id); }
  function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

  var panelIntro = el('bk-pre-intro');
  var panelBikes = el('bk-pre-bikes');

  function showPanel(which) {
    panelIntro.classList.toggle('active', which === 'intro');
    panelBikes.classList.toggle('active', which === 'bikes');
    var youDot = document.querySelector('.bk-step--pre[data-pre="intro"]');
    var bikeDot = document.querySelector('.bk-step--pre[data-pre="bikes"]');
    if (youDot && bikeDot) {
      youDot.classList.toggle('active', which === 'intro');
      youDot.classList.toggle('done', which === 'bikes');
      bikeDot.classList.toggle('active', which === 'bikes');
      bikeDot.classList.remove('done');
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // MARKER-RESET-PLACEMENT — items screen is past the first screen.
    if (typeof window.bkResetDock === 'function') {
      window.bkResetDock(which === 'bikes' ? panelBikes : null, which === 'bikes');
    }
  }

  // intro toggle
  var toggle = el('bk-pre-toggle');
  toggle.querySelectorAll('button').forEach(function (b) {
    b.addEventListener('click', function () {
      path = b.getAttribute('data-path');
      toggle.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      toggle.setAttribute('data-pos', path === 'returning' ? 'right' : 'left');
      el('bk-pre-new').style.display = path === 'new' ? '' : 'none';
      el('bk-pre-returning').style.display = path === 'returning' ? '' : 'none';
    });
  });

  // new customer -> bikes (one empty card)
  el('bk-pre-new-continue').addEventListener('click', function () {
    if (!assets.length) assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }]; // MARKER-ITEMS-PICK
    renderBikes(); showPanel('bikes');
  });

  // returning customer -> lookup
  el('bk-pre-lookup').addEventListener('click', function () {
    var email = (el('bk-pre-email').value || '').trim();
    var st = el('bk-pre-status');
    if (!email) { st.className = 'bk-pre-status show err'; st.textContent = 'Enter your email first.'; return; }
    st.className = 'bk-pre-status show'; st.textContent = 'Looking you up…';
    fetch(d.lookupUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': d.csrf },
      body: JSON.stringify({ email: email })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.found) {
          customerId = res.customer_id; firstName = res.first_name || ''; custEmail = email;
          lastName = res.last_name || ''; custPhone = res.phone || '';
          st.className = 'bk-pre-status show found';
          st.textContent = 'Welcome back' + (firstName ? (', ' + firstName) : '') + '! We pulled your ' + (d.assetPlural || 'items') + ' below.';
          // MARKER-ITEMS-PICK — account items start unselected; the customer
          // explicitly picks what's coming in. Deselect is the new "remove",
          // so nothing is ever unrecoverable.
          assets = (res.assets || []).map(function (a) {
            return { clientKey: nk(), name: a.name, customerAssetId: a.id, fromAccount: true, selected: false };
          });
          if (!assets.length) assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }];
          el('bk-pre-bikes-sub').textContent = "Tap the items you're bringing in — we pulled these from your account.";
          setTimeout(function () { renderBikes(); showPanel('bikes'); }, 600);
        } else {
          custEmail = email;
          st.className = 'bk-pre-status show err';
          st.innerHTML = "We didn't find that email. <button type='button' id='bk-pre-asnew' style='text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;font:inherit;padding:0'>Continue as new →</button>";
          var asNew = el('bk-pre-asnew');
          if (asNew) asNew.addEventListener('click', function () {
            assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }]; // MARKER-ITEMS-PICK
            renderBikes(); showPanel('bikes');
          });
        }
      })
      .catch(function () { st.className = 'bk-pre-status show err'; st.textContent = 'Something went wrong — please try again.'; });
  });

  // bikes
  function findAsset(k) { for (var i = 0; i < assets.length; i++) if (assets[i].clientKey === k) return assets[i]; return null; }
  function namedCount() { return assets.filter(function (b) { return b.selected !== false && (b.name || '').trim() !== ''; }).length; } // MARKER-ITEMS-PICK
  function updateContinue() {
    var n = namedCount();
    el('bk-pre-bikes-continue').disabled = n === 0;
    var hint = el('bk-pre-pick-hint');
    if (hint) hint.style.display = n === 0 ? 'block' : 'none';
  }

  function renderBikes() {
    var wrap = el('bk-pre-bike-list');
    var html = '';
    var shownIdx = 0;
    assets.forEach(function (b) {
      // MARKER-ITEMS-PICK — account items are toggleable pick-cards (214k
      // read-only names retained); manual items are editable and removable.
      if (b.fromAccount) {
        var on = b.selected !== false;
        html += '<div class="bk-pre-bike bk-pre-bike--pick' + (on ? ' bk-pre-bike--sel' : '') + '" data-pick="' + b.clientKey + '" role="button" tabindex="0">'
              +   '<div class="bk-pre-bike-h">'
              +     '<span class="bk-pre-pickcheck"></span>'
              +     '<span class="bk-pre-bike-tag">From your account</span>'
              +   '</div>'
              +   '<div class="bk-pre-bike-fixed">' + escAttr(b.name) + '</div>'
              + '</div>';
      } else {
        shownIdx++;
        html += '<div class="bk-pre-bike"><div class="bk-pre-bike-h"><span class="bk-pre-bike-idx">+</span>';
        html += '<button type="button" class="bk-pre-bike-rm" data-k="' + b.clientKey + '">Remove</button>';
        html += '</div>';
        html += '<input type="text" class="bk-input bk-pre-bike-name" data-k="' + b.clientKey + '" placeholder="Name this ' + escAttr(d.assetSingular || 'item') + '" value="' + escAttr(b.name) + '">';
        html += '</div>';
      }
    });
    wrap.innerHTML = html;
    wrap.querySelectorAll('.bk-pre-bike--pick').forEach(function (card) {
      card.addEventListener('click', function () {
        var b = findAsset(card.getAttribute('data-pick'));
        if (b) { b.selected = (b.selected === false); renderBikes(); }
      });
    });
    wrap.querySelectorAll('.bk-pre-bike-name').forEach(function (inp) {
      inp.addEventListener('input', function () { var b = findAsset(inp.getAttribute('data-k')); if (b) b.name = inp.value; updateContinue(); });
    });
    wrap.querySelectorAll('.bk-pre-bike-rm').forEach(function (btn) {
      btn.addEventListener('click', function () { assets = assets.filter(function (x) { return x.clientKey !== btn.getAttribute('data-k'); }); renderBikes(); });
    });
    updateContinue();
  }

  el('bk-pre-add').addEventListener('click', function () {
    assets.push({ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }); renderBikes(); // MARKER-ITEMS-PICK
  });
  el('bk-pre-bikes-back').addEventListener('click', function () { showPanel('intro'); });

  el('bk-pre-bikes-continue').addEventListener('click', function () {
    var picked = assets
      .filter(function (b) { return b.selected !== false && (b.name || '').trim() !== ''; }) // MARKER-ITEMS-PICK
      .map(function (b) { return { clientKey: b.clientKey, name: b.name.trim(), customerAssetId: b.customerAssetId || null }; });
    if (!picked.length) return;

    // Hand off to the rest of booking.js (214b consumes these).
    window.BkAssets = picked;
    window.BkCustomer = { id: customerId, firstName: firstName, lastName: lastName, email: custEmail, phone: custPhone };
    // MARKER-RETURNING-PREFILL — shared with the refresh-restore path (214i logic lives there now)
    if (typeof window.bkApplyReturningCustomer === 'function') window.bkApplyReturningCustomer(window.BkCustomer);

    pre.classList.remove('active');
    document.querySelectorAll('.bk-step--pre').forEach(function (dot) { dot.classList.remove('active'); dot.classList.add('done'); });
    if (typeof window.goTo === 'function') window.goTo(1);
    if (typeof window.__bkInitAssetServices === 'function') window.__bkInitAssetServices(); // MARKER-PATCH-214c
  });
})();
HOLDREL_4_EOF

cat > 'bootstrap/app.php' <<'HOLDREL_5_EOF'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Append LogRequests to every web + api request so we capture the
        // full request lifecycle including the terminate() write. Runs last
        // in the stack so it sees the real response status.
        $middleware->append(\App\Http\Middleware\LogRequests::class);

        // Exclude Stripe webhook from CSRF — Stripe signs the request body.
        // Tenant booking webhook (/webhooks/stripe) and addon subscription
        // webhook (/webhooks/stripe/subscriptions) both need this.
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/stripe/*',
            'webhooks/cloudflare', // MARKER-PATCH-118
            'webhooks/ses-bounce',  // MARKER-PATCH-146
            'webhooks/postmark',    // MARKER-PATCH-201
            'webhooks/postmark/inbound', // MARKER-PATCH-403
            'webhooks/twilio/inbound', // MARKER-PATCH-221
            'webhooks/stripe-connect', // MARKER-PATCH-172D (Stripe Connect, patch 168)
            'webhooks/stripe-direct/*', // MARKER-PATCH-172D (Direct Payments, patch 170)
            'api/plan-quiz/*',
            'booking/abandon', // MARKER-RECOVERY — partial booking capture
            'funnel/track', // MARKER-FUNNEL-CSRF — anonymous analytics beacon
            'book/release-hold', // MARKER-HOLD-RELEASE — payment-failure beacon; token-authenticated, capacity-freeing only
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Route unhandled exceptions into the debug panel.
        // Runs in addition to Laravel's normal logging — doesn't replace it.
        $exceptions->report(function (\Throwable $e) {
            if (app()->bound(\App\Services\DebugLogService::class)) {
                app(\App\Services\DebugLogService::class)->error($e);
            }
        });

        // 500 error reference ID (patch #43)
        // Stamp a short reference ID on every 5xx so support can grep logs.
        // Also passes the ID into the 500 view as $errorRefId. The ID is
        // written into the log message via the report() hook above when
        // the exception bubbles up — same ID in both places.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // patch #45c: bail early for exceptions that Laravel handles
            // natively with redirects (auth, validation). Without this, the
            // render hook below would catch AuthenticationException and show
            // a 500 page instead of redirecting to login.
            if ($e instanceof \Illuminate\Auth\AuthenticationException) return null;
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) return null;
            if ($e instanceof \Illuminate\Validation\ValidationException) return null;
            if ($e instanceof \Illuminate\Session\TokenMismatchException) return null;

            // Only intercept 5xx-class errors. Symfony HttpException carries
            // its own status code; other Throwables default to 500.
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status < 500 || $status > 599) {
                return null; // let Laravel render normally (404, 419, etc.)
            }

            $refId = 'ERR-' . strtoupper(\Illuminate\Support\Str::random(8));

            // Surface the ref id in the log line so it can be grepped.
            \Illuminate\Support\Facades\Log::error('500 with refId: ' . $refId, [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'url'       => $request->fullUrl(),
            ]);

            return response()->view('errors.500', [
                'errorRefId' => $refId,
                'exception'  => $e,
            ], 500);
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('waitlist:expire')->dailyAt('02:15');
        $schedule->command('addons:expire')->dailyAt('02:30');
    })
    ->create();
HOLDREL_5_EOF

echo "applied — server: git pull, php artisan route:clear && php artisan route:cache, view:clear"
