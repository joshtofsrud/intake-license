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
    // MARKER-PATCH-427 — list a customer's saved bikes (+ composed address) for the delivery drawer.
    public function customerAssets(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $customer = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', (string) $request->query('customer_id'))
            ->first();
        if (!$customer) {
            return response()->json(['assets' => [], 'address' => null]);
        }

        $assets = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'identifier'])
            ->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->name, 'identifier' => $a->identifier])
            ->values()->all();

        $address = collect([
            $customer->address_line1, $customer->address_line2,
            $customer->city, $customer->state, $customer->postcode,
        ])->filter()->implode(', ');

        return response()->json(['assets' => $assets, 'address' => $address ?: null]);
    }

    // MARKER-PATCH-427 — snapshot the selected customer bikes onto the delivery (name + identifier).
    private function snapshotAssets(array $data): array
    {
        $ids = array_values(array_filter((array) ($data['assets'] ?? [])));
        if (empty($ids) || empty($data['customer_id'])) {
            return [];
        }

        return \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', tenant()->id)
            ->where('customer_id', $data['customer_id'])
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'identifier'])
            ->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->name, 'identifier' => $a->identifier])
            ->values()->all();
    }

    // MARKER-PATCH-329 — render one pickup/dropoff slip on demand.
    public function printSlip(Request $request, string $id): View
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $d = TenantDelivery::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with('customer')
            ->firstOrFail();

        $appt = $d->appointment_id
            ? \App\Models\Tenant\TenantAppointment::with('items')->find($d->appointment_id)
            : null;
        $assets = $d->appointment_id
            ? \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $d->appointment_id)->orderBy('sort_order')->get()
            : collect();

        $slips = [[
            'type'        => $d->type === \App\Models\Tenant\TenantDelivery::TYPE_DROPOFF ? 'DROP-OFF' : 'PICKUP',
            'customer'    => $d->customer
                               ? (trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: 'Customer')
                               : 'Customer',
            'phone'       => $d->customer->phone ?? null,
            'address'     => $d->address,
            'time'        => tlocal($d->scheduled_at, 'g:i A'),
            'window_end'  => $d->window_minutes
                               ? tlocal($d->scheduled_at->copy()->addMinutes($d->window_minutes), 'g:i A')
                               : null,
            'asset_count' => !empty($d->assets) ? count($d->assets) : $assets->count(),
            'assets'      => !empty($d->assets)
                               ? collect($d->assets)->map(fn ($a) => trim(($a['name'] ?? '') . (!empty($a['identifier']) ? ' · ' . $a['identifier'] : '')))->filter()->values()->all()
                               : $assets->map(fn ($a) => trim(($a->asset_name_snapshot ?? '') . ($a->identifier_snapshot ? ' · ' . $a->identifier_snapshot : '')))->filter()->values()->all(),
            'items'       => $appt ? $appt->items->pluck('item_name_snapshot')->filter()->values()->all() : [],
            'job'         => $appt?->ra_number,
            'notes'       => $d->notes ?: $appt?->staff_notes,
        ]];

        $cfg   = (array) (($tenant->settings['work_order_tag'] ?? []));
        $print = \App\Services\PrintIdentityService::forTenant($tenant); // MARKER-PATCH-332
        $embed     = $request->boolean('embed');
        $dateLabel = tlocal($d->scheduled_at, 'D M j, Y');

        return view('tenant.deliveries.slips', compact('tenant', 'slips', 'print', 'embed', 'dateLabel'));
    }

    // MARKER-PATCH-321 — render the day's pickup/delivery slips (80mm stack).
    public function printSlips(Request $request): View
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $tz      = method_exists($tenant, 'timezone') ? $tenant->timezone() : ($tenant->timezone ?? config('app.timezone', 'UTC'));
        $dateStr = $request->query('date');
        $date    = $dateStr ? Carbon::parse($dateStr, $tz) : Carbon::now($tz);
        $start   = $date->copy()->startOfDay();
        $startUtc = $start->copy()->utc();
        $endUtc   = $start->copy()->addDay()->utc();

        $deliveries = TenantDelivery::where('tenant_id', $tenant->id)
            ->whereBetween('scheduled_at', [$startUtc, $endUtc])
            ->whereNull('cancelled_at')
            ->with('customer')
            ->orderBy('scheduled_at')
            ->get();

        $slips = [];
        foreach ($deliveries as $d) {
            $appt = $d->appointment_id
                ? \App\Models\Tenant\TenantAppointment::with('items')->find($d->appointment_id)
                : null;
            $assets = $d->appointment_id
                ? \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $d->appointment_id)->orderBy('sort_order')->get()
                : collect();

            $slips[] = [
                'type'        => $d->type === \App\Models\Tenant\TenantDelivery::TYPE_DROPOFF ? 'DROP-OFF' : 'PICKUP',
                'customer'    => $d->customer
                                   ? (trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: 'Customer')
                                   : 'Customer',
                'phone'       => $d->customer->phone ?? null,
                'address'     => $d->address,
                'time'        => tlocal($d->scheduled_at, 'g:i A'),
                'window_end'  => $d->window_minutes
                                   ? tlocal($d->scheduled_at->copy()->addMinutes($d->window_minutes), 'g:i A')
                                   : null,
                'asset_count' => !empty($d->assets) ? count($d->assets) : $assets->count(),
                'assets'      => !empty($d->assets)
                                   ? collect($d->assets)->map(fn ($a) => trim(($a['name'] ?? '') . (!empty($a['identifier']) ? ' · ' . $a['identifier'] : '')))->filter()->values()->all()
                                   : $assets->map(fn ($a) => trim(($a->asset_name_snapshot ?? '') . ($a->identifier_snapshot ? ' · ' . $a->identifier_snapshot : '')))->filter()->values()->all(),
                'items'       => $appt ? $appt->items->pluck('item_name_snapshot')->filter()->values()->all() : [],
                'job'         => $appt?->ra_number,
                'notes'       => $d->notes ?: $appt?->staff_notes,
            ];
        }

        $cfg   = (array) (($tenant->settings['work_order_tag'] ?? []));
        $print = \App\Services\PrintIdentityService::forTenant($tenant); // MARKER-PATCH-332
        $embed     = $request->boolean('embed');
        $dateLabel = $start->format('D M j, Y');

        return view('tenant.deliveries.slips', compact('tenant', 'slips', 'print', 'embed', 'dateLabel'));
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        // MARKER-PATCH-156 — gate behind feature toggle
        abort_unless($tenant->deliveries_enabled, 404);
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
            // MARKER-PATCH-153 — customer list now loads on demand via customer-search component
        ];

        if ($view === 'week') {
            $payload['days'] = $svc->forWeek($date);
        } else {
            $payload['deliveries'] = $svc->forDay($date);
        }

        // MARKER-PATCH-514 — map deliveries to route windows for the chip.
        $windowChips = [];
        $rw = \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $tenant->id)->active()->get();
        if ($rw->isNotEmpty()) {
            foreach ($deliveries as $dv) {
                $local = tlocal_carbon($dv->scheduled_at);
                if (! $local) continue;
                foreach ($rw as $w) {
                    if (! $w->runsOn($local)) continue;
                    $t = $local->format('H:i:s');
                    if ($t >= (string) $w->starts_at && $t < (string) $w->ends_at) {
                        $windowChips[$dv->id] = $w->label . ' · ' . $w->bookedStops($local) . '/' . $w->max_stops;
                        break;
                    }
                }
            }
        }
        $payload['windowChips'] = $windowChips;

        return view('tenant.deliveries.index', $payload);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
        $data = $this->validateInput($request);

        $start = CarbonImmutable::parse($data['scheduled_at'], $tenant->timezone ?? 'UTC')->utc(); // MARKER-PATCH-158 — explicit UTC conversion; Eloquent datetime cast does not convert.

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
            'assets'                => $this->snapshotAssets($data), // MARKER-PATCH-427 (create)
        ]);

        // MARKER-PATCH-157 — notification is now opt-in per click.
        // "Save & Notify" sends; plain "Save" doesn't.
        $notify = (bool) $request->input('notify', false);
        $flash  = ucfirst($data['type']) . ' scheduled.';
        if ($notify) {
            \App\Services\Tenant\TenantDeliveryNotificationService::forTenant($tenant)
                ->sendScheduled($delivery);
            $flash .= ' Customer notified.';
        }

        return back()->with('success', $flash);
    }

    /**
     * MARKER-PATCH-515 — schedule the return (dropoff) leg for a completed
     * appointment, against route-window capacity. Value format: "windowId|Y-m-d".
     */
    public function scheduleReturn(Request $request, string $appointmentId): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->findOrFail($appointmentId);

        $data = $request->validate(['window_slot' => ['required', 'string', 'max:60']]);
        [$windowId, $dateStr] = array_pad(explode('|', $data['window_slot'], 2), 2, null);

        $window = \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $tenant->id)
            ->where('is_active', true)->find((string) $windowId);
        $date = $dateStr ? \Carbon\Carbon::parse($dateStr) : null;
        if (! $window || ! $date || ! $window->runsOn($date)) {
            return back()->with('error', 'That delivery window is not available.');
        }

        $exists = TenantDelivery::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)
            ->where('type', 'dropoff')->where('status', '!=', 'cancelled')->exists();
        if ($exists) {
            return back()->with('error', 'A delivery is already scheduled for this appointment.');
        }

        $pickup  = TenantDelivery::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)->where('type', 'pickup')->first();
        $lockKey = 'pdwin:' . $tenant->id . ':' . $window->id . ':' . $date->toDateString();

        try {
            $delivery = app(\App\Support\MySQLLock::class)->withLock($lockKey, function () use ($window, $date, $tenant, $appointment, $pickup) {
                if ($window->remainingStops($date) < 1) {
                    throw new \RuntimeException('That window just filled — pick another.');
                }
                return TenantDelivery::create([
                    'tenant_id'      => $tenant->id,
                    'type'           => 'dropoff',
                    'status'         => TenantDelivery::STATUS_SCHEDULED,
                    'scheduled_at'   => \Carbon\CarbonImmutable::parse($date->toDateString() . ' ' . (string) $window->starts_at, $tenant->timezone ?? 'UTC')->utc(),
                    'window_minutes' => max(15, \Carbon\Carbon::parse((string) $window->starts_at)->diffInMinutes(\Carbon\Carbon::parse((string) $window->ends_at))),
                    'customer_id'    => $appointment->customer_id,
                    'appointment_id' => $appointment->id,
                    'address'        => $pickup?->address,
                    'notes'          => 'Return — ' . $window->label,
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $flash = 'Delivery scheduled for ' . tlocal_datetime($delivery->scheduled_at, 'D M j · g:i A') . '.';
        if ($request->boolean('notify', true)) {
            \App\Services\Tenant\TenantDeliveryNotificationService::forTenant($tenant)->sendScheduled($delivery);
            $flash .= ' Customer notified.';
        }

        return back()->with('success', $flash);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
        $delivery = TenantDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $this->validateInput($request);
        $start = CarbonImmutable::parse($data['scheduled_at'], $tenant->timezone ?? 'UTC')->utc(); // MARKER-PATCH-158 — explicit UTC conversion; Eloquent datetime cast does not convert.

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
            'assets'                => $this->snapshotAssets($data), // MARKER-PATCH-427 (update)
        ]);

        // MARKER-PATCH-157 — opt-in notify on update.
        // "Update & Notify" re-sends scheduled-notification with latest details.
        $notify = (bool) $request->input('notify', false);
        $flash  = 'Delivery updated.';
        if ($notify) {
            \App\Services\Tenant\TenantDeliveryNotificationService::forTenant($tenant)
                ->sendScheduled($delivery);
            $flash .= ' Customer notified.';
        }

        return back()->with('success', $flash);
    }

    public function complete(string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
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
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
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
            'assets'                => ['nullable', 'array'], // MARKER-PATCH-427
            'assets.*'              => ['uuid'],
            'notify'                => ['nullable'], // MARKER-PATCH-157 — checkbox or 0/1
        ]);
    }
}