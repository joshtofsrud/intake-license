#!/bin/bash
# timezone-wave1 — revenue- and money-visibility-facing timezone fixes from
# the merged audits. Server stays UTC; these make tenant-local day logic
# actually tenant-local.
#   · helpers: tenant_day_utc_range() — THE one way to bound a tenant-local
#     day when querying UTC timestamp columns (doc comment shows wrong/right)
#   · BookingService: availability window + min-notice computed in the
#     tenant timezone (Pacific tenants no longer lose the current day at
#     5 PM); expires_at instants deliberately untouched (correct as UTC)
#   · RentalBookingController: 3x sale_date + RD- numbering on tenant-local
#     dates (was UTC — evening rentals dated tomorrow)
#   · Dashboard today's-register-total: paid_at bounded by the tenant day's
#     UTC range (evening sales no longer vanish from the tile)
#   · Reports new-customers-per-day: created_at bucketed the same way
#   · Calendar breaks (2 sites): starts_at bounded the same way
#   · Special orders: upcoming-appointments window on tenant-local today
# No routes, no migrations. Wave 2 (DB session tz pin) ships separately.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-TZ-WAVE1" app/helpers.php; then
  echo "timezone-wave1 already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-FUNNEL-SESSION-FIX" resources/views/public/_funnel_tracker.blade.php; then
  echo "funnel-session-fix not applied — wrong base, aborting."; exit 1
fi

cat > 'app/helpers.php' <<'TZW1_0_EOF'
<?php

if (! function_exists('tenant')) {
    /**
     * Get the current tenant instance, or null if not in a tenant context.
     *
     * @return \App\Models\Tenant|null
     */
    function tenant(): ?\App\Models\Tenant
    {
        return app('tenant');
    }
}

if (! function_exists('tenant_url')) {
    /**
     * Generate a URL for the current tenant's public site.
     *
     * @param  string $path
     * @return string
     */
    function tenant_url(string $path = ''): string
    {
        $t = tenant();
        if (! $t) return url($path);

        // MARKER-PATCH-123 — delegate to Tenant::publicUrl() so custom
        // domains served via tenant_domains (and legacy custom_domain) are
        // both handled in one place.
        return $t->publicUrl() . '/' . ltrim($path, '/');
    }
}

if (! function_exists('format_money')) {
    /**
     * Format cents as a currency string using the current tenant's symbol.
     *
     * @param  int    $cents
     * @param  string $symbol  Fallback if no tenant in scope
     * @return string
     */
    function format_money(int $cents, string $symbol = '$'): string
    {
        $sym = tenant()?->currency_symbol ?? $symbol;
        return $sym . number_format($cents / 100, 2);
    }
}

if (! function_exists('tlocal')) {
    /**
     * MARKER-PATCH-189 — Render a UTC datetime instant in the current tenant's
     * timezone. THE canonical way to display any 'datetime'-cast column
     * (scheduled_at, starts_at, created_at, sent_at, …). Storing UTC and
     * converting at the edge is the standard; this makes the conversion
     * impossible to forget. For naive wall-clock values (appointment_time),
     * do NOT use this — those are already tenant-local and must not be shifted.
     *
     * @param  \Carbon\Carbon|\DateTimeInterface|string|null $instant  UTC instant
     * @param  string $format  PHP date format (default: '8:30 AM')
     * @return string          Empty string for null
     */
    function tlocal($instant, string $format = 'g:i A'): string
    {
        if ($instant === null || $instant === '') {
            return '';
        }
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $c  = $instant instanceof \Carbon\Carbon
            ? $instant->copy()
            : \Carbon\Carbon::parse($instant, 'UTC');
        // A bare string/DateTime is assumed UTC (matches how the DB stores
        // 'datetime' casts). Carbon instances already carry their own tz.
        return $c->setTimezone($tz)->format($format);
    }
}

if (! function_exists('tnow')) {
    /**
     * MARKER-PATCH-234C — "now" as a tenant-local Carbon. Use for
     * date-of-day boundaries the tenant will see (today's pickups, week
     * windows). For storage timestamps and created_at comparisons use plain
     * now() — those are UTC. Mirrors DashboardDataService::tnow().
     *
     * @return \Carbon\Carbon
     */
    function tnow(): \Carbon\Carbon
    {
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        return \Carbon\Carbon::now($tz);
    }
}

if (! function_exists('tlocal_date')) {
    /** Tenant-local date, e.g. "May 31, 2026". @see tlocal() */
    function tlocal_date($instant, string $format = 'M j, Y'): string
    {
        return tlocal($instant, $format);
    }
}

if (! function_exists('tlocal_datetime')) {
    /** Tenant-local date + time, e.g. "May 31, 2026 8:30 AM". @see tlocal() */
    function tlocal_datetime($instant, string $format = 'M j, Y g:i A'): string
    {
        return tlocal($instant, $format);
    }
}

if (! function_exists('tlocal_carbon')) {
    /**
     * Same conversion as tlocal() but returns the Carbon (for further work /
     * comparisons), not a formatted string. Returns null for null input.
     *
     * @return \Carbon\Carbon|null
     */
    function tlocal_carbon($instant): ?\Carbon\Carbon
    {
        if ($instant === null || $instant === '') {
            return null;
        }
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $c  = $instant instanceof \Carbon\Carbon
            ? $instant->copy()
            : \Carbon\Carbon::parse($instant, 'UTC');
        return $c->setTimezone($tz);
    }
}

if (! function_exists('debug_log')) {
    /**
     * Shortcut to the DebugLogService singleton.
     *
     *   debug_log()->error($exception);
     *   debug_log()->audit('settings_updated', 'Tenant updated', $tenant, $diff);
     *   debug_log()->mail($recipient, 'booking.confirmation');
     */
    function debug_log(): \App\Services\DebugLogService
    {
        return app(\App\Services\DebugLogService::class);
    }
}

if (! function_exists('tender_label')) {
    /**
     * MARKER-PATCH-630 — human label for a payment_method key.
     * 'cash_app' → 'Cash App', 'custom_house_account' → 'House account'.
     * Prefers the tenant's configured method name when available.
     */
    function tender_label(?string $key): string
    {
        if (!$key) return '';
        static $cache = [];
        $tid = function_exists('tenant') && app()->bound('tenant') && tenant() ? tenant()->id : null;
        $ck = ($tid ?? '-') . ':' . $key;
        if (isset($cache[$ck])) return $cache[$ck];

        $name = null;
        if ($tid) {
            try {
                $name = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $tid)
                    ->where('method_key', $key)->value('name');
            } catch (\Throwable $e) { /* table may not exist yet */ }
        }
        if (!$name) {
            $name = ucfirst(str_replace('_', ' ', preg_replace('/^custom_/', '', $key)));
        }
        return $cache[$ck] = $name;
    }
}

