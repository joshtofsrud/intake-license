<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\LockAcquisitionException;
use App\Http\Controllers\Controller;
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

        return response()->json([
            'services'  => $services,
            'customers' => $customers,
            'resources' => $resources,
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
}
