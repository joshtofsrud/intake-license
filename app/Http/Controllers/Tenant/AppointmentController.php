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
    private const STATUSES = [
        'pending', 'confirmed', 'in_progress',
        'completed', 'shipped', 'closed',
        'cancelled', 'refunded',
    ];

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

    private const DESTRUCTIVE = ['cancelled', 'refunded'];

    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------
    public function index(Request $request)
    {
        $tenant   = tenant();
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

        // Find or create the customer record
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

        // Generate ITO number: ITO-{sequential}-{random}
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

        // Add a system note
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
                'ok'       => true,
                'redirect' => route('tenant.appointments.show', $appointment->id),
            ]);
        }

        return redirect()->route('tenant.appointments.show', $appointment->id)
            ->with('success', 'Appointment created.');
    }

    // ----------------------------------------------------------------
    // Detail
    // ----------------------------------------------------------------
    public function show(Request $request, string $id)
    {
        $tenant      = tenant();
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'addons', 'responses', 'notes', 'charges', 'customer'])
            ->firstOrFail();

        $transitions = self::TRANSITIONS[$appointment->status] ?? [];
        $destructive = self::DESTRUCTIVE;

        return view('tenant.appointments.show', compact(
            'appointment', 'transitions', 'destructive'
        ));
    }

    // ----------------------------------------------------------------
    // Update (status, payment)
    // ----------------------------------------------------------------
    public function update(Request $request, string $id)
    {
        $tenant      = tenant();
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $op = $request->input('op');

        // Status transition
        if ($op === 'status') {
            $newStatus = $request->input('status');
            $allowed   = self::TRANSITIONS[$appointment->status] ?? [];
            if (! in_array($newStatus, $allowed, true)) {
                return back()->with('error', 'Invalid status transition.');
            }
            $appointment->update(['status' => $newStatus]);

            TenantAppointmentNote::create([
                'appointment_id'    => $appointment->id,
                'user_id'           => Auth::guard('tenant')->id(),
                'note_type'         => 'system',
                'is_customer_visible' => false,
                'note_content'      => 'Status changed to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.',
                'created_at'        => now(),
            ]);

            return back()->with('success', 'Status updated.');
        }

        // Update slot weight (admin override)
        if ($op === 'slot_weight') {
            $weight = max(1, min(4, (int) $request->input('slot_weight', 1)));
            $appointment->update([
                'slot_weight'            => $weight,
                'slot_weight_overridden' => $weight !== (int) $appointment->slot_weight_auto,
            ]);
            return back()->with('success', 'Slot weight updated.');
        }

        // Payment status
        if ($op === 'payment') {
            $newPayment = $request->input('payment_status');
            if (! in_array($newPayment, ['unpaid', 'partial', 'paid', 'refunded'], true)) {
                return back()->with('error', 'Invalid payment status.');
            }
            $appointment->update(['payment_status' => $newPayment]);
            return back()->with('success', 'Payment status updated.');
        }

        // Add charge
        if ($op === 'add_charge') {
            $request->validate([
                'description'  => ['required', 'string', 'max:255'],
                'amount_cents' => ['required', 'integer', 'min:1'],
            ]);
            TenantAppointmentCharge::create([
                'appointment_id' => $appointment->id,
                'description'    => $request->input('description'),
                'amount_cents'   => (int) $request->input('amount_cents'),
                'is_paid'        => false,
                'created_at'     => now(),
            ]);
            return back()->with('success', 'Charge added.');
        }

        // Add note (AJAX)
        if ($op === 'add_note' && $request->ajax()) {
            $note = mb_substr(trim($request->input('note', '')), 0, 500);
            if (! $note) {
                return response()->json(['success' => false, 'message' => 'Note is required.']);
            }
            $n = TenantAppointmentNote::create([
                'appointment_id'     => $appointment->id,
                'user_id'            => Auth::guard('tenant')->id(),
                'note_type'          => 'staff',
                'is_customer_visible'=> false,
                'note_content'       => $note,
                'created_at'         => now(),
            ]);
            $user = Auth::guard('tenant')->user();
            return response()->json([
                'success'     => true,
                'id'          => $n->id,
                'note'        => $n->note_content,
                'author'      => $user->name,
                'created_at'  => $n->created_at->format('M j, Y g:i a'),
            ]);
        }

        // Delete note (AJAX)
        if ($op === 'delete_note' && $request->ajax()) {
            TenantAppointmentNote::where('appointment_id', $appointment->id)
                ->where('id', $request->input('note_id'))
                ->delete();
            return response()->json(['success' => true]);
        }

        return back();
    }
}
