#!/bin/bash
# business-customers-1-data — phase 1 of 3: identity, data and the form.
#   · customer_type (defaults to individual, so every existing record and
#     query behaves exactly as today) + business_name, tax_exempt,
#     tax_exempt_certificate, payment_terms, po_required on tenant_customers
#   · tenant_customer_contacts table — the fleet manager, the rider and
#     accounts payable are three people; one email field cannot hold them.
#     makePrimary() demotes siblings in a scoped update so a customer can
#     never end up with two primaries or none.
#   · tax_exempt_applied / tax_exempt_certificate / po_number on tenant_sales,
#     so an exempt sale is auditable and a later customer edit cannot rewrite
#     what was true at the time of sale
#   · fullName() becomes the single display name: business name for a
#     business, unchanged for an individual. personName() added for the places
#     that genuinely mean the human.
#   · Customer create form and edit drawer get an Individual/Business toggle
#     that reveals the business fields; the individual path is untouched.
#   TWO TRAPS HANDLED EXPLICITLY:
#   1. validated() runs array_filter, which strips empty values — a false
#      boolean would have been discarded, making "not tax exempt" unsavable
#      once it had ever been true. The business fields are applied after it.
#   2. Several edit forms post only a subset of fields. Without a guard,
#      saving a phone number from one of them would flip a business back to
#      individual and wipe its exemption. An absent customer_type now means
#      "leave as-is", never "individual".
#   Person-name requirement relaxes for businesses on both client and server,
#   matching rules exactly.
# No routes. Server: MIGRATION REQUIRED, then view:clear.
# NEXT: phase 2 (tax + register), phase 3 (contacts panel, work order,
# receipts, list, search, settings defaults) — the display pass across the
# 26 files that concatenate names inline ships with phase 3.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-BIZ-CUSTOMER" app/Models/Tenant/TenantCustomer.php; then
  echo "business-customers-1-data already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-PARTGONE" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "wrong base — aborting."; exit 1
fi

cat > 'database/migrations/2026_07_24_000001_add_business_fields_to_tenant_customers.php' <<'BIZ1_0_EOF'
<?php

// MARKER-BIZ-CUSTOMER — business customers live on the customer record rather
// than a separate entity: a business still has assets, appointments, sales,
// history and a login, and splitting it would fork every query in the app.
// customer_type defaults to 'individual', so every existing record and every
// existing query behaves exactly as it does today.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $t) {
            $t->string('customer_type', 16)->default('individual')->index();
            $t->string('business_name', 191)->nullable();
            $t->boolean('tax_exempt')->default(false);
            $t->string('tax_exempt_certificate', 64)->nullable();
            // due_now (today's behaviour) | net_15 | net_30 | net_60
            $t->string('payment_terms', 16)->nullable();
            $t->boolean('po_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $t) {
            $t->dropColumn([
                'customer_type', 'business_name', 'tax_exempt',
                'tax_exempt_certificate', 'payment_terms', 'po_required',
            ]);
        });
    }
};
BIZ1_0_EOF

cat > 'database/migrations/2026_07_24_000002_create_tenant_customer_contacts_table.php' <<'BIZ1_1_EOF'
<?php

// MARKER-BIZ-CUSTOMER — the fleet manager who books, the rider who drops off,
// and accounts payable are three different people. One email field on the
// customer cannot hold them.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('role', 64)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'customer_id']);
            $t->index(['customer_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_contacts');
    }
};
BIZ1_1_EOF

cat > 'database/migrations/2026_07_24_000003_add_tax_exempt_and_po_to_tenant_sales.php' <<'BIZ1_2_EOF'
<?php

// MARKER-BIZ-CUSTOMER — the certificate is snapshotted onto the sale so a
// later edit to the customer cannot rewrite what was true at the time of
// sale, which is the whole point of an audit trail.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->boolean('tax_exempt_applied')->default(false);
            $t->string('tax_exempt_certificate', 64)->nullable();
            $t->string('po_number', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropColumn(['tax_exempt_applied', 'tax_exempt_certificate', 'po_number']);
        });
    }
};
BIZ1_2_EOF

cat > 'app/Models/Tenant/TenantCustomerContact.php' <<'BIZ1_3_EOF'
<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-BIZ-CUSTOMER — a person at a business customer. Exactly one contact
 * per customer is primary; the primary is what the app uses wherever it needs
 * a single email or phone for a business.
 */
class TenantCustomerContact extends Model
{
    use HasUuids;

    protected $table = 'tenant_customer_contacts';

    protected $fillable = [
        'tenant_id', 'customer_id',
        'name', 'role', 'email', 'phone',
        'is_primary', 'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    /**
     * Make this contact the only primary for its customer. Done as a pair of
     * scoped updates rather than a loop so two people saving at once cannot
     * leave a customer with two primaries or none.
     */
    public function makePrimary(): void
    {
        static::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->forceFill(['is_primary' => true])->save();
    }
}
BIZ1_3_EOF

cat > 'app/Models/Tenant/TenantCustomer.php' <<'BIZ1_4_EOF'
<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Tenant;

class TenantCustomer extends Authenticatable
{
    use HasUuids, Notifiable;

    protected $table    = 'tenant_customers';
    protected $fillable = [
        'tenant_id','first_name','last_name','email','phone',
        'sms_opt_out_at','sms_consent_source', // MARKER-PATCH-221
        'address_line1','address_line2','city','state','postcode','country',
        'notes','stripe_customer_id','wp_source_url',
        'password','remember_token','email_verified_at',
        'password_reset_token','password_reset_sent_at',
        'is_vip',
        // MARKER-BIZ-CUSTOMER
        'customer_type', 'business_name',
        'tax_exempt', 'tax_exempt_certificate',
        'payment_terms', 'po_required',
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'tax_exempt'             => 'boolean', // MARKER-BIZ-CUSTOMER
        'po_required'            => 'boolean', // MARKER-BIZ-CUSTOMER
        'email_verified_at'      => 'datetime',
        'password_reset_sent_at' => 'datetime',
        'password'               => 'hashed',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function appointments(): HasMany   { return $this->hasMany(TenantAppointment::class, 'customer_id'); }
    public function specialOrders(): HasMany  { return $this->hasMany(TenantSpecialOrder::class, 'customer_id'); }
    public function notes(): HasMany          { return $this->hasMany(TenantCustomerNote::class, 'customer_id')->orderByDesc('created_at'); }
    // MARKER-BIZ-CUSTOMER — one display name for the whole app. A business
    // shows its business name; an individual is unchanged. Everything that
    // renders a customer name routes through here so a business record can
    // never surface a person's name by accident.
    public function fullName(): string
    {
        if ($this->isBusiness()) {
            $name = trim((string) $this->business_name);
            if ($name !== '') {
                return $name;
            }
        }

        return trim($this->first_name . ' ' . $this->last_name);
    }

    /** The person, even for a business — used where a human is meant. */
    public function personName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function isBusiness(): bool
    {
        return $this->customer_type === self::TYPE_BUSINESS;
    }

    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_BUSINESS   = 'business';

    public const PAYMENT_TERMS = ['due_now', 'net_15', 'net_30', 'net_60'];

    public function termsLabel(): string
    {
        return match ($this->payment_terms) {
            'net_15' => 'Net 15',
            'net_30' => 'Net 30',
            'net_60' => 'Net 60',
            default  => 'Due at service',
        };
    }

    /** MARKER-BIZ-CUSTOMER — people at a business customer. */
    public function contacts()
    {
        return $this->hasMany(TenantCustomerContact::class, 'customer_id')
            ->orderByDesc('is_primary')
            ->orderBy('name');
    }

    public function primaryContact()
    {
        return $this->hasOne(TenantCustomerContact::class, 'customer_id')
            ->where('is_primary', true);
    }

    // MARKER-PATCH-158-A
    public function assets(): HasMany         { return $this->hasMany(TenantCustomerAsset::class, 'customer_id'); }
    public function activeAssets(): HasMany   { return $this->hasMany(TenantCustomerAsset::class, 'customer_id')->whereNull('archived_at'); }

    public function packs(): HasMany
    {
        return $this->hasMany(TenantCustomerPack::class, 'customer_id');
    }

    public function activePacks(): HasMany
    {
        return $this->packs()->where('status', 'active')
                    ->where('credits_remaining', '>', 0)
                    ->where('expires_at', '>=', now()->toDateString())
                    ->orderBy('expires_at');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantCustomerMembership::class, 'customer_id');
    }

    public function activeMembership(): ?TenantCustomerMembership
    {
        return $this->memberships()->where('status', 'active')->with('product')->first();
    }

    public function classRegistrations(): HasMany
    {
        return $this->hasMany(TenantClassRegistration::class, 'customer_id');
    }

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return $this->password ?? ''; }
}
BIZ1_4_EOF