if (! function_exists('tenant_day_utc_range')) {
    /**
     * MARKER-TZ-WAVE1 — the ONE way to bound a tenant-local calendar day
     * when querying UTC timestamp columns. Returns [startUtc, endUtc)
     * for the given tenant-local day.
     *
     * WRONG: ->whereDate('paid_at', tnow()->toDateString())
     *        (compares the UTC date of the stored instant — evening rows
     *        land on tomorrow)
     * RIGHT: [$s, $e] = tenant_day_utc_range(tnow());
     *        ->where('paid_at', '>=', $s)->where('paid_at', '<', $e)
     *
     * @param  \Carbon\Carbon|string  $day  tenant-local day (Carbon or Y-m-d)
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    function tenant_day_utc_range(\Carbon\Carbon|string $day, ?string $tz = null): array
    {
        $tz ??= tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $local = $day instanceof \Carbon\Carbon
            ? $day->copy()->setTimezone($tz)->startOfDay()
            : \Carbon\Carbon::parse($day, $tz)->startOfDay();

        return [$local->copy()->utc(), $local->copy()->addDay()->utc()];
    }
}
TZW1_0_EOF

cat > 'app/Services/BookingService.php' <<'TZW1_1_EOF'
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
}
TZW1_1_EOF

cat > 'app/Http/Controllers/Tenant/RentalBookingController.php' <<'TZW1_2_EOF'
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
TZW1_2_EOF

cat > 'app/Services/Tenant/DashboardDataService.php' <<'TZW1_3_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Support\AppointmentStatus;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantInventoryItem;  // MARKER-PATCH-110-STEP-1
use App\Services\Tenant\CustomersReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardDataService
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * "Now" in tenant local time. Use for any date-of-day calculation
     * the tenant will see (greeting hour, today's appointments, week boundaries).
     * For storage timestamps and created_at/updated_at comparisons, use plain now() — those are UTC.
     */
    private function tnow(): Carbon
    {
        return Carbon::now($this->tenant->timezone());
    }

    public function greeting(?object $user = null): array
    {
        $hour = (int) $this->tnow()->format('G');
        $timeOfDay = match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default    => 'evening',
        };

        $name = null;
        if ($user && $user->name) {
            $name = trim(explode(' ', $user->name)[0]);
        }

        return [
            'time_of_day' => $timeOfDay,
            'name'        => $name,
            'date_long'   => $this->tnow()->format('l, F j'),
        ];
    }

    public function zoneToday(): array
    {
        $today = $this->tnow()->toDateString();
        $weekStart = $this->tnow()->startOfWeek()->toDateString();

        $todayAppointments = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereDate('appointment_date', $today)
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with(['items', 'customer'])
            ->get();

        $nextUp = $todayAppointments->first(function ($a) {
            if (!$a->appointment_time) return false;
            // MARKER-PATCH-362 — appointment_time is naive tenant-local wall-clock;
            // parse it in the tenant timezone so "is it still upcoming?" compares
            // real instants against tnow() (was ~7h early, which hid the next-up
            // banner for genuinely upcoming appointments).
            $apptDateTime = Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time, $this->tenant->timezone());
            return $apptDateTime->greaterThanOrEqualTo($this->tnow());
        });

        // Patch 47: no fallback to first-of-day. If today's appointments are all
        // in the past, $nextUp stays null and the Blade hides the card. Showing
        // a completed 8am appointment as "Next up" at 9pm is worse than hiding
        // the card entirely. Future: fall through to tomorrow's first appointment.
        // (No fallback assignment — $nextUp may legitimately be null.)

        $last24hNewBookings = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $weekBase = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekStart, $today]);

        $weekBookings = (clone $weekBase)->count();
        // MARKER-PATCH-185 — week revenue = payments received (sale ledger).
        $tzW = $this->tenant->timezone();
        // $weekStart is a Y-m-d string; parse in tenant tz for the UTC window.
        $weekRevenue = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [
                Carbon::parse($weekStart, $tzW)->startOfDay()->utc(),
                $this->tnow()->copy()->setTimezone($tzW)->endOfDay()->utc(),
            ])
            ->sum('amount_cents');
        $weekCancellations = (clone $weekBase)->whereIn('status', AppointmentStatus::terminalStatuses())->count();

        $weekNewCustomers = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $weekStart)
            ->count();

        // MARKER-PATCH-183 — today's deliveries for the dashboard mini-section.
        $todayDeliveries = collect();
        try {
            $todayDeliveries = (new \App\Services\Tenant\TenantDeliveryService($this->tenant))
                ->forDay($this->tnow());
        } catch (\Throwable $e) {
            $todayDeliveries = collect();
        }

        return [
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'today_deliveries'    => $todayDeliveries,
            'next_up'             => $nextUp,
            'last_24h_bookings'   => $last24hNewBookings,
            'week_bookings'       => $weekBookings,
            'week_revenue_cents'  => $weekRevenue,
            'week_new_customers'  => $weekNewCustomers,
            'week_cancellations'  => $weekCancellations,
            'strip'               => $this->build7DayStripCenteredOn($this->tnow()->startOfDay()),
        ];
    }

    public function zoneAttention(): array
    {
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->toDateString();

        $unconfirmedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::awaitingStatuses())
            ->whereDate('appointment_date', '>=', $today)
            ->count();

        // MARKER-PICKUP-OUTREACH
        $pickupOutreachCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('pickup_outreach_pending', true)
            ->count();

        $unpaidDoneCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $unpaidDoneSumCents = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->sum(DB::raw('total_cents - paid_cents'));

        $readyPickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $waitlistCount = 0;
        if (class_exists(TenantWaitlistEntry::class)) {
            try {
                $waitlistCount = TenantWaitlistEntry::where('tenant_id', $tenantId)
                    ->where('status', 'waiting')
                    ->count();
            } catch (\Throwable $e) {
                $waitlistCount = 0;
            }
        }

        // MARKER-PATCH-539 — completed jobs with no scheduled drop-off (P&D tenants).
        // No-reply proposals (customer never picked from the options link) called out.
        $awaitingDeliveryCount = 0;
        $awaitingNoReplyCount  = 0;
        if ($this->tenant->deliveries_enabled) {
            $base = TenantAppointment::query()
                ->where('tenant_appointments.tenant_id', $tenantId)
                ->where('tenant_appointments.status', 'completed')
                ->whereNotNull('tenant_appointments.completed_at')
                ->where('tenant_appointments.completed_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('tenant_deliveries')
                        ->whereColumn('tenant_deliveries.appointment_id', 'tenant_appointments.id')
                        ->where('tenant_deliveries.type', 'dropoff')
                        ->where('tenant_deliveries.status', '!=', 'cancelled');
                });
            $awaitingDeliveryCount = (clone $base)->count();
            if ($awaitingDeliveryCount > 0) {
                $awaitingNoReplyCount = (clone $base)
                    ->whereExists(function ($q) use ($tenantId) {
                        $q->selectRaw('1')
                            ->from('tenant_delivery_proposals')
                            ->whereColumn('tenant_delivery_proposals.appointment_id', 'tenant_appointments.id')
                            ->where('tenant_delivery_proposals.tenant_id', $tenantId)
                            ->where('tenant_delivery_proposals.status', 'no_reply');
                    })->count();
            }
        }

        $cards = [];

        if ($awaitingDeliveryCount > 0) {
            $singular = $this->tenant->asset_label_singular ?: 'job';   // MARKER-PATCH-539
            $plural   = $this->tenant->asset_label_plural ?: 'jobs';
            $cards[] = [
                'count' => $awaitingDeliveryCount,
                'title' => 'Awaiting delivery',
                'key'   => 'awaiting_delivery',
                'icon'  => '🚚',
                'desc'  => ($awaitingDeliveryCount === 1
                        ? "1 completed {$singular} with no drop-off scheduled"
                        : "{$awaitingDeliveryCount} completed {$plural} with no drop-off scheduled")
                    . ($awaitingNoReplyCount > 0 ? " — {$awaitingNoReplyCount} never replied to the options link" : ''),
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index', ['filter' => 'awaiting_delivery']),
            ];
        }

        if ($unconfirmedCount > 0) {
            $cards[] = [
                'count' => $unconfirmedCount,
                'title' => 'Pending bookings',
                'key'   => 'pending_bookings',
                'icon'  => '🛎️',
                'desc'  => $unconfirmedCount === 1
                    ? '1 booking awaiting confirmation or drop-off'
                    : $unconfirmedCount . ' bookings awaiting confirmation or drop-off',
                'tone'  => 'red',  // your action: review and confirm
                'link'  => route('tenant.appointments.index', ['filter' => 'unconfirmed_bookings']),
            ];
        }

        // MARKER-PICKUP-OUTREACH — bookings that asked for pickup outreach
        if ($pickupOutreachCount > 0) {
            $cards[] = [
                'count' => $pickupOutreachCount,
                'title' => 'Pickup to arrange',
                'key'   => 'pickup_outreach',
                'icon'  => '🚚',
                'desc'  => $pickupOutreachCount === 1
                    ? '1 booking asked you to reach out about pickup'
                    : $pickupOutreachCount . ' bookings asked you to reach out about pickup',
                'tone'  => 'amber',  // your action: contact and schedule
                'link'  => route('tenant.appointments.index', ['filter' => 'pickup_outreach']),
            ];
        }

        if ($unpaidDoneCount > 0) {
            $cards[] = [
                'count' => $unpaidDoneCount,
                'title' => 'Unpaid completed jobs',
                'key'   => 'unpaid_completed',
                'icon'  => '💳',
                'desc'  => '$' . number_format($unpaidDoneSumCents / 100, 0) . ' outstanding on finished work',
                'tone'  => 'amber',  // customer's action: send payment
                'link'  => route('tenant.appointments.index', ['filter' => 'unpaid_completed']),
            ];
        }

        if ($readyPickupCount > 0) {
            $cards[] = [
                'count' => $readyPickupCount,
                'title' => 'Ready for pickup',
                'key'   => 'ready_pickup',
                'icon'  => '✅',
                'desc'  => $readyPickupCount === 1
                    ? 'Customer ready to receive their bike'
                    : 'Customers ready to receive their bikes',
                'tone'  => 'amber',  // customer's action: collect their item
                'link'  => route('tenant.appointments.index', ['filter' => 'ready_pickup']),
            ];
        }

        if ($waitlistCount > 0) {
            $cards[] = [
                'count' => $waitlistCount,
                'title' => 'Waitlist entries',
                'key'   => 'waitlist',
                'icon'  => '⏳',
                'desc'  => $waitlistCount === 1
                    ? 'Customer waiting for an opening'
                    : 'Customers waiting for an opening',
                'tone'  => 'amber',  // customer's action: accept the opening (waitlist page, not appointments)
                'link'  => route('tenant.waitlist.index'),
            ];
        }

        // ---- Overdue categories ----
        $overdueUnstartedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::notStartedStatuses())
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueUnstartedCount > 0) {
            $cards[] = [
                'count' => $overdueUnstartedCount,
                'title' => 'Overdue: not started',
                'key'   => 'overdue_unstarted',
                'icon'  => '⏰',
                'desc'  => $overdueUnstartedCount === 1
                    ? 'Appointment past its scheduled date and never started'
                    : 'Appointments past their scheduled date and never started',
                'tone'  => 'red',
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_unstarted']),
            ];
        }

        $overdueInProgressCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::inProgressStatuses())
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueInProgressCount > 0) {
            $cards[] = [
                'count' => $overdueInProgressCount,
                'title' => 'Overdue: in progress',
                'key'   => 'overdue_in_progress',
                'icon'  => '🔧',
                'desc'  => $overdueInProgressCount === 1
                    ? 'Job started but not closed out'
                    : 'Jobs started but not closed out',
                'tone'  => 'red',  // your action: close out the job (more concerning than unstarted)
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_in_progress']),
            ];
        }

        $stalePickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('updated_at', '<', now()->subDays(3))
            ->count();

        if ($stalePickupCount > 0) {
            $cards[] = [
                'count' => $stalePickupCount,
                'title' => 'Stale pickups',
                'key'   => 'stale_pickups',
                'icon'  => '📅',
                'desc'  => $stalePickupCount === 1
                    ? 'Completed 3+ days ago, customer not collected'
                    : 'Completed 3+ days ago, customers not collected',
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index', ['filter' => 'stale_pickups']),
            ];
        }

        // patch-92 SO triage cards — appended to the existing rule set.
        // Arrived: status=arrived (waiting on staff to pull from bench).
        // Overdue: status=ordered AND expected_arrival_date past today
        //   (vendor missed promised date, chase them).
        // MARKER-PATCH-422 — Needed: status=needed (soft request, not yet ordered from a vendor).
        $soNeededCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
            ->count();

        if ($soNeededCount > 0) {
            $cards[] = [
                'count' => $soNeededCount,
                'title' => 'Special orders to place',
                'key'   => 'so_needed',
                'icon'  => '🛒',
                'desc'  => $soNeededCount === 1
                    ? 'Customer part not yet ordered from a vendor'
                    : 'Customer parts not yet ordered from a vendor',
                'tone'  => 'amber',  // your action: pick a vendor + place the order
                'link'  => route('tenant.special-orders.index'),
            ];
        }

        $soArrivedCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ARRIVED)
            ->count();

        if ($soArrivedCount > 0) {
            $cards[] = [
                'count' => $soArrivedCount,
                'title' => 'Special orders arrived',
                'key'   => 'so_arrived',
                'icon'  => '📦',
                'desc'  => $soArrivedCount === 1
                    ? 'Customer part on the bench, ready to pull and notify'
                    : 'Customer parts on the bench, ready to pull and notify',
                'tone'  => 'amber',  // your action: pull from bench + tell customer
                'link'  => route('tenant.special-orders.index', ['view' => 'arrived_bench']),
            ];
        }

        $soOverdueCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
            ->whereNotNull('expected_arrival_date')
            ->whereDate('expected_arrival_date', '<', $today)
            ->count();

        if ($soOverdueCount > 0) {
            $cards[] = [
                'count' => $soOverdueCount,
                'title' => 'Special orders overdue',
                'key'   => 'so_overdue',
                'icon'  => '⚠️',
                'desc'  => $soOverdueCount === 1
                    ? 'Vendor missed expected arrival — chase them'
                    : 'Vendors missed expected arrivals — chase them',
                'tone'  => 'red',  // your action: contact vendor about delay
                'link'  => route('tenant.special-orders.index', ['view' => 'overdue']),
            ];
        }

        // patch-102 location-scoped transfer tiles — show two separate tiles
        // when relevant: items needing to be SENT FROM here, and items
        // currently IN TRANSIT TO here.
        $sessionLocId = session('current_location_id');

        if ($sessionLocId) {
            $toSendCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->where('from_location_id', $sessionLocId)
                ->count();

            if ($toSendCount > 0) {
                $cards[] = [
                    'count' => $toSendCount,
                    'title' => 'Transfers to send',
                    'key'   => 'transfers_to_send',
                    'icon'  => '📤',
                    'desc'  => $toSendCount === 1
                        ? 'Another location is asking for stock from here'
                        : 'Other locations are asking for stock from here',
                    'tone'  => 'amber',
                    'link'  => route('tenant.transfer-requests.index', ['view' => 'to_send']),
                ];
            }

            $toReceiveCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
                ->where('status', 'in_transit')
                ->where('to_location_id', $sessionLocId)
                ->count();

            if ($toReceiveCount > 0) {
                $cards[] = [
                    'count' => $toReceiveCount,
                    'title' => 'Transfers arriving',
                    'key'   => 'transfers_arriving',
                    'icon'  => '📥',
                    'desc'  => $toReceiveCount === 1
                        ? 'Stock is in transit to this location'
                        : 'Stock items are in transit to this location',
                    'tone'  => 'blue',
                    'link'  => route('tenant.transfer-requests.index', ['view' => 'to_receive']),
                ];
            }
        }

        // MARKER-PATCH-110-STEP-2 — Low stock + Win-back triage rules
        // Both rules are tenant-scoped and use existing indexed columns.

        // Low stock: items at or below shop_reorder_threshold. Mirrors the
        // 'stock=low' filter on the inventory index. NULL threshold = item
        // isn't being tracked for reorder, so it's excluded.
        $lowStockCount = TenantInventoryItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('shop_reorder_threshold')
            ->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold')
            ->count();

        if ($lowStockCount > 0) {
            $cards[] = [
                'count' => $lowStockCount,
                'title' => 'Low stock',
                'key'   => 'low_stock',
                'icon'  => '📉',
                'desc'  => $lowStockCount === 1
                    ? 'Item at or below its reorder threshold'
                    : 'Items at or below their reorder thresholds',
                'tone'  => 'amber',  // your action: plan replenishment
                'link'  => route('tenant.inventory.index', ['stock' => 'low']),
            ];
        }

        // MARKER-PATCH-609 — catalog attention: open pricing/MAP/MSRP flags from
        // distributor sync. Same count as the Catalog attention page header.
        try {
            $catalogAttn = \App\Models\Tenant\TenantPricingAttentionFlag::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('status', 'open')
                ->count();
        } catch (\Throwable $e) {
            $catalogAttn = 0;
        }

        if ($catalogAttn > 0) {
            $cards[] = [
                'count' => $catalogAttn,
                'title' => 'Catalog attention',
                'key'   => 'catalog_attention',
                'icon'  => '🏷️',
                'desc'  => $catalogAttn === 1
                    ? 'Price or MAP change from your distributor needs review'
                    : 'Price or MAP changes from your distributor need review',
                'tone'  => 'amber',
                'link'  => route('tenant.distributors.attention'),
            ];
        }

        // Win-back: customers lapsed 180+ days (had a delivered appointment
        // but not in 180+ days). Delegates to CustomersReportService for the
        // same definition as the Reports → Customers tab, so the numbers
        // agree across surfaces. aggregatesOnly skips the heavy list query.
        try {
            $lapsed = (new CustomersReportService($this->tenant))
                ->lapsedCustomers(aggregatesOnly: true);
            $winbackCount = (int) ($lapsed['lapsed_count'] ?? 0);
        } catch (\Throwable $e) {
            $winbackCount = 0;
        }

        if ($winbackCount > 0) {
            $cards[] = [
                'count' => $winbackCount,
                'title' => 'Win-back candidates',
                'key'   => 'win_back',
                'icon'  => '👋',
                'desc'  => $winbackCount === 1
                    ? 'Customer has not been in for 180+ days'
                    : 'Customers have not been in for 180+ days',
                'tone'  => 'violet',  // your action: start a re-engagement campaign
                'link'  => route('tenant.customers.index'),
            ];
        }

        return [
            'cards'       => $cards,
            'total_items' => count($cards),
        ];
    }

    public function zoneGrowth(): array
    {
        // MARKER-PATCH-115 — match Reports' revenue definition:
        //   - status IN ('completed','closed') so only delivered work counts
        //   - 30-day window inclusive of today (Reports' last_30 uses the
        //     same subDays(29) bound).
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->endOfDay();
        $thirtyAgo = $this->tnow()->subDays(29)->startOfDay();   // start of current 30d window
        $sixtyAgo  = $this->tnow()->subDays(59)->startOfDay();   // start of prior 30d window

        // MARKER-PATCH-185 — revenue = payments received (sale ledger), matching
        // Reports. recorded_at is UTC; bound by tenant-local windows -> UTC.
        $tzG = $this->tenant->timezone();
        $curStart = $thirtyAgo->copy()->setTimezone($tzG)->startOfDay()->utc();
        $curEnd   = $today->copy()->setTimezone($tzG)->endOfDay()->utc();
        $priStart = $sixtyAgo->copy()->setTimezone($tzG)->startOfDay()->utc();
        $priEnd   = $thirtyAgo->copy()->subDay()->setTimezone($tzG)->endOfDay()->utc();

        $revenueCurrent = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$curStart, $curEnd])
            ->sum('amount_cents');

        $revenuePrior = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$priStart, $priEnd])
            ->sum('amount_cents');

        $revenueDelta = $revenuePrior > 0
            ? round((($revenueCurrent - $revenuePrior) / $revenuePrior) * 100)
            : null;

        $customersCurrent = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$thirtyAgo, $today])
            ->count();

        $customersPrior = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$sixtyAgo, $thirtyAgo->copy()->subDay()])
            ->count();

        $customersDelta = $customersPrior > 0
            ? round((($customersCurrent - $customersPrior) / $customersPrior) * 100)
            : null;

        $revenueSpark = $this->dailyRevenueSeries($tenantId, $thirtyAgo, $today);
        $customersSpark = $this->dailyCustomerSeries($tenantId, $thirtyAgo, $today);

        // Honest operational health. Each item: ['label', 'detail', 'status'].
        // Statuses match dashboard.css: 'ok' (green), 'warn' (amber), 'err' (red/grey).
        $health = [];

        // Payment processing — driven by actual processor connection state. Stripe
        // Connect / PayPal / Square aren't wired yet, so until a tenant finishes a
        // real connection flow this stays 'err' (or 'warn' if they recorded intent).
        $ppStatus = $this->tenant->payment_processor_status ?? 'not_started';
        $ppLabel  = ucfirst($this->tenant->payment_processor ?? 'Processor');
        $health[] = match ($ppStatus) {
            'connected' => [
                'label'  => 'Payment processing',
                'detail' => $ppLabel . ' connected',
                'status' => 'ok',
            ],
            'intent_recorded', 'connecting' => [
                'label'  => 'Payment processing',
                'detail' => 'Pending — finish ' . $ppLabel . ' setup',
                'status' => 'warn',
            ],
            default => [
                'label'  => 'Payment processing',
                'detail' => 'Not connected',
                'status' => 'err',
            ],
        };

        // Website — fresh tenants seed Home as is_published=false. Any published
        // page means the tenant has actually pushed something live.
        $publishedCount = \App\Models\Tenant\TenantPage::where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->count();
        $health[] = $publishedCount > 0
            ? [
                'label'  => 'Website',
                'detail' => $publishedCount . ' page' . ($publishedCount === 1 ? '' : 's') . ' published',
                'status' => 'ok',
            ]
            : [
                'label'  => 'Website',
                'detail' => 'No pages published yet',
                'status' => 'err',
            ];

        // Email deliverability — bounce/complaint webhook tracking isn't wired yet.
        // Until we have real signal, show 'warn' rather than fake green.
        $health[] = [
            'label'  => 'Email deliverability',
            'detail' => 'Setup not complete',
            'status' => 'warn',
        ];

        return [
            'revenue' => [
                'current_cents' => $revenueCurrent,
                'prior_cents'   => $revenuePrior,
                'delta_pct'     => $revenueDelta,
                'sparkline'     => $revenueSpark,
            ],
            'customers' => [
                'current'   => $customersCurrent,
                'prior'     => $customersPrior,
                'delta_pct' => $customersDelta,
                'sparkline' => $customersSpark,
            ],
            'health' => $health,
        ];
    }

    public function onboardingProgress(bool $dismissedThisSession): array
    {
        $tenant = $this->tenant;

        $brandingDone = !empty($tenant->logo_url)
            || (!empty($tenant->accent_color) && $tenant->accent_color !== '#BEF264')
            || !empty($tenant->tagline);

        $servicesDone = TenantServiceItem::where('tenant_id', $tenant->id)->exists();
        $hoursDone    = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        $allDone = $brandingDone && $servicesDone && $hoursDone;

        return [
            'branding'   => $brandingDone,
            'services'   => $servicesDone,
            'hours'      => $hoursDone,
            'all_done'   => $allDone,
            // The 8-step onboarding wizard replaces this modal entirely. The
            // dashboard now redirects incomplete tenants to the wizard up front,
            // so the modal never needs to fire. Leaving the field for backward
            // compatibility with the Blade partial; flag is permanently false.
            'show_modal' => false,
        ];
    }

    /**
     * MARKER-PATCH-110-STEP-3
     * Launcher tile sub-stats. One DB hit per stat where the data isn't
     * already in zoneToday/zoneAttention. Order matters — tiles render
     * in array order.
     *
     * Cheap stats only: counts and simple aggregates. Anything that would
     * require a join across 3+ tables stays static label-only for now.
     */
    public function zoneLauncher(array $today, array $attention): array
    {
        $tenantId = $this->tenant->id;
        $todayStr = $this->tnow()->toDateString();

        // Today's register total. Sums tenant_sales paid today.
        // MARKER-TZ-WAVE1 — paid_at is a UTC instant; whereDate() compared
        // its UTC date to the tenant-local date, so evening sales vanished
        // from today's tile. Compare against the tenant day's UTC range.
        [$dayStartUtc, $dayEndUtc] = tenant_day_utc_range($this->tnow());
        $todaySalesTotal = (int) DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('paid_at', '>=', $dayStartUtc)
            ->where('paid_at', '<',  $dayEndUtc)
            ->where('payment_status', 'paid')
            ->sum('total_cents');

        // Customer count — single COUNT, cheap.
        $customerCount = (int) DB::table('tenant_customers')
            ->where('tenant_id', $tenantId)
            ->count();

        // Waitlist count — same query as zoneAttention waitlist card uses.
        $waitlistCount = 0;
        if (class_exists(TenantWaitlistEntry::class)) {
            try {
                $waitlistCount = TenantWaitlistEntry::where('tenant_id', $tenantId)
                    ->where('status', 'waiting')
                    ->count();
            } catch (\Throwable $e) {
                $waitlistCount = 0;
            }
        }

        // Inventory counts — active items, plus low-stock pulled from
        // already-computed attention cards if present.
        $activeItemsCount = (int) TenantInventoryItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $lowStockCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Low stock')['count'] ?? 0;

        // Special order counts — pulled from existing attention cards.
        $soArrivedCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Special orders arrived')['count'] ?? 0;
        $soOverdueCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Special orders overdue')['count'] ?? 0;

        // Services count — active service items, single COUNT.
        $servicesCount = (int) TenantServiceItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Resources count — active resources.
        $resourcesCount = (int) TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Staff count — tenant_users (excluding owner, if needed).
        $staffCount = (int) TenantUser::where('tenant_id', $tenantId)->count();

        // Published page count — same query zoneGrowth uses for health.
        $publishedPageCount = (int) \App\Models\Tenant\TenantPage::where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->count();

        return [
            'calendar' => [
                'today_count' => $today['today_count'] ?? 0,
                'cap'         => null,  // Cap calculation deferred — needs resource summation.
            ],
            'register' => [
                'today_total_cents' => $todaySalesTotal,
            ],
            'customers' => [
                'count' => $customerCount,
            ],
            'waitlist' => [
                'count' => $waitlistCount,
            ],
            'inventory' => [
                'active_count'    => $activeItemsCount,
                'low_stock_count' => $lowStockCount,
            ],
            'special_orders' => [
                'arrived_count' => $soArrivedCount,
                'overdue_count' => $soOverdueCount,
            ],
            'services' => [
                'count' => $servicesCount,
            ],
            'resources' => [
                'count'       => $resourcesCount,
                'staff_count' => $staffCount,
            ],
            'pages' => [
                'published_count' => $publishedPageCount,
            ],
        ];
    }

    private function dailyRevenueSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        // MARKER-PATCH-185 — daily revenue spark = payments received (ledger),
        // bucketed by recorded_at in tenant tz.
        $tzS = $this->tenant->timezone();
        $offS = Carbon::now($tzS)->utcOffset() * 60;
        $rows = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [
                $from->copy()->setTimezone($tzS)->startOfDay()->utc(),
                $to->copy()->setTimezone($tzS)->endOfDay()->utc(),
            ])
            ->selectRaw('DATE(DATE_ADD(recorded_at, INTERVAL ? SECOND)) as d, SUM(amount_cents) as cents', [$offS])
            ->groupBy('d')
            ->pluck('cents', 'd')
            ->toArray();

        return $this->fillDailySeries($from, $to, $rows, 0);
    }

    private function dailyCustomerSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->toArray();

        return $this->fillDailySeries($from, $to, $rows, 0);
    }

    private function fillDailySeries(Carbon $from, Carbon $to, array $rows, int|float $default): array
    {
        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $series[] = (int) ($rows[$key] ?? $default);
            $cursor->addDay();
        }
        return $series;
    }

    /**
     * Banner state for prompting tenants to set up work order fields.
     * Returns null if no banner should show.
     */
    /**
     * Returns appointment list + counts for a specific date + 7-day strip counts.
     * Used by both server render (for initial dashboard load with ?date=) and
     * the AJAX day-swap endpoint.
     */
    public function dayData(string $date): array
    {
        $tenantId = $this->tenant->id;
        $target = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        $appointments = TenantAppointment::where('tenant_id', $tenantId)
            ->whereDate('appointment_date', $target->toDateString())
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with('items')
            ->get();

        // 7-day strip: 3 days before, target, 3 days after.
        // Level (0-3) powers the heatmap-style load indicator on each day card.
        $strip = $this->build7DayStripCenteredOn($target);

        return [
            'target_date'       => $target->toDateString(),
            'target_date_long'  => $target->format('l, F j'),
            'appointments'      => $appointments,
            'appointment_count' => $appointments->count(),
            'strip'             => $strip,
        ];
    }

    /**
     * Build the 7-day strip array (3 days before, target, 3 days after)
     * with appointment counts and a 0-3 load level for each day. Used by
     * both the initial dashboard render (zoneToday) and the AJAX day-swap
     * endpoint (dayData).
     */
    private function build7DayStripCenteredOn(\Illuminate\Support\Carbon $target): array
    {
        $tenantId = $this->tenant->id;

        $activeResourceCount = max(1, TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count());

        $rulesByDow = TenantCapacityRule::where('tenant_id', $tenantId)
            ->where('rule_type', 'default')
            ->get()
            ->keyBy('day_of_week');

        $strip = [];
        for ($i = -3; $i <= 3; $i++) {
            $d = $target->copy()->addDays($i);
            $count = TenantAppointment::where('tenant_id', $tenantId)
                ->whereDate('appointment_date', $d->toDateString())
                ->whereNotIn('status', AppointmentStatus::terminalStatuses())
                ->count();

            $strip[] = [
                'date'       => $d->toDateString(),
                'day_short'  => $d->format('D'),
                'day_num'    => (int) $d->format('j'),
                'is_today'   => $d->isToday(),
                'is_target'  => $i === 0,
                'count'      => $count,
                'load_level' => $this->loadLevelForDay($d, $count, $rulesByDow, $activeResourceCount),
            ];
        }
        return $strip;
    }

    /**
     * Compute a 0-3 load level for a given day based on appointment count
     * vs. theoretical max slots (capacity rule's open hours × resources).
     * 0 = closed or zero appointments
     * 1 = 1-33% full
     * 2 = 34-66% full
     * 3 = 67-100% full
     */
    private function loadLevelForDay(
        \Illuminate\Support\Carbon $date,
        int $count,
        \Illuminate\Support\Collection $rulesByDow,
        int $activeResourceCount
    ): int {
        if ($count === 0) {
            return 0;
        }
        $rule = $rulesByDow->get($date->dayOfWeek);
        if (!$rule || !$rule->open_time || !$rule->close_time) {
            // Day with bookings but no capacity rule: show light load.
            return 1;
        }
        $open  = \Illuminate\Support\Carbon::parse($date->toDateString() . ' ' . $rule->open_time);
        $close = \Illuminate\Support\Carbon::parse($date->toDateString() . ' ' . $rule->close_time);
        $intervalMin = max(1, (int) ($rule->slot_interval_minutes ?? 30));
        $minutesOpen = max(0, $close->diffInMinutes($open));
        $slotsPerResource = intdiv($minutesOpen, $intervalMin);
        $maxSlots = max(1, $slotsPerResource * $activeResourceCount);
        $ratio = $count / $maxSlots;
        if ($ratio >= 0.67) return 3;
        if ($ratio >= 0.34) return 2;
        return 1;
    }

        public function workOrderBanner(bool $dismissed): ?array
    {
        if ($dismissed) { return null; }

        $hasFields = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $this->tenant->id)
            ->exists();

        if ($hasFields) { return null; }

        return [
            'title' => 'Set up your work order fields',
            'body'  => 'Track serial numbers, models, and whatever else your team needs to record when receiving a job. Tenants in your industry usually configure this once and forget about it.',
            'cta_label' => 'Configure now',
            'cta_url' => route('tenant.work-order-fields.index'),
        ];
    }

}
TZW1_3_EOF

