<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantCapacityRule;

class BookingModeService
{
    // ----------------------------------------------------------------
    // Preview what switching modes will change
    // Returns items that need review before switching
    // ----------------------------------------------------------------
    public static function previewSwitch(Tenant $tenant, string $toMode): array
    {
        $items = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        $preview = [];

        foreach ($items as $item) {
            if ($toMode === 'time_slots') {
                // Switching to time slots — show estimated duration from slot weight
                $estimatedDuration = self::durationFromWeight($item->slot_weight ?? 1);
                $preview[] = [
                    'id'                 => $item->id,
                    'name'               => $item->name,
                    'current_weight'     => $item->slot_weight ?? 1,
                    'estimated_duration' => $estimatedDuration,
                    'current_duration'   => $item->duration_minutes,
                    'needs_review'       => $item->duration_minutes === 60 && ($item->slot_weight ?? 1) > 1,
                ];
            } else {
                // Switching to drop-off — show estimated weight from duration
                $estimatedWeight = self::weightFromDuration($item->duration_minutes ?? 60);
                $preview[] = [
                    'id'               => $item->id,
                    'name'             => $item->name,
                    'current_duration' => $item->duration_minutes ?? 60,
                    'estimated_weight' => $estimatedWeight,
                    'current_weight'   => $item->slot_weight ?? 1,
                    'needs_review'     => $estimatedWeight !== ($item->slot_weight ?? 1),
                ];
            }
        }

        return $preview;
    }

    // ----------------------------------------------------------------
    // Execute the mode switch
    // Converts all service items to the new mode's data model
    // ----------------------------------------------------------------
    public static function executeSwitch(Tenant $tenant, string $toMode, array $overrides = []): void
    {
        $items = TenantServiceItem::where('tenant_id', $tenant->id)->get();

        foreach ($items as $item) {
            $updates = [];

            if ($toMode === 'time_slots') {
                // Use override if provided, otherwise estimate from slot weight
                $duration = $overrides[$item->id]['duration_minutes']
                    ?? self::durationFromWeight($item->slot_weight ?? 1);
                $updates['duration_minutes'] = (int) $duration;
            } else {
                // Use override if provided, otherwise estimate from duration
                $weight = $overrides[$item->id]['slot_weight']
                    ?? self::weightFromDuration($item->duration_minutes ?? 60);
                $updates['slot_weight'] = (int) min(4, max(1, $weight));
            }

            $item->update($updates);
        }

        // Update capacity rules with sensible defaults for time_slots mode
        if ($toMode === 'time_slots') {
            TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')
                ->whereNull('open_time')
                ->update([
                    'open_time'             => '09:00:00',
                    'close_time'            => '17:00:00',
                    'slot_interval_minutes' => 60,
                ]);
        }

        $tenant->update(['booking_mode' => $toMode]);
    }

    // ----------------------------------------------------------------
    // Conversion helpers
    // ----------------------------------------------------------------
    public static function durationFromWeight(int $weight): int
    {
        return match($weight) {
            1 => 60,
            2 => 120,
            3 => 180,
            4 => 240,
            default => 60,
        };
    }

    public static function weightFromDuration(int $minutes): int
    {
        return match(true) {
            $minutes > 180 => 4,
            $minutes > 120 => 3,
            $minutes > 60  => 2,
            default        => 1,
        };
    }

    // ----------------------------------------------------------------
    // Rate limit: one mode switch per tenant per 24 hours.
    // At scale, frequent flips corrupt appointment data and confuse
    // customers — deliberately slow this down.
    // ----------------------------------------------------------------
    public const RATE_LIMIT_HOURS = 24;

    public static function isRateLimited(Tenant $tenant): bool
    {
        if (!$tenant->last_booking_mode_switch_at) return false;
        $last = \Carbon\Carbon::parse($tenant->last_booking_mode_switch_at);
        return $last->diffInHours(now()) < self::RATE_LIMIT_HOURS;
    }

    public static function rateLimitRemainingHours(Tenant $tenant): int
    {
        if (!$tenant->last_booking_mode_switch_at) return 0;
        $last = \Carbon\Carbon::parse($tenant->last_booking_mode_switch_at);
        $elapsed = $last->diffInHours(now());
        return max(0, self::RATE_LIMIT_HOURS - (int) $elapsed);
    }

