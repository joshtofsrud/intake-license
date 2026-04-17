<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentNote;
use App\Models\Tenant\TenantAppointmentCharge;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    private const TRANSITIONS = [
        'pending'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'shipped', 'cancelled'],
        'completed'   => ['closed', 'shipped', 'refunded'],
        'shipped'     => ['closed', 'refunded'],
        'closed'      => ['refunded'],
        'cancelled'   => [],
        'refunded'    => [],
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

    // ----------------------------------------------------------------
    // List + Detail (JSON when ?detail=UUID)
    // ----------------------------------------------------------------
    public function index(Request $request)
    {
        $tenant = tenant();

        // JSON detail request
        if ($request->has('detail') && ($request->expectsJson() || $request->ajax())) {
            return $this->jsonDetail($tenant, $request->input('detail'));
        }

        // JSON update request
        if ($request->has('update') && $request->isMethod('post')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

        $search   = $request->input('s', '');
        $status   = $request->input('status', '');
        $payment  = $request->input('payment', '');
        $dateFrom = $request->input('date_from', '');
        $dateTo   = $request->input('date_to', '');
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 25;

        $q = TenantAppointment::where('tenant_id', $tenant->id);

        if ($search) {
            $q->where(function ($q2) use ($search) {
                $q2->where('ra_number', 'like', "%{$search}%")
                   ->orWhere('customer_first_name', 'like', "%{$search}%")
                   ->orWhere('customer_last_name', 'like', "%{$search}%")
                   ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }
        if ($status)   $q->where('status', $status);
        if ($payment)  $q->where('payment_status', $payment);
        if ($dateFrom) $q->where('appointment_date', '>=', $dateFrom);
        if ($dateTo)   $q->where('appointment_date', '<=', $dateTo);

        $total = $q->count();
        $appointments = $q->orderByDesc('appointment_date')
                          ->orderByDesc('created_at')
                          ->offset(($page - 1) * $perPage)
                          ->limit($perPage)
                          ->get();

        $totalPages = max(1, ceil($total / $perPage));

        return view('tenant.appointments.index', compact(
            'appointments', 'total', 'page', 'totalPages',
            'search', 'status', 'payment', 'dateFrom', 'dateTo'
        ));
    }

    // ----------------------------------------------------------------
    // Store (create new appointment via AJAX modal)
    // ----------------------------------------------------------------
    public function store(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'customer_first_name' => ['required', 'string', 'max:100'],
            'customer_last_name'  => ['required', 'string', 'max:100'],
            'customer_email'      => ['required', 'email', 'max:255'],
            'customer_phone'      => ['nullable', 'string', 'max:32'],
            'appointment_date'    => ['required', 'date'],
            'staff_notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = TenantCustomer::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'email'     => strtolower($data['customer_email']),
            ],
            [
                'first_name' => $data['customer_first_name'],
                'last_name'  => $data['customer_last_name'],
                'phone'      => $data['customer_phone'] ?? null,
            ]
        );

        $seq = TenantAppointment::where('tenant_id', $tenant->id)->count() + 1;
        $itoNumber = 'ITO-' . str_pad($seq, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(Str::random(4));

        $appointment = TenantAppointment::create([
            'tenant_id'           => $tenant->id,
            'customer_id'         => $customer->id,
            'ra_number'           => $itoNumber,
            'customer_first_name' => $data['customer_first_name'],
            'customer_last_name'  => $data['customer_last_name'],
            'customer_email'      => strtolower($data['customer_email']),
            'customer_phone'      => $data['customer_phone'] ?? null,
            'appointment_date'    => $data['appointment_date'],
            'status'              => 'pending',
            'payment_status'      => 'unpaid',
            'payment_method'      => 'manual',
            'subtotal_cents'      => 0,
            'tax_cents'           => 0,
            'total_cents'         => 0,
            'paid_cents'          => 0,
            'staff_notes'         => $data['staff_notes'] ?? null,
        ]);

        TenantAppointmentNote::create([
            'appointment_id'      => $appointment->id,
            'user_id'             => Auth::guard('tenant')->id(),
            'note_type'           => 'system',
            'is_customer_visible' => false,
            'note_content'        => 'Appointment created manually by staff.',
            'created_at'          => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok'  => true,
                'id'  => $appointment->id,
                'ito' => $itoNumber,
            ]);
        }

        return redirect()->route('tenant.appointments.index')
            ->with('success', 'Appointment created.');
    }

    // ----------------------------------------------------------------
    // Show (kept for backward compat, redirects non-AJAX)
    // ----------------------------------------------------------------
    public function show(Request $request, string $id)
    {
        $tenant = tenant();
        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonDetail($tenant, $id);
        }
        return redirect()->route('tenant.appointments.index');
    }

    // ----------------------------------------------------------------
    // Update (kept for backward compat)
    // ----------------------------------------------------------------
    public function update(Request $request, string $id)
    {
        return $this->handleUpdate(tenant(), $id, $request);
    }

    // ----------------------------------------------------------------
    // JSON detail
    // ----------------------------------------------------------------
    private function jsonDetail($tenant, string $id)
    {
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'addons', 'notes', 'charges', 'customer'])
            ->firstOrFail();

        $transitions = self::TRANSITIONS[$appointment->status] ?? [];

        return response()->json([
            'ok' => true,
            'appointment' => [
                'id'              => $appointment->id,
                'ra_number'       => $appointment->ra_number,
                'status'          => $appointment->status,
                'status_label'    => ucwords(str_replace('_', ' ', $appointment->status)),
                'payment_status'  => $appointment->payment_status,
                'payment_label'   => ucfirst($appointment->payment_status),
                'customer_name'   => $appointment->customerName(),
                'customer_email'  => $appointment->customer_email,
                'customer_phone'  => $appointment->customer_phone,
                'customer_id'     => $appointment->customer_id,
                'appointment_date'=> $appointment->appointment_date->format('M j, Y'),
                'appointment_date_raw' => $appointment->appointment_date->format('Y-m-d'),
                'staff_notes'     => $appointment->staff_notes,
                'subtotal_cents'  => $appointment->subtotal_cents,
                'tax_cents'       => $appointment->tax_cents,
                'total_cents'     => $appointment->total_cents,
                'paid_cents'      => $appointment->paid_cents,
                'total_display'   => format_money($appointment->total_cents),
                'paid_display'    => format_money($appointment->paid_cents),
                'subtotal_display'=> format_money($appointment->subtotal_cents),
                'created_at'      => $appointment->created_at->format('M j, Y g:i a'),
                'items' => $appointment->items->map(fn($i) => [
                    'name'  => $i->item_name_snapshot,
                    'tier'  => $i->tier_name_snapshot,
                    'price' => format_money($i->price_cents),
                ]),
                'addons' => $appointment->addons->map(fn($a) => [
                    'name'  => $a->addon_name_snapshot,
                    'price' => format_money($a->price_cents),
                ]),
                'charges' => $appointment->charges->map(fn($c) => [
                    'id'          => $c->id,
                    'description' => $c->description,
                    'amount'      => format_money($c->amount_cents),
                    'is_paid'     => $c->is_paid,
                    'date'        => \Carbon\Carbon::parse($c->created_at)->format('M j'),
                ]),
                'notes' => $appointment->notes->sortByDesc('created_at')->values()->map(fn($n) => [
                    'id'         => $n->id,
                    'note'       => $n->note_content,
                    'author'     => $n->user?->name ?? ($n->note_type === 'system' ? 'System' : 'Staff'),
                    'type'       => $n->note_type,
                    'created_at' => \Carbon\Carbon::parse($n->created_at)->format('M j, g:i a'),
                ]),
            ],
            'transitions' => collect($transitions)->map(fn($t) => [
                'status'      => $t,
                'label'       => self::TRANSITION_LABELS[$t] ?? ucfirst($t),
                'destructive' => in_array($t, self::DESTRUCTIVE),
            ])->values(),
        ]);
    }

    // ----------------------------------------------------------------
    // Handle update operations
    // ----------------------------------------------------------------
    private function handleUpdate($tenant, string $id, Request $request)
    {
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $op = $request->input('op');

        if ($op === 'status') {
            $newStatus = $request->input('status');
            $allowed   = self::TRANSITIONS[$appointment->status] ?? [];
            if (! in_array($newStatus, $allowed, true)) {
                return response()->json(['ok' => false, 'message' => 'Invalid status transition.'], 422);
            }
            $appointment->update(['status' => $newStatus]);
            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => 'Status changed to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.',
                'created_at'          => now(),
            ]);
            return response()->json(['ok' => true, 'status' => $newStatus, 'label' => ucwords(str_replace('_', ' ', $newStatus))]);
        }

        if ($op === 'payment') {
            $newPayment = $request->input('payment_status');
            if (! in_array($newPayment, ['unpaid', 'partial', 'paid', 'refunded'], true)) {
                return response()->json(['ok' => false, 'message' => 'Invalid payment status.'], 422);
            }
            $appointment->update(['payment_status' => $newPayment]);
            return response()->json(['ok' => true, 'payment_status' => $newPayment]);
        }

        if ($op === 'add_charge') {
            $request->validate([
                'description'  => ['required', 'string', 'max:255'],
                'amount_cents' => ['required', 'integer', 'min:1'],
            ]);
            $charge = TenantAppointmentCharge::create([
                'appointment_id' => $appointment->id,
                'description'    => $request->input('description'),
                'amount_cents'   => (int) $request->input('amount_cents'),
                'is_paid'        => false,
                'created_at'     => now(),
            ]);
            return response()->json(['ok' => true, 'id' => $charge->id, 'description' => $charge->description, 'amount' => format_money($charge->amount_cents)]);
        }

        if ($op === 'add_note') {
            $note = mb_substr(trim($request->input('note', '')), 0, 500);
            if (! $note) {
                return response()->json(['ok' => false, 'message' => 'Note is required.'], 422);
            }
            $n = TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'staff',
                'is_customer_visible' => false,
                'note_content'        => $note,
                'created_at'          => now(),
            ]);
            $user = Auth::guard('tenant')->user();
            return response()->json(['ok' => true, 'id' => $n->id, 'note' => $n->note_content, 'author' => $user->name, 'created_at' => $n->created_at->format('M j, g:i a')]);
        }

        if ($op === 'delete_note') {
            TenantAppointmentNote::where('appointment_id', $appointment->id)
                ->where('id', $request->input('note_id'))
                ->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'message' => 'Unknown operation.'], 422);
    }
}