cat > 'app/Services/Tenant/ReportsDataService.php' <<'TZW1_4_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportsDataService
 *
 * Phase 3: single global date range drives every zone. Each zone method
 * takes Carbon $from and Carbon $to directly. The controller is responsible
 * for parsing 'today' | 'week' | 'month' | 'custom' into a date pair.
 *
 * Capacity zone is the one exception — it falls back to the last 14 days
 * when the requested range is shorter than 7 days, since the day-of-week ×
 * hour heatmap needs density to be readable.
 */
class ReportsDataService
{
    private const DELIVERED_STATUSES = ['completed', 'closed'];
    private const CANCELLED_STATUSES = ['cancelled'];
    private const REFUNDED_STATUSES  = ['refunded'];

    public function __construct(private readonly Tenant $tenant) {}

    /** Top KPI row — always shows today's snapshot regardless of range. */
    public function topKpis(): array
    {
        $today = $this->tenant->localToday();
        $lastWeekSameDay = $today->copy()->subWeek();

        $todayRevenue = $this->revenueForDate($today);
        $lastWkRevenue = $this->revenueForDate($lastWeekSameDay);

        $todayBookings = $this->bookingCountForDate($today);
        $lastWkBookings = $this->bookingCountForDate($lastWeekSameDay);

        $todayCapacity = $this->capacityForDate($today);

        $thirtyDayNoShowRate = $this->noShowRateForRange(
            $today->copy()->subDays(29), $today
        );
        $todayNoShowCount = $this->noShowCountForDate($today);

        $todayNewCust = $this->newCustomerCountForDate($today);
        $lastWkNewCust = $this->newCustomerCountForDate($lastWeekSameDay);

        return [
            [
                'label'         => 'Revenue today',
                'value_dollars' => $todayRevenue / 100,
                'delta'         => $this->deltaPercent($todayRevenue, $lastWkRevenue),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'money',
            ],
            [
                'label'         => 'Bookings',
                'value_int'     => $todayBookings,
                'capacity'      => $todayCapacity,
                'delta'         => $this->deltaCount($todayBookings, $lastWkBookings),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'count',
            ],
            [
                'label'         => 'No-show rate',
                'value_int'     => round($thirtyDayNoShowRate * 100),
                'detail'        => $todayNoShowCount . ' today',
                'period_label'  => 'trailing 30 days',
                'format'        => 'percent',
            ],
            [
                'label'         => 'New customers today',
                'value_int'     => $todayNewCust,
                'delta'         => $this->deltaCount($todayNewCust, $lastWkNewCust),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'count',
            ],
        ];
    }

