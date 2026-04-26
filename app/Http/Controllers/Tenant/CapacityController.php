<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCapacityRule;
use App\Services\BookingModeService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CapacityController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        $defaults = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->orderBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        // Seed defaults if none exist
        if ($defaults->isEmpty()) {
            for ($d = 0; $d <= 6; $d++) {
                $rule = TenantCapacityRule::create([
                    'tenant_id'             => $tenant->id,
                    'rule_type'             => 'default',
                    'day_of_week'           => $d,
                    'max_appointments'      => in_array($d, [0, 6]) ? 0 : 8,
                    'open_time'             => '09:00:00',
                    'close_time'            => '17:00:00',
                    'slot_interval_minutes' => 60,
                ]);
                $defaults[$d] = $rule;
            }
        }

        $overrides = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'override')
            ->where('specific_date', '>=', $tenant->localToday()->toDateString())
            ->orderBy('specific_date')
            ->get();

        // Slot consumption per day (for display)
        $today      = $tenant->localToday()->toDateString();
        $weekEnd    = $tenant->localToday()->addDays(7)->toDateString();
        $slotUsage  = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereBetween('appointment_date', [$today, $weekEnd])
            ->selectRaw('appointment_date, SUM(slot_weight) as slots_used, COUNT(*) as job_count')
            ->groupBy('appointment_date')
            ->get()
            ->keyBy('appointment_date');

        $jsDefaults  = $defaults->map(fn($r) => [
            'id'                    => $r->id,
            'day'                   => $r->day_of_week,
            'max'                   => $r->max_appointments,
            'open_time'             => $r->open_time ? substr($r->open_time, 0, 5) : '09:00',
            'close_time'            => $r->close_time ? substr($r->close_time, 0, 5) : '17:00',
            'slot_interval_minutes' => $r->slot_interval_minutes ?? 60,
        ])->values();

        $jsOverrides = $overrides->map(fn($r) => [
            'id'   => $r->id,
            'date' => $r->specific_date->format('Y-m-d'),
            'max'  => $r->max_appointments,
            'note' => $r->note,
        ])->values();

        $jsUsage = $slotUsage->map(fn($u) => [
            'slots_used' => (int) $u->slots_used,
            'job_count'  => (int) $u->job_count,
        ]);

        $mode        = $tenant->booking_mode ?? 'drop_off';
        $switchPreview = null;

        return view('tenant.capacity.index', compact(
            'jsDefaults', 'jsOverrides', 'jsUsage', 'mode'
        ));
    }

    public function store(Request $request)
    {
        $tenant = tenant();
        $op     = $request->input('op');

        if ($op === 'save_defaults') {
            $days = $request->input('days', []);
            foreach ($days as $day => $data) {
                $updates = ['max_appointments' => max(0, (int)($data['max'] ?? $data))];

                // Time slot mode fields
                if (isset($data['open_time']))             $updates['open_time']             = $data['open_time'];
                if (isset($data['close_time']))            $updates['close_time']             = $data['close_time'];
                if (isset($data['slot_interval_minutes'])) $updates['slot_interval_minutes']  = (int)$data['slot_interval_minutes'];

                TenantCapacityRule::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'rule_type' => 'default', 'day_of_week' => (int)$day],
                    $updates
                );
            }
            return response()->json(['success' => true]);
        }

        if ($op === 'save_override') {
            $request->validate([
                'date' => ['required', 'date', 'after_or_equal:today'],
                'max'  => ['required', 'integer', 'min:0'],
            ]);
            $rule = TenantCapacityRule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'rule_type' => 'override', 'specific_date' => $request->input('date')],
                ['max_appointments' => (int)$request->input('max'), 'note' => $request->input('note', '')]
            );
            return response()->json([
                'success' => true,
                'id'      => $rule->id,
                'date'    => $rule->specific_date->format('Y-m-d'),
                'max'     => $rule->max_appointments,
                'note'    => $rule->note,
            ]);
        }

        if ($op === 'delete_override') {
            TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'override')
                ->where('id', $request->input('id'))
                ->delete();
            return response()->json(['success' => true]);
        }

        // Mode switch preview
        if ($op === 'preview_switch') {
            $toMode              = $request->input('to_mode');
            $servicePreview      = BookingModeService::previewSwitch($tenant, $toMode);
            $appointmentPreview  = BookingModeService::previewAppointmentMigration($tenant, $toMode);
            return response()->json([
                'success'               => true,
                'preview'               => $servicePreview,
                'appointment_preview'   => $appointmentPreview,
                'rate_limited'          => BookingModeService::isRateLimited($tenant),
                'rate_limit_remaining'  => BookingModeService::rateLimitRemainingHours($tenant),
                'last_switch_at'        => $tenant->last_booking_mode_switch_at?->toIso8601String(),
            ]);
        }

        // Mode switch execute
        if ($op === 'execute_switch') {
            $toMode    = $request->input('to_mode');
            $overrides = $request->input('overrides', '{}');

            // JS sends JSON-encoded string via FormData — decode to array.
            if (is_string($overrides)) {
                $decoded = json_decode($overrides, true);
                $overrides = is_array($decoded) ? $decoded : [];
            }

            if (! in_array($toMode, ['drop_off', 'time_slots'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid target mode.',
                ], 422);
            }

            // Rate limit guard — one switch per tenant per 24 hours.
            if (BookingModeService::isRateLimited($tenant)) {
                $remaining = BookingModeService::rateLimitRemainingHours($tenant);
                return response()->json([
                    'success' => false,
                    'code'    => 'rate_limited',
                    'message' => "Mode switching is rate-limited. Try again in {$remaining} hours.",
                ], 429);
            }

            // Optional appointment assignments payload (combined wizard sends this).
            $assignments = $request->input('assignments', '{}');
            if (is_string($assignments)) {
                $decoded = json_decode($assignments, true);
                $assignments = is_array($decoded) ? $decoded : [];
            }

            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $toMode, $overrides, $assignments) {
                    BookingModeService::executeSwitch($tenant, $toMode, $overrides);
                    if ($toMode === 'time_slots' && !empty($assignments)) {
                        BookingModeService::applyAppointmentAssignments($tenant, $assignments);
                    }
                    $tenant->update(['last_booking_mode_switch_at' => now()]);
                });
                return response()->json(['success' => true, 'mode' => $toMode]);
            } catch (\Throwable $e) {
                \Log::error('Booking mode switch failed', [
                    'tenant_id' => $tenant->id,
                    'to_mode'   => $toMode,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Switch failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        return response()->json(['success' => false, 'message' => 'Unknown op.'], 422);
    }
}