    // ----------------------------------------------------------------
    // Preview appointment migration (only relevant drop_off -> time_slots).
    // Returns rows with proposed times computed by sequential packing
    // against the target business hours. Admin can override per-row.
    // ----------------------------------------------------------------
    public static function previewAppointmentMigration(Tenant $tenant, string $toMode): array
    {
        // Only drop_off -> time_slots needs appointment migration.
        // Reverse direction: time_slots have times, drop_off ignores them — safe.
        if ($toMode !== 'time_slots') return ['count' => 0, 'rows' => []];

        $today = now()->toDateString();
        $appointments = \App\Models\Tenant\TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->where('appointment_date', '>=', $today)
            ->whereNull('appointment_time')
            ->with('items:id,appointment_id,item_name_snapshot,duration_minutes_snapshot')
            ->orderBy('appointment_date')
            ->orderBy('created_at')
            ->get();

        if ($appointments->isEmpty()) return ['count' => 0, 'rows' => []];

        // Group by date so we can pack sequentially per-day.
        $byDate = $appointments->groupBy('appointment_date');
        $rows = [];

        foreach ($byDate as $date => $group) {
            $dow  = \Carbon\Carbon::parse($date)->dayOfWeek;
            $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')
                ->where('day_of_week', $dow)
                ->first();

            // Default to 9-5 if no rule exists yet (about to be seeded by executeSwitch)
            $openTime  = $rule?->open_time  ?? '09:00:00';
            $closeTime = $rule?->close_time ?? '17:00:00';
            $openMin   = self::hmsToMinutes($openTime);
            $closeMin  = self::hmsToMinutes($closeTime);

            $cursor = $openMin;
            foreach ($group as $appt) {
                // Duration from snapshot, or fall back to 60 min.
                $duration = (int) ($appt->total_duration_minutes ?: 60);
                if ($duration <= 0) $duration = 60;

                $suggestedMin = $cursor;
                $fitsInDay    = ($cursor + $duration) <= $closeMin;

                $customerName = trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? ''));
                $serviceName  = optional($appt->items->first())->item_name_snapshot ?? 'Appointment';

                $rows[] = [
                    'id'             => $appt->id,
                    'customer_name'  => $customerName ?: 'Customer',
                    'date'           => $date,
                    'service_name'   => $serviceName,
                    'duration'       => $duration,
                    'suggested_time' => self::minutesToHm($suggestedMin),
                    'fits_in_day'    => $fitsInDay,
                ];

                $cursor += $duration;
            }
        }

        return ['count' => count($rows), 'rows' => $rows];
    }

    // ----------------------------------------------------------------
    // Apply appointment time assignments. Called by executeSwitch when
    // $toMode === 'time_slots' and the admin supplied assignments.
    //
    // $assignments shape:
    //   [ appointment_id => ['time' => '09:00', 'action' => 'assign'|'cancel'|'skip'], ... ]
    //
    // Any appointments NOT in $assignments fall back to sequential packing
    // and are marked needs_time_review for later admin attention.
    // ----------------------------------------------------------------
    public static function applyAppointmentAssignments(Tenant $tenant, array $assignments): void
    {
        $preview = self::previewAppointmentMigration($tenant, 'time_slots');
        if (($preview['count'] ?? 0) === 0) return;

        foreach ($preview['rows'] as $row) {
            $apptId = $row['id'];
            $a      = $assignments[$apptId] ?? null;
            $action = $a['action'] ?? 'assign';

            $appt = \App\Models\Tenant\TenantAppointment::find($apptId);
            if (!$appt) continue;

            if ($action === 'cancel') {
                $appt->update(['status' => 'cancelled']);
                continue;
            }

            if ($action === 'skip') {
                // Admin explicitly deferred — mark for review, no time set.
                $appt->update(['needs_time_review' => true]);
                continue;
            }

            // Default: assign. Use admin time if provided, else suggested.
            $time   = $a['time'] ?? $row['suggested_time'];
            $time   = preg_replace('/[^0-9:]/', '', $time);
            if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                // Garbage input — fall back to suggested + mark for review.
                $time = $row['suggested_time'];
                $appt->update([
                    'appointment_time'  => $time . ':00',
                    'needs_time_review' => true,
                ]);
                continue;
            }

            $timeFull = strlen($time) === 4 ? '0' . $time . ':00' : $time . ':00';
            // Compute end_time from start + existing duration.
            $startMin = self::hmsToMinutes($timeFull);
            $endMin   = $startMin + (int) ($appt->total_duration_minutes ?: $row['duration']);

            $appt->update([
                'appointment_time'     => $timeFull,
                'appointment_end_time' => self::minutesToHm($endMin) . ':00',
                'needs_time_review'    => !$row['fits_in_day'],
            ]);
        }
    }

    protected static function hmsToMinutes(string $hms): int
    {
        $parts = explode(':', $hms);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    protected static function minutesToHm(int $mins): string
    {
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return sprintf('%02d:%02d', $h, $m);
    }
}
