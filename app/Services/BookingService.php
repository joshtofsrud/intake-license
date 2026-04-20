<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentAddon;
use App\Models\Tenant\TenantAppointmentItem;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantServiceAddon;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BookingService
{
    public function createAppointment(array $data, string $tenantId): TenantAppointment
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one service is required.');
        }

        $plan = $this->buildBookingPlan($data['items'], $tenantId);

        $totalCents    = 0;
        $totalDuration = 0;
        $slotWeight    = 0;

        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents    += (int) $service->price_cents;
            $totalDuration += (int) $service->prep_before_minutes
                            + (int) $service->duration_minutes
                            + (int) $service->cleanup_after_minutes;
            $slotWeight    += (int) ($service->slot_weight ?? 1);

            foreach ($row['addons'] as $addonRow) {
                $totalCents    += (int) $addonRow['effective_price_cents'];
                $totalDuration += (int) $addonRow['effective_duration'];
            }
        }

        $appointmentTime = !empty($data['appointment_time']) ? $data['appointment_time'] : null;
        $appointmentEndTime = null;
        if ($appointmentTime && $totalDuration > 0) {
            $start = new \DateTimeImmutable($appointmentTime);
            $end = $start->modify("+{$totalDuration} minutes");
            $appointmentEndTime = $end->format('H:i:s');
        }

        return DB::transaction(function () use (
            $data, $tenantId, $plan,
            $totalCents, $totalDuration, $slotWeight,
            $appointmentTime, $appointmentEndTime
        ) {
            $customer = $this->upsertCustomer($data, $tenantId);
            $raNumber = TenantAppointment::generateRaNumber($tenantId, $data['date'] ?? null);

            $appointment = TenantAppointment::create([
                'id'                       => (string) Str::uuid(),
                'tenant_id'                => $tenantId,
                'customer_id'              => $customer->id,
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
                    'id'                       => (string) Str::uuid(),
                    'appointment_id'           => $appointment->id,
                    'service_item_id'          => $service->id,
                    'item_name_snapshot'       => $service->name,
                    'price_cents'              => $service->price_cents,
                    'duration_minutes_snapshot'=> $service->duration_minutes,
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
            return $appointment->fresh(['items', 'addons', 'customer', 'responses']);
        });
    }

    public function availableDates(Tenant $tenant, int $year, int $month): array
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

        $available = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $dow = $cursor->dayOfWeek;
            $rule = $overrides[$dateStr] ?? $defaults[$dow] ?? null;
            if (!$rule) { $cursor->addDay(); continue; }
            $max = (int) $rule->max_appointments;
            if ($max === 0) { $cursor->addDay(); continue; }

            if ($mode === 'drop_off') {
                $used = TenantAppointment::where('tenant_id', $tenant->id)
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->where('appointment_date', $dateStr)->sum('slot_weight');
                if ($used < $max) $available[] = $dateStr;
            } else {
                $slots = $this->availableSlotsForDate($tenant, $dateStr, $rule);
                if (!empty($slots)) $available[] = $dateStr;
            }
            $cursor->addDay();
        }
        return $available;
    }

    public function availableSlotsForDate(Tenant $tenant, string $date, $rule = null): array
    {
        if (!$rule) {
            $dow = Carbon::parse($date)->dayOfWeek;
            $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')->where('day_of_week', $dow)->first();
        }
        if (!$rule || !$rule->open_time || !$rule->close_time) return [];

        $interval = (int) ($rule->slot_interval_minutes ?? 60);
        $open  = Carbon::parse($date . ' ' . $rule->open_time);
        $close = Carbon::parse($date . ' ' . $rule->close_time);

        $booked = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->get(['appointment_time', 'appointment_end_time', 'total_duration_minutes']);

        $slots = [];
        $cursor = $open->copy();
        while ($cursor->lt($close)) {
            $slotStart = $cursor->copy();
            $slotEnd   = $slotStart->copy()->addMinutes($interval);
            $conflict = $booked->first(function ($appt) use ($date, $slotStart, $slotEnd, $interval) {
                $apptStart = Carbon::parse($date . ' ' . $appt->appointment_time);
                $apptEnd   = $appt->appointment_end_time
                    ? Carbon::parse($date . ' ' . $appt->appointment_end_time)
                    : $apptStart->copy()->addMinutes($appt->total_duration_minutes ?: $interval);
                return $slotStart->lt($apptEnd) && $slotEnd->gt($apptStart);
            });
            if (!$conflict) $slots[] = $slotStart->format('H:i');
            $cursor->addMinutes($interval);
        }
        return $slots;
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
            $plan[] = ['service' => $service, 'addons' => $addonRows];
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
