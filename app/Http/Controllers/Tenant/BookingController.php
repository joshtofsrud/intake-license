<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantFormSection;
use App\Models\Tenant\TenantReceivingMethod;
use App\Models\Tenant\TenantServiceCategory;
use App\Exceptions\LockAcquisitionException;
use App\Services\BookingService;
use App\Services\PayPalService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use RuntimeException;

class BookingController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        $catalog = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order')
                  ->with(['serviceAddons' => function ($sa) { $sa->orderBy('sort_order')->with('addon'); }]);
            }])
            ->get();

        $formSections = TenantFormSection::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->with(['fields' => function ($q) { $q->orderBy('sort_order'); }])
            ->get();

        $receivingMethods = TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('sort_order')->get();

        $s = $tenant->settings ?? [];
        $stripeEnabled = !empty($s['stripe_enabled']) && !empty($s['stripe_test_sk'] ?? $s['stripe_live_sk'] ?? '');
        $paypalEnabled = !empty($s['paypal_enabled']) && !empty($s['paypal_test_client_id'] ?? $s['paypal_live_client_id'] ?? '');
        $stripePublishableKey = '';
        if ($stripeEnabled) {
            $mode = $s['stripe_mode'] ?? 'test';
            $stripePublishableKey = $mode === 'live' ? ($s['stripe_live_pk'] ?? '') : ($s['stripe_test_pk'] ?? '');
        }
        $paypalClientId = '';
        if ($paypalEnabled) {
            $mode = $s['paypal_mode'] ?? 'sandbox';
            $paypalClientId = $mode === 'live' ? ($s['paypal_live_client_id'] ?? '') : ($s['paypal_test_client_id'] ?? '');
        }

        $bookingMode = $tenant->booking_mode ?? 'drop_off';

        $resources = $tenant->resources()->where('is_active', true)->get([
            'id', 'name', 'subtitle', 'color_hex', 'sort_order',
        ])->map(fn($r) => [
            'id'        => $r->id,
            'name'      => $r->name,
            'subtitle'  => $r->subtitle,
            'color_hex' => $r->color_hex,
        ])->values()->all();

        $bk = [
            'theme'          => $s['booking_theme'] ?? 'light',
            'accent'         => $s['booking_accent'] ?? '',
            'bg_tint'        => $s['booking_bg_tint'] ?? '#FFFFFF',
            'bg_opacity'     => $s['booking_bg_opacity'] ?? '100',
            'progress_bg'    => $s['booking_progress_bg'] ?? '',
            'progress_text'  => $s['booking_progress_text'] ?? '#000000',
            'body_text'      => $s['booking_body_text'] ?? '',
            'step1_label'    => $s['booking_step1_label'] ?? 'Services',
            'step2_label'    => $s['booking_step2_label'] ?? 'Schedule',
            'step3_label'    => $s['booking_step3_label'] ?? 'Details',
            'step4_label'    => $s['booking_step4_label'] ?? 'Review',
            'step1_heading'  => $s['booking_step1_heading'] ?? 'What do you need serviced?',
            'step2_heading'  => $s['booking_step2_heading'] ?? 'Pick a drop-off date',
            'step3_heading'  => $s['booking_step3_heading'] ?? 'Your details',
            'step4_heading'  => $s['booking_step4_heading'] ?? 'Review your order',
            'step1_sub'      => $s['booking_step1_sub'] ?? 'Select one or more services.',
            'step2_sub'      => $s['booking_step2_sub'] ?? 'Choose a date and tell us how you\'re dropping off.',
            'step3_sub'      => $s['booking_step3_sub'] ?? 'Who you are and anything we need to know.',
            'step4_sub'      => $s['booking_step4_sub'] ?? 'Confirm everything looks good.',
        ];

        return view('public.booking', compact(
            'catalog', 'formSections', 'receivingMethods',
            'stripeEnabled', 'paypalEnabled', 'stripePublishableKey', 'paypalClientId',
            'bookingMode', 'resources', 'bk'
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

        $dates = $booking->availableDates($tenant, $year, $month, $serviceId);

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
        ]);

        $tenant = tenant();

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

        if ($paymentMethod === 'stripe') {
            $stripe = new StripeService($tenant);
            if (!$stripe->isConfigured()) {
                return response()->json(['success' => false, 'message' => 'Stripe is not configured.'], 422);
            }
            $intent = $stripe->createPaymentIntent($appointment);
            return response()->json([
                'success' => true, 'payment' => 'stripe',
                'client_secret' => $intent['client_secret'],
                'ra_number' => $appointment->ra_number,
            ]);
        }

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

    public function stripeWebhook(Request $request)
    {
        try {
            StripeService::handleWebhook(tenant(), $request->getContent(), $request->header('Stripe-Signature', ''));
        } catch (\Throwable $e) {
            logger()->error('Stripe webhook error: ' . $e->getMessage());
            return response('error', 400);
        }
        return response('ok');
    }

    public function paypalWebhook(Request $request)
    {
        logger()->info('PayPal webhook received', $request->all());
        return response('ok');
    }
}
