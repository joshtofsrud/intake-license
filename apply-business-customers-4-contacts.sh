#!/bin/bash
# business-customers-4-contacts — the last surface from the spec.
#   · Contacts panel on the customer record, business customers only —
#     add, edit, remove, and set the primary.
#   · The FIRST contact added is automatically primary: a business with
#     contacts but no primary would leave the app without an address it
#     could actually use.
#   · Removing the primary PROMOTES the next contact rather than silently
#     leaving none.
#   · makePrimary() demotes siblings in a single scoped update, so two people
#     saving at once cannot produce two primaries.
#   · Contacts are eager-loaded for business customers only, so an individual
#     customer page issues no extra queries.
#   All three endpoints re-verify the customer belongs to the tenant AND that
#   the contact belongs to that customer, so a contact id from another
#   account cannot be edited or deleted through a valid customer id.
# ADDS THREE ROUTES, inserted surgically with an abort if the anchor moved.
# Server: route:clear + route:cache, view:clear. No migration (the table
# shipped in phase 1).
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-BIZ-CONTACTS" app/Http/Controllers/Tenant/CustomerController.php; then
  echo "phase 4 already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-BIZ-RECEIPT" resources/views/tenant/register/receipt.blade.php; then
  echo "phase 3 not applied — wrong base, aborting."; exit 1
fi

python3 - <<'PYROUTE'
s = open('routes/web.php').read()
if 'customers.contacts' in s:
    print('routes already present')
else:
    old = "            Route::post('/customers/{customerId}/assets',                  [TenantControllers\\CustomerAssetsController::class, 'store'])->name('customers.assets.store');"
    assert s.count(old) == 1, 'customer assets route anchor not found — routes/web.php differs, aborting'
    add = ("            // MARKER-BIZ-CONTACTS — people at a business customer" + chr(10)
         + "            Route::post('/customers/{customerId}/contacts',                [TenantControllers\\CustomerController::class, 'storeContact'])->name('customers.contacts.store');" + chr(10)
         + "            Route::patch('/customers/{customerId}/contacts/{contactId}',   [TenantControllers\\CustomerController::class, 'updateContact'])->name('customers.contacts.update');" + chr(10)
         + "            Route::delete('/customers/{customerId}/contacts/{contactId}',  [TenantControllers\\CustomerController::class, 'destroyContact'])->name('customers.contacts.destroy');" + chr(10))
    open('routes/web.php', 'w').write(s.replace(old, add + old))
    print('routes inserted')
PYROUTE

cat > 'app/Http/Controllers/Tenant/CustomerController.php' <<'BIZ4_0_EOF'
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
}
BIZ4_0_EOF

cat > 'resources/views/tenant/customers/customer-show.blade.php' <<'BIZ4_1_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle  = $customer->fullName();
  $updateUrl  = route('tenant.customers.update', $customer->id);
@endphp

@push('styles')
<style>
.cust-layout { display: grid; grid-template-columns: 1fr 280px; gap: 20px; align-items: start; }
.cust-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; }
.cust-field-label { font-size: 11px; opacity: .4; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.cust-field-value { font-size: 13px; }
.cust-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.cust-stat:last-child { border-bottom: none; }
.cust-stat-label { opacity: .5; }
.cust-stat-value { font-weight: 500; }
.appt-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); cursor: pointer; transition: opacity .12s; }
.appt-row:last-child { border-bottom: none; }
.appt-row:hover { opacity: .75; }
.appt-row-main { flex: 1; }
.appt-row-ra { font-size: 13px; font-weight: 500; }
.appt-row-date { font-size: 12px; opacity: .45; margin-top: 1px; }

/* Memberships & Packs card */
.cust-mp-row { display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--ia-surface-2); border-radius: 6px; border: 0.5px solid var(--ia-border); }
.cust-mp-row--history { opacity: .55; padding: 6px 10px; background: transparent; border: 0; border-bottom: 0.5px solid var(--ia-border); border-radius: 0; }
.cust-mp-row--history:last-child { border-bottom: none; }
.cust-mp-row-main { flex: 1; min-width: 0; }
.cust-mp-row-title { font-size: 13px; font-weight: 500; }
.cust-mp-row-sub { font-size: 12px; color: var(--ia-text-muted); margin-top: 2px; }
.cust-mp-bar { height: 4px; background: var(--ia-border); border-radius: 2px; margin-top: 6px; overflow: hidden; }
.cust-mp-bar-fill { height: 100%; background: var(--ia-accent); border-radius: 2px; transition: width .3s; }

