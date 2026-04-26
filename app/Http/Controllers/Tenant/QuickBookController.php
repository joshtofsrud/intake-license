<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\LockAcquisitionException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCalendarBreak;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Services\BookingService;
use Illuminate\Http\Request;
use RuntimeException;

class QuickBookController extends Controller
{
    public function picker(Request $request)
    {
        $tenant = tenant();
        $search = trim((string) $request->query('customer_search', ''));

        $services = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'price_cents',
                   'prep_before_minutes', 'cleanup_after_minutes']);

        $customersQuery = TenantCustomer::where('tenant_id', $tenant->id);
        if ($search !== '') {
            $customersQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%");
            });
        }
        $customers = $customersQuery
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $resources = TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'subtitle', 'color_hex']);

        // Business hours per day-of-week (0=Sun..6=Sat). Used by the Break tab
        // to show "Until X:XX PM" when the shop owner picks Rest of day mode.
        // Days with no rule (or null open/close) are reported as closed.
        $rules = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->get(['day_of_week', 'open_time', 'close_time']);

        $businessHours = [];
        for ($d = 0; $d < 7; $d++) {
            $rule = $rules->firstWhere('day_of_week', $d);
            if ($rule && $rule->open_time && $rule->close_time) {
                $businessHours[$d] = [
                    'open'  => substr($rule->open_time, 0, 5),
                    'close' => substr($rule->close_time, 0, 5),
                ];
            } else {
                $businessHours[$d] = null;
            }
        }

        return response()->json([
            'services'       => $services,
            'customers'      => $customers,
            'resources'      => $resources,
            'business_hours' => $businessHours,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'date'             => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'string'],
            'resource_id'      => ['required', 'string', 'uuid'],
            'service_item_id'  => ['required', 'string', 'uuid'],
            'customer_id'      => ['nullable', 'string', 'uuid'],
            'first_name'       => ['required_without:customer_id', 'string', 'max:100'],
            'last_name'        => ['required_without:customer_id', 'string', 'max:100'],
            'email'            => ['required_without:customer_id', 'email', 'max:191'],
            'phone'            => ['nullable', 'string', 'max:32'],
        ]);

        $tenant = tenant();

        $first = $request->input('first_name');
        $last  = $request->input('last_name');
        $email = $request->input('email');
        $phone = $request->input('phone');

        if ($customerId = $request->input('customer_id')) {
            $customer = TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $customerId)
                ->first();
            if ($customer) {
                $first = $customer->first_name;
                $last  = $customer->last_name;
                $email = $customer->email;
                $phone = $customer->phone;
            }
        }

        $payload = [
            'first_name'       => $first,
            'last_name'        => $last,
            'email'            => $email,
            'phone'            => $phone,
            'date'             => $request->input('date'),
            'appointment_time' => $request->input('appointment_time'),
            'resource_id'      => $request->input('resource_id'),
            'items'            => [
                ['service_item_id' => $request->input('service_item_id'), 'addon_ids' => []],
            ],
            'payment_method'   => 'none',
        ];

        try {
            $appointment = app(BookingService::class)->createAppointment($payload, $tenant->id);
        } catch (LockAcquisitionException $e) {
            return response()->json([
                'success' => false,
                'code'    => 'lock_timeout',
                'message' => 'We could not hold this slot. Please try again.',
            ], 409);
        } catch (RuntimeException $e) {
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

        return response()->json([
            'success'     => true,
            'appointment' => [
                'id'        => $appointment->id,
                'ra_number' => $appointment->ra_number,
            ],
        ]);
    }

    /**
     * Create a one-off break on the calendar.
     *
     * Two modes:
     *   - duration: starts_at = date + start_time, ends_at = starts_at + minutes
     *   - rest_of_day: ends_at = date + tenant capacity rule close_time for that DOW
     *
     * Single-resource breaks only in v1. Recurring breaks happen elsewhere.
     */
    public function storeBreak(Request $request)
    {
        $request->validate([
            'date'        => ['required', 'date', 'after_or_equal:today'],
            'start_time'  => ['required', 'string'],
            'mode'        => ['required', 'in:duration,rest_of_day'],
            'duration_minutes' => ['required_if:mode,duration', 'nullable', 'integer', 'min:5', 'max:1440'],
            'resource_id' => ['required', 'string', 'uuid'],
            'label'       => ['nullable', 'string', 'max:100'],
        ]);

        $tenant = tenant();

        // Validate resource belongs to this tenant
        $resource = TenantResource::where('tenant_id', $tenant->id)
            ->where('id', $request->input('resource_id'))
            ->where('is_active', true)
            ->first();

        if (!$resource) {
            return response()->json([
                'success' => false,
                'message' => 'Selected resource is not available.',
            ], 422);
        }

        // Compose start datetime — treat as tenant local time, store as-is.
        // The day grid reads the time portion only (hour/minute), so this
        // matches how the calendar already renders breaks.
        $date      = $request->input('date');
        $startTime = $request->input('start_time');
        $startTime = substr($startTime, 0, 5) . ':00';
        $startsAt  = $date . ' ' . $startTime;

        // Compute ends_at based on mode.
        if ($request->input('mode') === 'rest_of_day') {
            $dow = (new \DateTimeImmutable($date))->format('w'); // 0=Sun..6=Sat
            $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')
                ->where('day_of_week', (int) $dow)
                ->first();

            if (!$rule || !$rule->close_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'No business hours set for this day.',
                ], 422);
            }

            $closeTime = substr($rule->close_time, 0, 5) . ':00';
            $endsAt    = $date . ' ' . $closeTime;

            // Sanity: if start >= close, refuse — nothing to block out.
            if (strtotime($endsAt) <= strtotime($startsAt)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Start time is at or after closing time. Pick an earlier start.',
                ], 422);
            }
        } else {
            $minutes = (int) $request->input('duration_minutes');
            $endsAt  = date('Y-m-d H:i:s', strtotime($startsAt) + ($minutes * 60));
        }

        $label = trim((string) $request->input('label', '')) ?: 'Break';

        $break = TenantCalendarBreak::create([
            'tenant_id'          => $tenant->id,
            'resource_id'        => $resource->id,
            'label'              => $label,
            'starts_at'          => $startsAt,
            'ends_at'            => $endsAt,
            'is_recurring'       => false,
            'recurrence_type'    => null,
            'recurrence_config'  => null,
            'recurrence_until'   => null,
            'created_by_user_id' => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
        ]);

        return response()->json([
            'success' => true,
            'break'   => [
                'id'        => $break->id,
                'label'     => $break->label,
                'starts_at' => $break->starts_at->toDateTimeString(),
                'ends_at'   => $break->ends_at->toDateTimeString(),
            ],
        ]);
    }
}
