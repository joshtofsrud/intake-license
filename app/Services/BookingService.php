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

        app(\App\Services\Tenant\SalePaymentService::class)->record(
            $sale,
            $amount,
            \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT,
            \App\Models\Tenant\TenantSalePayment::SOURCE_BOOKING_FLOW,
            'stripe',
            $piId,
            null,
            'Prepaid at online booking',
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

        $earliest = now()->addHours($minNoticeHours)->startOfDay();
        $latest   = now()->addDays($windowDays)->endOfDay();
        $start = Carbon::create($year, $month, 1)->max($earliest);
        $end   = Carbon::create($year, $month, 1)->endOfMonth()->min($latest);
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
        $earliest = now()->addHours($minNoticeHours);

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
}
