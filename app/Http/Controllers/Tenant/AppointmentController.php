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
        $sort     = $request->input('sort', 'date_desc');
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
        $appointments = $q->offset(($page - 1) * $perPage)
                          ->limit($perPage)
                          ->get();

        $totalPages = max(1, ceil($total / $perPage));

        return view('tenant.appointments.index', compact(
            'appointments', 'total', 'page', 'totalPages',
            'search', 'status', 'payment', 'dateFrom', 'dateTo', 'sort'
        ));
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('update')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

        $data = $request->validate([
            'customer_first_name' => ['required', 'string', 'max:100'],
            'customer_last_name'  => ['required', 'string', 'max:100'],
            'customer_email'      => ['required', 'email', 'max:255'],
            'customer_phone'      => ['nullable', 'string', 'max:32'],
            'appointment_date'    => ['required', 'date'],
            'staff_notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = TenantCustomer::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => strtolower($data['customer_email'])],
            ['first_name' => $data['customer_first_name'], 'last_name' => $data['customer_last_name'], 'phone' => $data['customer_phone'] ?? null]
        );

        $seq = TenantAppointment::where('tenant_id', $tenant->id)->count() + 1;
        $itoNumber = 'ITO-' . str_pad($seq, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(Str::random(4));

        $appointment = TenantAppointment::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'ra_number' => $itoNumber,
            'customer_first_name' => $data['customer_first_name'], 'customer_last_name' => $data['customer_last_name'],
            'customer_email' => strtolower($data['customer_email']), 'customer_phone' => $data['customer_phone'] ?? null,
            'appointment_date' => $data['appointment_date'], 'status' => 'pending', 'payment_status' => 'unpaid',
            'payment_method' => 'manual', 'subtotal_cents' => 0, 'tax_cents' => 0, 'total_cents' => 0, 'paid_cents' => 0,
            'staff_notes' => $data['staff_notes'] ?? null,
        ]);

        TenantAppointmentNote::create([
            'appointment_id' => $appointment->id, 'user_id' => Auth::guard('tenant')->id(),
            'note_type' => 'system', 'is_customer_visible' => false,
            'note_content' => 'Appointment created manually by staff.', 'created_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $appointment->id, 'ito' => $itoNumber]);
        }
        return redirect()->route('tenant.appointments.index')->with('success', 'Appointment created.');
    }

    public function show(Request $request, string $id)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonDetail(tenant(), $id);
        }
        return redirect()->route('tenant.appointments.index');
    }

    public function update(Request $request, string $id)
    {
        return $this->handleUpdate(tenant(), $id, $request);
    }

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
                'items' => $appointment->items->map(fn($i) => ['name' => $i->item_name_snapshot, 'duration' => $i->duration_minutes_snapshot, 'price' => format_money($i->price_cents)]),
                'addons' => $appointment->addons->map(fn($a) => ['name' => $a->addon_name_snapshot, 'price' => format_money($a->price_cents)]),
                'charges' => $appointment->charges->map(fn($c) => ['id' => $c->id, 'description' => $c->description, 'amount' => format_money($c->amount_cents), 'is_paid' => $c->is_paid, 'date' => \Carbon\Carbon::parse($c->created_at)->format('M j')]),
                'notes' => $appointment->notes->sortByDesc('created_at')->values()->map(fn($n) => ['id' => $n->id, 'note' => $n->note_content, 'author' => $n->user?->name ?? ($n->note_type === 'system' ? 'System' : 'Staff'), 'type' => $n->note_type, 'created_at' => \Carbon\Carbon::parse($n->created_at)->format('M j, g:i a')]),
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
            $appointment->update(['status' => $newStatus]);

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
            return response()->json(['ok' => true, 'status' => $newStatus, 'label' => ucwords(str_replace('_', ' ', $newStatus))]);
        }
        if ($op === 'payment') {
            $newPayment = $request->input('payment_status');
            if (!in_array($newPayment, ['unpaid', 'partial', 'paid', 'refunded'], true)) return response()->json(['ok' => false, 'message' => 'Invalid payment status.'], 422);
            $appointment->update(['payment_status' => $newPayment]);
            return response()->json(['ok' => true, 'payment_status' => $newPayment]);
        }
        if ($op === 'date') {
            $request->validate(['appointment_date' => ['required', 'date']]);
            $appointment->update(['appointment_date' => $request->input('appointment_date')]);
            return response()->json(['ok' => true]);
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
        return response()->json(['ok' => false, 'message' => 'Unknown operation.'], 422);
    }
}