cat > 'app/Http/Controllers/Tenant/CustomerController.php' <<'BIZ1_5_EOF'
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
                   ->orWhere('phone',     'like', "%{$q}%");
            });
            // Name match wins over partial — order by best match heuristically
            $query->orderByRaw("
                CASE
                    WHEN first_name LIKE ? OR last_name LIKE ? THEN 0
                    WHEN email LIKE ? THEN 1
                    ELSE 2
                END
            ", ["{$q}%", "{$q}%", "{$q}%"]);
        } else {
            $query->orderByDesc('created_at');
        }

        $rows = $query->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->map(fn($c) => [
                'id'         => $c->id,
                'first_name' => $c->first_name,
                'last_name'  => $c->last_name,
                'email'      => $c->email,
                'phone'      => $c->phone,
                'label'      => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
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
                'name' => $customer->first_name . ' ' . $customer->last_name, 'email' => $customer->email,
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
BIZ1_5_EOF

cat > 'resources/views/tenant/customers/index.blade.php' <<'BIZ1_6_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Customers';
  $sortLabels = [
    'name_asc'     => 'Name A–Z',
    'name_desc'    => 'Name Z–A',
    'added_desc'   => 'Newest first',
    'added_asc'    => 'Oldest first',
    'spend_desc'   => 'Top spenders',
    'spend_asc'    => 'Lowest spend',
    'last_service' => 'Last service',
    'vips_only'    => 'VIPs only',
  ];
  $currentSortLabel = $sortLabels[$sort] ?? 'Name A–Z';
@endphp

@section('content')

{{-- CUSTOMER-LIST-MOBILE v1 — parallel desktop + mobile renders. --}}

{{-- ========== DESKTOP HEAD (hidden on mobile via CSS) ========== --}}
<div class="ia-page-head cust-desktop-only">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Customers</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('customer', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('new-customer-card').style.display='block';this.style.display='none'">
      + New customer
    </button>
  </div>
</div>

{{-- ========== MOBILE HEAD (hidden on desktop via CSS) ========== --}}
<div class="cust-mobile-only cust-mobile-head">
  <h1 class="cust-mobile-title">Customers</h1>
  <p class="cust-mobile-sub">{{ number_format($total) }} total</p>
</div>

{{-- ========== NEW CUSTOMER FORM (shared, toggled by either mobile + or desktop button) ========== --}}
<div id="new-customer-card" class="ia-card" style="display:none;margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">New customer</span>
    <button type="button" class="ia-card-action"
      onclick="document.getElementById('new-customer-card').style.display='none';
               var d = document.querySelector('.cust-desktop-only .ia-btn--primary'); if (d) d.style.display='';">
      Cancel
    </button>
  </div>
  <form method="POST" action="{{ route('tenant.customers.store') }}" data-biz-form>
    @csrf

    {{-- MARKER-BIZ-CUSTOMER — individual is the default, so this form opens
         exactly as it always has. Choosing Business reveals the extra fields
         and relaxes the person-name requirement. --}}
    @php $bizDefaults = tenant()->settings['customers'] ?? []; @endphp
    <div class="ia-form-group">
      <label class="ia-form-label">Customer type</label>
      <div class="biz-type-row">
        <label class="biz-type">
          <input type="radio" name="customer_type" value="individual" @checked(old('customer_type', 'individual') !== 'business')>
          <span>Individual</span>
        </label>
        <label class="biz-type">
          <input type="radio" name="customer_type" value="business" @checked(old('customer_type') === 'business')>
          <span>Business</span>
        </label>
      </div>
    </div>

    <div data-biz-only style="display:none">
      <div class="ia-form-group">
        <label class="ia-form-label">Business name <span class="ia-required">*</span></label>
        <input type="text" name="business_name" class="ia-input" value="{{ old('business_name') }}"
               placeholder="Spokane Public Schools">
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Payment terms</label>
          <select name="payment_terms" class="ia-input">
            <option value="">Due at service</option>
            <option value="net_15" @selected(old('payment_terms', $bizDefaults['default_payment_terms'] ?? '') === 'net_15')>Net 15</option>
            <option value="net_30" @selected(old('payment_terms', $bizDefaults['default_payment_terms'] ?? '') === 'net_30')>Net 30</option>
            <option value="net_60" @selected(old('payment_terms', $bizDefaults['default_payment_terms'] ?? '') === 'net_60')>Net 60</option>
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Purchase order</label>
          <label class="biz-check">
            <input type="checkbox" name="po_required" value="1"
                   @checked(old('po_required', ($bizDefaults['default_po_required'] ?? false) ? '1' : ''))>
            <span>Requires a PO number</span>
          </label>
        </div>
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Tax status</label>
          <label class="biz-check">
            <input type="checkbox" name="tax_exempt" value="1" data-biz-exempt @checked(old('tax_exempt'))>
            <span>Tax exempt</span>
          </label>
        </div>
        <div class="ia-form-group" data-biz-cert style="display:none">
          <label class="ia-form-label">Exemption certificate #</label>
          <input type="text" name="tax_exempt_certificate" class="ia-input" value="{{ old('tax_exempt_certificate') }}">
        </div>
      </div>
    </div>

    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label"><span data-biz-namelabel>First name</span> <span class="ia-required" data-biz-req>*</span></label>
        <input type="text" name="first_name" class="ia-input" required value="{{ old('first_name') }}" data-biz-name>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Last name <span class="ia-required" data-biz-req>*</span></label>
        <input type="text" name="last_name" class="ia-input" required value="{{ old('last_name') }}" data-biz-name>
      </div>
    </div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Email <span class="ia-required">*</span></label>
        <input type="email" name="email" class="ia-input" required value="{{ old('email') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Phone</label>
        <input type="tel" name="phone" class="ia-input" value="{{ old('phone') }}">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button type="submit" class="ia-btn ia-btn--primary">Save customer</button>
    </div>
  </form>
</div>

{{-- ========== DESKTOP FILTER TOOLBAR (hidden on mobile) ========== --}}
<style>
.cust-resource-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  margin-bottom: 16px;
  background: var(--ia-surface-2, rgba(255,255,255,0.03));
  border: 0.5px solid var(--ia-border);
  border-radius: 999px;
  font-size: 13px;
  color: var(--ia-text-2);
}
.cust-resource-chip strong { color: var(--ia-text); }
.cust-resource-clear {
  margin-left: 6px;
  color: var(--ia-text-3);
  text-decoration: none;
  font-size: 11px;
}
.cust-resource-clear:hover { color: var(--ia-accent, #BEF264); }
</style>

{{-- MARKER-PATCH-114 - created_after filter chip --}}
@if(!empty($createdAfter))
  <div class="cust-resource-chip">
    Showing customers added since
    <strong>{{ \Carbon\Carbon::parse($createdAfter)->format('M j, Y') }}</strong>
    <a href="{{ route('tenant.customers.index') }}" class="cust-resource-clear">clear ×</a>
  </div>
@endif

<form method="get" action="{{ route('tenant.customers.index') }}" class="ia-toolbar cust-desktop-only" id="cust-desktop-form">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search name, email, or phone…" style="max-width:300px">

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Search</button>
  @if($search || $sort !== 'name_asc')
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

{{-- ========== MOBILE FILTER BAR + SORT SHEET (hidden on desktop) ========== --}}
<form method="get" action="{{ route('tenant.customers.index') }}" class="cust-mobile-only cust-mfilter" id="cust-mobile-form">
  <div class="cust-mfilter-search-wrap">
    <svg class="cust-mfilter-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="search" name="s" class="cust-mfilter-search" value="{{ $search }}"
      placeholder="Search name, email, or phone" autocomplete="off" id="cust-search-mobile">
  </div>
  <input type="hidden" name="sort" id="cust-sort-mobile" value="{{ $sort }}">
  <button type="button" class="cust-mfilter-iconbtn {{ $sort !== 'name_asc' ? 'is-active' : '' }}" onclick="CustSort.open()" aria-label="Sort">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M3 6h18M6 12h12M10 18h4"/>
    </svg>
    @if($sort !== 'name_asc')
      <span class="cust-mfilter-badge" aria-hidden="true"></span>
    @endif
  </button>
  <button type="button" class="cust-mfilter-iconbtn" onclick="document.getElementById('new-customer-card').style.display='block';window.scrollTo({top:0,behavior:'smooth'})" aria-label="Add new customer">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </svg>
  </button>
</form>

{{-- Sort bottom sheet --}}
<div class="cust-sort-sheet-backdrop" id="cust-sort-backdrop" onclick="CustSort.close()" aria-hidden="true"></div>
<div class="cust-sort-sheet" id="cust-sort-sheet" role="dialog" aria-modal="true" aria-label="Sort customers" aria-hidden="true">
  <div class="cust-sort-handle" aria-hidden="true"></div>
  <div class="cust-sort-title">Sort by</div>
  @foreach($sortLabels as $val => $label)
    <button type="button"
            class="cust-sort-row {{ $sort === $val ? 'is-active' : '' }}"
            onclick="CustSort.pick('{{ $val }}')">
      <span>{{ $label }}</span>
      @if($sort === $val)
        <span class="cust-sort-check" aria-hidden="true">✓</span>
      @endif
    </button>
  @endforeach
</div>

{{-- ========== RESULT COUNT (desktop) ========== --}}
<p class="ia-result-count cust-desktop-only">
  <strong>{{ number_format($total) }}</strong> {{ Str::plural('customer', $total) }}
</p>

{{-- ========== MOBILE LIST HEADER (count + current sort) ========== --}}
<div class="cust-mobile-only cust-list-header">
  <span>{{ number_format($total) }} {{ Str::plural('customer', $total) }} · {{ $currentSortLabel }}</span>
</div>

@if($customers->isEmpty())
  <div class="ia-empty">
    <div class="ia-empty-icon">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="opacity:.4">
        <circle cx="10" cy="7" r="4" stroke="currentColor" stroke-width="1.4"/>
        <path d="M2.5 18c0-4 3.5-7 7.5-7s7.5 3 7.5 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="ia-empty-title">
      @if($search) No customers match "{{ $search }}" @else No customers yet @endif
    </div>
    <div class="ia-empty-desc">
      @if($search) Try a different search term. @else Customers are created when appointments are booked, or you can add one manually. @endif
    </div>
  </div>
@else
  {{-- ========== DESKTOP TABLE ========== --}}
  <div class="ia-table-wrap cust-desktop-only">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Last service</th>
          <th class="ia-num">Total spend</th>
          <th>Added</th>
        </tr>
      </thead>
      <tbody>
        @foreach($customers as $c)
          @php $stat = $stats[$c->id] ?? null; @endphp
          {{-- MARKER-PATCH-503 — straight to the customer page, no modal hop --}}
          <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.customers.show', $c->id) }}'">
            <td><span style="font-weight:500">{{ $c->first_name }} {{ $c->last_name }}</span>@if($c->is_vip)<span class="vip-list-star" title="VIP">★</span>@endif</td>
            <td class="ia-muted-cell">{{ $c->email }}</td>
            <td class="ia-muted-cell">{{ $c->phone ?: '—' }}</td>
            <td class="ia-muted-cell">
              {{ $stat?->last_service_date ? \Carbon\Carbon::parse($stat->last_service_date)->format('M j, Y') : '—' }}
            </td>
            <td class="ia-num">{{ format_money((int)($stat?->total_spend_cents ?? 0)) }}</td>
            <td class="ia-muted-cell">{{ $c->created_at->format('M j, Y') }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- ========== MOBILE CARD LIST ========== --}}
  <div class="cust-mobile-only cust-cards">
    @foreach($customers as $c)
      @php
        $stat = $stats[$c->id] ?? null;
        $spend = (int)($stat?->total_spend_cents ?? 0);
        $lastSvc = $stat?->last_service_date
          ? \Carbon\Carbon::parse($stat->last_service_date)->format('M j')
          : null;
        $contactParts = array_filter([$c->email, $c->phone]);
      @endphp
      <button type="button" class="cust-card" onclick="window.location.href='{{ route('tenant.customers.show', $c->id) }}'">
        <div class="cust-card-top">
          <span class="cust-card-name">{{ $c->first_name }} {{ $c->last_name }}</span>
          @if($spend > 0)
            <span class="cust-card-spend">{{ format_money($spend) }}</span>
          @endif
        </div>
        @if($contactParts)
          <div class="cust-card-contact">{{ implode(' · ', $contactParts) }}</div>
        @endif
        <div class="cust-card-meta">
          @if($lastSvc)Last service {{ $lastSvc }} · @endif
          Added {{ $c->created_at->format('M j, Y') }}
        </div>
      </button>
    @endforeach
  </div>

  @if($totalPages > 1)
    {{-- MARKER-PATCH-368 — windowed pager (prev/next + ellipses) replaces the full 1..N wall. --}}
    @php
      $pgUrl     = fn($p) => route('tenant.customers.index', array_merge(request()->query(), ['page' => $p]));
      $winStart  = max(1, $page - 2);
      $winEnd    = min($totalPages, $page + 2);
      $shownFrom = $total > 0 ? ($page - 1) * 25 + 1 : 0;
      $shownTo   = min($page * 25, $total);
    @endphp
    <div class="ia-pagination" role="navigation" aria-label="Customer pages">
      @if($page > 1)
        <a href="{{ $pgUrl($page - 1) }}" class="ia-page-btn" rel="prev" aria-label="Previous page">&lsaquo;</a>
      @else
        <span class="ia-page-btn is-disabled" aria-disabled="true">&lsaquo;</span>
      @endif

      @if($winStart > 1)
        <a href="{{ $pgUrl(1) }}" class="ia-page-btn">1</a>
        @if($winStart > 2)<span class="ia-page-ellipsis">&hellip;</span>@endif
      @endif

      @for($p = $winStart; $p <= $winEnd; $p++)
        <a href="{{ $pgUrl($p) }}" class="ia-page-btn {{ $p === $page ? 'active' : '' }}"@if($p === $page) aria-current="page"@endif>{{ $p }}</a>
      @endfor

      @if($winEnd < $totalPages)
        @if($winEnd < $totalPages - 1)<span class="ia-page-ellipsis">&hellip;</span>@endif
        <a href="{{ $pgUrl($totalPages) }}" class="ia-page-btn">{{ $totalPages }}</a>
      @endif

      @if($page < $totalPages)
        <a href="{{ $pgUrl($page + 1) }}" class="ia-page-btn" rel="next" aria-label="Next page">&rsaquo;</a>
      @else
        <span class="ia-page-btn is-disabled" aria-disabled="true">&rsaquo;</span>
      @endif
    </div>
    <div class="cust-page-count">Showing {{ number_format($shownFrom) }}&ndash;{{ number_format($shownTo) }} of {{ number_format($total) }}</div>
  @endif
@endif

@push('scripts')
<script>
(function () {
  // ── Sort sheet open/close + pick ─────────────────────────────────────────
  window.CustSort = {
    open: function () {
      document.getElementById('cust-sort-backdrop').classList.add('is-open');
      document.getElementById('cust-sort-sheet').classList.add('is-open');
      document.getElementById('cust-sort-backdrop').setAttribute('aria-hidden','false');
      document.getElementById('cust-sort-sheet').setAttribute('aria-hidden','false');
      document.body.style.overflow = 'hidden';
    },
    close: function () {
      document.getElementById('cust-sort-backdrop').classList.remove('is-open');
      document.getElementById('cust-sort-sheet').classList.remove('is-open');
      document.getElementById('cust-sort-backdrop').setAttribute('aria-hidden','true');
      document.getElementById('cust-sort-sheet').setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    },
    pick: function (val) {
      document.getElementById('cust-sort-mobile').value = val;
      document.getElementById('cust-mobile-form').submit();
    },
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') CustSort.close();
  });

  // ── Live search (mobile only) ────────────────────────────────────────────
  var searchInput = document.getElementById('cust-search-mobile');
  var form = document.getElementById('cust-mobile-form');
  if (searchInput && form) {
    var t = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { form.submit(); }, 350);
    });
  }
})();
</script>
@endpush