    /** Zone 1: Revenue. */
    public function zoneRevenue(Carbon $from, Carbon $to): array
    {
        // MARKER-PATCH-184 — revenue now reads the SALE PAYMENT LEDGER
        // ("Payments Received", cash-basis), not appointment totals. Payments
        // are signed (refunds negative) so the ledger nets correctly. recorded_at
        // is stored UTC; we bound by the tenant-local day window converted to UTC,
        // and bucket the series by recorded_at shifted into the tenant timezone.
        $tz = $this->tenant->timezone();
        $isSingleDay = $from->isSameDay($to);

        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();

        // Offset (seconds) from UTC to tenant-local, for SQL bucketing.
        $offsetSec = Carbon::now($tz)->utcOffset() * 60;

        $base = DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$winStart, $winEnd]);

        $totalCents = (int) (clone $base)->sum('amount_cents');

        $series = [];
        if ($isSingleDay) {
            $hourly = (clone $base)
                ->selectRaw("HOUR(DATE_ADD(recorded_at, INTERVAL ? SECOND)) as hour, SUM(amount_cents) as cents, COUNT(*) as n", [$offsetSec])
                ->groupBy('hour')
                ->get()
                ->keyBy('hour');
            for ($h = 8; $h <= 18; $h++) {
                $row = $hourly->get($h);
                $series[] = [
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        } else {
            $daily = (clone $base)
                ->selectRaw("DATE(DATE_ADD(recorded_at, INTERVAL ? SECOND)) as d, SUM(amount_cents) as cents, COUNT(*) as n", [$offsetSec])
                ->groupBy('d')
                ->get()
                ->keyBy('d');
            $days = $from->diffInDays($to);
            $labelFmt = $days <= 7 ? 'D' : ($days <= 31 ? 'j' : 'M j');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $row = $daily->get($d->toDateString());
                $series[] = [
                    'label' => $d->format($labelFmt),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        }

        $bestBucket = collect($series)->sortByDesc('cents')->first();

        // Revenue by service: composition of the SALES that received payment in
        // this window, by line-item name. Sums positive (non-refund) payments'
        // sales only, grouping the sale's line items by name_snapshot. This keeps
        // the breakdown aligned with the cash-basis headline.
        $paidSaleIds = (clone $base)
            ->where('amount_cents', '>', 0)
            ->distinct()
            ->pluck('sale_id');

        $byService = [];
        if ($paidSaleIds->isNotEmpty()) {
            $byService = DB::table('tenant_sale_items')
                ->where('tenant_id', $this->tenant->id)
                ->whereIn('sale_id', $paidSaleIds)
                ->selectRaw('name_snapshot as name, SUM(line_total_cents) as cents, COUNT(DISTINCT sale_id) as bookings')
                ->groupBy('name')
                ->orderByDesc('cents')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'name'     => $r->name,
                    'cents'    => (int) $r->cents,
                    'bookings' => (int) $r->bookings,
                    'pct'      => $totalCents > 0 ? round(($r->cents / $totalCents) * 100) : 0,
                ])
                ->all();
        }

        return [
            'total_cents'   => $totalCents,
            'best_bucket'   => $bestBucket && $bestBucket['cents'] > 0
                ? ['label' => $bestBucket['label'], 'cents' => $bestBucket['cents']]
                : null,
            'series'        => $series,
            'series_kind'   => $isSingleDay ? 'hourly' : 'daily',
            'by_service'    => $byService,
        ];
    }
    public function zoneBookings(Carbon $from, Carbon $to): array
    {
        $isSingleDay = $from->isSameDay($to);

        $confirmed = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();

        $cancelled = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::CANCELLED_STATUSES)
            ->count();

        $noShows = $this->noShowCountForRange($from, $to);

        $walkins = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('DATE(created_at) = appointment_date')
            ->count();

        // Hourly bars for single day, daily for ranges
        $timeline = [];
        if ($isSingleDay) {
            $hourly = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->where('appointment_date', $from->toDateString())
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw("HOUR(appointment_time) as hour, COUNT(*) as n")
                ->groupBy('hour')
                ->pluck('n', 'hour')
                ->toArray();
            for ($h = 8; $h <= 18; $h++) {
                $timeline[] = [
                    'date'  => $from->toDateString(),
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'count' => (int) ($hourly[$h] ?? 0),
                ];
            }
        } else {
            $daily = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw('appointment_date as d, COUNT(*) as n')
                ->groupBy('d')
                ->pluck('n', 'd')
                ->toArray();
            $days = $from->diffInDays($to);
            $labelFmt = $days <= 7 ? 'D' : ($days <= 31 ? 'j' : 'M j');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $timeline[] = [
                    'date'  => $d->toDateString(),
                    'label' => $d->format($labelFmt),
                    'count' => (int) ($daily[$d->toDateString()] ?? 0),
                ];
            }
        }

        return [
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'no_shows'  => $noShows,
            'walkins'   => $walkins,
            'timeline'  => $timeline,
        ];
    }

    /** Zone 3: Customers + retention. */
    public function zoneCustomers(Carbon $from, Carbon $to): array
    {
        $rangeAppts = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->select('appointment_date', 'customer_id')
            ->get()
            ->groupBy(fn($r) => $r->appointment_date);

        $newCustIds = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from->toDateString() . ' 00:00:00', $to->toDateString() . ' 23:59:59'])
            ->pluck('id')
            ->all();
        $newSet = array_flip($newCustIds);

        $daily = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $newCount = 0; $returningCount = 0;
            foreach ($rangeAppts->get($key, collect()) as $row) {
                if (isset($newSet[$row->customer_id])) $newCount++;
                else $returningCount++;
            }
            $daily[] = [
                'date'      => $key,
                'new'       => $newCount,
                'returning' => $returningCount,
            ];
        }

        // MARKER-PATCH-184C — top customers by SPEND now read the sale payment
        // ledger (payments received in window), attributed via the sale's
        // customer. "visits" = distinct sales paid in the window. recorded_at is
        // UTC; bound by the tenant-local window converted to UTC.
        $tz = $this->tenant->timezone();
        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();
        $topCustomers = TenantCustomer::where('tenant_customers.tenant_id', $this->tenant->id)
            ->join('tenant_sales as ts', 'ts.customer_id', '=', 'tenant_customers.id')
            ->join('tenant_sale_payments as tsp', function ($j) use ($winStart, $winEnd) {
                $j->on('tsp.sale_id', '=', 'ts.id')
                  ->whereBetween('tsp.recorded_at', [$winStart, $winEnd]);
            })
            ->selectRaw('tenant_customers.id, tenant_customers.first_name, tenant_customers.last_name, tenant_customers.created_at, SUM(tsp.amount_cents) as cents, COUNT(DISTINCT ts.id) as visits')
            ->groupBy('tenant_customers.id', 'tenant_customers.first_name', 'tenant_customers.last_name', 'tenant_customers.created_at')
            ->orderByDesc('cents')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'name'             => trim($r->first_name . ' ' . $r->last_name),
                'cents'            => (int) $r->cents,
                'visits'           => (int) $r->visits,
                'is_new_in_period' => Carbon::parse($r->created_at)->between($from, $to->copy()->endOfDay()),
            ])
            ->all();

        return [
            'daily'         => $daily,
            'top_customers' => $topCustomers,
        ];
    }

    /** Zone 4: Service popularity. */
    public function zoneServices(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('tenant_appointments as ta')
            ->where('ta.tenant_id', $this->tenant->id)
            ->whereBetween('ta.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('ta.status', self::DELIVERED_STATUSES)
            ->where('ta.payment_status', 'paid')
            ->join('tenant_appointment_items as tai', 'tai.appointment_id', '=', 'ta.id')
            ->selectRaw('tai.item_name_snapshot as name, COUNT(DISTINCT ta.id) as bookings, SUM(COALESCE(tai.price_cents_override, tai.price_cents)) as cents')
            ->groupBy('name')
            ->orderByDesc('cents')
            ->limit(10)
            ->get();

        $maxCents = $rows->max('cents') ?: 1;

        return [
            'services' => $rows->map(fn($r) => [
                'name'      => $r->name,
                'bookings'  => (int) $r->bookings,
                'cents'     => (int) $r->cents,
                'bar_pct'   => round(($r->cents / $maxCents) * 100),
            ])->all(),
        ];
    }

    /** Zone 5: Staff utilization. */
    public function zoneStaff(Carbon $from, Carbon $to): array
    {
        // Real available minutes: sum each day's actual open-to-close window
        // from tenant_capacity_rules (defaults + overrides). Days the shop is
        // closed contribute zero; days with no rule fall back to 8h.
        $availableMinutes = $this->openMinutesForRange($from, $to);

        $resources = TenantResource::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $bookedMinutes = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('resource_id, SUM(total_duration_minutes) as mins, COUNT(*) as n')
            ->groupBy('resource_id')
            ->get()
            ->keyBy('resource_id');

        // MARKER-PATCH-184D — per-resource revenue from the sale payment ledger
        // (payments received in window), attributed via payment -> sale ->
        // appointment -> resource_id. Walk-in retail sales (no appointment) carry
        // no resource and are correctly excluded from per-staff revenue.
        $tzStaff = $this->tenant->timezone();
        $revWinStart = $from->copy()->setTimezone($tzStaff)->startOfDay()->utc();
        $revWinEnd   = $to->copy()->setTimezone($tzStaff)->endOfDay()->utc();
        $revenue = DB::table('tenant_sale_payments as tsp')
            ->where('tsp.tenant_id', $this->tenant->id)
            ->whereBetween('tsp.recorded_at', [$revWinStart, $revWinEnd])
            ->join('tenant_sales as ts', 'ts.id', '=', 'tsp.sale_id')
            ->join('tenant_appointments as ta', 'ta.id', '=', 'ts.appointment_id')
            ->selectRaw('ta.resource_id as resource_id, SUM(tsp.amount_cents) as cents')
            ->groupBy('ta.resource_id')
            ->pluck('cents', 'resource_id')
            ->toArray();

        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());

        $noShowsByResource = [];
        $totalByResource = [];
        if ($from->toDateString() <= $effectiveTo) {
            $noShowsByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
                ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw('resource_id, COUNT(*) as n')
                ->groupBy('resource_id')
                ->pluck('n', 'resource_id')
                ->toArray();

            $totalByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
                ->selectRaw('resource_id, COUNT(*) as n')
                ->groupBy('resource_id')
                ->pluck('n', 'resource_id')
                ->toArray();
        }

        $cards = $resources->map(function ($r) use ($bookedMinutes, $revenue, $noShowsByResource, $totalByResource, $availableMinutes) {
            $row = $bookedMinutes->get($r->id);
            $booked = (int) ($row->mins ?? 0);
            $appts = (int) ($row->n ?? 0);
            $rev = (int) ($revenue[$r->id] ?? 0);
            $noShows = (int) ($noShowsByResource[$r->id] ?? 0);
            $totalCount = (int) ($totalByResource[$r->id] ?? 0);

            $utilization = $availableMinutes > 0
                ? min(100, round(($booked / $availableMinutes) * 100))
                : 0;

            $health = match (true) {
                $utilization > 85 => 'overloaded',
                $utilization >= 50 => 'healthy',
                default => 'underused',
            };

            $noShowRate = $totalCount > 0 ? round(($noShows / $totalCount) * 100) : 0;

            return [
                'name'         => $r->name,
                'subtitle'     => $r->subtitle ?: 'Staff',
                'color_hex'    => $r->color_hex,
                'utilization'  => $utilization,
                'booked_hrs'   => round($booked / 60, 1),
                'available_hrs'=> round($availableMinutes / 60, 1),
                'appts'        => $appts,
                'revenue_cents'=> $rev,
                'no_show_rate' => $noShowRate,
                'health'       => $health,
            ];
        })->all();

        return ['cards' => $cards];
    }

    /**
     * Zone 6: Capacity heatmap.
     * Falls back to last 14 days if the requested range is shorter than 7
     * days — heatmap density needs that much data to be readable.
     */
    public function zoneCapacity(Carbon $from, Carbon $to): array
    {
        $rangeDays = $from->diffInDays($to) + 1;
        $usedFallback = false;
        if ($rangeDays < 7) {
            $usedFallback = true;
            $today = $this->tenant->localToday();
            $from = $today->copy()->subDays(13);
            $to = $today->copy();
        }

        $cells = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw("DAYOFWEEK(appointment_date) - 1 as dow, HOUR(appointment_time) as hour, COUNT(*) as n")
            ->groupBy('dow', 'hour')
            ->get();

        $maxCellCount = $cells->max('n') ?: 1;

        $grid = [];
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        foreach ($days as $dowIdx => $dowLabel) {
            $row = ['label' => $dowLabel, 'cells' => []];
            for ($h = 8; $h <= 21; $h++) {
                $cell = $cells->first(fn($c) => $c->dow == $dowIdx && $c->hour == $h);
                $count = $cell ? (int) $cell->n : 0;
                $fill = match (true) {
                    $count == 0           => 0,
                    $count <= $maxCellCount * 0.15 => 1,
                    $count <= $maxCellCount * 0.35 => 2,
                    $count <= $maxCellCount * 0.55 => 3,
                    $count <= $maxCellCount * 0.80 => 4,
                    default               => 5,
                };
                $row['cells'][] = [
                    'hour'  => $h,
                    'count' => $count,
                    'fill'  => $fill,
                ];
            }
            $grid[] = $row;
        }

        return [
            'grid'          => $grid,
            'used_fallback' => $usedFallback,
            'fallback_label' => $usedFallback
                ? $from->format('M j') . ' – ' . $to->format('M j')
                : null,
            'hour_labels'   => array_map(
                fn($h) => Carbon::createFromTime($h)->format('ga'),
                range(8, 21)
            ),
        ];
    }

    // ---------- helpers ----------

    /**
     * Sum of "shop is open" minutes for every day in the range.
     * Override rules win over default rules for a specific date. If a day
     * has no rule at all, falls back to 8 hours so a brand-new tenant
     * doesn't show 100%-of-zero utilization.
     */
    private function openMinutesForRange(Carbon $from, Carbon $to): int
    {
        $defaults = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'default')
            ->whereNull('specific_date')
            ->get(['day_of_week', 'is_closed', 'open_time', 'close_time'])
            ->keyBy('day_of_week');

        $overrides = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$from->toDateString(), $to->toDateString()])
            ->get(['specific_date', 'is_closed', 'open_time', 'close_time'])
            ->keyBy(fn($r) => $r->specific_date);

        $totalMinutes = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $rule = $overrides->get($d->toDateString()) ?? $defaults->get($d->dayOfWeek);

            if (!$rule) {
                $totalMinutes += 8 * 60;  // fallback when no rule exists
                continue;
            }
            if (!empty($rule->is_closed)) continue;  // closed = 0 minutes
            if (empty($rule->open_time) || empty($rule->close_time)) {
                $totalMinutes += 8 * 60;  // partial rule, fallback
                continue;
            }

            try {
                $open  = Carbon::parse($rule->open_time);
                $close = Carbon::parse($rule->close_time);
                $mins  = max(0, $open->diffInMinutes($close));
                $totalMinutes += $mins;
            } catch (\Throwable $e) {
                $totalMinutes += 8 * 60;
            }
        }

        return $totalMinutes;
    }

    private function revenueForDate(Carbon $date): int
    {
        // MARKER-PATCH-184B — payments received (ledger) for the tenant-local
        // day, replacing appointment totals. recorded_at is UTC; bound by the
        // local-day window converted to UTC. Signed amounts net refunds.
        $tz = $this->tenant->timezone();
        $start = $date->copy()->setTimezone($tz)->startOfDay()->utc();
        $end   = $date->copy()->setTimezone($tz)->endOfDay()->utc();
        return (int) DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$start, $end])
            ->sum('amount_cents');
    }

    private function bookingCountForDate(Carbon $date): int
    {
        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();
    }

    private function capacityForDate(Carbon $date): ?int
    {
        $dow = $date->dayOfWeek;
        $rule = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where(function ($q) use ($dow, $date) {
                $q->where(function ($s) use ($date) {
                    $s->where('rule_type', 'override')->where('specific_date', $date->toDateString());
                })->orWhere(function ($s) use ($dow) {
                    $s->where('rule_type', 'default')->where('day_of_week', $dow)->whereNull('specific_date');
                });
            })
            ->orderByRaw("CASE WHEN rule_type='override' THEN 0 ELSE 1 END")
            ->first();

        return $rule?->max_appointments;
    }

    private function noShowCountForDate(Carbon $date): int
    {
        // Strict + 24h grace: only count yesterday-or-earlier confirmed
        // appointments. Today's date returns 0 because grace hasn't elapsed.
        if ($date->gte($this->tenant->localToday())) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->where('status', 'confirmed')
            ->count();
    }

    private function noShowCountForRange(Carbon $from, Carbon $to): int
    {
        // Strict + 24h grace: only count appointments that were actually
        // confirmed (not pending) AND whose date is at least one full day in
        // the past. This prevents inflating no-show counts with appointments
        // that simply haven't been status-updated yet.
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->where('status', 'confirmed')
            ->count();
    }

    private function noShowRateForRange(Carbon $from, Carbon $to): float
    {
        // Same strict + 24h grace as noShowCountForRange. Denominator is
        // every non-cancelled appointment, numerator is just the confirmed
        // ones that didn't make it to delivered.
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        $total = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->whereNotIn('status', self::CANCELLED_STATUSES)
            ->count();

        if ($total === 0) return 0;

        $noShows = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->where('status', 'confirmed')
            ->count();

        return $noShows / $total;
    }

    private function newCustomerCountForDate(Carbon $date): int
    {
        // MARKER-TZ-WAVE1 — created_at is UTC; bucket by the tenant day's
        // UTC range so evening signups land on the right local day.
        [$s, $e] = tenant_day_utc_range($date, $this->tenant->timezone());
        return TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $s)
            ->where('created_at', '<',  $e)
            ->count();
    }

    private function deltaPercent(int $current, int $prior): ?array
    {
        if ($prior === 0) return null;
        $pct = round((($current - $prior) / $prior) * 100);
        return [
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            'value'     => abs($pct) . '%',
        ];
    }

    private function deltaCount(int $current, int $prior): ?array
    {
        $diff = $current - $prior;
        return [
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            'value'     => abs($diff),
        ];
    }
}
TZW1_4_EOF

