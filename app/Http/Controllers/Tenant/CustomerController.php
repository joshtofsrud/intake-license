<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerNote;
use App\Models\Tenant\TenantAppointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
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

        $search  = $request->input('s', '');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        $q = TenantCustomer::where('tenant_id', $tenant->id);

        if ($search) {
            $q->where(function ($q2) use ($search) {
                $q2->where('first_name', 'like', "%{$search}%")
                   ->orWhere('last_name',  'like', "%{$search}%")
                   ->orWhere('email',      'like', "%{$search}%")
                   ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        $total   = $q->count();
        $customers = $q->orderBy('last_name')->orderBy('first_name')
                       ->offset(($page - 1) * $perPage)
                       ->limit($perPage)
                       ->get();

        $emails = $customers->pluck('email')->toArray();
        $stats = [];
        if (!empty($emails)) {
            $rows = TenantAppointment::where('tenant_id', $tenant->id)
                ->whereIn('customer_email', $emails)
                ->selectRaw('
                    customer_email,
                    MAX(CASE WHEN status IN (\'completed\',\'closed\',\'shipped\') THEN appointment_date END) AS last_service_date,
                    COALESCE(SUM(CASE WHEN payment_status = \'paid\' THEN total_cents ELSE 0 END), 0) AS total_spend_cents
                ')
                ->groupBy('customer_email')
                ->get()
                ->keyBy('customer_email');

            foreach ($customers as $c) {
                $stats[$c->id] = $rows[$c->email] ?? null;
            }
        }

        $totalPages = max(1, ceil($total / $perPage));

        return view('tenant.customers.index', compact(
            'customers', 'stats', 'total', 'page', 'totalPages', 'search'
        ));
    }

    public function show(Request $request, string $id)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonDetail(tenant(), $id);
        }
        return redirect()->route('tenant.customers.index');
    }

    public function store(Request $request)
    {
        $tenant = tenant();
        $data   = $this->validated($request);
        $data['tenant_id'] = $tenant->id;

        $existing = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->first();

        if ($existing) {
            $existing->update($data);
            $customer = $existing;
        } else {
            $customer = TenantCustomer::create($data);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $customer->id]);
        }

        return redirect()->route('tenant.customers.index')
            ->with('success', 'Customer saved.');
    }

    public function update(Request $request, string $id)
    {
        return $this->handleUpdate(tenant(), $id, $request);
    }

    // ----------------------------------------------------------------
    // JSON detail
    // ----------------------------------------------------------------
    private function jsonDetail($tenant, string $id)
    {
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $notes = TenantCustomerNote::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get();

        $appointments = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('customer_email', $customer->email)
            ->orderByDesc('appointment_date')
            ->orderByDesc('created_at')
            ->get();

        $totalSpend = $appointments->where('payment_status', 'paid')->sum('total_cents');
        $lastService = $appointments->whereIn('status', ['completed','closed','shipped'])
            ->max('appointment_date');
        $totalAppts = $appointments->count();

        return response()->json([
            'ok' => true,
            'customer' => [
                'id'            => $customer->id,
                'first_name'    => $customer->first_name,
                'last_name'     => $customer->last_name,
                'name'          => $customer->first_name . ' ' . $customer->last_name,
                'email'         => $customer->email,
                'phone'         => $customer->phone,
                'address_line1' => $customer->address_line1,
                'city'          => $customer->city,
                'state'         => $customer->state,
                'postcode'      => $customer->postcode,
                'country'       => $customer->country,
                'created_at'    => $customer->created_at->format('M j, Y'),
                'total_spend'   => format_money($totalSpend),
                'last_service'  => $lastService ? \Carbon\Carbon::parse($lastService)->format('M j, Y') : null,
                'total_appts'   => $totalAppts,
            ],
            'appointments' => $appointments->take(10)->map(fn($a) => [
                'id'      => $a->id,
                'ito'     => $a->ra_number,
                'date'    => $a->appointment_date->format('M j, Y'),
                'status'  => ucwords(str_replace('_', ' ', $a->status)),
                'status_key' => $a->status,
                'payment' => ucfirst($a->payment_status),
                'payment_key' => $a->payment_status,
                'total'   => format_money($a->total_cents),
            ]),
            'notes' => $notes->map(fn($n) => [
                'id'         => $n->id,
                'note'       => $n->note,
                'author'     => $n->user?->name ?? 'Staff',
                'created_at' => $n->created_at->format('M j, g:i a'),
            ]),
        ]);
    }

    // ----------------------------------------------------------------
    // Handle update operations
    // ----------------------------------------------------------------
    private function handleUpdate($tenant, string $id, Request $request)
    {
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $op = $request->input('op');

        if ($op === 'update_info') {
            $data = $this->validated($request, $customer->email);
            $customer->update($data);
            return response()->json(['ok' => true]);
        }

        if ($op === 'add_note') {
            $note = mb_substr(trim($request->input('note', '')), 0, 200);
            if (!$note) {
                return response()->json(['ok' => false, 'message' => 'Note is required.'], 422);
            }
            $n = TenantCustomerNote::create([
                'tenant_id'   => $tenant->id,
                'customer_id' => $customer->id,
                'user_id'     => Auth::guard('tenant')->id(),
                'note'        => $note,
                'created_at'  => now(),
            ]);
            $user = Auth::guard('tenant')->user();
            return response()->json([
                'ok'         => true,
                'id'         => $n->id,
                'note'       => $n->note,
                'author'     => $user->name,
                'created_at' => $n->created_at->format('M j, g:i a'),
            ]);
        }

        if ($op === 'delete_note') {
            TenantCustomerNote::where('customer_id', $customer->id)
                ->where('id', $request->input('note_id'))
                ->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'message' => 'Unknown operation.'], 422);
    }

    private function validated(Request $request, ?string $existingEmail = null): array
    {
        $emailRules = $existingEmail ? ['nullable','email','max:191'] : ['required','email','max:191'];
        $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => $emailRules,
            'phone'      => ['nullable', 'string', 'max:32'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'city'       => ['nullable', 'string', 'max:100'],
            'state'      => ['nullable', 'string', 'max:64'],
            'postcode'   => ['nullable', 'string', 'max:20'],
            'country'    => ['nullable', 'string', 'max:2'],
        ]);

        return array_filter([
            'first_name'    => $request->input('first_name'),
            'last_name'     => $request->input('last_name'),
            'email'         => $request->input('email') ?? $existingEmail,
            'phone'         => $request->input('phone'),
            'address_line1' => $request->input('address_line1'),
            'city'          => $request->input('city'),
            'state'         => $request->input('state'),
            'postcode'      => $request->input('postcode'),
            'country'       => strtoupper($request->input('country', 'US')),
        ], fn($v) => $v !== null && $v !== '');
    }
}
