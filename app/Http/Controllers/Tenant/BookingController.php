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
