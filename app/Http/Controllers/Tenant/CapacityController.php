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

        // Seed defaults if none exist. Default closed = Sun/Sat; open Mon–Fri 9–5.
        // Tenants edit this in the capacity admin; we just provide a reasonable starting shape.
        if ($defaults->isEmpty()) {
            for ($d = 0; $d <= 6; $d++) {
                $isWeekend = in_array($d, [0, 6]);
                $rule = TenantCapacityRule::create([
                    'tenant_id'             => $tenant->id,
                    'rule_type'             => 'default',
                    'day_of_week'           => $d,
                    'max_appointments'      => null,
                    'open_time'             => $isWeekend ? null : '09:00:00',
                    'close_time'            => $isWeekend ? null : '17:00:00',
                    'slot_interval_minutes' => 60,
                    'is_closed'             => $isWeekend,
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
            'is_closed'             => (bool) $r->is_closed,
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

        // Per-resource caps (read-only on capacity page; editable on resources page).
        // Pass as array of {id, name, color_hex, max_appointments_per_day} for the
        // capacity admin to render the "Resource caps sum: X" summary line.
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'color_hex', 'max_appointments_per_day']);

        $jsResources = $resources->map(fn($r) => [
            'id'                       => $r->id,
            'name'                     => $r->name,
            'color_hex'                => $r->color_hex,
            'max_appointments_per_day' => $r->max_appointments_per_day,
        ])->values();

        $resourceCapSum = (int) $resources->sum('max_appointments_per_day');

        return view('tenant.capacity.index', compact(
            'jsDefaults', 'jsOverrides', 'jsUsage', 'mode',
            'jsResources', 'resourceCapSum'
        ));
    }

    public function store(Request $request)
    {
        $tenant = tenant();
        $op     = $request->input('op');

        if ($op === 'save_defaults') {
            $days = $request->input('days', []);
            foreach ($days as $day => $data) {
                $updates = [];

                // is_closed comes first — if true, null out time/interval/max so
                // "saved-closed = lose prior values" per spec item 3.
                $isClosed = isset($data['is_closed']) ? (bool) $data['is_closed'] : false;
                $updates['is_closed'] = $isClosed;

                if ($isClosed) {
                    $updates['open_time']             = null;
                    $updates['close_time']            = null;
                    $updates['max_appointments']      = null;
                } else {
                    // max_appointments is NULL when blank — represents "no shop-wide override".
                    $maxRaw = $data['max'] ?? null;
                    $updates['max_appointments'] = ($maxRaw === '' || $maxRaw === null)
                        ? null
                        : max(0, (int) $maxRaw);

                    if (isset($data['open_time']))             $updates['open_time']             = $data['open_time'];
                    if (isset($data['close_time']))            $updates['close_time']            = $data['close_time'];
                    if (isset($data['slot_interval_minutes'])) $updates['slot_interval_minutes'] = (int) $data['slot_interval_minutes'];
                }

                TenantCapacityRule::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'rule_type' => 'default', 'day_of_week' => (int)$day],
                    $updates
                );
            }
            return response()->json(['success' => true]);
        }

        if ($op === 'save_override') {
            $request->validate([
                'date'      => ['required', 'date', 'after_or_equal:today'],
                'max'       => ['nullable', 'integer', 'min:0'],
                'is_closed' => ['nullable', 'boolean'],
                'note'      => ['nullable', 'string', 'max:255'],
            ]);

            $isClosed = (bool) $request->input('is_closed', false);
            $payload = [
                'is_closed' => $isClosed,
                'note'      => $request->input('note', ''),
            ];
            if ($isClosed) {
                $payload['max_appointments'] = null;
            } else {
                $maxRaw = $request->input('max');
                $payload['max_appointments'] = ($maxRaw === null || $maxRaw === '') ? null : (int) $maxRaw;
            }

            $rule = TenantCapacityRule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'rule_type' => 'override', 'specific_date' => $request->input('date')],
                $payload
            );
            return response()->json([
                'success'   => true,
                'id'        => $rule->id,
                'date'      => $rule->specific_date->format('Y-m-d'),
                'max'       => $rule->max_appointments,
                'is_closed' => (bool) $rule->is_closed,
                'note'      => $rule->note,
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
