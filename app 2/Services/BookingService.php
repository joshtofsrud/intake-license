<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentItem;
use App\Models\Tenant\TenantAppointmentAddon;
use App\Models\Tenant\TenantAppointmentResponse;
use App\Models\Tenant\TenantAppointmentNote;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantServiceTier;
use App\Models\Tenant\TenantAddon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class BookingService
{
    // ----------------------------------------------------------------
    // Generate a unique RA number
    // ----------------------------------------------------------------
    public static function generateRaNumber(Tenant $tenant): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $tenant->name), 0, 3));
        $prefix = str_pad($prefix, 3, 'X');

        do {
            $number = $prefix . '-' . strtoupper(substr(uniqid(), -6));
        } while (TenantAppointment::where('tenant_id', $tenant->id)
            ->where('ra_number', $number)->exists());

        return $number;
    }

    // ----------------------------------------------------------------
    // Available dates for a given month
    // Works for both drop_off and time_slots modes
    // ----------------------------------------------------------------
    public static function availableDates(Tenant $tenant, int $year, int $month): array
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
            ->where('rule_type', 'default')
            ->get()->keyBy('day_of_week');

        $overrides = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn($r) => $r->specific_date->toDateString());

        $available = [];
        $cursor    = $start->copy();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $dow     = $cursor->dayOfWeek;

            $rule = $overrides[$dateStr] ?? $defaults[$dow] ?? null;

            if (! $rule) { $cursor->addDay(); continue; }

            $max = (int) $rule->max_appointments;
            if ($max === 0) { $cursor->addDay(); continue; }

            if ($mode === 'drop_off') {
                // Drop-off: compare slot weight sum against max
                $used = TenantAppointment::where('tenant_id', $tenant->id)
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->where('appointment_date', $dateStr)
                    ->sum('slot_weight');

                if ($used < $max) $available[] = $dateStr;

            } else {
                // Time-slots: check if any slots are available
                $slots = self::availableSlotsForDate($tenant, $dateStr, $rule);
                if (! empty($slots)) $available[] = $dateStr;
            }

            $cursor->addDay();
        }

        return $available;
    }

    // ----------------------------------------------------------------
    // Available time slots for a specific date (time_slots mode)
    // Returns array of 'H:i' strings e.g. ['09:00','09:30','10:00']
    // ----------------------------------------------------------------
    public static function availableSlotsForDate(Tenant $tenant, string $date, $rule = null): array
    {
        if (! $rule) {
            $dow  = Carbon::parse($date)->dayOfWeek;
            $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')
                ->where('day_of_week', $dow)
                ->first();
        }

        if (! $rule || ! $rule->open_time || ! $rule->close_time) return [];

        $interval = (int) ($rule->slot_interval_minutes ?? 60);
        $open     = Carbon::parse($date . ' ' . $rule->open_time);
        $close    = Carbon::parse($date . ' ' . $rule->close_time);

        // Load existing appointments for this date with their durations
        $booked = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->get(['appointment_time', 'appointment_end_time', 'total_duration_minutes']);

        $slots = [];
        $cursor = $open->copy();

        while ($cursor->lt($close)) {
            $slotStart = $cursor->copy();

            // Check if this slot conflicts with any existing booking
            $conflict = $booked->first(function ($appt) use ($slotStart, $interval) {
                $apptStart = Carbon::parse($apptStart = $appt->appointment_time);
                $apptEnd   = $appt->appointment_end_time
                    ? Carbon::parse($appt->appointment_end_time)
                    : $apptStart->copy()->addMinutes($appt->total_duration_minutes ?: $interval);

                // New slot would overlap if it starts before appt ends AND ends after appt starts
                $slotEnd = $slotStart->copy()->addMinutes($interval);
                return $slotStart->lt($apptEnd) && $slotEnd->gt($apptStart);
            });

            if (! $conflict) {
                $slots[] = $slotStart->format('H:i');
            }

            $cursor->addMinutes($interval);
        }

        return $slots;
    }

    // ----------------------------------------------------------------
    // Calculate slot weight for an appointment
    // ----------------------------------------------------------------
    public static function calculateSlotWeight(array $itemRows, array $addonRows): int
    {
        $totalDuration = 0;
        $itemCount     = count($itemRows);

        foreach ($itemRows as $row) {
            $totalDuration += $row['duration_minutes'] ?? 60;
            $totalDuration += $row['buffer_minutes']   ?? 0;
        }

        foreach ($addonRows as $addon) {
            $totalDuration += $addon->duration_minutes ?? 0;
        }

        // Duration-based weight
        $weight = match(true) {
            $totalDuration > 240 => 4,
            $totalDuration > 180 => 3,
            $totalDuration > 120 => 2,
            default              => 1,
        };

        // Item count bonus
        if ($itemCount > 2) $weight = min(4, $weight + 1);

        return $weight;
    }

    // ----------------------------------------------------------------
    // Create appointment — wraps everything in a transaction
    // ----------------------------------------------------------------
    public static function createAppointment(Tenant $tenant, array $data): TenantAppointment
    {
        return DB::transaction(function () use ($tenant, $data) {

            // Upsert customer
            $customer = TenantCustomer::updateOrCreate(
                ['tenant_id' => $tenant->id, 'email' => strtolower($data['email'])],
                [
                    'first_name'    => $data['first_name'],
                    'last_name'     => $data['last_name'],
                    'phone'         => $data['phone']         ?? null,
                    'address_line1' => $data['address_line1'] ?? null,
                    'city'          => $data['city']          ?? null,
                    'state'         => $data['state']         ?? null,
                    'postcode'      => $data['postcode']      ?? null,
                    'country'       => $data['country']       ?? 'US',
                ]
            );

            // Resolve items with pricing and duration
            $itemRows   = [];
            $addonRows  = [];
            $subtotal   = 0;

            foreach ($data['items'] ?? [] as $sel) {
                $item = TenantServiceItem::find($sel['item_id']);
                $tier = TenantServiceTier::find($sel['tier_id']);
                if (!$item || !$tier) continue;

                $price = $item->tierPrices()
                    ->where('tier_id', $tier->id)
                    ->value('price_cents') ?? 0;

                $itemRows[] = [
                    'item'             => $item,
                    'tier'             => $tier,
                    'price_cents'      => (int) $price,
                    'duration_minutes' => $item->duration_minutes ?? 60,
                    'buffer_minutes'   => $item->buffer_minutes   ?? 0,
                ];
                $subtotal += (int) $price;
            }

            foreach ($data['addons'] ?? [] as $addonId) {
                $addon = TenantAddon::where('tenant_id', $tenant->id)->find($addonId);
                if (!$addon) continue;
                $addonRows[] = $addon;
                $subtotal   += (int) $addon->price_cents;
            }

            // Calculate durations and slot weight
            $totalDuration = array_sum(array_column($itemRows, 'duration_minutes'))
                + array_sum(array_column($itemRows, 'buffer_minutes'))
                + $addonRows ? array_sum(array_map(fn($a) => $a->duration_minutes ?? 0, $addonRows)) : 0;

            $slotWeightAuto = self::calculateSlotWeight($itemRows, $addonRows);

            // Calculate end time if appointment_time provided
            $appointmentTime    = $data['appointment_time'] ?? null;
            $appointmentEndTime = null;
            if ($appointmentTime && $totalDuration > 0) {
                $appointmentEndTime = Carbon::parse($data['date'] . ' ' . $appointmentTime)
                    ->addMinutes($totalDuration)
                    ->format('H:i:s');
            }

            // Create appointment
            $appointment = TenantAppointment::create([
                'tenant_id'                 => $tenant->id,
                'customer_id'               => $customer->id,
                'ra_number'                 => self::generateRaNumber($tenant),
                'customer_first_name'       => $data['first_name'],
                'customer_last_name'        => $data['last_name'],
                'customer_email'            => strtolower($data['email']),
                'customer_phone'            => $data['phone']            ?? null,
                'appointment_date'          => $data['date'],
                'appointment_time'          => $appointmentTime,
                'appointment_end_time'      => $appointmentEndTime,
                'total_duration_minutes'    => $totalDuration,
                'slot_weight'               => $slotWeightAuto,
                'slot_weight_auto'          => $slotWeightAuto,
                'slot_weight_overridden'    => false,
                'receiving_method_snapshot' => $data['receiving_method'] ?? null,
                'receiving_time_snapshot'   => $data['receiving_time']   ?? null,
                'status'                    => 'pending',
                'payment_status'            => 'unpaid',
                'subtotal_cents'            => $subtotal,
                'tax_cents'                 => 0,
                'total_cents'               => $subtotal,
                'paid_cents'                => 0,
            ]);

            // Line items
            foreach ($itemRows as $row) {
                TenantAppointmentItem::create([
                    'appointment_id'     => $appointment->id,
                    'item_id'            => $row['item']->id,
                    'tier_id'            => $row['tier']->id,
                    'item_name_snapshot' => $row['item']->name,
                    'tier_name_snapshot' => $row['tier']->name,
                    'price_cents'        => $row['price_cents'],
                ]);
            }

            // Add-ons
            foreach ($addonRows as $addon) {
                TenantAppointmentAddon::create([
                    'appointment_id'      => $appointment->id,
                    'addon_id'            => $addon->id,
                    'addon_name_snapshot' => $addon->name,
                    'price_cents'         => $addon->price_cents,
                ]);
            }

            // Form responses
            foreach ($data['responses'] ?? [] as $key => $value) {
                if ($value === null || $value === '') continue;
                TenantAppointmentResponse::create([
                    'appointment_id'       => $appointment->id,
                    'field_key_snapshot'   => $key,
                    'field_label_snapshot' => $data['response_labels'][$key] ?? $key,
                    'response_value'       => $value,
                ]);
            }

            // System note
            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => 'Appointment created via online booking form.',
                'created_at'          => now(),
            ]);

            self::sendConfirmationEmail($tenant, $appointment, $customer);

            return $appointment;
        });
    }

    // ----------------------------------------------------------------
    // Send confirmation emails
    // ----------------------------------------------------------------
    public static function sendConfirmationEmail(Tenant $tenant, TenantAppointment $appointment, $customer): void
    {
        $to      = $appointment->customer_email;
        $subject = "Booking confirmed — {$appointment->ra_number}";
        $body    = "Hi {$appointment->customer_first_name},\n\n"
            . "Your booking with {$tenant->name} is confirmed.\n\n"
            . "Reference: {$appointment->ra_number}\n"
            . "Date: {$appointment->appointment_date->format('F j, Y')}"
            . ($appointment->appointment_time ? " at " . Carbon::parse($appointment->appointment_time)->format('g:i A') : '') . "\n"
            . ($appointment->receiving_method_snapshot ? "Drop-off: {$appointment->receiving_method_snapshot}\n" : '')
            . "\nTotal: " . format_money($appointment->total_cents) . "\n\n"
            . "— {$tenant->name}";

        try {
            Mail::raw($body, fn($m) => $m
                ->to($to)
                ->from($tenant->emailFromAddress(), $tenant->emailFromName())
                ->subject($subject)
            );
        } catch (\Throwable $e) {
            logger()->error("Confirmation email failed: {$e->getMessage()}");
        }

        // Shop notification
        if ($notifyTo = $tenant->notification_email) {
            try {
                Mail::raw(
                    "New booking: {$appointment->ra_number}\n"
                    . "Customer: {$appointment->customer_first_name} {$appointment->customer_last_name}\n"
                    . "Date: {$appointment->appointment_date->format('F j, Y')}"
                    . ($appointment->appointment_time ? " at " . Carbon::parse($appointment->appointment_time)->format('g:i A') : '') . "\n"
                    . "Total: " . format_money($appointment->total_cents),
                    fn($m) => $m->to($notifyTo)->subject("New booking — {$appointment->ra_number}")
                );
            } catch (\Throwable $e) {
                logger()->error("Shop notification failed: {$e->getMessage()}");
            }
        }
    }
}