/* Grant modal */
.cust-mp-modal { position: fixed; inset: 0; background: rgba(0,0,0,.65); z-index: 1000; display: none; align-items: center; justify-content: center; }
.cust-mp-modal.is-open { display: flex; }
.cust-mp-modal-inner { background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: 10px; padding: 20px; max-width: 480px; width: 92%; }
.cust-mp-modal-title { font-size: 16px; font-weight: 600; margin-bottom: 6px; }
.cust-mp-modal-sub { font-size: 12px; color: var(--ia-text-muted); margin-bottom: 16px; }
.cust-mp-product-list { display: flex; flex-direction: column; gap: 6px; max-height: 280px; overflow-y: auto; margin-bottom: 12px; }
.cust-mp-product { display: flex; align-items: center; padding: 10px 12px; background: var(--ia-surface-2); border: 0.5px solid var(--ia-border); border-radius: 6px; cursor: pointer; transition: all var(--ia-t); }
.cust-mp-product:hover { border-color: var(--ia-border-strong); }
.cust-mp-product.is-selected { border-color: var(--ia-accent); background: var(--ia-accent-soft); }
.cust-mp-product-main { flex: 1; }
.cust-mp-product-name { font-size: 13px; font-weight: 500; }
.cust-mp-product-meta { font-size: 11px; color: var(--ia-text-muted); margin-top: 2px; }
.cust-mp-product-price { font-size: 13px; font-weight: 500; }

