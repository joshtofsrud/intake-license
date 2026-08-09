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

        if ($request->has('detail') && ($request->expectsJson() || $request->ajax())) {
            return $this->jsonDetail($tenant, $request->input('detail'));
        }

        $search        = $request->input('s', '');
        $createdAfter  = $request->input('created_after', ''); // MARKER-PATCH-114
        // When the dashboard's "new customers" tile links here, default sort
        // to newest-first so the new arrivals are immediately visible.
        $defaultSort   = $createdAfter ? 'added_desc' : 'name_asc';
        $sort          = $request->input('sort', $defaultSort);
        $page          = max(1, (int) $request->input('page', 1));
        $perPage       = 25;

        $q = TenantCustomer::where('tenant_id', $tenant->id);
        if ($createdAfter) {
            try {
                $q->where('created_at', '>=', \Carbon\Carbon::parse($createdAfter)->startOfDay());
            } catch (\Throwable $e) {
                $createdAfter = ''; // bad date, ignore silently
            }
        }

        if ($search) {
            $q->where(function ($q2) use ($search) {
                $q2->where('first_name', 'like', "%{$search}%")
                   ->orWhere('last_name',  'like', "%{$search}%")
                   ->orWhere('email',      'like', "%{$search}%")
                   ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        // VIPs-only filter is a sort option for UX simplicity. When
        // selected, filter to is_vip=true and order by name ascending.
        if ($sort === 'vips_only') {
            $q->where('is_vip', true);
        }

        // MARKER-CUST-ACCOUNT — same pseudo-sort pattern. A portal account
        // is exactly "has set a password".
        if ($sort === 'has_account') {
            $q->whereNotNull('password');
        }
        if ($sort === 'no_account') {
            $q->whereNull('password');
        }

        // MARKER-BIZ-LIST — same pattern as VIPs. Also the practical route to
        // finding records where a business was typed into a person's name.
        if ($sort === 'businesses_only') {
            $q->where('customer_type', \App\Models\Tenant\TenantCustomer::TYPE_BUSINESS);
        }

        // Sort
        switch ($sort) {
            case 'name_desc':
                $q->orderByDesc('last_name')->orderByDesc('first_name');
                break;
            case 'added_desc':
                $q->orderByDesc('created_at');
                break;
            case 'added_asc':
                $q->orderBy('created_at');
                break;
            default: // name_asc
                $q->orderBy('last_name')->orderBy('first_name');
                break;
        }

        $total   = $q->count();
        $customers = $q->offset(($page - 1) * $perPage)
                       ->limit($perPage)
                       ->get();

        $emails = $customers->pluck('email')->toArray();
        $stats = [];
        if (!empty($emails)) {
            // last_service_date stays appointment-sourced (scheduling fact).
            $rows = TenantAppointment::where('tenant_id', $tenant->id)
                ->whereIn('customer_email', $emails)
                ->selectRaw('
                    customer_email,
                    MAX(CASE WHEN status = \'completed\' THEN appointment_date END) AS last_service_date
                ')
                ->groupBy('customer_email')
                ->get()
                ->keyBy('customer_email');

            // MARKER-PATCH-184F — lifetime spend from the sale payment ledger,
            // keyed by the sale's customer_id (payments received).
            $spendByCustomer = \App\Models\Tenant\TenantSalePayment::where('tenant_sale_payments.tenant_id', $tenant->id)
                ->join('tenant_sales as ts', 'ts.id', '=', 'tenant_sale_payments.sale_id')
                ->whereNotNull('ts.customer_id')
                ->selectRaw('ts.customer_id as customer_id, SUM(tenant_sale_payments.amount_cents) as total_spend_cents')
                ->groupBy('ts.customer_id')
                ->pluck('total_spend_cents', 'customer_id');

            foreach ($customers as $c) {
                $row = $rows[$c->email] ?? null;
                $stats[$c->id] = (object) [
                    'last_service_date' => $row->last_service_date ?? null,
                    'total_spend_cents' => (int) ($spendByCustomer[$c->id] ?? 0),
                ];
            }
        }

        // Re-sort by spend/last service if needed (done in PHP since it's a joined stat)
        if ($sort === 'spend_desc') {
            $customers = $customers->sortByDesc(fn($c) => (int)($stats[$c->id]?->total_spend_cents ?? 0))->values();
        } elseif ($sort === 'spend_asc') {
            $customers = $customers->sortBy(fn($c) => (int)($stats[$c->id]?->total_spend_cents ?? 0))->values();
        } elseif ($sort === 'last_service') {
            $customers = $customers->sortByDesc(fn($c) => $stats[$c->id]?->last_service_date ?? '0000-00-00')->values();
        }

        $totalPages = max(1, ceil($total / $perPage));

        return view('tenant.customers.index', compact(
            'customers', 'stats', 'total', 'page', 'totalPages', 'search', 'sort', 'createdAfter'
        ));
    }

    /**
     * Lightweight customer search endpoint for typeahead pickers throughout
     * the admin (class registration, future POS, etc). Returns JSON with up
     * to 12 matches across name, email, phone — narrow result set so we don't
     * ship 5000 rows over the wire.
     *
     * Empty query returns the 12 most-recent customers as a "default browse"
     * convenience for clicking through without typing.
     */
    public function search(Request $request)
    {
        $tenant = tenant();
        $q      = trim((string) $request->input('q', ''));
        $limit  = 12;

        $query = TenantCustomer::where('tenant_id', $tenant->id);

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('first_name', 'like', "%{$q}%")
                   ->orWhere('last_name', 'like', "%{$q}%")
                   ->orWhere('email',     'like', "%{$q}%")
                   ->orWhere('phone',     'like', "%{$q}%")
                   // MARKER-BIZ-SEARCH — searching "Spokane Public" has to
                   // find the business, and its contacts are searchable too.
                   ->orWhere('business_name', 'like', "%{$q}%")
                   ->orWhereExists(function ($sub) use ($q) {
                       $sub->selectRaw('1')
                           ->from('tenant_customer_contacts as tcc')
                           ->whereColumn('tcc.customer_id', 'tenant_customers.id')
                           ->where(function ($w) use ($q) {
                               $w->where('tcc.name', 'like', "%{$q}%")
                                 ->orWhere('tcc.email', 'like', "%{$q}%")
                                 ->orWhere('tcc.phone', 'like', "%{$q}%");
                           });
                   });
            });
            // Name match wins over partial — order by best match heuristically
            $query->orderByRaw("
                CASE
                    WHEN business_name LIKE ? THEN 0
                    WHEN first_name LIKE ? OR last_name LIKE ? THEN 0
                    WHEN email LIKE ? THEN 1
                    ELSE 2
                END
            ", ["{$q}%", "{$q}%", "{$q}%", "{$q}%"]);
        } else {
            $query->orderByDesc('created_at');
        }

        $rows = $query->limit($limit)
            ->get([
                'id', 'first_name', 'last_name', 'email', 'phone',
                'customer_type', 'business_name', 'tax_exempt',
                'tax_exempt_certificate', 'po_required', 'payment_terms', // MARKER-BIZ-SEARCH
            ])
            ->map(fn($c) => [
                'id'         => $c->id,
                'first_name' => $c->first_name,
                'last_name'  => $c->last_name,
                'email'      => $c->email,
                'phone'      => $c->phone,
                // MARKER-BIZ-SEARCH — the register renders `name`/`label`, so
                // a business must present as its business name here or it will
                // show a person's name on the ticket.
                'name'       => $c->fullName(),
                'label'      => $c->fullName(),
                'is_business'=> $c->isBusiness(),
                'tax_exempt' => (bool) $c->tax_exempt,
                'tax_exempt_certificate' => $c->tax_exempt_certificate,
                'po_required'=> (bool) $c->po_required,
                'terms_label'=> $c->termsLabel(),
            ]);

        return response()->json(['customers' => $rows]);
    }

    public function show(Request $request, string $id)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonDetail(tenant(), $id);
        }

        $tenant = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $appointments = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('appointment_date')
            ->with('items')
            ->get();

        // Note: $customer->notes is a fillable string column on TenantCustomer.
        // The relationship is on notes() — call explicitly to get the collection.
        $notes       = $customer->notes()->orderByDesc('created_at')->get();
        // MARKER-PATCH-184F — lifetime spend from the sale payment ledger
        // (payments received, attributed via the sale's customer), not appt totals.
        $totalSpend  = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_sale_payments.tenant_id', $tenant->id)
            ->join('tenant_sales as ts', 'ts.id', '=', 'tenant_sale_payments.sale_id')
            ->where('ts.customer_id', $customer->id)
            ->sum('tenant_sale_payments.amount_cents');
        $lastService = $appointments->where('status', 'completed')->first()?->appointment_date;
        $updateUrl   = route('tenant.customers.update', $customer->id);

        // Unified activity timeline. Service merges appointments, sales,
        // class registrations, and pack/membership grants into a single
        // chronological feed grouped by month.
        $timelineService = app(\App\Services\Tenant\CustomerTimelineService::class);
        $timelineEvents  = $timelineService->buildForCustomer($tenant->id, $customer->id);
        $timelineMonths  = $timelineService->groupByMonth($timelineEvents);
        $timelineCount   = $timelineEvents->count();

        // Memberships & packs — only loaded when the tenant has classes enabled.
        // Saves a query for non-class tenants and prevents UI clutter.
        $customerMemberships = collect();
        $customerPacks       = collect();
        $membershipProducts  = collect();
        $packProducts        = collect();
        if ($tenant->classes_enabled) {
            $customerMemberships = \App\Models\Tenant\TenantCustomerMembership::where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->with('product:id,name,type,monthly_limit,price_cents')
                ->orderByDesc('created_at')
                ->get();
            $customerPacks = \App\Models\Tenant\TenantCustomerPack::where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->with('product:id,name,credit_count,expiry_days,price_cents')
                ->orderByDesc('created_at')
                ->get();
            $membershipProducts = \App\Models\Tenant\TenantClassMembershipProduct::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'monthly_limit', 'price_cents']);
            $packProducts = \App\Models\Tenant\TenantClassPackProduct::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'credit_count', 'expiry_days', 'price_cents']);
        }

        // MARKER-PATCH-158-C — load customer assets when multi_asset is on
        $customerActiveAssets   = collect();
        $customerArchivedAssets = collect();
        if ($tenant->multi_asset_enabled) {
            $allAssets = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->orderBy('created_at')
                ->get();
            $customerActiveAssets   = $allAssets->whereNull('archived_at')->values();
            $customerArchivedAssets = $allAssets->whereNotNull('archived_at')->values();
        }

        // Special orders for this customer (added by patch 88, Stage 5)
        $specialOrdersOpen = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('customer_id', $id)
            ->whereIn('status', \App\Models\Tenant\TenantSpecialOrder::STATUSES_OPEN)
            ->with(['vendor', 'item', 'appointment'])
            ->orderBy('expected_arrival_date')
            ->get();
        $specialOrdersClosed = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('customer_id', $id)
            ->whereIn('status', \App\Models\Tenant\TenantSpecialOrder::STATUSES_CLOSED)
            ->where('updated_at', '>=', now()->subDays(90))
            ->with(['vendor', 'item'])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
        $soVendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // MARKER-BIZ-CONTACTS — the panel reads contacts and the primary;
        // load them once rather than per row.
        if ($customer->isBusiness()) {
            $customer->load('contacts', 'primaryContact');
        }

        return view('tenant.customers.show', compact(
            'customer', 'appointments', 'notes',
            'totalSpend', 'lastService', 'updateUrl',
            'customerMemberships', 'customerPacks',
            'membershipProducts', 'packProducts',
            'timelineMonths', 'timelineCount', 'specialOrdersOpen', 'specialOrdersClosed', 'soVendors',
            'customerActiveAssets', 'customerArchivedAssets')); // MARKER-PATCH-158-C
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('update')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

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

    private function jsonDetail($tenant, string $id)
    {
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $notes = TenantCustomerNote::where('customer_id', $customer->id)->orderByDesc('created_at')->get();

        $appointments = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id) // MARKER-PATCH-421 — stable id, not the mutable email snapshot
            ->orderByDesc('appointment_date')->orderByDesc('created_at')->get();

        // MARKER-PATCH-184F — lifetime spend from the sale payment ledger.
        $totalSpend = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_sale_payments.tenant_id', $tenant->id)
            ->join('tenant_sales as ts', 'ts.id', '=', 'tenant_sale_payments.sale_id')
            ->where('ts.customer_id', $customer->id)
            ->sum('tenant_sale_payments.amount_cents');
        $lastService = $appointments->where('status', 'completed')->max('appointment_date');
        $totalAppts = $appointments->count();

        return response()->json([
            'ok' => true,
            'customer' => [
                'id' => $customer->id, 'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
                'name' => $customer->fullName(), 'email' => $customer->email,
                'phone' => $customer->phone, 'address_line1' => $customer->address_line1,
                'city' => $customer->city, 'state' => $customer->state, 'postcode' => $customer->postcode,
                'country' => $customer->country, 'created_at' => $customer->created_at->format('M j, Y'),
                'total_spend' => format_money($totalSpend),
                'last_service' => $lastService ? \Carbon\Carbon::parse($lastService)->format('M j, Y') : null,
                'total_appts' => $totalAppts,
            ],
            'appointments' => $appointments->take(10)->map(fn($a) => [
                'id' => $a->id, 'ito' => $a->ra_number, 'date' => $a->appointment_date->format('M j, Y'),
                'status' => ucwords(str_replace('_', ' ', $a->status)), 'status_key' => $a->status,
                'payment' => ucfirst($a->payment_status), 'payment_key' => $a->payment_status,
                'total' => format_money($a->total_cents),
            ]),
            'notes' => $notes->map(fn($n) => [
                'id' => $n->id, 'note' => $n->note, 'author' => $n->user?->name ?? 'Staff',
                'created_at' => $n->created_at->format('M j, g:i a'),
            ]),
        ]);
    }

    private function handleUpdate($tenant, string $id, Request $request)
    {
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $op = $request->input('op');

        if ($op === 'update_info') {
            // MARKER-PATCH-423 — capture the pre-edit email; it's the legacy join
            // key for appointment snapshots created before id-linking.
            $oldEmail = $customer->email;
            $data = $this->validated($request, $customer->email);
            $customer->update($data);

            // Propagate the corrected identity onto this customer's appointment
            // snapshots so every snapshot-reading surface (calendar tiles, tags,
            // the drawer fallback) shows current data. Matched by the stable
            // customer_id, plus the old email to catch any pre-id-link rows.
            TenantAppointment::where('tenant_id', $tenant->id)
                ->where(function ($q) use ($customer, $oldEmail) {
                    $q->where('customer_id', $customer->id)
                      ->orWhere('customer_email', $oldEmail);
                })
                ->update([
                    'customer_first_name' => $customer->first_name,
                    'customer_last_name'  => $customer->last_name,
                    'customer_email'      => $customer->email,
                    'customer_phone'      => $customer->phone,
                ]);

            return response()->json(['ok' => true]);
        }
        if ($op === 'toggle_vip') {
            // Toggle is_vip flag. Returns the new state so the UI can render
            // the updated star + badge without a full page reload.
            $customer->is_vip = !$customer->is_vip;
            $customer->save();
            return response()->json(['ok' => true, 'is_vip' => $customer->is_vip]);
        }
        if ($op === 'add_note') {
            $note = mb_substr(trim($request->input('note', '')), 0, 200);
            if (!$note) return response()->json(['ok' => false, 'message' => 'Note is required.'], 422);
            $n = TenantCustomerNote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'user_id' => Auth::guard('tenant')->id(), 'note' => $note, 'created_at' => now()]);
            $user = Auth::guard('tenant')->user();
            return response()->json(['ok' => true, 'id' => $n->id, 'note' => $n->note, 'author' => $user->name, 'created_at' => $n->created_at->format('M j, g:i a')]);
        }
        if ($op === 'delete_note') {
            TenantCustomerNote::where('customer_id', $customer->id)->where('id', $request->input('note_id'))->delete();
            return response()->json(['ok' => true]);
        }
        return response()->json(['ok' => false, 'message' => 'Unknown operation.'], 422);
    }

    /**
     * MARKER-BIZ-CONTACTS — people at a business customer. Kept on this
     * controller rather than a new one: they are part of the customer record,
     * and every action already runs through this controller's tenant scoping.
     */
    public function storeContact(Request $request, string $customerId)
    {
        $tenant   = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->where('id', $customerId)->firstOrFail();

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'role'       => ['nullable', 'string', 'max:64'],
            'email'      => ['nullable', 'email', 'max:191'],
            'phone'      => ['nullable', 'string', 'max:32'],
            'is_primary' => ['nullable'],
        ]);

        $contact = \App\Models\Tenant\TenantCustomerContact::create([
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'name'        => $data['name'],
            'role'        => $data['role'] ?? null,
            'email'       => $data['email'] ?? null,
            'phone'       => \App\Support\PhoneNumber::normalize($data['phone'] ?? null),
            'is_primary'  => false,
        ]);

        // The first contact is primary by definition — otherwise a business
        // would have contacts but no one the app could actually reach.
        $isFirst = $customer->contacts()->count() === 1;
        if ($isFirst || $request->boolean('is_primary')) {
            $contact->makePrimary();
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Contact added.']);
    }

    public function updateContact(Request $request, string $customerId, string $contactId)
    {
        $tenant = tenant();
        TenantCustomer::where('tenant_id', $tenant->id)->where('id', $customerId)->firstOrFail();

        $contact = \App\Models\Tenant\TenantCustomerContact::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where('id', $contactId)
            ->firstOrFail();

        if ($request->input('op') === 'make_primary') {
            $contact->makePrimary();
            return back()->with('flash', ['type' => 'success', 'message' => 'Primary contact updated.']);
        }

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'role'  => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $contact->update([
            'name'  => $data['name'],
            'role'  => $data['role'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => \App\Support\PhoneNumber::normalize($data['phone'] ?? null),
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Contact updated.']);
    }

    public function destroyContact(Request $request, string $customerId, string $contactId)
    {
        $tenant = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->where('id', $customerId)->firstOrFail();

        $contact = \App\Models\Tenant\TenantCustomerContact::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where('id', $contactId)
            ->firstOrFail();

        $wasPrimary = (bool) $contact->is_primary;
        $contact->delete();

        // MARKER-BIZ-CONTACTS — never leave a business with contacts but no
        // primary: promote the next one rather than silently losing the
        // address the app uses.
        if ($wasPrimary) {
            $next = $customer->contacts()->first();
            if ($next) {
                $next->makePrimary();
            }
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Contact removed.']);
    }

    private function validated(Request $request, ?string $existingEmail = null): array
    {
        $emailRules = $existingEmail ? ['nullable','email','max:191'] : ['required','email','max:191'];

        // MARKER-BIZ-CUSTOMER — a business is identified by its business name,
        // so the person's name stops being required there (the contact people
        // live in tenant_customer_contacts). Individuals are unchanged.
        $isBusiness = $request->input('customer_type') === \App\Models\Tenant\TenantCustomer::TYPE_BUSINESS;
        $nameRule   = $isBusiness ? ['nullable', 'string', 'max:100'] : ['required', 'string', 'max:100'];

        $request->validate([
            'customer_type'          => ['nullable', 'in:individual,business'],
            'business_name'          => [$isBusiness ? 'required' : 'nullable', 'string', 'max:191'],
            'tax_exempt_certificate' => ['nullable', 'string', 'max:64'],
            'payment_terms'          => ['nullable', 'in:' . implode(',', \App\Models\Tenant\TenantCustomer::PAYMENT_TERMS)],
            'first_name' => $nameRule, 'last_name' => $nameRule,
            'email' => $emailRules, 'phone' => ['nullable', 'string', 'max:32'],
            'address_line1' => ['nullable', 'string', 'max:191'], 'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:64'], 'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:2'],
        ]);
        $payload = array_filter([
            'first_name' => $request->input('first_name'), 'last_name' => $request->input('last_name'),
            'email' => $request->input('email') ?? $existingEmail, 'phone' => \App\Support\PhoneNumber::normalize($request->input('phone')),
            'address_line1' => $request->input('address_line1'), 'city' => $request->input('city'),
            'state' => $request->input('state'), 'postcode' => $request->input('postcode'),
            'country' => strtoupper($request->input('country', 'US')),
        ], fn($v) => $v !== null && $v !== '');

        // MARKER-BIZ-CUSTOMER — only touch the business fields when the form
        // actually submitted a customer_type. Several edit forms post a subset
        // of fields; without this guard, saving a phone number from one of
        // them would flip a business back to individual and wipe its tax
        // exemption. Absent means "leave as-is", not "individual".
        if (! $request->has('customer_type')) {
            return $payload;
        }

        // Added AFTER the array_filter above: it strips empty values, which
        // would silently discard a false boolean and make "not tax exempt"
        // unsavable once it had ever been true.
        $payload['customer_type'] = $isBusiness
            ? \App\Models\Tenant\TenantCustomer::TYPE_BUSINESS
            : \App\Models\Tenant\TenantCustomer::TYPE_INDIVIDUAL;
        $payload['business_name'] = $isBusiness ? $request->input('business_name') : null;

        if ($isBusiness) {
            $payload['tax_exempt']             = $request->boolean('tax_exempt');
            $payload['tax_exempt_certificate'] = $request->boolean('tax_exempt')
                ? $request->input('tax_exempt_certificate')
                : null;
            $payload['po_required']            = $request->boolean('po_required');
            $payload['payment_terms']          = $request->input('payment_terms') ?: null;
        } else {
            // Switching a record back to individual clears the business-only
            // fields rather than leaving stale exemptions applying to sales.
            $payload['tax_exempt']             = false;
            $payload['tax_exempt_certificate'] = null;
            $payload['po_required']            = false;
            $payload['payment_terms']          = null;
        }

        return $payload;
    }

    /**
     * MARKER-CUST-ACCOUNT — email the customer a link to set (or reset) their
     * portal password. Staff never see or choose the password; this issues the
     * same token the customer-facing reset flow already validates.
     */
    public function sendAccountLink(Request $request, string $id)
    {
        abort_unless(auth('tenant')->user()?->can('customers.account_manage'), 403);

        $tenant   = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $id)->firstOrFail();

        if (blank($customer->email)) {
            return back()->with('error', 'This customer has no email address on file.');
        }

        $isInvite = $customer->password === null;

        $token = \Illuminate\Support\Str::random(64);
        $customer->update([
            'password_reset_token'   => \Illuminate\Support\Facades\Hash::make($token),
            'password_reset_sent_at' => now(),
        ]);

        try {
            \Illuminate\Support\Facades\Mail::to($customer->email)->send(
                $isInvite
                    ? new \App\Mail\CustomerAccountInvite($customer, $token, $tenant)
                    : new \App\Mail\CustomerPasswordReset($customer, $token, $tenant)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('customer account link send failed', [
                'customer_id' => $customer->id, 'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'The email could not be sent — check your email settings and try again.');
        }

        return back()->with('success', $isInvite
            ? 'Account invite sent to ' . $customer->email . '.'
            : 'Password reset link sent to ' . $customer->email . '.');
    }
}