@push('styles')
<style>
/* CUSTOMER-LIST-MOBILE v1 ────────────────────────────────────────────────── */

.cust-mobile-only { display: none; }

@media (max-width: 600px) {
  .cust-desktop-only { display: none !important; }
  .cust-mobile-only { display: block; }

  /* Mobile head */
  .cust-mobile-head {
    margin-bottom: 14px;
  }
  .cust-mobile-title {
    font-size: 22px;
    font-weight: 600;
    letter-spacing: -.02em;
    line-height: 1.15;
    color: var(--ia-text);
    margin: 0;
  }
  .cust-mobile-sub {
    font-size: 12px;
    color: var(--ia-text-muted);
    margin: 2px 0 0;
  }

  /* Filter bar */
  .cust-mfilter {
    display: grid !important;
    grid-template-columns: 1fr 40px 40px;
    gap: 6px;
    margin-bottom: 14px;
  }
  .cust-mfilter-search-wrap {
    position: relative;
  }
  .cust-mfilter-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--ia-text-muted);
    pointer-events: none;
  }
  .cust-mfilter-search {
    width: 100%;
    height: 40px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    padding: 0 12px 0 36px;
    color: var(--ia-text);
    font-size: 14px;
    font-family: inherit;
    -webkit-appearance: none;
    appearance: none;
  }
  .cust-mfilter-search:focus {
    outline: none;
    border-color: var(--ia-accent);
  }
  .cust-mfilter-iconbtn {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ia-text-muted);
    cursor: pointer;
    position: relative;
    -webkit-tap-highlight-color: transparent;
  }
  .cust-mfilter-iconbtn:active { transform: scale(0.95); }
  .cust-mfilter-iconbtn.is-active {
    color: var(--ia-accent);
    border-color: rgba(190,242,100,.3);
    background: var(--ia-accent-soft);
  }
  .cust-mfilter-badge {
    position: absolute;
    top: 4px; right: 4px;
    width: 8px; height: 8px;
    background: var(--ia-accent);
    border-radius: 50%;
    border: 2px solid var(--ia-bg, #0a0a0a);
  }

  /* List header */
  .cust-list-header {
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }

  /* Customer cards */
  .cust-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .cust-card {
    display: block;
    width: 100%;
    text-align: left;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    font-family: inherit;
    color: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .cust-card:active { transform: scale(0.99); }
  .cust-card-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 10px;
  }
  .cust-card-name {
    font-size: 15px;
    font-weight: 500;
    color: var(--ia-text);
    letter-spacing: -.01em;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cust-card-spend {
    font-size: 14px;
    font-weight: 500;
    color: var(--ia-text);
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
  }
  .cust-card-contact {
    font-size: 12px;
    color: var(--ia-text-muted);
    margin-top: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cust-card-meta {
    font-size: 11px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    margin-top: 3px;
  }
}

/* Sort sheet — base styles outside media query so transitions work
   when the sheet opens. Hidden via translate when not .is-open. */
.cust-sort-sheet-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.cust-sort-sheet-backdrop.is-open {
  opacity: 1;
  pointer-events: auto;
}
.cust-sort-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  padding: 12px 0 calc(24px + env(safe-area-inset-bottom, 0px));
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 88vh;
  overflow-y: auto;
}
.cust-sort-sheet.is-open { transform: translateY(0); }
.cust-sort-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 0 auto 12px;
}
body.ia-theme-b .cust-sort-handle { background: rgba(0,0,0,.18); }
.cust-sort-title {
  padding: 0 20px 12px;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
}
.cust-sort-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 14px 20px;
  background: transparent;
  border: none;
  border-top: 0.5px solid var(--ia-border);
  color: var(--ia-text);
  font-size: 15px;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.cust-sort-row:active { background: rgba(255,255,255,.04); }
body.ia-theme-b .cust-sort-row:active { background: rgba(0,0,0,.04); }
.cust-sort-row.is-active { color: var(--ia-accent); }
.cust-sort-check {
  color: var(--ia-accent);
  font-weight: 600;
}

/* Hide the sort sheet entirely on desktop — never reachable */
@media (min-width: 601px) {
  .cust-sort-sheet,
  .cust-sort-sheet-backdrop { display: none !important; }
}