cat > 'app/Http/Controllers/Tenant/CalendarController.php' <<'TZW1_5_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCalendarBreak;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantWalkinHold;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalendarController extends Controller
{
    /**
     * Calendar index — routes to day/week/month based on ?view= query param.
     * Default: day. Each view loads its own data shape via a private helper
     * and renders a shared shell that includes the appropriate grid partial.
     *
     * Resource filtering and tenant-local "today" math are shared across
     * all three views via the helpers below.
     */
    public function index(Request $request)
    {
        $tenant = tenant();
        $mode   = $tenant->booking_mode ?? 'drop_off';

        // Month view exists only for time-slot mode in v1; drop-off has day + week.
        $allowedViews = $mode === 'time_slots'
            ? ['day', 'week', 'month']
            : ['day', 'week'];

        // MARKER-PATCH-181 — remember the last Day/Week/Month choice. If ?view=
        // is given explicitly, honor it and persist it in a 1-year cookie. If
        // not (e.g. arriving from the nav), fall back to the remembered view,
        // then to 'day'.
        $remembered = $request->cookie('calendar_view');
        $explicit   = $request->query('view');
        $view = $explicit ?: ($remembered ?: 'day');
        if (!in_array($view, $allowedViews, true)) {
            $view = 'day';
        }
        if ($explicit && in_array($explicit, $allowedViews, true)) {
            \Illuminate\Support\Facades\Cookie::queue('calendar_view', $explicit, 60 * 24 * 365);
        }

        if ($mode === 'drop_off') {
            return match ($view) {
                'week'  => $this->dropOffWeekView($request),
                default => $this->dropOffDayView($request),
            };
        }

        return match ($view) {
            'week'  => $this->weekView($request),
            'month' => $this->monthView($request),
            default => $this->dayView($request),
        };
    }

    /* ============================================================
     * Drop-off mode — day view
     * Per-resource swimlanes within a single day. No time axis.
     * Appointments stack within their resource column, ordered by
     * stack_order (manual drag) then RA number (newest last).
     * ============================================================ */
    protected function dropOffDayView(Request $request)
    {
        $tenant = tenant();
        $date   = $this->resolveDate($request->query('date'), $tenant);

        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'subtitle', 'color_hex', 'max_appointments_per_day']);

        // Active appointments for the day, all resources at once.
        $appointments = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderBy('ra_number')
            ->get([
                'id', 'ra_number', 'resource_id', 'status',
                'customer_first_name', 'customer_last_name',
                'receiving_method_snapshot', 'slot_weight',
                'appointment_date',
            ])
            ->groupBy('resource_id');

        return view('tenant.calendar.dropoff', [
            'mode'         => 'drop_off',
            'view'         => 'day',
            'date'         => $date,
            'resources'    => $resources,
            'appointments' => $appointments,
        ]);
    }

    /* ============================================================
     * Drop-off mode — week view
     * Resources as rows, days as columns. Each cell stacks the
     * appointments for that resource on that day.
     * ============================================================ */
    protected function dropOffWeekView(Request $request)
    {
        $tenant = tenant();
        $anchor = $this->resolveDate($request->query('date'), $tenant);

        // Sunday-anchored week (matches existing weekView convention).
        $weekStart = $anchor->copy()->startOfWeek(Carbon::SUNDAY);
        $weekEnd   = $weekStart->copy()->addDays(6);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $weekStart->copy()->addDays($i);
        }

        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'subtitle', 'color_hex', 'max_appointments_per_day']);

        $appointments = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->whereBetween('appointment_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderBy('appointment_date')
            ->orderBy('ra_number')
            ->get([
                'id', 'ra_number', 'resource_id', 'status',
                'customer_first_name', 'customer_last_name',
                'receiving_method_snapshot', 'slot_weight',
                'appointment_date',
            ]);

        // Group by (date, resource) tuple key so the Blade can render cell-by-cell.
        $byCell = [];
        foreach ($appointments as $appt) {
            $key = $appt->appointment_date->format('Y-m-d') . '|' . ($appt->resource_id ?? 'unassigned');
            $byCell[$key][] = $appt;
        }

        return view('tenant.calendar.dropoff-week', [
            'mode'         => 'drop_off',
            'view'         => 'week',
            'weekStart'    => $weekStart,
            'weekEnd'      => $weekEnd,
            'days'         => $days,
            'resources'    => $resources,
            'byCell'       => $byCell,
        ]);
    }

    /* ============================================================
     * Drop-off reschedule endpoint
     * Accepts: appointment_id, new_date (YYYY-MM-DD), new_resource_id
     * Updates the appointment row with appropriate validation.
     * Used by drag-between-days and drag-between-resources.
     * ============================================================ */
    public function dropOffReschedule(Request $request)
    {
        $tenant = tenant();
        $request->validate([
            'appointment_id'  => ['required', 'string', 'uuid'],
            'new_date'        => ['required', 'date'],
            'new_resource_id' => ['nullable', 'string', 'uuid'],
        ]);

        $appt = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $request->input('appointment_id'))
            ->firstOrFail();

        $newResourceId = $request->input('new_resource_id');
        if ($newResourceId) {
            $resourceOk = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $newResourceId)
                ->where('is_active', true)
                ->exists();
            if (!$resourceOk) {
                return response()->json(['success' => false, 'message' => 'Invalid resource.'], 422);
            }
        }

        $appt->update([
            'appointment_date' => $request->input('new_date'),
            'resource_id'      => $newResourceId ?? $appt->resource_id,
        ]);

        return response()->json([
            'success'    => true,
            'date'       => $appt->appointment_date->format('Y-m-d'),
            'resource_id'=> $appt->resource_id,
        ]);
    }

    /* ============================================================
     * Day view — high-fidelity time-axis grid with resource columns
     * ============================================================ */
    protected function dayView(Request $request)
    {
        $tenant = tenant();
        $tz     = $tenant->timezone();

        $date = $this->resolveDate($request->query('date'), $tenant);
        $dateStr = $date->toDateString();

        $prevDate = $date->copy()->subDay()->toDateString();
        $nextDate = $date->copy()->addDay()->toDateString();
        $todayStr = $tenant->localToday()->toDateString();
        $isToday  = $dateStr === $todayStr;

        [$allResources, $resources, $visibleIds, $myResource, $filterMode]
            = $this->resolveResources($tenant, $request);

        $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->where('day_of_week', $date->dayOfWeek)
            ->first();

        $hasRule  = $rule && $rule->open_time && $rule->close_time;
        $rawOpen  = $hasRule ? $this->timeToMinutes($rule->open_time)  : 9 * 60;
        $rawClose = $hasRule ? $this->timeToMinutes($rule->close_time) : 17 * 60;
        $slotMin  = max((int) ($rule->slot_interval_minutes ?? 30), 15);

        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->where('appointment_date', $dateStr)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleIds)
            ->with(['items:id,appointment_id,item_name_snapshot,duration_minutes_snapshot,prep_before_minutes_snapshot,cleanup_after_minutes_snapshot'])
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_time', 'appointment_end_time', 'total_duration_minutes',
                'status', 'total_cents', 'needs_time_review',
            ]);

        // Render bounds extend beyond the capacity rule's open/close hours so
        // adjacent context (early-morning prep, post-close wrap-up, classes
        // running outside business hours) is visible. The bounds:
        //   1. Always show ±60 min around open/close as buffer.
        //   2. Stretch further to include any appointments that fall outside.
        //   3. Clamped to [0, 1440] so we never render past midnight either way.
        $bufferMin = 60;
        $earliestApptMin = $rawOpen;
        $latestApptMin   = $rawClose;
        foreach ($appointments as $a) {
            $startMin = $this->timeToMinutes($a->appointment_time);
            if ($startMin === null) { continue; }
            $endMin = $a->appointment_end_time
                ? $this->timeToMinutes($a->appointment_end_time)
                : $startMin + (int) ($a->total_duration_minutes ?? 60);
            $earliestApptMin = min($earliestApptMin, $startMin);
            $latestApptMin   = max($latestApptMin, $endMin);
        }

        $openMin  = max(0,    min($rawOpen,  $earliestApptMin) - $bufferMin);
        $closeMin = min(1440, max($rawClose, $latestApptMin)   + $bufferMin);

        $breakWindows = $this->collectBreakWindows($tenant->id, $date);
        $holdWindows  = $this->collectHoldWindows($tenant->id, $date);

        // Customer prefill — when the calendar is opened from the customer
        // detail page via "+ New appointment", auto-open the QuickBook modal
        // with the customer pre-selected. Falls back to no prefill on bad ids.
        $prefillCustomer = null;
        $customerIdParam = $request->query('customer_id');
        if ($customerIdParam) {
            $c = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $customerIdParam)
                ->first(['id', 'first_name', 'last_name', 'email', 'phone']);
            if ($c) {
                $prefillCustomer = [
                    'id'         => $c->id,
                    'first_name' => $c->first_name,
                    'last_name'  => $c->last_name,
                    'email'      => $c->email,
                    'phone'      => $c->phone,
                ];
            }
        }

        return view('tenant.calendar.index', [
            'viewMode'      => 'day',
            'date'          => $date,
            'dateStr'       => $dateStr,
            'prevDate'      => $prevDate,
            'nextDate'      => $nextDate,
            'todayStr'      => $todayStr,
            'isToday'       => $isToday,
            'resources'     => $resources,
            'allResources'  => $allResources,
            'myResource'    => $myResource,
            'filterMode'    => $filterMode,
            'hasRule'       => $hasRule,
            'openMin'       => $openMin,
            'closeMin'      => $closeMin,
            'slotMin'       => $slotMin,
            'appointments'  => $appointments,
            'breakWindows'  => $breakWindows,
            'holdWindows'   => $holdWindows,
            'prefillCustomer' => $prefillCustomer,
        ]);
    }

    /* ============================================================
     * Week view — per-resource swimlanes, 7 day-columns Sun–Sat
     * Compact-list rendering. No continuous time axis; appointments
     * stack inside their day-cell ordered by appointment_time.
     * ============================================================ */
    protected function weekView(Request $request)
    {
        $tenant = tenant();

        // Sunday-anchored week containing the requested date.
        $anchor      = $this->resolveDate($request->query('date'), $tenant);
        $weekStart   = $anchor->copy()->startOfWeek(Carbon::SUNDAY);
        $weekEnd     = $weekStart->copy()->addDays(6);
        $weekStartStr = $weekStart->toDateString();
        $weekEndStr   = $weekEnd->toDateString();

        // Build the seven days for the header row.
        $days = [];
        $todayStr = $tenant->localToday()->toDateString();
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $ds = $d->toDateString();
            $days[] = [
                'date'     => $d,
                'dateStr'  => $ds,
                'short'    => $d->format('D'),       // Sun, Mon
                'num'      => $d->format('j'),       // 1–31
                'isToday'  => $ds === $todayStr,
                'isWeekend'=> in_array($d->dayOfWeek, [0, 6], true),
            ];
        }

        $prevDate = $weekStart->copy()->subWeek()->toDateString();
        $nextDate = $weekStart->copy()->addWeek()->toDateString();

        [$allResources, $resources, $visibleIds, $myResource, $filterMode]
            = $this->resolveResources($tenant, $request);

        // Pull all active appointments in the week range, scoped + ordered.
        // We hydrate items so the per-cell rendering can show service name.
        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('appointment_date', [$weekStartStr, $weekEndStr])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleIds)
            ->with(['items:id,appointment_id,item_name_snapshot'])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_date', 'appointment_time', 'total_duration_minutes',
                'status', 'needs_time_review',
            ]);

        // Group: resource_id => date => collection of appointments.
        // The Blade renders one row per resource, then iterates $days for cells.
        $byResourceDate = [];
        foreach ($appointments as $a) {
            $rid = $a->resource_id;
            $ds  = is_string($a->appointment_date)
                ? $a->appointment_date
                : $a->appointment_date->toDateString();
            $byResourceDate[$rid][$ds][] = $a;
        }

        return view('tenant.calendar.index', [
            'viewMode'        => 'week',
            'weekStart'       => $weekStart,
            'weekEnd'         => $weekEnd,
            'weekStartStr'    => $weekStartStr,
            'weekEndStr'      => $weekEndStr,
            'days'            => $days,
            'prevDate'        => $prevDate,
            'nextDate'        => $nextDate,
            'todayStr'        => $todayStr,
            'resources'       => $resources,
            'allResources'    => $allResources,
            'myResource'      => $myResource,
            'filterMode'      => $filterMode,
            'byResourceDate'  => $byResourceDate,
        ]);
    }

    /* ============================================================
     * Month view — 6×7 density grid. Always 6 weeks so layout is stable.
     * Up to 4 stacked color-coded bars per cell, "+N more" overflow.
     * Hover bar = tooltip; click cell = drill to day view.
     * ============================================================ */
    protected function monthView(Request $request)
    {
        $tenant = tenant();

        $anchor    = $this->resolveDate($request->query('date'), $tenant);
        $monthStart = $anchor->copy()->startOfMonth();
        $monthEnd   = $anchor->copy()->endOfMonth();

        // Grid: always 6 weeks (42 cells), Sunday-anchored. Spillover
        // before/after gets greyed out. Stable layout = no jump on month flip.
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd   = $gridStart->copy()->addDays(41);

        $todayStr = $tenant->localToday()->toDateString();
        $cells = [];
        for ($i = 0; $i < 42; $i++) {
            $d  = $gridStart->copy()->addDays($i);
            $ds = $d->toDateString();
            $cells[] = [
                'date'         => $d,
                'dateStr'      => $ds,
                'num'          => $d->format('j'),
                'inMonth'      => $d->month === $anchor->month,
                'isToday'      => $ds === $todayStr,
                'dayOfWeek'    => $d->dayOfWeek,
            ];
        }

        $prevDate = $monthStart->copy()->subMonthNoOverflow()->toDateString();
        $nextDate = $monthStart->copy()->addMonthNoOverflow()->toDateString();

        [$allResources, $resources, $visibleIds, $myResource, $filterMode]
            = $this->resolveResources($tenant, $request);

        // Pull all active appointments in the visible 6-week window.
        // Even spillover days render their bars — feels weird for the cell
        // to be empty when you can see the appt is there, just outside the month.
        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('appointment_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleIds)
            ->with(['items:id,appointment_id,item_name_snapshot'])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_date', 'appointment_time', 'status',
            ]);

        // Group: date => collection. Resource is preserved on each appt so the
        // bar color matches its resource. Up to 4 visible per cell.
        $byDate = [];
        foreach ($appointments as $a) {
            $ds = is_string($a->appointment_date)
                ? $a->appointment_date
                : $a->appointment_date->toDateString();
            $byDate[$ds][] = $a;
        }

        // Resource color lookup — keep small, used by the bar renderer.
        $resourceColors = $allResources->pluck('color_hex', 'id')->all();
        $resourceNames  = $allResources->pluck('name', 'id')->all();

        return view('tenant.calendar.index', [
            'viewMode'       => 'month',
            'monthAnchor'    => $anchor,
            'monthLabel'     => $anchor->format('F Y'),
            'cells'          => $cells,
            'prevDate'       => $prevDate,
            'nextDate'       => $nextDate,
            'todayStr'       => $todayStr,
            'resources'      => $resources,
            'allResources'   => $allResources,
            'myResource'     => $myResource,
            'filterMode'     => $filterMode,
            'byDate'         => $byDate,
            'resourceColors' => $resourceColors,
            'resourceNames'  => $resourceNames,
        ]);
    }

    /* ============================================================
     * Helpers — shared across all three views
     * ============================================================ */

    /**
     * Parse a YYYY-MM-DD string (or null) into a Carbon date in tenant TZ.
     * Falls back to "today" on any parse error or empty input.
     */
    protected function resolveDate(?string $param, $tenant): Carbon
    {
        $tz = $tenant->timezone();
        try {
            return $param
                ? Carbon::parse($param, $tz)->startOfDay()
                : $tenant->localToday();
        } catch (\Throwable $e) {
            return $tenant->localToday();
        }
    }

    /**
     * Resolve which resources to show based on the ?resources= query param.
     * Returns: [allResources, visibleResources, visibleIds, myResource, filterMode].
     *
     * - "all" or absent  → every active resource (default — users can narrow
     *                      via the chips, and their selection is persisted to
     *                      localStorage on the client and replayed on next visit)
     * - "uuid1,uuid2"    → just those (intersected with active)
     * - empty result     → falls back to all
     *
     * `myResource` is still surfaced (for "highlight my appointments" UI hooks
     * elsewhere) but no longer drives the default filter — the user starts
     * with everyone visible and clicks names to narrow.
     */
    protected function resolveResources($tenant, Request $request): array
    {
        $allResources = TenantResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $userId = Auth::guard('tenant')->id();
        $myResource = $userId
            ? $allResources->firstWhere('staff_user_id', $userId)
            : null;

        $filterParam = trim((string) $request->query('resources', ''));
        if ($filterParam === '' || $filterParam === 'all') {
            $visibleIds = $allResources->pluck('id')->all();
            $filterMode = 'all';
        } else {
            $requestedIds = array_filter(array_map('trim', explode(',', $filterParam)));
            $visibleIds = $allResources->whereIn('id', $requestedIds)->pluck('id')->all();
            if (empty($visibleIds)) {
                $visibleIds = $allResources->pluck('id')->all();
                $filterMode = 'all';
            } else {
                $filterMode = 'custom';
            }
        }

        $resources = $allResources->whereIn('id', $visibleIds)->values();

        return [$allResources, $resources, $visibleIds, $myResource, $filterMode];
    }

    protected function timeToMinutes(string $hms): int
    {
        $parts = explode(':', $hms);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    protected function collectBreakWindows(string $tenantId, Carbon $date): array
    {
        $records = TenantCalendarBreak::where('tenant_id', $tenantId)
            ->where(function ($q) use ($date) {
                $q->where(function ($q2) use ($date) {
                    // MARKER-TZ-WAVE1 — starts_at is UTC; bound by the
                    // tenant day's UTC range instead of whereDate.
                    [$dS, $dE] = tenant_day_utc_range($date);
                    $q2->where('is_recurring', false)
                       ->where('starts_at', '>=', $dS)
                       ->where('starts_at', '<',  $dE);
                })->orWhere(function ($q2) use ($date) {
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $date->copy()->endOfDay())
                       ->where(function ($q3) use ($date) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $date->toDateString());
                       });
                });
            })
            ->get(['id','resource_id','label','starts_at','ends_at','is_recurring','recurrence_type','recurrence_config']);

        return $this->expandWindows($records, $date, 'label');
    }

    protected function collectHoldWindows(string $tenantId, Carbon $date): array
    {
        $now = now();
        $records = TenantWalkinHold::where('tenant_id', $tenantId)
            ->whereNull('converted_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('auto_release_at')->orWhere('auto_release_at', '>', $now);
            })
            ->where(function ($q) use ($date) {
                $q->where(function ($q2) use ($date) {
                    // MARKER-TZ-WAVE1 — starts_at is UTC; bound by the
                    // tenant day's UTC range instead of whereDate.
                    [$dS, $dE] = tenant_day_utc_range($date);
                    $q2->where('is_recurring', false)
                       ->where('starts_at', '>=', $dS)
                       ->where('starts_at', '<',  $dE);
                })->orWhere(function ($q2) use ($date) {
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $date->copy()->endOfDay())
                       ->where(function ($q3) use ($date) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $date->toDateString());
                       });
                });
            })
            ->get(['id','resource_id','starts_at','ends_at','is_recurring','recurrence_type','recurrence_config','notes']);

        return $this->expandWindows($records, $date, 'notes');
    }

    protected function expandWindows($records, Carbon $target, string $labelField): array
    {
        $windows = [];
        foreach ($records as $r) {
            if (!$r->is_recurring) {
                $start = Carbon::parse($r->starts_at);
                $end   = Carbon::parse($r->ends_at);
                $windows[] = [
                    'id'           => $r->id,
                    'resource_id'  => $r->resource_id,
                    'starts_min'   => $start->hour * 60 + $start->minute,
                    'ends_min'     => $end->hour * 60 + $end->minute,
                    'label'        => $r->{$labelField} ?? '',
                    'is_recurring' => false,
                ];
                continue;
            }

            if (!$this->recurrenceAppliesOnDate($r->recurrence_type, $r->recurrence_config, $target)) {
                continue;
            }

            $origStart = Carbon::parse($r->starts_at);
            $origEnd   = Carbon::parse($r->ends_at);
            $windows[] = [
                'id'           => $r->id,
                'resource_id'  => $r->resource_id,
                'starts_min'   => $origStart->hour * 60 + $origStart->minute,
                'ends_min'     => $origEnd->hour * 60 + $origEnd->minute,
                'label'        => $r->{$labelField} ?? '',
                'is_recurring' => true,
            ];
        }
        return $windows;
    }

    protected function recurrenceAppliesOnDate(?string $type, $config, Carbon $target): bool
    {
        if ($type === 'daily') return true;

        if ($type === 'weekly') {
            $days = is_array($config) ? ($config['days'] ?? []) : [];
            if (!is_array($days) || empty($days)) return false;
            $targetDow = strtolower(substr($target->format('D'), 0, 3));
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
}
TZW1_5_EOF

cat > 'app/Http/Controllers/Tenant/SpecialOrderController.php' <<'TZW1_6_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantVendor;
use App\Services\Tenant\SpecialOrderService;
use App\Services\Tenant\SpecialOrderValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SpecialOrderController extends Controller
{
    use GuardsRetailAccess;

    public function __construct(private SpecialOrderService $service)
    {
    }

    /**
     * SO list with tab filters. The active tab is derived from the
     * `view` query param. All open is the default. Each tab is just
     * a different scope chain on the underlying TenantSpecialOrder
     * query — counts are cheap to compute alongside.
     */
    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $view = $request->input('view', 'open');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        // Compute counts for all tabs — small queries, all indexed
        $counts = [
            'open'          => TenantSpecialOrder::where('tenant_id', $tenant->id)->open()->count(),
            'arrived_bench' => TenantSpecialOrder::where('tenant_id', $tenant->id)->arrivedBench()->count(),
            'overdue'       => TenantSpecialOrder::where('tenant_id', $tenant->id)->overdue()->count(),
            'pulled'        => TenantSpecialOrder::where('tenant_id', $tenant->id)
                                ->where('status', TenantSpecialOrder::STATUS_PULLED)->count(),
            'cancelled'     => TenantSpecialOrder::where('tenant_id', $tenant->id)
                                ->where('status', TenantSpecialOrder::STATUS_CANCELLED)->count(),
        ];

        $q = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->with(['vendor', 'customer', 'appointment', 'item']);

        switch ($view) {
            case 'arrived_bench':
                $q->arrivedBench()->orderBy('arrived_at');
                break;
            case 'overdue':
                $q->overdue()->orderBy('expected_arrival_date');
                break;
            case 'pulled':
                $q->where('status', TenantSpecialOrder::STATUS_PULLED)
                  ->orderByDesc('pulled_at');
                break;
            case 'cancelled':
                $q->where('status', TenantSpecialOrder::STATUS_CANCELLED)
                  ->orderByDesc('cancelled_at');
                break;
            default: // 'open'
                $q->open()->orderByRaw("
                    CASE status
                        WHEN 'arrived' THEN 0
                        WHEN 'ordered' THEN 1
                        WHEN 'needed' THEN 2
                        ELSE 3
                    END
                ")->orderBy('expected_arrival_date');
                break;
        }

        $total = $q->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $sos = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Drawer prep: vendors list for the picker, plus today's date
        // for the date-picker default. Item search is XHR, customers
        // are XHR — only vendors is small enough to inline.
        $vendors = TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('tenant.special-orders.index', [
            'sos'        => $sos,
            'view'       => $view,
            'counts'     => $counts,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'vendors'    => $vendors,
        ]);
    }

    /**
     * SO detail page. Eager-loads everything the view needs.
     */
    public function show(Request $request, string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $so = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->with(['vendor', 'customer', 'appointment', 'item', 'notes.user', 'parent', 'children'])
            ->findOrFail($id);

        // If this SO is part of a batch, fetch siblings for the "linked rows" panel
        $batchSiblings = collect();
        if ($so->batch_id) {
            $batchSiblings = TenantSpecialOrder::where('tenant_id', $tenant->id)
                ->where('batch_id', $so->batch_id)
                ->where('id', '!=', $so->id)
                ->with(['customer', 'appointment'])
                ->get();
        }

        return view('tenant.special-orders.show', [
            'so'            => $so,
            'batchSiblings' => $batchSiblings,
        ]);
    }

    /**
     * Create new SO(s) from the drawer. The drawer can submit
     * multiple allocation rows; this method creates one SO row
     * per allocation, sharing a batch_id when there's >1 row.
     *
     * Validation is permissive — the service layer enforces the
     * real rules (status, required fields per status). This method
     * just validates shape.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'inventory_item_id'         => ['nullable', 'string'],
            'item_name'                 => ['required', 'string', 'max:255'],
            'vendor_id'                 => ['nullable', 'string'],
            'po_number'                 => ['nullable', 'string', 'max:64'],
            'expected_arrival_date'     => ['nullable', 'date'],
            'unit_cost_cents_estimated' => ['nullable', 'integer', 'min:0'],
            'allocations'               => ['required', 'array', 'min:1'],
            'allocations.*.mode'        => ['required', 'in:customer,customer_appt,stock'],
            'allocations.*.customer_id' => ['nullable', 'string'],
            'allocations.*.appointment_id' => ['nullable', 'string'],
            'allocations.*.quantity'    => ['required', 'integer', 'min:1'],
            'notes'                     => ['nullable', 'string'],
            'deposit_cents'             => ['nullable', 'integer', 'min:0'],
        ]);

        // Determine initial status: if vendor + PO + ETA provided → 'ordered'.
        // Otherwise → 'needed'.
        $hasFullOrderInfo = !empty($data['vendor_id'])
            && !empty($data['po_number'])
            && !empty($data['expected_arrival_date']);

        $initialStatus = $hasFullOrderInfo
            ? TenantSpecialOrder::STATUS_ORDERED
            : TenantSpecialOrder::STATUS_NEEDED;

        // Generate a batch_id if >1 allocation row
        $batchId = count($data['allocations']) > 1 ? \Illuminate\Support\Str::uuid()->toString() : null;

        try {
            $created = [];
            foreach ($data['allocations'] as $alloc) {
                $row = [
                    'tenant_id'                 => $tenant->id,
                    'inventory_item_id'         => $data['inventory_item_id'] ?? null,
                    'item_name_snapshot'        => $data['item_name'],
                    'quantity'                  => (int) $alloc['quantity'],
                    'customer_id'               => $alloc['mode'] === 'stock' ? null : ($alloc['customer_id'] ?? null),
                    'appointment_id'            => $alloc['mode'] === 'customer_appt' ? ($alloc['appointment_id'] ?? null) : null,
                    'vendor_id'                 => $data['vendor_id'] ?? null,
                    'po_number'                 => $data['po_number'] ?? null,
                    'expected_arrival_date'     => $data['expected_arrival_date'] ?? null,
                    'unit_cost_cents_estimated' => $data['unit_cost_cents_estimated'] ?? null,
                    'status'                    => $initialStatus,
                    'created_from'              => 'manual',
                    'batch_id'                  => $batchId,
                    'created_by_user_id'        => Auth::guard('tenant')->id(),
                    'deposit_cents'             => $data['deposit_cents'] ?? 0,
                    'notes'                     => $data['notes'] ?? null,
                ];
                $created[] = $this->service->create($row);
            }
        } catch (SpecialOrderValidationException $e) {
            return back()
                ->withInput()
                ->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $count = count($created);
        $msg = $count === 1
            ? "Special order {$created[0]->so_number} created."
            : "{$count} special orders created (batch).";

        return redirect()->route('tenant.special-orders.index')
            ->with('flash', ['type' => 'success', 'message' => $msg]);
    }

    /**
     * Mark an SO as ordered (needed → ordered).
     * If the SO already has vendor + PO + ETA from creation, this is
     * a no-op of sorts — the service layer rejects illegal transitions.
     * Required when the SO was created with status=needed and now
     * staff has placed the order with a vendor.
     */
    public function markOrdered(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'vendor_id'             => ['required', 'string'],
            'po_number'             => ['required', 'string', 'max:64'],
            'expected_arrival_date' => ['required', 'date'],
            'vendor_reference'      => ['nullable', 'string', 'max:64'],
            'unit_cost_cents_estimated' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->service->markOrdered($id, $data);
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked ordered.']);
    }

    /**
     * Mark an SO as arrived (ordered → arrived).
     * Stage 4b does FULL arrival only (received_qty = quantity).
     * Partial receipts ship in Stage 6 with the receiving integration.
     */
    public function markArrived(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'unit_cost_cents_actual' => ['nullable', 'integer', 'min:0'],
            'vendor_invoice_number'  => ['nullable', 'string', 'max:64'],
            'vendor_invoice_date'    => ['nullable', 'date'],
        ]);

        try {
            $this->service->markArrived(
                $id,
                null, // null = full receipt
                $data['unit_cost_cents_actual'] ?? null,
                $data['vendor_invoice_number'] ?? null,
                $data['vendor_invoice_date'] ?? null,
            );
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked arrived.']);
    }

    public function markPulled(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        try {
            $this->service->markPulled($id);
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked pulled.']);
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->cancel($id, $data['reason'] ?? null);
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Special order cancelled.']);
    }

    public function addNote(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $this->service->addNote(
                $id,
                Auth::guard('tenant')->id(),
                $data['body'],
                false
            );
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    /**
     * XHR endpoint for the drawer's allocation picker. Returns
     * upcoming (next 60 days) appointments for a given customer.
     * Scoped to the current tenant.
     */
    public function appointmentsForCustomer(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $customerId = $request->query('customer_id');
        if (empty($customerId)) {
            return response()->json(['ok' => false, 'error' => 'customer_id required'], 422);
        }

        $appts = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->whereDate('appointment_date', '>=', tnow()->toDateString()) // MARKER-TZ-WAVE1 — appointment_date is a naive tenant-local DATE
            ->whereDate('appointment_date', '<=', tnow()->addDays(60)->toDateString())
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderBy('appointment_date')
            ->limit(10)
            ->get(['id', 'ra_number', 'appointment_date', 'status']);

        return response()->json([
            'ok' => true,
            'appointments' => $appts->map(fn($a) => [
                'id'     => $a->id,
                'label'  => $a->ra_number . ' · ' . $a->appointment_date->format('M j, Y'),
                'date'   => $a->appointment_date->format('Y-m-d'),
                'status' => $a->status,
            ]),
        ]);
    }

    /**
     * Helper — abort 404 if the SO doesn't belong to this tenant.
     * The service layer's findOrFail is bare; we scope here.
     */
    private function ensureBelongsToTenant(string $id, string $tenantId): void
    {
        $exists = TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
        if (!$exists) {
            abort(404);
        }
    }
}
TZW1_6_EOF

echo "timezone-wave1 applied — server needs view:clear (no migrate)"
