<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentNote;
use App\Models\Tenant\TenantAppointmentCharge;
use App\Models\Tenant\TenantAppointmentPart;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\AppointmentInventoryService;
use App\Services\Tenant\AppointmentRegisterBridgeService;
use App\Services\Tenant\InventoryStockException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function __construct(
        protected AppointmentInventoryService $appointmentInventory,
        protected AppointmentRegisterBridgeService $registerBridge,
    ) {}

    // Active statuses can move freely between each other (forward or backward).
    // Terminal statuses (cancelled/refunded) can only be reopened to pending.
    // The UI is responsible for confirming destructive or backward moves;
    // this controller only enforces "is the target status valid at all?"
    private const ACTIVE_STATUSES   = ['pending', 'confirmed', 'in_progress', 'completed', 'shipped', 'closed'];
    private const TERMINAL_STATUSES = ['cancelled', 'refunded'];

    private const TRANSITIONS = [
        'pending'     => ['confirmed', 'in_progress', 'completed', 'shipped', 'closed', 'cancelled', 'refunded'],
        'confirmed'   => ['pending', 'in_progress', 'completed', 'shipped', 'closed', 'cancelled', 'refunded'],
        'in_progress' => ['pending', 'confirmed', 'completed', 'shipped', 'closed', 'cancelled', 'refunded'],
        'completed'   => ['pending', 'confirmed', 'in_progress', 'shipped', 'closed', 'cancelled', 'refunded'],
        'shipped'     => ['pending', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled', 'refunded'],
        'closed'      => ['pending', 'confirmed', 'in_progress', 'completed', 'shipped', 'cancelled', 'refunded'],
        'cancelled'   => ['pending'],
        'refunded'    => ['pending'],
    ];

    private const TRANSITION_LABELS = [
        'confirmed'   => 'Confirm',
        'in_progress' => 'Start Work',
        'completed'   => 'Mark Completed',
        'shipped'     => 'Mark Shipped',
        'closed'      => 'Close Job',
        'cancelled'   => 'Cancel',
        'refunded'    => 'Refund',
    ];

    private const DESTRUCTIVE = ['cancelled', 'refunded'];

    public function index(Request $request)
    {
        $tenant = tenant();

        if ($request->has('detail') && ($request->expectsJson() || $request->ajax())) {
            return $this->jsonDetail($tenant, $request->input('detail'));
        }

        $search   = $request->input('s', '');
        $status   = $request->input('status', '');
        $payment  = $request->input('payment', '');
        $dateFrom = $request->input('date_from', '');
        $dateTo   = $request->input('date_to', '');
        $filter      = $request->input('filter', '');
        $resourceId  = $request->input('resource_id', ''); // MARKER-PATCH-113
        $sort        = $request->input('sort', 'date_desc');
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 25;

        // Mapping from dashboard "Needs your attention" card slugs to query scopes.
        // Keep in sync with DashboardDataService::zoneAttention(). Each slug here
        // mirrors a card on the dashboard so clicking the card lands here filtered.
        $filterLabels = [
            'unconfirmed_bookings' => 'Unconfirmed bookings',
            'unpaid_completed'     => 'Unpaid completed jobs',
            'ready_pickup'         => 'Ready for pickup',
            'overdue_unstarted'    => 'Overdue: not started',
            'overdue_in_progress'  => 'Overdue: in progress',
            'stale_pickups'        => 'Stale pickups',
        ];
        $filter = array_key_exists($filter, $filterLabels) ? $filter : '';

        $q = TenantAppointment::where('tenant_id', $tenant->id);

        // Apply the high-level filter slug from the dashboard cards.
        $today = now($tenant->timezone())->toDateString();
        switch ($filter) {
            case 'unconfirmed_bookings':
                $q->where('status', 'pending')
                  ->whereDate('appointment_date', '>=', $today);
                break;
            case 'unpaid_completed':
                $q->whereIn('status', ['completed', 'shipped', 'closed'])
                  ->whereIn('payment_status', ['unpaid', 'partial']);
                break;
            case 'ready_pickup':
                $q->where('status', 'completed');
                break;
            case 'overdue_unstarted':
                $q->whereIn('status', ['pending', 'confirmed'])
                  ->whereDate('appointment_date', '<', $today);
                break;
            case 'overdue_in_progress':
                $q->where('status', 'in_progress')
                  ->whereDate('appointment_date', '<', $today);
                break;
            case 'stale_pickups':
                $q->where('status', 'completed')
                  ->where('updated_at', '<', now()->subDays(3));
                break;
        }

        if ($search) {
            $q->where(function ($q2) use ($search) {
                $q2->where('ra_number', 'like', "%{$search}%")
                   ->orWhere('customer_first_name', 'like', "%{$search}%")
                   ->orWhere('customer_last_name', 'like', "%{$search}%")
                   ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }
        if ($status)     $q->where('status', $status);
        if ($payment)    $q->where('payment_status', $payment);
        if ($dateFrom)   $q->where('appointment_date', '>=', $dateFrom);
        if ($dateTo)     $q->where('appointment_date', '<=', $dateTo);
        if ($resourceId) $q->where('resource_id', $resourceId);

        // Sort
        switch ($sort) {
            case 'date_asc':
                $q->orderBy('appointment_date')->orderBy('created_at');
                break;
            case 'name_asc':
                $q->orderBy('customer_last_name')->orderBy('customer_first_name');
                break;
            case 'name_desc':
                $q->orderByDesc('customer_last_name')->orderByDesc('customer_first_name');
                break;
            case 'status':
                $q->orderByRaw("FIELD(status,'pending','confirmed','in_progress','completed','shipped','closed','cancelled','refunded')")->orderByDesc('appointment_date');
                break;
            case 'total_desc':
                $q->orderByDesc('total_cents')->orderByDesc('appointment_date');
                break;
            case 'total_asc':
                $q->orderBy('total_cents')->orderByDesc('appointment_date');
                break;
            default: // date_desc
                $q->orderByDesc('appointment_date')->orderByDesc('created_at');
                break;
        }

        $total = $q->count();
        // Resolve the active resource filter for display (name + color in chip).
        $resourceFilter = null;
        if ($resourceId) {
            $resourceFilter = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $resourceId)
                ->first(['id', 'name', 'color_hex']);
        }

        $appointments = $q->with('resource:id,name,color_hex')
                          ->offset(($page - 1) * $perPage)
                          ->limit($perPage)
                          ->get();

        $totalPages = max(1, ceil($total / $perPage));

        // Active resources for the inline-edit dropdown on the table.
        // Cheap query; tenants typically have <20 active resources.
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'color_hex']);

        return view('tenant.appointments.index', compact(
            'appointments', 'total', 'page', 'totalPages',
            'search', 'status', 'payment', 'dateFrom', 'dateTo', 'sort',
            'filter', 'filterLabels', 'resources', 'resourceFilter'
        ));
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('update')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

        $data = $request->validate([
            'customer_id'         => ['nullable', 'string', 'uuid'],
            'customer_first_name' => ['required_without:customer_id', 'string', 'max:100'],
            'customer_last_name'  => ['required_without:customer_id', 'string', 'max:100'],
            'customer_email'      => ['required_without:customer_id', 'email', 'max:255'],
            'customer_phone'      => ['nullable', 'string', 'max:32'],
            'appointment_date'    => ['required', 'date'],
            'appointment_time'    => ['nullable', 'string'],
            'resource_id'         => ['nullable', 'string', 'uuid'],
            'staff_notes'         => ['nullable', 'string', 'max:1000'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.service_item_id'      => ['required', 'string', 'uuid'],
            'items.*.price_override_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        // If customer_id provided, hydrate name/email/phone from the existing record.
        $first = $data['customer_first_name'] ?? '';
        $last  = $data['customer_last_name']  ?? '';
        $email = $data['customer_email']      ?? '';
        $phone = $data['customer_phone']      ?? null;

        if (!empty($data['customer_id'])) {
            $existing = TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $data['customer_id'])
                ->first();
            if ($existing) {
                $first = $existing->first_name ?: $first;
                $last  = $existing->last_name  ?: $last;
                $email = $existing->email      ?: $email;
                $phone = $existing->phone      ?: $phone;
            }
        }

        if (!$email) {
            return response()->json(['ok' => false, 'errors' => ['customer_email' => ['Email is required.']]], 422);
        }

        // Time defaults to noon if not provided (date-only flow).
        $apptTime = !empty($data['appointment_time'])
            ? (strlen($data['appointment_time']) === 5 ? $data['appointment_time'] . ':00' : $data['appointment_time'])
            : '12:00:00';

        $payload = [
            'first_name'       => $first,
            'last_name'        => $last,
            'email'            => $email,
            'phone'            => $phone,
            'date'             => $data['appointment_date'],
            'appointment_time' => $apptTime,
            'resource_id'      => $data['resource_id'] ?? null,
            'items'            => array_map(function ($item) {
                return [
                    'service_item_id'      => $item['service_item_id'],
                    'price_override_cents' => $item['price_override_cents'] ?? null,
                    'addon_ids'            => [],
                ];
            }, $data['items']),
            'payment_method'   => 'none',
        ];

        try {
            $appointment = app(\App\Services\BookingService::class)
                ->createAppointment($payload, $tenant->id);
        } catch (\App\Exceptions\LockAcquisitionException $e) {
            return response()->json([
                'ok'      => false,
                'code'    => 'lock_timeout',
                'message' => 'Could not hold the slot. Try again.',
            ], 409);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Persist staff notes via TenantAppointmentNote — same as old flow.
        if (!empty($data['staff_notes'])) {
            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'manual',
                'is_customer_visible' => false,
                'note_content'        => $data['staff_notes'],
                'created_at'          => now(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'       => true,
                'id'       => $appointment->id,
                'ra'       => $appointment->ra_number,
                'redirect' => route('tenant.appointments.show', [
                    'id'        => $appointment->id,
                ]),
            ]);
        }

        return redirect()->route('tenant.appointments.show', [
            'id'        => $appointment->id,
        ])->with('success', 'Appointment created.');
    }

    /**
     * JSON endpoint that powers the create-appointment modal.
     *
     * Modes:
     *   - Default: services + customers + resources (full picker setup)
     *   - With service_ids[]: ALSO returns next-available + per-resource
     *     alternatives via BookingService availability methods
     */
    public function pickerData(Request $request)
    {
        $tenant = tenant();
        $search = trim((string) $request->query('q', ''));

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
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
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(15)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        $availability = null;
        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));

        if (!empty($serviceIds)) {
            $picked = $services->whereIn('id', $serviceIds);
            $required = 0;
            foreach ($picked as $svc) {
                $required += (int) ($svc->prep_before_minutes ?? 0)
                           + (int) ($svc->duration_minutes ?? 0)
                           + (int) ($svc->cleanup_after_minutes ?? 0);
            }

            if ($required > 0) {
                $bookingService = app(\App\Services\BookingService::class);
                $earliest = $bookingService->nextAvailableSlot($tenant, $required, null);
                $perResource = $bookingService->nextAvailablePerResource($tenant, $required);

                $availability = [
                    'required_minutes' => $required,
                    'earliest'         => $earliest,
                    'per_resource'     => $perResource,
                ];
            }
        }

        return response()->json([
            'services'     => $services,
            'customers'    => $customers,
            'resources'    => $resources,
            'availability' => $availability,
        ]);
    }

    /**
     * SEQUENTIAL-PICKER-ENDPOINTS v1
     *
     * Returns the active resources eligible to perform a given service.
     * If the service has no eligibility rows, all active resources qualify.
     * Used by the rebuilt big-modal sequential picker (service → resource → times).
     */
    public function eligibleResources(Request $request)
    {
        $tenant = tenant();
        $serviceId = (string) $request->query('service_id', '');
        if ($serviceId === '') {
            return response()->json(['resources' => []]);
        }

        $bookingService = app(\App\Services\BookingService::class);
        $eligibleIds = $bookingService->eligibleResourcesForService($tenant->id, $serviceId);

        if (empty($eligibleIds)) {
            return response()->json(['resources' => []]);
        }

        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->whereIn('id', $eligibleIds)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        return response()->json(['resources' => $resources]);
    }

    /**
     * Returns up to 7 days of available time slots starting from start_date.
     * Each result is a flat list across the week. The frontend paginates by
     * advancing/retreating start_date by 7 days on prev/next clicks.
     *
     * Required query params:
     *   service_id    — single service UUID (single-service modal at launch)
     *   resource_id   — single resource UUID (selected by user)
     *   start_date    — YYYY-MM-DD; results begin on this date
     *
     * Response:
     *   {
     *     slots: [{date: "YYYY-MM-DD", time: "HH:MM", date_label: "...", time_label: "..."}],
     *     required_minutes: int,
     *     start_date: "YYYY-MM-DD",
     *     end_date: "YYYY-MM-DD"
     *   }
     */
    public function weekTimes(Request $request)
    {
        $tenant = tenant();
        $serviceId  = (string) $request->query('service_id', '');
        $resourceId = (string) $request->query('resource_id', '');
        $startDate  = (string) $request->query('start_date', now()->toDateString());

        if ($serviceId === '' || $resourceId === '') {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $svc = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('id', $serviceId)
            ->first(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        if (!$svc) {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $required = (int) ($svc->prep_before_minutes ?? 0)
                  + (int) ($svc->duration_minutes ?? 0)
                  + (int) ($svc->cleanup_after_minutes ?? 0);

        if ($required === 0) {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $bookingService = app(\App\Services\BookingService::class);

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $cutoff = now()->addHours($minNoticeHours);

        $slots = [];
        $cursor = \Carbon\Carbon::parse($startDate);
        $endDate = $cursor->copy()->addDays(6);

        for ($i = 0; $i < 7; $i++) {
            $dateStr = $cursor->toDateString();
            $times = $bookingService->availableSlotsForDate($tenant, $dateStr, $resourceId, $required);

            // For today, drop any slots earlier than the min-notice cutoff.
            if ($cursor->isToday() && $minNoticeHours > 0) {
                $cutoffHi = $cutoff->format('H:i');
                $times = array_values(array_filter($times, fn($t) => $t >= $cutoffHi));
            }
            // Past dates: skip entirely.
            if ($cursor->isPast() && !$cursor->isToday()) {
                $cursor->addDay();
                continue;
            }

            $dateLabel = $cursor->format('D, M j');
            foreach ($times as $t) {
                $slots[] = [
                    'date'       => $dateStr,
                    'time'       => $t,
                    'date_label' => $dateLabel,
                    'time_label' => self::formatTimeLabel($t),
                ];
            }
            $cursor->addDay();
        }

        return response()->json([
            'slots'            => $slots,
            'required_minutes' => $required,
            'start_date'       => $startDate,
            'end_date'         => $endDate->toDateString(),
        ]);
    }

    private static function formatTimeLabel(string $hi): string
    {
        // "14:30" → "2:30 PM"
        $parts = explode(':', $hi);
        if (count($parts) < 2) return $hi;
        $h = (int) $parts[0];
        $m = $parts[1];
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        $minPart = $m === '00' ? '' : ':' . $m;
        return $h12 . $minPart . ' ' . $ampm;
    }

    public function dayStrip(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));

        if (empty($serviceIds)) {
            return response()->json(['days' => [], 'required_minutes' => 0]);
        }

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['days' => [], 'required_minutes' => 0]);
        }

        $startDate = (string) $request->query('start_date', now()->toDateString());
        $days      = max(1, min(14, (int) $request->query('days', 7)));
        $resourceId = $request->query('resource_id') ?: null;

        $bookingService = app(\App\Services\BookingService::class);
        $dayData = $bookingService->dayCounts($tenant, $required, $startDate, $days, $resourceId);

        return response()->json([
            'days'             => $dayData,
            'required_minutes' => $required,
        ]);
    }

    public function dayTimes(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));
        $date = (string) $request->query('date', '');

        if (empty($serviceIds) || $date === '') {
            return response()->json(['times' => [], 'required_minutes' => 0]);
        }

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['times' => [], 'required_minutes' => 0]);
        }

        $resourceId = $request->query('resource_id') ?: null;

        $bookingService = app(\App\Services\BookingService::class);
        $times = $bookingService->availableSlotsForDate($tenant, $date, $resourceId, $required);

        if (\Carbon\Carbon::parse($date)->isToday()) {
            $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
            $cutoff = now()->addHours($minNoticeHours)->format('H:i');
            $times = array_values(array_filter($times, fn($t) => $t >= $cutoff));
        }

        return response()->json([
            'times'            => $times,
            'required_minutes' => $required,
        ]);
    }

    public function resolveResource(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));
        $date = (string) $request->query('date', '');
        $time = (string) $request->query('time', '');

        if (empty($serviceIds) || $date === '' || $time === '') {
            return response()->json(['resource_id' => null]);
        }

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['resource_id' => null]);
        }

        $bookingService = app(\App\Services\BookingService::class);

        // If a specific resource is requested, verify it's free at that time.
        // Otherwise auto-resolve the first available active resource.
        $requestedResourceId = $request->query('resource_id') ?: null;
        if ($requestedResourceId) {
            $slots = $bookingService->availableSlotsForDate($tenant, $date, $requestedResourceId, $required);
            $resourceId = in_array($time, $slots, true) ? $requestedResourceId : null;
        } else {
            $resourceId = $bookingService->resolveResourceForSlot($tenant, $date, $time, $required);
        }

        return response()->json(['resource_id' => $resourceId]);
    }


    public function show(Request $request, string $id)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonDetail(tenant(), $id);
        }

        $tenant = tenant();
        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'addons', 'parts.inventoryItem', 'notes', 'charges', 'customer', 'workOrderResponses', 'workOrderFields', 'payments.registerSale', 'sales'])
            ->firstOrFail();

        $transitions = self::TRANSITIONS[$appointment->status] ?? [];
        $destructive = self::DESTRUCTIVE;

        // Active services + addons for the line-item editor.
        // Loaded once at render; the inline editor shows them in select dropdowns.
        $availableServices = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price_cents', 'duration_minutes']);

        $availableAddons = \App\Models\Tenant\TenantAddon::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price_cents', 'default_duration_minutes']);

        // Active resources for the sidebar resource-change dropdown.
        // Ordered to match the calendar column order.
        $availableResources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'subtitle', 'color_hex']);

        // Special orders for this appointment (added by patch 88, Stage 5)
        $specialOrdersForAppt = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('appointment_id', $id)
            ->with(['vendor', 'item'])
            ->orderBy('status')
            ->orderBy('expected_arrival_date')
            ->get();
        $soVendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // MARKER-PATCH-158-D — branch to multi-asset view when:
        //   1) tenant has the feature enabled, AND
        //   2) this appointment actually has assets attached (back-compat: pre-existing
        //      single-asset appointments render in the legacy view even after the
        //      flag is flipped on).
        if ($tenant->multi_asset_enabled) {
            $appointmentAssets = \App\Models\Tenant\TenantAppointmentAsset::where('tenant_id', $tenant->id)
                ->where('appointment_id', $appointment->id)
                ->with(['customerAsset', 'items.serviceItem', 'addons.addon'])
                ->orderBy('sort_order')
                ->get();

            if ($appointmentAssets->isNotEmpty()) {
                // Loose items/addons = those NOT pinned to any asset (back-compat)
                $looseItems  = $appointment->items->whereNull('appointment_asset_id');
                $looseAddons = $appointment->addons->whereNull('appointment_asset_id');

                // MARKER-PATCH-158-E1 — picker data: customer's saved assets
                // that aren't already attached to this appointment.
                $attachedAssetIds = $appointmentAssets->pluck('customer_asset_id')->filter()->values()->all();
                $pickerAssets = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                    ->where('customer_id', $appointment->customer_id)
                    ->whereNull('archived_at')
                    ->whereNotIn('id', $attachedAssetIds)
                    ->orderBy('name')
                    ->get();

                return view('tenant.appointments.show-multi-asset', compact(
                    'appointment', 'appointmentAssets', 'looseItems', 'looseAddons',
                    'pickerAssets',
                    'transitions', 'destructive',
                    'availableServices', 'availableAddons', 'availableResources',
                    'specialOrdersForAppt', 'soVendors'));
            }
        }

        return view('tenant.appointments.show', compact(
            'appointment', 'transitions', 'destructive',
            'availableServices', 'availableAddons', 'availableResources', 'specialOrdersForAppt', 'soVendors'));
    }

    public function update(Request $request, string $id)
    {
        return $this->handleUpdate(tenant(), $id, $request);
    }

    public function drawer(Request $request, string $id)
    {
        $tenant = tenant();
        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'addons', 'workOrderResponses'])
            ->firstOrFail();

        $identifierField = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $tenant->id)
            ->where('is_identifier', true)
            ->first();

        $identifierValue = null;
        if ($identifierField) {
            $resp = $appointment->workOrderResponses->firstWhere('field_id', $identifierField->id);
            $identifierValue = $resp?->response_value;
        }

        return response()->json([
            'ok' => true,
            'appointment' => [
                'id'                    => $appointment->id,
                'ra_number'             => $appointment->ra_number,
                'status'                => $appointment->status,
                'status_label'          => ucwords(str_replace('_', ' ', $appointment->status)),
                'payment_status'        => $appointment->payment_status,
                'payment_status_label'  => ucfirst($appointment->payment_status),
                'customer_name'         => trim(($appointment->customer_first_name ?? '') . ' ' . ($appointment->customer_last_name ?? '')),
                'customer_email'        => $appointment->customer_email,
                'customer_phone'        => $appointment->customer_phone,
                'appointment_date'      => $appointment->appointment_date?->format('Y-m-d'),
                'appointment_date_long' => $appointment->appointment_date?->format('l, F j, Y'),
                'appointment_time'      => $appointment->appointment_time,
                'total_cents'           => (int) $appointment->total_cents,
                'total_formatted'       => format_money($appointment->total_cents),
                'paid_cents'            => (int) $appointment->paid_cents,
                'duration_minutes'      => (int) ($appointment->total_duration_minutes ?? 0),
                'identifier_label'      => $identifierField?->label,
                'identifier_value'      => $identifierValue,
                'items'                 => $appointment->items->map(fn($i) => [
                    'name'     => $i->item_name_snapshot,
                    'price'    => format_money($i->price_cents),
                    'duration' => (int) ($i->duration_minutes_snapshot ?? 0),
                ])->values()->toArray(),
                'addons'                => $appointment->addons->map(fn($a) => [
                    'name'  => $a->addon_name_snapshot,
                    'price' => format_money($a->price_cents),
                ])->values()->toArray(),
                'full_url'              => route('tenant.appointments.show', ['id' => $appointment->id]),
            ],
        ]);
    }

        private function jsonDetail($tenant, string $id)
    {
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with([
                'items', 'addons', 'notes', 'charges', 'customer',
                'responses', 'workOrderResponses.field', 'resource',
            ])
            ->firstOrFail();

        $transitions = self::TRANSITIONS[$appointment->status] ?? [];

        return response()->json([
            'ok' => true,
            'appointment' => [
                'id' => $appointment->id, 'ra_number' => $appointment->ra_number,
                'status' => $appointment->status, 'status_label' => ucwords(str_replace('_', ' ', $appointment->status)),
                'payment_status' => $appointment->payment_status, 'payment_label' => ucfirst($appointment->payment_status),
                'customer_name' => $appointment->customerName(), 'customer_email' => $appointment->customer_email,
                'customer_phone' => $appointment->customer_phone, 'customer_id' => $appointment->customer_id,
                'appointment_date' => $appointment->appointment_date->format('M j, Y'),
                'appointment_date_raw' => $appointment->appointment_date->format('Y-m-d'),
                'staff_notes' => $appointment->staff_notes,
                'subtotal_cents' => $appointment->subtotal_cents, 'tax_cents' => $appointment->tax_cents,
                'total_cents' => $appointment->total_cents, 'paid_cents' => $appointment->paid_cents,
                'total_display' => format_money($appointment->total_cents),
                'paid_display' => format_money($appointment->paid_cents),
                'subtotal_display' => format_money($appointment->subtotal_cents),
                'created_at' => $appointment->created_at->format('M j, Y g:i a'),
                'slot_weight' => $appointment->slot_weight ?? 1,
                'resource' => $appointment->resource ? [
                    'id' => $appointment->resource->id,
                    'name' => $appointment->resource->name,
                    'subtitle' => $appointment->resource->subtitle,
                    'color_hex' => $appointment->resource->color_hex,
                ] : null,
                'appointment_time' => $appointment->appointment_time
                    ? \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i a')
                    : null,
                'appointment_end_time' => $appointment->appointment_end_time
                    ? \Carbon\Carbon::parse($appointment->appointment_end_time)->format('g:i a')
                    : null,
                'total_duration_minutes' => $appointment->total_duration_minutes,
                'items' => $appointment->items->map(fn($i) => [
                    'name' => $i->item_name_snapshot,
                    'duration' => $i->duration_minutes_snapshot,
                    'prep_min' => (int) ($i->prep_before_minutes_snapshot ?? 0),
                    'cleanup_min' => (int) ($i->cleanup_after_minutes_snapshot ?? 0),
                    'price' => format_money($i->price_cents),
                ]),
                'addons' => $appointment->addons->map(fn($a) => ['name' => $a->addon_name_snapshot, 'price' => format_money($a->price_cents)]),
                'charges' => $appointment->charges->map(fn($c) => ['id' => $c->id, 'description' => $c->description, 'amount' => format_money($c->amount_cents), 'is_paid' => $c->is_paid, 'date' => \Carbon\Carbon::parse($c->created_at)->format('M j')]),
                'notes' => $appointment->notes->sortByDesc('created_at')->values()->map(fn($n) => ['id' => $n->id, 'note' => $n->note_content, 'author' => $n->user?->name ?? ($n->note_type === 'system' ? 'System' : 'Staff'), 'type' => $n->note_type, 'created_at' => \Carbon\Carbon::parse($n->created_at)->format('M j, g:i a')]),
                'work_order_responses' => $appointment->workOrderResponses
                    ->filter(fn($r) => $r->field !== null)
                    ->map(fn($r) => [
                        'field_label' => $r->field->label,
                        'field_type' => $r->field->field_type,
                        'is_identifier' => (bool) $r->field->is_identifier,
                        'value' => $r->response_value,
                    ])
                    ->values(),
                'form_responses' => $appointment->responses->map(fn($r) => [
                    'field_label' => $r->field_label_snapshot,
                    'value' => $r->response_value,
                ]),
            ],
            'transitions' => collect($transitions)->map(fn($t) => ['status' => $t, 'label' => self::TRANSITION_LABELS[$t] ?? ucfirst($t), 'destructive' => in_array($t, self::DESTRUCTIVE)])->values(),
        ]);
    }

    private function handleUpdate($tenant, string $id, Request $request)
    {
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $op = $request->input('op');

        if ($op === 'status') {
            $newStatus = $request->input('status');
            $allowed = self::TRANSITIONS[$appointment->status] ?? [];
            if (!in_array($newStatus, $allowed, true)) return response()->json(['ok' => false, 'message' => 'Invalid status transition.'], 422);

            $oldStatus = $appointment->status;
            $wasCommitted = AppointmentInventoryService::isCommittedStatus($oldStatus);
            $willCommit   = AppointmentInventoryService::isCommittedStatus($newStatus);

            // Inventory transitions:
            //   not-committed → committed       : commit (decrement)
            //   committed     → not-committed   : revert (increment)
            //   committed     → committed (e.g. completed → shipped) : no-op
            //   not-committed → not-committed   : no-op
            //
            // We commit BEFORE writing the status so a stock failure doesn't
            // leave the appointment in a "completed but inventory not deducted"
            // state. Revert happens AFTER status change since it can't fail
            // for stock reasons (incrementing always succeeds).
            if (!$wasCommitted && $willCommit) {
                try {
                    $this->appointmentInventory->commitParts($appointment);
                } catch (InventoryStockException $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Cannot complete: ' . $e->getMessage(),
                    ], 422);
                }
            }

            $appointment->update(['status' => $newStatus]);

            // Register bridge:
            //   entering committed status → create draft sale (or mark paid / overage)
            //   leaving committed status  → void any open draft sale
            $bridgeResult = null;
            if (!$wasCommitted && $willCommit) {
                try {
                    $bridgeResult = $this->registerBridge->onAppointmentEnteringCommittedStatus($appointment);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Register bridge enter failed', [
                        'appointment_id' => $appointment->id,
                        'error'          => $e->getMessage(),
                    ]);
                    // Don't fail the status change — it's already written and inventory committed.
                    // The bridge can be retried via a "regenerate sale" action later.
                }
            } elseif ($wasCommitted && !$willCommit) {
                try {
                    $this->registerBridge->onAppointmentLeavingCommittedStatus($appointment);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Register bridge leave failed', [
                        'appointment_id' => $appointment->id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }

            if ($wasCommitted && !$willCommit) {
                $this->appointmentInventory->revertParts($appointment);
            }

            if ($newStatus === 'cancelled' && $appointment->appointment_date) {
                try {
                    $firstItem = $appointment->items()->first();
                    if ($firstItem && $firstItem->service_item_id) {
                        \App\Jobs\ProcessWaitlistOpeningJob::dispatch(
                            $appointment->tenant_id,
                            $appointment->appointment_date->toDateTimeString(),
                            $firstItem->service_item_id,
                            'cancellation',
                            $appointment->id
                        )->afterCommit();
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Waitlist dispatch failed', [
                        'appointment_id' => $appointment->id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }
            TenantAppointmentNote::create(['appointment_id' => $appointment->id, 'user_id' => Auth::guard('tenant')->id(), 'note_type' => 'system', 'is_customer_visible' => false, 'note_content' => 'Status changed to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.', 'created_at' => now()]);
            return response()->json([
                'ok'             => true,
                'status'         => $newStatus,
                'label'          => ucwords(str_replace('_', ' ', $newStatus)),
                'register_bridge'=> $bridgeResult,
                'reload'         => true, // signal client to reload — banner / lock state needs fresh data
            ]);
        }
        if ($op === 'payment') {
            // DEPRECATED: this op used to let staff manually flip
            // payment_status. The ledger is now the source of truth — staff
            // record actual payments via the register, and payment_status
            // is computed from ledger sums. We keep this for backward-compat
            // but only allow flipping to/from 'unpaid' (clearing) since any
            // other state must reflect real ledger entries.
            $newPayment = $request->input('payment_status');
            if (!in_array($newPayment, ['unpaid'], true)) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Payment status is computed from the payment ledger. Record a payment via the register instead.',
                ], 422);
            }
            $appointment->update(['payment_status' => 'unpaid']);
            return response()->json(['ok' => true, 'payment_status' => 'unpaid']);
        }

        if ($op === 'record_deposit') {
            // Routes a deposit through the register: creates a tiny draft
            // sale with one open_item line for the deposit amount, attached
            // to this appointment. Staff completes the sale in the register
            // to actually take the payment. On sale-close, the bridge
            // writes a payment row.
            //
            // Validation: amount must be positive, balance must allow it
            // (no taking deposits beyond the appointment total).
            $amountCents = (int) $request->input('amount_cents');
            if ($amountCents <= 0) {
                return response()->json(['ok' => false, 'message' => 'Amount must be positive.'], 422);
            }
            $balanceDue = max(0, (int) $appointment->total_cents - (int) $appointment->paid_cents);
            if ($amountCents > $balanceDue && $balanceDue > 0) {
                return response()->json([
                    'ok'      => false,
                    'message' => "Deposit can't exceed remaining balance of " . format_money($balanceDue) . '.',
                ], 422);
            }

            // Build a one-line deposit-collection sale.
            $sale = \DB::transaction(function () use ($appointment, $amountCents, $tenant) {
                $saleNumber = $this->generateDepositSaleNumber($tenant->id);
                $sale = \App\Models\Tenant\TenantSale::create([
                    'id'                  => (string) Str::uuid(),
                    'tenant_id'           => $tenant->id,
                    'sale_number'         => $saleNumber,
                    'sale_date'           => now()->toDateString(),
                    'status'              => 'pending',
                    'payment_status'      => 'draft',
                    'customer_id'         => $appointment->customer_id,
                    'appointment_id'      => $appointment->id,
                    'rang_up_by_user_id'  => Auth::guard('tenant')->id(),
                    'subtotal_cents'      => $amountCents,
                    'tax_cents'           => 0,
                    'total_cents'         => $amountCents,
                    'notes'               => 'Deposit collection for appointment ' . ($appointment->ra_number ?? $appointment->id),
                ]);

                DB::table('tenant_sale_items')->insert([
                    'id'                 => (string) Str::uuid(),
                    'tenant_id'          => $tenant->id,
                    'sale_id'            => $sale->id,
                    'type'               => 'open_item',
                    'name_snapshot'      => 'Deposit toward appointment ' . ($appointment->ra_number ?? ''),
                    'quantity'           => 1,
                    'unit_price_cents'   => $amountCents,
                    'line_total_cents'   => $amountCents,
                    'is_taxable'         => false,
                    'position'           => 0,
                    'notes'              => 'Auto-created deposit line; payment writes to appointment ledger on close.',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                return $sale;
            });

            return response()->json([
                'ok'              => true,
                'sale_id'         => $sale->id,
                'sale_number'     => $sale->sale_number,
                'redirect_url'    => route('tenant.register.index', [
                    ]) . '?resume=' . $sale->id,
                'message'         => 'Deposit sale created. Take payment in the register.',
            ]);
        }

        if ($op === 'void_register_sale') {
            // Staff clicked "Edit (voids draft)". Find the open sale, void
            // it through the bridge (which drops ledger rows + recomputes
            // status). Refunds (paid sale) take a different path.
            $sale = $appointment->openRegisterSale();
            if (!$sale) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'No open register sale to void.',
                ], 422);
            }
            $voided = $this->registerBridge->voidDraftSale($sale, 'manual_edit');
            if (!$voided) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Sale has been paid. Use the refund flow instead of voiding.',
                ], 422);
            }
            return response()->json([
                'ok'      => true,
                'reload'  => true,
                'message' => 'Draft sale voided. Appointment editable again.',
            ]);
        }
        if ($op === 'date') {
            $request->validate(['appointment_date' => ['required', 'date']]);
            $appointment->update(['appointment_date' => $request->input('appointment_date')]);
            return response()->json(['ok' => true]);
        }

        // RESCHEDULE-OP v1 — full reschedule (date + time + resource).
        // Validates availability defensively (slot may have been taken between
        // the picker fetch and this submit). Recomputes appointment_end_time.
        // Records a system note with from/to summary.
        if ($op === 'reschedule') {
            $request->validate([
                'appointment_date' => ['required', 'date'],
                'appointment_time' => ['required', 'string'],
                'resource_id'      => ['required', 'string'],
            ]);

            $newDate     = $request->input('appointment_date');
            $newTime     = $request->input('appointment_time');
            $newResource = $request->input('resource_id');

            // Normalize time to H:i:s for storage. Accepts "14:00" or "14:00:00".
            $newTimeNorm = strlen($newTime) === 5 ? $newTime . ':00' : $newTime;

            // Capture "from" for the system note.
            $fromDate     = $appointment->appointment_date?->format('Y-m-d');
            $fromTime     = $appointment->appointment_time;
            $fromResource = $appointment->resource_id;

            // No-op guard: if nothing actually changed, return early.
            if ($fromDate === $newDate
                && $fromTime === $newTimeNorm
                && $fromResource === $newResource) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            // Resolve the resource — must be a real resource on this tenant.
            $resource = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $newResource)
                ->first();
            if (!$resource) {
                return response()->json(['ok' => false, 'message' => 'Selected resource is not available.'], 422);
            }

            // Defensive availability check: confirm the requested slot is still open.
            // We use the same BookingService method the picker uses, so the answer
            // is consistent with what the user saw.
            $bookingService = app(\App\Services\BookingService::class);
            $required = (int) ($appointment->total_duration_minutes ?? 0);
            if ($required <= 0) {
                return response()->json(['ok' => false, 'message' => 'Appointment has no duration set; cannot reschedule.'], 422);
            }

            $availableTimes = $bookingService->availableSlotsForDate(
                $tenant, $newDate, $newResource, $required
            );

            // availableSlotsForDate returns "H:i" strings. The slot is available
            // unless it overlaps an existing appointment. Special case: if the
            // appointment we're rescheduling is on the SAME date+resource, it
            // already counts itself as busy. We only reject if the new H:i is
            // not in the available list AND the new slot doesn't match the old
            // (which would be a no-op caught above).
            $newTimeShort = substr($newTimeNorm, 0, 5);
            $sameDateResource = ($fromDate === $newDate && $fromResource === $newResource);

            if (!in_array($newTimeShort, $availableTimes, true) && !$sameDateResource) {
                return response()->json([
                    'ok' => false,
                    'message' => 'That time was just taken. Please pick another available slot.',
                ], 409);
            }

            // Compute new end time from new start + duration.
            $newEnd = \Carbon\Carbon::parse($newDate . ' ' . $newTimeNorm)
                ->addMinutes($required)
                ->format('H:i:s');

            $appointment->update([
                'appointment_date'     => $newDate,
                'appointment_time'     => $newTimeNorm,
                'appointment_end_time' => $newEnd,
                'resource_id'          => $newResource,
            ]);

            // Build a human-readable summary for the system note.
            $fmtTime = function ($t) {
                if (!$t) return '—';
                try { return \Carbon\Carbon::parse($t)->format('g:i A'); }
                catch (\Throwable $e) { return $t; }
            };
            $fmtDate = function ($d) {
                if (!$d) return '—';
                try { return \Carbon\Carbon::parse($d)->format('M j, Y'); }
                catch (\Throwable $e) { return $d; }
            };

            $fromResourceName = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $fromResource)->value('name') ?? 'Unassigned';
            $toResourceName   = $resource->name;

            $noteContent = sprintf(
                'Rescheduled from %s · %s · %s to %s · %s · %s.',
                $fmtDate($fromDate), $fmtTime($fromTime), $fromResourceName,
                $fmtDate($newDate),  $fmtTime($newTimeNorm), $toResourceName
            );

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => $noteContent,
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok' => true,
                'appointment' => [
                    'date'        => $newDate,
                    'time'        => $newTimeNorm,
                    'end_time'    => $newEnd,
                    'resource_id' => $newResource,
                ],
                'note' => $noteContent,
            ]);
        }

        if ($op === 'add_charge') {
            $request->validate(['description' => ['required', 'string', 'max:255'], 'amount_cents' => ['required', 'integer', 'min:1']]);
            $charge = TenantAppointmentCharge::create(['appointment_id' => $appointment->id, 'description' => $request->input('description'), 'amount_cents' => (int) $request->input('amount_cents'), 'is_paid' => false, 'created_at' => now()]);
            return response()->json(['ok' => true, 'id' => $charge->id, 'description' => $charge->description, 'amount' => format_money($charge->amount_cents)]);
        }
        if ($op === 'add_note') {
            $note = mb_substr(trim($request->input('note', '')), 0, 500);
            if (!$note) return response()->json(['ok' => false, 'message' => 'Note is required.'], 422);
            $n = TenantAppointmentNote::create(['appointment_id' => $appointment->id, 'user_id' => Auth::guard('tenant')->id(), 'note_type' => 'staff', 'is_customer_visible' => false, 'note_content' => $note, 'created_at' => now()]);
            $user = Auth::guard('tenant')->user();
            return response()->json(['ok' => true, 'id' => $n->id, 'note' => $n->note_content, 'author' => $user->name, 'created_at' => $n->created_at->format('M j, g:i a')]);
        }
        if ($op === 'save_work_order') {
            $values = $request->input('values', []);
            if (!is_array($values)) {
                return response()->json(['ok' => false, 'message' => 'values must be an array.'], 422);
            }

            // Load fields once so we can snapshot labels and detect the identifier
            $fields = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $tenant->id)
                ->whereIn('id', array_keys($values))
                ->get()
                ->keyBy('id');

            $identifierValue = null;
            $identifierLabel = null;

            foreach ($values as $fieldId => $rawValue) {
                $field = $fields->get($fieldId);
                if (!$field) continue;

                $value = is_string($rawValue) ? trim($rawValue) : $rawValue;
                $value = ($value === '' || $value === null) ? null : (string) $value;

                $existing = \App\Models\Tenant\TenantAppointmentWorkOrderResponse::where('tenant_id', $tenant->id)
                    ->where('appointment_id', $appointment->id)
                    ->where('field_id', $field->id)
                    ->first();

                if ($value === null) {
                    if ($existing) { $existing->delete(); }
                } elseif ($existing) {
                    $existing->update([
                        'response_value'       => $value,
                        'field_label_snapshot' => $field->label,
                    ]);
                } else {
                    \App\Models\Tenant\TenantAppointmentWorkOrderResponse::create([
                        'tenant_id'            => $tenant->id,
                        'appointment_id'       => $appointment->id,
                        'field_id'             => $field->id,
                        'field_label_snapshot' => $field->label,
                        'response_value'       => $value,
                    ]);
                }

                if ($field->is_identifier) {
                    $identifierValue = $value;
                    $identifierLabel = $value !== null ? $field->label : null;
                }
            }

            // Update the promoted identifier column if any identifier field was in the payload
            $identifierTouched = $fields->contains(fn($f) => (bool) $f->is_identifier);
            if ($identifierTouched) {
                $appointment->update([
                    'identifier'       => $identifierValue,
                    'identifier_label' => $identifierLabel,
                ]);
            }

            return response()->json(['ok' => true]);
        }
        if ($op === 'delete_note') {
            TenantAppointmentNote::where('appointment_id', $appointment->id)->where('id', $request->input('note_id'))->delete();
            return response()->json(['ok' => true]);
        }
        if ($op === 'add_service') {
            $serviceItemId = $request->input('service_item_id');
            $service = \App\Models\Tenant\TenantServiceItem::where('id', $serviceItemId)
                ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
            if (!$service) return response()->json(['ok' => false, 'message' => 'Service not found.'], 422);

            \App\Models\Tenant\TenantAppointmentItem::create([
                'id'                             => (string) \Illuminate\Support\Str::uuid(),
                'appointment_id'                 => $appointment->id,
                'service_item_id'                => $service->id,
                'item_name_snapshot'             => $service->name,
                'price_cents'                    => $service->price_cents,
                'duration_minutes_snapshot'      => $service->duration_minutes,
                'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
            ]);
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'remove_service') {
            $itemId = $request->input('item_id');
            $item = \App\Models\Tenant\TenantAppointmentItem::where('id', $itemId)
                ->where('appointment_id', $appointment->id)->first();
            if (!$item) return response()->json(['ok' => false, 'message' => 'Item not found.'], 422);
            $item->delete();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'add_addon') {
            $addonId = $request->input('addon_id');
            $addon = \App\Models\Tenant\TenantAddon::where('id', $addonId)
                ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
            if (!$addon) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);

            \App\Models\Tenant\TenantAppointmentAddon::create([
                'id'                        => (string) \Illuminate\Support\Str::uuid(),
                'appointment_id'            => $appointment->id,
                'addon_id'                  => $addon->id,
                'addon_name_snapshot'       => $addon->name,
                'price_cents'               => $addon->price_cents,
                'duration_minutes_snapshot' => $addon->default_duration_minutes ?? 0,
            ]);
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'remove_addon') {
            $addonId = $request->input('addon_id');
            $addon = \App\Models\Tenant\TenantAppointmentAddon::where('id', $addonId)
                ->where('appointment_id', $appointment->id)->first();
            if (!$addon) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);
            $addon->delete();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // -------------------------------------------------------------------
        // MARKER-PATCH-158-E1 — Multi-asset operations
        //
        // These ops only make sense when the tenant has multi_asset_enabled.
        // Guarded explicitly rather than relying on view-side gating, so that
        // a stale/malicious request can't sneak through to a tenant that
        // never opted in.
        //
        // Ops added:
        //   - attach_existing_asset  → pivots an existing customer asset onto this appointment
        //   - attach_new_asset       → creates a new customer asset AND attaches it
        //   - detach_asset           → removes the pivot, unpins services (set FK null)
        //   - add_service_to_asset   → creates an item or addon pinned to a specific asset
        // -------------------------------------------------------------------
        if (in_array($op, ['attach_existing_asset', 'attach_new_asset', 'detach_asset', 'add_service_to_asset'], true)) {
            if (!$tenant->multi_asset_enabled) {
                return response()->json(['ok' => false, 'message' => 'Multi-asset is not enabled for this tenant.'], 403);
            }
        }

        if ($op === 'attach_existing_asset') {
            $request->validate([
                'customer_asset_id' => ['required', 'uuid'],
            ]);
            $asset = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                ->where('customer_id', $appointment->customer_id)
                ->where('id', $request->input('customer_asset_id'))
                ->whereNull('archived_at')
                ->first();
            if (!$asset) return response()->json(['ok' => false, 'message' => 'Asset not found or archived.'], 422);

            // Don't double-attach the same asset
            $alreadyAttached = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('customer_asset_id', $asset->id)
                ->exists();
            if ($alreadyAttached) {
                return response()->json(['ok' => false, 'message' => 'Asset already attached to this appointment.'], 422);
            }

            $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->max('sort_order');

            \App\Models\Tenant\TenantAppointmentAsset::create([
                'tenant_id'           => $tenant->id,
                'appointment_id'      => $appointment->id,
                'customer_asset_id'   => $asset->id,
                'asset_name_snapshot' => $asset->name,
                'identifier_snapshot' => $asset->identifier,
                'sort_order'          => $maxSort + 10,
                'subtotal_cents'      => 0,
            ]);

            // Update last-seen on the persistent asset
            $asset->update([
                'last_seen_at'        => now(),
                'last_appointment_id' => $appointment->id,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($op === 'attach_new_asset') {
            $data = $request->validate([
                'name'       => ['required', 'string', 'max:200'],
                'identifier' => ['nullable', 'string', 'max:120'],
                'notes'      => ['nullable', 'string', 'max:5000'],
            ]);

            // Create the persistent customer asset, then attach
            $asset = \App\Models\Tenant\TenantCustomerAsset::create([
                'tenant_id'           => $tenant->id,
                'customer_id'         => $appointment->customer_id,
                'name'                => $data['name'],
                'identifier'          => $data['identifier'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'last_seen_at'        => now(),
                'last_appointment_id' => $appointment->id,
            ]);

            $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->max('sort_order');

            \App\Models\Tenant\TenantAppointmentAsset::create([
                'tenant_id'           => $tenant->id,
                'appointment_id'      => $appointment->id,
                'customer_asset_id'   => $asset->id,
                'asset_name_snapshot' => $asset->name,
                'identifier_snapshot' => $asset->identifier,
                'sort_order'          => $maxSort + 10,
                'subtotal_cents'      => 0,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($op === 'detach_asset') {
            $request->validate(['appointment_asset_id' => ['required', 'uuid']]);
            $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('id', $request->input('appointment_asset_id'))
                ->first();
            if (!$aa) return response()->json(['ok' => false, 'message' => 'Asset attachment not found.'], 422);

            // Unpin all services pinned to this asset rather than deleting them.
            // nullOnDelete in the DB does this automatically when the row is
            // dropped, but doing it explicitly here makes the intent clearer
            // in code.
            \App\Models\Tenant\TenantAppointmentItem::where('appointment_asset_id', $aa->id)
                ->update(['appointment_asset_id' => null]);
            \App\Models\Tenant\TenantAppointmentAddon::where('appointment_asset_id', $aa->id)
                ->update(['appointment_asset_id' => null]);

            $aa->delete();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'add_service_to_asset') {
            $data = $request->validate([
                'appointment_asset_id' => ['required', 'uuid'],
                'kind'                 => ['required', 'in:service,addon'],
                'service_item_id'      => ['nullable', 'uuid'],
                'addon_id'             => ['nullable', 'uuid'],
            ]);

            $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('id', $data['appointment_asset_id'])
                ->first();
            if (!$aa) return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);

            if ($data['kind'] === 'service') {
                if (empty($data['service_item_id'])) {
                    return response()->json(['ok' => false, 'message' => 'service_item_id required.'], 422);
                }
                $service = \App\Models\Tenant\TenantServiceItem::where('id', $data['service_item_id'])
                    ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
                if (!$service) return response()->json(['ok' => false, 'message' => 'Service not found.'], 422);

                \App\Models\Tenant\TenantAppointmentItem::create([
                    'id'                             => (string) \Illuminate\Support\Str::uuid(),
                    'appointment_id'                 => $appointment->id,
                    'appointment_asset_id'           => $aa->id,
                    'service_item_id'                => $service->id,
                    'item_name_snapshot'             => $service->name,
                    'price_cents'                    => $service->price_cents,
                    'duration_minutes_snapshot'      => $service->duration_minutes,
                    'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                    'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
                ]);
            } else {
                if (empty($data['addon_id'])) {
                    return response()->json(['ok' => false, 'message' => 'addon_id required.'], 422);
                }
                $addon = \App\Models\Tenant\TenantAddon::where('id', $data['addon_id'])
                    ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
                if (!$addon) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);

                \App\Models\Tenant\TenantAppointmentAddon::create([
                    'id'                        => (string) \Illuminate\Support\Str::uuid(),
                    'appointment_id'            => $appointment->id,
                    'appointment_asset_id'      => $aa->id,
                    'addon_id'                  => $addon->id,
                    'addon_name_snapshot'       => $addon->name,
                    'price_cents'               => $addon->price_cents,
                    'duration_minutes_snapshot' => $addon->default_duration_minutes ?? 0,
                ]);
            }

            // Recalc this asset's subtotal + the appointment grand total
            $aa->refreshSubtotal();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // -------------------------------------------------------------------
        // Parts: physical inventory items consumed during the appointment.
        // Snapshot-on-add. Stock is checked at add-time (overcommit possible
        // if stock changes between now and completion — by design, we don't
        // hold a reservation). Stock isn't actually decremented until the
        // appointment transitions to a committed status (see status op).
        // -------------------------------------------------------------------

        if ($op === 'add_part') {
            $request->validate([
                'inventory_item_id' => ['required', 'uuid'],
                'quantity'          => ['nullable', 'integer', 'min:1', 'max:999'],
            ]);

            $invItem = TenantInventoryItem::where('id', $request->input('inventory_item_id'))
                ->where('tenant_id', $tenant->id)
                ->first();
            if (!$invItem) {
                return response()->json(['ok' => false, 'message' => 'Inventory item not found.'], 422);
            }

            $qty = (int) ($request->input('quantity') ?? 1);

            // Add-time stock check — warn if we'd go negative without oversell.
            // We don't hard-block; the caller can set allow_oversell on items
            // that should be reservable beyond stock. But for normal items,
            // surface the issue clearly so the front desk knows to order more.
            $currentStock = (int) ($invItem->computed_stock_count ?? 0);
            if ($qty > $currentStock && !$invItem->allow_oversell) {
                return response()->json([
                    'ok'      => false,
                    'message' => "Only {$currentStock} in stock. Adjust quantity, mark the item as oversellable, or order more.",
                ], 422);
            }

            $part = TenantAppointmentPart::create([
                'appointment_id'     => $appointment->id,
                'inventory_item_id'  => $invItem->id,
                'item_name_snapshot' => $invItem->name,
                'item_sku_snapshot'  => $invItem->sku,
                'quantity'           => $qty,
                'unit_price_cents'   => (int) ($invItem->effectiveSellPriceCents() ?? 0),
                'cost_cents_at_time' => $invItem->effectiveCostCents(),
                'is_taxable'         => true,
                'committed_at'       => null,
            ]);

            // Audit note for the activity log.
            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf(
                    '%s added from inventory (qty %d)',
                    $invItem->name,
                    $qty
                ),
                'created_at'          => now(),
            ]);

            $this->recalcAppointmentTotals($appointment);

            return response()->json([
                'ok'   => true,
                'part' => [
                    'id'                 => $part->id,
                    'name'               => $part->item_name_snapshot,
                    'sku'                => $part->item_sku_snapshot,
                    'quantity'           => $part->quantity,
                    'unit_price_cents'   => $part->unit_price_cents,
                    'unit_price_display' => format_money($part->unit_price_cents),
                    'line_total_cents'   => $part->lineTotalCents(),
                    'line_total_display' => format_money($part->lineTotalCents()),
                    'current_stock'      => $currentStock,
                    'projected_stock'    => $currentStock - $qty,
                ],
            ]);
        }

        // Custom item: name + price, no inventory link, doesn't move stock.
        // Useful for one-off shop charges that aren't worth tracking in the
        // inventory module ("scratched paint touch-up — $15"). Stored as a
        // part row with inventory_item_id=null; the inventory service's
        // existing null-check makes commit/refund a no-op for these rows.
        if ($op === 'add_custom_item') {
            $request->validate([
                'name'             => ['required', 'string', 'max:255'],
                'unit_price_cents' => ['required', 'integer', 'min:0', 'max:99999999'],
                'quantity'         => ['nullable', 'integer', 'min:1', 'max:999'],
                'is_taxable'       => ['nullable', 'boolean'],
            ]);

            $qty = (int) ($request->input('quantity') ?? 1);

            $part = TenantAppointmentPart::create([
                'appointment_id'     => $appointment->id,
                'inventory_item_id'  => null,
                'item_name_snapshot' => trim($request->input('name')),
                'item_sku_snapshot'  => null,
                'quantity'           => $qty,
                'unit_price_cents'   => (int) $request->input('unit_price_cents'),
                'cost_cents_at_time' => null,
                'is_taxable'         => $request->boolean('is_taxable', true),
                'committed_at'       => null,
            ]);

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf(
                    'Custom item added: %s (qty %d)',
                    $part->item_name_snapshot,
                    $qty
                ),
                'created_at'          => now(),
            ]);

            $this->recalcAppointmentTotals($appointment);

            return response()->json([
                'ok'   => true,
                'part' => [
                    'id'                 => $part->id,
                    'name'               => $part->item_name_snapshot,
                    'quantity'           => $part->quantity,
                    'unit_price_cents'   => $part->unit_price_cents,
                    'unit_price_display' => format_money($part->unit_price_cents),
                    'line_total_cents'   => $part->lineTotalCents(),
                    'line_total_display' => format_money($part->lineTotalCents()),
                ],
            ]);
        }

        if ($op === 'remove_part') {
            $partId = $request->input('part_id');
            $part = TenantAppointmentPart::where('id', $partId)
                ->where('appointment_id', $appointment->id)
                ->first();
            if (!$part) {
                return response()->json(['ok' => false, 'message' => 'Item not found.'], 422);
            }

            // If this part has already been committed (decremented from stock),
            // we need to give it back BEFORE deleting the row, otherwise the
            // increment helper has nothing to operate on and committed_at
            // tracking is lost.
            if ($part->isCommitted()) {
                $tenantModel = $appointment->tenant;
                $loc = $tenantModel?->defaultLocation;
                if ($loc) {
                    DB::transaction(function () use ($appointment, $part, $loc) {
                        app(\App\Services\Tenant\InventoryService::class)
                            ->incrementForAppointmentPart($appointment, $part, $loc->id);
                    });
                }
            }

            $name = $part->item_name_snapshot;
            $part->delete();

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf('%s removed', $name),
                'created_at'          => now(),
            ]);

            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'update_part_quantity') {
            $partId = $request->input('part_id');
            $newQty = (int) $request->input('quantity', 0);
            if ($newQty < 1) {
                return response()->json(['ok' => false, 'message' => 'Quantity must be at least 1.'], 422);
            }

            $part = TenantAppointmentPart::where('id', $partId)
                ->where('appointment_id', $appointment->id)
                ->first();
            if (!$part) {
                return response()->json(['ok' => false, 'message' => 'Item not found.'], 422);
            }

            // Prevent quantity edits after the appointment has been completed
            // — but only for inventory-linked parts where stock is actually
            // decremented. Custom items don't move stock, so editing their
            // quantity post-commit is harmless.
            if ($part->isCommitted() && $part->inventory_item_id) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Move appointment back to In Progress to edit committed items.',
                ], 422);
            }

            // Add-time stock re-check on the new quantity.
            if ($part->inventory_item_id) {
                $invItem = TenantInventoryItem::find($part->inventory_item_id);
                if ($invItem) {
                    $stock = (int) ($invItem->computed_stock_count ?? 0);
                    if ($newQty > $stock && !$invItem->allow_oversell) {
                        return response()->json([
                            'ok'      => false,
                            'message' => "Only {$stock} in stock.",
                        ], 422);
                    }
                }
            }

            $part->update(['quantity' => $newQty]);
            $this->recalcAppointmentTotals($appointment);
            return response()->json([
                'ok' => true,
                'line_total_cents'   => $part->lineTotalCents(),
                'line_total_display' => format_money($part->lineTotalCents()),
            ]);
        }

        if ($op === 'update_line_item') {
            $itemId   = $request->input('item_id');
            $kind     = $request->input('kind', 'service');  // 'service' or 'addon'
            $price    = $request->input('price_cents');      // null = clear override
            $duration = $request->input('duration_minutes'); // null = clear override

            $model = $kind === 'addon'
                ? \App\Models\Tenant\TenantAppointmentAddon::class
                : \App\Models\Tenant\TenantAppointmentItem::class;
            $row = $model::where('id', $itemId)->where('appointment_id', $appointment->id)->first();
            if (!$row) return response()->json(['ok' => false, 'message' => 'Line item not found.'], 422);

            $row->price_cents_override      = ($price === null || $price === '')      ? null : (int) $price;
            $row->duration_minutes_override = ($duration === null || $duration === '') ? null : (int) $duration;
            $row->save();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'change_resource') {
            $newResourceId = $request->input('resource_id');
            $force         = (bool) $request->input('force', false);

            if (!$newResourceId) {
                return response()->json([
                    'ok' => false, 'message' => 'Resource is required.'
                ], 422);
            }

            // Validate the resource belongs to this tenant and is active.
            $resource = \App\Models\Tenant\TenantResource::where('id', $newResourceId)
                ->where('tenant_id', $appointment->tenant_id)
                ->where('is_active', true)
                ->first();

            if (!$resource) {
                return response()->json([
                    'ok' => false, 'message' => 'Selected resource is not available.'
                ], 422);
            }

            // No-op: same resource selected.
            if ($resource->id === $appointment->resource_id) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            $oldResource = \App\Models\Tenant\TenantResource::find($appointment->resource_id);
            $oldName     = $oldResource?->name ?? 'Unassigned';
            $newName     = $resource->name;

            // Conflict check — unless force is set, refuse to move into a
            // busy slot. Returns 409 with the conflicting appointment's
            // shape so the JS can render a clear confirm modal.
            if (!$force) {
                $apptDate = $appointment->appointment_date instanceof \Carbon\Carbon
                    ? $appointment->appointment_date->toDateString()
                    : (string) $appointment->appointment_date;

                $bookingService = app(\App\Services\BookingService::class);
                $conflict = $bookingService->resourceIsFreeDuring(
                    $appointment->tenant_id,
                    $resource->id,
                    $apptDate,
                    $appointment->appointment_time,
                    (int) $appointment->total_duration_minutes,
                    $appointment->id
                );

                if ($conflict) {
                    return response()->json([
                        'ok'        => false,
                        'conflict'  => $conflict,
                        'old_name'  => $oldName,
                        'new_name'  => $newName,
                        'message'   => 'That resource is busy at this time.',
                    ], 409);
                }
            }

            // Apply the change.
            $appointment->resource_id = $resource->id;
            $appointment->save();

            // Audit note. Mirrors the status-change note pattern.
            $noteContent = $force
                ? sprintf('Resource changed from %s to %s (override — conflict accepted).', $oldName, $newName)
                : sprintf('Resource changed from %s to %s.', $oldName, $newName);

            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => $noteContent,
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok'           => true,
                'resource_id'  => $resource->id,
                'resource_name'=> $newName,
                'forced'       => $force,
            ]);
        }

        if ($op === 'slot_weight') {
            $newWeight = (int) $request->input('slot_weight', 0);
            if ($newWeight < 1 || $newWeight > 4) {
                return response()->json([
                    'ok' => false, 'message' => 'Slot weight must be between 1 and 4.'
                ], 422);
            }

            $oldWeight = (int) $appointment->slot_weight;
            if ($newWeight === $oldWeight) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            $appointment->slot_weight = $newWeight;
            $appointment->slot_weight_overridden = true;
            $appointment->save();

            // Audit note — mirrors status-change + resource-change pattern.
            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf('Slot weight changed from %d to %d.', $oldWeight, $newWeight),
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok'          => true,
                'slot_weight' => $newWeight,
                'overridden'  => true,
            ]);
        }

        if ($op === 'reschedule_time') {
            // Drag-to-reschedule: change appointment_time and optionally resource_id
            // in one operation. Mirrors the change_resource pattern with a soft-warn
            // on conflicts and an audit note on success.
            $newTime       = $request->input('appointment_time');  // HH:MM:SS
            $newResourceId = $request->input('resource_id');       // optional, may match current
            $force         = (bool) $request->input('force', false);

            if (!$newTime) {
                return response()->json([
                    'ok' => false, 'message' => 'New time is required.'
                ], 422);
            }

            // Normalize H:i to H:i:s if needed (drag JS sends HH:MM:00 already, but be defensive)
            $parts = explode(':', $newTime);
            if (count($parts) === 2) $newTime = $newTime . ':00';

            // Resolve target resource — defaults to current if not supplied
            $targetResourceId = $newResourceId ?: $appointment->resource_id;
            $resource = \App\Models\Tenant\TenantResource::where('id', $targetResourceId)
                ->where('tenant_id', $appointment->tenant_id)
                ->where('is_active', true)
                ->first();

            if (!$resource) {
                return response()->json([
                    'ok' => false, 'message' => 'Selected resource is not available.'
                ], 422);
            }

            // No-op detection: same time + same resource = nothing to do
            $currentTime = $appointment->appointment_time;
            if ($targetResourceId === $appointment->resource_id && $currentTime === $newTime) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            // Resolve appointment date as string for conflict check
            $apptDate = $appointment->appointment_date instanceof \Carbon\Carbon
                ? $appointment->appointment_date->toDateString()
                : (string) $appointment->appointment_date;

            // Conflict check unless override is in effect
            if (!$force) {
                $bookingService = app(\App\Services\BookingService::class);
                $conflict = $bookingService->resourceIsFreeDuring(
                    $appointment->tenant_id,
                    $resource->id,
                    $apptDate,
                    $newTime,
                    (int) $appointment->total_duration_minutes,
                    $appointment->id  // exclude self
                );

                if ($conflict) {
                    $oldResource = \App\Models\Tenant\TenantResource::find($appointment->resource_id);
                    return response()->json([
                        'ok'        => false,
                        'conflict'  => $conflict,
                        'old_name'  => $oldResource?->name ?? 'current resource',
                        'new_name'  => $resource->name,
                        'message'   => 'That slot is busy.',
                    ], 409);
                }
            }

            // Capture before-state for the audit note
            $oldTime         = $appointment->appointment_time;
            $oldResource     = \App\Models\Tenant\TenantResource::find($appointment->resource_id);
            $oldResourceName = $oldResource?->name ?? 'unassigned';
            $newResourceName = $resource->name;

            // Compute new end time. total_duration already includes prep + cleanup.
            $startDateTime = new \DateTimeImmutable($apptDate . ' ' . $newTime);
            $endDateTime   = $startDateTime->modify('+' . (int) $appointment->total_duration_minutes . ' minutes');

            $appointment->appointment_time     = $newTime;
            $appointment->appointment_end_time = $endDateTime->format('H:i:s');
            $appointment->resource_id          = $resource->id;
            $appointment->save();

            // Audit note — describe what actually changed.
            $resourceChanged = $oldResource?->id !== $resource->id;
            $timeChanged     = $oldTime !== $newTime;

            $formatTime = function (string $hms): string {
                $t = \Carbon\Carbon::createFromFormat('H:i:s', $hms);
                return $t ? $t->format('g:i A') : $hms;
            };

            if ($resourceChanged && $timeChanged) {
                $note = sprintf(
                    'Rescheduled from %s on %s to %s on %s.',
                    $formatTime($oldTime), $oldResourceName,
                    $formatTime($newTime), $newResourceName
                );
            } elseif ($timeChanged) {
                $note = sprintf('Rescheduled from %s to %s.', $formatTime($oldTime), $formatTime($newTime));
            } else {
                $note = sprintf('Resource changed from %s to %s.', $oldResourceName, $newResourceName);
            }

            if ($force) {
                $note .= ' (override — conflict accepted)';
            }

            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => $note,
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok'              => true,
                'appointment_time'=> $newTime,
                'resource_id'     => $resource->id,
                'resource_name'   => $resource->name,
                'forced'          => $force,
            ]);
        }

        return response()->json(['ok' => false, 'message' => 'Unknown operation.'], 422);
    }
    /**
     * Recalculate appointment totals from current items + addons.
     * Uses effective (override-aware) values. Called after any line-item mutation.
     */
    /**
     * Lightweight sale number generator for deposit sales spawned from the
     * appointment page. Format: DP-YYYYMMDD-### per tenant.
     *
     * Uses a different prefix than register-spawned sales so reports can
     * easily distinguish deposit-collection sales from regular completion
     * sales if needed.
     */
    private function generateDepositSaleNumber(string $tenantId): string
    {
        $today = now()->format('Ymd');
        $prefix = "DP-{$today}-";
        $maxNumber = \DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderByDesc('sale_number')
            ->value('sale_number');

        $next = 1;
        if ($maxNumber) {
            $parts = explode('-', $maxNumber);
            $lastNum = (int) end($parts);
            $next = $lastNum + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Search active inventory items for the part-picker autocomplete.
     * Returns minimal payload so the picker can render fast.
     */
    public function searchInventoryItems(Request $request)
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));

        $query = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('sku',  'like', "%{$q}%");
            });
        }

        $items = $query->orderBy('name')->limit(15)->get(['id', 'name', 'sku', 'shop_sell_price_cents', 'catalog_msrp_cents', 'computed_stock_count', 'allow_oversell']);

        return response()->json([
            'ok'    => true,
            'items' => $items->map(function ($i) {
                $price = (int) ($i->effectiveSellPriceCents() ?? 0);
                return [
                    'id'             => $i->id,
                    'name'           => $i->name,
                    'sku'            => $i->sku,
                    'price_cents'    => $price,
                    'price_display'  => format_money($price),
                    'stock'          => (int) ($i->computed_stock_count ?? 0),
                    'allow_oversell' => (bool) $i->allow_oversell,
                ];
            })->values(),
        ]);
    }

    /**
     * Recompute subtotal, tax, and total for an appointment based on the
     * current items, addons, and parts. Honors:
     *   - tenant.tax_services_default for service items + addons
     *   - per-part is_taxable for parts
     *   - tenant.default_tax_rate for the actual rate
     *
     * Tax is computed on each line independently (not on the subtotal) so
     * fractional cents from rounding don't compound.
     */
    protected function recalcAppointmentTotals(\App\Models\Tenant\TenantAppointment $appointment): void
    {
        $appointment->load(['items', 'addons', 'parts', 'tenant']);

        $tenant = $appointment->tenant;
        $taxRate = (float) ($tenant->default_tax_rate ?? 0); // e.g. 8.875 for 8.875%
        $servicesTaxable = (bool) ($tenant->tax_services_default ?? true);

        $subtotalCents = 0;
        $taxCents      = 0;
        $totalDuration = 0;

        // Services
        foreach ($appointment->items as $item) {
            $line = (int) $item->effectivePriceCents();
            $subtotalCents += $line;
            $totalDuration += (int) $item->prep_before_minutes_snapshot
                            + $item->effectiveDurationMinutes()
                            + (int) $item->cleanup_after_minutes_snapshot;

            if ($servicesTaxable && $taxRate > 0) {
                $taxCents += (int) round($line * $taxRate / 100);
            }
        }

        // Addons (treated like services for taxability — they're service extras)
        foreach ($appointment->addons as $addon) {
            $line = (int) $addon->effectivePriceCents();
            $subtotalCents += $line;
            $totalDuration += $addon->effectiveDurationMinutes();

            if ($servicesTaxable && $taxRate > 0) {
                $taxCents += (int) round($line * $taxRate / 100);
            }
        }

        // Parts (per-row taxability)
        foreach ($appointment->parts as $part) {
            $line = (int) $part->lineTotalCents();
            $subtotalCents += $line;
            // Parts don't have a duration — they're physical goods.

            if ($part->is_taxable && $taxRate > 0) {
                $taxCents += (int) round($line * $taxRate / 100);
            }
        }

        // Recompute appointment_end_time if the appointment has a start time
        $endTime = $appointment->appointment_end_time;
        if ($appointment->appointment_time && $totalDuration > 0) {
            $start = new \DateTimeImmutable($appointment->appointment_time);
            $end   = $start->modify("+{$totalDuration} minutes");
            $endTime = $end->format('H:i:s');
        }

        $appointment->update([
            'subtotal_cents'         => $subtotalCents,
            'tax_cents'              => $taxCents,
            'total_cents'            => $subtotalCents + $taxCents,
            'total_duration_minutes' => $totalDuration,
            'appointment_end_time'   => $endTime,
        ]);
    }

}
