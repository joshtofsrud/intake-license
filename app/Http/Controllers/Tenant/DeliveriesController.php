<?php
// MARKER-PATCH-152B

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDelivery;
use App\Services\Tenant\TenantDeliveryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * DeliveriesController — internal pickup/dropoff scheduling.
 *
 * Day or week views, create + edit, mark complete, cancel.
 * Customer notifications fire in 152-c.
 */
class DeliveriesController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        $view   = $request->query('view', 'week');
        if (!in_array($view, ['day', 'week'], true)) $view = 'week';

        $tz      = $tenant->timezone ?? config('app.timezone', 'UTC');
        $dateStr = $request->query('date');
        $date    = $dateStr ? Carbon::parse($dateStr, $tz) : Carbon::now($tz)->startOfDay();

        $svc = new TenantDeliveryService($tenant);

        $bookingMode = $tenant->booking_mode ?? 'drop_off';
        $isTimeSlot  = $bookingMode === 'time_slots';

        $payload = [
            'tenant'      => $tenant,
            'view'        => $view,
            'date'        => $date,
            'is_timeslot' => $isTimeSlot,
            'resources'   => $isTimeSlot ? $svc->activeResources() : collect(),
            'customers'   => $svc->customersForPicker(),
        ];

        if ($view === 'week') {
            $payload['days'] = $svc->forWeek($date);
        } else {
            $payload['deliveries'] = $svc->forDay($date);
        }

        return view('tenant.deliveries.index', $payload);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $data = $this->validateInput($request);

        $start = CarbonImmutable::parse($data['scheduled_at'], $tenant->timezone ?? 'UTC');

        // Conflict check on the delivery resource (if any)
        $svc = new TenantDeliveryService($tenant);
        if (!empty($data['delivery_resource_id'])) {
            $conflict = $svc->findResourceConflict(
                $data['delivery_resource_id'],
                $start,
                (int) ($data['window_minutes'] ?? 30)
            );
            if ($conflict) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'delivery_resource_id' => 'This resource already has a delivery scheduled at that time.',
                    ]);
            }
        }

        $delivery = TenantDelivery::create([
            'tenant_id'             => $tenant->id,
            'type'                  => $data['type'],
            'status'                => TenantDelivery::STATUS_SCHEDULED,
            'scheduled_at'          => $start,
            'window_minutes'        => (int) ($data['window_minutes'] ?? 30),
            'address'               => $data['address'] ?? null,
            'customer_id'           => $data['customer_id'],
            'work_order_id'         => $data['work_order_id'] ?? null,
            'appointment_id'        => $data['appointment_id'] ?? null,
            'delivery_resource_id'  => $data['delivery_resource_id'] ?? null,
            'notes'                 => $data['notes'] ?? null,
        ]);

        // MARKER-PATCH-152C — fire customer notification (email + SMS)
        // Failures are logged but don't break the save flow.
        \App\Services\Tenant\TenantDeliveryNotificationService::forTenant($tenant)
            ->sendScheduled($delivery);

        return back()->with('success', ucfirst($data['type']) . ' scheduled.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $delivery = TenantDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $this->validateInput($request);
        $start = CarbonImmutable::parse($data['scheduled_at'], $tenant->timezone ?? 'UTC');

        $svc = new TenantDeliveryService($tenant);
        if (!empty($data['delivery_resource_id'])) {
            $conflict = $svc->findResourceConflict(
                $data['delivery_resource_id'],
                $start,
                (int) ($data['window_minutes'] ?? 30),
                $delivery->id
            );
            if ($conflict) {
                return back()->withInput()->withErrors([
                    'delivery_resource_id' => 'This resource already has a delivery scheduled at that time.',
                ]);
            }
        }

        $delivery->update([
            'type'                  => $data['type'],
            'scheduled_at'          => $start,
            'window_minutes'        => (int) ($data['window_minutes'] ?? 30),
            'address'               => $data['address'] ?? null,
            'customer_id'           => $data['customer_id'],
            'work_order_id'         => $data['work_order_id'] ?? null,
            'appointment_id'        => $data['appointment_id'] ?? null,
            'delivery_resource_id'  => $data['delivery_resource_id'] ?? null,
            'notes'                 => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Delivery updated.');
    }

    public function complete(string $id): RedirectResponse
    {
        $tenant = tenant();
        $delivery = TenantDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $delivery->update([
            'status'       => TenantDelivery::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return back()->with('success', 'Marked complete.');
    }

    public function cancel(string $id): RedirectResponse
    {
        $tenant = tenant();
        $delivery = TenantDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $delivery->update([
            'status'       => TenantDelivery::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return back()->with('success', 'Cancelled.');
    }

    protected function validateInput(Request $request): array
    {
        return $request->validate([
            'type'                  => ['required', 'in:pickup,dropoff'],
            'customer_id'           => ['required', 'uuid'],
            'scheduled_at'          => ['required', 'date'],
            'window_minutes'        => ['nullable', 'integer', 'in:15,30,60,120'],
            'address'               => ['nullable', 'string', 'max:500'],
            'delivery_resource_id'  => ['nullable', 'uuid'],
            'work_order_id'         => ['nullable', 'uuid'],
            'appointment_id'        => ['nullable', 'uuid'],
            'notes'                 => ['nullable', 'string', 'max:5000'],
        ]);
    }
}