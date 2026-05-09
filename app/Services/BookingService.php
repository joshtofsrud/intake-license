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

                foreach ($plan as $row) {
                    $service = $row['service'];
                    TenantAppointmentItem::create([
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
                            'addon_id'                  => $addonRow['addon']->id,
                            'addon_name_snapshot'       => $addonRow['addon']->name,
                            'price_cents'               => $addonRow['effective_price_cents'],
                            'duration_minutes_snapshot' => $addonRow['effective_duration'],
                        ]);
                    }
                }

                $this->persistResponses($appointment, $data);

                // Dispatch the booking-confirmation notification.
                // afterCommit() ensures the job only fires if the DB transaction
                // actually commits — never send a confirmation for a phantom
                // appointment that got rolled back by a later error in the chain.
                SendBookingConfirmationJob::dispatch($appointment->id)->afterCommit();

                return $appointment->fresh(['items', 'addons', 'customer', 'responses']);
            });
        });
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

    public function availableDates(Tenant $tenant, int $year, int $month, ?string $serviceId = null): array
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
                }
            } else {
                // time_slots: availableSlotsForDate already honors $rule.
                // The shop override (if any) is applied by capping how many
                // distinct slots remain bookable, but the grid math — not
                // a shop-wide weight sum — is the primary gating factor.
                $slots = $this->availableSlotsForDate($tenant, $dateStr, null, 0, $rule);
                if (!empty($slots)) $available[] = $dateStr;
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
        return (int) TenantAppointment::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->sum('slot_weight');
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
                'service'                => $service,
                'addons'                 => $addonRows,
                'price_override_cents'   => $override,
                'effective_price_cents'  => $effectivePrice,
            ];
        }
        return $plan;
    }

    protected function upsertCustomer(array $data, string $tenantId): TenantCustomer
    {
        $email = strtolower(trim($data['email'] ?? ''));
        if ($email === '') throw new RuntimeException('Customer email is required.');

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