@media (max-width: 900px) {
  .cust-layout { grid-template-columns: 1fr; }
  .cust-info-grid { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')

{{-- Header --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">Customer</div>
    <h1 class="ia-page-title">{{ $customer->fullName() }}</h1>
    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>

<div class="cust-layout">

  {{-- ============================================================
       Left: info card + work orders
       ============================================================ --}}
  <div style="display:flex;flex-direction:column;gap:20px">

    {{-- Info card --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Customer info</span>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="edit-toggle">Edit</button>
      </div>

      {{-- View mode --}}
      <div id="info-view">
        <div class="cust-info-grid">
          <div>
            <div class="cust-field-label">Name</div>
            <div class="cust-field-value">{{ $customer->fullName() }}</div>
          </div>
          <div>
            <div class="cust-field-label">Email</div>
            <div class="cust-field-value">{{ $customer->email }}</div>
          </div>
          <div>
            <div class="cust-field-label">Phone</div>
            <div class="cust-field-value">{{ $customer->phone ?: '—' }}</div>
          </div>
          <div>
            <div class="cust-field-label">Address</div>
            <div class="cust-field-value">
              @php
                $addr = array_filter([$customer->address_line1, $customer->city, $customer->state, $customer->postcode]);
              @endphp
              {{ $addr ? implode(', ', $addr) : '—' }}
            </div>
          </div>
        </div>
      </div>

      {{-- Edit mode --}}
      <form method="POST" action="{{ $updateUrl }}" id="info-edit" style="display:none">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="update_info">

        <div class="ia-input-grid-2" style="margin-bottom:12px">
          <div class="ia-form-group">
            <label class="ia-form-label">First name <span class="ia-required">*</span></label>
            <input type="text" name="first_name" class="ia-input" required value="{{ $customer->first_name }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Last name <span class="ia-required">*</span></label>
            <input type="text" name="last_name" class="ia-input" required value="{{ $customer->last_name }}">
          </div>
        </div>
        <div class="ia-input-grid-2" style="margin-bottom:12px">
          <div class="ia-form-group">
            <label class="ia-form-label">Email</label>
            <input type="email" name="email" class="ia-input" value="{{ $customer->email }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Phone</label>
            <input type="tel" name="phone" class="ia-input" value="{{ $customer->phone }}">
          </div>
        </div>
        <div class="ia-form-group" style="margin-bottom:12px">
          <label class="ia-form-label">Street address</label>
          <input type="text" name="address_line1" class="ia-input" value="{{ $customer->address_line1 }}">
        </div>
        <div class="ia-input-grid-3" style="margin-bottom:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">City</label>
            <input type="text" name="city" class="ia-input" value="{{ $customer->city }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">State</label>
            <input type="text" name="state" class="ia-input" value="{{ $customer->state }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">ZIP</label>
            <input type="text" name="postcode" class="ia-input" value="{{ $customer->postcode }}">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save changes</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="edit-cancel">Cancel</button>
        </div>
      </form>
    </div>

    {{-- Memberships & Packs (classes-enabled tenants only) --}}
    @if($currentTenant->classes_enabled)
      @php
        $activeMembership = $customerMemberships->where('status', 'active')->first();
        $activePacks      = $customerPacks->where('status', 'active');
        $historyMemberships = $customerMemberships->where('status', '!=', 'active');
        $historyPacks       = $customerPacks->where('status', '!=', 'active');
      @endphp
      {{-- MARKER-BIZ-CONTACTS — only for businesses: an individual customer
           has no separate people to keep track of. --}}
      @if($customer->isBusiness())
        @php $bizContacts = $customer->contacts; @endphp
        <div class="ia-card" id="cust-contacts-card">
          <div class="ia-card-head">
            <span class="ia-card-title">Contacts</span>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="document.getElementById('biz-add-contact').style.display='';this.style.display='none'">+ Add contact</button>
          </div>

          @if($bizContacts->isEmpty())
            <p style="font-size:13px;opacity:.6;margin:4px 0 12px">
              No contacts yet. The first one you add becomes the primary — the person Intake uses for this customer.
            </p>
          @else
            <div class="biz-contacts">
              @foreach($bizContacts as $bc)
                <div class="biz-contact">
                  <div class="biz-contact-main">
                    <div class="biz-contact-name">
                      {{ $bc->name }}
                      @if($bc->is_primary)<span class="biz-pill primary">Primary</span>@endif
                    </div>
                    <div class="biz-contact-meta">
                      @if($bc->role){{ $bc->role }}@endif
                      @if($bc->email) · <a href="mailto:{{ $bc->email }}">{{ $bc->email }}</a>@endif
                      @if($bc->phone) · <a href="tel:{{ $bc->phone }}">{{ $bc->phone }}</a>@endif
                    </div>
                  </div>
                  <div class="biz-contact-acts">
                    @unless($bc->is_primary)
                      <form method="POST" action="{{ route('tenant.customers.contacts.update', ['customerId' => $customer->id, 'contactId' => $bc->id]) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="op" value="make_primary">
                        <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Make primary</button>
                      </form>
                    @endunless
                    <form method="POST" action="{{ route('tenant.customers.contacts.destroy', ['customerId' => $customer->id, 'contactId' => $bc->id]) }}"
                          onsubmit="return confirm('Remove {{ addslashes($bc->name) }}?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#F09595">Remove</button>
                    </form>
                  </div>
                </div>
              @endforeach
            </div>
          @endif

          <form method="POST" action="{{ route('tenant.customers.contacts.store', ['customerId' => $customer->id]) }}"
                id="biz-add-contact" style="{{ $bizContacts->isEmpty() ? '' : 'display:none' }};margin-top:12px">
            @csrf
            <div class="ia-input-grid-2">
              <div class="ia-form-group">
                <label class="ia-form-label">Name <span class="ia-required">*</span></label>
                <input type="text" name="name" class="ia-input" required>
              </div>
              <div class="ia-form-group">
                <label class="ia-form-label">Role</label>
                <input type="text" name="role" class="ia-input" placeholder="Fleet manager">
              </div>
            </div>
            <div class="ia-input-grid-2">
              <div class="ia-form-group">
                <label class="ia-form-label">Email</label>
                <input type="email" name="email" class="ia-input">
              </div>
              <div class="ia-form-group">
                <label class="ia-form-label">Phone</label>
                <input type="tel" name="phone" class="ia-input">
              </div>
            </div>
            @unless($bizContacts->isEmpty())
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:10px;cursor:pointer">
                <input type="checkbox" name="is_primary" value="1">
                <span>Make this the primary contact</span>
              </label>
            @endunless
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Add contact</button>
          </form>
        </div>
      @endif

      <div class="ia-card" id="cust-mp-card">
        <div class="ia-card-head">
          <span class="ia-card-title">Memberships &amp; Packs</span>
          <div style="display:flex;gap:6px">
            @if(!$activeMembership && $membershipProducts->isNotEmpty())
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="openGrantModal('membership')">+ Grant membership</button>
            @endif
            @if($packProducts->isNotEmpty())
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="openGrantModal('pack')">+ Grant pack</button>
            @endif
          </div>
        </div>

        @if(!$activeMembership && $activePacks->isEmpty() && $historyMemberships->isEmpty() && $historyPacks->isEmpty())
          <p style="font-size:13px;opacity:.5">No memberships or packs yet.</p>
        @else
          {{-- Active items --}}
          <div style="display:flex;flex-direction:column;gap:8px">
            @if($activeMembership)
              <div class="cust-mp-row" data-mp-id="{{ $activeMembership->id }}" data-mp-kind="membership">
                <div class="cust-mp-row-main">
                  <div class="cust-mp-row-title">{{ $activeMembership->product?->name ?? 'Membership' }}</div>
                  <div class="cust-mp-row-sub">
                    @if($activeMembership->product?->type === 'unlimited')
                      Unlimited · used {{ $activeMembership->classes_used_this_period }} this period
                    @else
                      {{ $activeMembership->classes_used_this_period }} / {{ $activeMembership->product?->monthly_limit ?? '?' }} used this period
                    @endif
                    · renews {{ $activeMembership->current_period_end?->format('M j, Y') }}
                  </div>
                </div>
                <span class="ia-badge ia-badge--confirmed">Active</span>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="revokeMP('membership','{{ $activeMembership->id }}')">Cancel</button>
              </div>
            @endif

            @foreach($activePacks as $pack)
              @php
                $pct = $pack->credits_total > 0 ? round(($pack->credits_remaining / $pack->credits_total) * 100) : 0;
              @endphp
              <div class="cust-mp-row" data-mp-id="{{ $pack->id }}" data-mp-kind="pack">
                <div class="cust-mp-row-main">
                  <div class="cust-mp-row-title">{{ $pack->product?->name ?? 'Pack' }}</div>
                  <div class="cust-mp-row-sub">
                    {{ $pack->credits_remaining }} of {{ $pack->credits_total }} credits left ·
                    expires {{ $pack->expires_at?->format('M j, Y') }}
                  </div>
                  <div class="cust-mp-bar"><div class="cust-mp-bar-fill" style="width:{{ $pct }}%"></div></div>
                </div>
                <span class="ia-badge ia-badge--confirmed">Active</span>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="revokeMP('pack','{{ $pack->id }}')">Cancel</button>
              </div>
            @endforeach
          </div>

          {{-- History --}}
          @if($historyMemberships->isNotEmpty() || $historyPacks->isNotEmpty())
            <details style="margin-top:12px">
              <summary style="cursor:pointer;font-size:12px;color:var(--ia-text-muted)">History</summary>
              <div style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
                @foreach($historyMemberships as $m)
                  <div class="cust-mp-row cust-mp-row--history">
                    <div class="cust-mp-row-main">
                      <div class="cust-mp-row-title">{{ $m->product?->name ?? 'Membership' }}</div>
                      <div class="cust-mp-row-sub">
                        Status: {{ ucfirst($m->status) }}
                        @if($m->current_period_end) · ended {{ $m->current_period_end->format('M j, Y') }} @endif
                      </div>
                    </div>
                  </div>
                @endforeach
                @foreach($historyPacks as $p)
                  <div class="cust-mp-row cust-mp-row--history">
                    <div class="cust-mp-row-main">
                      <div class="cust-mp-row-title">{{ $p->product?->name ?? 'Pack' }}</div>
                      <div class="cust-mp-row-sub">
                        Status: {{ ucfirst($p->status) }} · {{ $p->credits_remaining }} credits remained
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>
            </details>
          @endif
        @endif
      </div>
    @endif

    {{-- Work orders --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Work orders</span>
        <span style="font-size:12px;opacity:.4">{{ $appointments->count() }}</span>
      </div>

      @if($appointments->isEmpty())
        <p style="font-size:13px;opacity:.4">No appointments yet.</p>
      @else
        @foreach($appointments as $appt)
          <div class="appt-row"
            onclick="window.location='{{ route('tenant.appointments.show', $appt->id) }}'">
            <div class="appt-row-main">
              <div class="appt-row-ra">{{ $appt->ra_number }}</div>
              <div class="appt-row-date">{{ $appt->appointment_date->format('M j, Y') }}</div>
            </div>
            <span class="ia-badge ia-badge--{{ str_replace('_','-',$appt->status) }}">
              {{ ucwords(str_replace('_',' ',$appt->status)) }}
            </span>
            <span class="ia-badge ia-badge--{{ $appt->payment_status }}">
              {{ ucfirst($appt->payment_status) }}
            </span>
            <div style="font-size:13px;font-weight:500;min-width:60px;text-align:right">
              {{ format_money($appt->total_cents) }}
            </div>
          </div>
        @endforeach
      @endif
    </div>

  </div>

  {{-- ============================================================
       Right: stats + notes
       ============================================================ --}}
  <div style="display:flex;flex-direction:column;gap:16px">

    {{-- Stats --}}
    <div class="ia-card ia-card--tight">
      <div class="cust-stat">
        <span class="cust-stat-label">Total spend</span>
        <span class="cust-stat-value">{{ format_money((int)$totalSpend) }}</span>
      </div>
      <div class="cust-stat">
        <span class="cust-stat-label">Work orders</span>
        <span class="cust-stat-value">{{ $appointments->count() }}</span>
      </div>
      <div class="cust-stat">
        <span class="cust-stat-label">Last service</span>
        <span class="cust-stat-value">
          {{ $lastService ? \Carbon\Carbon::parse($lastService)->format('M j, Y') : '—' }}
        </span>
      </div>
      <div class="cust-stat">
        <span class="cust-stat-label">Customer since</span>
        <span class="cust-stat-value">{{ $customer->created_at->format('M j, Y') }}</span>
      </div>
    </div>

    {{-- Notes --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Notes
      </div>

      {{-- Add note --}}
      <div class="ia-note-add">
        <textarea id="cust-note-input" rows="3" maxlength="200"
          data-maxlength="200" data-counter="cust-note-chars"
          placeholder="Add a note… (200 chars max)"
          style="width:100%;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);padding:8px 10px;font-size:13px;resize:none;font-family:var(--ia-font)"></textarea>
        <div class="ia-note-add-footer">
          <span class="ia-char-count" id="cust-note-chars">200</span>
          <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="cust-note-submit"
            data-url="{{ $updateUrl }}">
            Add note
          </button>
        </div>
        <p id="cust-note-error" style="font-size:12px;color:#E24B4A;margin-top:4px;display:none"></p>
      </div>

      {{-- Notes list --}}
      <div class="ia-notes" id="cust-notes-list">
        @forelse($notes as $note)
          <div class="ia-note" data-note-id="{{ $note->id }}">
            <div class="ia-note-head">
              <span class="ia-note-author">{{ $note->user?->name ?? 'Staff' }}</span>
              <span class="ia-note-time">
                {{ \Carbon\Carbon::parse($note->created_at)->format('M j, g:i a') }}
              </span>
              <button type="button" class="ia-note-delete"
                data-note-id="{{ $note->id }}" title="Delete">&#x2715;</button>
            </div>
            <div class="ia-note-body">{{ $note->note }}</div>
          </div>
        @empty
          <p class="ia-notes-empty" style="font-size:13px;opacity:.4">No notes yet.</p>
        @endforelse
      </div>
    </div>

  </div>

</div>

@if($currentTenant->classes_enabled)
  <div class="cust-mp-modal" id="cust-mp-modal"
       data-grant-membership-url="{{ route('tenant.customers.memberships.grant', ['customerId' => $customer->id]) }}"
       data-grant-pack-url="{{ route('tenant.customers.packs.grant', ['customerId' => $customer->id]) }}"
       data-revoke-membership-url-tpl="{{ route('tenant.customers.memberships.revoke', ['customerId' => $customer->id, 'id' => '__ID__']) }}"
       data-revoke-pack-url-tpl="{{ route('tenant.customers.packs.revoke', ['customerId' => $customer->id, 'id' => '__ID__']) }}">
    <div class="cust-mp-modal-inner">
      <div class="cust-mp-modal-title" id="cust-mp-modal-title">Grant membership</div>
      <div class="cust-mp-modal-sub" id="cust-mp-modal-sub">Pick a product to assign to this customer.</div>

      <div class="cust-mp-product-list" id="cust-mp-product-list">
        {{-- Membership options --}}
        @foreach($membershipProducts as $p)
          <div class="cust-mp-product" data-kind="membership" data-id="{{ $p->id }}">
            <div class="cust-mp-product-main">
              <div class="cust-mp-product-name">{{ $p->name }}</div>
              <div class="cust-mp-product-meta">
                @if($p->type === 'unlimited')
                  Unlimited classes / month
                @else
                  {{ $p->monthly_limit }} classes / month
                @endif
              </div>
            </div>
            <div class="cust-mp-product-price">{{ format_money($p->price_cents) }}/mo</div>
          </div>
        @endforeach
        {{-- Pack options --}}
        @foreach($packProducts as $p)
          <div class="cust-mp-product" data-kind="pack" data-id="{{ $p->id }}" hidden>
            <div class="cust-mp-product-main">
              <div class="cust-mp-product-name">{{ $p->name }}</div>
              <div class="cust-mp-product-meta">
                {{ $p->credit_count }} credits · expires after {{ $p->expiry_days }} days
              </div>
            </div>
            <div class="cust-mp-product-price">{{ format_money($p->price_cents) }}</div>
          </div>
        @endforeach
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin-bottom:4px">Note (optional)</label>
        <input type="text" id="cust-mp-modal-note" class="ia-input" placeholder="e.g. Comp for referral, manager comp, etc.">
      </div>

      <div id="cust-mp-modal-error" style="display:none;color:#EF4444;font-size:12px;margin-bottom:10px"></div>

      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeGrantModal()">Cancel</button>
        <button type="button" class="ia-btn ia-btn--primary" id="cust-mp-modal-grant" onclick="confirmGrant()" disabled>Grant</button>
      </div>
    </div>
  </div>
@endif

{{-- MARKER-BIZ-CONTACTS --}}
<style>
  .biz-contacts{display:flex;flex-direction:column;gap:8px}
  .biz-contact{display:flex;align-items:center;gap:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:11px 13px;flex-wrap:wrap}
  .biz-contact-main{flex:1;min-width:180px}
  .biz-contact-name{font-weight:600;font-size:13.5px}
  .biz-contact-meta{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
  .biz-contact-acts{display:flex;gap:6px;flex:none}
  .biz-contact-acts form{margin:0}
  .biz-pill{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border-radius:100px;padding:2px 7px;margin-left:6px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);vertical-align:1px}
  .biz-pill.primary{border-color:color-mix(in srgb, var(--ia-accent) 45%, transparent);color:var(--ia-accent)}
</style>

@endsection

@push('scripts')
<script>
(function () {
  var updateUrl = '{{ $updateUrl }}';
  var csrf      = window.IntakeAdmin.csrfToken;

  // Edit toggle
  var editToggle  = document.getElementById('edit-toggle');
  var editCancel  = document.getElementById('edit-cancel');
  var infoView    = document.getElementById('info-view');
  var infoEdit    = document.getElementById('info-edit');

  if (editToggle) editToggle.addEventListener('click', function () {
    infoView.style.display = 'none';
    infoEdit.style.display = '';
    editToggle.style.display = 'none';
  });
  if (editCancel) editCancel.addEventListener('click', function () {
    infoEdit.style.display = 'none';
    infoView.style.display = '';
    editToggle.style.display = '';
  });

  // AJAX-ify the info edit form so the browser doesn't navigate to JSON.
  if (infoEdit) {
    infoEdit.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(infoEdit);
      var submitBtn = infoEdit.querySelector('button[type="submit"]');
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }

      fetch(infoEdit.action, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Save changes'; }
          if (res.ok && res.body && (res.body.ok || res.body.success)) {
            window.IntakeToast.success('Customer updated');
            setTimeout(function () { window.location.reload(); }, 600);
          } else {
            window.IntakeToast.error((res.body && res.body.message) || 'Could not save.');
          }
        })
        .catch(function () {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Save changes'; }
          window.IntakeToast.error('Network error. Try again.');
        });
    });
  }

  // Note add
  var noteInput  = document.getElementById('cust-note-input');
  var noteSubmit = document.getElementById('cust-note-submit');
  var noteError  = document.getElementById('cust-note-error');
  var notesList  = document.getElementById('cust-notes-list');
  var noteChars  = document.getElementById('cust-note-chars');

  if (noteInput && noteChars) {
    noteInput.addEventListener('input', function () {
      var rem = 200 - noteInput.value.length;
      noteChars.textContent = rem;
      noteChars.classList.toggle('warn', rem <= 20);
    });
  }

  if (noteSubmit) noteSubmit.addEventListener('click', function () {
    var note = noteInput.value.trim();
    if (!note) { show(noteError, 'Please enter a note.'); return; }
    noteSubmit.disabled = true; noteSubmit.textContent = 'Saving…';

    post({ op: 'add_note', note: note }, function (resp) {
      noteSubmit.disabled = false; noteSubmit.textContent = 'Add note';
      if (!resp.ok) { show(noteError, resp.message || 'Error.'); return; }
      hide(noteError);
      var empty = notesList.querySelector('.ia-notes-empty');
      if (empty) empty.remove();
      var el = document.createElement('div');
      el.className = 'ia-note'; el.setAttribute('data-note-id', resp.id);
      el.innerHTML =
        '<div class="ia-note-head">' +
          '<span class="ia-note-author">' + esc(resp.author) + '</span>' +
          '<span class="ia-note-time">' + esc(resp.created_at) + '</span>' +
          '<button type="button" class="ia-note-delete" data-note-id="' + resp.id + '" title="Delete">&#x2715;</button>' +
        '</div><div class="ia-note-body">' + esc(resp.note) + '</div>';
      notesList.insertBefore(el, notesList.firstChild);
      bindDel(el.querySelector('.ia-note-delete'));
      noteInput.value = '';
      if (noteChars) { noteChars.textContent = '200'; noteChars.classList.remove('warn'); }
    });
  });

  // Note delete
  document.querySelectorAll('.ia-note-delete').forEach(bindDel);

  function bindDel(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm('Delete this note?')) return;
      var noteId = btn.getAttribute('data-note-id');
      post({ op: 'delete_note', note_id: noteId }, function (resp) {
        if (!resp.ok) return;
        var el = document.querySelector('[data-note-id="' + noteId + '"]');
        if (el) el.remove();
        if (!notesList.querySelector('.ia-note')) {
          var p = document.createElement('p');
          p.className = 'ia-notes-empty';
          p.style.cssText = 'font-size:13px;opacity:.4';
          p.textContent = 'No notes yet.';
          notesList.appendChild(p);
        }
      });
    });
  }

  function post(data, cb) {
    var fd = new FormData();
    fd.append('_token', csrf); fd.append('_method', 'PATCH');
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(cb)
      .catch(function () { show(noteError, 'Network error.'); });
  }
  function show(el, msg) { if (el) { el.textContent = msg; el.style.display = ''; } }
  function hide(el)       { if (el) el.style.display = 'none'; }
  function esc(s)         { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
}());

/**
 * Grant/revoke membership and pack flow. Lives outside the IIFE above so the
 * inline onclick handlers in the blade can reach these globals. Modal toggles
 * which kind (membership/pack) is selectable.
 */
(function () {
  var modal = document.getElementById('cust-mp-modal');
  if (!modal) return; // tenant doesn't have classes enabled

  var titleEl   = document.getElementById('cust-mp-modal-title');
  var subEl     = document.getElementById('cust-mp-modal-sub');
  var listEl    = document.getElementById('cust-mp-product-list');
  var noteEl    = document.getElementById('cust-mp-modal-note');
  var errEl     = document.getElementById('cust-mp-modal-error');
  var grantBtn  = document.getElementById('cust-mp-modal-grant');
  var grantMembershipUrl  = modal.dataset.grantMembershipUrl;
  var grantPackUrl        = modal.dataset.grantPackUrl;
  var revokeMembershipTpl = modal.dataset.revokeMembershipUrlTpl;
  var revokePackTpl       = modal.dataset.revokePackUrlTpl;
  var csrf       = window.IntakeAdmin.csrfToken;

  var currentKind = null;
  var selectedId  = null;

  window.openGrantModal = function (kind) {
    currentKind = kind;
    selectedId  = null;
    titleEl.textContent = kind === 'membership' ? 'Grant membership' : 'Grant pack';
    subEl.textContent   = kind === 'membership'
      ? 'Pick a membership tier to assign. Period starts today.'
      : 'Pick a pack to assign. Credits available immediately, expiry counts from today.';
    noteEl.value = '';
    errEl.style.display = 'none';
    grantBtn.disabled = true;

    // Show only the relevant kind in the product list
    listEl.querySelectorAll('.cust-mp-product').forEach(function (row) {
      var match = row.dataset.kind === kind;
      row.hidden = !match;
      row.classList.remove('is-selected');
    });
    modal.classList.add('is-open');
  };

  window.closeGrantModal = function () {
    modal.classList.remove('is-open');
  };

  // Click product → select
  listEl.addEventListener('click', function (e) {
    var row = e.target.closest('.cust-mp-product');
    if (!row || row.dataset.kind !== currentKind) return;
    listEl.querySelectorAll('.cust-mp-product').forEach(function (r) { r.classList.remove('is-selected'); });
    row.classList.add('is-selected');
    selectedId = row.dataset.id;
    grantBtn.disabled = false;
  });

  // Click outside / Esc closes
  modal.addEventListener('click', function (e) {
    if (e.target === modal) window.closeGrantModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) window.closeGrantModal();
  });

  window.confirmGrant = function () {
    if (!selectedId || !currentKind) return;
    grantBtn.disabled = true;
    grantBtn.textContent = 'Granting…';
    errEl.style.display = 'none';

    var path = currentKind === 'membership' ? 'memberships' : 'packs';
    var url = currentKind === 'membership' ? grantMembershipUrl : grantPackUrl;

    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('product_id', selectedId);
    fd.append('note', noteEl.value || '');

    fetch(url, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json().then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
      })
      .then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          // Reload to reflect the new state. Cheaper than rebuilding card client-side.
          window.location.reload();
        } else {
          errEl.textContent = (res.body && res.body.message) || 'Grant failed.';
          errEl.style.display = '';
          grantBtn.disabled = false;
          grantBtn.textContent = 'Grant';
        }
      })
      .catch(function () {
        errEl.textContent = 'Network error. Try again.';
        errEl.style.display = '';
        grantBtn.disabled = false;
        grantBtn.textContent = 'Grant';
      });
  };

  /**
   * Revoke flow — uses the app's confirm modal, then DELETEs. Audit note is
   * written server-side automatically. Reloads page on success to show the
   * updated state (history entry appears, active row removed).
   */
  window.revokeMP = function (kind, id) {
    var label = kind === 'membership' ? 'membership' : 'pack';
    var title = kind === 'membership' ? 'Cancel membership?' : 'Cancel pack?';
    var message = kind === 'membership'
      ? 'This will deactivate the membership immediately. The customer loses access to their classes-included tier. An audit note is added to the customer record.'
      : 'This will deactivate the pack and forfeit any remaining credits. An audit note is added to the customer record.';

    window.IntakeConfirm.show({
      title: title,
      message: message,
      confirmText: 'Cancel ' + label,
      cancelText: 'Keep it',
      danger: true,
    }).then(function (ok) {
      if (!ok) return;

      var tpl  = kind === 'membership' ? revokeMembershipTpl : revokePackTpl;
      var url  = tpl.replace('__ID__', id);

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'DELETE');

      fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin',
      })
        .then(function (r) {
          return r.json().then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
        })
        .then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            window.location.reload();
          } else {
            window.IntakeConfirm.show({
              title: 'Cancel failed',
              message: (res.body && res.body.message) || 'Something went wrong. Please try again.',
              confirmText: 'OK',
              cancelText: '',
            });
          }
        })
        .catch(function () {
          window.IntakeConfirm.show({
            title: 'Network error',
            message: 'Could not reach the server. Try again.',
            confirmText: 'OK',
            cancelText: '',
          });
        });
    });
  };
})();
</script>
@endpush
BIZ4_1_EOF

echo "business-customers-4-contacts applied — server: git pull && php artisan route:clear && php artisan route:cache && php artisan view:clear"