/* MARKER-PATCH-368 — windowed pager extras */
.ia-page-btn.is-disabled { opacity: .35; pointer-events: none; }
.ia-page-ellipsis { display: inline-flex; align-items: center; padding: 0 4px; color: var(--ia-text-3, #888); font-size: 12px; }
.cust-page-count { margin-top: 8px; font-size: 11.5px; color: var(--ia-text-3, #888); }
</style>
@endpush


{{-- MARKER-BIZ-CUSTOMER — inside the section: Blade discards markup placed
     after @endsection. --}}
<style>
  .biz-type-row{display:flex;gap:8px}
  .biz-type{flex:1;display:flex;align-items:center;gap:8px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:11px 13px;cursor:pointer;font-size:13.5px}
  .biz-type:has(input:checked){border-color:var(--ia-accent);background:color-mix(in srgb, var(--ia-accent) 10%, transparent)}
  .biz-check{display:flex;align-items:center;gap:8px;font-size:13px;padding:10px 0}
</style>
<script>
(function () {
  function sync(form) {
    var isBiz = !!form.querySelector('input[name="customer_type"][value="business"]:checked');
    var only  = form.querySelector('[data-biz-only]');
    if (only) only.style.display = isBiz ? '' : 'none';

    // A business is identified by its business name, so the person's name
    // stops being required — matching the server-side rule exactly.
    form.querySelectorAll('[data-biz-name]').forEach(function (i) { i.required = !isBiz; });
    form.querySelectorAll('[data-biz-req]').forEach(function (r) { r.style.display = isBiz ? 'none' : ''; });
    var lbl = form.querySelector('[data-biz-namelabel]');
    if (lbl) lbl.textContent = isBiz ? 'Contact first name' : 'First name';

    var ex   = form.querySelector('[data-biz-exempt]');
    var cert = form.querySelector('[data-biz-cert]');
    if (cert) cert.style.display = (isBiz && ex && ex.checked) ? '' : 'none';
  }

  document.querySelectorAll('[data-biz-form]').forEach(function (form) {
    form.addEventListener('change', function (e) {
      if (e.target.name === 'customer_type' || e.target.hasAttribute('data-biz-exempt')) sync(form);
    });
    sync(form);
  });
})();
</script>

@endsection
BIZ1_6_EOF

cat > 'resources/views/tenant/customers/show.blade.php' <<'BIZ1_7_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle  = $customer->first_name . ' ' . $customer->last_name;
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

/* CUSTOMER-MOBILE-POLISH v1 — phone polish at ≤600px */
@media (max-width: 600px) {

  /* Hide the page-level Back; top-bar already has ‹ Customers chevron */
  .ia-page-actions .ia-btn--ghost { display: none; }

  /* "+ New appointment" goes full-width on phones */
  .ia-page-actions .ia-btn--primary {
    width: 100%;
    justify-content: center;
  }

  /* Card headers (Memberships & Packs, Activity): stack title above actions */
  .ia-card-head {
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 8px;
  }
  .ia-card-head > div[style*="display:flex"] {
    width: 100%;
    flex-wrap: wrap;
    gap: 8px !important;
  }
  .ia-card-head .ia-btn--sm {
    flex: 1;
    justify-content: center;
    min-width: 0;
  }
  /* The Activity filter select stretches to fill the row */
  .ia-card-head #activity-filter {
    flex: 1;
    min-width: 0;
  }

  /* Activity rows: reflow 5-col grid into a compact 2-row layout */
  .act-row {
    grid-template-columns: 28px 1fr auto !important;
    grid-template-rows: auto auto;
    gap: 6px 10px !important;
    padding: 12px 4px !important;
  }
  .act-icon { grid-row: 1 / 3; align-self: start; }
  .act-date {
    grid-column: 2 / 4;
    grid-row: 1;
    font-size: 10px;
    margin-bottom: -2px;
  }
  .act-main {
    grid-column: 2;
    grid-row: 2;
    min-width: 0;
  }
  .act-title { font-size: 13px; }
  .act-id { display: block; margin-left: 0; margin-top: 1px; font-size: 11px; }
  .act-sub { font-size: 11px; }
  .act-pill {
    grid-column: 3;
    grid-row: 2;
    align-self: center;
    font-size: 10px !important;
    padding: 2px 6px !important;
  }
  .act-amount {
    grid-column: 3;
    grid-row: 1;
    text-align: right;
    align-self: center;
    font-size: 12px;
    font-weight: 500;
  }
}

/* Activity timeline (unified customer history). */
.act-month { margin-bottom: 4px; }
.act-month-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 4px; cursor: pointer;
  border-bottom: 0.5px solid var(--ia-border);
  font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
  color: var(--ia-text-muted); font-weight: 500;
  transition: color var(--ia-t);
}
.act-month-head:hover { color: var(--ia-text); }
.act-month-label { display: flex; align-items: center; gap: 4px; }
.act-chevron { font-size: 12px; opacity: .6; }
.act-month-count { color: var(--ia-text-dim); font-weight: 400; text-transform: none; letter-spacing: 0; margin-left: 4px; }
.act-month-total { font-variant-numeric: tabular-nums; color: var(--ia-text); }
.act-row {
  display: grid;
  grid-template-columns: 28px 60px 1fr auto auto;
  gap: 10px; align-items: center;
  padding: 10px 4px;
  border-bottom: 0.5px solid var(--ia-border);
  transition: background var(--ia-t);
}
.act-row:hover { background: var(--ia-hover); }
.act-row:last-child { border-bottom: none; }
.act-icon {
  width: 24px; height: 24px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  background: var(--ia-surface-2);
  color: var(--ia-text-muted);
}
.act-icon--sale               { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.act-icon--appointment        { background: rgba(250,180,106,.18); color: #FAB46A; }
.act-icon--class_registration { background: rgba(117,168,224,.18); color: #75A8E0; }
.act-icon--pack_grant         { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.act-icon--membership_grant   { background: rgba(244,115,115,.15); color: #F47373; }
.act-date {
  font-size: 11px; color: var(--ia-text-muted);
  font-variant-numeric: tabular-nums; white-space: nowrap;
}
.act-main { min-width: 0; }
.act-title { font-size: 13px; font-weight: 500; color: var(--ia-text); }
.act-id { color: var(--ia-text-muted); font-weight: 400; margin-left: 4px; }
.act-sub {
  font-size: 11.5px; color: var(--ia-text-muted); margin-top: 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.act-pill {
  font-size: 10px; padding: 2px 7px; border-radius: 20px;
  white-space: nowrap;
}
.act-pill--success  { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.act-pill--warning  { background: rgba(250,180,106,.15); color: #FAB46A; }
.act-pill--danger   { background: rgba(244,115,115,.15); color: #F47373; }
.act-pill--neutral  { background: var(--ia-surface-2); color: var(--ia-text-muted); }
.act-amount {
  font-size: 13px; font-weight: 500; min-width: 65px; text-align: right;
  font-variant-numeric: tabular-nums; color: var(--ia-text);
}
.act-amount.is-refunded { text-decoration: line-through; color: var(--ia-text-muted); }


/* CUSTOMER-MOBILE-REBUILD-CSS v1 — full mobile detail page styles + VIP. */

/* VIP — desktop badge + toggle */
.cust-vip-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 99px;
  background: rgba(245,158,11,.10);
  color: #F59E0B;
  border: 0.5px solid rgba(245,158,11,.30);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .04em;
  vertical-align: middle;
  margin-left: 8px;
}
.cust-vip-badge svg { color: #F59E0B; }

.cust-vip-toggle-desktop {
  display: inline-flex !important;
  align-items: center;
  gap: 5px;
}
.cust-vip-toggle-desktop.is-on {
  color: #F59E0B !important;
  border-color: rgba(245,158,11,.30) !important;
  background: rgba(245,158,11,.06) !important;
}

/* Customer list — small ★ next to VIP customer names */
.vip-list-star {
  color: #F59E0B;
  margin-left: 6px;
  font-size: 12px;
  vertical-align: middle;
}

/* Mobile-only visibility helpers */
.cust-mobile-only { display: none; }

@media (max-width: 600px) {
  .cust-desktop-only { display: none !important; }
  .cust-mobile-only { display: block; }

  /* Container */
  .cust-mobile { padding: 0; }

  /* HERO */
  .cmd-hero { margin-bottom: 16px; }
  .cmd-hero-top {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px;
  }
  .cmd-hero-name {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -.02em;
    line-height: 1.15;
    color: var(--ia-text);
    margin: 0;
    flex: 1;
    min-width: 0;
    word-break: break-word;
  }
  .cmd-hero-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
  }
  .cmd-vip-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    color: var(--ia-text-muted);
    height: 36px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-vip-btn.is-on {
    color: #F59E0B;
    border-color: rgba(245,158,11,.30);
    background: rgba(245,158,11,.08);
  }
  .cmd-vip-btn:active { transform: scale(0.95); }
  .cmd-edit-btn {
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    color: var(--ia-text-muted);
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-edit-btn:active { transform: scale(0.95); }

  /* Status pills */
  .cmd-status {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
  }
  .cmd-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 500;
  }
  .cmd-pill-dot {
    width: 6px; height: 6px; border-radius: 50%;
  }
  .cmd-pill--member {
    background: rgba(190,242,100,.10);
    color: var(--ia-accent);
    border: 0.5px solid rgba(190,242,100,.25);
  }
  .cmd-pill--member .cmd-pill-dot { background: var(--ia-accent); }
  .cmd-pill--neutral {
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    border: 0.5px solid var(--ia-border);
  }

  /* Contact tiles */
  .cmd-contact-tiles {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-top: 14px;
  }
  .cmd-tile {
    display: flex; flex-direction: column; align-items: center;
    gap: 4px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px 6px;
    color: var(--ia-text);
    text-decoration: none;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-tile svg { color: var(--ia-accent); }
  .cmd-tile:active { transform: scale(0.97); }
  .cmd-tile-label {
    font-size: 11px;
    color: var(--ia-text-muted);
    font-weight: 500;
  }
  .cmd-tile.is-disabled {
    opacity: .35;
    pointer-events: none;
  }
  .cmd-tile.is-disabled svg { color: var(--ia-text-muted); }

  /* CTA */
  .cmd-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    margin-top: 12px;
    padding: 14px;
    background: var(--ia-accent);
    color: var(--ia-bg, #0a0a0a);
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }
  .cmd-cta:active { transform: scale(0.99); }

  /* Stats */
  .cmd-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 20px;
  }
  .cmd-stat {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 10px 8px;
    text-align: center;
  }
  .cmd-stat-value {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -.01em;
    font-variant-numeric: tabular-nums;
    color: var(--ia-text);
  }
  .cmd-stat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--ia-text-muted);
    margin-top: 3px;
    font-weight: 500;
  }

  /* Sections */
  .cmd-section { margin-bottom: 24px; }
  .cmd-section-head {
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }

  /* Membership card */
  .cmd-mb-card {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-left: 3px solid var(--ia-accent);
    border-radius: 10px;
    padding: 14px;
  }
  .cmd-mb-card-top {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: 10px;
    margin-bottom: 4px;
  }
  .cmd-mb-card-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--ia-text);
  }
  .cmd-mb-card-renew {
    font-size: 11px;
    color: var(--ia-text-muted);
    font-variant-numeric: tabular-nums;
  }
  .cmd-mb-card-meta {
    font-size: 12px;
    color: var(--ia-text-muted);
  }

  /* Activity */
  .cmd-act-month-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    font-weight: 500;
    padding: 12px 4px 6px;
  }
  .cmd-act-row {
    display: grid;
    grid-template-columns: 28px 1fr auto;
    grid-template-rows: auto auto;
    gap: 4px 12px;
    padding: 12px 4px;
    border-bottom: 0.5px solid var(--ia-border);
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-act-row:last-child { border-bottom: none; }
  .cmd-act-icon {
    grid-row: 1 / 3;
    width: 28px; height: 28px;
    border-radius: 50%;
    margin-top: 2px;
    background: var(--ia-surface-2);
  }
  .cmd-act-icon--appt { background: rgba(250,180,106,.18); }
  .cmd-act-icon--sale { background: rgba(190,242,100,.15); }
  .cmd-act-icon--class { background: rgba(117,168,224,.18); }
  .cmd-act-icon--member { background: rgba(244,115,115,.15); }
  .cmd-act-date {
    grid-column: 2;
    grid-row: 1;
    font-size: 10px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    font-variant-numeric: tabular-nums;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .cmd-act-amount {
    grid-column: 3;
    grid-row: 1;
    font-size: 13px;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    color: var(--ia-text);
    text-align: right;
  }
  .cmd-act-main {
    grid-column: 2;
    grid-row: 2;
    min-width: 0;
  }
  .cmd-act-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--ia-text);
    margin-bottom: 1px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cmd-act-sub {
    font-size: 11px;
    color: var(--ia-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cmd-act-pill {
    grid-column: 3;
    grid-row: 2;
    align-self: center;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 99px;
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    white-space: nowrap;
    justify-self: end;
  }
  .cmd-act-pill--success { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .cmd-act-pill--warning { background: rgba(245,158,11,.15); color: #F59E0B; }
  .cmd-act-pill--danger { background: rgba(244,115,115,.15); color: #F47373; }

  /* Notes */
  .cmd-note {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
  }
  .cmd-note-head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11px;
    color: var(--ia-text-muted);
    margin-bottom: 4px;
  }
  .cmd-note-author { font-weight: 500; color: var(--ia-text); }
  .cmd-note-body { font-size: 13px; line-height: 1.4; color: var(--ia-text); }
  .cmd-note-empty { font-size: 13px; color: var(--ia-text-muted); padding: 4px; }
  .cmd-note-add {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 4px;
  }
  .cmd-note-add input {
    flex: 1;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    padding: 10px 12px;
    color: var(--ia-text);
    font-size: 13px;
    font-family: inherit;
  }
  .cmd-note-add-btn {
    background: var(--ia-accent);
    color: var(--ia-bg, #0a0a0a);
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
  }
}


/* CUST-EDIT-SHEET-CSS v1 */
.cust-edit-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.cust-edit-backdrop.is-open { opacity: 1; pointer-events: auto; }

.cust-edit-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}
.cust-edit-sheet.is-open { transform: translateY(0); }

.cust-edit-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 12px auto 8px;
  flex-shrink: 0;
}
body.ia-theme-b .cust-edit-handle { background: rgba(0,0,0,.18); }

.cust-edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 20px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.cust-edit-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text);
}
.cust-edit-close {
  background: transparent;
  border: none;
  color: var(--ia-text-muted);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.cust-edit-body {
  padding: 16px 20px calc(20px + env(safe-area-inset-bottom, 0px));
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  flex: 1;
}

.cust-edit-field {
  margin-bottom: 14px;
}
.cust-edit-label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
  margin-bottom: 5px;
}
.cust-edit-input {
  width: 100%;
  background: var(--ia-input-bg, var(--ia-surface-2));
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--ia-text);
  font-size: 15px;
  font-family: inherit;
  -webkit-appearance: none;
  appearance: none;
}
.cust-edit-input:focus {
  outline: none;
  border-color: var(--ia-accent);
}

.cust-edit-row-2 {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.cust-edit-row-2 .cust-edit-field {
  margin-bottom: 0;
}

.cust-edit-actions {
  display: grid;
  grid-template-columns: 1fr 2fr;
  gap: 8px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 0.5px solid var(--ia-border);
}
.cust-edit-btn-cancel {
  background: transparent;
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.cust-edit-btn-save {
  background: var(--ia-accent);
  color: var(--ia-bg, #0a0a0a);
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.cust-edit-btn-save:disabled {
  opacity: .5;
  cursor: wait;
}
.cust-edit-error {
  margin-top: 10px;
  padding: 8px 12px;
  background: rgba(244,115,115,.10);
  border: 0.5px solid rgba(244,115,115,.30);
  border-radius: 8px;
  color: #F47373;
  font-size: 13px;
}

/* Hide the edit sheet entirely on desktop — unreachable. */
@media (min-width: 601px) {
  .cust-edit-sheet,
  .cust-edit-backdrop { display: none !important; }
}

/* ============ MARKER-PATCH-158-C — Customer assets ============ */
.asset-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 10px;
}
.asset-tile {
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 8px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  position: relative;
}
.asset-tile.is-archived { opacity: 0.55; }
.asset-tile .asset-name { font-size: 13.5px; font-weight: 500; }
.asset-tile .asset-id {
  font-size: 11px;
  color: var(--ia-text-dim);
  font-family: ui-monospace, 'SF Mono', monospace;
}
.asset-tile .asset-notes {
  font-size: 11.5px;
  color: var(--ia-text-dim);
  margin-top: 4px;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.asset-tile .asset-meta {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 0.5px solid var(--ia-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
  color: var(--ia-text-dim);
}
.asset-tile .asset-actions {
  display: flex;
  gap: 4px;
}
.asset-tile .asset-action {
  background: transparent;
  border: 0;
  color: var(--ia-text-dim);
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 3px;
  cursor: pointer;
}
.asset-tile .asset-action:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); color: var(--ia-text); }
.asset-tile .asset-action.danger:hover { color: #f87171; background: rgba(248,113,113,0.08); }
.asset-tile .asset-action.success:hover { color: var(--ia-accent, #BEF264); background: rgba(190,242,100,0.06); }
.asset-empty {
  padding: 24px;
  text-align: center;
  font-size: 12.5px;
  color: var(--ia-text-dim);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border: 1px dashed var(--ia-border);
  border-radius: 8px;
}
.asset-archived-toggle {
  font-size: 11.5px;
  color: var(--ia-text-dim);
  background: transparent;
  border: 0;
  cursor: pointer;
  padding: 8px 0;
  margin-top: 10px;
}
.asset-archived-toggle:hover { color: var(--ia-text); }
.asset-archived-section { margin-top: 12px; display: none; }
.asset-archived-section.expanded { display: block; }

/* Asset modal (CRUD) */
.asset-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.6);
  z-index: 999;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.asset-modal-backdrop.is-open { display: flex; }
.asset-modal {
  background: var(--ia-surface, #111);
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  width: 480px;
  max-width: 100%;
  overflow: hidden;
}
.asset-modal-head {
  padding: 16px 20px;
  border-bottom: 1px solid var(--ia-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.asset-modal-title { font-size: 14px; font-weight: 500; }
.asset-modal-body { padding: 18px 20px; }
.asset-modal-foot {
  padding: 12px 20px;
  border-top: 1px solid var(--ia-border);
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.asset-form-row { margin-bottom: 14px; }
.asset-form-row:last-child { margin-bottom: 0; }
.asset-form-label {
  font-size: 11.5px;
  color: var(--ia-text-dim);
  margin-bottom: 5px;
  display: block;
}
.asset-form-label .opt { color: var(--ia-text-faint, #52525b); font-weight: 400; }
.asset-form-help { font-size: 11px; color: var(--ia-text-dim); margin-top: 4px; }
</style>
@endpush

@section('mobile-back', 'Customers|' . route('tenant.customers.index'))

@section('content')

<x-tenant.sale-detail-modal />

{{-- Header — VIP-DESKTOP-INTEGRATION v1 --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">Customer</div>
    <h1 class="ia-page-title">
      {{ $customer->first_name }} {{ $customer->last_name }}
      <span class="cust-vip-badge" data-vip-badge style="display:{{ $customer->is_vip ? 'inline-flex' : 'none' }}">
        <svg viewBox="0 0 24 24" fill="currentColor" stroke="none" width="12" height="12" aria-hidden="true">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        VIP
      </span>
    </h1>
    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost cust-desktop-only">← Back</a>
    <button type="button" class="ia-btn ia-btn--ghost cust-vip-toggle-desktop {{ $customer->is_vip ? 'is-on' : '' }}"
            data-vip-toggle data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}">
      <svg viewBox="0 0 24 24" fill="{{ $customer->is_vip ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      </svg>
      <span data-vip-label>{{ $customer->is_vip ? 'VIP' : 'Mark VIP' }}</span>
    </button>
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>

{{-- ============================================================
     MOBILE LAYOUT (hidden on desktop via CSS)
     ============================================================ --}}
@php
  // CUST-STATS-FIX v1 — Carbon 3 returns floats; coerce to int. Visit count
  // unified across appointments + class registrations for fitness tenants.
  $mobActiveMembership = isset($customerMemberships) ? $customerMemberships->where('status','active')->first() : null;
  $mobActivePacks = isset($customerPacks) ? $customerPacks->where('status','active') : collect();
  $mobLastVisit = $lastService ? \Carbon\Carbon::parse($lastService) : null;

  // Use timestamp math instead of Carbon diffInMonths to avoid the Carbon 3
  // float-return footgun (cf. carbon3-diff-fix.sh for the analogous diffInMinutes
  // sign-flip bug).
  $mobMonthsSinceFloat = ((now()->getTimestamp() - $customer->created_at->getTimestamp()) / (60 * 60 * 24 * 30.44));
  $mobMonthsSince = (int) floor($mobMonthsSinceFloat);
  if ($mobMonthsSince < 1) {
    $mobSinceLabel = '<1 mo';
  } elseif ($mobMonthsSince < 12) {
    $mobSinceLabel = $mobMonthsSince . ' mo';
  } else {
    $mobSinceLabel = ((int) floor($mobMonthsSince / 12)) . ' yr';
  }

  // Visits count = appointments in attended states + class registrations.
  // Iterate $timelineMonths (already grouped collection passed from controller)
  // because the flat $timelineEvents isn't in scope here.
  $mobVisitCount = 0;
  foreach ($timelineMonths as $month) {
    foreach ($month['events'] as $e) {
      if ($e['kind'] === 'class_registration') {
        $mobVisitCount++;
      } elseif ($e['kind'] === 'appointment'
                && in_array(strtolower((string)($e['status_key'] ?? $e['status'] ?? '')),
                            ['completed', 'confirmed', 'in_progress', 'in progress', 'shipped', 'closed'])) {
        $mobVisitCount++;
      }
    }
  }
@endphp

<div class="cust-mobile-only cust-mobile">

  {{-- HERO BAND --}}
  <div class="cmd-hero">
    <div class="cmd-hero-top">
      <h1 class="cmd-hero-name">{{ $customer->first_name }} {{ $customer->last_name }}</h1>
      <div class="cmd-hero-actions">
        <button type="button" class="cmd-vip-btn {{ $customer->is_vip ? 'is-on' : '' }}"
                data-vip-toggle data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}"
                aria-label="{{ $customer->is_vip ? 'Remove VIP status' : 'Mark as VIP' }}">
          <svg viewBox="0 0 24 24" fill="{{ $customer->is_vip ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          VIP
        </button>
        <button type="button" class="cmd-edit-btn" onclick="CustEditSheet.open()" aria-label="Edit customer info">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>
      </div>
    </div>

    {{-- Status pills --}}
    @if($mobActiveMembership || $mobLastVisit)
      <div class="cmd-status">
        @if($mobActiveMembership)
          <span class="cmd-pill cmd-pill--member">
            <span class="cmd-pill-dot"></span>
            Active member
          </span>
        @endif
        @if($mobLastVisit)
          <span class="cmd-pill cmd-pill--neutral">Last visit · {{ $mobLastVisit->format('M j') }}</span>
        @endif
      </div>
    @endif

    {{-- Contact tiles --}}
    <div class="cmd-contact-tiles">
      <a href="{{ $customer->phone ? 'tel:' . preg_replace('/[^0-9+]/', '', $customer->phone) : '#' }}"
         class="cmd-tile {{ $customer->phone ? '' : 'is-disabled' }}"
         {{ $customer->phone ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        <span class="cmd-tile-label">Call</span>
      </a>
      <a href="{{ $customer->phone ? 'sms:' . preg_replace('/[^0-9+]/', '', $customer->phone) : '#' }}"
         class="cmd-tile {{ $customer->phone ? '' : 'is-disabled' }}"
         {{ $customer->phone ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <span class="cmd-tile-label">Text</span>
      </a>
      <a href="{{ $customer->email ? 'mailto:' . $customer->email : '#' }}"
         class="cmd-tile {{ $customer->email ? '' : 'is-disabled' }}"
         {{ $customer->email ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        <span class="cmd-tile-label">Email</span>
      </a>
    </div>

    {{-- Primary CTA --}}
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}" class="cmd-cta">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      New appointment
    </a>
  </div>

  {{-- STATS ROW --}}
  <div class="cmd-stats">
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ $mobVisitCount }}</div>
      <div class="cmd-stat-label">Visits</div>
    </div>
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ format_money((int)$totalSpend) }}</div>
      <div class="cmd-stat-label">Lifetime</div>
    </div>
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ $mobSinceLabel }}</div>
      <div class="cmd-stat-label">Since</div>
    </div>
  </div>

  {{-- MEMBERSHIP CARD (conditional) --}}
  @if($mobActiveMembership)
    <div class="cmd-section">
      <div class="cmd-section-head">
        <span>Membership</span>
      </div>
      <div class="cmd-mb-card">
        <div class="cmd-mb-card-top">
          <span class="cmd-mb-card-title">{{ $mobActiveMembership->product?->name ?? 'Membership' }}</span>
          @if($mobActiveMembership->renews_at)
            <span class="cmd-mb-card-renew">Renews {{ \Carbon\Carbon::parse($mobActiveMembership->renews_at)->format('M j') }}</span>
          @endif
        </div>
        <div class="cmd-mb-card-meta">
          Started {{ \Carbon\Carbon::parse($mobActiveMembership->granted_at ?? $mobActiveMembership->created_at)->format('M j') }}
          @if($mobActiveMembership->product?->price_cents)
            · {{ format_money($mobActiveMembership->product->price_cents) }}/mo
          @endif
        </div>
      </div>
    </div>
  @endif

  {{-- ACTIVITY — reuse existing $timelineMonths data --}}
  @if($timelineCount > 0)
    <div class="cmd-section">
      <div class="cmd-section-head">
        <span>Activity · {{ $timelineCount }} events</span>
      </div>
      <div class="cmd-activity">
        @foreach($timelineMonths as $monthKey => $month)
          <div class="cmd-act-month-label">{{ $month['label'] }}</div>
          @foreach($month['events'] as $e)
            @php
              $iconClass = match($e['kind']) {
                'appointment' => 'cmd-act-icon--appt',
                'sale' => 'cmd-act-icon--sale',
                'class_registration' => 'cmd-act-icon--class',
                'pack_grant', 'membership_grant' => 'cmd-act-icon--member',
                default => '',
              };
            @endphp
            <div class="cmd-act-row"
                 @if(!empty($e['sale_id'])) onclick="window.openSaleModal && window.openSaleModal('{{ $e['sale_id'] }}')"
                 @elseif($e['href']) onclick="window.location='{{ $e['href'] }}'"
                 @endif>
              <div class="cmd-act-icon {{ $iconClass }}"></div>
              <div class="cmd-act-date">{{ $e['date']->format('M j') }}</div>
              <div class="cmd-act-amount">
                @if($e['amount_cents'] !== null){{ format_money($e['amount_cents']) }}@endif
              </div>
              <div class="cmd-act-main">
                <div class="cmd-act-title">{{ $e['title'] }}</div>
                <div class="cmd-act-sub">{{ $e['subtitle'] }}</div>
              </div>
              <span class="cmd-act-pill cmd-act-pill--{{ $e['status_tone'] }}">{{ $e['status'] }}</span>
            </div>
          @endforeach
        @endforeach
      </div>
    </div>
  @endif

  {{-- NOTES — mobile version. Reuses existing add-note infrastructure. --}}
  <div class="cmd-section">
    <div class="cmd-section-head">
      <span>Notes</span>
    </div>
    @forelse($notes as $note)
      <div class="cmd-note">
        <div class="cmd-note-head">
          <span class="cmd-note-author">{{ $note->user?->name ?? 'Staff' }}</span>
          <span>{{ \Carbon\Carbon::parse($note->created_at)->format('M j, g:i a') }}</span>
        </div>
        <div class="cmd-note-body">{{ $note->note }}</div>
      </div>
    @empty
      <p class="cmd-note-empty">No notes yet.</p>
    @endforelse
    <div class="cmd-note-add">
      <input type="text" id="cmd-note-input" placeholder="Add a note..." maxlength="200">
      <button type="button" class="cmd-note-add-btn" data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}">Add</button>
    </div>
  </div>

</div>

{{-- CUST-EDIT-SHEET v1 — mobile-only bottom sheet for editing customer info.
     Posts to the same PATCH endpoint as the desktop form (op=update_info).
     Hidden on desktop via CSS @media (min-width: 601px). --}}
<div class="cust-edit-backdrop" id="cust-edit-backdrop" onclick="CustEditSheet.close()" aria-hidden="true"></div>
<div class="cust-edit-sheet" id="cust-edit-sheet" role="dialog" aria-modal="true" aria-label="Edit customer" aria-hidden="true">
  <div class="cust-edit-handle" aria-hidden="true"></div>
  <div class="cust-edit-header">
    <span class="cust-edit-title">Edit customer</span>
    <button type="button" class="cust-edit-close" onclick="CustEditSheet.close()" aria-label="Close">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </button>
  </div>

  <form method="POST" action="{{ $updateUrl }}" id="cust-edit-form" class="cust-edit-body" data-biz-form>
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="update_info">

    {{-- MARKER-BIZ-CUSTOMER --}}
    <div class="cust-edit-field">
      <label class="cust-edit-label">Customer type</label>
      <div class="biz-type-row">
        <label class="biz-type">
          <input type="radio" name="customer_type" value="individual" @checked(!$customer->isBusiness())>
          <span>Individual</span>
        </label>
        <label class="biz-type">
          <input type="radio" name="customer_type" value="business" @checked($customer->isBusiness())>
          <span>Business</span>
        </label>
      </div>
    </div>
    <div data-biz-only style="display:none">
      <div class="cust-edit-field">
        <label class="cust-edit-label">Business name <span style="color:#F47373">*</span></label>
        <input type="text" name="business_name" class="cust-edit-input" value="{{ $customer->business_name }}">
      </div>
      <div class="cust-edit-field">
        <label class="cust-edit-label">Payment terms</label>
        <select name="payment_terms" class="cust-edit-input">
          <option value="">Due at service</option>
          <option value="net_15" @selected($customer->payment_terms === 'net_15')>Net 15</option>
          <option value="net_30" @selected($customer->payment_terms === 'net_30')>Net 30</option>
          <option value="net_60" @selected($customer->payment_terms === 'net_60')>Net 60</option>
        </select>
      </div>
      <div class="cust-edit-field">
        <label class="biz-check">
          <input type="checkbox" name="po_required" value="1" @checked($customer->po_required)>
          <span>Requires a PO number</span>
        </label>
      </div>
      <div class="cust-edit-field">
        <label class="biz-check">
          <input type="checkbox" name="tax_exempt" value="1" data-biz-exempt @checked($customer->tax_exempt)>
          <span>Tax exempt</span>
        </label>
      </div>
      <div class="cust-edit-field" data-biz-cert style="display:none">
        <label class="cust-edit-label">Exemption certificate #</label>
        <input type="text" name="tax_exempt_certificate" class="cust-edit-input" value="{{ $customer->tax_exempt_certificate }}">
      </div>
    </div>

    <div class="cust-edit-field">
      <label class="cust-edit-label"><span data-biz-namelabel>First name</span> <span style="color:#F47373" data-biz-req>*</span></label>
      <input type="text" name="first_name" class="cust-edit-input" required value="{{ $customer->first_name }}" data-biz-name>
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Last name <span style="color:#F47373" data-biz-req>*</span></label>
      <input type="text" name="last_name" class="cust-edit-input" required value="{{ $customer->last_name }}" data-biz-name>
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Email</label>
      <input type="email" name="email" class="cust-edit-input" value="{{ $customer->email }}" inputmode="email" autocapitalize="none" autocorrect="off">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Phone</label>
      <input type="tel" name="phone" class="cust-edit-input" value="{{ $customer->phone }}" inputmode="tel">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Street address</label>
      <input type="text" name="address_line1" class="cust-edit-input" value="{{ $customer->address_line1 }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">City</label>
      <input type="text" name="city" class="cust-edit-input" value="{{ $customer->city }}">
    </div>
    <div class="cust-edit-row-2">
      <div class="cust-edit-field">
        <label class="cust-edit-label">State</label>
        <input type="text" name="state" class="cust-edit-input" value="{{ $customer->state }}">
      </div>
      <div class="cust-edit-field">
        <label class="cust-edit-label">ZIP</label>
        <input type="text" name="postcode" class="cust-edit-input" value="{{ $customer->postcode }}" inputmode="numeric">
      </div>
    </div>

    <div class="cust-edit-actions">
      <button type="button" class="cust-edit-btn-cancel" onclick="CustEditSheet.close()">Cancel</button>
      <button type="submit" class="cust-edit-btn-save">Save</button>
    </div>
    <p id="cust-edit-error" class="cust-edit-error" style="display:none"></p>
  </form>
</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1 — parallel mobile render below.
     Desktop layout (this .cust-layout grid) is hidden on phones via CSS.
     ============================================================ --}}
<div class="cust-layout cust-desktop-only">

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
            <div class="cust-field-value">{{ $customer->first_name }} {{ $customer->last_name }}</div>
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

    {{-- MARKER-PATCH-158-C — Assets (multi-asset-enabled tenants only) --}}
    @if($currentTenant->multi_asset_enabled)
      <div class="ia-card" style="margin-bottom:24px" id="cust-assets-card">
        <div class="ia-card-head">
          <span class="ia-card-title">Assets <span style="font-size:11px;font-weight:500;padding:2px 7px;background:var(--ia-surface-3, rgba(255,255,255,0.04));border-radius:4px;color:var(--ia-text-dim);margin-left:6px">{{ $customerActiveAssets->count() }}</span></span>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="openAssetModal()">+ Add asset</button>
        </div>
        <div style="font-size:12px;color:var(--ia-text-dim);margin-bottom:14px;line-height:1.55">
          Bikes, vehicles, or other items that belong to this customer. Pickable when scheduling an appointment.
        </div>

        @if($customerActiveAssets->isEmpty())
          <div class="asset-empty">
            No assets yet. Click <strong>+ Add asset</strong> to add the customer's first bike, vehicle, or pet.
          </div>
        @else
          <div class="asset-grid">
            @foreach($customerActiveAssets as $asset)
              <div class="asset-tile">
                <div class="asset-name">{{ $asset->name }}</div>
                @if($asset->identifier)
                  <div class="asset-id">{{ $asset->identifier }}</div>
                @endif
                @if($asset->notes)
                  <div class="asset-notes">{{ $asset->notes }}</div>
                @endif
                <div class="asset-meta">
                  <span>
                    @if($asset->last_seen_at)
                      Last seen {{ \Carbon\Carbon::parse($asset->last_seen_at)->format('M j, Y') }}
                    @else
                      Never serviced
                    @endif
                  </span>
                  <div class="asset-actions">
                    <button type="button" class="asset-action"
                      onclick="openAssetModal('{{ $asset->id }}', @js($asset->name), @js($asset->identifier ?? ''), @js($asset->notes ?? ''))">Edit</button>
                    <form method="POST" action="{{ route('tenant.customers.assets.archive', ['customerId' => $customer->id, 'id' => $asset->id]) }}" style="display:inline" onsubmit="return confirm('Archive this asset? It won\'t appear in the appointment picker.');">
                      @csrf
                      <button type="submit" class="asset-action danger">Archive</button>
                    </form>
                  </div>
                </div>
              </div>
            @endforeach
          </div>
        @endif

        @if($customerArchivedAssets->isNotEmpty())
          <button type="button" class="asset-archived-toggle" onclick="this.nextElementSibling.classList.toggle('expanded'); this.textContent = this.textContent.includes('Show') ? 'Hide archived assets ({{ $customerArchivedAssets->count() }})' : 'Show archived assets ({{ $customerArchivedAssets->count() }})';">
            Show archived assets ({{ $customerArchivedAssets->count() }})
          </button>
          <div class="asset-archived-section">
            <div class="asset-grid">
              @foreach($customerArchivedAssets as $asset)
                <div class="asset-tile is-archived">
                  <div class="asset-name">{{ $asset->name }}</div>
                  @if($asset->identifier)
                    <div class="asset-id">{{ $asset->identifier }}</div>
                  @endif
                  <div class="asset-meta">
                    <span>
                      Archived {{ \Carbon\Carbon::parse($asset->archived_at)->format('M j, Y') }}
                    </span>
                    <form method="POST" action="{{ route('tenant.customers.assets.unarchive', ['customerId' => $customer->id, 'id' => $asset->id]) }}" style="display:inline">
                      @csrf
                      <button type="submit" class="asset-action success">Restore</button>
                    </form>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @endif
      </div>

      {{-- Asset modal — used for both create and edit --}}
      <div class="asset-modal-backdrop" id="asset-modal-backdrop" onclick="if(event.target===this) closeAssetModal()">
        <div class="asset-modal">
          <form method="POST" id="asset-form">
            @csrf
            <input type="hidden" name="_method" id="asset-form-method" value="POST">
            <div class="asset-modal-head">
              <div class="asset-modal-title" id="asset-modal-title">Add asset</div>
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="closeAssetModal()">✕</button>
            </div>
            <div class="asset-modal-body">
              <div class="asset-form-row">
                <label class="asset-form-label">Name</label>
                <input class="ia-input" type="text" name="name" id="asset-form-name" required maxlength="200" placeholder="e.g. Red Cannondale Synapse">
                <div class="asset-form-help">Whatever the customer calls it — make it recognizable.</div>
              </div>
              <div class="asset-form-row">
                <label class="asset-form-label">Identifier <span class="opt">— optional</span></label>
                <input class="ia-input" type="text" name="identifier" id="asset-form-identifier" maxlength="120" placeholder="Serial, license plate, microchip, tag…">
                <div class="asset-form-help">Bikes use serial number. Auto shops use VIN. Groomers might use a chip ID.</div>
              </div>
              <div class="asset-form-row">
                <label class="asset-form-label">Notes <span class="opt">— optional</span></label>
                <textarea class="ia-input" name="notes" id="asset-form-notes" rows="3" maxlength="5000" placeholder="Color, distinguishing features, prior issues…"></textarea>
              </div>
            </div>
            <div class="asset-modal-foot">
              <button type="button" class="ia-btn ia-btn--ghost" onclick="closeAssetModal()">Cancel</button>
              <button type="submit" class="ia-btn ia-btn--primary" id="asset-form-submit">Save asset</button>
            </div>
          </form>
        </div>
      </div>

      <script>
        // MARKER-PATCH-158-C — asset modal open/close + populate for edit
        function openAssetModal(id, name, identifier, notes) {
          const form  = document.getElementById('asset-form');
          const title = document.getElementById('asset-modal-title');
          const meth  = document.getElementById('asset-form-method');
          const submit = document.getElementById('asset-form-submit');
          if (id) {
            // Edit mode
            form.action = '{{ route('tenant.customers.assets.update', ['customerId' => $customer->id, 'id' => '__ID__']) }}'.replace('__ID__', id);
            meth.value  = 'PATCH';
            title.textContent  = 'Edit asset';
            submit.textContent = 'Save changes';
            document.getElementById('asset-form-name').value = name || '';
            document.getElementById('asset-form-identifier').value = identifier || '';
            document.getElementById('asset-form-notes').value = notes || '';
          } else {
            // Create mode
            form.action = '{{ route('tenant.customers.assets.store', ['customerId' => $customer->id]) }}';
            meth.value  = 'POST';
            title.textContent  = 'Add asset';
            submit.textContent = 'Save asset';
            form.reset();
          }
          document.getElementById('asset-modal-backdrop').classList.add('is-open');
          setTimeout(() => document.getElementById('asset-form-name').focus(), 60);
        }
        function closeAssetModal() {
          document.getElementById('asset-modal-backdrop').classList.remove('is-open');
        }
        // Escape to close
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') closeAssetModal();
        });
      </script>
    @endif

    {{-- Memberships & Packs (classes-enabled tenants only) --}}
    @if($currentTenant->classes_enabled)
      @php
        $activeMembership = $customerMemberships->where('status', 'active')->first();
        $activePacks      = $customerPacks->where('status', 'active');
        $historyMemberships = $customerMemberships->where('status', '!=', 'active');
        $historyPacks       = $customerPacks->where('status', '!=', 'active');
      @endphp
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

    {{-- ════════════════════════════════════════════════════════════
         Special Orders integration (added by patch 88, Stage 5)
         Open + recently closed SOs for this customer. Drawer trigger
         prefills customer in first allocation row.
         ════════════════════════════════════════════════════════════ --}}
    @isset($specialOrdersOpen)
      <div class="ia-card" style="margin-bottom:24px">
        <div class="ia-card-head">
          <span class="ia-card-title">Special orders</span>
          <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm"
                  onclick='SoDrawer.open({customer_id: @json($customer->id), customer_label: @json(trim($customer->first_name . " " . $customer->last_name)), alloc_mode: "customer"})'>
            + SO for {{ $customer->first_name }}
          </button>
        </div>

        @if($specialOrdersOpen->isEmpty() && $specialOrdersClosed->isEmpty())
          <p style="font-size:13px;color:var(--ia-text-muted);padding:8px 0;margin:0">No special orders.</p>
        @else
          @if($specialOrdersOpen->isNotEmpty())
            <table class="ia-table">
              <thead>
                <tr>
                  <th>SO</th>
                  <th>Item</th>
                  <th>Qty</th>
                  <th>For appt</th>
                  <th>Status</th>
                  <th>ETA</th>
                </tr>
              </thead>
              <tbody>
                @foreach($specialOrdersOpen as $so)
                  <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                    <td><strong>{{ $so->so_number }}</strong></td>
                    <td>{{ $so->item_name_snapshot }}</td>
                    <td>{{ $so->quantity }}</td>
                    <td style="color:var(--ia-text-muted);font-size:12px">
                      @if($so->appointment){{ $so->appointment->ra_number }}@else — @endif
                    </td>
                    <td>
                      @php
                        $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
                      @endphp
                      <span class="so-status so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
                    </td>
                    <td style="color:var(--ia-text-muted);font-size:12px">
                      @if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif

          @if($specialOrdersClosed->isNotEmpty())
            <details style="margin-top:14px">
              <summary style="font-size:12px;color:var(--ia-text-muted);cursor:pointer;padding-bottom:6px">
                Recent closed ({{ $specialOrdersClosed->count() }}, last 90 days)
              </summary>
              <table class="ia-table" style="margin-top:6px">
                <tbody>
                  @foreach($specialOrdersClosed as $so)
                    <tr style="cursor:pointer;opacity:.65" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                      <td><strong>{{ $so->so_number }}</strong></td>
                      <td>{{ $so->item_name_snapshot }} <span style="color:var(--ia-text-muted)">×{{ $so->quantity }}</span></td>
                      <td><span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span></td>
                      <td style="color:var(--ia-text-muted);font-size:12px">{{ $so->updated_at->format('M j, Y') }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </details>
          @endif
        @endif
      </div>

      @include('tenant.special-orders._drawer', ['vendors' => $soVendors ?? collect()])

      @push('styles')
      <style>
      .so-status {
        display: inline-block; padding: 2px 8px; border-radius: 99px;
        font-size: 10.5px; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.05em;
      }
      .so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
      .so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
      .so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent); }
      .so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-muted); }
      .so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
      .so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }
      </style>
      @endpush
    @endisset

        {{-- Activity — unified timeline of all customer events.
         Powered by CustomerTimelineService. Replaces the previous
         appointments-only section. Groups by month, current+previous
         expanded by default, older months collapsible. Filterable
         via single dropdown at top. --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Activity</span>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:12px;opacity:.4">{{ $timelineCount }} events</span>
          <select id="activity-filter" style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);color:var(--ia-text);font-size:12px;padding:4px 22px 4px 8px;border-radius:4px;appearance:none;cursor:pointer;background-image:url(&quot;data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.45)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>&quot;);background-repeat:no-repeat;background-position:right 6px center;font-family:inherit">
            <option value="all">All activity</option>
            <option value="appointment">Appointments</option>
            <option value="sale">Sales</option>
            <option value="class_registration">Class registrations</option>
            <option value="grant">Memberships &amp; packs</option>
          </select>
        </div>
      </div>

      @if($timelineCount === 0)
        <p style="font-size:13px;opacity:.4;padding:8px 0">No activity yet.</p>
      @else
        @foreach($timelineMonths as $monthKey => $month)
          <div class="act-month" data-act-month="{{ $monthKey }}" data-expanded="{{ $month['expanded'] ? '1' : '0' }}">
            <div class="act-month-head" onclick="toggleActMonth(this)">
              <span class="act-month-label">
                <i class="act-chevron ti ti-chevron-down" style="display:{{ $month['expanded'] ? 'inline-block' : 'none' }}"></i>
                <i class="act-chevron ti ti-chevron-right" style="display:{{ $month['expanded'] ? 'none' : 'inline-block' }}"></i>
                {{ $month['label'] }}
                @if(!$month['expanded'])
                  <span class="act-month-count">· {{ $month['events']->count() }} events</span>
                @endif
              </span>
              <span class="act-month-total">{{ format_money($month['total_cents']) }}</span>
            </div>
            <div class="act-month-body" style="display:{{ $month['expanded'] ? 'block' : 'none' }}">
              @foreach($month['events'] as $e)
                @php
                  $kindClass = $e['kind'] === 'pack_grant' || $e['kind'] === 'membership_grant'
                    ? 'grant' : $e['kind'];
                  $iconMap = [
                    'sale'              => 'ti-cash',
                    'appointment'       => 'ti-calendar',
                    'class_registration'=> 'ti-users',
                    'pack_grant'        => 'ti-ticket',
                    'membership_grant'  => 'ti-id-badge',
                  ];
                  $icon = $iconMap[$e['kind']] ?? 'ti-circle';
                @endphp
                <div class="act-row" data-act-kind="{{ $kindClass }}"
                     @if(!empty($e['sale_id']))
                       onclick="window.openSaleModal && window.openSaleModal('{{ $e['sale_id'] }}')" style="cursor:pointer"
                     @elseif($e['href'])
                       onclick="window.location='{{ $e['href'] }}'" style="cursor:pointer"
                     @endif>
                  <div class="act-icon act-icon--{{ $e['kind'] }}"><i class="ti {{ $icon }}"></i></div>
                  <div class="act-date">{{ $e['date']->format('M j') }}</div>
                  <div class="act-main">
                    <div class="act-title">
                      {{ $e['title'] }}
                      @if($e['identifier'])
                        <span class="act-id">{{ $e['identifier'] }}</span>
                      @endif
                    </div>
                    <div class="act-sub">{{ $e['subtitle'] }}</div>
                  </div>
                  <span class="act-pill act-pill--{{ $e['status_tone'] }}">{{ $e['status'] }}</span>
                  <div class="act-amount {{ $e['is_refunded'] ? 'is-refunded' : '' }}">
                    @if($e['amount_cents'] !== null)
                      {{ format_money($e['amount_cents']) }}
                    @else
                      <span style="opacity:.4">—</span>
                    @endif
                  </div>
                </div>
              @endforeach
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
        <span class="cust-stat-label">Appointments</span>
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



@push('scripts')
<script>
// VIP-TOGGLE-JS v1 — handles both desktop + mobile VIP toggle buttons.
(function () {
  function setupVipToggle() {
    document.querySelectorAll('[data-vip-toggle]').forEach(function (btn) {
      if (btn.__vipBound) return;
      btn.__vipBound = true;
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url');
        var csrf = btn.getAttribute('data-csrf');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('_token', csrf);
        fd.append('op', 'toggle_vip');

        fetch(url, {
          method: 'POST',
          body: fd,
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (!data || !data.ok) {
            if (window.IntakeConfirm && IntakeConfirm.alert) {
              IntakeConfirm.alert({ title: 'Couldn\'t toggle VIP', message: 'Please try again.' });
            } else {
              alert('Could not toggle VIP. Please try again.');
            }
            return;
          }
          // Update all VIP UI on the page (desktop + mobile both visible in DOM)
          var isOn = !!data.is_vip;
          document.querySelectorAll('[data-vip-toggle]').forEach(function (b) {
            b.classList.toggle('is-on', isOn);
            var svg = b.querySelector('svg');
            if (svg) svg.setAttribute('fill', isOn ? 'currentColor' : 'none');
            var lbl = b.querySelector('[data-vip-label]');
            if (lbl) lbl.textContent = isOn ? 'VIP' : 'Mark VIP';
          });
          // Desktop badge under name
          document.querySelectorAll('[data-vip-badge]').forEach(function (badge) {
            badge.style.display = isOn ? 'inline-flex' : 'none';
          });
        })
        .catch(function () {
          btn.disabled = false;
          alert('Could not toggle VIP. Please try again.');
        });
      });
    });
  }

  // Mobile note-add handler (mirrors desktop, smaller surface)
  function setupMobileNoteAdd() {
    var btn = document.querySelector('.cmd-note-add-btn');
    var input = document.getElementById('cmd-note-input');
    if (!btn || !input || btn.__bound) return;
    btn.__bound = true;
    btn.addEventListener('click', function () {
      var note = input.value.trim();
      if (!note) return;
      var url = btn.getAttribute('data-url');
      var csrf = btn.getAttribute('data-csrf');
      btn.disabled = true;
      var fd = new FormData();
      fd.append('_method', 'PATCH');
      fd.append('_token', csrf);
      fd.append('op', 'add_note');
      fd.append('note', note);
      fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (data && data.ok) {
            // Soft reload to reflect new note. Could splice in DOM but page is short.
            window.location.reload();
          } else {
            alert(data && data.message ? data.message : 'Could not add note.');
          }
        })
        .catch(function () { btn.disabled = false; alert('Could not add note.'); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setupVipToggle(); setupMobileNoteAdd(); });
  } else {
    setupVipToggle(); setupMobileNoteAdd();
  }
})();
</script>
@endpush



@push('scripts')
<script>
// CUST-EDIT-SHEET-JS v1 — mobile bottom-sheet edit form.
(function () {
  window.CustEditSheet = {
    open: function () {
      var b = document.getElementById('cust-edit-backdrop');
      var s = document.getElementById('cust-edit-sheet');
      if (!b || !s) return;
      b.classList.add('is-open');
      s.classList.add('is-open');
      b.setAttribute('aria-hidden', 'false');
      s.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      // Focus first input after the slide-up settles
      setTimeout(function () {
        var firstInput = s.querySelector('.cust-edit-input');
        if (firstInput) firstInput.focus();
      }, 240);
    },
    close: function () {
      var b = document.getElementById('cust-edit-backdrop');
      var s = document.getElementById('cust-edit-sheet');
      if (!b || !s) return;
      b.classList.remove('is-open');
      s.classList.remove('is-open');
      b.setAttribute('aria-hidden', 'true');
      s.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      var err = document.getElementById('cust-edit-error');
      if (err) err.style.display = 'none';
    },
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') CustEditSheet.close();
  });

  // Submit handler — submit via fetch, reload on success
  var form = document.getElementById('cust-edit-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var saveBtn = form.querySelector('.cust-edit-btn-save');
      var errEl = document.getElementById('cust-edit-error');
      if (errEl) errEl.style.display = 'none';
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';

      var fd = new FormData(form);
      fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
      .then(function (r) {
        return r.json().then(function (data) {
          return { ok: r.ok && data && data.ok !== false, status: r.status, data: data };
        });
      })
      .then(function (result) {
        if (result.ok) {
          // Reload to reflect the new values across hero name, contact tiles, page-head, etc.
          window.location.reload();
        } else {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save';
          var msg = (result.data && (result.data.message || (result.data.errors && Object.values(result.data.errors)[0]))) || 'Could not save. Please try again.';
          if (Array.isArray(msg)) msg = msg[0];
          if (errEl) {
            errEl.textContent = msg;
            errEl.style.display = 'block';
          }
        }
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (errEl) {
          errEl.textContent = 'Network error. Please try again.';
          errEl.style.display = 'block';
        }
      });
    });
  }
})();
</script>
@endpush


{{-- MARKER-BIZ-CUSTOMER — inside the section on purpose; Blade discards
     anything after @endsection. --}}
<style>
  .biz-type-row{display:flex;gap:8px}
  .biz-type{flex:1;display:flex;align-items:center;gap:8px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:10px 12px;cursor:pointer;font-size:13px}
  .biz-type:has(input:checked){border-color:var(--ia-accent);background:color-mix(in srgb, var(--ia-accent) 10%, transparent)}
  .biz-check{display:flex;align-items:center;gap:8px;font-size:13px;padding:8px 0;cursor:pointer}
</style>
<script>
(function () {
  function sync(form) {
    var isBiz = !!form.querySelector('input[name="customer_type"][value="business"]:checked');
    var only  = form.querySelector('[data-biz-only]');
    if (only) only.style.display = isBiz ? '' : 'none';
    form.querySelectorAll('[data-biz-name]').forEach(function (i) { i.required = !isBiz; });
    form.querySelectorAll('[data-biz-req]').forEach(function (r) { r.style.display = isBiz ? 'none' : ''; });
    var lbl = form.querySelector('[data-biz-namelabel]');
    if (lbl) lbl.textContent = isBiz ? 'Contact first name' : 'First name';
    var ex = form.querySelector('[data-biz-exempt]');
    var cert = form.querySelector('[data-biz-cert]');
    if (cert) cert.style.display = (isBiz && ex && ex.checked) ? '' : 'none';
  }
  document.querySelectorAll('[data-biz-form]').forEach(function (form) {
    form.addEventListener('change', function (e) {
      if (e.target.name === 'customer_type' || e.target.hasAttribute('data-biz-exempt')) sync(form);
    });
    sync(form);
  });
})();
</script>

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

@push('scripts')
<script>
  // Activity timeline — month collapse and dropdown filter.
  // Both behaviors are local-only state (refresh resets) — keeps the
  // implementation small and avoids per-customer preference storage.
  function toggleActMonth(headEl) {
    const monthEl = headEl.parentElement;
    const body = monthEl.querySelector('.act-month-body');
    const chevDown = monthEl.querySelector('.ti-chevron-down');
    const chevRight = monthEl.querySelector('.ti-chevron-right');
    const isExpanded = body.style.display !== 'none';

    body.style.display = isExpanded ? 'none' : 'block';
    chevDown.style.display = isExpanded ? 'none' : 'inline-block';
    chevRight.style.display = isExpanded ? 'inline-block' : 'none';
  }

  (function bindActivityFilter() {
    const sel = document.getElementById('activity-filter');
    if (!sel) return;
    sel.addEventListener('change', () => {
      const value = sel.value;
      document.querySelectorAll('.act-row').forEach(row => {
        const kind = row.dataset.actKind;
        const show = value === 'all' || kind === value;
        row.style.display = show ? 'grid' : 'none';
      });
      // Hide month headers for months with zero matching events.
      // Empty months are noise; collapse them out of view entirely.
      document.querySelectorAll('.act-month').forEach(month => {
        const visible = month.querySelectorAll('.act-row:not([style*="display: none"])').length > 0;
        month.style.display = visible ? 'block' : 'none';
      });
    });
  })();
</script>
@endpush
BIZ1_7_EOF

echo "business-customers-1-data applied — server: git pull && php artisan migrate --force && php artisan view:clear"
