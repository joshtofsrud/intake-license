#!/bin/bash
# business-customers-3-surfaces — phase 3 of 3: display, list, work order,
# receipts and the settings defaults.
#   DISPLAY PASS: 37 places concatenated first_name . ' ' . last_name inline,
#   which would have kept showing a person's name on a business record.
#   Converted in three careful passes:
#     · high-confidence customer expressions -> fullName()
#     · nullsafe relations ($x?->customer?->...) and customer-report rows
#     · RAW QUERY ROWS (top customers, lapsed customers) have no model
#       methods, so business_name/customer_type are now selected and the
#       name chosen inline — calling fullName() there would have fataled
#   Deliberately NOT converted, and why: staff names (rangUpBy), request
#   input, and the appointment's denormalized customer_first_name /
#   customer_last_name snapshot columns on the drop-off calendar cards —
#   those record who physically dropped off, and have no customer relation
#   to resolve. customerName() already routes through fullName(), so the
#   work order and everything using it became business-aware automatically.
#   SURFACES: businesses-only list filter with Business / Tax exempt pills;
#   work-order customer block showing the business pill, primary contact,
#   terms, exemption and PO-required (both customer blocks on that page);
#   receipt billed to the business name, with the exemption REASON and the
#   PO reference printed — a $0.00 tax line is not processable otherwise.
#   SETTINGS: Ordering tab gains "Business customers — defaults" (default
#   payment terms, default PO required) so those two fields are not
#   configured one customer at a time.
#   Also fixed: the special-order drawer label on the work order still
#   concatenated a person's name.
# No routes, no migration. Server: view:clear.
# REMAINING: the contacts panel (add/edit/remove, set primary) ships next as
# its own patch — it is the only surface from the spec not in these three.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-BIZ-RECEIPT" resources/views/tenant/register/receipt.blade.php; then
  echo "phase 3 already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-BIZ-TAX" app/Services/Tenant/SaleService.php; then
  echo "phase 2 not applied — wrong base, aborting."; exit 1
fi
mkdir -p app/Models/Tenant app/Http/Controllers/Tenant app/Services/Tenant \
         resources/views/tenant/settings resources/views/tenant/customers \
         resources/views/tenant/appointments resources/views/tenant/register \
         resources/views/tenant/inventory/receiving resources/views/tenant/special-orders \
         resources/views/tenant/inbox resources/views/tenant/rentals/units \
         resources/views/tenant/rentals/bookings

cat > 'app/Models/Tenant/TenantAppointment.php' <<'BIZ3_0_EOF'
<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Support\AppointmentStatus;

class TenantAppointment extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointments';
    protected $fillable = [
        'tenant_id','customer_id','resource_id','location_id','ra_number',
        'customer_first_name','customer_last_name','customer_email','customer_phone',
        'appointment_date','appointment_time','appointment_end_time',
        'promised_at', // MARKER-PATCH-311
        'pickup_outreach_pending', // MARKER-PICKUP-OUTREACH
        'delivery_resolution', 'delivery_resolved_at',            // MARKER-DELIVERY-RESOLUTION
        'delivery_resolved_by_user_id', 'delivery_snooze_until',  // MARKER-DELIVERY-RESOLUTION
        'total_duration_minutes','prep_before_minutes_snapshot','cleanup_after_minutes_snapshot',
        'slot_weight','slot_weight_auto','slot_weight_overridden',
        'receiving_method_snapshot','receiving_time_snapshot','tracking_number',
        'status','payment_status','payment_method',
        'stripe_payment_intent_id','paypal_order_id',
        'subtotal_cents','tax_cents','total_cents','paid_cents','staff_notes',
        'invoice_note','invoice_terms', // MARKER-PATCH-204
        'needs_time_review',
        'reminded_at', // MARKER-PATCH-154
        'completed_at', // MARKER-PATCH-481
    ];
    protected $casts = [
        'appointment_date'         => 'date',
        'total_duration_minutes'         => 'integer',
        'prep_before_minutes_snapshot'   => 'integer',
        'cleanup_after_minutes_snapshot' => 'integer',
        'slot_weight'                    => 'integer',
        'slot_weight_auto'         => 'integer',
        'slot_weight_overridden'   => 'boolean',
        'needs_time_review'        => 'boolean',
        'subtotal_cents'           => 'integer',
        'tax_cents'                => 'integer',
        'total_cents'              => 'integer',
        'paid_cents'               => 'integer',
        'reminded_at'              => 'datetime', // MARKER-PATCH-154
        'promised_at'              => 'datetime', // MARKER-PATCH-311
        'completed_at'             => 'datetime', // MARKER-PATCH-481
    ];

    // MARKER-PATCH-481 — stamp the actual completion instant once, on the first
    // transition into a done state, from any write path. Pairs with promised_at to
    // measure late_completion; never overwritten (records the first completion).
    protected static function booted(): void
    {
        static::saving(function (self $appt) {
            if (! $appt->completed_at
                && $appt->isDirty('status')
                && in_array($appt->status, ['completed', 'shipped', 'closed'], true)) {
                $appt->completed_at = tnow()->utc();
            }
        });

        // MARKER-PATCH-482 — once a completion is stamped, evaluate quality signals
        // (late_completion) for the customer's recovery history.
        static::saved(function (self $appt) {
            if ($appt->wasChanged('completed_at') && $appt->completed_at && $appt->customer_id) {
                app(\App\Services\Tenant\RecoverySignalService::class)->evaluate($appt);
            }
        });

        // MARKER-PATCH-485 — a shop-side date move on a live appointment (one the
        // customer was already expecting) is a reschedule signal. New bookings and
        // pending/cancelled rows don't count.
        static::saved(function (self $appt) {
            if ($appt->wasChanged('appointment_date')
                && ! $appt->wasRecentlyCreated
                && $appt->customer_id
                && ! in_array($appt->status, ['pending', 'cancelled', 'refunded'], true)) {
                app(\App\Services\Tenant\RecoverySignalService::class)
                    ->reschedule($appt, $appt->getOriginal('appointment_date'));
            }
        });
    }

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo  { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function resource(): BelongsTo  { return $this->belongsTo(TenantResource::class, 'resource_id'); }
    public function location(): BelongsTo  { return $this->belongsTo(TenantLocation::class, 'location_id'); }
    public function items(): HasMany       { return $this->hasMany(TenantAppointmentItem::class, 'appointment_id'); }
    public function addons(): HasMany      { return $this->hasMany(TenantAppointmentAddon::class, 'appointment_id'); }

    // MARKER-PATCH-158-A — multi-asset support
    public function assets(): HasMany
    {
        return $this->hasMany(TenantAppointmentAsset::class, 'appointment_id')->orderBy('sort_order');
    }
    public function parts(): HasMany       { return $this->hasMany(TenantAppointmentPart::class, 'appointment_id'); }
    public function responses(): HasMany   { return $this->hasMany(TenantAppointmentResponse::class, 'appointment_id'); }
    public function notes(): HasMany       { return $this->hasMany(TenantAppointmentNote::class, 'appointment_id')->orderBy('created_at'); }
    public function charges(): HasMany     { return $this->hasMany(TenantAppointmentCharge::class, 'appointment_id'); }
    // MARKER-PATCH-176 — payments now live on the linked SALE ledger (sales-as-
    // money). An appointment reaches its payments THROUGH its sale(s):
    // appointment_id on tenant_sales, sale_id on tenant_sale_payments. Same row
    // shape (kind/amount_cents/recorded_at) so existing reads keep working.
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            TenantSalePayment::class,
            TenantSale::class,
            'appointment_id', // FK on tenant_sales -> appointments
            'sale_id',        // FK on tenant_sale_payments -> sales
            'id',             // local key on appointments
            'id'              // local key on sales
        )->orderBy('tenant_sale_payments.recorded_at');
    }
    public function sales(): HasMany       { return $this->hasMany(TenantSale::class, 'appointment_id'); }
    public function specialOrders(): HasMany { return $this->hasMany(TenantSpecialOrder::class, 'appointment_id'); }

    public function scopeActive($q)        { return $q->whereNotIn('status', AppointmentStatus::terminalStatuses()); }
    public function customerName(): string
    {
        // MARKER-PATCH-421 — live customer via customer_id is the source of truth;
        // the snapshot is only a fallback for a deleted customer record.
        return $this->customer
            ? trim($this->customer->fullName())
            : trim(($this->customer_first_name ?? '') . ' ' . ($this->customer_last_name ?? ''));
    }
    public function isPaid(): bool         { return $this->payment_status === 'paid'; }

    public function customerVisibleMinutes(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += (int) ($item->duration_minutes_snapshot ?? 0);
        }
        foreach ($this->addons as $addon) {
            $total += (int) ($addon->duration_minutes_snapshot ?? 0);
        }
        return $total;
    }

    public static function generateRaNumber(string $tenantId, ?string $appointmentDate = null): string
    {
        $date = $appointmentDate ? new \DateTimeImmutable($appointmentDate) : new \DateTimeImmutable('today');
        $datePart = $date->format('mdy');
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $random = '';
            for ($i = 0; $i < 5; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $candidate = "ITO-{$datePart}-{$random}";
            $exists = static::where('tenant_id', $tenantId)->where('ra_number', $candidate)->exists();
            if (!$exists) return $candidate;
        }
        throw new \RuntimeException('Could not generate a unique RA number after 6 attempts.');
    }

    public function workOrderResponses()
    {
        return $this->hasMany(TenantAppointmentWorkOrderResponse::class, 'appointment_id');
    }

    public function workOrderFields()
    {
        return $this->hasMany(TenantWorkOrderField::class, 'tenant_id', 'tenant_id')
            ->orderBy('sort_order');
    }

    /**
     * Sum the ledger. Authoritative — paid_cents column is just a cache.
     * Use this when you need to be sure (e.g. in the status hook).
     */
    public function paidCentsFromLedger(): int
    {
        // Allow callers to use already-loaded relation without an extra query.
        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->sum('amount_cents');
        }
        return (int) $this->payments()->sum('amount_cents');
    }

    /**
     * What the customer still owes. Negative means tenant owes customer (overage).
     * Reads from cached paid_cents — load payments and call paidCentsFromLedger()
     * if you need ledger-truth.
     */
    public function balanceDueCents(): int
    {
        return (int) $this->total_cents - (int) $this->paid_cents;
    }

    /**
     * Whether there's an active (non-voided) register sale tied to this
     * appointment. If true, the appointment is locked from edits.
     *
     * "Active" = sale exists, status is not 'cancelled'.
     */
    public function hasActiveRegisterSale(): bool
    {
        if ($this->relationLoaded('sales')) {
            return $this->sales->where('status', '!=', 'cancelled')->isNotEmpty();
        }
        return $this->sales()->where('status', '!=', 'cancelled')->exists();
    }

    /**
     * The single open draft sale created by the auto-send-on-Completed flow.
     * Returns null if there is no sale, or if the sale is closed/paid.
     *
     * Uses the SaleService convention: drafts have payment_status='draft'.
     */
    public function openRegisterSale(): ?TenantSale
    {
        return $this->sales()
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->where('payment_status', 'draft')
            ->latest('created_at')
            ->first();
    }

    /**
     * MARKER-PATCH-194 — a live payment-link sale awaiting the customer:
     * has a Stripe checkout session, not yet paid, not cancelled. Drives the
     * "payment pending" banner so a link sent from this appointment is visible
     * and trackable instead of floating until it resolves.
     */
    public function pendingPaymentLinkSale(): ?TenantSale
    {
        return $this->sales()
            ->whereNotNull('checkout_session_id')
            ->whereNotIn('status', ['cancelled', 'closed', 'completed'])
            ->whereNotIn('payment_status', ['paid', 'refunded'])
            ->latest('created_at')
            ->first();
    }

}
BIZ3_0_EOF

cat > 'app/Http/Controllers/Tenant/CustomerController.php' <<'BIZ3_1_EOF'
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
BIZ3_1_EOF

cat > 'app/Http/Controllers/Tenant/DailyOpsController.php' <<'BIZ3_2_EOF'
<?php
// MARKER-PATCH-633 — Reports → Daily ops → End of day.
// All money from the sales-as-money ledger (tenant_sale_payments) bucketed by
// tenant-local day; gross/tax/tips from the sale rows paid that day. Drawer
// reconciliation lives in tenant_drawer_days; closing the day snapshots the
// numbers so history can't drift as data changes.
// TIMEZONE: a "day" is the tenant-local calendar day converted to a UTC range.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDrawerDay;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSalePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailyOpsController extends Controller
{
    public function endOfDay(Request $request)
    {
        $tenant = tenant();
        [$day, $fromUtc, $toUtc] = $this->dayWindow($request);

        $drawer = TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->whereDate('day', $day->toDateString())
            ->first();

        // Closed days render from the snapshot — immutable history.
        if ($drawer && $drawer->isClosed() && $drawer->snapshot) {
            $n = $drawer->snapshot;
        } else {
            $n = $this->numbersFor($fromUtc, $toUtc);
        }

        $attention = $this->attention($fromUtc, $toUtc);

        return view('tenant.reports.daily', [
            'day'       => $day,
            'n'         => $n,
            'drawer'    => $drawer,
            'attention' => $attention,
            'isToday'   => $day->isSameDay(tnow()),
        ]);
    }

    public function saveDrawer(Request $request)
    {
        $tenant = tenant();
        [$day] = $this->dayWindow($request);

        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:100000'],
            'paid_out'      => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'paid_out_note' => ['nullable', 'string', 'max:200'],
            'counted'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $drawer = TenantDrawerDay::firstOrNew([
            'tenant_id'   => $tenant->id,
            'location_id' => null,
            'day'         => $day->toDateString(),
        ]);
        abort_if($drawer->isClosed(), 422, 'This day is closed.');

        $drawer->fill([
            'opening_float_cents' => (int) round($data['opening_float'] * 100),
            'paid_out_cents'      => (int) round(($data['paid_out'] ?? 0) * 100),
            'paid_out_note'       => $data['paid_out_note'] ?? null,
            'counted_cents'       => $request->filled('counted') ? (int) round($data['counted'] * 100) : null,
        ])->save();

        return back()->with('success', 'Drawer saved.');
    }

    public function closeDay(Request $request)
    {
        $tenant = tenant();
        [$day, $fromUtc, $toUtc] = $this->dayWindow($request);

        $drawer = TenantDrawerDay::firstOrNew([
            'tenant_id'   => $tenant->id,
            'location_id' => null,
            'day'         => $day->toDateString(),
        ]);
        abort_if($drawer->isClosed(), 422, 'Already closed.');
        if ($drawer->counted_cents === null) {
            return back()->with('error', 'Count the drawer before closing the day.');
        }

        $n = $this->numbersFor($fromUtc, $toUtc);
        $expected = $drawer->opening_float_cents + $n['cash_collected'] - $n['cash_refunds'] - $drawer->paid_out_cents;

        $drawer->fill([
            'expected_cents'   => $expected,
            'over_short_cents' => $drawer->counted_cents - $expected,
            'snapshot'         => $n,
            'closed_by'        => Auth::guard('tenant')->id(),
            'closed_at'        => now(),
        ])->save();

        return back()->with('success', 'Day closed — numbers locked.');
    }

    public function reopenDay(Request $request)
    {
        $tenant = tenant();
        [$day] = $this->dayWindow($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $drawer = TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereNull('location_id')->whereDate('day', $day->toDateString())->firstOrFail();

        $drawer->fill([
            'closed_at' => null,
            'closed_by' => null,
            'snapshot'  => null,
            'paid_out_note' => trim(($drawer->paid_out_note ? $drawer->paid_out_note . ' · ' : '') . 'Reopened: ' . $data['reason']),
        ])->save();

        return back()->with('success', 'Day reopened.');
    }

    public function printDay(Request $request)
    {
        $tenant = tenant();
        [$day, $fromUtc, $toUtc] = $this->dayWindow($request);
        $drawer = TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereNull('location_id')->whereDate('day', $day->toDateString())->first();
        $n = ($drawer && $drawer->isClosed() && $drawer->snapshot) ? $drawer->snapshot : $this->numbersFor($fromUtc, $toUtc);

        return view('tenant.reports.daily-print', ['day' => $day, 'n' => $n, 'drawer' => $drawer, 'tenant' => $tenant]);
    }

    /* ------------------------------------------------------------ reconciliation (MARKER-PATCH-635) */

    public function reconciliation(Request $request)
    {
        $tenant = tenant();
        $tz = $tenant->timezone();
        $week = $request->filled('week')
            ? \Carbon\Carbon::parse($request->input('week'), $tz)->startOfWeek()
            : tnow()->startOfWeek();
        $fromUtc = $week->copy()->utc();
        $toUtc   = $week->copy()->addWeek()->utc();

        $svc = new \App\Services\Tenant\PayoutReconService($tenant);

        $payouts = \App\Models\Tenant\TenantStripePayout::where('tenant_id', $tenant->id)
            ->whereBetween('arrived_on', [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])
            ->orderByDesc('arrived_on')->get();

        // flat unmatched list across the week's payouts
        $unmatched = [];
        foreach ($payouts as $po) {
            foreach ((array) $po->items as $it) {
                if (! ($it['matched'] ?? false)) {
                    $unmatched[] = ['payout' => $po->payout_id, 'charge' => $it['charge'] ?? '', 'pi' => $it['pi'] ?? null,
                                    'amount' => (int) ($it['amount'] ?? 0), 'created' => (int) ($it['created'] ?? 0)];
                }
            }
        }

        // cash week from closed drawer days
        $cashWeek = \App\Models\Tenant\TenantDrawerDay::where('tenant_id', $tenant->id)
            ->whereBetween('day', [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])
            ->orderBy('day')->get();

        return view('tenant.reports.daily-recon', [
            'week'      => $week,
            'payouts'   => $payouts,
            'unmatched' => $unmatched,
            'cashWeek'  => $cashWeek,
            'available' => $svc->available(),
            'lastFetch' => $payouts->max('fetched_at'),
        ]);
    }

    public function reconciliationRefresh(Request $request)
    {
        $tenant = tenant();
        $tz = $tenant->timezone();
        $week = $request->filled('week')
            ? \Carbon\Carbon::parse($request->input('week'), $tz)->startOfWeek()
            : tnow()->startOfWeek();

        try {
            $n = (new \App\Services\Tenant\PayoutReconService($tenant))
                ->refreshRange($week->copy()->utc(), $week->copy()->addWeek()->utc());
        } catch (\Throwable $e) {
            logger()->warning('payout recon refresh failed', ['err' => $e->getMessage()]);
            return back()->with('error', 'Stripe fetch failed — check your Stripe keys and try again.');
        }

        return back()->with('success', $n . ' payout(s) fetched and matched.');
    }

    /** MARKER-PATCH-635 — Xero bank statement CSV from cached payouts. */
    public function exportXero(Request $request)
    {
        $tenant = tenant();
        [$from, $to, $label] = $this->rangeWindow($request);

        $payouts = \App\Models\Tenant\TenantStripePayout::where('tenant_id', $tenant->id)
            ->whereBetween('arrived_on', [tlocal_carbon($from)->toDateString(), tlocal_carbon($to)->toDateString()])
            ->orderBy('arrived_on')->get();

        return response()->streamDownload(function () use ($payouts) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Amount', 'Payee', 'Description', 'Reference']);
            foreach ($payouts as $po) {
                fputcsv($out, [
                    $po->arrived_on->format('d/m/Y'),
                    number_format($po->net_cents / 100, 2, '.', ''),
                    'Stripe',
                    'Stripe payout — gross ' . number_format($po->gross_cents / 100, 2) . ', fees ' . number_format($po->fee_cents / 100, 2),
                    $po->payout_id,
                ]);
            }
            fclose($out);
        }, 'xero-statement-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------ exports (MARKER-PATCH-634) */

    public function exports(Request $request)
    {
        [$from, $to, $label] = $this->rangeWindow($request);
        return view('tenant.reports.daily-exports', ['from' => $from, 'to' => $to, 'label' => $label]);
    }

    /**
     * QuickBooks Online journal CSV — one balanced journal entry per day.
     * Debits: per-method collected (net of that method's refunds) into its
     * deposit account. Credits: sales income (derived), tax payable, tips.
     * Income = collected − tax − tips, so debits always equal credits.
     * Account names come from each method's QB mapping when set (stage 4),
     * with sensible defaults until then.
     */
    public function exportQbJournal(Request $request)
    {
        $tenant = tenant();
        [$from, $to, $label] = $this->rangeWindow($request);

        $qbMap = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $tenant->id)->get()
            ->keyBy('method_key')->map(fn ($m) => $m->qb['deposit_account'] ?? null);

        $days = $this->dailyBreakdown($from, $to);

        // MARKER-PATCH-636 — global credit accounts from settings
        $st = $tenant->settings ?? [];
        $acctIncome = $st['qb_income_account'] ?? 'Sales';
        $acctTax    = $st['qb_tax_account'] ?? 'Sales Tax Payable';
        $acctTips   = $st['qb_tips_account'] ?? 'Tips Payable';

        return response()->streamDownload(function () use ($days, $qbMap, $acctIncome, $acctTax, $acctTips) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description']);
            $no = 1;
            foreach ($days as $d) {
                if ($d['collected'] === 0 && $d['refunds'] === 0) continue;
                $date = $d['date'];
                $desc = 'Daily sales ' . $date;
                foreach ($d['by_method'] as $bm) {
                    $net = $bm['collected'] - $bm['refunded'];
                    if ($net === 0) continue;
                    $acct = $qbMap[$bm['method']] ?? ($bm['method'] === 'card' ? 'Stripe Clearing' : 'Undeposited Funds');
                    if ($net > 0) fputcsv($out, [$no, $date, $acct, number_format($net / 100, 2, '.', ''), '', $desc . ' — ' . $bm['label']]);
                    else          fputcsv($out, [$no, $date, $acct, '', number_format(abs($net) / 100, 2, '.', ''), $desc . ' — ' . $bm['label'] . ' (net refund)']);
                }
                $netCollected = $d['collected'] - $d['refunds'];
                $income = $netCollected - $d['tax'] - $d['tips'];
                if ($income !== 0) fputcsv($out, [$no, $date, $acctIncome, $income < 0 ? number_format(abs($income) / 100, 2, '.', '') : '', $income > 0 ? number_format($income / 100, 2, '.', '') : '', $desc . ' — income']);
                if ($d['tax'] > 0)  fputcsv($out, [$no, $date, $acctTax, '', number_format($d['tax'] / 100, 2, '.', ''), $desc . ' — sales tax']);
                if ($d['tips'] > 0) fputcsv($out, [$no, $date, $acctTips, '', number_format($d['tips'] / 100, 2, '.', ''), $desc . ' — tips']);
                $no++;
            }
            fclose($out);
        }, 'quickbooks-journal-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Every payment row with sale context — the everything file. */
    public function exportDetail(Request $request)
    {
        $tenant = tenant();
        [$from, $to, $label] = $this->rangeWindow($request);

        return response()->streamDownload(function () use ($tenant, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Recorded (local)', 'Kind', 'Method', 'Amount', 'Sale total', 'Tax', 'Tip', 'Customer', 'Source', 'Reference']);
            TenantSalePayment::where('tenant_sale_payments.tenant_id', $tenant->id)
                ->where('recorded_at', '>=', $from)->where('recorded_at', '<', $to)
                ->leftJoin('tenant_sales', 'tenant_sales.id', '=', 'tenant_sale_payments.sale_id')
                ->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_sale_payments.customer_id')
                ->orderBy('recorded_at')
                ->select(['tenant_sale_payments.*',
                          'tenant_sales.total_cents as s_total', 'tenant_sales.tax_cents as s_tax', 'tenant_sales.tip_cents as s_tip',
                          'tenant_customers.first_name as c_first', 'tenant_customers.last_name as c_last'])
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $sign = in_array($r->kind, ['refund', 'overage_refund'], true) ? -1 : 1;
                        fputcsv($out, [
                            tlocal($r->recorded_at, 'Y-m-d H:i'),
                            $r->kind,
                            tender_label($r->method),
                            number_format($sign * abs($r->amount_cents) / 100, 2, '.', ''),
                            $r->s_total !== null ? number_format($r->s_total / 100, 2, '.', '') : '',
                            $r->s_tax !== null ? number_format($r->s_tax / 100, 2, '.', '') : '',
                            $r->s_tip !== null ? number_format($r->s_tip / 100, 2, '.', '') : '',
                            trim(($r->c_first ?? '') . ' ' . ($r->c_last ?? '')),
                            $r->source,
                            $r->external_reference,
                        ]);
                    }
                });
            fclose($out);
        }, 'sales-payments-detail-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Tax by day — gross, taxable, tax collected. Shaped for the WA excise return. */
    public function exportTax(Request $request)
    {
        [$from, $to, $label] = $this->rangeWindow($request);
        $days = $this->dailyBreakdown($from, $to);

        return response()->streamDownload(function () use ($days) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Sales', 'Gross', 'Taxable (gross − tax)', 'Tax collected']);
            $tg = $tt = 0;
            foreach ($days as $d) {
                if ($d['gross'] === 0 && $d['tax'] === 0) continue;
                fputcsv($out, [$d['date'], $d['sale_count'],
                    number_format($d['gross'] / 100, 2, '.', ''),
                    number_format(($d['gross'] - $d['tax']) / 100, 2, '.', ''),
                    number_format($d['tax'] / 100, 2, '.', '')]);
                $tg += $d['gross']; $tt += $d['tax'];
            }
            fputcsv($out, ['TOTAL', '', number_format($tg / 100, 2, '.', ''), number_format(($tg - $tt) / 100, 2, '.', ''), number_format($tt / 100, 2, '.', '')]);
            fclose($out);
        }, 'tax-summary-' . $label . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Per-tenant-local-day numbers across a range (reuses numbersFor per day). */
    private function dailyBreakdown($fromUtc, $toUtc): array
    {
        $tenant = tenant();
        $days = [];
        $cursor = tlocal_carbon($fromUtc)->startOfDay();
        $endLocal = tlocal_carbon($toUtc);
        while ($cursor->lt($endLocal)) {
            $dFrom = $cursor->copy()->utc();
            $dTo   = $cursor->copy()->addDay()->utc();
            $n = $this->numbersFor($dFrom, $dTo);
            $n['date'] = $cursor->toDateString();
            $days[] = $n;
            $cursor->addDay();
        }
        return $days;
    }

    /** [fromUtc, toUtc, filenameLabel] from ?from=&to= (defaults: current month). */
    private function rangeWindow(Request $request): array
    {
        $tenant = tenant();
        $tz = $tenant->timezone();
        $from = $request->filled('from')
            ? \Carbon\Carbon::parse($request->input('from'), $tz)->startOfDay()
            : tnow()->startOfMonth();
        $to = $request->filled('to')
            ? \Carbon\Carbon::parse($request->input('to'), $tz)->endOfDay()
            : tnow()->endOfDay();
        return [$from->copy()->utc(), $to->copy()->utc(), $from->format('Y-m-d') . '_' . $to->format('Y-m-d')];
    }

    /* ------------------------------------------------------------ numbers */

    private function numbersFor($fromUtc, $toUtc): array
    {
        $tenant = tenant();

        $pay = TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('recorded_at', '>=', $fromUtc)->where('recorded_at', '<', $toUtc);

        $collectKinds = [TenantSalePayment::KIND_PAYMENT, TenantSalePayment::KIND_BALANCE, TenantSalePayment::KIND_DEPOSIT];
        $refundKinds  = [TenantSalePayment::KIND_REFUND, TenantSalePayment::KIND_OVERAGE_REFUND];

        // by-method table: collected and refunded per method
        $byMethod = (clone $pay)
            ->selectRaw("COALESCE(method,'unknown') as m,
                SUM(CASE WHEN kind IN ('payment','balance','deposit') THEN amount_cents ELSE 0 END) as collected,
                SUM(CASE WHEN kind IN ('refund','overage_refund') THEN amount_cents ELSE 0 END) as refunded,
                SUM(CASE WHEN kind IN ('payment','balance','deposit') THEN 1 ELSE 0 END) as n")
            ->groupBy('m')->get()
            ->map(fn ($r) => [
                'method'    => $r->m,
                'label'     => tender_label($r->m),
                'count'     => (int) $r->n,
                'collected' => (int) $r->collected,
                'refunded'  => (int) abs($r->refunded),
            ])
            ->sortByDesc('collected')->values()->all();

        $collected = array_sum(array_column($byMethod, 'collected'));
        $refunds   = array_sum(array_column($byMethod, 'refunded'));
        $deposits  = (int) (clone $pay)->where('kind', TenantSalePayment::KIND_DEPOSIT)->sum('amount_cents');

        $cashRow = collect($byMethod)->firstWhere('method', 'cash') ?? ['collected' => 0, 'refunded' => 0];

        // gross / tax / tips from sales paid this day
        $sales = TenantSale::where('tenant_id', $tenant->id)
            ->where('paid_at', '>=', $fromUtc)->where('paid_at', '<', $toUtc)
            ->whereNull('refund_of_sale_id');
        $salesAgg = (clone $sales)->selectRaw('COUNT(*) as n, COALESCE(SUM(total_cents),0) as gross, COALESCE(SUM(tax_cents),0) as tax, COALESCE(SUM(tip_cents),0) as tips')->first();

        return [
            'gross'          => (int) $salesAgg->gross,
            'sale_count'     => (int) $salesAgg->n,
            'collected'      => $collected,
            'refunds'        => $refunds,
            'tax'            => (int) $salesAgg->tax,
            'tips'           => (int) $salesAgg->tips,
            'deposits'       => $deposits,
            'cash_collected' => (int) $cashRow['collected'],
            'cash_refunds'   => (int) $cashRow['refunded'],
            'by_method'      => $byMethod,
        ];
    }

    private function attention($fromUtc, $toUtc): array
    {
        $tenant = tenant();
        $items = [];

        // open register drafts
        $drafts = TenantSale::where('tenant_id', $tenant->id)->drafts()
            ->orderByDesc('created_at')->limit(5)->get(['id', 'total_cents', 'created_at']);
        foreach ($drafts as $d) {
            $items[] = ['label' => 'Draft sale open on the register', 'amount' => (int) $d->total_cents,
                        'tag' => 'draft', 'url' => route('tenant.register.index')];
        }

        // online orders awaiting a manual payment (631)
        $pending = TenantOrder::where('tenant_id', $tenant->id)
            ->where('status', TenantOrder::STATUS_PENDING_PAYMENT)
            ->whereNotNull('payment_method')
            ->orderByDesc('created_at')->limit(10)
            ->get(['id', 'order_number', 'total_cents', 'payment_method']);
        foreach ($pending as $o) {
            $items[] = ['label' => 'Awaiting ' . tender_label($o->payment_method) . ' — order ' . $o->order_number,
                        'amount' => (int) $o->total_cents, 'tag' => 'pending',
                        'url' => route('tenant.orders.show', $o->id)];
        }

        // completed appointments with a balance
        $unpaid = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->whereIn('status', ['completed', 'shipped', 'closed'])
            ->whereColumn('paid_cents', '<', 'total_cents')
            ->where('total_cents', '>', 0)
            ->with('customer:id,first_name,last_name')
            ->orderByDesc('appointment_date')->limit(5)
            ->get(['id', 'customer_id', 'total_cents', 'paid_cents', 'appointment_date', 'status']);
        foreach ($unpaid as $a) {
            $due = max(0, (int) $a->total_cents - (int) $a->paid_cents);
            if ($due === 0) continue;
            $who = $a->customer ? trim($a->customer->fullName()) : 'customer';
            $items[] = ['label' => 'Unpaid job — ' . $who,
                        'amount' => $due, 'tag' => 'unpaid',
                        'url' => route('tenant.appointments.show', $a->id)];
        }

        return array_slice($items, 0, 8);
    }

    /** [$dayCarbon(tenant-local midnight), $fromUtc, $toUtc] from ?day=YYYY-MM-DD */
    private function dayWindow(Request $request): array
    {
        $tenant = tenant();
        $day = $request->filled('day')
            ? \Carbon\Carbon::parse($request->input('day'), $tenant->timezone())->startOfDay()
            : tnow()->startOfDay();
        return [$day, $day->copy()->utc(), $day->copy()->addDay()->utc()];
    }
}

BIZ3_2_EOF

cat > 'app/Http/Controllers/Tenant/SettingsController.php' <<'BIZ3_3_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified settings controller. Absorbs the previous BrandingController so the
 * settings page is a single tabbed view. The `tab` request input discriminates
 * which group of fields to validate and persist.
 *
 * Tabs:
 *  - business      currency, timezone, booking, tax, drop-off methods (CRUD via ReceivingMethodController)
 *  - branding      shop name, tagline, logos, colors, typography
 *  - communication email sender details, SMS provider config, notification toggles
 *  - account       custom domain (booking URL is read-only)
 *  - appearance    admin theme
 *  - payments      Stripe + PayPal API keys
 */
class SettingsController extends Controller
{
    public function index()
    {
        $tenant = tenant();
        $receivingMethods = \App\Models\Tenant\TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $paymentMethods = \App\Models\Tenant\TenantPaymentMethod::bootstrapFor($tenant); // MARKER-PATCH-629
        return view('tenant.settings.index', compact('receivingMethods', 'paymentMethods'));
    }

    public function update(Request $request)
    {
        $tenant = tenant();
        $tab    = $request->input('tab', 'business');

        return match ($tab) {
            'business'      => $this->updateBusiness($request, $tenant),
            'branding'      => $this->updateBranding($request, $tenant),
            'communication' => $this->updateCommunication($request, $tenant),
            'account'       => $this->updateAccount($request, $tenant),
            'appearance'    => $this->updateAppearance($request, $tenant),
            'payments'      => $this->updatePayments($request, $tenant),
            'tags'          => $this->updateTags($request, $tenant), // MARKER-PATCH-315
            'ordering'      => $this->updateOrdering($request, $tenant), // MARKER-SO-AUTOVENDOR
            default         => back()->with('error', 'Unknown tab.'),
        };
    }

    // -------------------------------------------------------------------
    // MARKER-SO-AUTOVENDOR — how special orders choose a vendor.
    // -------------------------------------------------------------------
    private function updateOrdering(Request $request, $tenant)
    {
        $request->validate([
            'so_auto_assign_vendor' => ['required', 'in:preferred,lowest_price,off'],
        ]);

        // MARKER-BIZ-SETTINGS — business-customer defaults share this tab.
        $request->validate([
            'cust_default_payment_terms' => ['nullable', 'in:' . implode(',', \App\Models\Tenant\TenantCustomer::PAYMENT_TERMS)],
        ]);

        $settings = $tenant->settings ?? [];
        $so = (array) ($settings['special_orders'] ?? []);
        $so['auto_assign_vendor'] = $request->input('so_auto_assign_vendor');
        $settings['special_orders'] = $so;

        $cust = (array) ($settings['customers'] ?? []);
        $cust['default_payment_terms'] = $request->input('cust_default_payment_terms') ?: null;
        $cust['default_po_required']   = $request->boolean('cust_default_po_required');
        $settings['customers'] = $cust;

        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Ordering settings saved.');
    }

    // -------------------------------------------------------------------
    // MARKER-PATCH-315 — Work-order tag settings (toggles, lead time,
    // paper width, thermal logo). Stored in the tenant settings JSON.
    // -------------------------------------------------------------------
    private function updateTags(Request $request, $tenant)
    {
        $request->validate([
            'wot_lead_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'wot_paper'     => ['nullable', 'in:80mm,58mm'],
            'wot_header_text' => ['nullable', 'string', 'max:500'], // MARKER-PATCH-330
            'wot_footer_text' => ['nullable', 'string', 'max:500'], // MARKER-PATCH-330
            'wot_logo'      => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = $tenant->settings ?? [];
        $wot = (array) ($settings['work_order_tag'] ?? []);

        $wot['enabled']       = (bool) $request->input('wot_enabled');
        $wot['show_header']   = (bool) $request->input('wot_show_header');
        $wot['show_phone']    = (bool) $request->input('wot_show_phone');
        $wot['show_bike']     = (bool) $request->input('wot_show_bike');
        $wot['show_services'] = (bool) $request->input('wot_show_services');
        $wot['show_note']     = (bool) $request->input('wot_show_note');
        $wot['show_qr']       = (bool) $request->input('wot_show_qr');
        $wot['show_stub']     = (bool) $request->input('wot_show_stub');
        $wot['lead_days']     = $request->filled('wot_lead_days') ? (int) $request->input('wot_lead_days') : 3;
        $wot['paper']         = $request->input('wot_paper', '80mm');
        $wot['logo_size']     = in_array($request->input('wot_logo_size'), ['small', 'medium', 'large', 'xl'], true) ? $request->input('wot_logo_size') : 'medium'; // MARKER-PATCH-317
        $wot['feed_mm']       = max(0, min(40, (int) $request->input('wot_feed_mm', 0))); // MARKER-PATCH-320
        $wot['header_text']   = trim((string) $request->input('wot_header_text', '')); // MARKER-PATCH-330
        $wot['footer_text']   = trim((string) $request->input('wot_footer_text', '')); // MARKER-PATCH-330

        if ($request->hasFile('wot_logo')) {
            $wot['logo_path'] = $request->file('wot_logo')->store("tenants/{$tenant->id}/work-order-tag", 'public');
        } elseif ($request->input('wot_logo_remove') === '1') {
            $wot['logo_path'] = null;
        }

        $settings['work_order_tag'] = $wot;
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Work-order tag settings saved.');
    }

    // -------------------------------------------------------------------
    // Business: currency, timezone, booking window, classes, tax
    // -------------------------------------------------------------------
    private function updateBusiness(Request $request, $tenant)
    {
        $request->validate([
            'currency'             => ['required', 'string', 'size:3'],
            'currency_symbol'      => ['required', 'string', 'max:5'],
            'timezone'             => ['required', 'string', 'max:64'],
            'booking_window_days'  => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_hours'     => ['required', 'integer', 'min:0', 'max:168'],
            'classes_enabled'      => ['nullable', 'boolean'],
            'deliveries_enabled'   => ['nullable', 'boolean'], // MARKER-PATCH-156
            'multi_asset_enabled'  => ['nullable', 'boolean'], // MARKER-PATCH-158-B
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'default_tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:25'],
            'tax_services_default' => ['nullable', 'boolean'],
            'tax_supports_exempt'  => ['nullable', 'boolean'],
        ]);

        $tenant->update([
            'currency'             => $request->input('currency'),
            'currency_symbol'      => $request->input('currency_symbol'),
            'timezone'             => $request->input('timezone'),
            'booking_window_days'  => (int) $request->input('booking_window_days'),
            'min_notice_hours'     => (int) $request->input('min_notice_hours'),
            'classes_enabled'      => (bool) $request->input('classes_enabled'),
            'deliveries_enabled'   => (bool) $request->input('deliveries_enabled'), // MARKER-PATCH-156
            'multi_asset_enabled'  => (bool) $request->input('multi_asset_enabled'), // MARKER-PATCH-158-B
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'default_tax_rate'     => $request->filled('default_tax_rate')
                ? (float) $request->input('default_tax_rate')
                : null,
            'tax_services_default' => (bool) $request->input('tax_services_default'),
            'tax_supports_exempt'  => (bool) $request->input('tax_supports_exempt'),
        ]);

        return back()->with('success', 'Business settings saved.');
    }

    // -------------------------------------------------------------------
    // Branding: shop identity, logos, colors, typography
    // (formerly BrandingController::update tab=appearance, file uploads + colors)
    // -------------------------------------------------------------------
    private function updateBranding(Request $request, $tenant)
    {
        $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'tagline'           => ['nullable', 'string', 'max:255'],
            'accent_color'      => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color'        => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bg_color'          => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_heading'      => ['nullable', 'string', 'max:100'],
            'font_body'         => ['nullable', 'string', 'max:100'],
            'logo_size_admin'   => ['nullable', 'integer', 'min:16', 'max:80'],
            'logo_size_booking' => ['nullable', 'integer', 'min:16', 'max:120'],
        ]);

        $data = $request->only([
            'name', 'tagline', 'accent_color', 'text_color',
            'bg_color', 'font_heading', 'font_body',
            'logo_size_admin', 'logo_size_booking',
        ]);

        if ($request->hasFile('logo')) {
            $request->validate(['logo' => ['image', 'max:2048']]);
            $path = $request->file('logo')->store("tenants/{$tenant->id}/logo", 'public');
            $data['logo_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('logo_light')) {
            $request->validate(['logo_light' => ['image', 'max:2048']]);
            $path = $request->file('logo_light')->store("tenants/{$tenant->id}/logo", 'public');
            $data['logo_light_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('favicon')) {
            $request->validate(['favicon' => ['image', 'max:512']]);
            $path = $request->file('favicon')->store("tenants/{$tenant->id}/favicon", 'public');
            $data['favicon_url'] = asset('storage/' . $path);
        }

        $tenant->update($data);

        return back()->with('success', 'Branding saved.');
    }

    // -------------------------------------------------------------------
    // Communication: email sender, SMS provider, notification toggles
    // -------------------------------------------------------------------
    private function updateCommunication(Request $request, $tenant)
    {
        $request->validate([
            // Email
            'email_from_name'    => ['nullable', 'string', 'max:255'],
            'email_from_address' => ['nullable', 'email', 'max:255'],
            'email_reply_to'     => ['nullable', 'email', 'max:255'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            // SMS
            // MARKER-PATCH-224 — sms_* moved to Settings\MessagingController.
            // MARKER-PATCH-406 — notification toggles moved to Communication Center
        ]);

        // Don't overwrite an existing token with empty input — the form posts
        // MARKER-PATCH-224 — sms_*/twilio_* are owned by
        // Settings\MessagingController now. Writing them here would null
        // the messaging config on every unrelated settings save.
        $tenant->update([
            'email_from_name'    => $request->input('email_from_name'),
            'email_from_address' => $request->input('email_from_address'),
            'email_reply_to'     => $request->input('email_reply_to'),
            'notification_email' => $request->input('notification_email'),
        ]);

        // MARKER-PATCH-406 — notification toggles now owned by CommunicationController

        return back()->with('success', 'Communication settings saved.');
    }

    // -------------------------------------------------------------------
    // Account: custom domain
    // (booking URL is read-only display; subscription/billing also read-only)
    // -------------------------------------------------------------------
    private function updateAccount(Request $request, $tenant)
    {
        if (in_array($tenant->plan_tier, ['branded', 'scale', 'custom'])) {
            $request->validate([
                // MARKER-PATCH-120-SETTINGS-CONTROLLER - tenant_domains is the new source of truth
                // 'custom_domain' => ['nullable', 'string', 'max:253',
                //     'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
            ]);
            // $tenant->update(['custom_domain' => $request->input('custom_domain') ?: null]); // MARKER-PATCH-120-SETTINGS-CONTROLLER
        }
        return back()->with('success', 'Account settings saved.');
    }

    // -------------------------------------------------------------------
    // Appearance: admin theme
    // -------------------------------------------------------------------
    private function updateAppearance(Request $request, $tenant)
    {
        $request->validate([
            'admin_theme' => ['required', 'in:b,c'],
        ]);
        $settings = $tenant->settings ?? [];
        $settings['admin_theme'] = $request->input('admin_theme');
        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Appearance saved.');
    }

    // -------------------------------------------------------------------
    // Payments: Stripe + PayPal API keys (preserved verbatim from old controller)
    // -------------------------------------------------------------------
    private function updatePayments(Request $request, $tenant)
    {
        $settings = $tenant->settings ?? [];

        // MARKER-PATCH-388 — legacy booking-deposit stripe_* keys retired.
        // Booking deposits now run on Direct Payments (register_payments_* keys).

        // MARKER-PATCH-169 — Direct Payments bridge feature.
        // Register card-sale keys, namespaced separately from the booking-deposit
        // Stripe keys above (which power BookingController via App\Services\StripeService).
        // Only saved if the tenant has direct_payments_enabled set by master admin;
        // otherwise the form fields don\'t render and the inputs come back empty,
        // which is fine.
        if ($tenant->direct_payments_enabled) {
            // MARKER-PATCH-618 — tenant-level on/off for card + payment-link tenders
            // (master flag stays the capability gate; this is the tenant's switch).
            $settings['stripe_register_enabled'] = (bool) $request->input('stripe_register_enabled');
            $settings['square_enabled']          = (bool) $request->input('square_enabled');

            $settings['register_payments_mode']           = $request->input('register_payments_mode', 'test');
            $settings['register_payments_test_pk']        = $request->input('register_payments_test_pk', '');
            $settings['register_payments_test_sk']        = $request->input('register_payments_test_sk', '');
            $settings['register_payments_live_pk']        = $request->input('register_payments_live_pk', '');
            $settings['register_payments_live_sk']        = $request->input('register_payments_live_sk', '');
            $settings['register_payments_webhook_secret'] = $request->input('register_payments_webhook_secret', '');

            // MARKER-PATCH-473 — Square (tenant-connected) credentials
            $settings['square_payments_mode']           = $request->input('square_payments_mode', 'sandbox');
            $settings['square_sandbox_app_id']          = $request->input('square_sandbox_app_id', '');
            $settings['square_sandbox_location_id']     = $request->input('square_sandbox_location_id', '');
            $settings['square_sandbox_access_token']    = $request->input('square_sandbox_access_token', '');
            $settings['square_production_app_id']       = $request->input('square_production_app_id', '');
            $settings['square_production_location_id']  = $request->input('square_production_location_id', '');
            $settings['square_production_access_token'] = $request->input('square_production_access_token', '');
            $settings['square_webhook_signature_key']   = $request->input('square_webhook_signature_key', '');
        }

        $settings['paypal_enabled']        = (bool) $request->input('paypal_enabled');
        $settings['paypal_mode']           = $request->input('paypal_mode', 'sandbox');
        $settings['paypal_test_client_id'] = $request->input('paypal_test_client_id', '');
        $settings['paypal_test_secret']    = $request->input('paypal_test_secret', '');
        $settings['paypal_live_client_id'] = $request->input('paypal_live_client_id', '');
        $settings['paypal_live_secret']    = $request->input('paypal_live_secret', '');

        // MARKER-PATCH-618 — Venmo / Cash App manual tenders (peer-to-peer pay links).
        // Handles are stored bare (no @ / $); the link helper adds the scheme.
        // MARKER-PATCH-629 — venmo/cashapp keys retired here: owned by
        // tenant_payment_methods and written back via syncLegacyKeys().

        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Payment settings saved.');
    }

    // -------------------------------------------------------------------
    // POST endpoint: send a test SMS to verify Twilio configuration.
    // Uses the tenant's *saved* credentials, so user must save before testing.
    // -------------------------------------------------------------------
    // MARKER-PATCH-468 — toggle asset tracking from the Services-page banner
    public function toggleAssetTracking(Request $request): JsonResponse
    {
        $tenant = tenant();
        $enabled = (bool) $request->input('enabled');
        $tenant->update(['multi_asset_enabled' => $enabled]);
        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    // MARKER-PATCH-473 — verify the tenant's pasted Square credentials
    public function verifySquareConnection(Request $request): JsonResponse
    {
        $tenant = tenant();
        if (! ($tenant->direct_payments_enabled ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Payments are not enabled for this account.'], 403);
        }
        $result = (new \App\Services\Tenant\SquarePaymentsService($tenant))->verifyConnection();
        return response()->json($result);
    }

    public function sendTestSms(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'string', 'max:32'],
        ]);

        $tenant = tenant();

        // MARKER-PATCH-224 — managed numbers send on platform creds; only
        // require tenant creds when no platform fallback exists.
        $hasCreds = ($tenant->twilio_account_sid && $tenant->twilio_auth_token)
            || (config('services.twilio.sid') && config('services.twilio.token')); // MARKER-PATCH-224B
        if (! $tenant->sms_enabled || ! $tenant->sms_from_number || ! $hasCreds) {
            return response()->json([
                'ok'    => false,
                'error' => 'SMS is not enabled or credentials are missing. Save your settings first, then try again.',
            ], 422);
        }

        try {
            SmsService::send(
                $tenant,
                $request->input('to'),
                sprintf('Intake test message from %s. SMS is configured correctly.', $tenant->name)
            );
            return response()->json(['ok' => true, 'message' => 'Test SMS sent. Check the recipient phone.']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => 'Send failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

BIZ3_3_EOF

cat > 'app/Services/Tenant/ReportsDataService.php' <<'BIZ3_4_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportsDataService
 *
 * Phase 3: single global date range drives every zone. Each zone method
 * takes Carbon $from and Carbon $to directly. The controller is responsible
 * for parsing 'today' | 'week' | 'month' | 'custom' into a date pair.
 *
 * Capacity zone is the one exception — it falls back to the last 14 days
 * when the requested range is shorter than 7 days, since the day-of-week ×
 * hour heatmap needs density to be readable.
 */
class ReportsDataService
{
    private const DELIVERED_STATUSES = ['completed', 'closed'];
    private const CANCELLED_STATUSES = ['cancelled'];
    private const REFUNDED_STATUSES  = ['refunded'];

    public function __construct(private readonly Tenant $tenant) {}

    /** Top KPI row — always shows today's snapshot regardless of range. */
    public function topKpis(): array
    {
        $today = $this->tenant->localToday();
        $lastWeekSameDay = $today->copy()->subWeek();

        $todayRevenue = $this->revenueForDate($today);
        $lastWkRevenue = $this->revenueForDate($lastWeekSameDay);

        $todayBookings = $this->bookingCountForDate($today);
        $lastWkBookings = $this->bookingCountForDate($lastWeekSameDay);

        $todayCapacity = $this->capacityForDate($today);

        $thirtyDayNoShowRate = $this->noShowRateForRange(
            $today->copy()->subDays(29), $today
        );
        $todayNoShowCount = $this->noShowCountForDate($today);

        $todayNewCust = $this->newCustomerCountForDate($today);
        $lastWkNewCust = $this->newCustomerCountForDate($lastWeekSameDay);

        return [
            [
                'label'         => 'Revenue today',
                'value_dollars' => $todayRevenue / 100,
                'delta'         => $this->deltaPercent($todayRevenue, $lastWkRevenue),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'money',
            ],
            [
                'label'         => 'Bookings',
                'value_int'     => $todayBookings,
                'capacity'      => $todayCapacity,
                'delta'         => $this->deltaCount($todayBookings, $lastWkBookings),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'count',
            ],
            [
                'label'         => 'No-show rate',
                'value_int'     => round($thirtyDayNoShowRate * 100),
                'detail'        => $todayNoShowCount . ' today',
                'period_label'  => 'trailing 30 days',
                'format'        => 'percent',
            ],
            [
                'label'         => 'New customers today',
                'value_int'     => $todayNewCust,
                'delta'         => $this->deltaCount($todayNewCust, $lastWkNewCust),
                'period_label'  => 'vs. last ' . $today->format('l'),
                'format'        => 'count',
            ],
        ];
    }

    /** Zone 1: Revenue. */
    public function zoneRevenue(Carbon $from, Carbon $to): array
    {
        // MARKER-PATCH-184 — revenue now reads the SALE PAYMENT LEDGER
        // ("Payments Received", cash-basis), not appointment totals. Payments
        // are signed (refunds negative) so the ledger nets correctly. recorded_at
        // is stored UTC; we bound by the tenant-local day window converted to UTC,
        // and bucket the series by recorded_at shifted into the tenant timezone.
        $tz = $this->tenant->timezone();
        $isSingleDay = $from->isSameDay($to);

        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();

        // MARKER-TZ-WAVE4 — DST-correct per-row offset (a fixed "today"
        // offset shifted historical rows across DST changes).
        [$tzExpr, $tzBind] = tenant_tz_offset_expr('recorded_at', $tz, $winStart, $winEnd);

        $base = DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$winStart, $winEnd]);

        $totalCents = (int) (clone $base)->sum('amount_cents');

        $series = [];
        if ($isSingleDay) {
            $hourly = (clone $base)
                ->selectRaw("HOUR({$tzExpr}) as hour, SUM(amount_cents) as cents, COUNT(*) as n", $tzBind)
                ->groupBy('hour')
                ->get()
                ->keyBy('hour');
            for ($h = 8; $h <= 18; $h++) {
                $row = $hourly->get($h);
                $series[] = [
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        } else {
            $daily = (clone $base)
                ->selectRaw("DATE({$tzExpr}) as d, SUM(amount_cents) as cents, COUNT(*) as n", $tzBind)
                ->groupBy('d')
                ->get()
                ->keyBy('d');
            $days = $from->diffInDays($to);
            $labelFmt = $days <= 7 ? 'D' : ($days <= 31 ? 'j' : 'M j');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $row = $daily->get($d->toDateString());
                $series[] = [
                    'label' => $d->format($labelFmt),
                    'cents' => (int) ($row->cents ?? 0),
                    'count' => (int) ($row->n ?? 0),
                ];
            }
        }

        $bestBucket = collect($series)->sortByDesc('cents')->first();

        // Revenue by service: composition of the SALES that received payment in
        // this window, by line-item name. Sums positive (non-refund) payments'
        // sales only, grouping the sale's line items by name_snapshot. This keeps
        // the breakdown aligned with the cash-basis headline.
        $paidSaleIds = (clone $base)
            ->where('amount_cents', '>', 0)
            ->distinct()
            ->pluck('sale_id');

        $byService = [];
        if ($paidSaleIds->isNotEmpty()) {
            $byService = DB::table('tenant_sale_items')
                ->where('tenant_id', $this->tenant->id)
                ->whereIn('sale_id', $paidSaleIds)
                ->selectRaw('name_snapshot as name, SUM(line_total_cents) as cents, COUNT(DISTINCT sale_id) as bookings')
                ->groupBy('name')
                ->orderByDesc('cents')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'name'     => $r->name,
                    'cents'    => (int) $r->cents,
                    'bookings' => (int) $r->bookings,
                    'pct'      => $totalCents > 0 ? round(($r->cents / $totalCents) * 100) : 0,
                ])
                ->all();
        }

        return [
            'total_cents'   => $totalCents,
            'best_bucket'   => $bestBucket && $bestBucket['cents'] > 0
                ? ['label' => $bestBucket['label'], 'cents' => $bestBucket['cents']]
                : null,
            'series'        => $series,
            'series_kind'   => $isSingleDay ? 'hourly' : 'daily',
            'by_service'    => $byService,
        ];
    }
    public function zoneBookings(Carbon $from, Carbon $to): array
    {
        $isSingleDay = $from->isSameDay($to);

        $confirmed = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();

        $cancelled = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::CANCELLED_STATUSES)
            ->count();

        $noShows = $this->noShowCountForRange($from, $to);

        $walkins = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('DATE(created_at) = appointment_date')
            ->count();

        // Hourly bars for single day, daily for ranges
        $timeline = [];
        if ($isSingleDay) {
            $hourly = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->where('appointment_date', $from->toDateString())
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw("HOUR(appointment_time) as hour, COUNT(*) as n")
                ->groupBy('hour')
                ->pluck('n', 'hour')
                ->toArray();
            for ($h = 8; $h <= 18; $h++) {
                $timeline[] = [
                    'date'  => $from->toDateString(),
                    'label' => Carbon::createFromTime($h)->format('ga'),
                    'count' => (int) ($hourly[$h] ?? 0),
                ];
            }
        } else {
            $daily = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw('appointment_date as d, COUNT(*) as n')
                ->groupBy('d')
                ->pluck('n', 'd')
                ->toArray();
            $days = $from->diffInDays($to);
            $labelFmt = $days <= 7 ? 'D' : ($days <= 31 ? 'j' : 'M j');
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $timeline[] = [
                    'date'  => $d->toDateString(),
                    'label' => $d->format($labelFmt),
                    'count' => (int) ($daily[$d->toDateString()] ?? 0),
                ];
            }
        }

        return [
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'no_shows'  => $noShows,
            'walkins'   => $walkins,
            'timeline'  => $timeline,
        ];
    }

    /** Zone 3: Customers + retention. */
    public function zoneCustomers(Carbon $from, Carbon $to): array
    {
        $rangeAppts = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->select('appointment_date', 'customer_id')
            ->get()
            ->groupBy(fn($r) => $r->appointment_date);

        $newCustIds = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from->toDateString() . ' 00:00:00', $to->toDateString() . ' 23:59:59'])
            ->pluck('id')
            ->all();
        $newSet = array_flip($newCustIds);

        $daily = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $newCount = 0; $returningCount = 0;
            foreach ($rangeAppts->get($key, collect()) as $row) {
                if (isset($newSet[$row->customer_id])) $newCount++;
                else $returningCount++;
            }
            $daily[] = [
                'date'      => $key,
                'new'       => $newCount,
                'returning' => $returningCount,
            ];
        }

        // MARKER-PATCH-184C — top customers by SPEND now read the sale payment
        // ledger (payments received in window), attributed via the sale's
        // customer. "visits" = distinct sales paid in the window. recorded_at is
        // UTC; bound by the tenant-local window converted to UTC.
        $tz = $this->tenant->timezone();
        $winStart = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $winEnd   = $to->copy()->setTimezone($tz)->endOfDay()->utc();
        $topCustomers = TenantCustomer::where('tenant_customers.tenant_id', $this->tenant->id)
            ->join('tenant_sales as ts', 'ts.customer_id', '=', 'tenant_customers.id')
            ->join('tenant_sale_payments as tsp', function ($j) use ($winStart, $winEnd) {
                $j->on('tsp.sale_id', '=', 'ts.id')
                  ->whereBetween('tsp.recorded_at', [$winStart, $winEnd]);
            })
            // MARKER-BIZ-NAME — raw rows carry no model methods, so the
            // business name is selected and chosen inline.
            ->selectRaw('tenant_customers.id, tenant_customers.first_name, tenant_customers.last_name, tenant_customers.business_name, tenant_customers.customer_type, tenant_customers.created_at, SUM(tsp.amount_cents) as cents, COUNT(DISTINCT ts.id) as visits')
            ->groupBy('tenant_customers.id', 'tenant_customers.first_name', 'tenant_customers.last_name', 'tenant_customers.business_name', 'tenant_customers.customer_type', 'tenant_customers.created_at')
            ->orderByDesc('cents')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'name'             => ($r->customer_type === 'business' && trim((string) $r->business_name) !== '')
                    ? trim($r->business_name)
                    : trim($r->first_name . ' ' . $r->last_name), // MARKER-BIZ-NAME
                'cents'            => (int) $r->cents,
                'visits'           => (int) $r->visits,
                'is_new_in_period' => Carbon::parse($r->created_at)->between($from, $to->copy()->endOfDay()),
            ])
            ->all();

        return [
            'daily'         => $daily,
            'top_customers' => $topCustomers,
        ];
    }

    /** Zone 4: Service popularity. */
    public function zoneServices(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('tenant_appointments as ta')
            ->where('ta.tenant_id', $this->tenant->id)
            ->whereBetween('ta.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('ta.status', self::DELIVERED_STATUSES)
            ->where('ta.payment_status', 'paid')
            ->join('tenant_appointment_items as tai', 'tai.appointment_id', '=', 'ta.id')
            ->selectRaw('tai.item_name_snapshot as name, COUNT(DISTINCT ta.id) as bookings, SUM(COALESCE(tai.price_cents_override, tai.price_cents)) as cents')
            ->groupBy('name')
            ->orderByDesc('cents')
            ->limit(10)
            ->get();

        $maxCents = $rows->max('cents') ?: 1;

        return [
            'services' => $rows->map(fn($r) => [
                'name'      => $r->name,
                'bookings'  => (int) $r->bookings,
                'cents'     => (int) $r->cents,
                'bar_pct'   => round(($r->cents / $maxCents) * 100),
            ])->all(),
        ];
    }

    /** Zone 5: Staff utilization. */
    public function zoneStaff(Carbon $from, Carbon $to): array
    {
        // Real available minutes: sum each day's actual open-to-close window
        // from tenant_capacity_rules (defaults + overrides). Days the shop is
        // closed contribute zero; days with no rule fall back to 8h.
        $availableMinutes = $this->openMinutesForRange($from, $to);

        $resources = TenantResource::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $bookedMinutes = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw('resource_id, SUM(total_duration_minutes) as mins, COUNT(*) as n')
            ->groupBy('resource_id')
            ->get()
            ->keyBy('resource_id');

        // MARKER-PATCH-184D — per-resource revenue from the sale payment ledger
        // (payments received in window), attributed via payment -> sale ->
        // appointment -> resource_id. Walk-in retail sales (no appointment) carry
        // no resource and are correctly excluded from per-staff revenue.
        $tzStaff = $this->tenant->timezone();
        $revWinStart = $from->copy()->setTimezone($tzStaff)->startOfDay()->utc();
        $revWinEnd   = $to->copy()->setTimezone($tzStaff)->endOfDay()->utc();
        $revenue = DB::table('tenant_sale_payments as tsp')
            ->where('tsp.tenant_id', $this->tenant->id)
            ->whereBetween('tsp.recorded_at', [$revWinStart, $revWinEnd])
            ->join('tenant_sales as ts', 'ts.id', '=', 'tsp.sale_id')
            ->join('tenant_appointments as ta', 'ta.id', '=', 'ts.appointment_id')
            ->selectRaw('ta.resource_id as resource_id, SUM(tsp.amount_cents) as cents')
            ->groupBy('ta.resource_id')
            ->pluck('cents', 'resource_id')
            ->toArray();

        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());

        $noShowsByResource = [];
        $totalByResource = [];
        if ($from->toDateString() <= $effectiveTo) {
            $noShowsByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
                ->whereNotIn('status', array_merge(self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
                ->selectRaw('resource_id, COUNT(*) as n')
                ->groupBy('resource_id')
                ->pluck('n', 'resource_id')
                ->toArray();

            $totalByResource = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
                ->selectRaw('resource_id, COUNT(*) as n')
                ->groupBy('resource_id')
                ->pluck('n', 'resource_id')
                ->toArray();
        }

        $cards = $resources->map(function ($r) use ($bookedMinutes, $revenue, $noShowsByResource, $totalByResource, $availableMinutes) {
            $row = $bookedMinutes->get($r->id);
            $booked = (int) ($row->mins ?? 0);
            $appts = (int) ($row->n ?? 0);
            $rev = (int) ($revenue[$r->id] ?? 0);
            $noShows = (int) ($noShowsByResource[$r->id] ?? 0);
            $totalCount = (int) ($totalByResource[$r->id] ?? 0);

            $utilization = $availableMinutes > 0
                ? min(100, round(($booked / $availableMinutes) * 100))
                : 0;

            $health = match (true) {
                $utilization > 85 => 'overloaded',
                $utilization >= 50 => 'healthy',
                default => 'underused',
            };

            $noShowRate = $totalCount > 0 ? round(($noShows / $totalCount) * 100) : 0;

            return [
                'name'         => $r->name,
                'subtitle'     => $r->subtitle ?: 'Staff',
                'color_hex'    => $r->color_hex,
                'utilization'  => $utilization,
                'booked_hrs'   => round($booked / 60, 1),
                'available_hrs'=> round($availableMinutes / 60, 1),
                'appts'        => $appts,
                'revenue_cents'=> $rev,
                'no_show_rate' => $noShowRate,
                'health'       => $health,
            ];
        })->all();

        return ['cards' => $cards];
    }

    /**
     * Zone 6: Capacity heatmap.
     * Falls back to last 14 days if the requested range is shorter than 7
     * days — heatmap density needs that much data to be readable.
     */
    public function zoneCapacity(Carbon $from, Carbon $to): array
    {
        $rangeDays = $from->diffInDays($to) + 1;
        $usedFallback = false;
        if ($rangeDays < 7) {
            $usedFallback = true;
            $today = $this->tenant->localToday();
            $from = $today->copy()->subDays(13);
            $to = $today->copy();
        }

        $cells = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->selectRaw("DAYOFWEEK(appointment_date) - 1 as dow, HOUR(appointment_time) as hour, COUNT(*) as n")
            ->groupBy('dow', 'hour')
            ->get();

        $maxCellCount = $cells->max('n') ?: 1;

        $grid = [];
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        foreach ($days as $dowIdx => $dowLabel) {
            $row = ['label' => $dowLabel, 'cells' => []];
            for ($h = 8; $h <= 21; $h++) {
                $cell = $cells->first(fn($c) => $c->dow == $dowIdx && $c->hour == $h);
                $count = $cell ? (int) $cell->n : 0;
                $fill = match (true) {
                    $count == 0           => 0,
                    $count <= $maxCellCount * 0.15 => 1,
                    $count <= $maxCellCount * 0.35 => 2,
                    $count <= $maxCellCount * 0.55 => 3,
                    $count <= $maxCellCount * 0.80 => 4,
                    default               => 5,
                };
                $row['cells'][] = [
                    'hour'  => $h,
                    'count' => $count,
                    'fill'  => $fill,
                ];
            }
            $grid[] = $row;
        }

        return [
            'grid'          => $grid,
            'used_fallback' => $usedFallback,
            'fallback_label' => $usedFallback
                ? $from->format('M j') . ' – ' . $to->format('M j')
                : null,
            'hour_labels'   => array_map(
                fn($h) => Carbon::createFromTime($h)->format('ga'),
                range(8, 21)
            ),
        ];
    }

    // ---------- helpers ----------

    /**
     * Sum of "shop is open" minutes for every day in the range.
     * Override rules win over default rules for a specific date. If a day
     * has no rule at all, falls back to 8 hours so a brand-new tenant
     * doesn't show 100%-of-zero utilization.
     */
    private function openMinutesForRange(Carbon $from, Carbon $to): int
    {
        $defaults = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'default')
            ->whereNull('specific_date')
            ->get(['day_of_week', 'is_closed', 'open_time', 'close_time'])
            ->keyBy('day_of_week');

        $overrides = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$from->toDateString(), $to->toDateString()])
            ->get(['specific_date', 'is_closed', 'open_time', 'close_time'])
            ->keyBy(fn($r) => $r->specific_date);

        $totalMinutes = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $rule = $overrides->get($d->toDateString()) ?? $defaults->get($d->dayOfWeek);

            if (!$rule) {
                $totalMinutes += 8 * 60;  // fallback when no rule exists
                continue;
            }
            if (!empty($rule->is_closed)) continue;  // closed = 0 minutes
            if (empty($rule->open_time) || empty($rule->close_time)) {
                $totalMinutes += 8 * 60;  // partial rule, fallback
                continue;
            }

            try {
                $open  = Carbon::parse($rule->open_time);
                $close = Carbon::parse($rule->close_time);
                $mins  = max(0, $open->diffInMinutes($close));
                $totalMinutes += $mins;
            } catch (\Throwable $e) {
                $totalMinutes += 8 * 60;
            }
        }

        return $totalMinutes;
    }

    private function revenueForDate(Carbon $date): int
    {
        // MARKER-PATCH-184B — payments received (ledger) for the tenant-local
        // day, replacing appointment totals. recorded_at is UTC; bound by the
        // local-day window converted to UTC. Signed amounts net refunds.
        $tz = $this->tenant->timezone();
        $start = $date->copy()->setTimezone($tz)->startOfDay()->utc();
        $end   = $date->copy()->setTimezone($tz)->endOfDay()->utc();
        return (int) DB::table('tenant_sale_payments')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [$start, $end])
            ->sum('amount_cents');
    }

    private function bookingCountForDate(Carbon $date): int
    {
        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->whereNotIn('status', array_merge(self::CANCELLED_STATUSES, self::REFUNDED_STATUSES))
            ->count();
    }

    private function capacityForDate(Carbon $date): ?int
    {
        $dow = $date->dayOfWeek;
        $rule = DB::table('tenant_capacity_rules')
            ->where('tenant_id', $this->tenant->id)
            ->where(function ($q) use ($dow, $date) {
                $q->where(function ($s) use ($date) {
                    $s->where('rule_type', 'override')->where('specific_date', $date->toDateString());
                })->orWhere(function ($s) use ($dow) {
                    $s->where('rule_type', 'default')->where('day_of_week', $dow)->whereNull('specific_date');
                });
            })
            ->orderByRaw("CASE WHEN rule_type='override' THEN 0 ELSE 1 END")
            ->first();

        return $rule?->max_appointments;
    }

    private function noShowCountForDate(Carbon $date): int
    {
        // Strict + 24h grace: only count yesterday-or-earlier confirmed
        // appointments. Today's date returns 0 because grace hasn't elapsed.
        if ($date->gte($this->tenant->localToday())) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('appointment_date', $date->toDateString())
            ->where('status', 'confirmed')
            ->count();
    }

    private function noShowCountForRange(Carbon $from, Carbon $to): int
    {
        // Strict + 24h grace: only count appointments that were actually
        // confirmed (not pending) AND whose date is at least one full day in
        // the past. This prevents inflating no-show counts with appointments
        // that simply haven't been status-updated yet.
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        return TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->where('status', 'confirmed')
            ->count();
    }

    private function noShowRateForRange(Carbon $from, Carbon $to): float
    {
        // Same strict + 24h grace as noShowCountForRange. Denominator is
        // every non-cancelled appointment, numerator is just the confirmed
        // ones that didn't make it to delivered.
        $today = $this->tenant->localToday();
        $effectiveTo = min($to->toDateString(), $today->copy()->subDay()->toDateString());
        if ($from->toDateString() > $effectiveTo) return 0;

        $total = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->whereNotIn('status', self::CANCELLED_STATUSES)
            ->count();

        if ($total === 0) return 0;

        $noShows = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$from->toDateString(), $effectiveTo])
            ->where('status', 'confirmed')
            ->count();

        return $noShows / $total;
    }

    private function newCustomerCountForDate(Carbon $date): int
    {
        // MARKER-TZ-WAVE1 — created_at is UTC; bucket by the tenant day's
        // UTC range so evening signups land on the right local day.
        [$s, $e] = tenant_day_utc_range($date, $this->tenant->timezone());
        return TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $s)
            ->where('created_at', '<',  $e)
            ->count();
    }

    private function deltaPercent(int $current, int $prior): ?array
    {
        if ($prior === 0) return null;
        $pct = round((($current - $prior) / $prior) * 100);
        return [
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            'value'     => abs($pct) . '%',
        ];
    }

    private function deltaCount(int $current, int $prior): ?array
    {
        $diff = $current - $prior;
        return [
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            'value'     => abs($diff),
        ];
    }
}
BIZ3_4_EOF

cat > 'app/Services/Tenant/CustomersReportService.php' <<'BIZ3_5_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CustomersReportService
 *
 * Whole-database (NOT date-ranged) customer analytics for the
 * Reports → Customers tab. Three independent panels:
 *
 *   - missingContactInfo()  : customers with no phone number on file
 *   - lapsedCustomers()     : customers whose most-recent appointment
 *                              was more than 180 days ago (90–180d = at-risk)
 *   - highestLtv()          : top customers by lifetime value, where LTV =
 *                              sum of delivered-appointment totals + paid sales,
 *                              minus refund sales. Snapshotted values, not
 *                              live-recomputed prices.
 *
 * All methods are tenant-scoped through the constructor-injected tenant.
 *
 * Performance note: at 10,000+ customers per tenant, the lapsedCustomers
 * query (which left-joins to the most-recent-appointment-per-customer)
 * may benefit from an index on tenant_appointments.(customer_id, appointment_date).
 * The current schema indexes (tenant_id, appointment_date) only. Not adding
 * an index in this patch — defer until data volume warrants it.
 */
class CustomersReportService
{
    /**
     * Status values that count as a real visit (drove revenue, used capacity).
     * Mirrors ReportsDataService::DELIVERED_STATUSES.
     */
    private const DELIVERED_STATUSES = ['in_progress', 'completed', 'shipped', 'closed'];

    private const LAPSED_DAYS = 180;
    private const AT_RISK_DAYS = 90;

    private const LIST_LIMIT_LAPSED = 100;
    private const LIST_LIMIT_LTV = 50;
    private const LIST_LIMIT_MISSING = 100;

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Customers missing usable phone contact.
     *
     * Schema: email is NOT NULL (unique per tenant); phone is nullable.
     * So "missing contact info" = phone is null or empty string.
     *
     * Returns:
     *   total           : count of all customers missing phone
     *   reachable_count : number of total customers (for context %)
     *   list            : top N customers missing phone, newest first
     */
    public function missingContactInfo(bool $aggregatesOnly = false): array
    {
        $totalCustomers = TenantCustomer::where('tenant_id', $this->tenant->id)->count();

        $missingQuery = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where(function ($q) {
                $q->whereNull('phone')->orWhere('phone', '');
            });

        $totalMissing = (clone $missingQuery)->count();

        $list = $aggregatesOnly ? [] : $missingQuery
            ->orderByDesc('created_at')
            ->limit(self::LIST_LIMIT_MISSING)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at'])
            ->map(fn($c) => [
                'id'       => $c->id,
                'name'     => trim($c->fullName()),
                'email'    => $c->email,
                'added_at' => $c->created_at,
            ])
            ->all();

        return [
            'total_missing'   => $totalMissing,
            'total_customers' => $totalCustomers,
            'percent_missing' => $totalCustomers > 0
                ? round(($totalMissing / $totalCustomers) * 100, 1)
                : 0.0,
            'list'            => $list,
            'list_limit'      => self::LIST_LIMIT_MISSING,
        ];
    }

    /**
     * Customers who used to come in and haven't lately.
     *
     * Definition (locked 2026-05-16):
     *   - Lapsed:  no delivered appointment in the last 180 days,
     *              but HAS at least one delivered appointment ever.
     *   - At-risk: most-recent delivered appointment is 90–180 days ago.
     *
     * Customers with zero delivered appointments are excluded — they're
     * "never engaged," not "lapsed."
     */
    public function lapsedCustomers(bool $aggregatesOnly = false): array
    {
        $now = $this->tenant->localToday();
        $lapsedCutoff  = $now->copy()->subDays(self::LAPSED_DAYS);
        $atRiskCutoff  = $now->copy()->subDays(self::AT_RISK_DAYS);

        // Build a subquery: most recent delivered appointment per customer.
        $deliveredStatusesSql = "'" . implode("','", self::DELIVERED_STATUSES) . "'";
        $latest = DB::table('tenant_appointments')
            ->selectRaw('customer_id, MAX(appointment_date) as last_appt')
            ->where('tenant_id', $this->tenant->id)
            ->whereRaw("status IN ($deliveredStatusesSql)")
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        $base = DB::table('tenant_customers as c')
            ->joinSub($latest, 'l', fn($j) => $j->on('l.customer_id', '=', 'c.id'))
            ->where('c.tenant_id', $this->tenant->id);

        $lapsedCount = (clone $base)
            ->whereDate('l.last_appt', '<', $lapsedCutoff->toDateString())
            ->count();

        $atRiskCount = (clone $base)
            ->whereDate('l.last_appt', '>=', $lapsedCutoff->toDateString())
            ->whereDate('l.last_appt', '<',  $atRiskCutoff->toDateString())
            ->count();

        $list = $aggregatesOnly ? [] : (clone $base)
            ->whereDate('l.last_appt', '<', $lapsedCutoff->toDateString())
            ->orderBy('l.last_appt', 'asc') // longest-lapsed first — most urgent
            ->limit(self::LIST_LIMIT_LAPSED)
            // MARKER-BIZ-NAME — raw rows: select what the display name needs
            ->select('c.id', 'c.first_name', 'c.last_name', 'c.business_name', 'c.customer_type', 'c.email', 'c.phone', 'l.last_appt')
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'name'       => ($r->customer_type === 'business' && trim((string) $r->business_name) !== '')
                    ? trim($r->business_name)
                    : trim($r->first_name . ' ' . $r->last_name), // MARKER-BIZ-NAME
                'email'      => $r->email,
                'phone'      => $r->phone,
                'last_visit' => $r->last_appt,
                'days_since' => Carbon::parse($r->last_appt)->diffInDays($now),
            ])
            ->all();

        return [
            'lapsed_count'    => $lapsedCount,
            'at_risk_count'   => $atRiskCount,
            'lapsed_days'     => self::LAPSED_DAYS,
            'at_risk_days'    => self::AT_RISK_DAYS,
            'list'            => $list,
            'list_limit'      => self::LIST_LIMIT_LAPSED,
        ];
    }

    /**
     * Top customers by lifetime value.
     *
     * LTV scope (locked 2026-05-16):
     *   Sum of snapshotted line-item totals from:
     *     - tenant_appointments where status IN (delivered set)
     *     - tenant_sales where payment_status = 'paid' AND refund_of_sale_id IS NULL
     *   Less refund sales (rows where refund_of_sale_id IS NOT NULL).
     *
     * Snapshot values from the rows, not live-recomputed from current prices.
     */
    public function highestLtv(bool $aggregatesOnly = false): array
    {
        if ($aggregatesOnly) {
            return [
                'list'       => [],
                'list_limit' => self::LIST_LIMIT_LTV,
                'total_ltv'  => 0,
            ];
        }

        // MARKER-PATCH-185 — LTV = payments received (sale ledger) per customer,
        // attributed via the sale's customer. Single source of truth; no more
        // appt+sale double-count. Signed amounts net refunds automatically.
        $ledgerLtv = DB::table('tenant_sale_payments as tsp')
            ->join('tenant_sales as ts', 'ts.id', '=', 'tsp.sale_id')
            ->where('tsp.tenant_id', $this->tenant->id)
            ->whereNotNull('ts.customer_id')
            ->selectRaw('ts.customer_id as customer_id, SUM(tsp.amount_cents) as cents')
            ->groupBy('ts.customer_id')
            ->get()
            ->keyBy('customer_id');

        // Build the LTV-by-customer dictionary.
        $ltv = [];
        foreach ($ledgerLtv as $cid => $row) $ltv[$cid] = (int) $row->cents;

        // Filter out customers with non-positive LTV (refund-only edge case).
        $ltv = array_filter($ltv, fn($cents) => $cents > 0);

        // Sort desc, take top N.
        arsort($ltv);
        $topIds = array_slice(array_keys($ltv), 0, self::LIST_LIMIT_LTV, true);

        if (empty($topIds)) {
            return [
                'list'       => [],
                'list_limit' => self::LIST_LIMIT_LTV,
                'total_ltv'  => 0,
            ];
        }

        // Hydrate the top N with customer details.
        $customers = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereIn('id', $topIds)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'created_at'])
            ->keyBy('id');

        // Visit-count map for context (one cheap query, same scope).
        $visitMap = DB::table('tenant_appointments')
            ->selectRaw('customer_id, COUNT(*) as visits')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereIn('customer_id', $topIds)
            ->groupBy('customer_id')
            ->pluck('visits', 'customer_id');

        $list = [];
        foreach ($topIds as $cid) {
            $c = $customers->get($cid);
            if (!$c) continue;
            $list[] = [
                'id'             => $c->id,
                'name'           => trim($c->fullName()),
                'email'          => $c->email,
                'phone'          => $c->phone,
                'ltv_cents'      => $ltv[$cid],
                'visits'         => (int) ($visitMap[$cid] ?? 0),
                'customer_since' => $c->created_at,
            ];
        }

        return [
            'list'       => $list,
            'list_limit' => self::LIST_LIMIT_LTV,
            'total_ltv'  => array_sum($ltv),
        ];
    }
}
BIZ3_5_EOF

cat > 'resources/views/tenant/settings/index.blade.php' <<'BIZ3_6_EOF'
@extends('layouts.tenant.app')
@php
  /*
   * Unified settings page. Six tabs, JS-switched (no URL params).
   * Each tab is its own form; one save button per tab in a sticky save bar.
   * Drop-off methods CRUD lives in the Business tab and uses its own
   * dedicated endpoints (tenant.receiving-methods.*) — preserved verbatim
   * from the previous settings/branding split.
   */
  $pageTitle  = 'Settings';
  $s          = $currentTenant->settings ?? [];
  $currencies = ['USD'=>'$','CAD'=>'CA$','GBP'=>'£','EUR'=>'€','AUD'=>'A$','NZD'=>'NZ$'];
  $fonts      = ['Inter','Poppins','DM Sans','Nunito','Lato','Raleway','Montserrat','Playfair Display','Merriweather'];

  // Admin theme stored in settings JSON. Default to 'c' (dark).
  $adminTheme = $s['admin_theme'] ?? 'c';
  if ($adminTheme === 'a') $adminTheme = 'c';

  // Notification toggles default to ON via Tenant::notificationEnabled().
  $notifyBookingEmail = $currentTenant->notificationEnabled('booking_confirmation_email');
  $notifyBookingSms   = $currentTenant->notificationEnabled('booking_confirmation_sms');

  // MARKER-PATCH-152C — delivery scheduled toggles
  $notifyDeliveryEmail = $currentTenant->notificationEnabled('delivery_scheduled_email');
  $notifyDeliverySms   = $currentTenant->notificationEnabled('delivery_scheduled_sms');

  // MARKER-PATCH-154 — appointment reminder toggles
  $notifyApptReminderEmail = $currentTenant->notificationEnabled('appointment_reminder_email');
  $notifyApptReminderSms   = $currentTenant->notificationEnabled('appointment_reminder_sms');

  // MARKER-PATCH-155 — delivery reminder toggles
  $notifyDeliveryReminderEmail = $currentTenant->notificationEnabled('delivery_reminder_email');
  $notifyDeliveryReminderSms   = $currentTenant->notificationEnabled('delivery_reminder_sms');

  // SMS auth token: don't render the actual value back to the form. Show
  // a masked placeholder if one is set, blank if not. Controller treats
  // an empty submission as "leave unchanged."
  $hasTwilioToken = (bool) $currentTenant->twilio_auth_token;
@endphp

@push('styles')
<style>
/* -------------------------------------------------------------------------
 * Settings page chrome
 * ------------------------------------------------------------------------- */
.set-head {
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:16px; margin-bottom:18px; flex-wrap:wrap;
}
.set-booking-chip {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 12px; border-radius:99px;
  border:0.5px solid var(--ia-border);
  background:var(--ia-surface);
  font-size:12px; color:var(--ia-text);
  text-decoration:none;
  transition:background var(--ia-t), border-color var(--ia-t);
  white-space:nowrap;
}
.set-booking-chip:hover { background:var(--ia-hover); border-color:var(--ia-border-strong); }
.set-booking-chip svg { opacity:.55; }

/* Tabs */
.set-tabs {
  display:flex; gap:0;
  border-bottom:0.5px solid var(--ia-border);
  margin-bottom:20px;
  overflow-x:auto;
  scrollbar-width:none;
}
.set-tabs::-webkit-scrollbar { display:none; }
.set-tab {
  padding:10px 18px; font-size:13px; color:var(--ia-text-muted);
  cursor:pointer; border-bottom:2px solid transparent;
  background:transparent; border-left:none; border-right:none; border-top:none;
  font-family:inherit; transition:color .12s, border-color .12s;
  white-space:nowrap;
}
.set-tab:hover { color:var(--ia-text); }
.set-tab.active { color:var(--ia-text); border-bottom-color:var(--ia-accent); }

/* Panes */
.set-pane { display:none; }
.set-pane.active { display:block; }

/* MARKER-PATCH-150-POLISH-A — responsive card grid */
.set-section {
  display: block;
  max-width: 1200px;
}
/* Each card in a settings form becomes a grid cell.
   Cards default to ~half width (min 420px). Cards with .set-card--wide
   span the full row. Save bars and headers are always full-row. */
.set-section .ia-card {
  margin-bottom: 0;
}
.set-section--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 18px;
  /* MARKER-PATCH-150-POLISH-C — same-row cards match heights */
  align-items: stretch;
}
.set-section--grid > .ia-card { display: flex; flex-direction: column; }
.set-section--grid .set-card--wide,
.set-section--grid .set-savebar {
  grid-column: 1 / -1;
}
@media (max-width: 880px) {
  .set-section--grid { grid-template-columns: 1fr; }
}

/* Save bar — sticky at top of pane, dims when no changes */
.set-savebar {
  position:sticky; top:0; z-index:5;
  background:var(--ia-bg);
  margin:-6px -6px 16px;
  padding:10px 6px;
  border-bottom:0.5px solid transparent;
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap;
  transition:border-color .15s;
}
.set-savebar.dirty { border-bottom-color:var(--ia-border); }
.set-savebar-msg {
  font-size:12px; color:var(--ia-text-dim);
  transition:color .15s;
}
.set-savebar.dirty .set-savebar-msg { color:var(--ia-text); }
.set-savebar-actions { display:flex; gap:8px; }
.set-save-btn {
  font-size:13px; padding:8px 16px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-accent);
  background:var(--ia-accent); color:var(--ia-accent-text);
  cursor:pointer; font-family:inherit; font-weight:500;
  transition:opacity .15s, filter .15s;
}
.set-save-btn:hover { filter:brightness(1.08); }
.set-save-btn:disabled,
.set-savebar:not(.dirty) .set-save-btn {
  opacity:.4; cursor:not-allowed; filter:none;
}
.set-discard-btn {
  font-size:13px; padding:8px 14px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-border);
  background:transparent; color:var(--ia-text-muted);
  cursor:pointer; font-family:inherit;
  transition:background .12s;
}
.set-discard-btn:hover { background:var(--ia-hover); color:var(--ia-text); }
.set-savebar:not(.dirty) .set-discard-btn { display:none; }

/* "Coming soon" sections (Locations, etc.) */
.set-coming-soon {
  position:relative;
  border:0.5px dashed var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:18px 20px;
  margin-bottom:20px;
  opacity:.55;
}
.set-coming-soon-pill {
  position:absolute; top:14px; right:14px;
  font-size:10px; padding:3px 9px; border-radius:99px;
  background:var(--ia-surface-2); color:var(--ia-text-dim);
  text-transform:uppercase; letter-spacing:.06em; font-weight:600;
}
.set-coming-soon-title {
  font-size:14px; font-weight:500; margin-bottom:4px;
}
.set-coming-soon-desc {
  font-size:12px; color:var(--ia-text-muted); line-height:1.5;
  max-width:520px;
}

/* Provider toggle (Stripe / PayPal) — preserved from old settings page */
.provider-card {
  border:0.5px solid var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:20px; margin-bottom:16px;
  transition:border-color .12s;
}
.provider-card.enabled { border-color:var(--ia-accent); }
.provider-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:0; }
.provider-fields {
  margin-top:16px; padding-top:16px;
  border-top:0.5px solid var(--ia-border);
  display:none;
}
.provider-card.enabled .provider-fields { display:block; }
.prov-toggle-btn {
  width:38px; height:22px; background:var(--ia-border);
  border-radius:11px; position:relative;
  cursor:pointer; border:none; outline:none;
  transition:background .12s; flex-shrink:0;
}
.prov-toggle-btn.on { background:var(--ia-accent); }
.prov-toggle-btn::after {
  content:''; position:absolute; top:3px; left:3px;
  width:16px; height:16px; border-radius:50%;
  background:white; transition:transform .12s;
}
.prov-toggle-btn.on::after { transform:translateX(16px); }

/* Domain badge (preserved) */
.domain-badge {
  font-size:11px; padding:3px 10px;
  border-radius:20px; font-weight:500; margin-left:8px;
}
.domain-badge.basic   { background:var(--ia-surface-2); color:var(--ia-text-muted); }
.domain-badge.branded { background:#EEEDFE; color:#534AB7; }
.domain-badge.scale   { background:#E1F5EE; color:#0F6E56; }
.domain-badge.custom  { background:#EAF3DE; color:#3B6D11; }

/* notif-row styles removed — patch-406 (toggles moved to Communication Center) */

/* Color swatch (branding tab) */
.color-swatch-row {
  display:flex; gap:10px; align-items:center; margin-top:6px;
}
.color-swatch {
  width:36px; height:36px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-border);
  overflow:hidden; cursor:pointer; flex-shrink:0;
}
.color-swatch input[type=color] {
  width:52px; height:52px; margin:-8px;
  border:none; cursor:pointer; background:none; padding:0;
}

/* Logo previews (branding tab) */
.logo-preview { height:40px; border-radius:6px; margin-bottom:8px; display:block; }
.logo-preview-dark {
  background:#111; padding:6px 10px; border-radius:6px;
  margin-bottom:8px; display:inline-block;
}
.logo-preview-dark img { height:32px; }

/* Theme picker (appearance tab) */
.theme-grid {
  display:grid; grid-template-columns:repeat(2,1fr);
  gap:12px; margin-top:8px; max-width:420px;
}
.theme-card {
  border:0.5px solid var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:14px; cursor:pointer; transition:all .12s;
  position:relative;
}
.theme-card:hover { border-color:var(--ia-accent); }
.theme-card.selected { border-color:var(--ia-accent); background:var(--ia-accent-soft); }
.theme-card input { position:absolute; opacity:0; width:0; height:0; }
.theme-preview {
  height:60px; border-radius:var(--ia-r-md);
  overflow:hidden; margin-bottom:8px; display:flex;
}
.theme-label { font-size:12px; font-weight:500; text-align:center; }
.preview-b-wrap { flex:1; display:flex; flex-direction:column; }
.preview-b-top  { height:12px; background:#ffffff; border-bottom:0.5px solid #e8e8e4; }
.preview-b-main { flex:1; background:#ffffff; }
.preview-c-side { width:35%; background:#0c0c0c; }
.preview-c-main { flex:1; background:#111111; }

/* SMS test status flash */
.sms-test-status {
  margin-top:10px; font-size:12px; padding:8px 12px;
  border-radius:var(--ia-r-md);
  display:none;
}
.sms-test-status.success { display:block; background:rgba(120,200,120,.10); color:#78c878; border:0.5px solid rgba(120,200,120,.25); }
.sms-test-status.error   { display:block; background:rgba(240,149,149,.10); color:#F09595; border:0.5px solid rgba(240,149,149,.25); }
</style>
@endpush

@section('content')

<div class="set-head">
  <div>
    <h1 class="ia-page-title" style="margin-bottom:4px">Settings</h1>
    <p class="ia-page-subtitle" style="margin:0">Configure your shop's operational preferences and branding.</p>
  </div>
  <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" rel="noopener noreferrer" class="set-booking-chip">
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
      <path d="M5 9L9 5M9 5H5.5M9 5v3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
      <rect x="2" y="2" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.2"/>
    </svg>
    Open booking page
  </a>
</div>

{{-- MARKER-PATCH-165 — success flash removed; the global layout renders it once at the top. --}}
@if($errors->any())
<div style="padding:10px 14px;margin-bottom:16px;border-radius:var(--ia-r-md);background:rgba(240,149,149,.10);border:0.5px solid rgba(240,149,149,.25);font-size:13px;color:#F09595">
  @foreach($errors->all() as $err){{ $err }}<br>@endforeach
</div>
@endif

<div class="set-tabs" role="tablist">
  <button type="button" class="set-tab active" data-tab="business"      role="tab">Business</button>
  <button type="button" class="set-tab"        data-tab="branding"      role="tab">Branding</button>
  <button type="button" class="set-tab"        data-tab="communication" role="tab">Communication</button>
  <button type="button" class="set-tab"        data-tab="account"       role="tab">Account</button>
  <button type="button" class="set-tab"        data-tab="payments"      role="tab">Payments</button>
  <button type="button" class="set-tab"        data-tab="tags"          role="tab">Print &amp; receipts</button>{{-- MARKER-PATCH-315 / 339 --}}
  <button type="button" class="set-tab"        data-tab="ordering"      role="tab">Ordering</button>{{-- MARKER-SO-AUTOVENDOR --}}
</div>

{{-- =====================================================================
     BUSINESS — currency, timezone, booking, tax, drop-off methods
     ===================================================================== --}}
<div class="set-pane active" id="pane-business" role="tabpanel">

  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="business">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save business settings</button>
      </div>
    </div>

    {{-- Currency --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Currency</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Currency code</label>
          <select name="currency" class="ia-input">
            @foreach($currencies as $code => $sym)
              <option value="{{ $code }}" @selected($currentTenant->currency === $code)>{{ $code }} ({{ $sym }})</option>
            @endforeach
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Currency symbol</label>
          <input type="text" name="currency_symbol" class="ia-input"
            value="{{ old('currency_symbol', $currentTenant->currency_symbol) }}" maxlength="5">
        </div>
      </div>
    </div>

    {{-- Timezone --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Timezone</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Your local timezone</label>
        <select name="timezone" class="ia-input">
          @php
            $tzGroups = [
              'United States' => [
                'America/Los_Angeles' => 'Pacific (Los Angeles)',
                'America/Denver'      => 'Mountain (Denver)',
                'America/Phoenix'     => 'Mountain — no DST (Phoenix)',
                'America/Chicago'     => 'Central (Chicago)',
                'America/New_York'    => 'Eastern (New York)',
                'America/Anchorage'   => 'Alaska (Anchorage)',
                'Pacific/Honolulu'    => 'Hawaii (Honolulu)',
              ],
              'Canada' => [
                'America/Vancouver' => 'Pacific (Vancouver)',
                'America/Edmonton'  => 'Mountain (Edmonton)',
                'America/Winnipeg'  => 'Central (Winnipeg)',
                'America/Toronto'   => 'Eastern (Toronto)',
                'America/Halifax'   => 'Atlantic (Halifax)',
              ],
              'Other' => [
                'UTC'              => 'UTC',
                'Europe/London'    => 'London',
                'Europe/Paris'     => 'Paris',
                'Australia/Sydney' => 'Sydney',
              ],
            ];
            $currentTz = old('timezone', $currentTenant->timezone ?? 'America/Los_Angeles');
          @endphp
          @foreach($tzGroups as $groupName => $zones)
            <optgroup label="{{ $groupName }}">
              @foreach($zones as $tz => $label)
                <option value="{{ $tz }}" @selected($currentTz === $tz)>{{ $label }}</option>
              @endforeach
            </optgroup>
          @endforeach
        </select>
        <p style="font-size:12px;opacity:.5;margin-top:6px">
          Determines what counts as "today" on your calendar and dashboard. Stored timestamps are unaffected.
        </p>
      </div>
    </div>

    {{-- Booking window --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Booking window</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">How far ahead can customers book?</label>
          <input type="number" name="booking_window_days" class="ia-input" min="1" max="365"
            value="{{ old('booking_window_days', $currentTenant->booking_window_days ?? 60) }}">
          <p style="font-size:11px;opacity:.4;margin-top:4px">Days from today</p>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Minimum notice required</label>
          <input type="number" name="min_notice_hours" class="ia-input" min="0" max="168"
            value="{{ old('min_notice_hours', $currentTenant->min_notice_hours ?? 24) }}">
          <p style="font-size:11px;opacity:.4;margin-top:4px">0 = same-day bookings allowed</p>
        </div>
      </div>
    </div>

    {{-- Class bookings --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Class bookings</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Enable class bookings</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a Classes section to your admin and a customer-facing /classes page.</div>
        </div>
        <input type="hidden" name="classes_enabled" id="classes_enabled_input" value="{{ $currentTenant->classes_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->classes_enabled ? 'on' : '' }}"
          id="classes-toggle-btn"
          aria-label="Enable class bookings">
          <span class="ia-toggle-sr">{{ $currentTenant->classes_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
    </div>

    {{-- MARKER-PATCH-156 — Deliveries --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Deliveries</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Enable deliveries</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Internal pickup &amp; dropoff scheduling. Adds a Deliveries pill to your Schedule menu.</div>
        </div>
        <input type="hidden" name="deliveries_enabled" id="deliveries_enabled_input" value="{{ $currentTenant->deliveries_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->deliveries_enabled ? 'on' : '' }}"
          id="deliveries-toggle-btn"
          aria-label="Enable deliveries">
          <span class="ia-toggle-sr">{{ $currentTenant->deliveries_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
    </div>

    {{-- MARKER-PATCH-158-B — Multi-asset --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Multi-asset appointments</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Track customer assets</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Track bikes, vehicles, pets, or other items per customer, and attach multiple to a single appointment. Useful for family drop-offs, fleet servicing, or multi-pet appointments.</div>
        </div>
        <input type="hidden" name="multi_asset_enabled" id="multi_asset_enabled_input" value="{{ $currentTenant->multi_asset_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->multi_asset_enabled ? 'on' : '' }}"
          id="multi-asset-toggle-btn"
          aria-label="Enable multi-asset tracking">
          <span class="ia-toggle-sr">{{ $currentTenant->multi_asset_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
      {{-- MARKER-PATCH-215 — what this tenant calls its assets (drives customer booking copy) --}}
      <div class="ia-input-grid-2" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--ia-border,rgba(255,255,255,.08))">
        <div class="ia-form-group">
          <label class="ia-form-label">What you call one (singular)</label>
          <input type="text" name="asset_label_singular" class="ia-input" maxlength="30"
            placeholder="item" value="{{ old('asset_label_singular', $currentTenant->asset_label_singular) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Plural</label>
          <input type="text" name="asset_label_plural" class="ia-input" maxlength="30"
            placeholder="items" value="{{ old('asset_label_plural', $currentTenant->asset_label_plural) }}">
        </div>
      </div>
      <div style="font-size:12px;opacity:.5;margin-top:8px">Shown on your customer booking page — e.g. “bike”, “vehicle”, “pet”. Leave blank for “item”.</div>
    </div>

    {{-- Tax --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Sales tax</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Default tax rate (%)</label>
        <input type="number" name="default_tax_rate" class="ia-input" step="0.001" min="0" max="25"
          style="max-width:200px"
          value="{{ old('default_tax_rate', $currentTenant->default_tax_rate) }}"
          placeholder="e.g. 8.875">
        <p style="font-size:11px;opacity:.5;margin-top:6px;line-height:1.5">
          Applied to taxable items at checkout. Leave blank if you don't collect sales tax.
        </p>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-top:0.5px solid var(--ia-border);margin-top:8px">
        <div>
          <div style="font-size:13px;font-weight:500">Services are taxable by default</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Per-service overrides available later when editing a service.</div>
        </div>
        <input type="hidden" name="tax_services_default" id="tax_services_default_input" value="{{ ($currentTenant->tax_services_default ?? true) ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ ($currentTenant->tax_services_default ?? true) ? 'on' : '' }}"
          id="tax-services-toggle-btn"
          aria-label="Services are taxable by default">
          <span class="ia-toggle-sr">{{ ($currentTenant->tax_services_default ?? true) ? 'Yes' : 'No' }}</span>
        </button>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-top:0.5px solid var(--ia-border)">
        <div>
          <div style="font-size:13px;font-weight:500">Customers can be tax-exempt</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a "tax exempt" toggle to customer records (useful for non-profits, resellers).</div>
        </div>
        <input type="hidden" name="tax_supports_exempt" id="tax_supports_exempt_input" value="{{ ($currentTenant->tax_supports_exempt ?? false) ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ ($currentTenant->tax_supports_exempt ?? false) ? 'on' : '' }}"
          id="tax-exempt-toggle-btn"
          aria-label="Customers can be tax-exempt">
          <span class="ia-toggle-sr">{{ ($currentTenant->tax_supports_exempt ?? false) ? 'Yes' : 'No' }}</span>
        </button>
      </div>
    </div>

    {{-- Locations (coming soon) --}}
    <div class="set-coming-soon">
      <span class="set-coming-soon-pill">Add-on</span>
      <div class="set-coming-soon-title">Locations</div>
      <div class="set-coming-soon-desc">
        Run multiple shops from one Intake account — separate calendars, staff, and reporting per location.
        Available as a paid add-on. Talk to support to enable.
      </div>
    </div>

  </form>

  {{-- Drop-off methods (separate block — own endpoints, not part of the main form) --}}
  <div class="set-section set-section--grid">
    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Drop-off methods</span>
        <span style="font-size:11px;opacity:.45">Shown on the booking page so customers tell you how they're getting items to you</span>
      </div>

      <div style="padding:14px 16px">
        <form id="add-method-form" style="display:grid;grid-template-columns:1.2fr 1.6fr auto;gap:10px;align-items:end">
          @csrf
          <div>
            <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
            <input type="text" name="name" required maxlength="120" placeholder="e.g. Walk-in" class="ia-input" style="width:100%">
          </div>
          <div>
            <label class="ia-label" style="display:block;margin-bottom:5px">Description (optional)</label>
            <input type="text" name="description" maxlength="500" placeholder="e.g. Stop by during business hours" class="ia-input" style="width:100%">
          </div>
          <div>
            <button type="submit" class="ia-btn ia-btn--primary">Add</button>
          </div>
        </form>
        <div style="display:flex;gap:18px;margin-top:10px;font-size:12px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" form="add-method-form" name="ask_for_time" value="1"> Ask for arrival time
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" form="add-method-form" name="ask_for_tracking" value="1"> Ask for shipment tracking number
          </label>
        </div>
      </div>

      @if($receivingMethods->isEmpty())
        <div style="padding:24px;text-align:center;border-top:0.5px solid var(--ia-border)">
          <div style="font-size:13px;opacity:.55">No drop-off methods yet. Add your first one above.</div>
        </div>
      @else
        <div id="method-list" style="border-top:0.5px solid var(--ia-border)">
          @foreach($receivingMethods as $m)
            <div class="method-row" data-method-id="{{ $m->id }}"
                 style="display:grid;grid-template-columns:auto 1.2fr 1.6fr auto auto auto;gap:12px;align-items:center;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);{{ $m->is_active ? '' : 'opacity:.45' }}">
              <div class="drag-handle" style="cursor:grab;opacity:.4;font-size:14px;user-select:none">⋮⋮</div>
              <input type="text" data-field="name" value="{{ $m->name }}" maxlength="120" class="ia-input method-edit" style="width:100%">
              <input type="text" data-field="description" value="{{ $m->description }}" maxlength="500" placeholder="—" class="ia-input method-edit" style="width:100%">
              <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a time field on the booking page when this method is selected">
                <input type="checkbox" data-field="ask_for_time" {{ $m->ask_for_time ? 'checked' : '' }} class="method-edit-toggle">
                <span>Time</span>
              </label>
              <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a tracking-number field on the booking page when this method is selected">
                <input type="checkbox" data-field="ask_for_tracking" {{ $m->ask_for_tracking ? 'checked' : '' }} class="method-edit-toggle">
                <span>Tracking</span>
              </label>
              <button type="button" class="ia-toggle method-row-toggle {{ $m->is_active ? 'on' : '' }}" data-field="is_active" title="{{ $m->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                <span class="ia-toggle-sr">{{ $m->is_active ? 'Active' : 'Inactive' }}</span>
              </button>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  </div>
</div>

{{-- =====================================================================
     BRANDING — shop identity, logos, colors, typography
     ===================================================================== --}}
<div class="set-pane" id="pane-branding" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="branding">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save branding</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Shop identity</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Shop name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" value="{{ old('name', $currentTenant->name) }}" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Tagline</label>
        <input type="text" name="tagline" class="ia-input" value="{{ old('tagline', $currentTenant->tagline) }}"
          placeholder="e.g. Expert bike service since 2010">
      </div>
    </div>

    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Logos</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        Upload two versions of your logo. The system automatically picks the right one based on the background color.
      </p>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default logo <span style="opacity:.4;font-weight:400">(for light backgrounds)</span></label>
          @if($currentTenant->logo_url)
            <img src="{{ $currentTenant->logo_url }}" alt="Logo" class="logo-preview">
          @endif
          <input type="file" name="logo" accept="image/*" class="ia-input" style="padding:6px">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Light logo <span style="opacity:.4;font-weight:400">(for dark backgrounds)</span></label>
          @if($currentTenant->logo_light_url)
            <div class="logo-preview-dark">
              <img src="{{ $currentTenant->logo_light_url }}" alt="Light logo">
            </div>
          @endif
          <input type="file" name="logo_light" accept="image/*" class="ia-input" style="padding:6px">
          <div style="font-size:11px;opacity:.35;margin-top:4px">White or light-colored version for dark hero sections and dark theme booking forms.</div>
        </div>
      </div>
      <div class="ia-form-group" style="margin-top:12px">
        <label class="ia-form-label">Favicon</label>
        @if($currentTenant->favicon_url)
          <img src="{{ $currentTenant->favicon_url }}" alt="Favicon" style="height:32px;border-radius:4px;margin-bottom:8px;display:block">
        @endif
        <input type="file" name="favicon" accept="image/*" class="ia-input" style="padding:6px;max-width:300px">
      </div>
    </div>

    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Logo display size</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:18px">
        Drag the sliders to set how big the uploaded logo renders. The preview shows what it'll look like.
        Doesn't affect the file itself — re-uploading isn't needed.
      </p>

      @php
        // Pulled into PHP vars so JS init values match what's in the DB.
        $adminPx   = (int) ($currentTenant->logo_size_admin   ?? 26);
        $bookingPx = (int) ($currentTenant->logo_size_booking ?? 28);
        // Pick whichever logo will actually render in each surface.
        $adminLogo = \App\Support\ColorHelper::pickLogo($currentTenant, '#0c0c0c'); // dark sidebar
        $bookLogo  = \App\Support\ColorHelper::pickLogo($currentTenant, $currentTenant->bg_color ?? '#ffffff'); // booking bg
      @endphp

      {{-- Admin sidebar --}}
      <div style="margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="ia-form-label" style="margin:0">Admin sidebar</label>
          <span style="font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
            <span id="logo-admin-readout">{{ $adminPx }}</span>px
          </span>
        </div>
        <input type="range" name="logo_size_admin" id="logo-admin-slider"
               min="16" max="80" step="1" value="{{ $adminPx }}"
               style="width:100%;margin:0">
        <div style="font-size:11px;opacity:.45;margin-top:4px;display:flex;justify-content:space-between">
          <span>16px</span><span>80px</span>
        </div>

        {{-- Mini preview chip — mimics the sidebar logo block --}}
        <div style="margin-top:14px;background:#0c0c0c;border-radius:var(--ia-r-md);padding:14px 16px;display:flex;align-items:center;gap:10px;min-height:60px">
          @if($adminLogo)
            <img id="logo-admin-preview" src="{{ $adminLogo }}" alt="Admin logo preview"
                 style="height:{{ $adminPx }}px;width:auto;border-radius:4px;max-width:160px;object-fit:contain;transition:height .05s linear">
          @else
            <span style="color:#999;font-size:12px;font-style:italic">Upload a logo above to preview</span>
          @endif
        </div>
      </div>

      {{-- Booking page --}}
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="ia-form-label" style="margin:0">Booking page</label>
          <span style="font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
            <span id="logo-booking-readout">{{ $bookingPx }}</span>px
          </span>
        </div>
        <input type="range" name="logo_size_booking" id="logo-booking-slider"
               min="16" max="120" step="1" value="{{ $bookingPx }}"
               style="width:100%;margin:0">
        <div style="font-size:11px;opacity:.45;margin-top:4px;display:flex;justify-content:space-between">
          <span>16px</span><span>120px</span>
        </div>

        {{-- Mini preview chip — mimics the booking page top bar --}}
        @php $previewBg = $currentTenant->bg_color ?? '#ffffff'; @endphp
        <div style="margin-top:14px;background:{{ $previewBg }};border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:14px 16px;display:flex;align-items:center;gap:10px;min-height:80px">
          @if($bookLogo)
            <img id="logo-booking-preview" src="{{ $bookLogo }}" alt="Booking logo preview"
                 style="height:{{ $bookingPx }}px;width:auto;border-radius:4px;max-width:240px;object-fit:contain;transition:height .05s linear">
          @else
            <span style="color:#999;font-size:12px;font-style:italic">Upload a logo above to preview</span>
          @endif
        </div>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Colors</span></div>
      @foreach([
        ['accent_color', 'Accent color', $currentTenant->accent_color ?? '#BEF264', 'Used for buttons, links, and active states'],
        ['text_color',   'Text color',   $currentTenant->text_color   ?? '#111111', 'Main body text on your booking form'],
        ['bg_color',     'Background',   $currentTenant->bg_color     ?? '#ffffff', 'Page background on your booking form'],
      ] as [$name, $label, $value, $hint])
      <div class="ia-form-group">
        <label class="ia-form-label">{{ $label }}</label>
        <div class="color-swatch-row">
          <div class="color-swatch">
            <input type="color" name="{{ $name }}" value="{{ old($name, $value) }}" id="color-{{ $name }}">
          </div>
          <input type="text" class="ia-input" style="width:110px;font-family:var(--ia-font-mono);font-size:13px"
            value="{{ old($name, $value) }}" id="text-{{ $name }}"
            oninput="document.getElementById('color-{{ $name }}').value=this.value"
            pattern="^#[0-9A-Fa-f]{6}$">
          <span style="font-size:12px;opacity:.45">{{ $hint }}</span>
        </div>
      </div>
      @endforeach
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Typography</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Heading font</label>
          <select name="font_heading" class="ia-input">
            @foreach($fonts as $font)
              <option value="{{ $font }}" @selected(old('font_heading', $currentTenant->font_heading) === $font)>{{ $font }}</option>
            @endforeach
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Body font</label>
          <select name="font_body" class="ia-input">
            @foreach($fonts as $font)
              <option value="{{ $font }}" @selected(old('font_body', $currentTenant->font_body) === $font)>{{ $font }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
  </form>
</div>

{{-- =====================================================================
     COMMUNICATION — email sender, SMS provider, notifications
     ===================================================================== --}}
<div class="set-pane" id="pane-communication" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="communication">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save communication settings</button>
      </div>
    </div>



    {{-- Email sender details --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Email sender details</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        All emails to your customers will be sent from these details.
      </p>
      <div class="ia-form-group">
        <label class="ia-form-label">From name</label>
        <input type="text" name="email_from_name" class="ia-input"
          value="{{ old('email_from_name', $currentTenant->email_from_name) }}"
          placeholder="{{ $currentTenant->name }}">
      </div>
      {{-- MARKER-PATCH-143 — From address locked to <subdomain>@intake.works until custom domains land --}}
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">From email address</label>
          <input type="email" class="ia-input" readonly disabled
            value="{{ $currentTenant->subdomain }}@intake.works"
            style="opacity:.7;cursor:not-allowed">
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
            All your customer emails come from this address. Custom domains coming soon.
          </div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reply-to (optional)</label>
          <input type="email" name="email_reply_to" class="ia-input"
            value="{{ old('email_reply_to', $currentTenant->email_reply_to) }}"
            placeholder="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
            Where replies go. Usually your shop's main email.
          </div>
        </div>
      </div>

      {{-- MARKER-PATCH-144 — Test send block (no nested form, uses fetch) --}}
      <div style="margin-top:14px;padding:14px;background:rgba(190,242,100,.06);border:1px solid rgba(190,242,100,.18);border-radius:var(--ia-r-md)" id="email-test-block">
        <div style="font-size:13px;font-weight:500;margin-bottom:6px">Test your email setup</div>
        <div style="font-size:12px;color:var(--ia-text-dim);margin-bottom:10px;line-height:1.55">
          Save any changes above first. Then enter a recipient and send a test email to verify the From name and reply-to look right.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="email" id="email-test-recipient" class="ia-input" style="flex:1;min-width:240px"
            placeholder="recipient@example.com"
            value="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <button type="button" id="email-test-btn" class="ia-btn ia-btn--ghost ia-btn--sm">Send test email</button>
        </div>
        <div id="email-test-result" style="margin-top:10px;font-size:12px;display:none"></div>
      </div>
      <script>
        (function() {
          const btn = document.getElementById('email-test-btn');
          const recipient = document.getElementById('email-test-recipient');
          const result = document.getElementById('email-test-result');
          if (!btn) return;
          btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const r = (recipient.value || '').trim();
            if (!r) {
              result.style.display = 'block';
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Enter a recipient email first.';
              return;
            }
            btn.disabled = true;
            btn.textContent = 'Sending…';
            result.style.display = 'block';
            result.style.color = 'var(--ia-text-dim)';
            result.textContent = 'Sending test email to ' + r + '…';
            try {
              const resp = await fetch('{{ route('tenant.settings.email.test') }}', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                  'X-Requested-With': 'XMLHttpRequest',
                  'Accept': 'application/json'
                },
                body: 'recipient=' + encodeURIComponent(r)
              });
              if (resp.ok) {
                result.style.color = 'var(--ia-ok, #86EFAC)';
                result.textContent = 'Sent to ' + r + '. Check the inbox (and spam folder) within ~1 minute.';
              } else {
                const body = await resp.text();
                result.style.color = 'var(--ia-bad, #F87171)';
                result.textContent = 'Send failed (HTTP ' + resp.status + '). Check logs for details.';
              }
            } catch (err) {
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Send failed: ' + err.message;
            } finally {
              btn.disabled = false;
              btn.textContent = 'Send test email';
            }
          });
        })();
      </script>
      <div class="ia-form-group">
        <label class="ia-form-label">New booking notification email</label>
        <input type="email" name="notification_email" class="ia-input"
          value="{{ old('notification_email', $currentTenant->notification_email) }}"
          placeholder="Where to send new booking alerts">
      </div>
    </div>

    {{-- MARKER-PATCH-228B — Rentals pointer card --}}
    @if($currentTenant->rentals_enabled)
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Rentals &amp; leasing</span>
        <span class="ia-badge {{ $currentTenant->rentals_visible ? 'ia-badge--paid' : 'ia-badge--unpaid' }}">
          {{ $currentTenant->rentals_visible ? 'On' : 'Hidden' }}{{ $currentTenant->leases_enabled ? ' · leasing' : '' }}
        </span>
      </div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Turn rentals on or off, configure your season window, and enable season-long leasing.
      </p>
      <a href="{{ route('tenant.rentals.settings') }}" class="ia-btn ia-btn--primary">Open Rental settings</a>
    </div>
    @endif

    {{-- MARKER-PATCH-228B — Notifications/Alerts pointer card --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Notifications</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Choose how you hear about new bookings, overdue rentals, payments, and more — in-app and by text.
      </p>
      <a href="{{ route('tenant.alerts.prefs') }}" class="ia-btn ia-btn--primary">Open Notification settings</a>
    </div>

    {{-- MARKER-PATCH-224 — SMS config moved to Settings -> Messaging --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Text messaging</span>
        <span class="ia-badge {{ $currentTenant->sms_enabled && $currentTenant->sms_from_number ? 'ia-badge--paid' : 'ia-badge--unpaid' }}">
          {{ $currentTenant->sms_enabled && $currentTenant->sms_from_number ? 'Active · ' . $currentTenant->sms_from_number : 'Not set up' }}
        </span>
      </div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Your business text number, two-way Inbox routing, and SMS sending live on the Messaging page.
      </p>
      <a href="{{ route('tenant.settings.messaging') }}" class="ia-btn ia-btn--primary">Open Messaging settings</a>
    </div>

    {{-- MARKER-PATCH-406 — customer notifications moved to Communication Center --}}
    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Customer notifications</span></div>
      <p style="font-size:13px;opacity:.6;margin:0;line-height:1.55">
        Booking, delivery, reminder, and receipt messages are managed in
        <a href="{{ route('tenant.communication.index') }}" style="color:var(--ia-accent)">Communication</a>.
      </p>
    </div>
  </form>
  {{-- MARKER-PATCH-150-FIX — Web analytics card, outside parent form (HTML disallows nested forms) --}}
  {{-- MARKER-PATCH-150-POLISH-C — wrap in grid section so set-card--wide applies --}}
  <div class="set-section set-section--grid">
  <div class="ia-card set-card--wide" style="margin-bottom: 20px;">
    <div class="ia-card-head">
      <span class="ia-card-title">Web analytics</span>
    </div>
    <p style="font-size:13px;opacity:.5;margin-bottom:14px">
      Connect Google Analytics 4 to your public-facing pages. We'll inject the tracking script automatically.
      Leave blank to disable.
    </p>
    <form method="POST" action="{{ route('tenant.settings.analytics.update') }}">
      @csrf
      <div class="ia-form-group">
        <label class="ia-form-label">GA-4 measurement ID</label>
        <input type="text" name="analytics_ga4_id" class="ia-input"
               value="{{ old('analytics_ga4_id', $currentTenant->settings['analytics_ga4_id'] ?? '') }}"
               placeholder="G-XXXXXXXXXX"
               style="max-width: 320px; font-family: var(--ia-font-mono, 'JetBrains Mono', monospace);">
        <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
          Find this in your GA-4 Admin → Data Streams → Measurement ID. Starts with <code>G-</code>.
        </div>
      </div>
      @error('analytics_ga4_id')
        <div style="color: #F47373; font-size: 12px; margin-top: 6px;">{{ $message }}</div>
      @enderror
      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>
  </div>
  </div>{{-- MARKER-PATCH-150-POLISH-C close grid wrapper --}}

</div>

{{-- =====================================================================
     ACCOUNT — booking URL, custom domain, subscription
     ===================================================================== --}}
<div class="set-pane" id="pane-account" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="account">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save account</button>
      </div>
    </div>

    {{-- Booking URL (read-only) --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Your booking URL</span></div>
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">
        <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" rel="noopener noreferrer"
           style="color:var(--ia-accent);text-decoration:none;font-family:var(--ia-font-mono);font-size:13px">
          {{ $currentTenant->bookingUrl() }}
        </a>
      </div>
      <div style="font-size:12px;opacity:.5">This is where customers go to book with you.</div>
    </div>

    {{-- MARKER-PATCH-120 - Custom domains live on a dedicated page --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head">
        <span class="ia-card-title">Custom domains</span>
      </div>
      <p style="font-size:13px;opacity:.6;margin-bottom:14px;line-height:1.55">
        Connect your own domain — like <code style="font-family:var(--ia-font-mono);font-size:12px">{{ $currentTenant->subdomain }}.com</code> — to your Intake site. HTTPS is automatic.
      </p>
      <a href="{{ route('tenant.domains.index', []) }}"
         class="ia-btn ia-btn-secondary"
         style="display:inline-flex;align-items:center;gap:6px">
        Manage domains →
      </a>
    </div>
  </form>

  {{-- Subscription (read-only, separate from form) --}}
  <div class="set-section set-section--grid">
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Subscription</span></div>

      @if($currentTenant->stripe_customer_id)
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:480px;font-size:13px;margin-bottom:16px">
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Current plan</div>
            <div style="font-weight:500">{{ ucfirst($currentTenant->plan_tier ?? 'Starter') }}</div>
          </div>
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Status</div>
            <div style="font-weight:500">{{ ucfirst($currentTenant->subscription_status ?? 'unknown') }}</div>
          </div>
          @if($currentTenant->trial_ends_at)
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Trial ends</div>
            <div style="font-weight:500">{{ $currentTenant->trial_ends_at->format('M j, Y') }}</div>
          </div>
          @endif
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Billing</div>
            <div style="font-weight:500">{{ ucfirst($currentTenant->stripe_subscription_cadence ?? '') ?: '—' }}</div>
          </div>
        </div>

        <a href="{{ route('tenant.billing.portal', []) }}"
           class="ia-btn ia-btn--primary"
           target="_blank" rel="noopener noreferrer">
          Manage billing in Stripe →
        </a>
        <p style="font-size:12px;color:var(--ia-text-muted);margin-top:8px">
          Update your card, download invoices, or cancel your subscription through Stripe's secure portal.
        </p>
      @else
        <p style="margin:0;color:var(--ia-text-muted);font-size:13px;line-height:1.55">
          No billing account is connected to this tenant. Contact support to enable billing.
        </p>
      @endif
    </div>
  </div>
</div>

{{-- =====================================================================
     PAYMENTS — Stripe + PayPal (preserved verbatim)
     ===================================================================== --}}
<div class="set-pane" id="pane-payments" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="payments">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save payment settings</button>
      </div>
    </div>

    {{-- MARKER-PATCH-169 — Direct Payments bridge feature.
         Only renders when master admin flipped direct_payments_enabled on for this tenant.
         Tenant pastes their own Stripe keys here for register card-sales. --}}
    @if($currentTenant->direct_payments_enabled ?? false)
    {{-- MARKER-PATCH-618 — toggle-able (default on). Off hides card + payment-link tenders at the register; refunds of past charges still work. --}}
    <div class="provider-card {{ ($s['stripe_register_enabled'] ?? true) ? 'enabled' : '' }}" id="register-payments-card">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500;display:flex;align-items:center;gap:8px">
            Register card payments
          </div>
          <div style="font-size:12px;opacity:.6;margin-top:2px">Hand-key card numbers and send payment links from the register. Paste your own Stripe keys below.</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['stripe_register_enabled'] ?? true) ? 'on' : '' }}"
          id="register-payments-toggle" onclick="toggleProvider('register-payments')"></button>
        <input type="hidden" name="stripe_register_enabled" id="register-payments-enabled-val" value="{{ ($s['stripe_register_enabled'] ?? true) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="register-payments-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="register_payments_mode" class="ia-input" style="width:auto">
            <option value="test" @selected(($s['register_payments_mode'] ?? 'test') === 'test')>Test</option>
            <option value="live" @selected(($s['register_payments_mode'] ?? 'test') === 'live')>Live</option>
          </select>
          <div style="font-size:11px;opacity:.55;margin-top:6px">Start in test mode. Switch to live only after you've verified end-to-end flows with test cards.</div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Test publishable key</label>
            <input type="text" name="register_payments_test_pk" value="{{ $s['register_payments_test_pk'] ?? '' }}" class="ia-input" placeholder="pk_test_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Test secret key</label>
            <input type="password" name="register_payments_test_sk" value="{{ $s['register_payments_test_sk'] ?? '' }}" class="ia-input" placeholder="sk_test_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live publishable key</label>
            <input type="text" name="register_payments_live_pk" value="{{ $s['register_payments_live_pk'] ?? '' }}" class="ia-input" placeholder="pk_live_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live secret key</label>
            <input type="password" name="register_payments_live_sk" value="{{ $s['register_payments_live_sk'] ?? '' }}" class="ia-input" placeholder="sk_live_…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div class="ia-form-group">
          <label class="ia-form-label">Webhook signing secret</label>
          <input type="password" name="register_payments_webhook_secret" value="{{ $s['register_payments_webhook_secret'] ?? '' }}" class="ia-input" placeholder="whsec_…" autocomplete="off" spellcheck="false">
          <div style="font-size:11px;opacity:.55;margin-top:6px">
            From Stripe Dashboard -> Developers -> Webhooks. Point a new endpoint at <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">{{ url('/webhooks/stripe-direct/' . $currentTenant->id) }}</code> and subscribe to <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">payment_intent.succeeded</code>, <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">checkout.session.completed</code>, and <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">charge.refunded</code>.
          </div>
        </div>

      </div>
    </div>
    @endif

    {{-- MARKER-PATCH-473 — Square (tenant-connected, paste-token). Same master-admin gate as Stripe. --}}
    @if($currentTenant->direct_payments_enabled ?? false)
    <div class="provider-card {{ ($s['square_enabled'] ?? true) ? 'enabled' : '' }}" id="square-payments-card" style="margin-top:16px">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500;display:flex;align-items:center;gap:8px">Square card payments</div>
          <div style="font-size:12px;opacity:.6;margin-top:2px">Connect your own Square account as an alternative to Stripe. Paste the credentials from your Square app, save, then test the connection.</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['square_enabled'] ?? true) ? 'on' : '' }}"
          id="square-payments-toggle" onclick="toggleProvider('square-payments')"></button>
        <input type="hidden" name="square_enabled" id="square-payments-enabled-val" value="{{ ($s['square_enabled'] ?? true) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="square-payments-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="square_payments_mode" class="ia-input" style="width:auto">
            <option value="sandbox" @selected(($s['square_payments_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
            <option value="production" @selected(($s['square_payments_mode'] ?? 'sandbox') === 'production')>Production</option>
          </select>
          <div style="font-size:11px;opacity:.55;margin-top:6px">Sandbox and production are separate Square apps with their own credentials. Verify in sandbox first.</div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="font-size:11px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Sandbox credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Application ID</label>
            <input type="text" name="square_sandbox_app_id" value="{{ $s['square_sandbox_app_id'] ?? '' }}" class="ia-input" placeholder="sandbox-sq0idb-…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Location ID</label>
            <input type="text" name="square_sandbox_location_id" value="{{ $s['square_sandbox_location_id'] ?? '' }}" class="ia-input" placeholder="L…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group" style="grid-column:1 / -1">
            <label class="ia-form-label">Access token</label>
            <input type="password" name="square_sandbox_access_token" value="{{ $s['square_sandbox_access_token'] ?? '' }}" class="ia-input" placeholder="EAAAl…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="font-size:11px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Production credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Application ID</label>
            <input type="text" name="square_production_app_id" value="{{ $s['square_production_app_id'] ?? '' }}" class="ia-input" placeholder="sq0idp-…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Location ID</label>
            <input type="text" name="square_production_location_id" value="{{ $s['square_production_location_id'] ?? '' }}" class="ia-input" placeholder="L…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group" style="grid-column:1 / -1">
            <label class="ia-form-label">Access token</label>
            <input type="password" name="square_production_access_token" value="{{ $s['square_production_access_token'] ?? '' }}" class="ia-input" placeholder="EAAAl…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div class="ia-form-group">
          <label class="ia-form-label">Webhook signature key</label>
          <input type="password" name="square_webhook_signature_key" value="{{ $s['square_webhook_signature_key'] ?? '' }}" class="ia-input" placeholder="webhook signature key" autocomplete="off" spellcheck="false">
          <div style="font-size:11px;opacity:.55;margin-top:6px">
            From Square Developer Console -> your app -> Webhooks. Point a subscription at <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">{{ url('/webhooks/square/' . $currentTenant->id) }}</code> and subscribe to <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">payment.updated</code> and <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">refund.updated</code>.
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="display:flex;align-items:center;gap:12px">
          <button type="button" class="ia-btn ia-btn--ghost" onclick="squareTestConnection(this)">Test connection</button>
          <span id="square-test-result" style="font-size:12px;opacity:.85"></span>
        </div>
        <div style="font-size:11px;opacity:.55;margin-top:8px">Save your credentials first, then test. This calls Square with your saved access token to confirm the location is reachable.</div>
      </div>
    </div>
    <script>
      window.squareTestConnection = function (btn) {
        var out = document.getElementById('square-test-result');
        btn.disabled = true; out.textContent = 'Testing…'; out.style.color = '';
        fetch({!! json_encode(route('tenant.settings.square.verify')) !!}, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': {!! json_encode(csrf_token()) !!}, 'Accept': 'application/json' },
          body: '{}'
        }).then(function (r) { return r.json(); }).then(function (d) {
          btn.disabled = false;
          if (d && d.ok) { out.textContent = '\u2713 ' + (d.message || 'Connected'); out.style.color = 'var(--ia-accent)'; }
          else { out.textContent = '\u2715 ' + ((d && d.message) || 'Failed'); out.style.color = '#f87171'; }
        }).catch(function () { btn.disabled = false; out.textContent = '\u2715 Request failed'; out.style.color = '#f87171'; });
      };
    </script>
    @endif

    {{-- PayPal --}}
    <div class="provider-card {{ ($s['paypal_enabled'] ?? false) ? 'enabled' : '' }}" id="paypal-card">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500">PayPal</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">PayPal, Venmo, Pay Later</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['paypal_enabled'] ?? false) ? 'on' : '' }}"
          id="paypal-toggle" onclick="toggleProvider('paypal')"></button>
        <input type="hidden" name="paypal_enabled" id="paypal-enabled-val" value="{{ ($s['paypal_enabled'] ?? false) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="paypal-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="paypal_mode" class="ia-input" style="width:auto">
            <option value="sandbox" @selected(($s['paypal_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
            <option value="live"    @selected(($s['paypal_mode'] ?? 'sandbox') === 'live')>Live</option>
          </select>
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Sandbox client ID</label>
            <input type="text" name="paypal_test_client_id" class="ia-input ia-mono" value="{{ $s['paypal_test_client_id'] ?? '' }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Sandbox secret</label>
            <input type="password" name="paypal_test_secret" class="ia-input ia-mono" value="{{ $s['paypal_test_secret'] ?? '' }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live client ID</label>
            <input type="text" name="paypal_live_client_id" class="ia-input ia-mono" value="{{ $s['paypal_live_client_id'] ?? '' }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live secret</label>
            <input type="password" name="paypal_live_secret" class="ia-input ia-mono" value="{{ $s['paypal_live_secret'] ?? '' }}">
          </div>
        </div>
      </div>
    </div>

  </form>

  {{-- MARKER-PATCH-629 — unified payment methods list (replaces the 618 Venmo/Cash App cards) --}}
  @include('tenant.settings._payment-methods')
</div>
{{-- MARKER-PATCH-315 — Work-order tag settings --}}
{{-- =====================================================================
     ORDERING — how special orders pick a vendor      MARKER-SO-AUTOVENDOR
     ===================================================================== --}}
@php $soAuto = $s['special_orders']['auto_assign_vendor'] ?? 'preferred'; @endphp
<div class="set-pane" id="pane-ordering" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="ordering">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span>
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save ordering settings</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Special orders — vendor assignment</span></div>
      <p style="font-size:13px;opacity:.55;margin-bottom:16px">
        When a special order is created, Intake can pick the vendor for you from the
        vendors already linked to that item. You can always change it before placing the order.
      </p>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="preferred" @checked($soAuto === 'preferred')>
        <span>
          <strong style="display:block;font-size:13.5px">Preferred vendor</strong>
          <span style="font-size:12px;opacity:.6">Uses the vendor marked preferred on the item, falling back to whoever you ordered from most recently.</span>
        </span>
      </label>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="lowest_price" @checked($soAuto === 'lowest_price')>
        <span>
          <strong style="display:block;font-size:13.5px">Lowest price</strong>
          <span style="font-size:12px;opacity:.6">Cheapest cost among vendors that carry it, preferring vendors that actually show stock. Falls back to the preferred vendor when no cost is known.</span>
        </span>
      </label>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="off" @checked($soAuto === 'off')>
        <span>
          <strong style="display:block;font-size:13.5px">Don't assign automatically</strong>
          <span style="font-size:12px;opacity:.6">Leave the vendor blank and choose it yourself on the special orders screen.</span>
        </span>
      </label>
    </div>

    {{-- MARKER-BIZ-SETTINGS — defaults for new business customers, so
         payment terms and PO-required are not fields you have to remember
         to set one customer at a time. --}}
    @php $custDefaults = $s['customers'] ?? []; @endphp
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Business customers — defaults</span></div>
      <p style="font-size:13px;opacity:.55;margin-bottom:16px">
        Applied when a new business customer is created. Each customer can still be changed individually.
      </p>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default payment terms</label>
          <select name="cust_default_payment_terms" class="ia-input">
            <option value="">Due at service</option>
            <option value="net_15" @selected(($custDefaults['default_payment_terms'] ?? '') === 'net_15')>Net 15</option>
            <option value="net_30" @selected(($custDefaults['default_payment_terms'] ?? '') === 'net_30')>Net 30</option>
            <option value="net_60" @selected(($custDefaults['default_payment_terms'] ?? '') === 'net_60')>Net 60</option>
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Purchase orders</label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:10px 0;cursor:pointer">
            <input type="checkbox" name="cust_default_po_required" value="1" @checked($custDefaults['default_po_required'] ?? false)>
            <span>New business customers require a PO by default</span>
          </label>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="set-pane" id="pane-tags" role="tabpanel">
  @php
    $wot      = $s['work_order_tag'] ?? [];
    $wotOn    = fn($k) => array_key_exists($k, $wot) ? (bool) $wot[$k] : true;
    $wotLead  = $wot['lead_days'] ?? 3;
    $wotPaper = ($wot['paper'] ?? '80mm') === '58mm' ? '58mm' : '80mm';
    $wotLogo  = $wot['logo_path'] ?? null;
    $wotFeed  = (int) ($wot['feed_mm'] ?? 0);
    $wotHeader = (string) ($wot['header_text'] ?? ''); // MARKER-PATCH-330
    $wotFooter = (string) ($wot['footer_text'] ?? ''); // MARKER-PATCH-330
  @endphp
  <style>
    .wot-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:0.5px solid var(--ia-border);cursor:pointer}
    .wot-row:last-child{border-bottom:none}
    .wot-row-l .t{font-size:13px;color:var(--ia-text)}
    .wot-row-l .d{font-size:11.5px;color:var(--ia-muted);margin-top:2px}
    .wot-switch{appearance:none;-webkit-appearance:none;width:38px;height:22px;border-radius:99px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);position:relative;cursor:pointer;flex-shrink:0;transition:background .15s;margin:0}
    .wot-switch::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:var(--ia-muted);transition:all .15s}
    .wot-switch:checked{background:var(--ia-accent);border-color:var(--ia-accent)}
    .wot-switch:checked::after{left:18px;background:#0a0a0a}
    .wot-seg{display:flex;gap:6px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:8px;padding:4px;max-width:240px}
    .wot-seg label{flex:1;text-align:center;padding:8px;border-radius:5px;font-size:13px;cursor:pointer;color:var(--ia-muted);position:relative}
    .wot-seg input{position:absolute;opacity:0;pointer-events:none}
    .wot-seg label:has(input:checked){background:var(--ia-accent);color:#0a0a0a;font-weight:600}
    .wot-logo-preview{background:#fff;padding:10px 12px;border-radius:8px;display:inline-block;margin-bottom:10px}
    .wot-logo-preview img{max-height:42px;max-width:200px;display:block}
  </style>

  <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH') {{-- MARKER-PATCH-316 --}}
    <input type="hidden" name="tab" value="tags">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span>
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save tag settings</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <label class="wot-row" style="border:none;padding:2px 0">
        <span class="wot-row-l">
          <span class="t">Print service tags</span>
          <span class="d">Hang a tag on each item at drop-off. Prints to your 80mm receipt printer.</span>
        </span>
        <input type="checkbox" name="wot_enabled" value="1" {{ $wotOn('enabled') ? 'checked' : '' }} class="wot-switch">
      </label>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">What prints on the tag</span></div>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Shop name / logo header</span></span><input type="checkbox" name="wot_show_header" value="1" {{ $wotOn('show_header') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Customer phone</span></span><input type="checkbox" name="wot_show_phone" value="1" {{ $wotOn('show_phone') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Item / asset description</span></span><input type="checkbox" name="wot_show_bike" value="1" {{ $wotOn('show_bike') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Requested services</span></span><input type="checkbox" name="wot_show_services" value="1" {{ $wotOn('show_services') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Intake note</span></span><input type="checkbox" name="wot_show_note" value="1" {{ $wotOn('show_note') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">QR code (links to the job)</span></span><input type="checkbox" name="wot_show_qr" value="1" {{ $wotOn('show_qr') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Tear-off customer claim stub</span></span><input type="checkbox" name="wot_show_stub" value="1" {{ $wotOn('show_stub') ? 'checked' : '' }} class="wot-switch"></label>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Defaults</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default &ldquo;promised by&rdquo;</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="wot_lead_days" value="{{ $wotLead }}" min="0" max="30" class="ia-input" style="width:84px">
            <span style="font-size:13px;color:var(--ia-muted)">business days after drop-off</span>
          </div>
          <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Prefilled on new jobs; editable per work order.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Paper width</label>
          <div class="wot-seg">
            <label><input type="radio" name="wot_paper" value="80mm" {{ $wotPaper === '80mm' ? 'checked' : '' }}><span>80mm</span></label>
            <label><input type="radio" name="wot_paper" value="58mm" {{ $wotPaper === '58mm' ? 'checked' : '' }}><span>58mm</span></label>
          </div>
        </div>
        {{-- MARKER-PATCH-320 --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Extra paper after cut</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="wot_feed_mm" value="{{ $wotFeed }}" min="0" max="40" class="ia-input" style="width:84px">
            <span style="font-size:13px;color:var(--ia-muted)">mm of feed so it clears the cutter</span>
          </div>
          <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Try 10&ndash;15mm if the last line cuts too close.</div>
        </div>
      </div>
    </div>

    {{-- MARKER-PATCH-330 --}}
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Header &amp; footer</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Header lines</label>
        <textarea name="wot_header_text" rows="2" class="ia-input" placeholder="e.g. 509-555-1234&#10;Mon–Fri 9–6" style="resize:vertical">{{ $wotHeader }}</textarea>
        <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Shown under your logo on tags, receipts &amp; slips. One per line.</div>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Footer message</label>
        <textarea name="wot_footer_text" rows="2" class="ia-input" placeholder="e.g. Thanks for riding with us!" style="resize:vertical">{{ $wotFooter }}</textarea>
        <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Printed at the bottom. Leave blank for the default.</div>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Logo</span></div>
      @if($wotLogo)
        <div class="wot-logo-preview"><img src="{{ asset('storage/' . ltrim($wotLogo, '/')) }}" alt="Tag logo"></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ia-muted);margin-bottom:12px;cursor:pointer">
          <input type="checkbox" name="wot_logo_remove" value="1"> Remove current logo
        </label>
      @endif
      {{-- MARKER-PATCH-317 --}}
      <div class="ia-form-group" style="margin-bottom:12px;max-width:240px">
        <label class="ia-form-label">Logo size on tag</label>
        @php $wls = $wot['logo_size'] ?? 'medium'; @endphp
        <select name="wot_logo_size" class="ia-input">
          <option value="small"  {{ $wls === 'small'  ? 'selected' : '' }}>Small</option>
          <option value="medium" {{ $wls === 'medium' ? 'selected' : '' }}>Medium</option>
          <option value="large"  {{ $wls === 'large'  ? 'selected' : '' }}>Large</option>
          <option value="xl"     {{ $wls === 'xl'     ? 'selected' : '' }}>Extra large</option>
        </select>
      </div>
      <input type="file" name="wot_logo" accept="image/png,image/jpeg,image/webp" class="ia-input">
      <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">High-contrast black-on-white prints best on thermal. Shown at the top of each tag in place of the shop name.</div>
    </div>

  </form>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
(function() {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  /* -----------------------------------------------------------------------
   * Tab switching (no URL params)
   * ----------------------------------------------------------------------- */
  function switchTab(name) {
    document.querySelectorAll('.set-tab').forEach(function(t) {
      t.classList.toggle('active', t.dataset.tab === name);
    });
    document.querySelectorAll('.set-pane').forEach(function(p) {
      p.classList.toggle('active', p.id === 'pane-' + name);
    });
    // Reset window scroll so a long pane doesn't start mid-page
    window.scrollTo({ top: 0, behavior: 'instant' });
  }
  document.querySelectorAll('.set-tab').forEach(function(t) {
    t.addEventListener('click', function() { switchTab(t.dataset.tab); });
  });

  /* -----------------------------------------------------------------------
   * Dirty tracking — per form, save bar dims when no changes
   * ----------------------------------------------------------------------- */
  // MARKER-PATCH-166 — savebar shows ONLY the unsaved-changes warning.
  // Save confirmation lives in the top flash banner (one source of truth).
  document.querySelectorAll('[data-dirty-form]').forEach(function(form) {
    var savebar = form.querySelector('[data-savebar]');
    var msg     = savebar ? savebar.querySelector('.set-savebar-msg') : null;
    var initial = serialize(form);

    function serialize(f) {
      // For dirty tracking we build a stable string from the form's editable
      // values. File inputs and password fields with placeholder dots can't
      // be reliably serialized, so we only mark dirty on text/select/hidden
      // changes — any user interaction is enough to flip the bar.
      var parts = [];
      Array.from(f.elements).forEach(function(el) {
        if (!el.name) return;
        if (el.type === 'file') {
          if (el.files && el.files.length) parts.push(el.name + '=FILE');
          return;
        }
        if (el.type === 'checkbox' || el.type === 'radio') {
          parts.push(el.name + '=' + (el.checked ? '1' : '0') + '|' + (el.value || ''));
          return;
        }
        parts.push(el.name + '=' + (el.value || ''));
      });
      return parts.join('&');
    }

    function checkDirty() {
      var nowSerialized = serialize(form);
      var dirty = nowSerialized !== initial;
      if (savebar) {
        savebar.classList.toggle('dirty', dirty);
        // MARKER-PATCH-166 — savebar shows the warning only.
        // Save confirmation is handled by the global flash banner at the top
        // (layouts/tenant/app.blade.php). Dual confirmation was confusing.
        if (msg) {
          msg.textContent = dirty ? 'You have unsaved changes.' : '';
        }
      }
    }

    // Initial paint
    checkDirty();

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);

    // Discard: reload the page (server-rendered, so this resets to saved state)
    var discardBtn = form.querySelector('[data-discard]');
    if (discardBtn) {
      discardBtn.addEventListener('click', function() {
        if (confirm('Discard your unsaved changes?')) {
          window.location.reload();
        }
      });
    }
  });

  /* -----------------------------------------------------------------------
   * Generic "ia-toggle bound to hidden input" pattern. Used on:
   *   - Business: classes_enabled, tax_services_default, tax_supports_exempt
   *   - Communication: sms_enabled, notify_booking_confirmation_email/sms
   *
   * Clicking the toggle flips both the visual class and the hidden input's
   * value, then dispatches a 'change' on the input so dirty tracking runs.
   * ----------------------------------------------------------------------- */
  function bindToggle(btnId, inputId) {
    var btn   = document.getElementById(btnId);
    var input = document.getElementById(inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', function() {
      if (btn.disabled) return;
      var on = !btn.classList.contains('on');
      btn.classList.toggle('on', on);
      input.value = on ? '1' : '0';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }
  bindToggle('classes-toggle-btn',          'classes_enabled_input');
  // MARKER-PATCH-156
  bindToggle('deliveries-toggle-btn',       'deliveries_enabled_input');
  // MARKER-PATCH-158-B
  bindToggle('multi-asset-toggle-btn',      'multi_asset_enabled_input');
  bindToggle('tax-services-toggle-btn',     'tax_services_default_input');
  bindToggle('tax-exempt-toggle-btn',       'tax_supports_exempt_input');
  // notify toggles removed — patch-406 (moved to Communication Center)

  /* -----------------------------------------------------------------------
   * Branding: color picker text/swatch sync
   * ----------------------------------------------------------------------- */
  document.querySelectorAll('input[type=color]').forEach(function(picker) {
    var textId = picker.id.replace('color-', 'text-');
    var text   = document.getElementById(textId);
    if (text) picker.addEventListener('input', function() { text.value = picker.value; });
  });

  /* -----------------------------------------------------------------------
   * Drop-off methods CRUD (preserved verbatim from the previous settings
   * page — endpoints unchanged, just wrapped in the new tab structure).
   * ----------------------------------------------------------------------- */
  var list = document.getElementById('method-list');

  // Add new method
  var addForm = document.getElementById('add-method-form');
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var fd = new FormData(addForm);
      var body = {
        name:             fd.get('name'),
        description:      fd.get('description'),
        ask_for_time:     fd.get('ask_for_time') ? 1 : 0,
        ask_for_tracking: fd.get('ask_for_tracking') ? 1 : 0,
      };
      fetch("{{ route('tenant.receiving-methods.store') }}", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        if (r.ok) window.location.reload();
        else alert('Could not add method.');
      });
    });
  }

  // Drag-to-reorder
  if (list && window.Sortable) {
    Sortable.create(list, {
      handle: '.drag-handle',
      animation: 150,
      onEnd: function() {
        var ids = Array.from(list.querySelectorAll('.method-row'))
                       .map(function(r) { return r.getAttribute('data-method-id'); });
        fetch("{{ route('tenant.receiving-methods.reorder') }}", {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          body: JSON.stringify({ order: ids }),
        }).then(function(r) {
          // MARKER-PATCH-248
          if (r.ok) { if (window.IntakeToast) IntakeToast.success('Order saved'); }
          else { if (window.IntakeToast) IntakeToast.error('Could not save the new order'); }
        }).catch(function() { if (window.IntakeToast) IntakeToast.error('Could not save the new order — check your connection'); });
      }
    });
  }

  // Inline edit on blur (text) / change (checkbox)
  document.querySelectorAll('.method-edit, .method-edit-toggle').forEach(function(el) {
    var evt = el.type === 'checkbox' ? 'change' : 'blur';
    el.addEventListener(evt, function() {
      var row = el.closest('.method-row');
      var id  = row.getAttribute('data-method-id');
      var field = el.getAttribute('data-field');
      var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
      var body = {};
      body[field] = value;
      fetch("{{ url('admin/receiving-methods') }}/" + id, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        // MARKER-PATCH-248 — saves speak.
        if (r.ok) { if (window.IntakeToast) IntakeToast.success('Saved'); }
        else {
          row.style.outline = '1px solid #d04444';
          setTimeout(function() { row.style.outline = ''; }, 1500);
          if (window.IntakeToast) IntakeToast.error('Could not save — try again');
        }
      }).catch(function() { if (window.IntakeToast) IntakeToast.error('Could not save — check your connection'); });
    });
  });

  // Active toggle
  document.querySelectorAll('.method-row-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (btn.classList.contains('is-busy')) return;
      var row    = btn.closest('.method-row');
      var id     = row.getAttribute('data-method-id');
      var field  = btn.getAttribute('data-field');
      var newVal = !btn.classList.contains('on');
      btn.classList.add('is-busy');
      var body = {};
      body[field] = newVal ? 1 : 0;
      fetch("{{ url('admin/receiving-methods') }}/" + id, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        btn.classList.remove('is-busy');
        if (r.ok) {
          btn.classList.toggle('on', newVal);
          row.style.opacity = newVal ? '' : '.45';
          btn.setAttribute('title', newVal ? 'Click to deactivate' : 'Click to activate');
          btn.querySelector('.ia-toggle-sr').textContent = newVal ? 'Active' : 'Inactive';
        } else {
          row.style.outline = '1px solid #d04444';
          setTimeout(function() { row.style.outline = ''; }, 1500);
          if (window.IntakeToast) IntakeToast.error('Could not update — try again'); // MARKER-PATCH-248
        }
      }).catch(function() {
        btn.classList.remove('is-busy');
        if (window.IntakeToast) IntakeToast.error('Could not update — check your connection'); // MARKER-PATCH-248
      });
    });
  });

  /* -----------------------------------------------------------------------
   * SMS test send
   * ----------------------------------------------------------------------- */
  var smsTestBtn    = document.getElementById('sms-test-btn');
  var smsTestTo     = document.getElementById('sms_test_to');
  var smsTestStatus = document.getElementById('sms-test-status');

  if (smsTestBtn && smsTestTo && smsTestStatus) {
    smsTestBtn.addEventListener('click', function() {
      var to = smsTestTo.value.trim();
      if (!to) {
        smsTestStatus.className = 'sms-test-status error';
        smsTestStatus.textContent = 'Enter a phone number first.';
        return;
      }
      smsTestStatus.className = 'sms-test-status';
      smsTestStatus.textContent = '';
      smsTestBtn.disabled = true;
      smsTestBtn.textContent = 'Sending…';

      fetch("{{ route('tenant.settings.test-sms') }}", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ to: to }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        smsTestBtn.disabled = false;
        smsTestBtn.textContent = 'Send test';
        if (res.ok && res.body.ok) {
          smsTestStatus.className = 'sms-test-status success';
          smsTestStatus.textContent = res.body.message || 'Test sent.';
        } else {
          smsTestStatus.className = 'sms-test-status error';
          smsTestStatus.textContent = (res.body && res.body.error) || 'Send failed.';
        }
      }).catch(function() {
        smsTestBtn.disabled = false;
        smsTestBtn.textContent = 'Send test';
        smsTestStatus.className = 'sms-test-status error';
        smsTestStatus.textContent = 'Network error.';
      });
    });
  }

  /* -----------------------------------------------------------------------
   * Logo size sliders — live preview chip resize
   *
   * Slider input dispatches 'input' on every drag tick. We mutate the
   * preview img's height directly. The slider itself is a normal form input
   * so dirty tracking + save bar fire automatically.
   * ----------------------------------------------------------------------- */
  function bindLogoSlider(sliderId, readoutId, previewId) {
    var slider  = document.getElementById(sliderId);
    var readout = document.getElementById(readoutId);
    var preview = document.getElementById(previewId);
    if (!slider) return;
    slider.addEventListener('input', function() {
      var v = parseInt(slider.value, 10) || 16;
      if (readout) readout.textContent = v;
      if (preview) preview.style.height = v + 'px';
    });
  }
  bindLogoSlider('logo-admin-slider',   'logo-admin-readout',   'logo-admin-preview');
  bindLogoSlider('logo-booking-slider', 'logo-booking-readout', 'logo-booking-preview');

})();

/* -----------------------------------------------------------------------
 * Provider toggle (Stripe / PayPal) — needs to be global because the
 * onclick attribute references it from inline. Preserved from old page.
 * ----------------------------------------------------------------------- */
function toggleProvider(name) {
  var card     = document.getElementById(name + '-card');
  var toggle   = document.getElementById(name + '-toggle');
  var valInput = document.getElementById(name + '-enabled-val');
  var enabled  = toggle.classList.toggle('on');
  card.classList.toggle('enabled', enabled);
  valInput.value = enabled ? '1' : '0';
  // Trigger dirty tracking on the parent form
  valInput.dispatchEvent(new Event('change', { bubbles: true }));
}
</script>
@endpush

BIZ3_6_EOF

cat > 'resources/views/tenant/customers/index.blade.php' <<'BIZ3_7_EOF'
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
    'businesses_only' => 'Businesses only', // MARKER-BIZ-LIST
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
            <td>
              <span style="font-weight:500">{{ $c->fullName() }}</span>@if($c->is_vip)<span class="vip-list-star" title="VIP">★</span>@endif
              {{-- MARKER-BIZ-LIST --}}
              @if($c->isBusiness())
                <span class="biz-pill">Business</span>
                @if($c->tax_exempt)<span class="biz-pill exempt">Tax exempt</span>@endif
              @endif
            </td>
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
          <span class="cust-card-name">{{ $c->fullName() }}</span>
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

{{-- MARKER-BIZ-LIST --}}
<style>
  .biz-pill{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border-radius:100px;padding:2px 7px;margin-left:6px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);vertical-align:1px}
  .biz-pill.exempt{border-color:rgba(232,163,61,.4);color:#E8A33D}
</style>

@endsection
BIZ3_7_EOF

cat > 'resources/views/tenant/customers/show.blade.php' <<'BIZ3_8_EOF'
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
      {{ $customer->fullName() }}
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
      <h1 class="cmd-hero-name">{{ $customer->fullName() }}</h1>
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
BIZ3_8_EOF

cat > 'resources/views/tenant/customers/customer-show.blade.php' <<'BIZ3_9_EOF'
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
BIZ3_9_EOF

cat > 'resources/views/tenant/appointments/show.blade.php' <<'BIZ3_10_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = $appointment->ra_number;
  $statusLabels = \App\Support\AppointmentStatus::LABELS; // MARKER-PATCH-287 single source
  $transitionLabels = [
    'confirmed'   => 'Confirm',
    'in_progress' => 'Start work',
    'completed'   => 'Mark completed',
    'shipped'     => 'Mark shipped',
    'closed'      => 'Close job',
    'cancelled'   => 'Cancel appointment',
    'refunded'    => 'Refund',
  ];
  $updateUrl = route('tenant.appointments.update', $appointment->id);
@endphp

<style>
.appt-layout { display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: start; }
.appt-section-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 500; opacity: .45; margin-bottom: 10px; }
.appt-line-items { width: 100%; border-collapse: collapse; font-size: 13px; }
.appt-line-items th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 500; opacity: .45; padding: 6px 0; text-align: left; border-bottom: 0.5px solid var(--ia-border); }
.appt-line-items td { padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); vertical-align: middle; }
.appt-line-items tr:last-child td { border-bottom: none; }
.appt-line-items .ia-num { text-align: right; font-variant-numeric: tabular-nums; }
.appt-total-row { display: flex; justify-content: space-between; padding: 10px 0; border-top: 0.5px solid var(--ia-border); font-weight: 500; }
.appt-response { display: flex; flex-direction: column; gap: 2px; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); }
.appt-response:last-child { border-bottom: none; }
.appt-response-label { font-size: 11px; opacity: .45; }
.appt-response-value { font-size: 13px; }
.appt-charge-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.appt-charge-row:last-child { border-bottom: none; }
.sidebar-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.sidebar-stat:last-child { border-bottom: none; }
.sidebar-stat-label { opacity: .5; }
.sidebar-stat-value { font-weight: 500; }
.add-charge-form { display: none; margin-top: 12px; padding-top: 12px; border-top: 0.5px solid var(--ia-border); }
.add-charge-form.open { display: block; }
@media (max-width: 900px) { .appt-layout { grid-template-columns: 1fr; } }

/* Status progress bar */
.appt-progress-card { padding: 18px 24px; margin-bottom: 20px; }
.appt-progress-bar { display: flex; align-items: flex-start; justify-content: space-between; position: relative; gap: 4px; }
.appt-progress-bar::before { content: ''; position: absolute; top: 12px; left: 12px; right: 12px; height: 2px; background: var(--ia-border); z-index: 0; }
.appt-progress-bar::after {
  content: ''; position: absolute; top: 12px; left: 12px; height: 2px; background: var(--ia-accent); z-index: 0;
  width: calc((100% - 24px) * var(--progress, 0));
  transition: width .25s ease;
}
.appt-progress-step {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  position: relative; z-index: 1; background: transparent; border: none; cursor: pointer; padding: 0;
  flex: 1; min-width: 0; font-family: inherit;
}
.appt-progress-step:disabled { cursor: default; }
.appt-progress-dot {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--ia-surface); border: 0.5px solid var(--ia-border);
  display: flex; align-items: center; justify-content: center;
  transition: background var(--ia-t), border-color var(--ia-t);
  color: #fff;
}
.appt-progress-step.is-done .appt-progress-dot { background: var(--ia-accent); border-color: var(--ia-accent); color: var(--ia-accent-text); }
.appt-progress-step.is-current .appt-progress-dot { border: 2px solid var(--ia-accent); background: var(--ia-surface); }
.appt-progress-dot-inner { width: 8px; height: 8px; border-radius: 50%; background: var(--ia-accent); }
.appt-progress-label { font-size: 11px; color: var(--ia-text-muted); transition: color var(--ia-t); }
.appt-progress-step.is-current .appt-progress-label { font-weight: 500; color: var(--ia-text); }
.appt-progress-step:not(:disabled):hover .appt-progress-dot { border-color: var(--ia-accent); }
.appt-progress-step.is-saving .appt-progress-dot { opacity: .5; }

/* Terminal state card (cancelled / refunded) */
.appt-terminal-card { display: flex; align-items: center; gap: 14px; padding: 18px 24px; margin-bottom: 20px; }
.appt-terminal-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #fff; }
.appt-terminal-icon--cancelled { background: #A32D2D; }
.appt-terminal-icon--refunded { background: #BA7517; }
.appt-terminal-title { font-size: 15px; font-weight: 500; }
.appt-terminal-sub { font-size: 13px; color: var(--ia-text-muted); margin-top: 2px; }
.appt-terminal-card .appt-reopen-btn { margin-left: auto; }

.appt-cancel-btn { margin-top: 4px; }

/* LAYOUT-B-CSS v1 */
.appt-b-shell { display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start; }
.appt-b-rail { display: flex; flex-direction: column; gap: 14px; position: sticky; top: 16px; }
.appt-b-main { display: flex; flex-direction: column; gap: 18px; }
@media (max-width: 900px) { .appt-b-shell { grid-template-columns: 1fr; } .appt-b-rail { position: static; } }

/* Time/date hero band — left rail, accent border-left */
.appt-b-when {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-left: 3px solid var(--ia-accent);
  border-radius: var(--ia-r-md);
  padding: 14px 16px;
}
.appt-b-when-time {
  font-size: 22px; font-weight: 500; letter-spacing: -.01em; line-height: 1.15;
  color: var(--ia-text);
}
.appt-b-when-date { font-size: 12px; color: var(--ia-text-muted); margin-top: 4px; }
.appt-b-when-dur  { font-size: 11px; color: var(--ia-text-muted); margin-top: 6px; opacity: .7; }
.appt-b-when-resource {
  margin-top: 12px; padding-top: 10px;
  border-top: 0.5px solid var(--ia-border);
  font-size: 12px; color: var(--ia-text-muted);
}
.appt-b-when-resource .who { color: var(--ia-text); font-weight: 500; }
.appt-b-when-resource .swatch {
  display: inline-block; width: 8px; height: 8px;
  border-radius: 50%; margin-right: 6px; vertical-align: 1px;
}

/* Vertical status pipeline — overrides the horizontal one when wrapped in .appt-b-rail */
.appt-b-rail .appt-progress-card { padding: 14px 16px; }
.appt-b-rail .appt-progress-bar {
  flex-direction: column;
  align-items: stretch;
  gap: 0;
}
.appt-b-rail .appt-progress-bar::before,
.appt-b-rail .appt-progress-bar::after { display: none; }
.appt-b-rail .appt-progress-step {
  flex-direction: row;
  justify-content: flex-start;
  gap: 12px;
  padding: 8px 0;
  text-align: left;
  position: relative;
}
.appt-b-rail .appt-progress-step:not(:last-child)::after {
  content: ''; position: absolute;
  left: 11.25px; top: calc(50% + 12px); bottom: -8px;
  width: 1.5px; background: var(--ia-border);
  z-index: 0;
}
.appt-b-rail .appt-progress-step.is-done:not(:last-child)::after { background: var(--ia-accent); }
.appt-b-rail .appt-progress-step.is-done + .appt-progress-step.is-current::after,
.appt-b-rail .appt-progress-step.is-done + .appt-progress-step.is-done::after { background: var(--ia-accent); }
.appt-b-rail .appt-progress-dot { flex-shrink: 0; }
.appt-b-rail .appt-progress-label {
  font-size: 13px; line-height: 1.3;
}

/* Action button stack */
.appt-b-actions {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 8px;
  display: flex; flex-direction: column; gap: 4px;
}
.appt-b-actions .ia-btn { width: 100%; justify-content: flex-start; padding: 8px 12px; font-size: 13px; }
.appt-b-actions-divider { height: 0.5px; background: var(--ia-border); margin: 4px 4px; }
.appt-b-action-coming-soon {
  font-size: 11px; color: var(--ia-text-muted);
  padding: 0 12px 6px; opacity: .55;
}

/* Rail customer card — slightly tighter than the original */
.appt-b-rail .ia-card { padding: 14px 16px; }
.appt-b-rail .appt-section-label { margin-bottom: 8px; }

/* Time/date inline meta on hero (re-shows at bottom of band) */
.appt-b-meta-grid {
  display: grid; grid-template-columns: auto 1fr; gap: 4px 10px;
  font-size: 12px; color: var(--ia-text-muted);
  margin-top: 10px;
}
.appt-b-meta-grid .lbl { opacity: .65; }

/* Inline capacity-in-work-order grid (3-up) */
.appt-b-wo-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
  margin-top: 8px;
}
.appt-b-wo-cell .lbl { font-size: 11px; color: var(--ia-text-muted); margin-bottom: 4px; }
.appt-b-wo-cell .val { font-size: 13px; }

/* Hide the original right-rail cancel button when in B layout — JS still finds it,
   but visually the new rail action handles it. */
.appt-b-shell .appt-cancel-btn-original { display: none !important; }


/* LAYOUT-B-CUST-MODAL-CSS v1 */
.appt-b-cust-modal {
  position: fixed; inset: 0; z-index: 1000;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.appt-b-cust-modal[hidden] { display: none; }
.appt-b-cust-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,.55);
  backdrop-filter: blur(4px);
}
.appt-b-cust-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg, 12px);
  width: 100%; max-width: 560px;
  max-height: 80vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.appt-b-cust-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.appt-b-cust-modal-title {
  margin: 0;
  font-size: 15px; font-weight: 500; letter-spacing: -.01em;
}
.appt-b-cust-modal-close {
  background: none; border: none; color: inherit;
  font-size: 22px; line-height: 1; cursor: pointer;
  padding: 4px 8px; border-radius: 4px;
  opacity: .6;
}
.appt-b-cust-modal-close:hover { opacity: 1; background: rgba(255,255,255,.06); }
.appt-b-cust-modal-body {
  padding: 18px 20px;
  overflow-y: auto;
}

/* Capacity collapsible — hide default disclosure triangle */
.appt-b-cap-override summary { list-style: none; }
.appt-b-cap-override summary::-webkit-details-marker { display: none; }
.appt-b-cap-override[open] summary { color: var(--ia-text-muted); }

/* RESCHEDULE-MODAL-CSS v1 */
.resch-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.resch-modal[hidden] { display: none; }
.resch-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); }
.resch-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg, 12px);
  width: 100%; max-width: 520px;
  max-height: 86vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.resch-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.resch-modal-title { margin: 0; font-size: 15px; font-weight: 500; letter-spacing: -.01em; }
.resch-modal-close {
  background: none; border: none; color: inherit;
  font-size: 22px; line-height: 1; cursor: pointer;
  padding: 4px 8px; border-radius: 4px; opacity: .6;
}
.resch-modal-close:hover { opacity: 1; background: rgba(255,255,255,.06); }
.resch-modal-body { padding: 18px 20px; overflow-y: auto; }
.resch-modal-foot {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 14px 20px;
  border-top: 0.5px solid var(--ia-border);
}

.resch-from {
  background: var(--ia-surface-2, rgba(255,255,255,.03));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 8px);
  padding: 12px 14px;
  margin-bottom: 16px;
}
.resch-from-label, .resch-to-label {
  font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
  font-weight: 500; opacity: .5; margin-bottom: 6px;
}
.resch-from-when, .resch-to-when { font-size: 14px; font-weight: 500; }
.resch-from-resource, .resch-to-resource {
  font-size: 12px; opacity: .7; margin-top: 4px;
  display: flex; align-items: center; gap: 6px;
}
.resch-swatch { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }

.resch-to {
  background: rgba(190,242,100,.07);
  border: 1px solid rgba(190,242,100,.25);
  border-radius: var(--ia-r-md, 8px);
  padding: 12px 14px;
  margin-top: 12px;
}
.resch-to-label { color: var(--ia-accent); opacity: 1; }

.resch-field { margin-bottom: 14px; }
.resch-label {
  display: block; font-size: 12px; opacity: .7;
  margin-bottom: 6px;
}
.resch-input {
  width: 100%; padding: 9px 12px;
  background: var(--ia-surface-2, rgba(255,255,255,.03));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 6px);
  color: inherit; font-family: inherit; font-size: 13px;
}

.resch-times-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.resch-week-nav { display: flex; align-items: center; gap: 4px; }
.resch-week-btn {
  background: transparent; border: 0.5px solid var(--ia-border);
  color: inherit; cursor: pointer;
  width: 24px; height: 24px; border-radius: 4px;
  font-size: 14px; line-height: 1; padding: 0;
}
.resch-week-btn:hover:not(:disabled) { background: rgba(255,255,255,.04); }
.resch-week-btn:disabled { opacity: .3; cursor: not-allowed; }
.resch-week-label { font-size: 12px; opacity: .7; min-width: 110px; text-align: center; }

.resch-times-list {
  max-height: 240px; overflow-y: auto;
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 6px);
}
.resch-times-empty { padding: 24px 16px; text-align: center; font-size: 12px; opacity: .5; }
.resch-times-empty.error { color: #f59999; }
.resch-time-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  cursor: pointer;
  font-size: 13px;
  transition: background .1s;
}
.resch-time-row:last-child { border-bottom: none; }
.resch-time-row:hover { background: rgba(255,255,255,.04); }
.resch-time-row.selected {
  background: rgba(190,242,100,.1);
  color: var(--ia-accent);
  font-weight: 500;
}
.resch-time-date { opacity: .7; }
.resch-time-time { font-variant-numeric: tabular-nums; }

/* CANCEL-RED-DARK v1 — rail Cancel needs visible red on dark surface */
.appt-b-cancel-btn.ia-btn--danger {
  background: #6B1F1F;
  color: #FFD0D0;
  border: 1px solid #8C2C2C;
}
.appt-b-cancel-btn.ia-btn--danger:hover {
  background: #8C2C2C;
  color: #FFE5E5;
}

/* APPT-MOBILE-OVERFLOW-FIX v1 — keep the page from exceeding viewport width.
   The status pipeline's `min-width: max-content` rule in the block below
   makes the bar take its content width. Without these constraints, that
   width propagates up through .appt-progress-card → .appt-b-rail →
   .appt-b-shell → page, causing horizontal scroll. We constrain the chain
   so the bar can scroll horizontally inside its card while the card stays
   inside the rail stays inside the page. */
@media (max-width: 900px) {
  .appt-b-shell, .appt-b-rail, .appt-b-main { max-width: 100%; min-width: 0; }
  .appt-b-rail > * { max-width: 100%; min-width: 0; }
  .appt-b-rail .appt-progress-card {
    max-width: 100%;
    min-width: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  /* Body block too — services/work order/payment cards shouldn't push width */
  .appt-b-main > * { max-width: 100%; min-width: 0; overflow-x: hidden; }
}

/* APPT-DETAIL-MOBILE v1 — phone polish at ≤700px */
@media (max-width: 700px) {

  /* Tighten the hero band on phones */
  .appt-b-when {
    padding: 12px 14px;
  }
  .appt-b-when-time {
    font-size: 20px;
  }

  /* ── Status pipeline: vertical → horizontal pill chain ── */
  .appt-b-rail .appt-progress-card {
    padding: 10px 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .appt-b-rail .appt-progress-bar {
    flex-direction: row !important;
    gap: 6px;
    align-items: center;
    min-width: max-content;
  }
  .appt-b-rail .appt-progress-step {
    flex-direction: row !important;
    padding: 4px 10px !important;
    border-radius: 99px;
    background: var(--ia-surface-2, rgba(255,255,255,.04));
    border: 0.5px solid var(--ia-border);
    flex-shrink: 0;
    gap: 6px;
  }
  .appt-b-rail .appt-progress-step::after {
    display: none !important;  /* no connecting line in horizontal mode */
  }
  .appt-b-rail .appt-progress-step.is-done {
    background: var(--ia-accent-soft);
    border-color: rgba(190,242,100,.3);
    color: var(--ia-accent);
  }
  .appt-b-rail .appt-progress-step.is-current {
    background: var(--ia-accent);
    border-color: var(--ia-accent);
    color: var(--ia-accent-text);
    font-weight: 600;
  }
  .appt-b-rail .appt-progress-dot {
    width: 12px; height: 12px;
  }
  .appt-b-rail .appt-progress-label {
    font-size: 12px;
    white-space: nowrap;
  }

  /* ── Action stack: vertical → 2-col grid ── */
  .appt-b-actions {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    padding: 6px;
  }
  .appt-b-actions .ia-btn {
    width: 100%;
    justify-content: center !important;
    padding: 10px 8px !important;
    font-size: 13px;
  }
  /* The "Reschedule shipping tomorrow" hint is no longer present, but keep
     the rule defensive for any future inline hint row. */
  .appt-b-action-coming-soon { display: none; }
  /* Divider spans full row */
  .appt-b-actions-divider { grid-column: 1 / -1; }
  /* Cancel button spans full row */
  .appt-b-cancel-btn { grid-column: 1 / -1; }

  /* ── Reschedule modal → bottom sheet ── */
  .resch-modal {
    align-items: flex-end !important;
    padding: 0 !important;
  }
  .resch-modal-card {
    max-width: 100% !important;
    width: 100%;
    border-top-left-radius: 18px;
    border-top-right-radius: 18px;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    max-height: 88vh;
    padding-bottom: env(safe-area-inset-bottom, 0);
    animation: appt-sheet-up 280ms cubic-bezier(.2, .8, .2, 1);
  }
  /* Drag handle */
  .resch-modal-card::before {
    content: '';
    display: block;
    width: 36px;
    height: 4px;
    background: var(--ia-text-dim, rgba(255,255,255,.18));
    border-radius: 2px;
    margin: 10px auto 0;
  }
  .resch-modal-head { padding-top: 10px; }
  .resch-modal-foot {
    flex-wrap: wrap;
    gap: 6px;
  }
  .resch-modal-foot .ia-btn { flex: 1; min-width: 0; }

  /* ── Booking-notes modal → bottom sheet ── */
  .appt-b-cust-modal {
    align-items: flex-end !important;
    padding: 0 !important;
  }
  .appt-b-cust-modal-card {
    max-width: 100% !important;
    width: 100%;
    border-top-left-radius: 18px;
    border-top-right-radius: 18px;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    max-height: 88vh;
    padding-bottom: env(safe-area-inset-bottom, 0);
    animation: appt-sheet-up 280ms cubic-bezier(.2, .8, .2, 1);
  }
  .appt-b-cust-modal-card::before {
    content: '';
    display: block;
    width: 36px;
    height: 4px;
    background: var(--ia-text-dim, rgba(255,255,255,.18));
    border-radius: 2px;
    margin: 10px auto 0;
  }
  .appt-b-cust-modal-head { padding-top: 10px; }
}

/* APPT-MOBILE-FIX v1 — duplicate back button + FAB padding */
@media (max-width: 700px) {
  /* The mobile top-bar already shows "‹ Schedule"; hide the page-head Back. */
  .ia-page-actions .ia-btn--ghost { display: none; }
  /* So .ia-page-actions doesn't render as an empty box, hide it when empty. */
  .ia-page-actions:empty { display: none; }
}
@media (max-width: 1023px) {
  /* Push content above the FAB so the last card isn't covered. */
  .ia-content { padding-bottom: calc(160px + env(safe-area-inset-bottom, 0px)) !important; }
}

@keyframes appt-sheet-up {
  from { transform: translateY(100%); }
  to   { transform: translateY(0); }
}
</style>

@section('mobile-back', 'Schedule|' . route('tenant.calendar.index'))
@section('mobile-fab', 'walk-in')

@section('content')

@php
  // Banner state — drives the top-of-page status banner.
  // Three cases: open draft sale (amber, take payment), paid (green),
  // overage (amber warning, refund customer). Anything else = no banner.
  $bannerPendingLink = $appointment->pendingPaymentLinkSale(); // MARKER-PATCH-194
  $bannerSale     = $appointment->openRegisterSale();
  $bannerBalance  = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
  $bannerOverage  = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
  $bannerPaidFull = ($appointment->payment_status === 'paid');
@endphp

@if($bannerPendingLink)
  {{-- MARKER-PATCH-194 — a payment link is out and awaiting the customer. --}}
  <div style="background:rgba(96,165,250,.10);border:0.5px solid rgba(96,165,250,.35);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">🔗</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Payment link sent — awaiting customer · {{ format_money($bannerPendingLink->total_cents) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        Sale {{ $bannerPendingLink->sale_number ?? 'pending' }} · the customer can pay on their own time; this updates automatically when they do.
      </div>
    </div>
    <a href="{{ route('tenant.register.index', []) }}?status={{ $bannerPendingLink->id }}"
       class="ia-btn ia-btn--ghost ia-btn--sm">View status →</a>
  </div>
@elseif($bannerSale)
  <div style="background:rgba(251,191,36,.10);border:0.5px solid rgba(251,191,36,.35);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">💳</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Ready for checkout — {{ format_money($bannerBalance) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        Sale {{ $bannerSale->sale_number }} parked in the register for {{ $appointment->customerName() }}.
      </div>
    </div>
    <a href="{{ route('tenant.register.index', []) }}?resume={{ $bannerSale->id }}"
       class="ia-btn ia-btn--primary ia-btn--sm">Open in register →</a>
  </div>
@elseif($bannerPaidFull)
  <div style="background:rgba(132,204,22,.08);border:0.5px solid rgba(132,204,22,.30);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">✅</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Paid in full — {{ format_money($appointment->paid_cents) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        @if($appointment->payments()->count() === 1 && $appointment->payments()->first()->kind === 'deposit')
          Customer prepaid before service. No checkout needed.
        @else
          {{ $appointment->payments()->count() }} {{ $appointment->payments()->count() === 1 ? 'payment' : 'payments' }} on file.
        @endif
      </div>
    </div>
  </div>
@elseif($bannerOverage > 0)
  <div style="background:rgba(251,191,36,.10);border:0.5px solid rgba(251,191,36,.45);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">⚠️</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Customer owed {{ format_money($bannerOverage) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        Customer paid more than the final total. Refund the difference through the register.
      </div>
    </div>
  </div>
@endif

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">
      Work order
    </div>
    <h1 class="ia-page-title">{{ $appointment->ra_number }}</h1>
    <p class="ia-page-subtitle">
      {{ $appointment->customerName() }} ·
      {{ $appointment->appointment_date->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
  </div>
</div>

@php
  // Status progress bar — terminal states (cancelled/refunded) replace the bar with a card.
  $isTerminal = \App\Support\AppointmentStatus::isTerminal($appointment->status);
  $pipelineSteps = \App\Support\AppointmentStatus::pipeline();
  // TODO: per-tenant extensions for 'shipped' and 'closed' once Workflow settings ship.
  $currentIndex = array_search($appointment->status, $pipelineSteps);
  if ($currentIndex === false) $currentIndex = 0;
@endphp

{{-- LAYOUT-B-PIPELINE-RELOCATED v1: original full-width status pipeline removed.
     The rail (above) renders the same markup with the same JS hooks. --}}

<div class="appt-b-shell">

  {{-- LAYOUT-B-RAIL v1 — left rail: time/date, status, actions, customer --}}
  <aside class="appt-b-rail">

    {{-- Time/date hero band --}}
    @php
      try {
        $apptStartC = $appointment->appointment_time
          ? \Carbon\Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time)
          : null;
        $durationMin = (int) ($appointment->total_duration_minutes ?? 0);
        $apptEndC   = ($apptStartC && $durationMin > 0) ? $apptStartC->copy()->addMinutes($durationMin) : null;
      } catch (\Throwable $e) {
        $apptStartC = null; $apptEndC = null; $durationMin = 0;
      }
    @endphp
    @if($apptStartC)
      <div class="appt-b-when">
        <div class="appt-b-when-time">
          {{ $apptStartC->format('g:i A') }}@if($apptEndC) – {{ $apptEndC->format('g:i A') }}@endif
        </div>
        <div class="appt-b-when-date">
          {{ $appointment->appointment_date->format('l, M j, Y') }}
        </div>
        @if($durationMin > 0)
          <div class="appt-b-when-dur">{{ $durationMin }} min</div>
        @endif
        @if($currentResource ?? null)
          <div class="appt-b-when-resource">
            <span class="swatch" style="background: {{ ($availableResources->firstWhere('id', $appointment->resource_id))->color_hex ?? '#888' }}"></span>
            <span class="who">{{ ($availableResources->firstWhere('id', $appointment->resource_id))->name ?? 'Unassigned' }}</span>
          </div>
        @else
          @php $rr = $availableResources->firstWhere('id', $appointment->resource_id); @endphp
          @if($rr)
            <div class="appt-b-when-resource">
              <span class="swatch" style="background: {{ $rr->color_hex ?? '#888' }}"></span>
              <span class="who">{{ $rr->name }}</span>
            </div>
          @endif
        @endif
      </div>
    @else
      <div class="appt-b-when">
        <div class="appt-b-when-time" style="font-size:15px;font-weight:500">
          {{ $appointment->appointment_date->format('l, M j, Y') }}
        </div>
        <div class="appt-b-when-dur">No time set</div>
      </div>
    @endif
    {{-- MARKER-PATCH-311 --}}
    <div style="margin-top:10px">@include('tenant.appointments._promised_editor')</div>
    @include('tenant.appointments._delivery_propose_modal'){{-- MARKER-PATCH-527 --}}
    {{-- MARKER-PATCH-514 --}}
    @include('tenant.appointments._route_trip')

    {{-- Status pipeline (markup is fed into vertical CSS by .appt-b-rail wrapper) --}}
    @if($isTerminal)
      {{-- Terminal state — show compact card --}}
      <div class="ia-card appt-terminal-card" style="padding:12px 14px">
        <div class="appt-terminal-icon appt-terminal-icon--{{ $appointment->status }}">
          @if($appointment->status === 'cancelled')
            <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          @else
            <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2 5h6M5 2v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          @endif
        </div>
        <div>
          <div class="appt-terminal-title" style="font-size:13px">{{ $statusLabels[$appointment->status] }}</div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-reopen-btn" data-status="pending" style="margin-left:auto">
          Reopen
        </button>
      </div>
    @else
      <div class="ia-card appt-progress-card">
        <div class="appt-progress-bar" data-current-index="{{ $currentIndex }}" data-update-url="{{ $updateUrl }}">
          @foreach($pipelineSteps as $idx => $step)
            @php
              $stepLabel = $statusLabels[$step];
              $isDone    = $idx < $currentIndex;
              $isCurrent = $idx === $currentIndex;
            @endphp
            <button type="button"
                    class="appt-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                    data-status="{{ $step }}"
                    data-step-index="{{ $idx }}"
                    data-label="{{ $stepLabel }}">
              <span class="appt-progress-dot">
                @if($isDone)
                  <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif($isCurrent)
                  <span class="appt-progress-dot-inner"></span>
                @endif
              </span>
              <span class="appt-progress-label">{{ $stepLabel }}</span>
            </button>
          @endforeach
        </div>
      </div>
    @endif

    {{-- Action stack --}}
    @unless($isTerminal)
    <div class="appt-b-actions">
      {{-- MARKER-PATCH-313 --}}
      {{-- MARKER-PATCH-315 — gated on the tag enable toggle --}}
      @if(data_get(tenant()->settings, 'work_order_tag.enabled', true))
      <button type="button" class="ia-btn ia-btn--secondary" onclick="openTagModal()">&#9113; Print tag</button>
      @endif
      <div class="appt-b-actions-divider"></div>
      {{-- MARKER-PATCH-285 — removed stray "Reschedule shipping tomorrow" cruft --}}
      <button type="button" class="ia-btn ia-btn--secondary appt-b-reschedule-btn">↻ Reschedule</button>
      <div class="appt-b-actions-divider"></div>
      <button type="button" class="ia-btn ia-btn--danger appt-b-cancel-btn">Cancel appointment</button>
    </div>
    @endunless

    {{-- Customer card --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Customer</div>
      <div style="font-weight:500;margin-bottom:4px">
        {{ $appointment->customerName() }}
        {{-- MARKER-BIZ-WORKORDER — business context where the job is done --}}
        @if($appointment->customer && $appointment->customer->isBusiness())
          <span class="biz-pill">Business</span>
        @endif
      </div>
      @if($appointment->customer && $appointment->customer->isBusiness())
        @php $bizPrimary = $appointment->customer->primaryContact; @endphp
        @if($bizPrimary)
          <div style="font-size:13px;opacity:.75;margin-bottom:2px">
            {{ $bizPrimary->name }}@if($bizPrimary->role) · {{ $bizPrimary->role }}@endif
            @if($bizPrimary->phone) · {{ $bizPrimary->phone }}@endif
          </div>
        @endif
        <div style="font-size:12.5px;opacity:.6;margin-bottom:6px">
          {{ $appointment->customer->termsLabel() }}
          @if($appointment->customer->tax_exempt)
            · Tax exempt@if($appointment->customer->tax_exempt_certificate) (cert {{ $appointment->customer->tax_exempt_certificate }})@endif
          @endif
          @if($appointment->customer->po_required) · <span style="color:#E8A33D">PO required</span>@endif
        </div>
      @endif
      <div style="font-size:13px;opacity:.6;margin-bottom:2px">
        {{ $appointment->customer_email }}
      </div>
      @if($appointment->customer_phone)
        <div style="font-size:13px;opacity:.6;margin-bottom:10px">
          {{ $appointment->customer_phone }}
        </div>
      @else
        <div style="margin-bottom:10px"></div>
      @endif
      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
        @if($appointment->responses->isNotEmpty())
          <button type="button"
                  class="ia-btn ia-btn--primary ia-btn--sm appt-b-cust-details-btn"
                  style="width:100%;justify-content:center;margin-top:6px">
            View booking notes →
          </button>
        @endif
      @endif
    </div>

    {{-- Resource — change which staff member or station owns this appointment.
         Soft-warns on conflicts with an override path. Auto-notes on change. --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 20 --}}
    <div class="ia-card ia-card--tight" data-appt-resource-card data-appt-id="{{ $appointment->id }}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Resource
      </div>

      @php
        $currentResourceId = $appointment->resource_id;
        $currentResource   = $availableResources->firstWhere('id', $currentResourceId);
      @endphp

      <div class="sidebar-stat" style="border-bottom:none;padding-bottom:4px">
        <span class="sidebar-stat-label">Currently assigned</span>
        <span class="sidebar-stat-value" style="display:flex;align-items:center;gap:6px">
          @if($currentResource)
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $currentResource->color_hex ?: '#888' }}"></span>
            {{ $currentResource->name }}
          @else
            <span style="opacity:.5">Unassigned</span>
          @endif
        </span>
      </div>

      <label class="ia-form-label" style="margin-top:12px">Change to</label>
      <select class="ia-input" data-appt-resource-select style="margin-bottom:8px">
        @foreach($availableResources as $r)
          <option value="{{ $r->id }}" @selected($r->id === $currentResourceId)>
            {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
          </option>
        @endforeach
      </select>
      <button type="button"
              class="ia-btn ia-btn--ghost"
              data-appt-resource-save
              style="width:100%">Save resource</button>
      <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
        If the new resource is busy at this time, you'll get a warning before the change is saved.
      </p>
    </div>

    {{-- Capacity slots · LAYOUT-B-RAIL v1 (collapsible override) --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:10px">
        Capacity slots
      </div>
      <div class="sidebar-stat" style="margin-bottom:8px">
        <span class="sidebar-stat-label">Auto-calculated</span>
        <span class="sidebar-stat-value">{{ $appointment->slot_weight_auto ?? 1 }}</span>
      </div>
      @if($appointment->slot_weight_overridden)
      <div class="sidebar-stat" style="margin-bottom:4px">
        <span class="sidebar-stat-label" style="color:#EF9F27">Overridden</span>
        <span class="sidebar-stat-value" style="color:#EF9F27">{{ $appointment->slot_weight }}</span>
      </div>
      @endif
      <details class="appt-b-cap-override" style="margin-top:8px">
        <summary style="cursor:pointer;font-size:12px;color:var(--ia-accent);padding:4px 0;list-style:none">
          Override slot weight ▾
        </summary>
        <div data-appt-slot-weight-card style="margin-top:10px">
          <input type="hidden" data-appt-slot-weight-current value="{{ (int) ($appointment->slot_weight ?? 1) }}">
          <select class="ia-input" data-appt-slot-weight-select style="margin-bottom:8px">
            @foreach([1,2,3,4] as $w)
              <option value="{{ $w }}" @selected($appointment->slot_weight == $w)>
                {{ $w }} slot{{ $w > 1 ? 's' : '' }}
                @if($w == 1) — normal job
                @elseif($w == 2) — bigger job
                @elseif($w == 3) — large job
                @elseif($w == 4) — full day job
                @endif
              </option>
            @endforeach
          </select>
          <button type="button"
                  class="ia-btn ia-btn--ghost"
                  data-appt-slot-weight-save
                  data-appt-id="{{ $appointment->id }}"
                  style="width:100%">Save slot weight</button>
          <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
            Override how many capacity slots this appointment occupies.
          </p>
        </div>
      </details>
    </div>

  </aside>

  {{-- Main column starts here (existing content unchanged) --}}
  <div class="appt-b-main" style="display:flex;flex-direction:column;gap:20px">

    {{-- Line items · LAYOUT-B-PROMOTE-ORDER 10 --}}
    <div class="ia-card" style="order:10">
      <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Services</span>
        @if($bannerSale)
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm void-sale-btn" style="font-size:11px;padding:4px 10px" data-context="services">
            <span style="opacity:.6">🔒</span> Edit (voids draft)
          </button>
        @endif
      </div>

      <table class="appt-line-items" id="line-items-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="ia-num">Duration</th>
            <th class="ia-num">Price</th>
            <th style="width:32px"></th>
          </tr>
        </thead>
        <tbody id="line-items-body">
          @foreach($appointment->items as $item)
            <tr class="line-row" data-kind="service" data-item-id="{{ $item->id }}">
              <td style="font-weight:500">{{ $item->item_name_snapshot }}</td>
              <td class="ia-num">
                <input type="number" min="0" class="line-edit ia-input ia-input--sm"
                  data-field="duration_minutes"
                  value="{{ $item->duration_minutes_override ?? $item->duration_minutes_snapshot ?? 0 }}"
                  style="width:70px;text-align:right"> <span style="opacity:.5">min</span>
              </td>
              <td class="ia-num">
                <input type="number" min="0" step="0.01" class="line-edit ia-input ia-input--sm"
                  data-field="price_dollars"
                  value="{{ number_format(($item->price_cents_override ?? $item->price_cents) / 100, 2, '.', '') }}"
                  style="width:80px;text-align:right">
              </td>
              <td>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm line-remove" title="Remove">&#x2715;</button>
              </td>
            </tr>
          @endforeach
          @foreach($appointment->addons as $addon)
            <tr class="line-row" data-kind="addon" data-item-id="{{ $addon->id }}">
              <td style="opacity:.7">+ {{ $addon->addon_name_snapshot }}</td>
              <td class="ia-num">
                <input type="number" min="0" class="line-edit ia-input ia-input--sm"
                  data-field="duration_minutes"
                  value="{{ $addon->duration_minutes_override ?? $addon->duration_minutes_snapshot ?? 0 }}"
                  style="width:70px;text-align:right"> <span style="opacity:.5">min</span>
              </td>
              <td class="ia-num">
                <input type="number" min="0" step="0.01" class="line-edit ia-input ia-input--sm"
                  data-field="price_dollars"
                  value="{{ number_format(($addon->price_cents_override ?? $addon->price_cents) / 100, 2, '.', '') }}"
                  style="width:80px;text-align:right">
              </td>
              <td>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm line-remove" title="Remove">&#x2715;</button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if($appointment->items->isEmpty() && $appointment->addons->isEmpty())
        <p style="font-size:13px;opacity:.4;margin:8px 0 12px">No items yet — add one below.</p>
      @endif

      <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:0.5px solid var(--ia-border);align-items:center">
        <select id="add-line-select" class="ia-input ia-input--sm" style="flex:1">
          <option value="">+ Add service or add-on…</option>
          <optgroup label="Services">
            @foreach($availableServices as $svc)
              <option value="service:{{ $svc->id }}">{{ $svc->name }} · {{ format_money($svc->price_cents) }}</option>
            @endforeach
          </optgroup>
          <optgroup label="Add-ons">
            @foreach($availableAddons as $ad)
              <option value="addon:{{ $ad->id }}">+ {{ $ad->name }} · {{ format_money($ad->price_cents) }}</option>
            @endforeach
          </optgroup>
        </select>
        <button type="button" id="add-line-btn" class="ia-btn ia-btn--secondary ia-btn--sm">Add</button>
      </div>

      <div class="appt-total-row" style="margin-top:14px">
        <span>Subtotal</span>
        <span>{{ format_money($appointment->subtotal_cents) }}</span>
      </div>
    </div>

    {{-- ===================================================================
         Products & Add-ons (physical inventory and custom items consumed
         during the appointment)
         =================================================================== --}}
    @php
      $isCommittedStatus = \App\Support\AppointmentStatus::isDone($appointment->status);
    @endphp
    {{-- LAYOUT-B-PROMOTE-ORDER 40 --}}
    <div class="ia-card" id="parts-card" style="order:40">
      <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <span>Products & Add-ons</span>
        <div style="display:flex;align-items:center;gap:8px">
          @if($isCommittedStatus)
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:2px 8px;border-radius:99px;background:var(--ia-surface-2)">Committed</span>
          @endif
          @if($bannerSale)
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm void-sale-btn" style="font-size:11px;padding:4px 10px" data-context="parts">
              <span style="opacity:.6">🔒</span> Edit (voids draft)
            </button>
          @endif
        </div>
      </div>

      <table class="appt-line-items" id="parts-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="ia-num" style="width:90px">Qty</th>
            <th class="ia-num" style="width:90px">Price</th>
            <th class="ia-num" style="width:90px">Total</th>
            <th style="width:32px"></th>
          </tr>
        </thead>
        <tbody id="parts-body">
          @foreach($appointment->parts as $part)
            @php
              $invItem = $part->inventoryItem;
              $stockNow = $invItem ? (int) ($invItem->computed_stock_count ?? 0) : null;
              $stockProjected = ($stockNow !== null && !$part->isCommitted())
                ? $stockNow - (int) $part->quantity
                : null;
            @endphp
            <tr class="part-row" data-part-id="{{ $part->id }}" data-committed="{{ $part->isCommitted() ? '1' : '0' }}">
              <td>
                <div style="font-weight:500;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span>{{ $part->item_name_snapshot }}</span>
                  @if(!$part->inventory_item_id)
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:1px 7px;border-radius:99px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border)">Custom</span>
                  @endif
                </div>
                @if($part->item_sku_snapshot)
                  <div style="font-size:11px;opacity:.45;font-family:var(--ia-font-mono);margin-top:2px">{{ $part->item_sku_snapshot }}</div>
                @endif
                @if($stockNow !== null)
                  <div style="font-size:11px;opacity:.55;margin-top:3px">
                    @if($part->isCommitted())
                      Stock decremented · current: {{ $stockNow }}
                    @else
                      Stock: {{ $stockNow }} → {{ $stockProjected }} on completion
                    @endif
                  </div>
                @endif
              </td>
              <td class="ia-num">
                <input type="number" min="1" max="999"
                  class="part-qty-edit ia-input ia-input--sm"
                  value="{{ $part->quantity }}"
                  data-part-id="{{ $part->id }}"
                  {{ ($part->isCommitted() && $part->inventory_item_id) ? 'disabled' : '' }}
                  style="width:60px;text-align:right">
              </td>
              <td class="ia-num">{{ format_money($part->effectiveUnitPriceCents()) }}</td>
              <td class="ia-num" data-line-total>{{ format_money($part->lineTotalCents()) }}</td>
              <td>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm part-remove" data-part-id="{{ $part->id }}" title="Remove">&#x2715;</button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if($appointment->parts->isEmpty())
        <p style="font-size:13px;opacity:.4;margin:8px 0 12px">No products added yet.</p>
      @endif

      <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:0.5px solid var(--ia-border);align-items:center;position:relative">
        <input type="text" id="part-picker-input" class="ia-input ia-input--sm" placeholder="+ Add product from inventory or custom item…" style="flex:1" autocomplete="off">
        <div id="part-picker-results" style="display:none;position:absolute;top:100%;left:0;right:64px;margin-top:4px;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);max-height:280px;overflow-y:auto;z-index:20"></div>
      </div>

      {{-- Custom item inline form (shown when user clicks "+ Custom item" in the picker) --}}
      <div id="custom-item-form" style="display:none;margin-top:10px;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);background:var(--ia-surface-2)">
        <div style="font-size:12px;font-weight:500;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
          <span>Custom item</span>
          <button type="button" id="custom-item-cancel" class="ia-btn ia-btn--ghost ia-btn--sm" style="padding:2px 8px;font-size:11px">Cancel</button>
        </div>
        <div style="display:grid;grid-template-columns:1.6fr 0.7fr 0.5fr auto;gap:8px;align-items:end">
          <div>
            <label class="ia-form-label" style="font-size:11px;margin-bottom:4px">Name</label>
            <input type="text" id="custom-item-name" class="ia-input ia-input--sm" maxlength="255" placeholder="e.g. Scratched paint touch-up" style="width:100%">
          </div>
          <div>
            <label class="ia-form-label" style="font-size:11px;margin-bottom:4px">Price</label>
            <input type="number" id="custom-item-price" class="ia-input ia-input--sm" min="0" step="0.01" placeholder="0.00" style="width:100%;text-align:right">
          </div>
          <div>
            <label class="ia-form-label" style="font-size:11px;margin-bottom:4px">Qty</label>
            <input type="number" id="custom-item-qty" class="ia-input ia-input--sm" min="1" max="999" value="1" style="width:100%;text-align:right">
          </div>
          <div>
            <button type="button" id="custom-item-add" class="ia-btn ia-btn--primary ia-btn--sm">Add</button>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:6px;margin-top:10px;font-size:11px;cursor:pointer;opacity:.75">
          <input type="checkbox" id="custom-item-taxable" checked> Taxable
        </label>
      </div>
    </div>
    {{-- ================================================================== --}}

    {{-- Work order (staff-filled equipment details) --}}
    @if($appointment->workOrderFields && $appointment->workOrderFields->isNotEmpty())
    @php
      $responsesByFieldId = $appointment->workOrderResponses->keyBy('field_id');
      $identifierField = $appointment->workOrderFields->firstWhere('is_identifier', true);
      $identifierValue = $identifierField ? ($responsesByFieldId[$identifierField->id]->response_value ?? null) : null;
      $nonIdentifierFields = $appointment->workOrderFields->filter(fn($f) => !$f->is_identifier);
    @endphp
    {{-- LAYOUT-B-PROMOTE-ORDER 50 --}}
    {{-- ════════════════════════════════════════════════════════════
         Special Orders integration (added by patch 88, Stage 5)
         Parts on order via SO. Distinct from in-stock parts above.
         Includes soft completion-block warning when appointment is
         in_progress and SOs aren't yet pulled.
         ════════════════════════════════════════════════════════════ --}}
    @isset($specialOrdersForAppt)
      @php
        $openAppointmentSos = $specialOrdersForAppt->whereIn('status', ['needed', 'ordered', 'arrived']);
        $unArrivedSos = $specialOrdersForAppt->whereIn('status', ['needed', 'ordered']);
        $showBlockWarning = $appointment->status === 'in_progress' && $unArrivedSos->isNotEmpty();
      @endphp

      <div class="ia-card" id="so-parts-card" style="order:45;{{ $showBlockWarning ? 'border-left:3px solid #F59E0B;' : '' }}">
        <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
          <span>Special-order parts</span>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                  onclick='SoDrawer.open({customer_id: @json($appointment->customer_id), customer_label: @json($appointment->customerName()), appointment_id: @json($appointment->id), alloc_mode: "customer_appt"})'>
            + SO for this appointment
          </button>
        </div>

        @if($showBlockWarning)
          <div style="background:rgba(245,158,11,0.08);border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:12.5px">
            <strong style="color:#F59E0B">⚠ {{ $unArrivedSos->count() }} part{{ $unArrivedSos->count() === 1 ? '' : 's' }} not yet arrived.</strong>
            <span style="color:var(--ia-text-muted)">
              Completing this appointment will leave the customer waiting on parts. Consider waiting until parts arrive, or proceed if customer is OK with split pickup.
            </span>
          </div>
        @endif

        @if($specialOrdersForAppt->isEmpty())
          <p style="font-size:13px;color:var(--ia-text-muted);padding:6px 0;margin:0">No special-order parts on this appointment.</p>
        @else
          <table class="appt-line-items">
            <thead>
              <tr>
                <th>Part</th>
                <th class="ia-num" style="width:60px">Qty</th>
                <th style="width:120px">Status</th>
                <th style="width:90px">ETA</th>
                <th>Vendor</th>
                <th style="width:80px">SO #</th>
                <th style="width:70px"></th>{{-- MARKER-SO-APPT-CANCEL --}}
              </tr>
            </thead>
            <tbody>
              @foreach($specialOrdersForAppt as $so)
                @php
                  $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
                  $rowOpacity = in_array($so->status, ['pulled', 'cancelled']) ? '0.55' : '1';
                @endphp
                <tr style="cursor:pointer;opacity:{{ $rowOpacity }}" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                  <td>
                    <strong>{{ $so->item_name_snapshot }}</strong>
                  </td>
                  <td class="ia-num">{{ $so->quantity }}</td>
                  <td>
                    <span class="so-status so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
                  </td>
                  <td style="color:var(--ia-text-muted);font-size:12px">
                    @if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif
                  </td>
                  <td style="color:var(--ia-text-muted);font-size:12px">{{ $so->vendor?->name ?? 'TBD' }}</td>
                  <td style="font-size:11px;color:var(--ia-text-muted)">{{ $so->so_number }}</td>
                  {{-- MARKER-SO-APPT-CANCEL — a special order attached here had
                       no way off the work order: the only action was opening
                       it. A still-"needed" order can be retracted from the
                       row; placed ones cannot, since goods may be inbound. --}}
                  <td onclick="event.stopPropagation()" style="cursor:default;text-align:right">
                    @if($so->status === 'needed')
                      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                              data-appt-so-cancel="{{ $so->id }}" data-so-number="{{ $so->so_number }}">Cancel</button>
                    @elseif(in_array($so->status, ['ordered', 'arrived']))
                      <span style="font-size:11px;color:var(--ia-text-muted)">placed</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>

      @include('tenant.special-orders._drawer', ['vendors' => $soVendors ?? collect()])

      {{-- MARKER-SO-APPT-CANCEL — inside the section on purpose: a script
           placed after @endsection is silently discarded by Blade. --}}
      <script>
      (function () {
        var url  = @json(route('tenant.special-orders.cancel', ['id' => '__ID__']));
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

        document.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-appt-so-cancel]');
          if (!btn) return;
          e.preventDefault();
          e.stopPropagation();

          var num = btn.getAttribute('data-so-number') || 'this special order';
          if (!confirm('Cancel ' + num + '? The part is no longer needed for this work order.')) return;

          btn.disabled = true;
          fetch(url.replace('__ID__', btn.getAttribute('data-appt-so-cancel')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ reason: 'Cancelled from the work order.' }),
          })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (j && j.ok) {
                if (window.IntakeToast) IntakeToast.success(num + ' cancelled');
                window.location.reload();
              } else {
                btn.disabled = false;
                if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not cancel.');
              }
            })
            .catch(function () {
              btn.disabled = false;
              if (window.IntakeToast) IntakeToast.error('Network error.');
            });
        });
      })();
      </script>

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

        <div class="ia-card" id="work-order-card" style="order:50">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:0.5px solid var(--ia-border)">
        <div class="appt-section-label" style="margin-bottom:0">Work order</div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wo-edit-toggle">Edit</button>
      </div>

      {{-- Display mode --}}
      <div id="wo-display">
        @if($identifierField && $identifierValue)
          <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:0.5px solid var(--ia-border)">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:6px">
              {{ $identifierField->label }}
            </div>
            <div class="ia-mono" style="font-size:18px;font-weight:500;letter-spacing:.02em">
              {{ $identifierValue }}
            </div>
          </div>
        @endif

        @php
          $filledNonIdentifier = $nonIdentifierFields->filter(fn($f) => !empty($responsesByFieldId[$f->id]->response_value ?? null));
        @endphp

        @if($filledNonIdentifier->isEmpty() && (!$identifierField || !$identifierValue))
          <p style="font-size:13px;opacity:.4">No work order details recorded yet.</p>
        @elseif($filledNonIdentifier->isNotEmpty())
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px 32px">
            @foreach($filledNonIdentifier as $field)
              <div>
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:3px">
                  {{ $field->label }}
                </div>
                <div style="font-size:14px">{{ $responsesByFieldId[$field->id]->response_value }}</div>
              </div>
            @endforeach
          </div>
        @endif
      </div>

      {{-- Edit mode --}}
      <form id="wo-edit-form" style="display:none" method="POST" action="{{ $updateUrl }}">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="save_work_order">

        @foreach($appointment->workOrderFields as $field)
          @php $currentValue = $responsesByFieldId[$field->id]->response_value ?? ''; @endphp
          <div class="ia-form-group" style="margin-bottom:14px">
            <label class="ia-form-label">
              {{ $field->label }}
              @if($field->is_identifier)
                <span style="background:var(--ia-accent);color:var(--ia-accent-text);font-size:9px;font-weight:500;padding:1px 6px;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;margin-left:6px">ID</span>
              @endif
              @if($field->is_required)
                <span class="ia-required">*</span>
              @endif
            </label>
            @if($field->field_type === 'textarea')
              <textarea name="values[{{ $field->id }}]" class="ia-input" rows="3" @if($field->is_required) required @endif>{{ $currentValue }}</textarea>
            @elseif($field->field_type === 'number')
              <input type="number" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
            @elseif($field->field_type === 'select')
              <select name="values[{{ $field->id }}]" class="ia-input" @if($field->is_required) required @endif>
                <option value="">—</option>
                @foreach(($field->options ?? []) as $opt)
                  <option value="{{ $opt }}" @selected($currentValue === $opt)>{{ $opt }}</option>
                @endforeach
              </select>
            @else
              <input type="text" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
            @endif
            @if($field->help_text)
              <div style="font-size:11px;opacity:.5;margin-top:3px">{{ $field->help_text }}</div>
            @endif
          </div>
        @endforeach

        <div style="display:flex;gap:8px;margin-top:16px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wo-edit-cancel">Cancel</button>
        </div>
      </form>
    </div>
    @endif

    {{-- LAYOUT-B-CUSTDETAIL-MOVED v1: Booking notes (intake responses) now render in the modal at end of page --}}

    {{-- Charges --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 70 --}}
    <div class="ia-card" style="order:70">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div class="appt-section-label" style="margin-bottom:0">Additional charges</div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="add-charge-toggle">
          + Add charge
        </button>
      </div>

      <form method="POST" action="{{ $updateUrl }}" class="add-charge-form" id="add-charge-form">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="add_charge">
        <div class="ia-input-grid-2" style="margin-bottom:10px">
          <div class="ia-form-group" style="margin-bottom:0">
            <label class="ia-form-label">Description <span class="ia-required">*</span></label>
            <input type="text" name="description" class="ia-input" placeholder="e.g. New brake cable" required>
          </div>
          <div class="ia-form-group" style="margin-bottom:0">
            <label class="ia-form-label">Amount ($) <span class="ia-required">*</span></label>
            <input type="number" name="amount_display" class="ia-input" placeholder="25.00"
              step="0.01" min="0.01" id="charge-amount-display">
            <input type="hidden" name="amount_cents" id="charge-amount-cents">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save charge</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="add-charge-cancel">Cancel</button>
        </div>
      </form>

      @if($appointment->charges->isEmpty())
        <p style="font-size:13px;opacity:.4">No additional charges.</p>
      @else
        @foreach($appointment->charges as $charge)
          <div class="appt-charge-row">
            <div>
              <div style="font-size:13px">{{ $charge->description }}</div>
              <div style="font-size:11px;opacity:.4">
                {{ \Carbon\Carbon::parse($charge->created_at)->format('M j') }} ·
                {{ $charge->is_paid ? 'Paid' : 'Unpaid' }}
              </div>
            </div>
            <div style="font-weight:500">{{ format_money($charge->amount_cents) }}</div>
          </div>
        @endforeach

        <div class="appt-total-row">
          <span>Charges total</span>
          <span>{{ format_money($appointment->charges->sum('amount_cents')) }}</span>
        </div>
      @endif
    </div>

    {{-- Notes --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 90 --}}
    <div class="ia-card" style="order:90">
      <div class="appt-section-label">Notes</div>

      <div class="ia-note-add">
        <textarea id="note-input" rows="3" maxlength="500"
          data-maxlength="500" data-counter="note-chars"
          placeholder="Add a note…" style="width:100%;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);padding:8px 10px;font-size:13px;resize:none;font-family:var(--ia-font)"></textarea>
        <div class="ia-note-add-footer">
          <span class="ia-char-count" id="note-chars">500</span>
          <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="note-submit"
            data-url="{{ $updateUrl }}">
            Add note
          </button>
        </div>
        <p id="note-error" style="font-size:12px;color:#E24B4A;margin-top:4px;display:none"></p>
      </div>

      <div class="ia-notes" id="notes-list">
        @forelse($appointment->notes->sortByDesc('created_at') as $note)
          <div class="ia-note" data-note-id="{{ $note->id }}">
            <div class="ia-note-head">
              <span class="ia-note-author">
                {{ $note->user?->name ?? ($note->note_type === 'system' ? 'System' : 'Staff') }}
              </span>
              <span class="ia-note-time">
                {{ tlocal($note->created_at, 'M j, g:i a') }}{{-- MARKER-PATCH-532 --}}
              </span>
              @if($note->note_type !== 'system')
                <button type="button" class="ia-note-delete"
                  data-note-id="{{ $note->id }}"
                  title="Delete">&#x2715;</button>
              @endif
            </div>
            <div class="ia-note-body">{{ $note->note_content }}</div>
          </div>
        @empty
          <p class="ia-notes-empty" style="font-size:13px;opacity:.4">No notes yet.</p>
        @endforelse
      </div>
    </div>

    {{-- LAYOUT-B-PROMOTE v1 — moved-block unwrapped; cards are direct children of .appt-b-main and ordered via CSS order:N --}}

    {{-- Customer card · LAYOUT-B-PROMOTE-ORDER 9999 (hidden) --}}
    <div class="ia-card ia-card--tight" style="display:none;order:9999" aria-hidden="true">
      <div class="appt-section-label">Customer</div>
      <div style="font-weight:500;margin-bottom:4px">
        {{ $appointment->customerName() }}
        {{-- MARKER-BIZ-WORKORDER — business context where the job is done --}}
        @if($appointment->customer && $appointment->customer->isBusiness())
          <span class="biz-pill">Business</span>
        @endif
      </div>
      @if($appointment->customer && $appointment->customer->isBusiness())
        @php $bizPrimary = $appointment->customer->primaryContact; @endphp
        @if($bizPrimary)
          <div style="font-size:13px;opacity:.75;margin-bottom:2px">
            {{ $bizPrimary->name }}@if($bizPrimary->role) · {{ $bizPrimary->role }}@endif
            @if($bizPrimary->phone) · {{ $bizPrimary->phone }}@endif
          </div>
        @endif
        <div style="font-size:12.5px;opacity:.6;margin-bottom:6px">
          {{ $appointment->customer->termsLabel() }}
          @if($appointment->customer->tax_exempt)
            · Tax exempt@if($appointment->customer->tax_exempt_certificate) (cert {{ $appointment->customer->tax_exempt_certificate }})@endif
          @endif
          @if($appointment->customer->po_required) · <span style="color:#E8A33D">PO required</span>@endif
        </div>
      @endif
      <div style="font-size:13px;opacity:.6;margin-bottom:2px">
        {{ $appointment->customer_email }}
      </div>
      @if($appointment->customer_phone)
        <div style="font-size:13px;opacity:.6;margin-bottom:10px">
          {{ $appointment->customer_phone }}
        </div>
      @else
        <div style="margin-bottom:10px"></div>
      @endif
      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
      @endif
    </div>

    {{-- LAYOUT-B-RAIL-MOVE v1: Resource card moved to rail --}}

    {{-- LAYOUT-B-RAIL-MOVE v1: Capacity card moved to rail --}}

    {{-- Payment ledger · LAYOUT-B-PROMOTE-ORDER 80 (order applies to the wrapping ia-card div below) --}}
    @php
      $payments      = $appointment->payments;
      $balanceDue    = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
      $overage       = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
      $openSale      = $appointment->openRegisterSale();
      $hasOpenSale   = $openSale !== null;
    @endphp
    <div style="order:80" class="ia-card ia-card--tight">
      <div class="appt-section-label">Payment</div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Status</span>
        <span>
          <span class="ia-badge ia-badge--{{ $appointment->payment_status }}">
            {{ ucwords(str_replace('_', ' ', $appointment->payment_status)) }}
          </span>
        </span>
      </div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Subtotal</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->subtotal_cents) }}</span>
      </div>
      @if($appointment->tax_cents > 0)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Tax</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->tax_cents) }}</span>
      </div>
      @endif
      <div class="sidebar-stat">
        <span class="sidebar-stat-label" style="font-weight:500">Total</span>
        <span class="sidebar-stat-value" style="font-size:16px">{{ format_money($appointment->total_cents) }}</span>
      </div>

      @if($payments->isNotEmpty())
        <div style="margin-top:14px;padding-top:12px;border-top:0.5px solid var(--ia-border)">
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:8px">Payment ledger</div>
          @foreach($payments as $p)
            <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:12px;padding:6px 0;border-bottom:0.5px solid var(--ia-border)">
              <div style="flex:1;min-width:0">
                <div style="font-weight:500;color:var(--ia-text)">
                  {{ $p->kind === 'refund' || $p->kind === 'overage_refund' ? 'Refund' : ucfirst($p->kind) }}
                  · {{ $p->methodLabel() }}
                </div>
                <div style="font-size:10px;color:var(--ia-text-dim);margin-top:2px">
                  {{ $p->recorded_at ? tlocal($p->recorded_at, 'M j · g:i A') : '' }}
                  @if($p->source === 'register_sale' && $p->register_sale_id)
                    · sale {{ optional($p->registerSale)->sale_number ?? '#' }}
                  @endif
                </div>
              </div>
              <div style="font-weight:500;color:{{ $p->amount_cents < 0 ? '#F09595' : '#A8D670' }}">
                {{ $p->amount_cents < 0 ? '−' : '+' }}{{ format_money(abs($p->amount_cents)) }}
              </div>
            </div>
          @endforeach
        </div>
      @endif

      <div class="sidebar-stat" style="margin-top:8px">
        <span class="sidebar-stat-label">Paid so far</span>
        <span class="sidebar-stat-value" style="color:#A8D670">{{ format_money($appointment->paid_cents) }}</span>
      </div>

      @if($balanceDue > 0)
        <div class="sidebar-stat">
          <span class="sidebar-stat-label" style="font-weight:500">Balance owed</span>
          <span class="sidebar-stat-value" style="font-size:15px;font-weight:500">{{ format_money($balanceDue) }}</span>
        </div>
      @elseif($overage > 0)
        <div class="sidebar-stat">
          <span class="sidebar-stat-label" style="font-weight:500;color:#FBBF24">Customer is owed</span>
          <span class="sidebar-stat-value" style="font-size:15px;font-weight:500;color:#FBBF24">{{ format_money($overage) }}</span>
        </div>
      @else
        <div class="sidebar-stat">
          <span class="sidebar-stat-label" style="font-weight:500">Balance owed</span>
          <span class="sidebar-stat-value" style="font-size:15px;font-weight:500;color:#A8D670">$0.00</span>
        </div>
      @endif

      @if($hasOpenSale)
        <a href="{{ route('tenant.register.index', []) }}?resume={{ $openSale->id }}"
           class="ia-btn ia-btn--primary ia-btn--sm" style="width:100%;margin-top:14px;text-align:center;display:block">
          Take payment in register
        </a>
      @elseif($balanceDue > 0 && !\App\Support\AppointmentStatus::isTerminal($appointment->status))
        <button type="button" id="record-deposit-toggle" class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;margin-top:14px">
          + Record deposit
        </button>
        <div id="record-deposit-form" style="display:none;margin-top:10px;padding:12px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border)">
          <label style="font-size:11px;color:var(--ia-text-dim);display:block;margin-bottom:4px">Amount</label>
          <input type="number" id="record-deposit-amount" min="0.01" step="0.01" placeholder="0.00" style="width:100%;padding:6px 10px;background:var(--ia-bg);border:0.5px solid var(--ia-border);color:var(--ia-text);border-radius:6px;font-size:13px;margin-bottom:8px">
          <div style="display:flex;gap:6px">
            <button type="button" id="record-deposit-cancel" class="ia-btn ia-btn--ghost ia-btn--sm" style="flex:1">Cancel</button>
            <button type="button" id="record-deposit-go" class="ia-btn ia-btn--primary ia-btn--sm" style="flex:1">Send to register</button>
          </div>
          <p style="font-size:10px;color:var(--ia-text-dim);margin:8px 0 0">Creates a draft sale in the register where you take the actual payment.</p>
        </div>
      @endif
    </div>

    {{-- Cancel appointment — DOM kept, hidden in B layout; rail Cancel proxies to this --}}
    @unless(\App\Support\AppointmentStatus::isTerminal($appointment->status))
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn appt-cancel-btn-original" style="width:100%;order:9999">
        Cancel appointment
      </button>
    @endunless

  </div>{{-- /.appt-b-main --}}

</div>{{-- /.appt-b-shell --}}

{{-- RESCHEDULE-MODAL v1 --}}
@php
  // Build the eligible resources list. For now, all active resources on this tenant
  // are eligible. (A future improvement is per-service eligibility via
  // service_resource_eligibility, matching the create flow's logic.)
  $reschedFirstService = $appointment->items->first();
  $reschedFirstServiceId = $reschedFirstService?->service_item_id;
  $reschedCurrentResource = $availableResources->firstWhere('id', $appointment->resource_id);

  try {
    $reschedFromTimeC = $appointment->appointment_time
      ? \Carbon\Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time)
      : null;
    $reschedFromDur = (int) ($appointment->total_duration_minutes ?? 0);
    $reschedFromEndC = ($reschedFromTimeC && $reschedFromDur > 0)
      ? $reschedFromTimeC->copy()->addMinutes($reschedFromDur)
      : null;
  } catch (\Throwable $e) {
    $reschedFromTimeC = null; $reschedFromEndC = null;
  }
@endphp
@unless($isTerminal)
<div class="resch-modal" id="resch-modal" hidden role="dialog" aria-modal="true" aria-labelledby="resch-modal-title">
  <div class="resch-modal-backdrop" data-resch-close></div>
  <div class="resch-modal-card">
    <div class="resch-modal-head">
      <h2 class="resch-modal-title" id="resch-modal-title">Reschedule appointment</h2>
      <button type="button" class="resch-modal-close" data-resch-close aria-label="Close">×</button>
    </div>

    <div class="resch-modal-body">

      {{-- "From" current state --}}
      <div class="resch-from">
        <div class="resch-from-label">Current</div>
        <div class="resch-from-when">
          @if($reschedFromTimeC)
            {{ $reschedFromTimeC->format('D, M j · g:i A') }}@if($reschedFromEndC) – {{ $reschedFromEndC->format('g:i A') }}@endif
          @else
            No time set
          @endif
        </div>
        <div class="resch-from-resource">
          @if($reschedCurrentResource)
            <span class="resch-swatch" style="background: {{ $reschedCurrentResource->color_hex ?: '#888' }}"></span>
            {{ $reschedCurrentResource->name }}
          @else
            Unassigned
          @endif
        </div>
      </div>

      {{-- Resource picker --}}
      <div class="resch-field">
        <label class="resch-label" for="resch-resource">Resource</label>
        <select class="resch-input" id="resch-resource" data-resch-resource>
          @foreach($availableResources as $r)
            <option value="{{ $r->id }}" data-color="{{ $r->color_hex ?: '#888' }}" @selected($r->id === $appointment->resource_id)>
              {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
            </option>
          @endforeach
        </select>
      </div>

      {{-- Times picker — week strip --}}
      <div class="resch-field">
        <div class="resch-times-head">
          <label class="resch-label" style="margin:0">Available times</label>
          <div class="resch-week-nav">
            <button type="button" class="resch-week-btn" id="resch-prev-week" aria-label="Previous week">‹</button>
            <span class="resch-week-label" id="resch-week-label">—</span>
            <button type="button" class="resch-week-btn" id="resch-next-week" aria-label="Next week">›</button>
          </div>
        </div>
        <div class="resch-times-list" id="resch-times-list">
          <div class="resch-times-empty">Pick a resource and click Show times.</div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="resch-show-times"
                style="width:100%;margin-top:8px">
          Show available times
        </button>
      </div>

      {{-- "To" preview (appears after selection) --}}
      <div class="resch-to" id="resch-to" hidden>
        <div class="resch-to-label">New</div>
        <div class="resch-to-when" id="resch-to-when">—</div>
        <div class="resch-to-resource" id="resch-to-resource">—</div>
      </div>

    </div>

    <div class="resch-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" data-resch-close>Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="resch-submit" disabled
              data-appt-id="{{ $appointment->id }}"
              data-update-url="{{ $updateUrl }}"
              data-first-service-id="{{ $reschedFirstServiceId ?? '' }}">
        Reschedule
      </button>
    </div>
  </div>
</div>
@endunless

{{-- LAYOUT-B-CUSTDETAIL-MODAL v1 --}}
@if($appointment->responses->isNotEmpty())
<div class="appt-b-cust-modal" id="appt-b-cust-modal" hidden role="dialog" aria-modal="true" aria-labelledby="appt-b-cust-modal-title">
  <div class="appt-b-cust-modal-backdrop" data-cust-modal-close></div>
  <div class="appt-b-cust-modal-card">
    <div class="appt-b-cust-modal-head">
      <h2 class="appt-b-cust-modal-title" id="appt-b-cust-modal-title">Booking notes</h2>
      <button type="button" class="appt-b-cust-modal-close" data-cust-modal-close aria-label="Close">×</button>
    </div>
    <div class="appt-b-cust-modal-body">
      @foreach($appointment->responses as $r)
        <div class="appt-response">
          <div class="appt-response-label">{{ $r->field_label_snapshot }}</div>
          <div class="appt-response-value">{{ $r->response_value ?: '—' }}</div>
        </div>
      @endforeach
    </div>
  </div>
</div>
@endif

{{-- MARKER-PATCH-314 --}}
@include('tenant.appointments._tag_modal')

{{-- MARKER-BIZ-WORKORDER --}}
<style>
  .biz-pill{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border-radius:100px;padding:2px 7px;margin-left:6px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);vertical-align:1px}
</style>

@endsection

@push('scripts')
<script>
(function () {
  var updateUrl = '{{ $updateUrl }}';
  var csrf      = window.IntakeAdmin.csrfToken;

  var toggle  = document.getElementById('add-charge-toggle');
  var form    = document.getElementById('add-charge-form');
  var cancel  = document.getElementById('add-charge-cancel');
  var amtDisp = document.getElementById('charge-amount-display');
  var amtCents= document.getElementById('charge-amount-cents');

  if (toggle) toggle.addEventListener('click', function () {
    form.classList.add('open');
    toggle.style.display = 'none';
  });
  if (cancel) cancel.addEventListener('click', function () {
    form.classList.remove('open');
    toggle.style.display = '';
  });
  if (amtDisp) amtDisp.addEventListener('input', function () {
    amtCents.value = Math.round(parseFloat(amtDisp.value || 0) * 100);
  });

  var noteInput  = document.getElementById('note-input');
  var noteSubmit = document.getElementById('note-submit');
  var noteError  = document.getElementById('note-error');
  var notesList  = document.getElementById('notes-list');
  var noteChars  = document.getElementById('note-chars');

  if (noteInput && noteChars) {
    noteInput.addEventListener('input', function () {
      var rem = 500 - noteInput.value.length;
      noteChars.textContent = rem;
      noteChars.classList.toggle('warn', rem <= 30);
    });
  }

  if (noteSubmit) {
    noteSubmit.addEventListener('click', function () {
      var note = noteInput.value.trim();
      if (!note) { showErr('Please enter a note.'); return; }
      noteSubmit.disabled = true;
      noteSubmit.textContent = 'Saving…';

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'add_note');
      fd.append('note', note);

      fetch(updateUrl, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          noteSubmit.disabled = false;
          noteSubmit.textContent = 'Add note';
          if (!resp.ok) { showErr(resp.message || 'Error.'); return; }

          var empty = notesList.querySelector('.ia-notes-empty');
          if (empty) empty.remove();

          var el = document.createElement('div');
          el.className = 'ia-note';
          el.setAttribute('data-note-id', resp.id);
          el.innerHTML =
            '<div class="ia-note-head">' +
              '<span class="ia-note-author">' + esc(resp.author) + '</span>' +
              '<span class="ia-note-time">' + esc(resp.created_at) + '</span>' +
              '<button type="button" class="ia-note-delete" data-note-id="' + resp.id + '" title="Delete">&#x2715;</button>' +
            '</div>' +
            '<div class="ia-note-body">' + esc(resp.note) + '</div>';
          notesList.insertBefore(el, notesList.firstChild);
          bindDeleteOnEl(el.querySelector('.ia-note-delete'));

          noteInput.value = '';
          if (noteChars) { noteChars.textContent = '500'; noteChars.classList.remove('warn'); }
          hideErr();
        })
        .catch(function () {
          noteSubmit.disabled = false;
          noteSubmit.textContent = 'Add note';
          showErr('Network error. Try again.');
        });
    });
  }

  document.querySelectorAll('.ia-note-delete').forEach(bindDeleteOnEl);

  function bindDeleteOnEl(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm('Delete this note?')) return;
      var noteId = btn.getAttribute('data-note-id');
      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'delete_note');
      fd.append('note_id', noteId);
      fetch(updateUrl, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp.ok) {
            var li = document.querySelector('[data-note-id="' + noteId + '"]');
            if (li) li.remove();
            if (!notesList.querySelector('.ia-note')) {
              var p = document.createElement('p');
              p.className = 'ia-notes-empty';
              p.style.cssText = 'font-size:13px;opacity:.4';
              p.textContent = 'No notes yet.';
              notesList.appendChild(p);
            }
          }
        });
    });
  }

  function showErr(msg) {
    if (noteError) { noteError.textContent = msg; noteError.style.display = ''; }
  }
  function hideErr() {
    if (noteError) noteError.style.display = 'none';
  }
  function esc(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }


  // ================================================================
  // Line-item editor — services and add-ons
  // ================================================================
  function postOp(payload) {
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
    return fetch(updateUrl, { method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  // Add a new service or addon
  var addBtn = document.getElementById('add-line-btn');
  var addSel = document.getElementById('add-line-select');
  if (addBtn && addSel) {
    addBtn.addEventListener('click', function () {
      var val = addSel.value;
      if (!val) return;
      var parts = val.split(':');
      var kind = parts[0]; // 'service' or 'addon'
      var id   = parts[1];
      addBtn.disabled = true;
      addBtn.textContent = '…';
      var op = kind === 'addon' ? 'add_addon' : 'add_service';
      var payload = { op: op };
      payload[kind === 'addon' ? 'addon_id' : 'service_item_id'] = id;
      postOp(payload).then(function (res) {
        addBtn.disabled = false;
        addBtn.textContent = 'Add';
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success(kind === 'addon' ? 'Add-on added' : 'Service added');
          setTimeout(function () { window.location.reload(); }, 500);
        } else {
          window.IntakeToast.error((res.body && res.body.message) || 'Could not add.');
        }
      });
    });
  }

  // Remove a service or addon
  document.querySelectorAll('.line-remove').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row  = btn.closest('.line-row');
      var kind = row.getAttribute('data-kind');
      var id   = row.getAttribute('data-item-id');

      window.IntakeConfirm.show({
        title:       'Remove this ' + (kind === 'addon' ? 'add-on' : 'service') + '?',
        message:     'The line will be removed and totals recalculated.',
        confirmText: 'Remove',
        danger:      true
      }).then(function (ok) {
        if (!ok) return;
        var op = kind === 'addon' ? 'remove_addon' : 'remove_service';
        var payload = { op: op };
        payload[kind === 'addon' ? 'addon_id' : 'item_id'] = id;
        postOp(payload).then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            window.IntakeToast.success('Removed');
            setTimeout(function () { window.location.reload(); }, 500);
          } else {
            window.IntakeToast.error((res.body && res.body.message) || 'Could not remove.');
          }
        });
      });
    });
  });

  // Update price/duration override on blur
  document.querySelectorAll('.line-edit').forEach(function (input) {
    input.addEventListener('blur', function () {
      var row  = input.closest('.line-row');
      var kind = row.getAttribute('data-kind');
      var id   = row.getAttribute('data-item-id');
      var field = input.getAttribute('data-field');

      // Read both fields' current values so we send a complete update.
      var durInput = row.querySelector('.line-edit[data-field="duration_minutes"]');
      var priInput = row.querySelector('.line-edit[data-field="price_dollars"]');
      var duration = durInput ? parseInt(durInput.value, 10) : null;
      var dollars  = priInput ? parseFloat(priInput.value) : null;
      var cents    = (dollars === null || isNaN(dollars)) ? null : Math.round(dollars * 100);

      postOp({
        op: 'update_line_item',
        kind: kind,
        item_id: id,
        price_cents: cents === null ? '' : cents,
        duration_minutes: (duration === null || isNaN(duration)) ? '' : duration,
      }).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success('Updated');
        } else {
          window.IntakeToast.error((res.body && res.body.message) || 'Could not save.');
        }
      });
    });
  });

  // Status updates — used by progress bar steps, reopen button, and cancel button.
  function updateStatus(targetStatus, targetLabel, opts) {
    opts = opts || {};
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'status');
    fd.append('status', targetStatus);

    return fetch(updateUrl, { method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success(targetLabel || 'Saved');
          // MARKER-PATCH-527 — completed + P&D: offer to text delivery windows
          if (res.body.propose_delivery && window.IntakeDeliveryPropose
              && IntakeDeliveryPropose.show(res.body.propose_delivery, { updateUrl: updateUrl, csrf: csrf })) {
            return true; // modal handles the reload
          }
          setTimeout(function () {
            if (opts.redirectToCalendar) {
              window.location.href = '{{ route("tenant.calendar.index") }}';
            } else {
              window.location.reload();
            }
          }, 600);
          return true;
        }
        var msg = (res.body && res.body.message) || 'Could not update status.';
        window.IntakeToast.error(msg);
        return false;
      })
      .catch(function () {
        window.IntakeToast.error('Network error. Try again.');
        return false;
      });
  }

  // Progress bar — click a step to move there.
  // Forward moves go silently. Backward moves trigger a confirm modal.
  var bar = document.querySelector('.appt-progress-bar');
  if (bar) {
    var currentIndex = parseInt(bar.getAttribute('data-current-index'), 10);
    // Set the green-fill width via CSS variable.
    bar.style.setProperty('--progress', currentIndex / 3);

    bar.querySelectorAll('.appt-progress-step').forEach(function (step) {
      step.addEventListener('click', function () {
        var stepIndex = parseInt(step.getAttribute('data-step-index'), 10);
        var status    = step.getAttribute('data-status');
        var label     = step.getAttribute('data-label');
        if (stepIndex === currentIndex) return;  // clicking current is a no-op

        var go = function () {
          step.classList.add('is-saving');
          updateStatus(status, label);
        };

        if (stepIndex < currentIndex) {
          window.IntakeConfirm.show({
            title:       'Move back to ' + label + '?',
            message:     'This appointment is currently further along. Going back may surprise the customer.',
            confirmText: 'Move back',
            cancelText:  'Keep where it is'
          }).then(function (ok) { if (ok) go(); });
        } else {
          go();
        }
      });
    });
  }

  // Reopen button on terminal cards (cancelled / refunded)
  var reopenBtn = document.querySelector('.appt-reopen-btn');
  if (reopenBtn) {
    reopenBtn.addEventListener('click', function () {
      window.IntakeConfirm.show({
        title:       'Reopen this appointment?',
        message:     'This will return it to Pending status.',
        confirmText: 'Reopen',
        cancelText:  'Keep closed'
      }).then(function (ok) {
        if (ok) updateStatus('pending', 'Reopened');
      });
    });
  }

  // Cancel button (sidebar)
  var cancelBtn = document.querySelector('.appt-cancel-btn');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      window.IntakeConfirm.show({
        title:       'Cancel this appointment?',
        message:     "The appointment will be removed from the calendar and the customer's slot released. This stays in your records but won't show on the active schedule.",
        confirmText: 'Cancel appointment',
        cancelText:  'Keep it',
        danger:      true
      }).then(function (ok) {
        if (ok) updateStatus('cancelled', 'Cancelled', { redirectToCalendar: true });
      });
    });
  }

  // Work order edit mode toggle — wo-edit-toggle-bound
  var woDisplay = document.getElementById('wo-display');
  var woForm = document.getElementById('wo-edit-form');
  var woToggle = document.getElementById('wo-edit-toggle');
  var woCancel = document.getElementById('wo-edit-cancel');
  if (woToggle && woForm && woDisplay) {
    woToggle.addEventListener('click', function() {
      woDisplay.style.display = 'none';
      woForm.style.display = 'block';
      woToggle.style.display = 'none';
    });
  }
  if (woCancel && woForm && woDisplay) {
    woCancel.addEventListener('click', function() {
      woForm.style.display = 'none';
      woDisplay.style.display = 'block';
      if (woToggle) woToggle.style.display = '';
    });
  }

  /* ===================================================================
     Products & Add-ons — picker, add, remove, quantity edit, custom item
     =================================================================== */
  var partPickerInput   = document.getElementById('part-picker-input');
  var partPickerResults = document.getElementById('part-picker-results');
  var partsBody         = document.getElementById('parts-body');

  function searchInventory(q) {
    var url = '{{ route("tenant.appointments.inventory-search", ["subdomain" => $currentTenant->subdomain]) }}?q=' + encodeURIComponent(q);
    return fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function(r) { return r.json(); });
  }

  // The custom-item pin row is always shown at the top of the picker so
  // users have an obvious escape hatch when no inventory match exists.
  var customItemPinHtml =
      '<div class="part-pick-row part-pick-custom" style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2)">'
    + '<div style="font-weight:500;font-size:13px;color:var(--ia-accent)">+ Custom item</div>'
    + '<div style="font-size:11px;opacity:.55;margin-top:2px">One-off item not in inventory</div>'
    + '</div>';

  function renderPickerResults(items) {
    var html = customItemPinHtml;
    if (!items.length) {
      html += '<div style="padding:10px;font-size:12px;opacity:.5">No matching products in inventory.</div>';
    } else {
      html += items.map(function(it) {
        var stockClass = it.stock <= 0 && !it.allow_oversell ? 'opacity:.4' : '';
        var stockNote  = it.stock <= 0 && !it.allow_oversell
          ? '<span style="color:#F09595">Out of stock</span>'
          : 'In stock: ' + it.stock;
        return '<div class="part-pick-row" data-id="' + it.id + '" style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border);' + stockClass + '">'
             + '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">'
             + '<div style="font-weight:500;font-size:13px">' + escapeHtml(it.name) + '</div>'
             + '<div style="font-size:12px;opacity:.7">' + it.price_display + '</div>'
             + '</div>'
             + '<div style="font-size:11px;opacity:.5;margin-top:2px;display:flex;justify-content:space-between">'
             + '<span>' + (it.sku ? escapeHtml(it.sku) : '') + '</span>'
             + '<span>' + stockNote + '</span>'
             + '</div>'
             + '</div>';
      }).join('');
    }
    partPickerResults.innerHTML = html;
    partPickerResults.style.display = 'block';
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  if (partPickerInput) {
    var debounceTimer = null;
    partPickerInput.addEventListener('input', function() {
      var q = partPickerInput.value.trim();
      clearTimeout(debounceTimer);
      if (q.length < 1) {
        // Still show the pin so "+ Custom item" is reachable from an empty box.
        renderPickerResults([]);
        return;
      }
      debounceTimer = setTimeout(function() {
        searchInventory(q).then(function(d) {
          if (d.ok) renderPickerResults(d.items);
        });
      }, 180);
    });

    partPickerInput.addEventListener('focus', function() {
      // Always show on focus — the pin is reachable here even with no matches.
      var q = partPickerInput.value.trim();
      if (!q) {
        searchInventory('').then(function(d) {
          renderPickerResults(d.ok ? d.items : []);
        });
      }
    });

    document.addEventListener('click', function(e) {
      if (!partPickerInput.contains(e.target) && !partPickerResults.contains(e.target)) {
        partPickerResults.style.display = 'none';
      }
    });

    // Click a result → either open custom-item form or add the inventory part.
    partPickerResults.addEventListener('click', function(e) {
      var row = e.target.closest('.part-pick-row');
      if (!row) return;
      partPickerResults.style.display = 'none';
      if (row.classList.contains('part-pick-custom')) {
        openCustomItemForm();
        return;
      }
      var id = row.getAttribute('data-id');
      addPart(id);
    });
  }

  function addPart(inventoryItemId) {
    fetch(updateUrl, {
      method: 'PATCH',
      headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
      body: JSON.stringify({
        op: 'add_part',
        inventory_item_id: inventoryItemId,
        quantity: 1,
      }),
    }).then(function(r) {
      return r.json().then(function(d) { return { ok: r.ok, body: d }; });
    }).then(function(res) {
      if (!res.ok || !res.body.ok) {
        alert(res.body && res.body.message || 'Could not add item.');
        return;
      }
      // Reload — easiest way to get fresh totals + stock displays everywhere.
      window.location.reload();
    });
  }

  // Custom item form
  var customForm    = document.getElementById('custom-item-form');
  var customCancel  = document.getElementById('custom-item-cancel');
  var customAdd     = document.getElementById('custom-item-add');
  var customName    = document.getElementById('custom-item-name');
  var customPrice   = document.getElementById('custom-item-price');
  var customQty     = document.getElementById('custom-item-qty');
  var customTaxable = document.getElementById('custom-item-taxable');

  function openCustomItemForm() {
    if (!customForm) return;
    customForm.style.display = 'block';
    customName.value = '';
    customPrice.value = '';
    customQty.value = '1';
    if (customTaxable) customTaxable.checked = true;
    customName.focus();
  }
  function closeCustomItemForm() {
    if (!customForm) return;
    customForm.style.display = 'none';
  }

  if (customCancel) customCancel.addEventListener('click', closeCustomItemForm);

  if (customAdd) {
    customAdd.addEventListener('click', function() {
      var name  = (customName.value || '').trim();
      var price = parseFloat(customPrice.value || '0');
      var qty   = parseInt(customQty.value, 10) || 1;
      if (!name) { customName.focus(); return; }
      if (isNaN(price) || price < 0) { customPrice.focus(); return; }
      if (qty < 1) qty = 1;

      customAdd.disabled = true;
      customAdd.textContent = 'Adding…';
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({
          op: 'add_custom_item',
          name: name,
          unit_price_cents: Math.round(price * 100),
          quantity: qty,
          is_taxable: customTaxable && customTaxable.checked ? 1 : 0,
        }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not add custom item.');
          customAdd.disabled = false;
          customAdd.textContent = 'Add';
          return;
        }
        window.location.reload();
      }).catch(function() {
        alert('Network error.');
        customAdd.disabled = false;
        customAdd.textContent = 'Add';
      });
    });

    // Enter in the name or price field submits.
    [customName, customPrice, customQty].forEach(function(inp) {
      if (!inp) return;
      inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); customAdd.click(); }
      });
    });
  }

  // Remove
  if (partsBody) {
    partsBody.addEventListener('click', function(e) {
      var btn = e.target.closest('.part-remove');
      if (!btn) return;
      var partId = btn.getAttribute('data-part-id');
      if (!confirm('Remove this item?')) return;
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({ op: 'remove_part', part_id: partId }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not remove item.');
          return;
        }
        window.location.reload();
      });
    });

    // Quantity edit (debounced blur)
    partsBody.addEventListener('change', function(e) {
      var inp = e.target.closest('.part-qty-edit');
      if (!inp) return;
      var partId = inp.getAttribute('data-part-id');
      var qty    = parseInt(inp.value, 10);
      if (!qty || qty < 1) {
        inp.value = 1;
        qty = 1;
      }
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({ op: 'update_part_quantity', part_id: partId, quantity: qty }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not update quantity.');
          window.location.reload();
          return;
        }
        // Update the line total cell inline for snappier feel.
        var row  = inp.closest('.part-row');
        var cell = row && row.querySelector('[data-line-total]');
        if (cell) cell.textContent = res.body.line_total_display;
      });
    });
  }

  /* ===================================================================
     Record deposit — opens form, sends to register, redirects.
     =================================================================== */
  var depositToggle = document.getElementById('record-deposit-toggle');
  var depositForm   = document.getElementById('record-deposit-form');
  var depositCancel = document.getElementById('record-deposit-cancel');
  var depositGo     = document.getElementById('record-deposit-go');
  var depositAmt    = document.getElementById('record-deposit-amount');

  if (depositToggle) {
    depositToggle.addEventListener('click', function () {
      depositToggle.style.display = 'none';
      depositForm.style.display = 'block';
      if (depositAmt) depositAmt.focus();
    });
  }
  if (depositCancel) {
    depositCancel.addEventListener('click', function () {
      depositForm.style.display = 'none';
      depositToggle.style.display = '';
      depositAmt.value = '';
    });
  }
  if (depositGo) {
    depositGo.addEventListener('click', function () {
      var dollars = parseFloat(depositAmt.value || '0');
      if (isNaN(dollars) || dollars <= 0) {
        depositAmt.focus();
        return;
      }
      depositGo.disabled = true;
      depositGo.textContent = 'Sending…';
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({
          op: 'record_deposit',
          amount_cents: Math.round(dollars * 100),
        }),
      }).then(function (r) {
        return r.json().then(function (d) { return { ok: r.ok, body: d }; });
      }).then(function (res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not create deposit sale.');
          depositGo.disabled = false;
          depositGo.textContent = 'Send to register';
          return;
        }
        // Redirect to the register draft
        window.location.href = res.body.redirect_url;
      }).catch(function () {
        alert('Network error.');
        depositGo.disabled = false;
        depositGo.textContent = 'Send to register';
      });
    });

    if (depositAmt) {
      depositAmt.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); depositGo.click(); }
      });
    }
  }

  /* ===================================================================
     Void register sale — staff clicked "Edit (voids draft)" on a locked
     section. Confirms, voids the open draft sale, reloads the page so
     the now-unlocked editing UI renders cleanly.
     =================================================================== */
  document.querySelectorAll('.void-sale-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Void the draft register sale to edit this appointment?\n\nThe sale will be cancelled. After your edits, completing the appointment again creates a fresh draft.')) {
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Voiding…';
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({ op: 'void_register_sale' }),
      }).then(function (r) {
        return r.json().then(function (d) { return { ok: r.ok, body: d }; });
      }).then(function (res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not void sale.');
          btn.disabled = false;
          btn.textContent = '🔒 Edit (voids draft)';
          return;
        }
        window.location.reload();
      }).catch(function () {
        alert('Network error.');
        btn.disabled = false;
        btn.textContent = '🔒 Edit (voids draft)';
      });
    });
  });

}());
</script>

  <script src="{{ asset('js/tenant/appointment-resource.js') }}?v={{ filemtime(public_path('js/tenant/appointment-resource.js')) }}" defer></script>

  <script src="{{ asset('js/tenant/appointment-slot-weight.js') }}?v={{ filemtime(public_path('js/tenant/appointment-slot-weight.js')) }}" defer></script>

<script>
// LAYOUT-B-WIRING v1
// Rail "Cancel" button proxies to the original cancel handler.
// Rail "Reschedule" shows a coming-soon toast.
document.addEventListener('DOMContentLoaded', function () {
  var railCancel = document.querySelector('.appt-b-cancel-btn');
  if (railCancel) {
    railCancel.addEventListener('click', function () {
      var orig = document.querySelector('.appt-cancel-btn-original');
      if (orig) orig.click();
    });
  }

  var railResch = document.querySelector('.appt-b-reschedule-btn');
  if (railResch) {
    railResch.addEventListener('click', function () {
      if (window.IntakeToast) {
        window.IntakeToast.info('Reschedule from this page ships tomorrow. For now, drag the appointment block on the calendar to move it.');
      }
    });
  }
});
</script>

<script>
// LAYOUT-B-CUST-MODAL-JS v1
document.addEventListener('DOMContentLoaded', function () {
  var modal     = document.getElementById('appt-b-cust-modal');
  var openBtn   = document.querySelector('.appt-b-cust-details-btn');
  if (!modal || !openBtn) return;

  function open()  { modal.hidden = false;  document.body.style.overflow = 'hidden'; }
  function close() { modal.hidden = true;   document.body.style.overflow = ''; }

  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-cust-modal-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
});
</script>

<script>
// RESCHEDULE-MODAL-JS v1
(function () {
  var modal = document.getElementById('resch-modal');
  if (!modal) return;  // terminal-state appointments don't render the modal

  var openBtn  = document.querySelector('.appt-b-reschedule-btn');
  if (!openBtn) return;

  // Strip the old "ships tomorrow" toast handler by cloning the button.
  // (clone+replace removes all event listeners.)
  var newOpenBtn = openBtn.cloneNode(true);
  openBtn.parentNode.replaceChild(newOpenBtn, openBtn);
  openBtn = newOpenBtn;

  var resourceSel = document.getElementById('resch-resource');
  var showBtn     = document.getElementById('resch-show-times');
  var prevWeekBtn = document.getElementById('resch-prev-week');
  var nextWeekBtn = document.getElementById('resch-next-week');
  var weekLabel   = document.getElementById('resch-week-label');
  var listEl      = document.getElementById('resch-times-list');
  var toBlock     = document.getElementById('resch-to');
  var toWhenEl    = document.getElementById('resch-to-when');
  var toResEl     = document.getElementById('resch-to-resource');
  var submitBtn   = document.getElementById('resch-submit');

  var weekTimesUrl = "{{ route('tenant.appointments.week-times') }}";

  var state = {
    weekStartDate: null,
    selectedSlot:  null,   // {date, time, time_label, date_label}
    slots:         [],
  };

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }

  function open() {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    state.selectedSlot = null;
    state.weekStartDate = todayStr();
    weekLabel.textContent = formatWeekLabel(state.weekStartDate);
    listEl.innerHTML = '<div class="resch-times-empty">Click Show available times to see open slots.</div>';
    toBlock.hidden = true;
    submitBtn.disabled = true;
  }

  function close() {
    modal.hidden = true;
    document.body.style.overflow = '';
  }

  function fetchTimes() {
    var firstSvc = submitBtn.getAttribute('data-first-service-id');
    if (!firstSvc) {
      listEl.innerHTML = '<div class="resch-times-empty error">This appointment has no service item; cannot look up available times.</div>';
      return;
    }
    var resourceId = resourceSel.value;
    if (!resourceId) {
      listEl.innerHTML = '<div class="resch-times-empty error">Pick a resource first.</div>';
      return;
    }
    listEl.innerHTML = '<div class="resch-times-empty">Loading…</div>';
    weekLabel.textContent = formatWeekLabel(state.weekStartDate);
    prevWeekBtn.disabled = (state.weekStartDate <= todayStr());

    var url = weekTimesUrl
      + '?service_id='  + encodeURIComponent(firstSvc)
      + '&resource_id=' + encodeURIComponent(resourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.slots = data.slots || [];
        renderTimes();
      })
      .catch(function () {
        listEl.innerHTML = '<div class="resch-times-empty error">Could not load available times.</div>';
      });
  }

  function renderTimes() {
    if (!state.slots.length) {
      listEl.innerHTML = '<div class="resch-times-empty">No available times this week. Try Next week →</div>';
      return;
    }
    var html = '';
    state.slots.forEach(function (slot, idx) {
      var isSel = state.selectedSlot
        && state.selectedSlot.date === slot.date
        && state.selectedSlot.time === slot.time;
      html += '<div class="resch-time-row' + (isSel ? ' selected' : '') + '" data-idx="' + idx + '">'
        +   '<span class="resch-time-date">' + escapeHtml(slot.date_label) + '</span>'
        +   '<span class="resch-time-time">' + escapeHtml(slot.time_label) + '</span>'
        + '</div>';
    });
    listEl.innerHTML = html;
    listEl.querySelectorAll('.resch-time-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var slot = state.slots[parseInt(row.getAttribute('data-idx'), 10)];
        state.selectedSlot = slot;
        renderTimes();
        updateToPreview();
      });
    });
  }

  function updateToPreview() {
    if (!state.selectedSlot) {
      toBlock.hidden = true;
      submitBtn.disabled = true;
      return;
    }
    var resOpt = resourceSel.options[resourceSel.selectedIndex];
    var resColor = resOpt ? resOpt.getAttribute('data-color') : '#888';
    var resName  = resOpt ? resOpt.textContent.trim() : '';
    toWhenEl.textContent = state.selectedSlot.date_label + ' · ' + state.selectedSlot.time_label;
    toResEl.innerHTML = '<span class="resch-swatch" style="background:' + escapeHtml(resColor) + '"></span>' + escapeHtml(resName);
    toBlock.hidden = false;
    submitBtn.disabled = false;
  }

  function formatWeekLabel(startDate) {
    if (!startDate) return '—';
    var s = new Date(startDate + 'T00:00:00');
    var e = new Date(s); e.setDate(e.getDate() + 6);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[s.getMonth()] + ' ' + s.getDate() + ' – ' + months[e.getMonth()] + ' ' + e.getDate();
  }

  function shiftWeek(days) {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + days);
    var ymd = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (ymd < todayStr()) ymd = todayStr();
    state.weekStartDate = ymd;
    fetchTimes();
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  function submit() {
    if (!state.selectedSlot) return;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Rescheduling…';

    var url = submitBtn.getAttribute('data-update-url');
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch(url, {
      method: 'PATCH',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        op: 'reschedule',
        appointment_date: state.selectedSlot.date,
        appointment_time: state.selectedSlot.time,
        resource_id:      resourceSel.value,
      }),
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    }).then(function (resp) {
      if (resp.status === 200 && resp.data.ok) {
        if (window.IntakeToast) window.IntakeToast.success('Appointment rescheduled.');
        // Reload to reflect the new state across rail + main + system note.
        setTimeout(function () { window.location.reload(); }, 600);
      } else if (resp.status === 409) {
        // Slot taken — refresh times.
        if (window.IntakeToast) window.IntakeToast.error(resp.data.message || 'That time was just taken.');
        state.selectedSlot = null;
        toBlock.hidden = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Reschedule';
        fetchTimes();
      } else {
        if (window.IntakeToast) window.IntakeToast.error(resp.data.message || 'Reschedule failed.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Reschedule';
      }
    }).catch(function () {
      if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Reschedule';
    });
  }

  // Wire up.
  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-resch-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
  resourceSel.addEventListener('change', function () {
    state.selectedSlot = null;
    toBlock.hidden = true;
    submitBtn.disabled = true;
    if (state.slots.length) fetchTimes();
  });
  showBtn.addEventListener('click', fetchTimes);
  prevWeekBtn.addEventListener('click', function () { shiftWeek(-7); });
  nextWeekBtn.addEventListener('click', function () { shiftWeek(7); });
  submitBtn.addEventListener('click', submit);
})();
</script>
@endpush
BIZ3_10_EOF

cat > 'resources/views/tenant/appointments/show-multi-asset.blade.php' <<'BIZ3_11_EOF'
{{-- MARKER-PATCH-158-D — Multi-asset appointment show view (read-only) --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = $appointment->ra_number;
  $statusLabels = \App\Support\AppointmentStatus::LABELS; // MARKER-PATCH-287 single source

  // Totals computed from the asset rollups + any loose (unpinned) items.
  $assetsSubtotal = $appointmentAssets->sum('subtotal_cents');
  $looseSubtotal  = $looseItems->sum('price_cents') + $looseAddons->sum('price_cents');
  $subtotalCents  = $assetsSubtotal + $looseSubtotal;
  // Tax rate from tenant settings, if any
  $taxRate        = (float) ($appointment->tenant->settings['default_tax_rate'] ?? 0);
  $taxCents       = (int) round($subtotalCents * $taxRate / 100);
  $totalCents     = $subtotalCents + $taxCents;

  $serviceCount = $appointmentAssets->sum(fn($a) => $a->items->count()) + $looseItems->count();
  $addonCount   = $appointmentAssets->sum(fn($a) => $a->addons->count()) + $looseAddons->count();

  $updateUrl = route('tenant.appointments.update', $appointment->id);

  // MARKER-PATCH-158-E2 — status pipeline (mirrors legacy show.blade.php)
  $isTerminal    = \App\Support\AppointmentStatus::isTerminal($appointment->status);
  $pipelineSteps = \App\Support\AppointmentStatus::pipeline();
  if ($appointment->status === 'shipped') $pipelineSteps[] = 'shipped';
  if ($appointment->status === 'closed')  $pipelineSteps[] = 'closed';
  $currentIndex = array_search($appointment->status, $pipelineSteps);
  if ($currentIndex === false) $currentIndex = 0;
@endphp

@push('styles')
<style>
.ma-layout {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 24px;
  align-items: start;
}
@media (max-width: 1000px) {
  .ma-layout { grid-template-columns: 1fr; }
}

/* ============== MARKER-PATCH-158-G3 — Top row (status | customer tile) ============== */
.ma-top-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 18px;
  align-items: stretch;
}
@media (max-width: 900px) {
  .ma-top-row { grid-template-columns: 1fr; }
}
/* MARKER-PATCH-413 — phone: stack header so the long RA number isn't squeezed */
@media (max-width: 640px) {
  .ma-page-head { flex-direction: column; gap: 14px; }
  .ma-page-title { font-size: 19px; gap: 10px; }
}

/* ===== MARKER-PATCH-414 — mobile B: summary hero + segmented tabs ===== */
.ma-mhero, .ma-mtabs { display: none; }
.ma-mhero { background: var(--ia-surface, rgba(255,255,255,0.02)); border: 1px solid var(--ia-border); border-radius: 14px; padding: 15px 16px; margin-bottom: 13px; }
.ma-mhero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.ma-mhero-cust { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; }
.ma-mhero-when { color: var(--ia-text-dim); font-size: 12.5px; margin-top: 2px; }
.ma-mhero-bal { display: flex; align-items: baseline; justify-content: space-between; margin-top: 13px; padding-top: 12px; border-top: 1px solid var(--ia-border); }
.ma-mhero-bal .l { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em; color: #e0a82e; font-weight: 600; }
.ma-mhero-bal .a { font-size: 22px; font-weight: 760; font-variant-numeric: tabular-nums; }
.ma-mhero-bal--paid .l { color: #86efac; }
.ma-mtabs { background: rgba(255,255,255,0.04); border: 1px solid var(--ia-border); border-radius: 11px; padding: 3px; margin-bottom: 14px; }
.ma-mtabs button { flex: 1; border: none; background: none; font: inherit; font-size: 12px; font-weight: 600; color: var(--ia-text-dim); padding: 8px 3px; border-radius: 8px; cursor: pointer; }
.ma-mtabs button.on { background: rgba(255,255,255,0.07); color: var(--ia-text); }
.ma-mhero-ra { font-family: ui-monospace, Menlo, monospace; font-size: 11.5px; letter-spacing: 0.02em; color: var(--ia-text-dim); margin-bottom: 9px; }
.ma-mbar { display: none; gap: 8px; margin-top: 13px; } /* MARKER-PATCH-417 — in-hero action row */
.ma-mbar .ia-btn { flex: 1; }

@media (max-width: 640px) {
  .ma-mhero { display: block; }
  .ma-mtabs { display: flex; }
  .ma-layout { display: block; }
  #ma-appt[data-mtab] .ma-top-row,
  #ma-appt[data-mtab] .ma-assets-group,
  #ma-appt[data-mtab] #ma-notes-card,
  #ma-appt[data-mtab] .ma-rail { display: none; }
  #ma-appt[data-mtab="overview"] .ma-top-row { display: grid; }
  #ma-appt[data-mtab="items"] .ma-assets-group { display: block; }
  #ma-appt[data-mtab="notes"] #ma-notes-card { display: block; }
  #ma-appt[data-mtab="pay"] .ma-rail { display: flex; }
  /* MARKER-PATCH-415 — match the mockup */
  #ma-appt .ma-page-head { display: none !important; } /* MARKER-PATCH-417 — beat the later base .ma-page-head rule */
  .ma-mbar { display: flex; }
  .ia-page#ma-appt { padding: 14px 14px 60px !important; } /* MARKER-PATCH-416/417 — gutter + nav clearance */
  .ma-layout { gap: 0; }
  #ma-appt[data-mtab] .ma-sale-banner { display: none; }
  #ma-appt[data-mtab="pay"] .ma-sale-banner { display: flex; }

  /* MARKER-PATCH-418 — even up the mobile card family: one radius + one gutter
     so hero / tabs / tiles line up; minmax(0,1fr) + min-width:0 keep a long
     status label or email from pushing a tile past the gutter on a narrow phone. */
  #ma-appt .ma-mhero,
  #ma-appt .ma-mtabs,
  #ma-appt .ma-top-tile { border-radius: 14px; }
  #ma-appt .ma-top-tile { padding: 15px 16px; }
  #ma-appt .ma-top-row { grid-template-columns: minmax(0, 1fr); }
  #ma-appt .ma-progress-step { min-width: 0; }
}
/* MARKER-PATCH-211 — subtle card separation: stronger edges + a small lift */
.ma-layout { --ia-border: rgba(255,255,255,0.17); }
.ma-layout .ma-top-tile,
.ma-layout .ma-asset { box-shadow: 0 1px 2px rgba(0,0,0,0.5); }
.ma-top-tile {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
}
.ma-top-tile-label {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ia-text-faint, #52525b);
  margin-bottom: 14px;
}

/* When the progress card is a top tile, drop its outer padding/margins and
   let the tile container handle them. The bar centers vertically in the
   remaining space so it visually aligns with the right-tile content. */
.ma-top-tile.ma-progress-card {
  padding: 16px 20px;
  margin-bottom: 0;
  justify-content: flex-start;
}
.ma-top-tile.ma-terminal-card {
  margin-bottom: 0;
  align-items: center;
  flex-direction: row;
  gap: 12px;
}

/* Customer header inside the right tile */
.ma-top-customer {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.ma-top-customer-main {
  flex: 1;
  min-width: 0;
}
.ma-top-customer-main .ma-customer-name {
  font-size: 14px;
  font-weight: 500;
}
.ma-top-customer-main .ma-customer-meta {
  font-size: 11.5px;
  color: var(--ia-text-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 2px;
  display: block;
}
.ma-top-customer-main .ma-customer-meta .sep { margin: 0 4px; opacity: 0.6; }
.ma-top-view-link {
  flex-shrink: 0;
  font-size: 12px;
  color: var(--ia-accent, #BEF264);
  text-decoration: none;
}
.ma-top-view-link:hover { text-decoration: underline; }

/* Resource picker row inside right tile */
.ma-top-resource {
  padding-top: 12px;
  border-top: 0.5px solid var(--ia-border);
  margin-bottom: 12px;
}
.ma-top-resource-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Actions row inside right tile */
.ma-top-actions {
  display: flex;
  gap: 8px;
  padding-top: 12px;
  border-top: 0.5px solid var(--ia-border);
  margin-top: auto; /* push actions to bottom of tile when tile is taller */
}

/* Header */
.ma-page-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  gap: 24px; margin-bottom: 20px;
}
.ma-page-eyebrow {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em;
  color: var(--ia-text-faint, #52525b); margin-bottom: 4px;
}
.ma-page-title {
  font-size: 22px; font-weight: 500; letter-spacing: -0.01em;
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.ma-ra-number { white-space: nowrap; }
.ma-status-pill {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
  padding: 3px 10px; border-radius: 4px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
  border: 1px solid var(--ia-border);
}
.ma-status-pill--scheduled  { background: rgba(96,165,250,0.12); color: #93c5fd; border-color: rgba(96,165,250,0.25); }
.ma-status-pill--confirmed  { background: rgba(96,165,250,0.12); color: #93c5fd; border-color: rgba(96,165,250,0.25); }
.ma-status-pill--in_progress{ background: rgba(251,191,36,0.12); color: #fcd34d; border-color: rgba(251,191,36,0.25); }
.ma-status-pill--completed  { background: rgba(74,222,128,0.10); color: #86efac; border-color: rgba(74,222,128,0.25); }
.ma-status-pill--pending    { background: var(--ia-surface-3, rgba(255,255,255,0.04)); color: var(--ia-text-dim); border-color: var(--ia-border); }
.ma-page-sub {
  margin-top: 6px;
  font-size: 13px; color: var(--ia-text-dim);
  display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
}
.ma-page-sub .dot { color: var(--ia-text-faint, #52525b); }

/* Customer avatar + name + meta — used in the top-row tile (G3) */
.ma-customer-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: rgba(190,242,100,0.15);
  color: var(--ia-accent, #BEF264);
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 500; font-size: 13px;
  flex-shrink: 0;
}
.ma-customer-name { font-size: 14px; font-weight: 500; }
.ma-customer-meta {
  font-size: 12px; color: var(--ia-text-dim);
}
.ma-customer-meta .sep { color: var(--ia-text-faint, #52525b); }

/* Section heads */
.ma-section-head {
  display: flex; align-items: center; justify-content: space-between;
  margin: 4px 0 14px;
}
.ma-section-title {
  font-size: 13px; font-weight: 500;
  display: flex; align-items: center; gap: 10px;
}
.ma-section-title .count {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
  padding: 2px 7px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  border-radius: 4px;
  color: var(--ia-text-dim);
}
.ma-section-sub {
  font-size: 12px; color: var(--ia-text-dim);
  margin-bottom: 14px; line-height: 1.55;
}

/* Asset cards */
.ma-asset {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  margin-bottom: 12px;
  /* MARKER-PATCH-158-G11 — Removed overflow:hidden; was clipping the
     per-asset part-picker autocomplete dropdown (positioned absolute
     below the input, extending past the card edge). The defensive
     clipping wasn't actually needed — child backgrounds don't bleed
     past the rounded corners. */
  position: relative;
}
.ma-asset-head {
  display: grid;
  grid-template-columns: 28px 1fr auto auto;
  gap: 12px;
  align-items: center;
  padding: 14px 18px;
  border-bottom: 1px solid var(--ia-border);
}
/* MARKER-PATCH-158-E1 */
.ma-asset-detach {
  background: transparent; border: 0;
  color: var(--ia-text-faint, #52525b);
  font-size: 14px;
  width: 26px; height: 26px;
  border-radius: 4px;
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
}
.ma-asset-detach:hover { color: #f87171; background: rgba(248,113,113,0.08); }
.ma-add-svc-btn {
  width: 100%;
  font: inherit; font-size: 12px;
  padding: 8px;
  background: transparent;
  border: 1px dashed var(--ia-border);
  border-radius: 6px;
  color: var(--ia-text-dim);
  cursor: pointer;
  margin-top: 8px;
}
.ma-add-svc-btn:hover {
  border-color: var(--ia-accent, #BEF264);
  color: var(--ia-accent, #BEF264);
  border-style: solid;
}
.ma-add-asset-btn {
  width: 100%;
  font: inherit; font-size: 13px;
  padding: 14px;
  background: transparent;
  border: 1px dashed var(--ia-border);
  border-radius: 10px;
  color: var(--ia-text-dim);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.ma-add-asset-btn:hover {
  border-color: var(--ia-accent, #BEF264);
  color: var(--ia-accent, #BEF264);
  border-style: solid;
}
.ma-asset-num {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600;
}
.ma-asset-name {
  font-size: 14px; font-weight: 500;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.ma-pill {
  font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
  padding: 2px 6px; border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-pill--persistent {
  background: rgba(190,242,100,0.1);
  color: var(--ia-accent, #BEF264);
  border: 1px solid rgba(190,242,100,0.3);
}
.ma-asset-meta {
  font-size: 12px; color: var(--ia-text-dim); margin-top: 2px;
}
.ma-asset-subtotal {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.ma-asset-subtotal-label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--ia-text-faint, #52525b);
  margin-bottom: 2px;
}

/* Services pinned to an asset */
.ma-asset-services { padding: 8px 18px 14px; }
.ma-service-row {
  display: grid;
  grid-template-columns: 1fr 78px 104px 26px; /* MARKER-PATCH-344 */
  gap: 10px;
  align-items: center;
  padding: 10px 12px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  margin-bottom: 6px;
}
.ma-service-row:last-child { margin-bottom: 0; }
.ma-service-name { font-size: 13px; }
.ma-service-meta { font-size: 11px; color: var(--ia-text-dim); margin-top: 1px; }
.ma-service-tag {
  font-size: 10px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
  padding: 2px 6px; border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-service-tag--addon {
  background: rgba(96,165,250,0.08);
  color: #93c5fd;
}
.ma-service-price {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  min-width: 70px;
  text-align: right;
}
.ma-service-empty {
  font-size: 12px; color: var(--ia-text-dim);
  padding: 8px 12px;
  font-style: italic;
}

/* "Loose" items section — services not pinned to any asset (back-compat) */
.ma-loose-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  margin-bottom: 12px;
  padding: 14px 18px;
}
.ma-loose-title {
  font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--ia-text-dim); margin-bottom: 12px;
}
/* MARKER-PATCH-470 — assign-later holding state */
.ma-loose-card--needs { border-color: rgba(245,158,11,.38); background: rgba(245,158,11,.06); }
.ma-loose-title--row { display:flex; align-items:center; gap:8px; text-transform:none; letter-spacing:0; }
.ma-loose-count { display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:rgba(255,255,255,.08);color:var(--ia-text-dim);font-size:11px;font-weight:600;margin-left:4px; }
.ma-loose-needs { color:#f59e0b;border:0.5px solid rgba(245,158,11,.45);border-radius:99px;padding:2px 9px;font-size:10px;font-weight:600;text-transform:none;letter-spacing:0; }
.ma-assign-loose-btn { margin-top:7px;padding:4px 10px;border-radius:7px;border:0.5px solid rgba(245,158,11,.4);background:rgba(245,158,11,.08);color:#f59e0b;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .12s; }
.ma-assign-loose-btn:hover { background:rgba(245,158,11,.16); }
.ma-assign-loose-list { display:flex;flex-direction:column;gap:7px; }
.ma-assign-opt { text-align:left;padding:12px 14px;border-radius:9px;border:1px solid var(--ia-border);background:var(--ia-surface,rgba(255,255,255,.02));color:var(--ia-text,#f0f0f0);font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;transition:all .12s; }
.ma-assign-opt:hover { border-color:var(--ia-accent,#BEF264);background:var(--ia-accent-soft,rgba(190,242,100,.08)); }
.ma-assign-opt { display:flex;flex-direction:column;gap:0; }
.ma-assign-opt-sub { font-size:11px;color:var(--ia-text-dim);font-weight:400;margin-top:2px; }
.ma-assign-opt--new { border-style:dashed;color:var(--ia-accent,#BEF264);font-weight:600; }
.ma-assign-sec-label { font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);margin:12px 2px 6px; }
.ma-assign-sec-label:first-child { margin-top:0; }
.ma-assign-opt--later { color:var(--ia-text-dim);border-style:dashed; }

/* Right rail cards */
.ma-rail { display: flex; flex-direction: column; gap: 12px; }
.ma-rail-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
}
.ma-rail-card-title {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--ia-text-faint, #52525b); margin-bottom: 10px;
}
.ma-rail-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 6px 0;
  border-top: 0.5px solid var(--ia-border);
  font-size: 13px;
}
.ma-rail-row:first-of-type { border-top: 0; padding-top: 0; }
.ma-rail-row .k { color: var(--ia-text-dim); }
.ma-rail-row .v { color: var(--ia-text); font-variant-numeric: tabular-nums; }
.ma-rail-row--total {
  border-top: 1px solid var(--ia-border);
  margin-top: 6px; padding-top: 10px; font-weight: 500;
}
.ma-rail-row--total .v { font-size: 16px; }
.ma-schedule-row {
  display: grid;
  grid-template-columns: 80px 1fr;
  padding: 6px 0;
  font-size: 13px;
  border-top: 0.5px solid var(--ia-border);
}
.ma-schedule-row:first-of-type { border-top: 0; padding-top: 0; }
.ma-schedule-row .lbl {
  color: var(--ia-text-faint, #52525b);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  align-self: center;
}

/* ============== MARKER-PATCH-158-F — Empty state ============== */
.ma-empty {
  text-align: center;
  padding: 48px 20px;
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px dashed var(--ia-border);
  border-radius: 10px;
  margin-bottom: 12px;
}
.ma-empty-icon {
  width: 48px; height: 48px;
  border-radius: 50%;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  margin: 0 auto 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: var(--ia-text-faint, #52525b);
}
.ma-empty-title { font-size: 14px; font-weight: 500; margin-bottom: 6px; }
.ma-empty-sub {
  font-size: 12.5px; color: var(--ia-text-dim);
  margin-bottom: 16px;
  max-width: 360px;
  margin-left: auto; margin-right: auto;
  line-height: 1.5;
}

/* ============== MARKER-PATCH-158-E2 — Status pipeline (mirrors legacy) ============== */
.ma-progress-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 18px 22px;
  margin-bottom: 16px;
}
.ma-progress-bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  position: relative;
  gap: 4px;
}
.ma-progress-bar::before {
  content: '';
  position: absolute;
  top: 12px; left: 12px; right: 12px;
  height: 2px; background: var(--ia-border);
  z-index: 0;
}
.ma-progress-bar::after {
  content: '';
  position: absolute;
  top: 12px; left: 12px;
  height: 2px; background: var(--ia-accent, #BEF264);
  z-index: 0;
  /* MARKER-PATCH-158-G1 — fixed overshoot: legacy uses fraction (0..1) of
     (100% - 24px) to account for the 12px padding on each side. */
  width: calc((100% - 24px) * var(--progress, 0));
  transition: width 0.3s;
}
.ma-progress-step {
  position: relative;
  z-index: 1;
  background: transparent;
  border: 0;
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  cursor: pointer;
  padding: 0;
  font: inherit;
  flex: 1;
}
.ma-progress-step:disabled { cursor: default; }
.ma-progress-dot {
  width: 26px; height: 26px;
  border-radius: 50%;
  background: var(--ia-surface, #111);
  border: 2px solid var(--ia-border);
  display: flex; align-items: center; justify-content: center;
  color: var(--ia-accent-text, #0a0a0a);
  transition: all 0.15s;
}
.ma-progress-step.is-done .ma-progress-dot {
  background: var(--ia-accent, #BEF264);
  border-color: var(--ia-accent, #BEF264);
}
.ma-progress-step.is-current .ma-progress-dot {
  border: 2px solid var(--ia-accent, #BEF264);
  background: var(--ia-surface, #111);
}
.ma-progress-dot-inner {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--ia-accent, #BEF264);
}
.ma-progress-label {
  font-size: 11px;
  color: var(--ia-text-dim);
  transition: color 0.15s;
}
.ma-progress-step.is-current .ma-progress-label {
  font-weight: 500;
  color: var(--ia-text);
}
.ma-progress-step:not(:disabled):hover .ma-progress-dot {
  border-color: var(--ia-accent, #BEF264);
}
.ma-progress-step.is-saving .ma-progress-dot { opacity: 0.5; }

.ma-terminal-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 12px;
}
.ma-terminal-icon {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  color: var(--ia-text-dim);
}
.ma-terminal-title { font-size: 13px; font-weight: 500; }

/* Inline edits + remove on service rows */
.ma-service-edit {
  width: 70px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 4px;
  color: var(--ia-text);
  font: inherit; font-size: 12.5px;
  padding: 3px 6px;
  text-align: right;
  font-variant-numeric: tabular-nums;
  -webkit-appearance: none; -moz-appearance: textfield; appearance: textfield; margin: 0;
}
/* MARKER-PATCH-344 — no spinner arrows, fields sized to content */
.ma-service-edit::-webkit-outer-spin-button,
.ma-service-edit::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.ma-service-edit[data-field="duration_minutes"] { width: 46px; }
.ma-service-edit[data-field="price_dollars"] { width: 80px; }
.ma-service-edit:hover { border-color: var(--ia-border); }
.ma-service-edit:focus {
  outline: none;
  border-color: var(--ia-accent, #BEF264);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.ma-service-remove {
  background: transparent; border: 0;
  color: var(--ia-text-faint, #52525b);
  font-size: 12px;
  width: 22px; height: 22px;
  border-radius: 3px;
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
}
.ma-service-remove:hover { color: #f87171; background: rgba(248,113,113,0.08); }

/* ============== MARKER-PATCH-158-E3 — Charges + Payment ============== */
.ma-charges-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-charges-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 14px;
}
.ma-add-charge-form {
  margin-bottom: 14px;
  padding-bottom: 14px;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-charge-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 0;
  border-bottom: 0.5px solid var(--ia-border);
  font-size: 13px;
}
.ma-charge-row:last-child { border-bottom: 0; }

/* ============== MARKER-PATCH-158-G4 — Per-asset Parts section ============== */

/* The collapsible Parts section lives inside each asset card, just below
   the services list. <details> drives the open/closed state with no JS. */
.ma-asset-parts {
  border-top: 0.5px solid var(--ia-border);
  margin-top: 8px;
}
/* MARKER-PATCH-158-G6 — Horizontal padding so the collapsible content
   doesn't sit flush against the asset card edges. Matches .ma-asset-head's
   18px so labels/inputs align vertically with the card title above. */
/* MARKER-PATCH-158-G7 — Symmetric vertical padding + explicit min-height
   so the Parts head and the Work order head render exactly the same
   height when stacked. */
.ma-asset-parts-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  min-height: 40px;
  box-sizing: border-box;
  cursor: pointer;
  user-select: none;
  list-style: none;
}
.ma-asset-parts-head::-webkit-details-marker { display: none; }
.ma-asset-parts-title {
  font-size: 12px;
  font-weight: 500;
  color: var(--ia-text);
}
.ma-asset-parts-count {
  font-size: 11px;
  font-weight: 500;
  padding: 1px 7px;
  background: var(--ia-surface-2, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
  border-radius: 9px;
  font-variant-numeric: tabular-nums;
}
.ma-asset-parts-chev {
  margin-left: auto;
  font-size: 12px;
  color: var(--ia-text-faint, #52525b);
  transition: transform 0.15s;
}
.ma-asset-parts[open] .ma-asset-parts-chev { transform: rotate(180deg); }

.ma-asset-parts-body {
  padding: 4px 18px 12px;
}
.ma-asset-parts-empty {
  font-size: 12px;
  opacity: .4;
  margin: 4px 0 12px;
}

/* Per-asset picker: same styles as the loose .ma-part-picker, but scoped */
.ma-asset-part-pickerwrap {
  position: relative;
  margin-top: 10px;
}
.ma-asset-part-pickerwrap .ia-input { width: 100%; }
.ma-asset-part-results {
  position: absolute;
  top: 100%; left: 0; right: 0;
  margin-top: 4px;
  background: var(--ia-surface, #111);
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  max-height: 280px;
  overflow-y: auto;
  z-index: 20;
}
.ma-asset-part-results[hidden] { display: none; }

.ma-asset-custom-form {
  margin-top: 10px;
  padding: 12px;
  border: 0.5px solid var(--ia-border);
  border-radius: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.ma-asset-custom-form[hidden] { display: none; }
.ma-asset-custom-form-head {
  font-size: 12px;
  font-weight: 500;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.ma-asset-custom-grid {
  display: grid;
  grid-template-columns: 1.6fr 0.7fr 0.5fr auto;
  gap: 8px;
  align-items: end;
}

/* ============== MARKER-PATCH-158-G5 — Per-asset Work order section ============== */
.ma-asset-wo {
  border-top: 0.5px solid var(--ia-border);
  margin-top: 8px;
}
.ma-asset-wo .ma-asset-parts-head { /* reuse parts head styles */ }
.ma-asset-wo-body {
  /* MARKER-PATCH-158-G6 — Horizontal padding so form fields don't touch
     the asset card edges. Matches .ma-asset-parts-body. */
  padding: 4px 18px 12px;
}
.ma-asset-wo-empty {
  font-size: 12px;
  opacity: .5;
  margin: 4px 0 12px;
}
.ma-asset-wo-id-block {
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-asset-wo-id-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--ia-text-faint, #52525b);
  font-weight: 500;
  margin-bottom: 4px;
}
.ma-asset-wo-id-value {
  font-family: ui-monospace, 'SF Mono', monospace;
  font-size: 15px;
  font-weight: 500;
  letter-spacing: 0.02em;
}
.ma-asset-wo-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px 24px;
}
.ma-asset-wo-field-value { font-size: 13px; }
.ma-asset-wo-edit-form[hidden] { display: none; }
.ma-wo-id-pill {
  background: var(--ia-accent, #BEF264);
  color: var(--ia-accent-text, #0a0a0a);
  font-size: 9px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 3px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-left: 6px;
}

/* ============== MARKER-PATCH-158-E4 — Parts card + table (reused by G4 Unassigned section) ============== */
.ma-parts-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-parts-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ma-parts-table th {
  font-size: 10.5px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ia-text-faint, #52525b);
  padding: 6px 0;
  text-align: left;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-parts-table th.num { text-align: right; }
.ma-parts-table td {
  padding: 10px 0;
  border-bottom: 0.5px solid var(--ia-border);
  vertical-align: middle;
}
.ma-parts-table td.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-parts-table tr:last-child td { border-bottom: 0; }
.ma-part-qty-edit {
  width: 60px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 4px;
  color: var(--ia-text);
  font: inherit; font-size: 12.5px;
  padding: 3px 6px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-part-qty-edit:hover { border-color: var(--ia-border); }
.ma-part-qty-edit:focus {
  outline: none;
  border-color: var(--ia-accent, #BEF264);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.ma-part-qty-edit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.ma-part-picker-result {
  padding: 8px 12px;
  cursor: pointer;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-part-picker-result:last-child { border-bottom: 0; }
.ma-part-picker-result:hover,
.ma-part-picker-result.is-active {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
}
.ma-part-picker-result .name {
  font-size: 13px;
}
.ma-part-picker-result .meta {
  font-size: 11px;
  color: var(--ia-text-dim);
  margin-top: 2px;
  display: flex;
  justify-content: space-between;
}
.ma-part-picker-custom {
  padding: 10px 12px;
  cursor: pointer;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border-top: 0.5px solid var(--ia-border);
  font-size: 12.5px;
  color: var(--ia-accent, #BEF264);
}
.ma-part-picker-custom:hover {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
}
.ma-part-picker-empty {
  padding: 12px;
  text-align: center;
  font-size: 12px;
  color: var(--ia-text-dim);
}

/* ============== MARKER-PATCH-158-E5 — Work order + Notes ============== */
.ma-wo-card,
.ma-notes-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-wo-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 14px;
  padding-bottom: 10px;
  border-bottom: 0.5px solid var(--ia-border);
}

/* Notes list styling — mirrors legacy ia-note look */
.ma-note {
  padding: 10px 0;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-note:first-child { padding-top: 0; }
.ma-note:last-child { border-bottom: 0; padding-bottom: 0; }
.ma-note-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
  font-size: 11.5px;
}
.ma-note-author {
  font-weight: 500;
  color: var(--ia-text);
}
.ma-note-time {
  color: var(--ia-text-faint, #52525b);
}
.ma-note-visibility {
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-note-visibility--customer {
  background: rgba(96, 165, 250, 0.10);
  color: #93c5fd;
}
.ma-note-delete {
  background: transparent;
  border: 0;
  color: var(--ia-text-faint, #52525b);
  font-size: 11px;
  width: 18px; height: 18px;
  border-radius: 3px;
  cursor: pointer;
  margin-left: auto;
  display: inline-flex; align-items: center; justify-content: center;
}
.ma-note-delete:hover { color: #f87171; background: rgba(248, 113, 113, 0.08); }
.ma-note-body {
  font-size: 13px;
  white-space: pre-wrap;
  line-height: 1.5;
}
.ma-notes-empty {
  font-size: 13px;
  opacity: .4;
  margin: 0;
}

/* ============== MARKER-PATCH-158-E6 — Special orders + polish ============== */
.ma-so-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-so-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 14px;
}
.ma-so-warning {
  background: rgba(245, 158, 11, 0.08);
  border-radius: 6px;
  padding: 10px 12px;
  margin-bottom: 12px;
  font-size: 12.5px;
  line-height: 1.5;
}
.ma-so-warning strong { color: #F59E0B; }
.ma-so-warning span { color: var(--ia-text-dim); }
.ma-so-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ma-so-table th {
  font-size: 10.5px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ia-text-faint, #52525b);
  padding: 6px 0;
  text-align: left;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-so-table th.num { text-align: right; }
.ma-so-table td {
  padding: 10px 0;
  border-bottom: 0.5px solid var(--ia-border);
  vertical-align: middle;
}
.ma-so-table td.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-so-table tr:last-child td { border-bottom: 0; }
.ma-so-table tbody tr:hover {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
}
.ma-so-status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 10.5px; font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.ma-so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
.ma-so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
.ma-so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent, #BEF264); }
.ma-so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-dim); }
.ma-so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
.ma-so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }

/* System notes — visually differentiated as activity-log entries */
.ma-note--system {
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border-radius: 6px;
  padding: 8px 12px;
  margin: 4px 0;
  border-bottom: 0 !important;
}
.ma-note--system + .ma-note:not(.ma-note--system) { margin-top: 10px; }
.ma-note--system .ma-note-author {
  font-size: 10.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ia-text-faint, #52525b);
}
.ma-note--system .ma-note-body {
  font-size: 12px;
  color: var(--ia-text-dim);
}

/* ============== MARKER-PATCH-158-G1 — Sale callout banners ============== */
.ma-sale-banner {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
  margin-bottom: 16px;
  border-radius: 8px;
}
.ma-sale-banner-icon {
  font-size: 20px;
  line-height: 1;
}
.ma-sale-banner-body { flex: 1; min-width: 0; }
.ma-sale-banner-title {
  font-weight: 500;
  font-size: 13px;
  color: var(--ia-text);
}
.ma-sale-banner-sub {
  font-size: 12px;
  color: var(--ia-text-dim);
  margin-top: 2px;
}
.ma-sale-banner--checkout {
  background: rgba(251, 191, 36, 0.10);
  border: 0.5px solid rgba(251, 191, 36, 0.35);
}
.ma-sale-banner--paid {
  background: rgba(132, 204, 22, 0.08);
  border: 0.5px solid rgba(132, 204, 22, 0.30);
}
.ma-sale-banner--overage {
  background: rgba(251, 191, 36, 0.10);
  border: 0.5px solid rgba(251, 191, 36, 0.45);
}

/* MARKER-PATCH-158-G2 — Rail action stack (reschedule + cancel) */
.ma-rail-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 4px;
}

/* MARKER-PATCH-158-G3 — Cancel button dark-red theme (mirrors legacy CANCEL-RED-DARK).
   Without this, ia-btn--danger renders too light against the dark surface. */
.ma-cancel-btn.ia-btn--danger,
button.ma-cancel-btn {
  background: #6B1F1F !important;
  color: #FFD0D0 !important;
  border: 1px solid #8C2C2C !important;
}
.ma-cancel-btn.ia-btn--danger:hover,
button.ma-cancel-btn:hover {
  background: #8C2C2C !important;
  color: #FFE5E5 !important;
}

/* Payment status badge */
.ma-payment-badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 2px 8px;
  border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-payment-badge--paid {
  background: rgba(74, 222, 128, 0.12);
  color: #86efac;
}
.ma-payment-badge--partial,
.ma-payment-badge--deposit_paid {
  background: rgba(251, 191, 36, 0.12);
  color: #fcd34d;
}
.ma-payment-badge--unpaid {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-payment-badge--refunded {
  background: rgba(248, 113, 113, 0.10);
  color: #fca5a5;
}

/* Asset name inline edit */
/* MARKER-PATCH-158-E4 — fixed CSS specificity so input doesn't pick up
   browser/ia-input default white background. Higher specificity + !important
   on the visual properties because ia-input wins generic selectors. */
.ma-asset .ma-asset-name-edit,
input.ma-asset-name-edit {
  background: transparent !important;
  border: 1px solid transparent !important;
  border-radius: 4px;
  color: var(--ia-text) !important;
  font: inherit;
  font-size: 14px !important;
  font-weight: 500 !important;
  font-family: var(--ia-font, inherit) !important;
  padding: 2px 6px;
  width: 100%;
  max-width: 100%;
  box-shadow: none !important;
  -webkit-appearance: none;
  appearance: none;
}
.ma-asset .ma-asset-name-edit:hover,
input.ma-asset-name-edit:hover {
  border-color: var(--ia-border) !important;
}
.ma-asset .ma-asset-name-edit:focus,
input.ma-asset-name-edit:focus {
  outline: none !important;
  border-color: var(--ia-accent, #BEF264) !important;
  background: var(--ia-surface-2, rgba(255,255,255,0.02)) !important;
}

/* ============== MARKER-PATCH-158-E1 — Modals ============== */
.ma-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.6);
  z-index: 999;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.ma-modal-backdrop.is-open { display: flex; }
.ma-modal {
  background: var(--ia-surface, #111);
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  width: 540px;
  max-width: 100%;
  overflow: hidden;
  display: flex; flex-direction: column;
  max-height: 90vh;
}
.ma-modal-head {
  padding: 16px 20px;
  border-bottom: 1px solid var(--ia-border);
  display: flex; justify-content: space-between; align-items: center;
}
.ma-modal-title { font-size: 14px; font-weight: 500; }
.ma-modal-close {
  background: transparent; border: 0;
  color: var(--ia-text-dim);
  cursor: pointer;
  font-size: 16px;
  padding: 4px 8px;
  border-radius: 4px;
}
.ma-modal-close:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); color: var(--ia-text); }
.ma-modal-body {
  padding: 18px 20px;
  overflow-y: auto;
  flex: 1;
}
.ma-modal-foot {
  padding: 12px 20px;
  border-top: 1px solid var(--ia-border);
  display: flex; justify-content: flex-end; gap: 8px;
}

/* Tabs */
.ma-tabs {
  display: flex;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  padding: 3px;
  margin-bottom: 14px;
}
.ma-tab {
  flex: 1;
  padding: 7px 10px;
  border: 0;
  background: transparent;
  border-radius: 4px;
  font: inherit; font-size: 12px;
  color: var(--ia-text-dim);
  cursor: pointer;
}
.ma-tab.is-active {
  background: var(--ia-surface, #111);
  color: var(--ia-text);
}
.ma-tab-panel { display: none; }
.ma-tab-panel.is-active { display: block; }

/* Picker list (existing assets) */
.ma-picker-list {
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  max-height: 260px;
  overflow-y: auto;
}
.ma-picker-row {
  padding: 10px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex; align-items: center; gap: 12px;
  cursor: pointer;
}
.ma-picker-row:last-child { border-bottom: 0; }
.ma-picker-row:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); }
.ma-picker-radio {
  width: 14px; height: 14px;
  accent-color: var(--ia-accent, #BEF264);
  cursor: pointer;
}
.ma-picker-main { flex: 1; min-width: 0; }
.ma-picker-name { font-size: 13px; }
.ma-picker-meta { font-size: 11px; color: var(--ia-text-dim); margin-top: 1px; }

/* Catalog list (services / addons) */
.ma-catalog-list {
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  max-height: 300px;
  overflow-y: auto;
}
.ma-catalog-row {
  padding: 9px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex; align-items: center; gap: 12px;
  cursor: pointer;
}
.ma-catalog-row:last-child { border-bottom: 0; }
.ma-catalog-row:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); }
.ma-catalog-main { flex: 1; min-width: 0; }
.ma-catalog-name { font-size: 13px; }
.ma-catalog-meta { font-size: 11px; color: var(--ia-text-dim); margin-top: 1px; }
.ma-catalog-price {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  min-width: 70px;
  text-align: right;
}

/* Form rows in modals */
.ma-form-row { margin-bottom: 14px; }
.ma-form-row:last-child { margin-bottom: 0; }
.ma-form-label {
  display: block;
  font-size: 12px;
  color: var(--ia-text-dim);
  margin-bottom: 5px;
}
/* MARKER-PATCH-419 — per-line special-order checkbox */
.ma-part-so { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 11px; color: var(--ia-text-dim); cursor: pointer; user-select: none; }
.ma-part-so input { width: 13px; height: 13px; accent-color: var(--ia-accent, #BEF264); cursor: pointer; margin: 0; }
.ma-part-so-badge { font-family: ui-monospace, 'SF Mono', monospace; font-size: 10px; letter-spacing: .02em; color: var(--ia-accent, #BEF264); opacity: .85; }
.ma-part-so-badge:empty { display: none; }
/* MARKER-PATCH-424 — mobile contact actions in the appointment customer tile */
.ma-mcontact { display: none; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.ma-mcontact-tile { display: flex; flex-direction: column; align-items: center; gap: 4px; background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: 10px; padding: 12px 6px; color: var(--ia-text); text-decoration: none; -webkit-tap-highlight-color: transparent; }
.ma-mcontact-tile svg { color: var(--ia-accent); }
.ma-mcontact-tile:active { transform: scale(0.97); }
.ma-mcontact-label { font-size: 11px; color: var(--ia-text-muted); font-weight: 500; }
.ma-mcontact-tile.is-disabled { opacity: .35; pointer-events: none; }
.ma-mcontact-tile.is-disabled svg { color: var(--ia-text-muted); }
.ma-mcontact-view { display: none; margin-top: 12px; padding-bottom: 12px; text-align: center; font-size: 12px; color: var(--ia-accent); text-decoration: none; }
@media (max-width: 640px) {
  #ma-appt .ma-top-customer { display: none; }
  #ma-appt .ma-top-resource { display: none; }
  #ma-appt .ma-mcontact { display: grid; }
  #ma-appt .ma-mcontact-view { display: block; }
}
/* MARKER-PATCH-425 — mobile: single full-width Cancel; reschedule lives in the hero */
@media (max-width: 640px) {
  #ma-appt .ma-top-actions .appt-b-reschedule-btn { display: none; }
}
</style>
@endpush

@section('mobile-back', 'Appointments|' . route('tenant.appointments.index'))

@section('content')
{{-- MARKER-PATCH-158-G8 — Removed max-width:1400px + margin:0 auto. No other tenant page uses centered wrapper; this one shouldn't either. --}}
<div class="ia-page" id="ma-appt" style="padding: 24px 28px 60px;">

  {{-- Header --}}
  <div class="ma-page-head">
    <div>
      <div class="ma-page-eyebrow">Appointment</div>
      <h1 class="ma-page-title">
        <span class="ma-ra-number">{{ $appointment->ra_number }}</span>
        <span class="ma-status-pill ma-status-pill--{{ $appointment->status }}">{{ $statusLabels[$appointment->status] ?? $appointment->status }}</span>
      </h1>
      <div class="ma-page-sub">
        <span>{{ $appointment->appointment_date->format('D M j') }}@if($appointment->appointment_time), {{ \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A') }}@endif</span>
        @if($appointment->resource)
          <span class="dot">·</span>
          <span>{{ $appointment->resource->name }}</span>
        @endif
        <span class="dot">·</span>
        <span>{{ $appointmentAssets->count() }} {{ \Illuminate\Support\Str::plural('asset', $appointmentAssets->count()) }} · {{ $serviceCount + $addonCount }} {{ \Illuminate\Support\Str::plural('service', $serviceCount + $addonCount) }}</span>
      </div>
    </div>
    {{-- MARKER-PATCH-205 — invoice export trigger --}}
    <div class="ma-page-actions">
      {{-- MARKER-PATCH-313 --}}
      {{-- MARKER-PATCH-315 — gated on the tag enable toggle --}}
      @if(data_get(tenant()->settings, 'work_order_tag.enabled', true))
      <button type="button" class="ia-btn ia-btn--secondary" onclick="window.openPrintComposer ? openPrintComposer('appointment', '{{ $appointment->id }}', {type:'tag', number:'{{ $appointment->ra_number }}'}) : openTagModal()">&#9113; Print &amp; Send</button>{{-- MARKER-PATCH-339 --}}
      @endif
      {{-- MARKER-PATCH-347 — Invoice button removed; Print & Send (Invoice → "Graphical invoice") covers this. --}}
    </div>
  </div>

  {{-- MARKER-PATCH-347 — _invoice-modal include removed with the Invoice button. --}}
  {{-- MARKER-PATCH-314 --}}
  @include('tenant.appointments._tag_modal')

  {{-- MARKER-PATCH-414 — mobile summary hero (phone only) --}}
  @php $mBalance = max(0, (int) $appointment->total_cents - (int) $appointment->paid_cents); @endphp
  <div class="ma-mhero">
    <div class="ma-mhero-ra">{{ $appointment->ra_number }}</div>
    <div class="ma-mhero-top">
      <div>
        <div class="ma-mhero-cust">{{ $appointment->customer ? trim($appointment->customer->fullName()) : 'Walk-in' }}</div>
        <div class="ma-mhero-when">{{ $appointment->appointment_date->format('D, M j') }}@if($appointment->appointment_time) · {{ \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A') }}@endif @if($appointment->resource) · {{ $appointment->resource->name }}@endif</div>
      </div>
      <span class="ma-status-pill ma-status-pill--{{ $appointment->status }}">{{ $statusLabels[$appointment->status] ?? $appointment->status }}</span>
    </div>
    @if($mBalance > 0)
      <div class="ma-mhero-bal"><span class="l">Balance due</span><span class="a">{{ format_money($mBalance) }}</span></div>
    @elseif((int) $appointment->total_cents > 0)
      <div class="ma-mhero-bal ma-mhero-bal--paid"><span class="l">Paid in full</span><span class="a">{{ format_money($appointment->total_cents) }}</span></div>
    @endif
    {{-- MARKER-PATCH-417 — primary actions inside the hero (app has its own bottom nav) --}}
    <div class="ma-mbar">
      @if(data_get(tenant()->settings, 'work_order_tag.enabled', true))
      <button type="button" class="ia-btn ia-btn--primary" onclick="window.openPrintComposer ? openPrintComposer('appointment', '{{ $appointment->id }}', {type:'tag', number:'{{ $appointment->ra_number }}'}) : openTagModal()">&#9113; Print &amp; Send</button>
      @endif
      @unless($isTerminal)
      <button type="button" class="ia-btn ia-btn--secondary" style="background:#ffffff;color:#0d0d0d;border-color:#ffffff;" onclick="var b=document.querySelector('.appt-b-reschedule-btn'); if(b) b.click();">&#8635; Reschedule</button>
      @endunless
    </div>
  </div>

  {{-- MARKER-PATCH-414 — segmented control (phone only) --}}
  <div class="ma-mtabs">
    <button type="button" class="on" onclick="maTab(this,'overview')">Overview</button>
    <button type="button" onclick="maTab(this,'items')">Items</button>
    <button type="button" onclick="maTab(this,'notes')">Notes</button>
    <button type="button" onclick="maTab(this,'pay')">Pay</button>
  </div>
  <script>
    (function(){ var p = document.getElementById('ma-appt'); if (p && !p.getAttribute('data-mtab')) p.setAttribute('data-mtab','overview'); })();
    function maTab(btn, tab){
      var p = document.getElementById('ma-appt'); if (p) p.setAttribute('data-mtab', tab);
      btn.parentNode.querySelectorAll('button').forEach(function(b){ b.classList.remove('on'); });
      btn.classList.add('on');
    }
  </script>

  {{-- MARKER-PATCH-158-G1 — Sale callout banners (mirrors legacy bannerSale) --}}
  @php
    $bannerPendingLink = $appointment->pendingPaymentLinkSale(); // MARKER-PATCH-196
    $bannerSale     = $appointment->openRegisterSale();
    $bannerBalance  = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
    $bannerOverage  = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
    // MARKER-PATCH-158-G12 — Derive "paid in full" from actual cents, not the
    // payment_status column. The column gets set when a deposit equals the
    // total at that moment, but new charges/parts/tax can push total higher
    // without the column ever being updated. Only show "Paid in full" when
    // there's actually no balance owed AND the total is non-zero.
    $bannerPaidFull = ((int)$appointment->total_cents > 0)
                      && ((int)$appointment->paid_cents >= (int)$appointment->total_cents)
                      && ($bannerOverage === 0);
    // MARKER-PATCH-461 — a real Stripe charge means a 'card' overage refund is
    // possible; otherwise the refund is cash/check/store-credit/mark-paid only.
    $bannerHasStripeCharge = $appointment->sales()->whereNotNull('stripe_payment_intent_id')->exists();
  @endphp
  @if($bannerPendingLink)
    {{-- MARKER-PATCH-196 — a payment link is out and awaiting the customer. --}}
    <div class="ma-sale-banner" style="background:rgba(96,165,250,.10);border:0.5px solid rgba(96,165,250,.35)">
      <span class="ma-sale-banner-icon">🔗</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Payment link sent — awaiting customer · {{ format_money($bannerPendingLink->total_cents) }}</div>
        <div class="ma-sale-banner-sub">
          Sale {{ $bannerPendingLink->sale_number ?? 'pending' }} · the customer can pay on their own time; this updates automatically when they do.
        </div>
      </div>
      <a href="{{ route('tenant.register.index', []) }}?status={{ $bannerPendingLink->id }}"
         class="ia-btn ia-btn--ghost ia-btn--sm">View status →</a>
    </div>
  @elseif($bannerSale)
    <div class="ma-sale-banner ma-sale-banner--checkout">
      <span class="ma-sale-banner-icon">💳</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Ready for checkout — ${{ number_format($bannerBalance / 100, 2) }}</div>
        <div class="ma-sale-banner-sub">
          Sale {{ $bannerSale->sale_number }} parked in the register for
          {{ trim(($appointment->customer->first_name ?? '') . ' ' . ($appointment->customer->last_name ?? '')) ?: 'this customer' }}.
        </div>
      </div>
      <a href="{{ route('tenant.register.index', []) }}?resume={{ $bannerSale->id }}"
         class="ia-btn ia-btn--primary ia-btn--sm">Open in register →</a>
    </div>
  @elseif($bannerPaidFull)
    <div class="ma-sale-banner ma-sale-banner--paid">
      <span class="ma-sale-banner-icon">✅</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Paid in full — ${{ number_format(($appointment->paid_cents ?? 0) / 100, 2) }}</div>
        <div class="ma-sale-banner-sub">
          @if($appointment->payments()->count() === 1 && $appointment->payments()->first()->kind === 'deposit')
            Customer prepaid before service. No checkout needed.
          @else
            {{ $appointment->payments()->count() }} {{ $appointment->payments()->count() === 1 ? 'payment' : 'payments' }} on file.
          @endif
        </div>
      </div>
    </div>
  @elseif($bannerOverage > 0)
    <div class="ma-sale-banner ma-sale-banner--overage">
      <span class="ma-sale-banner-icon">⚠</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Customer overpaid — ${{ number_format($bannerOverage / 100, 2) }}</div>
        <div class="ma-sale-banner-sub">Refund the overage or adjust the total.</div>
      </div>
      {{-- MARKER-PATCH-461 — record an overage refund (writes overage_refund row + cascades paid cache) --}}
      <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" onclick="maOpenOverageRefund()">Record refund</button>
    </div>

    {{-- MARKER-PATCH-461 — overage refund modal (only present when an overage exists) --}}
    <div id="ma-overage-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)maCloseOverageRefund()">
      <div style="background:#1a1a1a;border:0.5px solid rgba(255,255,255,.12);border-radius:14px;max-width:420px;width:100%;padding:22px;font-family:inherit;color:#f0f0f0;">
        <div style="font-size:16px;font-weight:600;margin-bottom:4px;">Record overage refund</div>
        <div style="font-size:12.5px;color:rgba(255,255,255,.5);margin-bottom:16px;">Customer overpaid by ${{ number_format($bannerOverage / 100, 2) }}. Return all or part of it.</div>

        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.5);margin-bottom:6px;">Amount</label>
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;">
          <span style="opacity:.5;">$</span>
          <input id="ma-overage-amount" type="number" min="0.01" step="0.01" max="{{ number_format($bannerOverage / 100, 2, '.', '') }}" value="{{ number_format($bannerOverage / 100, 2, '.', '') }}"
                 style="flex:1;padding:9px 12px;background:rgba(255,255,255,.05);border:0.5px solid rgba(255,255,255,.12);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;">
        </div>

        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.5);margin-bottom:6px;">Method</label>
        <select id="ma-overage-method" onchange="maOverageMethodChanged()" style="width:100%;padding:9px 12px;background:rgba(255,255,255,.05);border:0.5px solid rgba(255,255,255,.12);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;margin-bottom:8px;">
          <option value="cash">Cash</option>
          <option value="check">Check</option>
          <option value="store_credit">Store credit</option>
          <option value="mark_paid">Mark paid (bookkeeping only)</option>
          <option value="card">Card</option>
        </select>
        <div id="ma-overage-card-hint" style="display:none;font-size:11.5px;color:#FBBF24;margin:0 0 14px;line-height:1.4;">No card charge on file for this appointment — this records a card refund without sending money through Stripe.</div>

        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.5);margin-bottom:6px;">Note (optional)</label>
        <input id="ma-overage-note" type="text" maxlength="500" placeholder="e.g. returned cash at pickup"
               style="width:100%;padding:9px 12px;background:rgba(255,255,255,.05);border:0.5px solid rgba(255,255,255,.12);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;margin-bottom:8px;">

        <div id="ma-overage-msg" style="font-size:12.5px;min-height:16px;margin-bottom:10px;"></div>

        <div style="display:flex;gap:10px;">
          <button type="button" class="ia-btn ia-btn--ghost" style="flex:1;" onclick="maCloseOverageRefund()">Cancel</button>
          <button type="button" id="ma-overage-submit" class="ia-btn ia-btn--primary" style="flex:1;" onclick="maSubmitOverageRefund()">Record refund</button>
        </div>
      </div>
    </div>

    <script>
      (function(){
        var OVERAGE_CENTS = {{ (int) $bannerOverage }};
        var HAS_STRIPE = {{ $bannerHasStripeCharge ? 'true' : 'false' }};
        var TOKEN = {!! json_encode(csrf_token()) !!};
        var URL = {!! json_encode(route('tenant.register.appointment-overage-refund')) !!};
        var APPT = {!! json_encode($appointment->id) !!};
        window.maOverageMethodChanged = function(){
          var m = document.getElementById('ma-overage-method').value;
          document.getElementById('ma-overage-card-hint').style.display = (m === 'card' && !HAS_STRIPE) ? 'block' : 'none';
        };
        window.maOpenOverageRefund  = function(){ document.getElementById('ma-overage-modal').style.display = 'flex'; window.maOverageMethodChanged(); };
        window.maCloseOverageRefund = function(){ document.getElementById('ma-overage-modal').style.display = 'none'; };
        window.maSubmitOverageRefund = function(){
          var msg = document.getElementById('ma-overage-msg');
          var btn = document.getElementById('ma-overage-submit');
          msg.style.color = 'rgba(255,255,255,.6)';
          var dollars = parseFloat(document.getElementById('ma-overage-amount').value);
          if (isNaN(dollars) || dollars <= 0) { msg.style.color = '#F09595'; msg.textContent = 'Enter an amount greater than zero.'; return; }
          var cents = Math.round(dollars * 100);
          if (cents > OVERAGE_CENTS) { msg.style.color = '#F09595'; msg.textContent = 'That is more than the overage.'; return; }
          var method = document.getElementById('ma-overage-method').value;
          var notes  = document.getElementById('ma-overage-note').value;
          btn.disabled = true; msg.textContent = 'Recording…';
          fetch(URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
            body: JSON.stringify({ appointment_id: APPT, amount_cents: cents, method: method, notes: notes })
          }).then(function(r){ return r.json().then(function(b){ return { ok: r.ok, b: b }; }); })
            .then(function(res){
              if (res.ok && res.b.ok) { window.location.reload(); return; }
              btn.disabled = false; msg.style.color = '#F09595';
              msg.textContent = (res.b && res.b.error) ? res.b.error : 'Something went wrong.';
            }).catch(function(){ btn.disabled = false; msg.style.color = '#F09595'; msg.textContent = 'Network error. Try again.'; });
        };
      })();
    </script>
  @endif

  {{-- MARKER-PATCH-158-G3 — Top row: status pipeline (left) + customer/resource/actions tile (right) --}}
  @php
    $maCurrentResource = $availableResources->firstWhere('id', $appointment->resource_id);
    $maInitials = $appointment->customer
      ? strtoupper(substr($appointment->customer->first_name ?? '?', 0, 1) . substr($appointment->customer->last_name ?? '', 0, 1))
      : '?';
  @endphp
  <div class="ma-top-row">

    {{-- LEFT: Status pipeline (or terminal card) --}}
    @if($isTerminal)
      <div class="ma-top-tile ma-terminal-card">
        <div class="ma-terminal-icon">
          @if($appointment->status === 'cancelled')✕@else↩@endif
        </div>
        <div class="ma-terminal-title">{{ $statusLabels[$appointment->status] ?? $appointment->status }}</div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-status="pending" id="ma-reopen-btn" style="margin-left:auto;">
          Reopen
        </button>
      </div>
    @else
      <div class="ma-top-tile ma-progress-card">
        <div class="ma-top-tile-label">Status</div>
        <div class="ma-progress-bar"
             data-current-index="{{ $currentIndex }}"
             data-update-url="{{ $updateUrl }}"
             style="--progress: {{ count($pipelineSteps) > 1 ? $currentIndex / (count($pipelineSteps) - 1) : 0 }};">
          @foreach($pipelineSteps as $idx => $step)
            @php
              $stepLabel = $statusLabels[$step] ?? $step;
              $isDone    = $idx < $currentIndex;
              $isCurrent = $idx === $currentIndex;
            @endphp
            <button type="button"
                    class="ma-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                    data-status="{{ $step }}"
                    data-step-index="{{ $idx }}"
                    data-label="{{ $stepLabel }}">
              <span class="ma-progress-dot">
                @if($isDone)
                  <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif($isCurrent)
                  <span class="ma-progress-dot-inner"></span>
                @endif
              </span>
              <span class="ma-progress-label">{{ $stepLabel }}</span>
            </button>
          @endforeach
        </div>
      </div>
    @endif

    {{-- RIGHT: Customer + resource + actions tile --}}
    <div class="ma-top-tile" data-appt-resource-card data-appt-id="{{ $appointment->id }}">
      @if($appointment->customer)
        <div class="ma-top-customer">
          <div class="ma-customer-avatar">{{ $maInitials }}</div>
          <div class="ma-top-customer-main">
            <div class="ma-customer-name">{{ $appointment->customer->fullName() }}</div>
            <div class="ma-customer-meta">
              @if($appointment->customer->email)<span>{{ $appointment->customer->email }}</span>@endif
              @if($appointment->customer->email && $appointment->customer->phone)<span class="sep">·</span>@endif
              @if($appointment->customer->phone)<span>{{ $appointment->customer->phone }}</span>@endif
            </div>
          </div>
          <a href="{{ route('tenant.customers.show', $appointment->customer->id) }}"
             class="ma-top-view-link">View →</a>
        </div>
        {{-- MARKER-PATCH-424 — mobile contact actions (replace the hero-repeat on phones) --}}
        <div class="ma-mcontact">
          <a href="{{ $appointment->customer->phone ? 'tel:' . preg_replace('/[^0-9+]/', '', $appointment->customer->phone) : '#' }}" class="ma-mcontact-tile {{ $appointment->customer->phone ? '' : 'is-disabled' }}" {{ $appointment->customer->phone ? '' : 'aria-disabled=true' }}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            <span class="ma-mcontact-label">Call</span>
          </a>
          <a href="{{ $appointment->customer->phone ? 'sms:' . preg_replace('/[^0-9+]/', '', $appointment->customer->phone) : '#' }}" class="ma-mcontact-tile {{ $appointment->customer->phone ? '' : 'is-disabled' }}" {{ $appointment->customer->phone ? '' : 'aria-disabled=true' }}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span class="ma-mcontact-label">Text</span>
          </a>
          <a href="{{ $appointment->customer->email ? 'mailto:' . $appointment->customer->email : '#' }}" class="ma-mcontact-tile {{ $appointment->customer->email ? '' : 'is-disabled' }}" {{ $appointment->customer->email ? '' : 'aria-disabled=true' }}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span class="ma-mcontact-label">Email</span>
          </a>
        </div>
        <a href="{{ route('tenant.customers.show', $appointment->customer->id) }}" class="ma-mcontact-view">View profile →</a>
      @endif

      {{-- Resource picker (data attrs match legacy so appointment-resource.js auto-binds) --}}
      <div class="ma-top-resource">
        <div class="ma-top-tile-label" style="margin-bottom: 6px;">Resource</div>
        <div class="ma-top-resource-row">
          @if($maCurrentResource)
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $maCurrentResource->color_hex ?: '#888' }};flex-shrink:0;"></span>
          @else
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#666;flex-shrink:0;"></span>
          @endif
          <select class="ia-input ia-input--sm" data-appt-resource-select style="flex: 1; min-width: 0;">
            @foreach($availableResources as $r)
              <option value="{{ $r->id }}" @selected($r->id === $appointment->resource_id)>
                {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
              </option>
            @endforeach
          </select>
          <button type="button"
                  class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-appt-resource-save
                  style="flex-shrink: 0;">Save</button>
        </div>
      </div>

      {{-- Actions (reschedule + cancel) --}}
      @unless($isTerminal)
        <div class="ma-top-actions">
          <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-b-reschedule-btn" style="flex: 1;">↻ Reschedule</button>
          <button type="button" class="ia-btn ia-btn--danger ia-btn--sm ma-cancel-btn" style="flex: 1;">Cancel</button>
        </div>
      @endunless
    </div>

  </div>

  <div class="ma-layout">

    {{-- LEFT: assets + services --}}
    <main>

      {{-- MARKER-PATCH-414 — assets group wrapper (mobile Items tab target) --}}
      <div class="ma-assets-group">

      <div class="ma-section-head">
        <div class="ma-section-title">
          Assets being serviced
          <span class="count">{{ $appointmentAssets->count() }}</span>
        </div>
      </div>
      <p class="ma-section-sub">Each asset has its own services and add-ons. Subtotals roll up to the total on the right.</p>

      {{-- MARKER-PATCH-158-F — empty state when no assets attached yet --}}
      @if($appointmentAssets->isEmpty())
        <div class="ma-empty">
          <div class="ma-empty-icon">⊕</div>
          <div class="ma-empty-title">No assets yet</div>
          <div class="ma-empty-sub">
            @if($pickerAssets->isNotEmpty())
              Pick from {{ $appointment->customer->first_name ?? 'this customer' }}'s {{ $pickerAssets->count() }} saved {{ \Illuminate\Support\Str::plural('asset', $pickerAssets->count()) }}, or add a new one.
            @else
              Attach a bike, vehicle, or other item to this appointment.
            @endif
          </div>
          <button type="button" class="ia-btn ia-btn--primary" onclick="maOpenAttachAssetModal()">+ Attach asset</button>
        </div>
      @endif

      {{-- Render each asset card --}}
      @foreach($appointmentAssets as $idx => $aa)
        @php
          $isExistingAsset = $aa->customerAsset !== null && $aa->customer_asset_id;
          // MARKER-PATCH-460 — per-asset subtotal must include parts. The stored
          // appointment_asset.subtotal_cents column is initialised to 0 and never
          // recomputed when parts/services change (recalcAppointmentTotals only
          // maintains the appointment-level total), so it always rendered $0.00.
          // Compute from this asset's own line items, mirroring the controller's
          // effective-price methods so per-asset subtotals sum to the grand total.
          $aaSubtotalCents = (int) $aa->items->sum(fn ($i) => (int) $i->effectivePriceCents())
                           + (int) $aa->addons->sum(fn ($a) => (int) $a->effectivePriceCents())
                           + (int) $aa->parts->sum(fn ($p) => (int) $p->lineTotalCents());
        @endphp
        <article class="ma-asset">
          <header class="ma-asset-head">
            <span class="ma-asset-num">{{ $idx + 1 }}</span>
            <div>
              <div class="ma-asset-name">
                {{-- MARKER-PATCH-158-E2 — inline rename --}}
                <input type="text"
                       class="ma-asset-name-edit asset-name-edit"
                       data-aa-id="{{ $aa->id }}"
                       value="{{ $aa->asset_name_snapshot }}"
                       maxlength="200"
                       title="Click to edit name">
                @if($isExistingAsset)
                  <span class="ma-pill ma-pill--persistent">Existing</span>
                @endif
              </div>
              @if($aa->identifier_snapshot)
                <div class="ma-asset-meta">
                  {{ $aa->identifier_snapshot }}
                  @if($aa->customerAsset && $aa->customerAsset->last_seen_at)
                    · last seen {{ \Carbon\Carbon::parse($aa->customerAsset->last_seen_at)->format('M j, Y') }}
                  @endif
                </div>
              @endif
            </div>
            <div class="ma-asset-subtotal">
              <div class="ma-asset-subtotal-label">Subtotal</div>
              <div>${{ number_format($aaSubtotalCents / 100, 2) }}</div>
            </div>
            {{-- MARKER-PATCH-158-E1 — detach button --}}
            <button type="button" class="ma-asset-detach"
                    onclick="maDetachAsset('{{ $aa->id }}', '{{ addslashes($aa->asset_name_snapshot) }}')"
                    title="Remove this asset (services stay on appointment)">
              ✕
            </button>
          </header>

          <div class="ma-asset-services">
            @forelse($aa->items as $item)
              {{-- MARKER-PATCH-158-E2 — inline edits + remove --}}
              <div class="ma-service-row line-row" data-kind="service" data-item-id="{{ $item->id }}">
                <div>
                  <div class="ma-service-name">{{ $item->item_name_snapshot }}</div>
                  <div class="ma-service-meta" style="margin-top:1px;">
                    <span class="ma-service-tag">Service</span>
                  </div>
                </div>
                <div style="text-align:right;">
                  <input type="number" min="0" class="ma-service-edit line-edit"
                    data-field="duration_minutes"
                    value="{{ $item->duration_minutes_override ?? $item->duration_minutes_snapshot ?? 0 }}"
                    title="Duration (minutes)">
                  <span style="font-size:10px;opacity:.5;">min</span>
                </div>
                <div style="text-align:right;">
                  <span style="opacity:.5;font-size:11px;">$</span>
                  <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                    data-field="price_dollars"
                    value="{{ number_format(($item->price_cents_override ?? $item->price_cents) / 100, 2, '.', '') }}"
                    title="Price (dollars)">
                </div>
                <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
              </div>
            @empty
              @if($aa->addons->isEmpty())
                <div class="ma-service-empty">No services yet.</div>
              @endif
            @endforelse
            @foreach($aa->addons as $addon)
              <div class="ma-service-row line-row" data-kind="addon" data-item-id="{{ $addon->id }}">
                <div>
                  <div class="ma-service-name">+ {{ $addon->addon_name_snapshot }}</div>
                  <div class="ma-service-meta" style="margin-top:1px;">
                    <span class="ma-service-tag ma-service-tag--addon">Add-on</span>
                  </div>
                </div>
                <div style="text-align:right;">
                  <input type="number" min="0" class="ma-service-edit line-edit"
                    data-field="duration_minutes"
                    value="{{ $addon->duration_minutes_override ?? $addon->duration_minutes_snapshot ?? 0 }}"
                    title="Duration (minutes)">
                  <span style="font-size:10px;opacity:.5;">min</span>
                </div>
                <div style="text-align:right;">
                  <span style="opacity:.5;font-size:11px;">$</span>
                  <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                    data-field="price_dollars"
                    value="{{ number_format(($addon->price_cents_override ?? $addon->price_cents) / 100, 2, '.', '') }}"
                    title="Price (dollars)">
                </div>
                <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
              </div>
            @endforeach

            <button type="button" class="ma-add-svc-btn"
                    onclick="maOpenAddServiceModal('{{ $aa->id }}', '{{ addslashes($aa->asset_name_snapshot) }}')">
              + Add service or add-on to this bike
            </button>
          </div>

          {{-- MARKER-PATCH-158-G4 — Parts section per asset (collapsible) --}}
          <details class="ma-asset-parts" data-aa-id="{{ $aa->id }}" @if($aa->parts->isNotEmpty()) open @endif>
            <summary class="ma-asset-parts-head">
              <span class="ma-asset-parts-title">Parts &amp; products</span>
              <span class="ma-asset-parts-count">{{ $aa->parts->count() }}</span>
              <span class="ma-asset-parts-chev">▾</span>
            </summary>
            <div class="ma-asset-parts-body">
              @if($aa->parts->isNotEmpty())
                <table class="ma-parts-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th class="num" style="width: 70px;">Qty</th>
                      <th class="num" style="width: 80px;">Price</th>
                      <th class="num" style="width: 80px;">Total</th>
                      <th style="width: 22px;"></th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($aa->parts as $part)
                      @php
                        $invItem = $part->inventoryItem;
                        $stockNow = $invItem ? (int) ($invItem->computed_stock_count ?? 0) : null;
                        $stockProjected = ($stockNow !== null && !$part->isCommitted())
                          ? $stockNow - (int) $part->quantity
                          : null;
                      @endphp
                      <tr class="ma-part-row" data-part-id="{{ $part->id }}" data-committed="{{ $part->isCommitted() ? '1' : '0' }}">
                        <td>
                          <div style="font-weight: 500; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span>{{ $part->item_name_snapshot }}</span>
                            @if(!$part->inventory_item_id)
                              <span class="ma-pill">Custom</span>
                            @endif
                          </div>
                          @if($part->item_sku_snapshot)
                            <div style="font-size: 11px; opacity: .45; font-family: ui-monospace, 'SF Mono', monospace; margin-top: 2px;">{{ $part->item_sku_snapshot }}</div>
                          @endif
                          @if($stockNow !== null)
                            <div style="font-size: 11px; opacity: .55; margin-top: 3px;">
                              @if($part->isCommitted())
                                Stock decremented · current: {{ $stockNow }}
                              @else
                                Stock: {{ $stockNow }} → {{ $stockProjected }} on completion
                              @endif
                            </div>
                          @endif
                          @if($part->inventory_item_id)
                            {{-- MARKER-PATCH-419 — per-line "add to special orders" --}}
                            <label class="ma-part-so">
                              <input type="checkbox" class="ma-part-so-toggle" data-part-id="{{ $part->id }}" {{ $part->is_special_order ? 'checked' : '' }}>
                              <span>Special order</span>
                              <span class="ma-part-so-badge" data-part-id="{{ $part->id }}">{{ $part->special_order_id && $part->specialOrder ? $part->specialOrder->so_number : '' }}</span>
                            </label>
                          @endif
                        </td>
                        <td class="num">
                          <input type="number" min="1" max="999"
                            class="ma-part-qty-edit"
                            value="{{ $part->quantity }}"
                            data-part-id="{{ $part->id }}"
                            {{ ($part->isCommitted() && $part->inventory_item_id) ? 'disabled' : '' }}>
                        </td>
                        <td class="num">${{ number_format($part->effectiveUnitPriceCents() / 100, 2) }}</td>
                        <td class="num" data-line-total>${{ number_format($part->lineTotalCents() / 100, 2) }}</td>
                        <td>
                          <button type="button" class="ma-service-remove ma-part-remove" data-part-id="{{ $part->id }}" title="Remove">&#x2715;</button>
                        </td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              @else
                <p class="ma-asset-parts-empty">No products yet.</p>
              @endif

              {{-- Per-asset picker. Same UI as the loose picker but scoped via data-aa-id. --}}
              <div class="ma-asset-part-pickerwrap">
                <input type="text" class="ia-input ma-asset-part-picker"
                       data-aa-id="{{ $aa->id }}"
                       placeholder="+ Add product or custom item to this bike…"
                       autocomplete="off">
                <div class="ma-asset-part-results" data-aa-id="{{ $aa->id }}" hidden></div>
              </div>

              {{-- Per-asset custom item form (hidden until user clicks "+ Custom item" in picker) --}}
              <div class="ma-asset-custom-form" data-aa-id="{{ $aa->id }}" hidden>
                <div class="ma-asset-custom-form-head">
                  <span>Custom item</span>
                  <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm ma-asset-custom-cancel" data-aa-id="{{ $aa->id }}" style="padding: 2px 8px; font-size: 11px;">Cancel</button>
                </div>
                <div class="ma-asset-custom-grid">
                  <div>
                    <label class="ma-form-label" style="font-size: 11px; margin-bottom: 4px;">Name</label>
                    <input type="text" class="ia-input ma-asset-custom-name" maxlength="255" placeholder="e.g. Special-order grommet">
                  </div>
                  <div>
                    <label class="ma-form-label" style="font-size: 11px; margin-bottom: 4px;">Price</label>
                    <input type="number" class="ia-input ma-asset-custom-price" min="0" step="0.01" placeholder="0.00" style="text-align: right;">
                  </div>
                  <div>
                    <label class="ma-form-label" style="font-size: 11px; margin-bottom: 4px;">Qty</label>
                    <input type="number" class="ia-input ma-asset-custom-qty" min="1" max="999" value="1" style="text-align: right;">
                  </div>
                  <div>
                    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm ma-asset-custom-save" data-aa-id="{{ $aa->id }}">Add</button>
                  </div>
                </div>
              </div>
            </div>
          </details>

          {{-- MARKER-PATCH-158-G5 — Work order details section per asset (collapsible).
               Renders only when the tenant has work-order fields configured.
               Responses are keyed by (appointment_id, field_id, appointment_asset_id). --}}
          @if($appointment->workOrderFields && $appointment->workOrderFields->isNotEmpty())
            @php
              $aaResponses        = $aa->workOrderResponses->keyBy('field_id');
              $aaIdentifierField  = $appointment->workOrderFields->firstWhere('is_identifier', true);
              $aaIdentifierValue  = $aaIdentifierField ? ($aaResponses[$aaIdentifierField->id]->response_value ?? null) : null;
              $aaNonIdentifier    = $appointment->workOrderFields->filter(fn($f) => !$f->is_identifier);
              $aaFilledCount      = $appointment->workOrderFields->filter(fn($f) => !empty($aaResponses[$f->id]->response_value ?? null))->count();
            @endphp
            <details class="ma-asset-wo" data-aa-id="{{ $aa->id }}" @if($aaFilledCount > 0) open @endif>
              <summary class="ma-asset-parts-head">
                <span class="ma-asset-parts-title">Work order details</span>
                <span class="ma-asset-parts-count">{{ $aaFilledCount }}/{{ $appointment->workOrderFields->count() }}</span>
                <span class="ma-asset-parts-chev">▾</span>
              </summary>
              <div class="ma-asset-wo-body">

                {{-- Display mode --}}
                <div class="ma-asset-wo-display" data-aa-id="{{ $aa->id }}">
                  @if($aaIdentifierField && $aaIdentifierValue)
                    <div class="ma-asset-wo-id-block">
                      <div class="ma-asset-wo-id-label">{{ $aaIdentifierField->label }}</div>
                      <div class="ma-asset-wo-id-value">{{ $aaIdentifierValue }}</div>
                    </div>
                  @endif

                  @php $aaFilledNonId = $aaNonIdentifier->filter(fn($f) => !empty($aaResponses[$f->id]->response_value ?? null)); @endphp
                  @if($aaFilledNonId->isEmpty() && (!$aaIdentifierField || !$aaIdentifierValue))
                    <p class="ma-asset-wo-empty">No details yet — click <strong>Edit</strong> to add.</p>
                  @elseif($aaFilledNonId->isNotEmpty())
                    <div class="ma-asset-wo-grid">
                      @foreach($aaFilledNonId as $field)
                        <div>
                          <div class="ma-asset-wo-id-label">{{ $field->label }}</div>
                          <div class="ma-asset-wo-field-value">{{ $aaResponses[$field->id]->response_value }}</div>
                        </div>
                      @endforeach
                    </div>
                  @endif

                  <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm ma-asset-wo-edit-toggle" data-aa-id="{{ $aa->id }}" style="margin-top: 10px;">
                    Edit
                  </button>
                </div>

                {{-- Edit mode --}}
                <form class="ma-asset-wo-edit-form" data-aa-id="{{ $aa->id }}" data-update-url="{{ $updateUrl }}" hidden>
                  <input type="hidden" name="appointment_asset_id" value="{{ $aa->id }}">

                  @foreach($appointment->workOrderFields as $field)
                    @php $currentValue = $aaResponses[$field->id]->response_value ?? ''; @endphp
                    <div class="ma-form-row">
                      <label class="ma-form-label">
                        {{ $field->label }}
                        @if($field->is_identifier)
                          <span class="ma-wo-id-pill">ID</span>
                        @endif
                        @if($field->is_required)
                          <span style="color: #f87171;">*</span>
                        @endif
                      </label>
                      @if($field->field_type === 'textarea')
                        <textarea name="values[{{ $field->id }}]" class="ia-input" rows="3" @if($field->is_required) required @endif>{{ $currentValue }}</textarea>
                      @elseif($field->field_type === 'number')
                        <input type="number" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
                      @elseif($field->field_type === 'select')
                        <select name="values[{{ $field->id }}]" class="ia-input" @if($field->is_required) required @endif>
                          <option value="">—</option>
                          @foreach(($field->options ?? []) as $opt)
                            <option value="{{ $opt }}" @selected($currentValue === $opt)>{{ $opt }}</option>
                          @endforeach
                        </select>
                      @else
                        <input type="text" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
                      @endif
                      @if($field->help_text)
                        <div style="font-size: 11px; color: var(--ia-text-dim); margin-top: 4px;">{{ $field->help_text }}</div>
                      @endif
                    </div>
                  @endforeach

                  <div style="display: flex; gap: 8px; margin-top: 14px;">
                    <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
                    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm ma-asset-wo-edit-cancel" data-aa-id="{{ $aa->id }}">Cancel</button>
                  </div>
                </form>

              </div>
            </details>
          @endif
        </article>
      @endforeach

      {{-- Loose items section — only shown if there are unpinned items --}}
      @if($looseItems->isNotEmpty() || $looseAddons->isNotEmpty())
        @php
          $assetSingular = tenant()->asset_label_singular ?: 'item';
          $looseCount = $looseItems->count() + $looseAddons->count();
          $hasAssets = $appointmentAssets->isNotEmpty();
        @endphp
        {{-- MARKER-PATCH-470 — assign-later holding state --}}
        <div class="ma-loose-card ma-loose-card--needs">
          <div class="ma-loose-title ma-loose-title--row">
            <span>Unassigned <span class="ma-loose-count">{{ $looseCount }}</span></span>
            <span class="ma-loose-needs">needs a {{ $assetSingular }}</span>
          </div>
          @foreach($looseItems as $item)
            <div class="ma-service-row line-row" data-kind="service" data-item-id="{{ $item->id }}" style="margin-bottom: 6px;">
              <div>
                <div class="ma-service-name">{{ $item->item_name_snapshot }}</div>
                <div class="ma-service-meta" style="margin-top:1px;">
                  <span class="ma-service-tag">Service</span>
                </div>
              </div>
              <div style="text-align:right;">
                <input type="number" min="0" class="ma-service-edit line-edit"
                  data-field="duration_minutes"
                  value="{{ $item->duration_minutes_override ?? $item->duration_minutes_snapshot ?? 0 }}"
                  title="Duration (minutes)">
                <span style="font-size:10px;opacity:.5;">min</span>
              </div>
              <div style="text-align:right;">
                <span style="opacity:.5;font-size:11px;">$</span>
                <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                  data-field="price_dollars"
                  value="{{ number_format(($item->price_cents_override ?? $item->price_cents) / 100, 2, '.', '') }}"
                  title="Price (dollars)">
              </div>
              <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
            </div>
          @endforeach
          @foreach($looseAddons as $addon)
            <div class="ma-service-row line-row" data-kind="addon" data-item-id="{{ $addon->id }}" style="margin-bottom: 6px;">
              <div>
                <div class="ma-service-name">+ {{ $addon->addon_name_snapshot }}</div>
                <div class="ma-service-meta" style="margin-top:1px;">
                  <span class="ma-service-tag ma-service-tag--addon">Add-on</span>
                </div>
              </div>
              <div style="text-align:right;">
                <input type="number" min="0" class="ma-service-edit line-edit"
                  data-field="duration_minutes"
                  value="{{ $addon->duration_minutes_override ?? $addon->duration_minutes_snapshot ?? 0 }}"
                  title="Duration (minutes)">
                <span style="font-size:10px;opacity:.5;">min</span>
              </div>
              <div style="text-align:right;">
                <span style="opacity:.5;font-size:11px;">$</span>
                <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                  data-field="price_dollars"
                  value="{{ number_format(($addon->price_cents_override ?? $addon->price_cents) / 100, 2, '.', '') }}"
                  title="Price (dollars)">
              </div>
              <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
            </div>
          @endforeach
          <div style="font-size: 11.5px; color: var(--ia-text-faint, #52525b); margin-top: 8px; line-height: 1.5;">
            These services aren't pinned to any asset. Attach an asset above and add new services to pin them.
          </div>
        </div>
      @endif

      {{-- MARKER-PATCH-472 — service-first add (primary path, always available) --}}
      <button type="button" class="ma-add-asset-btn" onclick="maOpenAddServiceFirst()" style="border-color:var(--ia-accent,#BEF264);color:var(--ia-accent,#BEF264);margin-bottom:8px;">
        + Add a service
      </button>

      {{-- MARKER-PATCH-158-E1 — real Attach asset button (only when assets already exist; empty state has its own) --}}
      @if($appointmentAssets->isNotEmpty())
        <button type="button" class="ma-add-asset-btn" onclick="maOpenAttachAssetModal()">
          + Attach asset to this appointment
        </button>
      @endif

      {{-- MARKER-PATCH-158-E3 — Additional charges card --}}
      <div class="ma-charges-card">
        <div class="ma-charges-head">
          <div class="ma-section-title">Additional charges</div>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="ma-add-charge-toggle">
            + Add charge
          </button>
        </div>

        <form method="POST" action="{{ $updateUrl }}" class="ma-add-charge-form" id="ma-add-charge-form" style="display: none;">
          @csrf
          @method('PATCH')
          <input type="hidden" name="op" value="add_charge">
          <div style="display: grid; grid-template-columns: 1fr 140px; gap: 10px; margin-bottom: 10px;">
            <div>
              <label class="ma-form-label">Description</label>
              <input type="text" name="description" class="ia-input" placeholder="e.g. New brake cable" required>
            </div>
            <div>
              <label class="ma-form-label">Amount ($)</label>
              <input type="number" name="amount_display" class="ia-input" placeholder="25.00"
                     step="0.01" min="0.01" id="ma-charge-amount-display" required>
              <input type="hidden" name="amount_cents" id="ma-charge-amount-cents">
            </div>
          </div>
          <div style="display: flex; gap: 8px;">
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save charge</button>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="ma-add-charge-cancel">Cancel</button>
          </div>
        </form>

        @if($appointment->charges->isEmpty())
          <p style="font-size: 13px; opacity: .4; margin: 0;">No additional charges.</p>
        @else
          @foreach($appointment->charges as $charge)
            <div class="ma-charge-row">
              <div>
                <div style="font-size: 13px;">{{ $charge->description }}</div>
                <div style="font-size: 11px; opacity: .4; margin-top: 1px;">
                  {{ \Carbon\Carbon::parse($charge->created_at)->format('M j') }} ·
                  {{ $charge->is_paid ? 'Paid' : 'Unpaid' }}
                </div>
              </div>
              <div style="font-weight: 500; font-variant-numeric: tabular-nums;">${{ number_format($charge->amount_cents / 100, 2) }}</div>
            </div>
          @endforeach

          <div class="ma-charge-row" style="font-weight: 500; border-bottom: 0; padding-top: 10px;">
            <span>Charges total</span>
            <span style="font-variant-numeric: tabular-nums;">${{ number_format($appointment->charges->sum('amount_cents') / 100, 2) }}</span>
          </div>
        @endif
      </div>

      {{-- MARKER-PATCH-158-G4 — Unassigned parts (only shown if any parts are unpinned).
           Parts pinned to an asset live in that asset's collapsible Parts section above. --}}
      @if($looseParts->isNotEmpty())
        <div class="ma-parts-card">
          <div class="ma-charges-head">
            <div class="ma-section-title">Unassigned parts</div>
            <div style="font-size: 11px; color: var(--ia-text-faint, #52525b);">
              Not pinned to any specific asset
            </div>
          </div>

          <table class="ma-parts-table">
            <thead>
              <tr>
                <th>Item</th>
                <th class="num" style="width: 80px;">Qty</th>
                <th class="num" style="width: 90px;">Price</th>
                <th class="num" style="width: 90px;">Total</th>
                <th style="width: 28px;"></th>
              </tr>
            </thead>
            <tbody>
              @foreach($looseParts as $part)
                @php
                  $invItem = $part->inventoryItem;
                  $stockNow = $invItem ? (int) ($invItem->computed_stock_count ?? 0) : null;
                  $stockProjected = ($stockNow !== null && !$part->isCommitted())
                    ? $stockNow - (int) $part->quantity
                    : null;
                @endphp
                <tr class="ma-part-row" data-part-id="{{ $part->id }}" data-committed="{{ $part->isCommitted() ? '1' : '0' }}">
                  <td>
                    <div style="font-weight: 500; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                      <span>{{ $part->item_name_snapshot }}</span>
                      @if(!$part->inventory_item_id)
                        <span class="ma-pill">Custom</span>
                      @endif
                    </div>
                    @if($part->item_sku_snapshot)
                      <div style="font-size: 11px; opacity: .45; font-family: ui-monospace, 'SF Mono', monospace; margin-top: 2px;">{{ $part->item_sku_snapshot }}</div>
                    @endif
                    @if($stockNow !== null)
                      <div style="font-size: 11px; opacity: .55; margin-top: 3px;">
                        @if($part->isCommitted())
                          Stock decremented · current: {{ $stockNow }}
                        @else
                          Stock: {{ $stockNow }} → {{ $stockProjected }} on completion
                        @endif
                      </div>
                    @endif
                    @if($part->inventory_item_id)
                      {{-- MARKER-PATCH-419 — per-line "add to special orders" --}}
                      <label class="ma-part-so">
                        <input type="checkbox" class="ma-part-so-toggle" data-part-id="{{ $part->id }}" {{ $part->is_special_order ? 'checked' : '' }}>
                        <span>Special order</span>
                        <span class="ma-part-so-badge" data-part-id="{{ $part->id }}">{{ $part->special_order_id && $part->specialOrder ? $part->specialOrder->so_number : '' }}</span>
                      </label>
                    @endif
                  </td>
                  <td class="num">
                    <input type="number" min="1" max="999"
                      class="ma-part-qty-edit"
                      value="{{ $part->quantity }}"
                      data-part-id="{{ $part->id }}"
                      {{ ($part->isCommitted() && $part->inventory_item_id) ? 'disabled' : '' }}>
                  </td>
                  <td class="num">${{ number_format($part->effectiveUnitPriceCents() / 100, 2) }}</td>
                  <td class="num" data-line-total>${{ number_format($part->lineTotalCents() / 100, 2) }}</td>
                  <td>
                    <button type="button" class="ma-service-remove ma-part-remove" data-part-id="{{ $part->id }}" title="Remove">&#x2715;</button>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      {{-- MARKER-PATCH-158-E6 — Special-order parts --}}
      @isset($specialOrdersForAppt)
        @php
          $unArrivedSos = $specialOrdersForAppt->whereIn('status', ['needed', 'ordered']);
          $showBlockWarning = $appointment->status === 'in_progress' && $unArrivedSos->isNotEmpty();
        @endphp
        <div class="ma-so-card" id="ma-so-parts-card" style="{{ $showBlockWarning ? 'border-left: 3px solid #F59E0B;' : '' }}">
          <div class="ma-so-head">
            <div class="ma-section-title">Special-order parts</div>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                    onclick='SoDrawer.open({customer_id: @json($appointment->customer_id), customer_label: @json(trim(($appointment->customer->first_name ?? "") . " " . ($appointment->customer->last_name ?? ""))), appointment_id: @json($appointment->id), alloc_mode: "customer_appt"})'>
              + SO for this appointment
            </button>
          </div>

          @if($showBlockWarning)
            <div class="ma-so-warning">
              <strong>⚠ {{ $unArrivedSos->count() }} part{{ $unArrivedSos->count() === 1 ? '' : 's' }} not yet arrived.</strong>
              <span>Completing this appointment will leave the customer waiting on parts. Consider waiting until parts arrive, or proceed if customer is OK with split pickup.</span>
            </div>
          @endif

          @if($specialOrdersForAppt->isEmpty())
            <p style="font-size: 13px; color: var(--ia-text-dim); padding: 6px 0; margin: 0;">No special-order parts on this appointment.</p>
          @else
            <table class="ma-so-table">
              <thead>
                <tr>
                  <th>Part</th>
                  <th class="num" style="width: 60px;">Qty</th>
                  <th style="width: 110px;">Status</th>
                  <th style="width: 80px;">ETA</th>
                  <th>Vendor</th>
                  <th style="width: 80px;">SO #</th>
                </tr>
              </thead>
              <tbody>
                @foreach($specialOrdersForAppt as $so)
                  @php
                    $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
                    $rowOpacity = in_array($so->status, ['pulled', 'cancelled']) ? '0.55' : '1';
                  @endphp
                  <tr style="cursor: pointer; opacity: {{ $rowOpacity }};"
                      onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                    <td><strong>{{ $so->item_name_snapshot }}</strong></td>
                    <td class="num">{{ $so->quantity }}</td>
                    <td>
                      <span class="ma-so-status ma-so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
                    </td>
                    <td style="color: var(--ia-text-dim); font-size: 12px;">
                      @if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif
                    </td>
                    <td style="color: var(--ia-text-dim); font-size: 12px;">{{ $so->vendor?->name ?? 'TBD' }}</td>
                    <td style="font-size: 11px; color: var(--ia-text-dim);">{{ $so->so_number }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif
        </div>

        @include('tenant.special-orders._drawer', ['vendors' => $soVendors ?? collect()])
      @endisset

      {{-- MARKER-PATCH-158-G5 — Bottom work-order card removed; now per-asset inside each asset card --}}

      </div>{{-- MARKER-PATCH-414 — /ma-assets-group --}}

      {{-- MARKER-PATCH-158-E5 — Notes card --}}
      <div class="ma-notes-card" id="ma-notes-card">
        <div class="ma-charges-head">
          <div class="ma-section-title">Notes</div>
        </div>

        <div style="margin-bottom: 14px;">
          <textarea id="ma-note-input" rows="3" maxlength="500"
            placeholder="Add a note…" class="ia-input"
            style="width: 100%; resize: vertical; font-family: inherit;"></textarea>
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 8px;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ia-text-dim); cursor: pointer;">
              <input type="checkbox" id="ma-note-customer-visible" style="accent-color: var(--ia-accent, #BEF264);">
              Also show to customer
            </label>
            <div style="display: flex; align-items: center; gap: 10px;">
              <span id="ma-note-char-count" style="font-size: 11px; color: var(--ia-text-dim); font-variant-numeric: tabular-nums;">500</span>
              <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="ma-note-submit">
                Add note
              </button>
            </div>
          </div>
          <p id="ma-note-error" style="font-size: 12px; color: #f87171; margin-top: 6px; display: none;"></p>
        </div>

        <div id="ma-notes-list">
          @forelse($appointment->notes->sortByDesc('created_at') as $note)
            <div class="ma-note {{ $note->note_type === 'system' ? 'ma-note--system' : '' }}" data-note-id="{{ $note->id }}">
              <div class="ma-note-head">
                <span class="ma-note-author">
                  {{ $note->user?->name ?? ($note->note_type === 'system' ? 'Activity' : 'Staff') }}
                </span>
                @if($note->is_customer_visible)
                  <span class="ma-note-visibility ma-note-visibility--customer">Customer-visible</span>
                @endif
                <span class="ma-note-time">
                  {{ tlocal($note->created_at, 'M j, g:i a') }}{{-- MARKER-PATCH-532 --}}
                </span>
                @if($note->note_type !== 'system')
                  <button type="button" class="ma-note-delete"
                    data-note-id="{{ $note->id }}"
                    title="Delete">&#x2715;</button>
                @endif
              </div>
              <div class="ma-note-body">{{ $note->note_content }}</div>
            </div>
          @empty
            <p class="ma-notes-empty">No notes yet.</p>
          @endforelse
        </div>
      </div>

    </main>

    {{-- RIGHT RAIL --}}
    <aside class="ma-rail">

      @php
        $payments      = $appointment->payments;
        $balanceDue    = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
        $overage       = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
        $openSale      = $appointment->openRegisterSale();
        $hasOpenSale   = $openSale !== null;
      @endphp

      {{-- MARKER-PATCH-158-G12 — Totals + Payment merged into one card.
           Previously two separate cards duplicated Subtotal/Tax/Total and
           used different sources (one recomputed from live items, one from
           snapshot columns), so they could disagree. Now: snapshot is the
           single source of truth, kept fresh by recalcAppointmentTotals(). --}}
      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Totals</div>
        <div class="ma-rail-row"><span class="k">Assets</span><span class="v">{{ $appointmentAssets->count() }}</span></div>
        <div class="ma-rail-row"><span class="k">Services</span><span class="v">{{ $serviceCount }}</span></div>
        @if($addonCount > 0)
          <div class="ma-rail-row"><span class="k">Add-ons</span><span class="v">{{ $addonCount }}</span></div>
        @endif
        @php $partCount = $appointmentAssets->sum(fn($a) => $a->parts->count()) + $looseParts->count(); @endphp
        @if($partCount > 0)
          <div class="ma-rail-row"><span class="k">Parts</span><span class="v">{{ $partCount }}</span></div>
        @endif

        <div class="ma-rail-row" style="margin-top: 8px; padding-top: 8px; border-top: 0.5px solid var(--ia-border);">
          <span class="k">Subtotal</span>
          <span class="v">${{ number_format(($appointment->subtotal_cents ?? 0) / 100, 2) }}</span>
        </div>
        @if(($appointment->tax_cents ?? 0) > 0)
          <div class="ma-rail-row">
            <span class="k">Tax</span>
            <span class="v">${{ number_format($appointment->tax_cents / 100, 2) }}</span>
          </div>
        @endif
        <div class="ma-rail-row ma-rail-row--total">
          <span class="k">Total</span>
          <span class="v">${{ number_format(($appointment->total_cents ?? 0) / 100, 2) }}</span>
        </div>

        {{-- Payment section --}}
        <div style="margin-top: 14px; padding-top: 14px; border-top: 0.5px solid var(--ia-border);">
          <div class="ma-rail-row" style="margin-bottom: 6px;">
            <span class="k" style="font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--ia-text-dim); font-weight: 600;">Payment</span>
            <span class="v" style="text-transform: capitalize;">
              <span class="ma-payment-badge ma-payment-badge--{{ $appointment->payment_status }}">
                {{ ucwords(str_replace('_', ' ', $appointment->payment_status ?? 'unpaid')) }}
              </span>
            </span>
          </div>

          @if($payments->isNotEmpty())
            <div style="margin-top: 10px;">
              <div style="font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--ia-text-dim); font-weight: 600; margin-bottom: 6px;">Ledger</div>
              @foreach($payments as $p)
                <div style="display: flex; justify-content: space-between; align-items: flex-start; font-size: 12px; padding: 6px 0; border-bottom: 0.5px solid var(--ia-border);">
                  <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 500; color: var(--ia-text);">
                      {{ in_array($p->kind, ['refund', 'overage_refund']) ? 'Refund' : ucfirst($p->kind) }}
                      · {{ $p->methodLabel() }}
                    </div>
                    <div style="font-size: 10px; color: var(--ia-text-dim); margin-top: 2px;">
                      {{ $p->recorded_at ? tlocal($p->recorded_at, 'M j · g:i A') : '' }}
                      @if($p->source === 'register_sale' && $p->register_sale_id)
                        · sale {{ optional($p->registerSale)->sale_number ?? '#' }}
                      @endif
                    </div>
                  </div>
                  <div style="font-weight: 500; color: {{ $p->amount_cents < 0 ? '#F09595' : '#A8D670' }};">
                    {{ $p->amount_cents < 0 ? '−' : '+' }}${{ number_format(abs($p->amount_cents) / 100, 2) }}
                  </div>
                </div>
              @endforeach
            </div>
          @endif

          <div class="ma-rail-row" style="margin-top: 8px;">
            <span class="k">Paid so far</span>
            <span class="v" style="color: #A8D670;">${{ number_format(($appointment->paid_cents ?? 0) / 100, 2) }}</span>
          </div>

          @if($balanceDue > 0)
            <div class="ma-rail-row" style="font-weight: 500;">
              <span class="k">Balance owed</span>
              <span class="v" style="font-size: 14px; font-weight: 500;">${{ number_format($balanceDue / 100, 2) }}</span>
            </div>
          @elseif($overage > 0)
            <div class="ma-rail-row" style="font-weight: 500;">
              <span class="k" style="color: #FBBF24;">Customer is owed</span>
              <span class="v" style="font-size: 14px; font-weight: 500; color: #FBBF24;">${{ number_format($overage / 100, 2) }}</span>
            </div>
          @else
            <div class="ma-rail-row" style="font-weight: 500;">
              <span class="k">Balance owed</span>
              <span class="v" style="font-size: 14px; font-weight: 500; color: #A8D670;">$0.00</span>
            </div>
          @endif

          @if($hasOpenSale)
            <a href="{{ route('tenant.register.index', []) }}?resume={{ $openSale->id }}"
               class="ia-btn ia-btn--primary ia-btn--sm"
               style="display: block; width: 100%; text-align: center; margin-top: 14px;">
              Take payment in register
            </a>
          @elseif($balanceDue > 0 && !$isTerminal)
            <button type="button" id="ma-record-deposit-toggle" class="ia-btn ia-btn--secondary ia-btn--sm" style="width: 100%; margin-top: 14px;">
              + Record deposit
            </button>
            <div id="ma-record-deposit-form" style="display: none; margin-top: 10px; padding: 12px; background: var(--ia-surface-2, rgba(255,255,255,0.02)); border-radius: 6px; border: 0.5px solid var(--ia-border);">
              <label style="font-size: 11px; color: var(--ia-text-dim); display: block; margin-bottom: 4px;">Amount</label>
              <input type="number" id="ma-record-deposit-amount" min="0.01" step="0.01" placeholder="0.00"
                     style="width: 100%; padding: 6px 10px; background: var(--ia-surface, #111); border: 0.5px solid var(--ia-border); color: var(--ia-text); border-radius: 6px; font-size: 13px; margin-bottom: 8px;">
              <div style="display: flex; gap: 6px;">
                <button type="button" id="ma-record-deposit-cancel" class="ia-btn ia-btn--ghost ia-btn--sm" style="flex: 1;">Cancel</button>
                <button type="button" id="ma-record-deposit-go" class="ia-btn ia-btn--primary ia-btn--sm" style="flex: 1;">Send to register</button>
              </div>
              <p style="font-size: 10px; color: var(--ia-text-dim); margin: 8px 0 0;">Creates a draft sale in the register where you take the actual payment.</p>
            </div>
          @endif
        </div>
      </div>

      {{-- MARKER-PATCH-158-G12 — Old separate Payment card removed (merged into Totals above). --}}

      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Schedule</div>
        <div class="ma-schedule-row">
          <span class="lbl">Date</span>
          <span>{{ $appointment->appointment_date->format('D M j, Y') }}</span>
        </div>
        {{-- MARKER-PATCH-311 --}}
        @include('tenant.appointments._promised_editor')
        {{-- MARKER-PATCH-514 --}}
        @include('tenant.appointments._route_trip')
        @if($appointment->appointment_time)
          <div class="ma-schedule-row">
            <span class="lbl">Time</span>
            <span>{{ \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A') }}</span>
          </div>
        @endif
        @if($appointment->resource)
          <div class="ma-schedule-row">
            <span class="lbl">Resource</span>
            <span>{{ $appointment->resource->name }}</span>
          </div>
        @endif
        @if($appointment->total_duration_minutes)
          <div class="ma-schedule-row">
            <span class="lbl">Duration</span>
            <span>{{ $appointment->total_duration_minutes }} min</span>
          </div>
        @endif
      </div>

      @if($appointment->staff_notes)
        <div class="ma-rail-card">
          <div class="ma-rail-card-title">Internal note</div>
          <div style="font-size: 13px; color: var(--ia-text); white-space: pre-wrap;">{{ $appointment->staff_notes }}</div>
        </div>
      @endif


      {{-- MARKER-PATCH-158-G3 — Resource card + Action buttons moved to top tile (G3) --}}

    </aside>

  </div>
</div>

{{-- ============== MARKER-PATCH-158-E1 — Modals + JS ============== --}}

{{-- Attach asset modal --}}
<div class="ma-modal-backdrop" id="ma-attach-modal" onclick="if(event.target===this) maCloseModal('ma-attach-modal')">
  <div class="ma-modal" style="width: 560px;">
    <div class="ma-modal-head">
      <div class="ma-modal-title">Attach asset to this appointment</div>
      <button type="button" class="ma-modal-close" onclick="maCloseModal('ma-attach-modal')">✕</button>
    </div>
    <div class="ma-modal-body">

      <div class="ma-tabs">
        <button type="button" class="ma-tab is-active" data-tab="existing" onclick="maSwitchAttachTab('existing')">
          From {{ $appointment->customer->first_name ?? 'this customer' }}'s assets ({{ $pickerAssets->count() }})
        </button>
        <button type="button" class="ma-tab" data-tab="new" onclick="maSwitchAttachTab('new')">
          Add new asset
        </button>
      </div>

      {{-- Existing tab --}}
      <div class="ma-tab-panel is-active" data-panel="existing">
        @if($pickerAssets->isEmpty())
          <div style="padding: 24px; text-align: center; color: var(--ia-text-dim); font-size: 13px; background: var(--ia-surface-2, rgba(255,255,255,0.02)); border: 1px dashed var(--ia-border); border-radius: 8px;">
            No saved assets to attach. Switch to <strong style="color: var(--ia-text);">Add new asset</strong> to create one.
          </div>
        @else
          <div class="ma-picker-list">
            @foreach($pickerAssets as $pa)
              <label class="ma-picker-row">
                <input type="radio" name="picker_asset_id" value="{{ $pa->id }}" class="ma-picker-radio">
                <div class="ma-picker-main">
                  <div class="ma-picker-name">{{ $pa->name }}</div>
                  <div class="ma-picker-meta">
                    @if($pa->identifier){{ $pa->identifier }} · @endif
                    @if($pa->last_seen_at)
                      last seen {{ \Carbon\Carbon::parse($pa->last_seen_at)->format('M j, Y') }}
                    @else
                      never serviced
                    @endif
                  </div>
                </div>
              </label>
            @endforeach
          </div>
        @endif
      </div>

      {{-- New tab --}}
      <div class="ma-tab-panel" data-panel="new">
        <div class="ma-form-row">
          <label class="ma-form-label">Name</label>
          <input id="ma-new-name" class="ia-input" type="text" maxlength="200" placeholder="e.g. Red Cannondale Synapse">
        </div>
        <div class="ma-form-row">
          <label class="ma-form-label">Identifier <span style="color: var(--ia-text-faint, #52525b);">— optional</span></label>
          <input id="ma-new-identifier" class="ia-input" type="text" maxlength="120" placeholder="Serial, license plate, microchip, tag…">
        </div>
        <div class="ma-form-row">
          <label class="ma-form-label">Notes <span style="color: var(--ia-text-faint, #52525b);">— optional</span></label>
          <textarea id="ma-new-notes" class="ia-input" rows="3" maxlength="5000" placeholder="Distinguishing features, prior issues…"></textarea>
        </div>
        <div style="font-size: 11.5px; color: var(--ia-text-dim);">
          Creates a new asset on {{ $appointment->customer->first_name ?? 'the customer' }}'s record AND attaches it to this appointment.
        </div>
      </div>

    </div>
    <div class="ma-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="maCloseModal('ma-attach-modal')">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="ma-attach-submit" onclick="maSubmitAttach()">Attach</button>
    </div>
  </div>
</div>

{{-- Add service-to-asset modal --}}
{{-- MARKER-PATCH-470 — assign-loose picker --}}
<script>
  window.maApptAssets = @json($appointmentAssets->map(fn ($a) => ['id' => $a->id, 'name' => $a->asset_name_snapshot])->values());
  window.maPickerAssets = @json($pickerAssets->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'identifier' => $a->identifier])->values());
  window.maLooseAssetSingular = @json(tenant()->asset_label_singular ?: 'item');
  window.maLooseAssetPlural = @json(tenant()->asset_label_plural ?: 'items');
  window.maLooseCustomerName = @json($appointment->customer->first_name ?? null);
</script>
<div class="ma-modal-backdrop" id="ma-assign-loose-modal" onclick="if(event.target===this) maCloseModal('ma-assign-loose-modal')">
  <div class="ma-modal" style="width: 380px;">
    <div class="ma-modal-head">
      <div class="ma-modal-title" id="ma-assign-loose-title">Assign to a {{ tenant()->asset_label_singular ?: 'item' }}</div>
      <button type="button" class="ma-modal-close" onclick="maCloseModal('ma-assign-loose-modal')">✕</button>
    </div>
    <div class="ma-modal-body">
      <div class="ma-assign-loose-list" id="ma-assign-loose-list"></div>
      <div class="ma-assign-new-form" id="ma-assign-new-form" style="display:none">
        <input type="text" id="ma-assign-new-name" class="ia-input" placeholder="{{ ucfirst(tenant()->asset_label_singular ?: 'item') }} name" autocomplete="off" style="width:100%;margin:0 0 8px;">
        <input type="text" id="ma-assign-new-identifier" class="ia-input" placeholder="Serial / ID (optional)" autocomplete="off" style="width:100%;margin:0 0 8px;">
        <textarea id="ma-assign-new-notes" class="ia-input" rows="2" placeholder="Notes (optional)" style="width:100%;margin:0 0 10px;resize:vertical;"></textarea>
        <div style="display:flex;gap:8px;">
          <button type="button" class="ia-btn ia-btn--ghost" onclick="maAssignNewBack()" style="flex:1;">Back</button>
          <button type="button" class="ia-btn ia-btn--primary" onclick="maAssignNewSubmit()" style="flex:1;">Create &amp; assign</button>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="ma-modal-backdrop" id="ma-add-svc-modal" onclick="if(event.target===this) maCloseModal('ma-add-svc-modal')">
  <div class="ma-modal" style="width: 560px;">
    <div class="ma-modal-head">
      <div class="ma-modal-title" id="ma-add-svc-title">Add to asset</div>
      <button type="button" class="ma-modal-close" onclick="maCloseModal('ma-add-svc-modal')">✕</button>
    </div>
    <div class="ma-modal-body">

      <div class="ma-tabs">
        <button type="button" class="ma-tab is-active" data-tab="service" onclick="maSwitchSvcTab('service')">
          Services ({{ $availableServices->count() }})
        </button>
        <button type="button" class="ma-tab" data-tab="addon" onclick="maSwitchSvcTab('addon')">
          Add-ons ({{ $availableAddons->count() }})
        </button>
      </div>

      {{-- MARKER-PATCH-467 — live filter of the catalog --}}
      <input type="text" id="ma-svc-search" class="ia-input" placeholder="Search services & add-ons…" autocomplete="off" oninput="maFilterServices()" style="width:100%;margin:0 0 12px;">

      {{-- MARKER-PATCH-469 — category pill rail (filters the services list) --}}
      <style>
        .ma-cat-rail{display:flex;gap:8px;overflow-x:auto;padding:0 0 12px;margin:0;scrollbar-width:none}
        .ma-cat-rail::-webkit-scrollbar{display:none}
        .ma-cat-pill{flex:none;padding:7px 13px;border-radius:99px;border:0.5px solid var(--ia-border,rgba(255,255,255,.14));background:transparent;color:var(--ia-text,#f0f0f0);opacity:.72;font-size:12.5px;font-weight:600;white-space:nowrap;cursor:pointer;font-family:inherit;transition:all .12s}
        .ma-cat-pill:hover{opacity:1;border-color:var(--ia-border-strong,rgba(255,255,255,.22))}
        .ma-cat-pill.on{background:var(--ia-accent,#BEF264);color:var(--ia-accent-text,#0a0a0a);border-color:var(--ia-accent,#BEF264);opacity:1}
        .ma-cat-ct{opacity:.55;font-size:11px;margin-left:5px}
        .ma-cat-pill.on .ma-cat-ct{opacity:.75}
      </style>
      @php
        $svcCats = $availableServices->groupBy(fn ($s) => $s->category?->name ?? 'Other')->sortKeys();
      @endphp
      <div class="ma-cat-rail" id="ma-cat-rail" data-active="">
        <button type="button" class="ma-cat-pill on" data-cat="" onclick="maPickCat(this)">All <span class="ma-cat-ct">{{ $availableServices->count() }}</span></button>
        @foreach($svcCats as $catName => $items)
          <button type="button" class="ma-cat-pill" data-cat="{{ strtolower($catName) }}" onclick="maPickCat(this)">{{ $catName }} <span class="ma-cat-ct">{{ $items->count() }}</span></button>
        @endforeach
      </div>

      <div class="ma-tab-panel is-active" data-panel="service">
        @if($availableServices->isEmpty())
          <div style="padding: 18px; text-align: center; color: var(--ia-text-dim); font-size: 12.5px;">
            No active services in the catalog.
          </div>
        @else
          <div class="ma-catalog-list">
            @foreach($availableServices as $svc)
              <label class="ma-catalog-row" data-cat="{{ strtolower($svc->category?->name ?? 'Other') }}">
                <input type="radio" name="svc_choice" value="service:{{ $svc->id }}" class="ma-picker-radio">
                <div class="ma-catalog-main">
                  <div class="ma-catalog-name">{{ $svc->name }}</div>
                  @if($svc->duration_minutes || $svc->category)
                    <div class="ma-catalog-meta">{{ $svc->duration_minutes ? $svc->duration_minutes.' min' : '' }}{{ $svc->category ? ($svc->duration_minutes ? ' · ' : '').$svc->category->name : '' }}</div>
                  @endif
                </div>
                <div class="ma-catalog-price">${{ number_format($svc->price_cents / 100, 2) }}</div>
              </label>
            @endforeach
          </div>
        @endif
      </div>

      <div class="ma-tab-panel" data-panel="addon">
        @if($availableAddons->isEmpty())
          <div style="padding: 18px; text-align: center; color: var(--ia-text-dim); font-size: 12.5px;">
            No active add-ons in the catalog.
          </div>
        @else
          <div class="ma-catalog-list">
            @foreach($availableAddons as $addon)
              <label class="ma-catalog-row">
                <input type="radio" name="svc_choice" value="addon:{{ $addon->id }}" class="ma-picker-radio">
                <div class="ma-catalog-main">
                  <div class="ma-catalog-name">{{ $addon->name }}</div>
                  @if($addon->default_duration_minutes)
                    <div class="ma-catalog-meta">{{ $addon->default_duration_minutes }} min</div>
                  @endif
                </div>
                <div class="ma-catalog-price">${{ number_format($addon->price_cents / 100, 2) }}</div>
              </label>
            @endforeach
          </div>
        @endif
      </div>

    </div>
    <div class="ma-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="maCloseModal('ma-add-svc-modal')">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="ma-add-svc-submit" onclick="maSubmitAddService()">Add</button>
    </div>
  </div>
</div>

<script>
// MARKER-PATCH-158-E1
(function() {
  const APPT_URL = {!! json_encode(route('tenant.appointments.update', $appointment->id)) !!};
  const CSRF     = {!! json_encode(csrf_token()) !!};

  // Currently-targeted asset for "add service to asset" modal
  let currentAssetId = null;

  // Generic post helper
  async function post(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v));
    fd.append('_method', 'PATCH');
    fd.append('_token', CSRF);
    const r = await fetch(APPT_URL, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    });
    let data = null;
    try { data = await r.json(); } catch (e) {}
    return { ok: r.ok && data && data.ok, message: data?.message || `HTTP ${r.status}`, data };
  }

  function openModal(id)  { document.getElementById(id).classList.add('is-open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('is-open'); }
  window.maCloseModal = closeModal;

  // ---------------------- Attach asset ----------------------
  window.maOpenAttachAssetModal = function() {
    // reset
    document.getElementById('ma-new-name').value = '';
    document.getElementById('ma-new-identifier').value = '';
    document.getElementById('ma-new-notes').value = '';
    document.querySelectorAll('input[name="picker_asset_id"]').forEach(r => r.checked = false);
    // default to existing tab unless no existing
    const hasExisting = document.querySelectorAll('input[name="picker_asset_id"]').length > 0;
    maSwitchAttachTab(hasExisting ? 'existing' : 'new');
    openModal('ma-attach-modal');
  };

  window.maSwitchAttachTab = function(tab) {
    document.querySelectorAll('#ma-attach-modal .ma-tab').forEach(t => {
      t.classList.toggle('is-active', t.dataset.tab === tab);
    });
    document.querySelectorAll('#ma-attach-modal .ma-tab-panel').forEach(p => {
      p.classList.toggle('is-active', p.dataset.panel === tab);
    });
  };

  window.maSubmitAttach = async function() {
    const btn = document.getElementById('ma-attach-submit');
    btn.disabled = true;
    const activeTab = document.querySelector('#ma-attach-modal .ma-tab.is-active')?.dataset.tab;

    let result;
    if (activeTab === 'existing') {
      const sel = document.querySelector('input[name="picker_asset_id"]:checked');
      if (!sel) { alert('Pick an asset first, or use the "Add new asset" tab.'); btn.disabled = false; return; }
      result = await post({ op: 'attach_existing_asset', customer_asset_id: sel.value });
    } else {
      const name = document.getElementById('ma-new-name').value.trim();
      if (!name) { alert('Name is required.'); btn.disabled = false; return; }
      result = await post({
        op: 'attach_new_asset',
        name,
        identifier: document.getElementById('ma-new-identifier').value.trim(),
        notes:      document.getElementById('ma-new-notes').value.trim(),
      });
    }

    btn.disabled = false;
    if (!result.ok) { alert('Attach failed: ' + result.message); return; }
    location.reload();
  };

  // ---------------------- Add service to asset ----------------------
  // MARKER-PATCH-471 — unified assign picker: appointment assets + customer's saved assets + add-new, one path
  // MARKER-PATCH-472 — generalized to two modes: 'move' an existing loose line vs 'add' a new service
  let maAssignTarget = null;
  let maPickerMode = 'move';
  let maPickerService = null;
  let maServiceFirst = false;
  function maOptButton(name, sub, onClick, extraClass) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'ma-assign-opt' + (extraClass ? ' ' + extraClass : '');
    const t = document.createElement('span'); t.textContent = name; b.appendChild(t);
    if (sub) { const s = document.createElement('span'); s.className = 'ma-assign-opt-sub'; s.textContent = sub; b.appendChild(s); }
    b.addEventListener('click', onClick);
    return b;
  }
  function maSecLabel(text) {
    const d = document.createElement('div'); d.className = 'ma-assign-sec-label'; d.textContent = text; return d;
  }
  function maBuildAssignList() {
    const list = document.getElementById('ma-assign-loose-list');
    list.innerHTML = '';
    const appt = window.maApptAssets || [];
    const saved = window.maPickerAssets || [];
    const sing = window.maLooseAssetSingular || 'item';
    const plur = window.maLooseAssetPlural || (sing + 's');
    if (appt.length) {
      list.appendChild(maSecLabel('On this appointment'));
      appt.forEach(function(a) { list.appendChild(maOptButton(a.name, null, function() { maDoAssign({ target: 'appointment_asset', appointment_asset_id: a.id }); })); });
    }
    if (saved.length) {
      const owner = window.maLooseCustomerName ? (window.maLooseCustomerName + '’s ' + plur) : ('Saved ' + plur);
      list.appendChild(maSecLabel(owner));
      saved.forEach(function(a) { list.appendChild(maOptButton(a.name, a.identifier || null, function() { maDoAssign({ target: 'customer_asset', customer_asset_id: a.id }); })); });
    }
    list.appendChild(maSecLabel('Or create one'));
    list.appendChild(maOptButton('+ Add a new ' + sing, null, function() { maAssignShowNew(); }, 'ma-assign-opt--new'));
    if (maPickerMode === 'add') {
      list.appendChild(maOptButton('Assign later', 'Add it now, attach a ' + sing + ' when you know', function() { maDoAssign({ target: 'later' }); }, 'ma-assign-opt--later'));
    }
  }
  async function maDoAssign(extra) {
    let payload;
    if (maPickerMode === 'add') {
      if (!maPickerService) return;
      payload = Object.assign({ op: 'add_service_to_target', kind: maPickerService.kind, service_id: maPickerService.id }, extra);
    } else {
      if (!maAssignTarget) return;
      payload = Object.assign({ op: 'assign_loose_to_target', kind: maAssignTarget.kind, item_id: maAssignTarget.itemId }, extra);
    }
    const result = await post(payload);
    if (!result.ok) {
      if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message); else alert('Could not add: ' + result.message);
      return;
    }
    closeModal('ma-assign-loose-modal');
    if (window.IntakeToast) IntakeToast.success(maPickerMode === 'add' ? 'Service added' : 'Assigned');
    setTimeout(function() { location.reload(); }, 500);
  }
  window.maAssignShowNew = function() {
    document.getElementById('ma-assign-loose-list').style.display = 'none';
    document.getElementById('ma-assign-new-form').style.display = '';
    const n = document.getElementById('ma-assign-new-name');
    n.value = ''; document.getElementById('ma-assign-new-identifier').value = ''; document.getElementById('ma-assign-new-notes').value = '';
    setTimeout(function() { n.focus(); }, 50);
  };
  window.maAssignNewBack = function() {
    document.getElementById('ma-assign-new-form').style.display = 'none';
    document.getElementById('ma-assign-loose-list').style.display = '';
  };
  window.maAssignNewSubmit = function() {
    const name = document.getElementById('ma-assign-new-name').value.trim();
    if (!name) { alert('Name is required.'); return; }
    maDoAssign({ target: 'new', name: name, identifier: document.getElementById('ma-assign-new-identifier').value.trim(), notes: document.getElementById('ma-assign-new-notes').value.trim() });
  };
  window.maAssignLoose = function(itemId, kind) {
    maPickerMode = 'move';
    maAssignTarget = { itemId: itemId, kind: kind };
    const t = document.getElementById('ma-assign-loose-title');
    if (t) t.textContent = 'Assign to a ' + (window.maLooseAssetSingular || 'item');
    maBuildAssignList();
    document.getElementById('ma-assign-new-form').style.display = 'none';
    document.getElementById('ma-assign-loose-list').style.display = '';
    openModal('ma-assign-loose-modal');
  };
  // MARKER-PATCH-472 — service-first: pick a service, then choose which asset (or assign later)
  window.maPickServiceTarget = function(svc) {
    maPickerMode = 'add';
    maPickerService = svc;
    const sing = window.maLooseAssetSingular || 'item';
    const t = document.getElementById('ma-assign-loose-title');
    if (t) t.textContent = 'Which ' + sing + ' is “' + svc.name + '” for?';
    maBuildAssignList();
    document.getElementById('ma-assign-new-form').style.display = 'none';
    document.getElementById('ma-assign-loose-list').style.display = '';
    openModal('ma-assign-loose-modal');
  };
  // Inject "Assign to a {asset}" into each unassigned line (name cell — keeps the row grid intact)
  (function injectAssignButtons() {
    document.querySelectorAll('.ma-loose-card .line-row').forEach(function(row) {
      if (row.querySelector('.ma-assign-loose-btn')) return;
      const cell = row.firstElementChild;
      if (!cell) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ma-assign-loose-btn';
      btn.textContent = 'Assign to a ' + (window.maLooseAssetSingular || 'item');
      btn.addEventListener('click', function() { window.maAssignLoose(row.dataset.itemId, row.dataset.kind); });
      cell.appendChild(btn);
    });
  })();

  // MARKER-PATCH-472 — service-first entry: pick the service first, then choose the asset
  window.maOpenAddServiceFirst = function() {
    currentAssetId = null;
    maServiceFirst = true;
    document.getElementById('ma-add-svc-title').textContent = 'Add a service';
    document.getElementById('ma-add-svc-submit').textContent = 'Continue';
    document.querySelectorAll('input[name="svc_choice"]').forEach(function(r) { r.checked = false; });
    const svcSearch = document.getElementById('ma-svc-search');
    if (svcSearch) svcSearch.value = '';
    const catRail = document.getElementById('ma-cat-rail');
    if (catRail) { catRail.dataset.active = ''; catRail.querySelectorAll('.ma-cat-pill').forEach(function(p, i) { p.classList.toggle('on', i === 0); }); }
    maFilterServices();
    maSwitchSvcTab('service');
    openModal('ma-add-svc-modal');
    setTimeout(function() { svcSearch && svcSearch.focus(); }, 60);
  };

  window.maOpenAddServiceModal = function(appointmentAssetId, assetName) {
    currentAssetId = appointmentAssetId;
    maServiceFirst = false; // MARKER-PATCH-472 — asset-first add
    document.getElementById('ma-add-svc-submit').textContent = 'Add';
    document.getElementById('ma-add-svc-title').textContent = 'Add to ' + assetName;
    document.querySelectorAll('input[name="svc_choice"]').forEach(r => r.checked = false);
    // MARKER-PATCH-467 — reset + focus the catalog search on open
    const svcSearch = document.getElementById('ma-svc-search');
    if (svcSearch) svcSearch.value = '';
    const catRail = document.getElementById('ma-cat-rail'); // MARKER-PATCH-469 — reset to All
    if (catRail) { catRail.dataset.active = ''; catRail.querySelectorAll('.ma-cat-pill').forEach((p, i) => p.classList.toggle('on', i === 0)); }
    maFilterServices();
    maSwitchSvcTab('service');
    openModal('ma-add-svc-modal');
    setTimeout(() => { svcSearch && svcSearch.focus(); }, 60);
  };

  window.maSwitchSvcTab = function(tab) {
    document.querySelectorAll('#ma-add-svc-modal .ma-tab').forEach(t => {
      t.classList.toggle('is-active', t.dataset.tab === tab);
    });
    document.querySelectorAll('#ma-add-svc-modal .ma-tab-panel').forEach(p => {
      p.classList.toggle('is-active', p.dataset.panel === tab);
    });
    const rail = document.getElementById('ma-cat-rail'); // MARKER-PATCH-469
    if (rail) rail.style.display = (tab === 'service') ? 'flex' : 'none';
  };

  // MARKER-PATCH-469 — category pill selection
  window.maPickCat = function(btn) {
    const rail = document.getElementById('ma-cat-rail');
    rail.querySelectorAll('.ma-cat-pill').forEach(p => p.classList.toggle('on', p === btn));
    rail.dataset.active = btn.dataset.cat || '';
    maFilterServices();
  };

  // MARKER-PATCH-469 — filter by category (services) + search text (both panels)
  window.maFilterServices = function() {
    const box = document.getElementById('ma-svc-search');
    const q = (box ? box.value : '').trim().toLowerCase();
    const rail = document.getElementById('ma-cat-rail');
    const cat = rail ? (rail.dataset.active || '') : '';
    document.querySelectorAll('#ma-add-svc-modal .ma-tab-panel').forEach(panel => {
      const isService = panel.dataset.panel === 'service';
      let shown = 0;
      panel.querySelectorAll('.ma-catalog-row').forEach(row => {
        const nameEl = row.querySelector('.ma-catalog-name');
        const name = nameEl ? nameEl.textContent.toLowerCase() : '';
        const catOk = !isService || !cat || (row.dataset.cat || '') === cat;
        const qOk = !q || name.includes(q);
        const match = catOk && qOk;
        row.style.display = match ? '' : 'none';
        if (match) shown++;
      });
      let empty = panel.querySelector('.ma-catalog-noresults');
      if (shown === 0 && (q || (isService && cat))) {
        if (!empty) {
          empty = document.createElement('div');
          empty.className = 'ma-catalog-noresults';
          empty.style.cssText = 'padding:18px;text-align:center;color:var(--ia-text-dim);font-size:12.5px;';
          (panel.querySelector('.ma-catalog-list') || panel).appendChild(empty);
        }
        empty.textContent = q ? ('No services match “' + q + '.”') : 'Nothing in this category.';
        empty.style.display = '';
      } else if (empty) {
        empty.style.display = 'none';
      }
    });
  };

  window.maSubmitAddService = async function() {
    const sel = document.querySelector('input[name="svc_choice"]:checked');
    if (!sel) { alert('Pick a service or add-on first.'); return; }
    const [kind, id] = sel.value.split(':');
    if (maServiceFirst) {
      // MARKER-PATCH-472 — service-first: capture the service, then ask which asset
      const row = sel.closest('.ma-catalog-row');
      const nameEl = row ? row.querySelector('.ma-catalog-name') : null;
      const name = nameEl ? nameEl.textContent.trim() : (kind === 'addon' ? 'add-on' : 'service');
      closeModal('ma-add-svc-modal');
      maPickServiceTarget({ kind: kind, id: id, name: name });
      return;
    }
    const btn = document.getElementById('ma-add-svc-submit');
    btn.disabled = true;
    const payload = { op: 'add_service_to_asset', appointment_asset_id: currentAssetId, kind };
    if (kind === 'service') payload.service_item_id = id;
    else                    payload.addon_id        = id;
    const result = await post(payload);
    btn.disabled = false;
    if (!result.ok) { alert('Add failed: ' + result.message); return; }
    location.reload();
  };

  // ---------------------- Detach asset ----------------------
  window.maDetachAsset = async function(appointmentAssetId, assetName) {
    if (!confirm('Detach "' + assetName + '" from this appointment?\n\nServices on this asset will move to "Unassigned services" rather than being deleted.')) return;
    const result = await post({ op: 'detach_asset', appointment_asset_id: appointmentAssetId });
    if (!result.ok) { alert('Detach failed: ' + result.message); return; }
    location.reload();
  };

  // ---------------------- MARKER-PATCH-158-E2 ----------------------

  // Status pipeline click → transition.
  // MARKER-PATCH-158-G1 — Forward moves go silently. Backward moves prompt
  // via IntakeConfirm (matches legacy view's behavior). Falls back to native
  // confirm() if IntakeConfirm isn't loaded for some reason.
  (function() {
    const bar = document.querySelector('.ma-progress-bar');
    if (!bar) return;
    const currentIndex = parseInt(bar.dataset.currentIndex, 10);

    bar.querySelectorAll('.ma-progress-step').forEach(function(step) {
      step.addEventListener('click', async function() {
        if (step.classList.contains('is-current')) return;
        if (step.classList.contains('is-saving')) return;

        const newStatus = step.dataset.status;
        const label     = step.dataset.label;
        const stepIndex = parseInt(step.dataset.stepIndex, 10);
        const isBackward = stepIndex < currentIndex;

        const go = async function() {
          step.classList.add('is-saving');
          const result = await post({ op: 'status', status: newStatus });
          step.classList.remove('is-saving');
          if (!result.ok) {
            if (window.IntakeToast) IntakeToast.error('Could not change status: ' + result.message);
            else alert('Could not change status: ' + result.message);
            return;
          }
          if (window.IntakeToast) IntakeToast.success(label);
          // MARKER-PATCH-527 — completed + P&D: offer to text delivery windows
          if (result.data && result.data.propose_delivery && window.IntakeDeliveryPropose
              && IntakeDeliveryPropose.show(result.data.propose_delivery, { updateUrl: APPT_URL, csrf: CSRF })) {
            return; // modal handles the reload
          }
          setTimeout(function() { location.reload(); }, 600);
        };

        if (isBackward) {
          if (window.IntakeConfirm) {
            const ok = await window.IntakeConfirm.show({
              title:       'Move back to ' + label + '?',
              message:     'This appointment is currently further along. Going back may surprise the customer and will revert any register sale.',
              confirmText: 'Move back',
              cancelText:  'Keep where it is',
            });
            if (ok) go();
          } else {
            if (confirm('Move back to ' + label + '?')) go();
          }
        } else {
          go();
        }
      });
    });
  })();

  // Reopen button (terminal state)
  // MARKER-PATCH-158-G1 — Use IntakeConfirm to match legacy
  const reopenBtn = document.getElementById('ma-reopen-btn');
  if (reopenBtn) {
    reopenBtn.addEventListener('click', async function() {
      let proceed = false;
      if (window.IntakeConfirm) {
        proceed = await window.IntakeConfirm.show({
          title:       'Reopen this appointment?',
          message:     'This will return it to Pending status.',
          confirmText: 'Reopen',
          cancelText:  'Keep closed',
        });
      } else {
        proceed = confirm('Reopen this appointment? Status will return to pending.');
      }
      if (!proceed) return;
      const result = await post({ op: 'status', status: 'pending' });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not reopen: ' + result.message);
        else alert('Could not reopen: ' + result.message);
        return;
      }
      if (window.IntakeToast) IntakeToast.success('Reopened');
      setTimeout(function() { location.reload(); }, 600);
    });
  }

  // Inline edit (price + duration) on service/addon rows
  document.querySelectorAll('.line-edit').forEach(function(input) {
    input.addEventListener('blur', async function() {
      const row  = input.closest('.line-row');
      if (!row) return;
      const kind = row.dataset.kind;
      const id   = row.dataset.itemId;

      const durInput = row.querySelector('.line-edit[data-field="duration_minutes"]');
      const priInput = row.querySelector('.line-edit[data-field="price_dollars"]');
      const duration = durInput ? parseInt(durInput.value, 10) : null;
      const dollars  = priInput ? parseFloat(priInput.value) : null;
      const cents    = (dollars === null || isNaN(dollars)) ? null : Math.round(dollars * 100);

      const result = await post({
        op: 'update_line_item',
        kind: kind,
        item_id: id,
        price_cents: cents === null ? '' : cents,
        duration_minutes: (duration === null || isNaN(duration)) ? '' : duration,
      });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not save: ' + result.message);
        else alert('Could not save: ' + result.message);
      } else if (window.IntakeToast) {
        IntakeToast.success('Saved');
      }
    });

    // Select all on focus so editing feels snappy
    input.addEventListener('focus', function() { input.select(); });
  });

  // Remove button on each service/addon row
  document.querySelectorAll('.line-remove').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      const row  = btn.closest('.line-row');
      if (!row) return;
      const kind = row.dataset.kind;
      const id   = row.dataset.itemId;
      if (!confirm('Remove this ' + (kind === 'addon' ? 'add-on' : 'service') + '?')) return;
      const result = await post({
        op: kind === 'addon' ? 'remove_addon' : 'remove_service',
        [kind === 'addon' ? 'addon_id' : 'item_id']: id,
      });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not remove: ' + result.message);
        else alert('Could not remove: ' + result.message);
        return;
      }
      location.reload();
    });
  });

  // Asset name inline rename
  document.querySelectorAll('.asset-name-edit').forEach(function(input) {
    let originalValue = input.value;
    input.addEventListener('focus', function() { originalValue = input.value; input.select(); });
    input.addEventListener('blur', async function() {
      const newName = input.value.trim();
      if (newName === originalValue) return;
      if (newName === '') { input.value = originalValue; return; }
      const aaId = input.dataset.aaId;
      const result = await post({
        op: 'rename_appointment_asset',
        appointment_asset_id: aaId,
        name: newName,
      });
      if (!result.ok) {
        input.value = originalValue;
        if (window.IntakeToast) IntakeToast.error('Could not rename: ' + result.message);
        else alert('Could not rename: ' + result.message);
        return;
      }
      originalValue = newName;
      if (window.IntakeToast) IntakeToast.success('Renamed');
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.value = originalValue; input.blur(); }
    });
  });

  // ---------------------- MARKER-PATCH-158-E3 ----------------------

  // Add-charge form toggle
  (function() {
    const toggle = document.getElementById('ma-add-charge-toggle');
    const form   = document.getElementById('ma-add-charge-form');
    const cancel = document.getElementById('ma-add-charge-cancel');
    const dollarsInput = document.getElementById('ma-charge-amount-display');
    const centsInput   = document.getElementById('ma-charge-amount-cents');
    if (!toggle || !form) return;

    toggle.addEventListener('click', function() {
      form.style.display = 'block';
      toggle.style.display = 'none';
      setTimeout(function() { form.querySelector('input[name="description"]').focus(); }, 50);
    });
    if (cancel) cancel.addEventListener('click', function() {
      form.style.display = 'none';
      toggle.style.display = '';
      form.reset();
    });
    // Convert dollars -> cents in hidden input on submit
    form.addEventListener('submit', function(e) {
      const dollars = parseFloat(dollarsInput.value);
      if (isNaN(dollars) || dollars <= 0) {
        e.preventDefault();
        alert('Enter a valid amount.');
        return;
      }
      centsInput.value = Math.round(dollars * 100);
    });
  })();

  // Record-deposit flow
  (function() {
    const toggleBtn = document.getElementById('ma-record-deposit-toggle');
    const form      = document.getElementById('ma-record-deposit-form');
    const cancelBtn = document.getElementById('ma-record-deposit-cancel');
    const goBtn     = document.getElementById('ma-record-deposit-go');
    const amtInput  = document.getElementById('ma-record-deposit-amount');
    if (!toggleBtn || !form) return;

    toggleBtn.addEventListener('click', function() {
      form.style.display = 'block';
      toggleBtn.style.display = 'none';
      setTimeout(function() { amtInput.focus(); }, 50);
    });
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
      form.style.display = 'none';
      toggleBtn.style.display = '';
      amtInput.value = '';
    });
    if (goBtn) goBtn.addEventListener('click', async function() {
      const dollars = parseFloat(amtInput.value);
      if (isNaN(dollars) || dollars <= 0) { alert('Enter a valid amount.'); return; }
      const cents = Math.round(dollars * 100);
      goBtn.disabled = true;
      const result = await post({ op: 'record_deposit', amount_cents: cents });
      goBtn.disabled = false;
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error(result.message);
        else alert(result.message);
        return;
      }
      // Redirect to register
      const url = result.data?.redirect_url;
      if (url) { window.location.href = url; }
      else { location.reload(); }
    });
  })();

  // ---------------------- MARKER-PATCH-158-E4 — Inventory parts ----------------------

  // Part quantity inline edit
  document.querySelectorAll('.ma-part-qty-edit').forEach(function(input) {
    let originalValue = input.value;
    input.addEventListener('focus', function() { originalValue = input.value; input.select(); });
    input.addEventListener('blur', async function() {
      const newQty = parseInt(input.value, 10);
      if (isNaN(newQty) || newQty < 1) { input.value = originalValue; return; }
      if (String(newQty) === originalValue) return;
      const partId = input.dataset.partId;
      const result = await post({
        op: 'update_part_quantity',
        part_id: partId,
        quantity: newQty,
      });
      if (!result.ok) {
        input.value = originalValue;
        if (window.IntakeToast) IntakeToast.error('Could not update quantity: ' + result.message);
        else alert('Could not update quantity: ' + result.message);
        return;
      }
      // MARKER-PATCH-158-G12 — Reload so the Totals card recomputes from the
      // updated snapshot. Inline line-total update alone left the rail stale.
      if (window.IntakeToast) IntakeToast.success('Quantity updated');
      setTimeout(function() { location.reload(); }, 400);
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.value = originalValue; input.blur(); }
    });
  });

  // Part remove button
  document.querySelectorAll('.ma-part-remove').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      if (!confirm('Remove this part from the appointment?')) return;
      const partId = btn.dataset.partId;
      const result = await post({ op: 'remove_part', part_id: partId });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not remove: ' + result.message);
        else alert('Could not remove: ' + result.message);
        return;
      }
      location.reload();
    });
  });

  // MARKER-PATCH-419 — per-line "add to special orders" toggle
  document.querySelectorAll('.ma-part-so-toggle').forEach(function(box) {
    box.addEventListener('change', async function() {
      const partId = box.dataset.partId;
      const result = await post({ op: 'toggle_part_special_order', part_id: partId, enabled: box.checked ? 1 : 0 });
      if (!result.ok) {
        box.checked = !box.checked;
        if (window.IntakeToast) IntakeToast.error('Could not update: ' + result.message);
        else alert('Could not update: ' + result.message);
        return;
      }
      box.checked = !!result.data.is_special_order;
      const badge = document.querySelector('.ma-part-so-badge[data-part-id="' + partId + '"]');
      if (badge) badge.textContent = result.data.so_number || '';
      if (window.IntakeToast) {
        IntakeToast.success(result.data.is_special_order
          ? ('Added to special orders' + (result.data.so_number ? ' · ' + result.data.so_number : ''))
          : 'Removed from special orders');
      }
    });
  });

  // Part picker with autocomplete
  (function() {
    const input   = document.getElementById('ma-part-picker-input');
    const results = document.getElementById('ma-part-picker-results');
    const customForm = document.getElementById('ma-custom-item-form');
    if (!input || !results) return;

    const searchUrl = {!! json_encode(route('tenant.appointments.inventory-search')) !!};
    let debounceTimer = null;
    let lastQuery = '';

    async function doSearch(q) {
      const r = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      });
      const data = await r.json();
      renderResults(data.items || [], q);
    }

    function renderResults(items, q) {
      let html = '';
      if (items.length === 0) {
        html = '<div class="ma-part-picker-empty">No matching items.</div>';
      } else {
        items.forEach(function(it) {
          html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                  '  <div class="name">' + escapeHtml(it.name) + '</div>' +
                  '  <div class="meta">' +
                  '    <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                  '    <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                  '  </div>' +
                  '</div>';
        });
      }
      html += '<div class="ma-part-picker-custom" id="ma-picker-custom-trigger">+ Add custom item' +
              (q ? ' "' + escapeHtml(q) + '"' : '') + '</div>';
      results.innerHTML = html;
      results.style.display = 'block';

      // Wire selection
      results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
        el.addEventListener('click', async function() {
          const id = el.dataset.id;
          results.style.display = 'none';
          input.value = '';
          const result = await post({ op: 'add_part', inventory_item_id: id, quantity: 1 });
          if (!result.ok) {
            if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
            else alert('Could not add: ' + result.message);
            return;
          }
          location.reload();
        });
      });

      // Wire custom trigger
      const trig = document.getElementById('ma-picker-custom-trigger');
      if (trig) trig.addEventListener('click', function() {
        results.style.display = 'none';
        customForm.style.display = 'block';
        const nameField = document.getElementById('ma-custom-item-name');
        if (q && nameField) nameField.value = q;
        if (nameField) setTimeout(function() { nameField.focus(); }, 50);
        input.value = '';
      });
    }

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    input.addEventListener('input', function() {
      const q = input.value.trim();
      if (q === lastQuery) return;
      lastQuery = q;
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function() { doSearch(q); }, 180);
    });
    input.addEventListener('focus', function() {
      if (lastQuery !== input.value.trim() || results.innerHTML === '') {
        lastQuery = input.value.trim();
        doSearch(lastQuery);
      } else {
        results.style.display = 'block';
      }
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
      if (!input.contains(e.target) && !results.contains(e.target)) {
        results.style.display = 'none';
      }
    });

    // Custom item form save / cancel
    const customCancel = document.getElementById('ma-custom-item-cancel');
    const customSave   = document.getElementById('ma-custom-item-save');
    if (customCancel) customCancel.addEventListener('click', function() {
      customForm.style.display = 'none';
      document.getElementById('ma-custom-item-name').value = '';
      document.getElementById('ma-custom-item-price').value = '';
      document.getElementById('ma-custom-item-qty').value = '1';
    });
    if (customSave) customSave.addEventListener('click', async function() {
      const name  = document.getElementById('ma-custom-item-name').value.trim();
      const price = parseFloat(document.getElementById('ma-custom-item-price').value);
      const qty   = parseInt(document.getElementById('ma-custom-item-qty').value, 10) || 1;
      if (!name) { alert('Name is required.'); return; }
      if (isNaN(price) || price < 0) { alert('Enter a valid price.'); return; }
      customSave.disabled = true;
      const result = await post({
        op: 'add_custom_item',
        name: name,
        unit_price_cents: Math.round(price * 100),
        quantity: qty,
      });
      customSave.disabled = false;
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
        else alert('Could not add: ' + result.message);
        return;
      }
      location.reload();
    });
  })();

  // ---------------------- MARKER-PATCH-158-G4 — Per-asset part pickers ----------------------
  //
  // Same UI/UX as the loose picker above, but scoped to each asset card via
  // data-aa-id. Each asset gets its own input + results dropdown + custom-item
  // form. The asset id is passed to the backend as appointment_asset_id so the
  // part is pinned to that asset.
  (function() {
    const pickers = document.querySelectorAll('.ma-asset-part-picker');
    if (pickers.length === 0) return;

    const searchUrl = {!! json_encode(route('tenant.appointments.inventory-search')) !!};

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    pickers.forEach(function(input) {
      const aaId       = input.dataset.aaId;
      const results    = document.querySelector('.ma-asset-part-results[data-aa-id="' + aaId + '"]');
      const customForm = document.querySelector('.ma-asset-custom-form[data-aa-id="' + aaId + '"]');
      if (!results || !customForm) return;

      let debounceTimer = null;
      let lastQuery = '';

      async function doSearch(q) {
        const r = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const data = await r.json();
        renderResults(data.items || [], q);
      }

      function renderResults(items, q) {
        let html = '';
        if (items.length === 0) {
          html = '<div class="ma-part-picker-empty">No matching items.</div>';
        } else {
          items.forEach(function(it) {
            html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                    '  <div class="name">' + escapeHtml(it.name) + '</div>' +
                    '  <div class="meta">' +
                    '    <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                    '    <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                    '  </div>' +
                    '</div>';
          });
        }
        html += '<div class="ma-part-picker-custom ma-asset-picker-custom-trigger">+ Add custom item' +
                (q ? ' "' + escapeHtml(q) + '"' : '') + '</div>';
        results.innerHTML = html;
        results.hidden = false;

        results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
          el.addEventListener('click', async function() {
            const id = el.dataset.id;
            results.hidden = true;
            input.value = '';
            const result = await post({
              op: 'add_part',
              inventory_item_id: id,
              quantity: 1,
              appointment_asset_id: aaId, // MARKER-PATCH-158-G4
            });
            if (!result.ok) {
              if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
              else alert('Could not add: ' + result.message);
              return;
            }
            location.reload();
          });
        });

        const trig = results.querySelector('.ma-asset-picker-custom-trigger');
        if (trig) trig.addEventListener('click', function() {
          results.hidden = true;
          customForm.hidden = false;
          const nameField = customForm.querySelector('.ma-asset-custom-name');
          if (q && nameField) nameField.value = q;
          if (nameField) setTimeout(function() { nameField.focus(); }, 50);
          input.value = '';
        });
      }

      input.addEventListener('input', function() {
        const q = input.value.trim();
        if (q === lastQuery) return;
        lastQuery = q;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() { doSearch(q); }, 180);
      });
      input.addEventListener('focus', function() {
        if (lastQuery !== input.value.trim() || results.innerHTML === '') {
          lastQuery = input.value.trim();
          doSearch(lastQuery);
        } else {
          results.hidden = false;
        }
      });

      document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
          results.hidden = true;
        }
      });

      // Custom form cancel/save for this asset
      const cancelBtn = customForm.querySelector('.ma-asset-custom-cancel');
      const saveBtn   = customForm.querySelector('.ma-asset-custom-save');
      if (cancelBtn) cancelBtn.addEventListener('click', function() {
        customForm.hidden = true;
        customForm.querySelector('.ma-asset-custom-name').value = '';
        customForm.querySelector('.ma-asset-custom-price').value = '';
        customForm.querySelector('.ma-asset-custom-qty').value = '1';
      });
      if (saveBtn) saveBtn.addEventListener('click', async function() {
        const name  = customForm.querySelector('.ma-asset-custom-name').value.trim();
        const price = parseFloat(customForm.querySelector('.ma-asset-custom-price').value);
        const qty   = parseInt(customForm.querySelector('.ma-asset-custom-qty').value, 10) || 1;
        if (!name) { alert('Name is required.'); return; }
        if (isNaN(price) || price < 0) { alert('Enter a valid price.'); return; }
        saveBtn.disabled = true;
        const result = await post({
          op: 'add_custom_item',
          name: name,
          unit_price_cents: Math.round(price * 100),
          quantity: qty,
          appointment_asset_id: aaId, // MARKER-PATCH-158-G4
        });
        saveBtn.disabled = false;
        if (!result.ok) {
          if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
          else alert('Could not add: ' + result.message);
          return;
        }
        location.reload();
      });
    });
  })();

  // ---------------------- MARKER-PATCH-158-G5 — Per-asset work order forms ----------------------
  //
  // Each asset card has its own work-order details section. The display/edit
  // toggle and save submission are scoped to that asset via data-aa-id. The
  // form posts to save_work_order with appointment_asset_id, so the response
  // rows get pinned to that asset.
  (function() {
    document.querySelectorAll('.ma-asset-wo-edit-toggle').forEach(function(btn) {
      btn.addEventListener('click', function() {
        const aaId = btn.dataset.aaId;
        const display = document.querySelector('.ma-asset-wo-display[data-aa-id="' + aaId + '"]');
        const form    = document.querySelector('.ma-asset-wo-edit-form[data-aa-id="' + aaId + '"]');
        if (!display || !form) return;
        display.hidden = true;
        form.hidden = false;
      });
    });

    document.querySelectorAll('.ma-asset-wo-edit-cancel').forEach(function(btn) {
      btn.addEventListener('click', function() {
        const aaId = btn.dataset.aaId;
        const display = document.querySelector('.ma-asset-wo-display[data-aa-id="' + aaId + '"]');
        const form    = document.querySelector('.ma-asset-wo-edit-form[data-aa-id="' + aaId + '"]');
        if (!display || !form) return;
        form.hidden = true;
        display.hidden = false;
      });
    });

    document.querySelectorAll('.ma-asset-wo-edit-form').forEach(function(form) {
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const aaId = form.dataset.aaId;
        const url  = form.dataset.updateUrl;

        const fd = new FormData(form);
        fd.append('_token', {!! json_encode(csrf_token()) !!});
        fd.append('_method', 'PATCH');
        fd.append('op', 'save_work_order');
        // appointment_asset_id is already included via the hidden input in the form

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
          const r = await fetch(url, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          });
          let data = null;
          try { data = await r.json(); } catch(e) {}
          if (!r.ok || !data || !data.ok) {
            if (window.IntakeToast) IntakeToast.error((data && data.message) || 'Could not save.');
            else alert((data && data.message) || 'Could not save.');
            if (submitBtn) submitBtn.disabled = false;
            return;
          }
          if (window.IntakeToast) IntakeToast.success('Work order saved');
          setTimeout(function() { location.reload(); }, 500);
        } catch (err) {
          if (window.IntakeToast) IntakeToast.error('Network error. Try again.');
          else alert('Network error. Try again.');
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });
  })();

  // ---------------------- MARKER-PATCH-158-E5 — Notes + Work order ----------------------

  // Work order: Edit / display toggle
  (function() {
    const card        = document.getElementById('ma-wo-card');
    if (!card) return;
    const editToggle  = document.getElementById('ma-wo-edit-toggle');
    const displayDiv  = document.getElementById('ma-wo-display');
    const editForm    = document.getElementById('ma-wo-edit-form');
    const editCancel  = document.getElementById('ma-wo-edit-cancel');
    if (!editToggle || !displayDiv || !editForm) return;

    editToggle.addEventListener('click', function() {
      displayDiv.style.display = 'none';
      editForm.style.display = 'block';
      editToggle.style.display = 'none';
    });
    if (editCancel) editCancel.addEventListener('click', function() {
      displayDiv.style.display = '';
      editForm.style.display = 'none';
      editToggle.style.display = '';
    });
    // Form submit goes through normal PATCH redirect (full page reload after save_work_order)
  })();

  // Notes: char count
  (function() {
    const input = document.getElementById('ma-note-input');
    const counter = document.getElementById('ma-note-char-count');
    if (!input || !counter) return;
    function updateCount() {
      const remaining = 500 - input.value.length;
      counter.textContent = remaining;
      counter.style.color = remaining < 50 ? '#f87171' : '';
    }
    input.addEventListener('input', updateCount);
    updateCount();
  })();

  // Notes: add note
  (function() {
    const submitBtn  = document.getElementById('ma-note-submit');
    const input      = document.getElementById('ma-note-input');
    const visBox     = document.getElementById('ma-note-customer-visible');
    const errEl      = document.getElementById('ma-note-error');
    if (!submitBtn || !input) return;

    submitBtn.addEventListener('click', async function() {
      const note = input.value.trim();
      errEl.style.display = 'none';
      if (!note) {
        errEl.textContent = 'Note can\'t be empty.';
        errEl.style.display = 'block';
        return;
      }
      submitBtn.disabled = true;
      const result = await post({
        op: 'add_note',
        note: note,
        is_customer_visible: visBox && visBox.checked ? '1' : '0',
      });
      submitBtn.disabled = false;
      if (!result.ok) {
        errEl.textContent = 'Could not save: ' + result.message;
        errEl.style.display = 'block';
        return;
      }
      // Clear + reload to render the new note
      input.value = '';
      if (visBox) visBox.checked = false;
      location.reload();
    });
  })();

  // Notes: delete
  document.querySelectorAll('.ma-note-delete').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      if (!confirm('Delete this note?')) return;
      const noteId = btn.dataset.noteId;
      const result = await post({ op: 'delete_note', note_id: noteId });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not delete: ' + result.message);
        else alert('Could not delete: ' + result.message);
        return;
      }
      // Remove the note element directly without reload
      const noteEl = btn.closest('.ma-note');
      if (noteEl) noteEl.remove();
    });
  });

  // Escape closes any open modal
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.ma-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
  });
})();
</script>

{{-- MARKER-PATCH-158-G2 — Shared reschedule modal partial (markup + JS) --}}
@include('tenant.appointments._reschedule_modal')
@include('tenant.appointments._delivery_propose_modal'){{-- MARKER-PATCH-527 --}}

{{-- MARKER-PATCH-158-G2 — Resource picker save handler (shared with legacy view) --}}
@push('scripts')
<script src="{{ asset('js/tenant/appointment-resource.js') }}?v={{ filemtime(public_path('js/tenant/appointment-resource.js')) }}" defer></script>
<script>
// MARKER-PATCH-158-G2 — Cancel-appointment handler (mirrors legacy)
(function() {
  const cancelBtn = document.querySelector('.ma-cancel-btn');
  if (!cancelBtn) return;
  cancelBtn.addEventListener('click', async function() {
    const proceed = window.IntakeConfirm
      ? await window.IntakeConfirm.show({
          title:       'Cancel this appointment?',
          message:     "The appointment will be removed from the calendar and the customer's slot released. This stays in your records but won't show on the active schedule.",
          confirmText: 'Cancel appointment',
          cancelText:  'Keep it',
          danger:      true,
        })
      : confirm('Cancel this appointment?');
    if (!proceed) return;
    const fd = new FormData();
    fd.append('_token', {!! json_encode(csrf_token()) !!});
    fd.append('_method', 'PATCH');
    fd.append('op', 'status');
    fd.append('status', 'cancelled');
    const r = await fetch({!! json_encode(route('tenant.appointments.update', $appointment->id)) !!}, {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    });
    let data = null;
    try { data = await r.json(); } catch(e) {}
    if (!r.ok || !data || !data.ok) {
      if (window.IntakeToast) IntakeToast.error((data && data.message) || 'Could not cancel.');
      else alert((data && data.message) || 'Could not cancel.');
      return;
    }
    if (window.IntakeToast) IntakeToast.success('Cancelled');
    setTimeout(function() { window.location.href = {!! json_encode(route('tenant.calendar.index')) !!}; }, 600);
  });
})();
</script>
@endpush

@endsection
BIZ3_11_EOF

cat > 'resources/views/tenant/appointments/_create_modal.blade.php' <<'BIZ3_12_EOF'
{{--
  New Appointment modal — availability-first design.

  Sections:
    1. Customer (search-or-create)
    2. Services (multi-select with in-line price override)
    3. When (NEW: next-available suggestion + alternatives + manual override)
    4. Notes

  Key differences from prior version:
    - "When" is the system's job, not the user's. Once services are picked, the
      modal asks pickerData?service_ids[]=... and surfaces the earliest slot.
    - "Pick another time" expands a manual override (date + time + resource).
    - Adding/removing services refires availability lookup (300ms debounce).
--}}
<div id="new-appt-modal" style="display:none">
  <style>
    #new-appt-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.6);
      backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: flex-start; justify-content: center;
      padding: 40px 20px; overflow-y: auto;
      animation: appt-fade .2s ease-out;
    }
    @keyframes appt-fade { from { opacity: 0; } to { opacity: 1; } }
    #new-appt-card {
      background: var(--ia-surface, #1a1a1a);
      color: var(--ia-text, #f0f0f0);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-lg, 16px);
      width: 100%; max-width: 580px;
      animation: appt-pop .25s cubic-bezier(.2,1.1,.3,1);
    }
    @keyframes appt-pop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    .appt-head { padding: 22px 26px 0; display: flex; justify-content: space-between; align-items: center; }
    .appt-title { font-size: 20px; font-weight: 700; }
    .appt-close { background: none; border: none; color: inherit; font-size: 24px; cursor: pointer; opacity: .5; padding: 4px 8px; line-height: 1; }
    .appt-close:hover { opacity: 1; }

    .appt-body { padding: 18px 26px; }
    .appt-section { margin-bottom: 22px; }
    .appt-section-h { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; opacity: .55; margin-bottom: 10px; }

    .appt-field { margin-bottom: 12px; }
    .appt-label { display: block; font-size: 12px; opacity: .7; margin-bottom: 5px; }
    .appt-input {
      width: 100%; padding: 9px 12px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      color: var(--ia-text, #f0f0f0); font-size: 14px; font-family: inherit;
      transition: border-color .12s; box-sizing: border-box;
    }
    .appt-input:focus { outline: none; border-color: var(--ia-accent, #BEF264); }
    .appt-textarea { resize: vertical; min-height: 60px; }
    .appt-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .appt-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

    /* Customer search */
    .appt-cust-results { background: var(--ia-surface-2, #222); border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; margin-top: 4px; max-height: 180px; overflow-y: auto; }
    .appt-cust-row { padding: 8px 12px; cursor: pointer; font-size: 13px; }
    .appt-cust-row:hover { background: rgba(255,255,255,.06); }
    .appt-cust-row .meta { font-size: 11px; opacity: .55; }
    .appt-cust-attached { background: var(--ia-surface-2, #222); border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .appt-cust-attached .clear { font-size: 11px; opacity: .55; cursor: pointer; }
    .appt-cust-attached .clear:hover { opacity: 1; color: #f39999; }

    /* Service picker */
    .appt-svc-list { display: flex; flex-direction: column; gap: 6px; }
    .appt-svc-row { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; padding: 8px 10px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 13px; }
    .appt-svc-row .name { font-weight: 500; }
    .appt-svc-row .meta { font-size: 11px; opacity: .55; }
    .appt-svc-price-edit { width: 88px; padding: 5px 8px; background: rgba(255,255,255,.04); border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 6px; color: inherit; font-size: 13px; text-align: right; }
    .appt-svc-price-edit.overridden { border-color: var(--ia-accent, #BEF264); color: var(--ia-accent, #BEF264); }
    .appt-svc-remove { font-size: 14px; opacity: .55; cursor: pointer; padding: 4px 8px; }
    .appt-svc-remove:hover { opacity: 1; color: #f39999; }
    .appt-svc-totals { margin-top: 8px; padding-top: 8px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); display: flex; justify-content: space-between; font-size: 12px; opacity: .8; }
    .appt-svc-totals strong { font-weight: 600; opacity: 1; }
    .appt-svc-add-btn { margin-top: 8px; width: 100%; padding: 8px; background: transparent; border: 0.5px dashed var(--ia-border, rgba(255,255,255,.2)); border-radius: 8px; color: inherit; opacity: .65; font-size: 12px; font-family: inherit; cursor: pointer; }
    .appt-svc-add-btn:hover { opacity: 1; border-color: var(--ia-accent, #BEF264); }
    .appt-svc-picker { background: var(--ia-surface-2, #222); border-radius: 8px; padding: 8px; max-height: 200px; overflow-y: auto; margin-top: 6px; }
    .appt-svc-picker-row { padding: 6px 10px; cursor: pointer; font-size: 13px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; }
    .appt-svc-picker-row:hover { background: rgba(255,255,255,.06); }

    /* Day strip picker */
    .appt-strip-wrap { display: flex; align-items: center; gap: 4px; margin-bottom: 12px; }
    .appt-strip-arrow { font-size: 18px; opacity: .5; cursor: pointer; padding: 4px 8px; user-select: none; }
    .appt-strip-arrow:hover { opacity: 1; }
    .appt-strip-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; flex: 1; }
    .appt-strip-day {
      text-align: center;
      padding: 8px 4px;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-strip-day:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-strip-day.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
    }
    .appt-strip-day.disabled { opacity: .35; cursor: not-allowed; }
    .appt-strip-day.disabled:hover { border-color: transparent; }
    .appt-strip-dow { font-size: 10px; text-transform: uppercase; opacity: .55; letter-spacing: .04em; }
    .appt-strip-num { font-size: 14px; font-weight: 500; margin: 1px 0; }
    .appt-strip-meta { font-size: 9px; opacity: .55; }
    .appt-strip-day.selected .appt-strip-dow,
    .appt-strip-day.selected .appt-strip-meta { color: var(--ia-accent, #BEF264); opacity: 1; }
    .appt-strip-day.selected .appt-strip-num { color: var(--ia-accent, #BEF264); }

    .appt-times-label { font-size: 11px; opacity: .55; margin-bottom: 6px; }
    .appt-times-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .appt-time-btn {
      padding: 8px 4px;
      text-align: center;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-time-btn:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-time-btn.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
      color: var(--ia-accent, #BEF264);
      font-weight: 500;
    }
    .appt-times-empty { font-size: 12px; opacity: .55; padding: 12px; text-align: center; background: var(--ia-surface-2, #222); border-radius: 6px; }
    .appt-resolved-resource { font-size: 11px; opacity: .65; margin-top: 10px; }
    .appt-resolved-resource a { color: var(--ia-accent, #BEF264); cursor: pointer; }

    /* Availability section */
    .appt-when-empty { padding: 14px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 12px; opacity: .55; text-align: center; }
    .appt-when-loading { padding: 14px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 12px; opacity: .65; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .appt-when-card {
      padding: 14px;
      background: rgba(190, 242, 100, 0.08);
      border: 0.5px solid var(--ia-accent, #BEF264);
      border-radius: 8px;
      margin-bottom: 8px;
    }
    .appt-when-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
    .appt-when-card-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-accent, #BEF264); }
    .appt-when-card-pick { font-size: 11px; color: var(--ia-accent, #BEF264); cursor: pointer; opacity: .85; }
    .appt-when-card-pick:hover { opacity: 1; }
    .appt-when-card-time { font-size: 15px; font-weight: 500; color: var(--ia-text, #f0f0f0); }
    .appt-when-none { padding: 14px; background: rgba(226,75,74,.10); border: 0.5px solid rgba(226,75,74,.25); border-radius: 8px; font-size: 13px; color: #f39999; }
    .appt-when-alts { margin-top: 10px; }
    .appt-when-alts-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
    .appt-when-alts-label { font-size: 11px; opacity: .55; }
    .appt-when-alts-nav { display: flex; gap: 6px; }
    .appt-when-alts-arrow { width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border-radius: 4px; background: rgba(255,255,255,.04); cursor: pointer; font-size: 14px; opacity: .65; user-select: none; }
    .appt-when-alts-arrow:hover { opacity: 1; background: rgba(255,255,255,.08); }
    .appt-when-alts-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-when-alts-track { display: flex; gap: 6px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; -ms-overflow-style: none; scroll-behavior: smooth; }
    .appt-when-alts-track::-webkit-scrollbar { display: none; }
    .appt-when-alt-row { flex: 0 0 calc((100% - 12px) / 3); scroll-snap-align: start; display: flex; flex-direction: column; justify-content: center; gap: 3px; padding: 10px 12px; border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; cursor: pointer; font-size: 13px; min-height: 52px; box-sizing: border-box; }
    .appt-when-alt-row:hover { border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-row.selected { background: rgba(190, 242, 100, 0.08); border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appt-when-alt-time { font-size: 11px; opacity: .65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appt-when-manual-toggle { font-size: 11px; color: var(--ia-text-muted, #999); cursor: pointer; margin-top: 10px; display: inline-block; }
    .appt-when-manual-toggle:hover { color: var(--ia-text, #f0f0f0); }
    .appt-when-manual { margin-top: 10px; padding-top: 10px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); }

    .appt-foot { padding: 16px 26px 22px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); display: flex; justify-content: flex-end; gap: 10px; }
    .appt-btn { padding: 10px 18px; border-radius: var(--ia-r-md, 8px); font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; border: none; transition: filter .12s; }
    .appt-btn--cancel { background: rgba(255,255,255,.06); color: var(--ia-text, #f0f0f0); }
    .appt-btn--create { background: var(--ia-accent, #BEF264); color: #000; }
    .appt-btn:hover { filter: brightness(.92); }
    .appt-btn:disabled { opacity: .5; cursor: not-allowed; }
    .appt-err { background: rgba(226,75,74,.12); color: #f39999; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 12px; display: none; }
    .appt-spin { display: inline-block; width: 12px; height: 12px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: appt-spin .6s linear infinite; vertical-align: -2px; margin-right: 6px; }
    @keyframes appt-spin { to { transform: rotate(360deg); } }
    
    /* SEQUENTIAL-PICKER-CSS v1 */
    .appt-sp-times-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; flex-wrap:wrap; }
    .appt-sp-week-nav { display:flex; align-items:center; gap:6px; font-size:11px; }
    .appt-sp-week-btn {
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      color: inherit;
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 4px;
      cursor: pointer;
      font-family: inherit;
    }
    .appt-sp-week-btn:hover:not(:disabled) { background: rgba(255,255,255,.08); }
    .appt-sp-week-btn:disabled { opacity: .35; cursor: not-allowed; }
    .appt-sp-week-label { opacity: .65; min-width: 100px; text-align: center; }
    .appt-sp-times-list {
      max-height: 240px;       /* ~5 rows visible */
      overflow-y: auto;
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: 8px;
      background: rgba(255,255,255,.02);
    }
    .appt-sp-time-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 14px;
      border-bottom: 0.5px solid var(--ia-border, rgba(255,255,255,.06));
      cursor: pointer;
      font-size: 13px;
    }
    .appt-sp-time-row:last-child { border-bottom: none; }
    .appt-sp-time-row:hover { background: rgba(190,242,100,0.06); }
    .appt-sp-time-row.selected { background: rgba(190,242,100,0.12); border-left: 2px solid var(--ia-accent, #BEF264); padding-left: 12px; }
    .appt-sp-time-date { opacity: .65; font-size: 12px; }
    .appt-sp-time-time { font-weight: 500; }
    .appt-sp-times-empty {
      padding: 18px 14px;
      text-align: center;
      font-size: 12px;
      opacity: .55;
    }
    .appt-sp-times-empty.error { color: #f39999; opacity: .8; }
  </style>

  <div id="new-appt-backdrop">
    <div id="new-appt-card">
      <div class="appt-head">
        <span class="appt-title">New Appointment</span>
        <button type="button" class="appt-close" onclick="ApptModal.close()">&times;</button>
      </div>

      <div class="appt-body">
        <div id="appt-error" class="appt-err"></div>

        {{-- Customer --}}
        <div class="appt-section">
          <div class="appt-section-h">Customer</div>
          <div id="appt-cust-search-wrap">
            <input type="search" id="appt-cust-search" class="appt-input" placeholder="Search by name, email, or phone…" autocomplete="off">
            <div id="appt-cust-results" class="appt-cust-results" style="display:none"></div>
            <div id="appt-cust-new-fields" style="display:none; margin-top:10px">
              <div class="appt-row">
                <input type="text" id="appt-first" class="appt-input" placeholder="First name *">
                <input type="text" id="appt-last"  class="appt-input" placeholder="Last name *">
              </div>
              <div class="appt-row" style="margin-top:8px">
                <input type="email" id="appt-email" class="appt-input" placeholder="Email *">
                <input type="tel"   id="appt-phone" class="appt-input" placeholder="Phone">
              </div>
              <div style="font-size:11px;opacity:.55;margin-top:6px">No match — a new customer will be created.</div>
            </div>
          </div>
          <div id="appt-cust-attached" class="appt-cust-attached" style="display:none">
            <div>
              <div id="appt-cust-attached-name" style="font-weight:500"></div>
              <div id="appt-cust-attached-meta" style="font-size:11px;opacity:.55"></div>
            </div>
            <span class="clear" onclick="ApptModal.clearCustomer()">Remove</span>
          </div>
        </div>

        {{-- SEQUENTIAL-PICKER v1 --}}
        <div class="appt-section">
          <div class="appt-section-h">Service</div>
          {{-- SERVICE-TYPEAHEAD v1 — register-style search over the loaded catalog --}}
          <div id="appt-sp-service-wrap" style="position:relative">
            <input type="text" id="appt-sp-service-search" class="appt-input"
                   placeholder="Search services…" autocomplete="off">
            <div id="appt-sp-service-results" class="appt-cust-results" style="display:none"></div>
          </div>
          <div id="appt-sp-service-selected" class="appt-cust-attached" style="display:none">
            <div>
              <div id="appt-sp-service-selected-name" style="font-weight:500"></div>
              <div id="appt-sp-service-selected-meta" style="font-size:11px;opacity:.55"></div>
            </div>
            <span class="clear" onclick="ApptModal.clearService()">Change</span>
          </div>
          <p class="appt-sp-note" style="font-size:11px; opacity:.55; margin-top:6px;">
            You can add more services on the next page after creating the appointment.
          </p>
        </div>

        <div class="appt-section" id="appt-sp-resource-section" style="display:none">
          <div class="appt-section-h">Resource</div>
          <select id="appt-sp-resource" class="appt-input">
            <option value="">Select a resource…</option>
          </select>
        </div>

        <div class="appt-section" id="appt-sp-find-section" style="display:none">
          <button type="button" class="appt-btn appt-btn--cancel" id="appt-sp-find" style="width:100%; padding:10px;">
            Show available times
          </button>
        </div>

        <div class="appt-section" id="appt-sp-times-section" style="display:none">
          <div class="appt-sp-times-head">
            <div class="appt-section-h" style="margin-bottom:0">Available times</div>
            <div class="appt-sp-week-nav">
              <button type="button" class="appt-sp-week-btn" id="appt-sp-prev-week" disabled>← Prev week</button>
              <span class="appt-sp-week-label" id="appt-sp-week-label">—</span>
              <button type="button" class="appt-sp-week-btn" id="appt-sp-next-week">Next week →</button>
            </div>
          </div>
          <div class="appt-sp-times-list" id="appt-sp-times-list">
            <div class="appt-sp-times-empty">Loading…</div>
          </div>
        </div>

        {{-- Notes --}}
        <div class="appt-section">
          <div class="appt-section-h">Staff Notes (optional)</div>
          <textarea id="appt-notes" class="appt-input appt-textarea" placeholder="Internal notes about this appointment…"></textarea>
        </div>

        {{-- MARKER-PATCH-519 — pickup window + need-by (route tenants only) --}}
        @php
          $pdModalWindows = $currentTenant->deliveries_enabled
              ? \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $currentTenant->id)->active()->get()
              : collect();
        @endphp
        @if($pdModalWindows->isNotEmpty())
        <div id="appt-pd-wrap" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:180px">
            <label style="display:block;font-size:11px;color:var(--ia-text-muted);margin-bottom:4px">Pickup window <span style="opacity:.6">(optional — picks the chosen date)</span></label>
            <select id="appt-pd-window" class="appt-input">
              <option value="">No pickup — customer brings it</option>
              @foreach($pdModalWindows as $w)
                <option value="{{ $w->id }}" data-days="{{ implode(',', $w->days ?? []) }}">{{ $w->label }} · {{ $w->max_stops }} stops/day</option>
              @endforeach
            </select>
          </div>
          <div>
            <label style="display:block;font-size:11px;color:var(--ia-text-muted);margin-bottom:4px">Need by</label>
            <input type="date" id="appt-pd-needby" class="appt-input">
          </div>
        </div>
        <script>
        (function () {
          // MARKER-PATCH-519 — grey window options that don't run on the picked date
          window.apptPdFilter = function (dateStr) {
            var sel = document.getElementById('appt-pd-window');
            if (!sel || !dateStr) return;
            var d = new Date(dateStr + 'T12:00:00');
            var iso = d.getDay() === 0 ? 7 : d.getDay();
            Array.prototype.forEach.call(sel.options, function (o) {
              if (!o.value) return;
              var days = (o.dataset.days || '').split(',');
              var ok = days.indexOf(String(iso)) !== -1;
              o.disabled = !ok;
              if (!ok && o.selected) sel.value = '';
            });
            var nb = document.getElementById('appt-pd-needby');
            if (nb) nb.min = dateStr;
          };
        })();
        </script>
        @endif
      </div>

      <div class="appt-foot">
        <button type="button" class="appt-btn appt-btn--cancel" onclick="ApptModal.close()">Cancel</button>
        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="ApptModal.submit()">Create Appointment</button>
      </div>
    </div>
  </div>
</div>

<script>
window.ApptModal = (function () {
  // SEQUENTIAL-PICKER-STATE v1
  var state = {
    services: [],
    resources: [],          // all active (loaded once for caching, but not used for picker)
    eligibleResources: [],  // narrowed by selected service
    cart: [],               // single-element at launch (one service); prepped for future multi
    customerId: null,
    pickerOpen: false,
    selectedSlot: null,     // {date, time, resource_id}
    selectedServiceId: null,
    selectedResourceId: null,
    selectedResourceName: '',
    weekStartDate: null,    // YYYY-MM-DD; advances on next/prev week
    availSlots: [],
    availLoading: false,
  };

  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
    eligibleResources: "{{ route('tenant.appointments.eligible-resources') }}",
    weekTimes:         "{{ route('tenant.appointments.week-times') }}",
  };

  var custSearchTimer = null;
  var availTimer = null;

  function fmt(cents) { return '$' + (cents / 100).toFixed(2); }
  function el(id) { return document.getElementById(id); }

  function showError(msg) { var e = el('appt-error'); e.textContent = msg; e.style.display = 'block'; }
  function clearError() { el('appt-error').style.display = 'none'; }

  function loadInitialData() {
    fetch(routes.pickerData, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.services  = data.services  || [];
        state.resources = data.resources || [];
        populateServices();
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }

  // SERVICE-TYPEAHEAD v1 — filterable list over state.services.
  function svcMeta(svc) {
    // SERVICE-LABEL-DEDUPE v1: skip "(N min)" suffix if the name already has one.
    var nameHasDuration = /\(\s*\d+\s*min\s*\)/i.test(svc.name);
    var dur = (svc.duration_minutes && !nameHasDuration) ? svc.duration_minutes + ' min' : '';
    var price = (svc.price_cents != null) ? fmt(svc.price_cents) : '';
    return [dur, price].filter(Boolean).join(' · ');
  }
  var svcHighlight = -1;
  function svcFiltered() {
    var q = (el('appt-sp-service-search').value || '').toLowerCase().trim();
    var list = state.services.slice().sort(function (a, b) { return a.name.localeCompare(b.name); });
    if (!q) return list;
    return list.filter(function (svc) { return svc.name.toLowerCase().indexOf(q) !== -1; });
  }
  function renderServiceResults() {
    var box = el('appt-sp-service-results');
    var list = svcFiltered();
    if (!list.length) {
      box.innerHTML = '<div class="appt-cust-row" style="cursor:default;opacity:.55">No matching services.</div>';
      box.style.display = 'block';
      return;
    }
    box.innerHTML = list.map(function (svc, i) {
      return '<div class="appt-cust-row' + (i === svcHighlight ? '" style="background:rgba(255,255,255,.06)"' : '"')
        + ' data-svc-id="' + svc.id + '">'
        + '<div>' + escapeHtml(svc.name) + '</div>'
        + '<div class="meta">' + escapeHtml(svcMeta(svc)) + '</div></div>';
    }).join('');
    box.style.display = 'block';
    var hi = box.children[svcHighlight];
    if (hi && hi.scrollIntoView) hi.scrollIntoView({ block: 'nearest' });
  }
  function hideServiceResults() {
    svcHighlight = -1;
    var box = el('appt-sp-service-results');
    if (box) box.style.display = 'none';
  }
  function selectService(id) {
    var svc = null;
    state.services.forEach(function (x) { if (String(x.id) === String(id)) svc = x; });
    if (!svc) return;
    el('appt-sp-service-selected-name').textContent = svc.name;
    el('appt-sp-service-selected-meta').textContent = svcMeta(svc);
    el('appt-sp-service-selected').style.display = 'flex';
    el('appt-sp-service-wrap').style.display = 'none';
    hideServiceResults();
    applyServiceSelection(String(svc.id));
  }
  function clearService() {
    el('appt-sp-service-selected').style.display = 'none';
    el('appt-sp-service-wrap').style.display = 'block';
    var inp = el('appt-sp-service-search');
    inp.value = '';
    applyServiceSelection('');
    inp.focus();
  }
  function populateServices() {
    // Typeahead renders on demand; nothing to pre-populate.
  }

  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.selectedSlot = null;
    state.selectedServiceId = null;
    state.selectedResourceId = null;
    state.selectedResourceName = '';
    state.weekStartDate = todayStr();
    state.availSlots = [];
    state.availLoading = false;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function (id) { el(id).value = ''; });
    // Reset sequential picker UI
    el('appt-sp-service-search').value = '';
    el('appt-sp-service-selected').style.display = 'none';
    el('appt-sp-service-wrap').style.display = 'block';
    hideServiceResults();
    el('appt-sp-resource').innerHTML = '<option value="">Select a resource…</option>';
    el('appt-sp-resource-section').style.display = 'none';
    el('appt-sp-find-section').style.display = 'none';
    el('appt-sp-times-section').style.display = 'none';
    el('appt-sp-times-list').innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    populateServices();
    el('appt-sp-service-search').focus();
  }

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }

  function close() { el('new-appt-modal').style.display = 'none'; }

  // ── Customer search ──
  el('appt-cust-search').addEventListener('input', function () {
    clearTimeout(custSearchTimer);
    var q = this.value.trim();
    if (q.length < 2) {
      el('appt-cust-results').style.display = 'none';
      el('appt-cust-new-fields').style.display = 'none';
      return;
    }
    custSearchTimer = setTimeout(function () {
      fetch(routes.pickerData + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderCustomerResults(data.customers || [], q); });
    }, 250);
  });

  function renderCustomerResults(customers, query) {
    var box = el('appt-cust-results');
    if (customers.length === 0) {
      box.style.display = 'none';
      el('appt-cust-new-fields').style.display = 'block';
      var parts = query.split(/\s+/);
      if (parts.length >= 2 && !query.includes('@') && !/\d/.test(query)) {
        el('appt-first').value = parts[0];
        el('appt-last').value = parts.slice(1).join(' ');
      }
      return;
    }
    box.innerHTML = '';
    customers.forEach(function (c) {
      var row = document.createElement('div');
      row.className = 'appt-cust-row';
      row.innerHTML = '<div>' + escapeHtml(c.name || (c.first_name + ' ' + c.last_name)) + '</div>' // MARKER-BIZ-NAME
        + '<div class="meta">' + escapeHtml(c.email || c.phone || '') + '</div>';
      row.addEventListener('click', function () { attachCustomer(c); });
      box.appendChild(row);
    });
    box.style.display = 'block';
    el('appt-cust-new-fields').style.display = 'none';
  }

  function attachCustomer(c) {
    state.customerId = c.id;
    el('appt-cust-attached-name').textContent = (c.name || (c.first_name + ' ' + c.last_name)).trim(); // MARKER-BIZ-NAME
    el('appt-cust-attached-meta').textContent = c.email || c.phone || '';
    el('appt-cust-attached').style.display = 'flex';
    el('appt-cust-search-wrap').style.display = 'none';
  }

  function clearCustomer() {
    state.customerId = null;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-search').focus();
  }

  // ── Service picker ──
  function toggleServicePicker() {
    state.pickerOpen = !state.pickerOpen;
    if (state.pickerOpen) { renderServicePicker(); el('appt-svc-picker').style.display = 'block'; }
    else { el('appt-svc-picker').style.display = 'none'; }
  }

  function renderServicePicker() {
    var box = el('appt-svc-picker');
    if (state.services.length === 0) {
      box.innerHTML = '<div style="padding:8px;font-size:12px;opacity:.55">No services available.</div>';
      return;
    }
    box.innerHTML = '';
    state.services.forEach(function (s) {
      var row = document.createElement('div');
      row.className = 'appt-svc-picker-row';
      row.innerHTML = '<span>' + escapeHtml(s.name) + '</span>'
        + '<span style="opacity:.6;font-size:11px">' + s.duration_minutes + ' min · ' + fmt(s.price_cents) + '</span>';
      row.addEventListener('click', function () { addServiceToCart(s); });
      box.appendChild(row);
    });
  }

  function addServiceToCart(s) {
    // SEQUENTIAL-PICKER-DEAD-PATH: cart helpers retained for compat, no-op in new flow.
    state.cart.push({ service_item_id: s.id, name: s.name, duration: s.duration_minutes, price: s.price_cents, override: null });
    state.pickerOpen = false;
    el('appt-svc-picker').style.display = 'none';
    renderCart();
  }

  function removeFromCart(idx) {
    state.cart.splice(idx, 1);
    renderCart();
  }

  function setOverride(idx, dollarStr) {
    var clean = dollarStr.replace(/[^\d.]/g, '');
    if (clean === '') { state.cart[idx].override = null; }
    else {
      var cents = Math.round(parseFloat(clean) * 100);
      if (isNaN(cents)) cents = null;
      state.cart[idx].override = cents;
    }
    renderTotals();
  }

  function renderCart() {
    var list = el('appt-svc-list');
    if (state.cart.length === 0) {
      list.innerHTML = '<div style="font-size:12px;opacity:.5;padding:6px 0">No services selected.</div>';
      el('appt-svc-totals').style.display = 'none';
      return;
    }
    list.innerHTML = '';
    state.cart.forEach(function (line, idx) {
      var effective = line.override !== null ? line.override : line.price;
      var displayValue = (effective / 100).toFixed(2);
      var overridden = line.override !== null && line.override !== line.price;
      var row = document.createElement('div');
      row.className = 'appt-svc-row';
      row.innerHTML = '<div>'
        + '<div class="name">' + escapeHtml(line.name) + '</div>'
        + '<div class="meta">' + line.duration + ' min · catalog ' + fmt(line.price) + (overridden ? ' · <span style="color:#BEF264">overridden</span>' : '') + '</div>'
        + '</div>'
        + '<input type="text" class="appt-svc-price-edit ' + (overridden ? 'overridden' : '') + '" value="' + displayValue + '" data-idx="' + idx + '">'
        + '<span class="appt-svc-remove" data-idx="' + idx + '">&times;</span>';
      list.appendChild(row);
    });
    list.querySelectorAll('.appt-svc-price-edit').forEach(function (input) {
      input.addEventListener('change', function () { setOverride(parseInt(this.dataset.idx, 10), this.value); });
      input.addEventListener('blur',   function () { renderCart(); });
    });
    list.querySelectorAll('.appt-svc-remove').forEach(function (x) {
      x.addEventListener('click', function () { removeFromCart(parseInt(this.dataset.idx, 10)); });
    });
    renderTotals();
  }

  function renderTotals() {
    var total = 0, dur = 0;
    state.cart.forEach(function (line) {
      total += (line.override !== null ? line.override : line.price);
      dur   += line.duration;
    });
    el('appt-svc-count').textContent = state.cart.length + ' service' + (state.cart.length === 1 ? '' : 's');
    el('appt-svc-duration').textContent = dur + ' min';
    el('appt-svc-total').textContent = fmt(total);
    el('appt-svc-totals').style.display = 'flex';
  }

  // SEQUENTIAL-PICKER-HANDLERS v1
  // Service change → load eligible resources, reset downstream UI.
  function applyServiceSelection(serviceId) {
    state.selectedServiceId = serviceId || null;
    state.selectedResourceId = null;
    state.selectedResourceName = '';
    state.selectedSlot = null;
    el('appt-sp-resource').innerHTML = '<option value="">Loading resources…</option>';
    el('appt-sp-find-section').style.display = 'none';
    el('appt-sp-times-section').style.display = 'none';
    if (!serviceId) {
      el('appt-sp-resource-section').style.display = 'none';
      return;
    }
    el('appt-sp-resource-section').style.display = 'block';
    fetch(routes.eligibleResources + '?service_id=' + encodeURIComponent(serviceId), {
      headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var resources = data.resources || [];
        state.eligibleResources = resources;
        var rsel = el('appt-sp-resource');
        rsel.innerHTML = '<option value="">Select a resource…</option>';
        if (resources.length === 0) {
          rsel.innerHTML = '<option value="">No eligible resources for this service</option>';
          return;
        }
        resources.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.id;
          opt.textContent = r.name + (r.subtitle ? ' · ' + r.subtitle : '');
          rsel.appendChild(opt);
        });
      })
      .catch(function () { showError('Could not load resources.'); });
  }

  function onResourceChange() {
    var sel = el('appt-sp-resource');
    var resourceId = sel.value;
    state.selectedResourceId = resourceId || null;
    state.selectedResourceName = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
    state.selectedSlot = null;
    el('appt-sp-times-section').style.display = 'none';
    if (resourceId) {
      el('appt-sp-find-section').style.display = 'block';
    } else {
      el('appt-sp-find-section').style.display = 'none';
    }
  }

  function onFindTimes() {
    if (!state.selectedServiceId || !state.selectedResourceId) return;
    state.weekStartDate = state.weekStartDate || todayStr();
    fetchWeekTimes();
  }

  function fetchWeekTimes() {
    var listEl = el('appt-sp-times-list');
    listEl.innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('appt-sp-times-section').style.display = 'block';
    state.availLoading = true;
    el('appt-sp-week-label').textContent = formatWeekLabel(state.weekStartDate);
    el('appt-sp-prev-week').disabled = (state.weekStartDate <= todayStr());

    var url = routes.weekTimes
      + '?service_id='  + encodeURIComponent(state.selectedServiceId)
      + '&resource_id=' + encodeURIComponent(state.selectedResourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.availLoading = false;
        state.availSlots = data.slots || [];
        renderTimes();
      })
      .catch(function () {
        state.availLoading = false;
        listEl.innerHTML = '<div class="appt-sp-times-empty error">Could not load available times.</div>';
      });
  }

  function renderTimes() {
    var listEl = el('appt-sp-times-list');
    if (!state.availSlots || state.availSlots.length === 0) {
      listEl.innerHTML = '<div class="appt-sp-times-empty">No available times this week. Try Next week →</div>';
      return;
    }
    var html = '';
    state.availSlots.forEach(function (slot, idx) {
      var isSel = state.selectedSlot
        && state.selectedSlot.date === slot.date
        && state.selectedSlot.time === slot.time;
      html += '<div class="appt-sp-time-row' + (isSel ? ' selected' : '') + '" data-idx="' + idx + '">'
        + '<span class="appt-sp-time-date">' + escapeHtml(slot.date_label) + '</span>'
        + '<span class="appt-sp-time-time">' + escapeHtml(slot.time_label) + '</span>'
        + '</div>';
    });
    listEl.innerHTML = html;
    listEl.querySelectorAll('.appt-sp-time-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var idx = parseInt(row.getAttribute('data-idx'), 10);
        var slot = state.availSlots[idx];
        state.selectedSlot = {
          date: slot.date,
          time: slot.time,
          resource_id: state.selectedResourceId,
        };
        if (window.apptPdFilter) window.apptPdFilter(slot.date); // MARKER-PATCH-519
        renderTimes();
      });
    });
  }

  function onPrevWeek() {
    if (!state.weekStartDate) return;
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() - 7);
    var ymd = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (ymd < todayStr()) ymd = todayStr();
    state.weekStartDate = ymd;
    fetchWeekTimes();
  }

  function onNextWeek() {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    state.weekStartDate = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    fetchWeekTimes();
  }

  function formatWeekLabel(startDate) {
    if (!startDate) return '—';
    var s = new Date(startDate + 'T00:00:00');
    var e = new Date(s);
    e.setDate(e.getDate() + 6);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[s.getMonth()] + ' ' + s.getDate() + ' – ' + months[e.getMonth()] + ' ' + e.getDate();
  }

  // Wire up sequential picker events (idempotent — only once).
  (function wireSequentialPicker() {
    var svcInp = el('appt-sp-service-search');
    if (!svcInp || svcInp.dataset.spWired) return;
    svcInp.dataset.spWired = '1';
    svcInp.addEventListener('input', function () { svcHighlight = -1; renderServiceResults(); });
    svcInp.addEventListener('focus', renderServiceResults);
    svcInp.addEventListener('keydown', function (e) {
      var list = svcFiltered();
      if (e.key === 'ArrowDown') { e.preventDefault(); svcHighlight = Math.min(svcHighlight + 1, list.length - 1); renderServiceResults(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); svcHighlight = Math.max(svcHighlight - 1, 0); renderServiceResults(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (list[svcHighlight]) selectService(list[svcHighlight].id); else if (list.length === 1) selectService(list[0].id); }
      else if (e.key === 'Escape') { hideServiceResults(); }
    });
    el('appt-sp-service-results').addEventListener('click', function (e) {
      var row = e.target.closest('[data-svc-id]');
      if (row) selectService(row.getAttribute('data-svc-id'));
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('#appt-sp-service-wrap')) hideServiceResults();
    });
    el('appt-sp-resource').addEventListener('change', onResourceChange);
    el('appt-sp-find').addEventListener('click', onFindTimes);
    el('appt-sp-prev-week').addEventListener('click', onPrevWeek);
    el('appt-sp-next-week').addEventListener('click', onNextWeek);
  })();

  // ── Submit ──
  function submit() {
    clearError();
    if (!state.selectedServiceId) return showError('Pick a service.');
    if (!state.selectedResourceId) return showError('Pick a resource.');
    if (!state.selectedSlot || !state.selectedSlot.date) return showError('Pick a time.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: state.selectedSlot.date,
      appointment_time: state.selectedSlot.time,
      resource_id: state.selectedResourceId,
      staff_notes: el('appt-notes').value || null,
      route_window_id: (el('appt-pd-window') && el('appt-pd-window').value) || null, // MARKER-PATCH-519
      need_by: (el('appt-pd-needby') && el('appt-pd-needby').value) || null,
      items: [
        { service_item_id: state.selectedServiceId, price_override_cents: null },
      ],
    };
    if (!state.customerId) {
      payload.customer_first_name = el('appt-first').value.trim();
      payload.customer_last_name  = el('appt-last').value.trim();
      payload.customer_email      = el('appt-email').value.trim();
      payload.customer_phone      = el('appt-phone').value.trim();
      if (!payload.customer_first_name || !payload.customer_last_name || !payload.customer_email) {
        showError('First name, last name, and email are required for a new customer.');
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    fetch(routes.store, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : '' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
    .then(function (res) {
      if (res.ok && res.body.ok) {
        if (res.body.redirect) window.location.href = res.body.redirect;
        else window.location.reload();
        return;
      }
      // If the slot got taken between fetch and submit, refresh week-times.
      if (res.body && res.body.code === 'lock_timeout') {
        showError('That slot was just taken. Recomputing…');
        if (state.selectedServiceId && state.selectedResourceId) fetchWeekTimes();
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
      var msg = (res.body && (res.body.message || (res.body.errors && Object.values(res.body.errors).flat().join(' ')))) || 'Server error.';
      showError(msg);
      btn.disabled = false; btn.innerHTML = 'Create Appointment';
    })
    .catch(function () {
      showError('Network error.');
      btn.disabled = false; btn.innerHTML = 'Create Appointment';
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  return {
    open: open, close: close, clearCustomer: clearCustomer, clearService: clearService,
    toggleServicePicker: toggleServicePicker, submit: submit,
  };
})();

window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };

// BFCACHE-MODAL-RESET v1
// When the user navigates back to a page where this modal lives, the browser
// may bfcache-restore the page mid-submit (frozen spinner, modal still open).
// Detect persisted-restore and reset modal + submit button state.
window.addEventListener('pageshow', function (e) {
  if (!e.persisted) return;
  var modal = document.getElementById('new-appt-modal');
  if (modal) modal.style.display = 'none';
  var btn = document.getElementById('appt-submit');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = 'Create Appointment';
  }
});
</script>
BIZ3_12_EOF

cat > 'resources/views/tenant/register/receipt.blade.php' <<'BIZ3_13_EOF'
{{-- MARKER-PATCH-319 — standalone 80mm sales receipt. Shares the work-order
     tag's print identity (logo, size, paper) and the same printable-width CSS
     so it never clips. Reads the sale off the record. Auto-prints unless embed. --}}
@php
  $pageMm   = ($print['paper'] ?? '80mm') === '58mm' ? '46mm' : '70mm';
  $logoMax  = ['small'=>'12mm','medium'=>'18mm','large'=>'26mm','xl'=>'34mm'][$print['logo_size'] ?? 'medium'] ?? '18mm';
  $logoUrl  = $print['logo_path'] ? asset('storage/' . ltrim($print['logo_path'], '/')) : null;
  $headerText = trim((string) ($print['header_text'] ?? '')); // MARKER-PATCH-330
  $footerText = trim((string) ($print['footer_text'] ?? '')); // MARKER-PATCH-330
  $feedMm   = (int) ($print['feed_mm'] ?? 0) > 0 ? ((int) $print['feed_mm']) . 'mm' : null; // MARKER-PATCH-320
  $sym      = $tenant->currency_symbol ?: '$';
  $m        = fn($c) => $sym . number_format(((int) $c) / 100, 2);
  $qfmt     = fn($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
  $when     = $sale->paid_at ?? $sale->created_at;
  // MARKER-BIZ-RECEIPT — a business is billed by its business name
  $custName = $sale->customer
                ? trim($sale->customer->fullName())
                : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt {{ $sale->sale_number }}</title>
<style>
  @page { size: {{ $pageMm }} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { width: {{ $pageMm }}; color: #000; font-family: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; }
  .slip { width: 100%; margin: 0; padding: 4mm 3mm 3mm; font-size: 11px; line-height: 1.45; overflow: hidden; }
  .slip * { max-width: 100%; }
  .slip img { max-width: 100%; height: auto; }
  .ctr { text-align: center; }
  .hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .hr2 { border: 0; border-top: 2px solid #000; margin: 6px 0; }
  .shop { font-size: 14px; font-weight: 700; letter-spacing: .04em; }
  .logo { max-width: 100%; max-height: {{ $logoMax }}; display: block; margin: 0 auto 4px; }
  .lbl { font-size: 10px; letter-spacing: .16em; }
  .meta { font-size: 10px; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { padding: 2px 0; font-size: 11px; vertical-align: top; word-break: break-word; overflow-wrap: anywhere; }
  td.r { text-align: right; white-space: nowrap; width: 22mm; }
  .tot td.r { font-weight: 700; }
  .grand td { font-size: 14px; font-weight: 700; padding-top: 3px; }
  .foot { text-align: center; font-size: 10px; margin-top: 8px; }
  @media screen {
    body { width: {{ $pageMm }}; margin: 24px auto; box-shadow: 0 0 0 1px #ddd; }
    .printbar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff;
      font-family: system-ui, sans-serif; font-size: 13px; padding: 10px 16px; display: flex;
      gap: 12px; align-items: center; justify-content: center; }
    .printbar button { background: #BEF264; color: #0a0a0a; border: 0; font-weight: 600;
      padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    body { margin-top: 64px; }
  }
  @media print { .printbar { display: none; } }
  @if($embed ?? false) @media screen { body { margin: 0 auto !important; box-shadow: none !important; } } @endif
</style>
</head>
<body>

@unless($embed ?? false)
<div class="printbar">
  <span>Receipt {{ $sale->sale_number }}</span>
  <button onclick="window.print()">Print</button>
</div>
@endunless

<div class="slip">

  <div class="ctr" style="border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
    @if($logoUrl)
      <img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">
    @else
      <div class="shop">{{ strtoupper($tenant->name ?? 'SHOP') }}</div>
    @endif
    @if($tenant->phone ?? null)<div class="meta">{{ $tenant->phone }}</div>@endif
    @if($headerText)<div class="meta">{!! nl2br(e($headerText)) !!}</div>@endif{{-- MARKER-PATCH-330 --}}
  </div>

  <div class="ctr lbl">{{ $sale->isRefunded() ? 'REFUND' : 'RECEIPT' }}</div>

  <table style="margin-top:4px">
    <tr><td>Sale</td><td class="r" style="white-space:normal">{{ $sale->sale_number }}</td></tr>
    <tr><td>Date</td><td class="r" style="white-space:normal">{{ tlocal($when, 'M j, Y g:ia') }}</td></tr>
    @if($custName)<tr><td>Customer</td><td class="r" style="white-space:normal">{{ $custName }}</td></tr>@endif
  </table>

  <hr class="hr">

  <table>
    @foreach($sale->items as $it)
      <tr>
        <td>{{ $qfmt($it->quantity) }} &times; {{ $it->name_snapshot }}</td>
        <td class="r">{{ $m($it->line_total_cents) }}</td>
      </tr>
    @endforeach
  </table>

  <hr class="hr">

  <table class="tot">
    <tr><td>Subtotal</td><td class="r">{{ $m($sale->subtotal_cents) }}</td></tr>
    @if((int) $sale->discount_cents > 0)
      <tr><td>Discount</td><td class="r">&minus;{{ $m($sale->discount_cents) }}</td></tr>
    @endif
    @if((int) $sale->tax_cents > 0)
      <tr><td>Tax</td><td class="r">{{ $m($sale->tax_cents) }}</td></tr>
      {{-- MARKER-BIZ-RECEIPT — an accounts-payable clerk needs to see WHY tax
           is zero, and needs the PO reference to process the invoice. --}}
      @if($sale->tax_exempt_applied)
        <tr><td colspan="2" style="font-size:11px;opacity:.7">
          Tax exempt@if($sale->tax_exempt_certificate) — certificate {{ $sale->tax_exempt_certificate }}@endif
        </td></tr>
      @endif
      @if($sale->po_number)
        <tr><td colspan="2" style="font-size:11px;opacity:.7">PO {{ $sale->po_number }}</td></tr>
      @endif
    @endif
    @if((int) $sale->surcharge_cents > 0)
      <tr><td>Surcharge</td><td class="r">{{ $m($sale->surcharge_cents) }}</td></tr>
    @endif
    @if((int) $sale->tip_cents > 0)
      <tr><td>Tip</td><td class="r">{{ $m($sale->tip_cents) }}</td></tr>
    @endif
  </table>

  <hr class="hr2">
  <table class="grand"><tr><td>TOTAL</td><td class="r">{{ $m($sale->total_cents) }}</td></tr></table>

  @if($sale->payments && $sale->payments->count())
    <hr class="hr">
    <table>
      @foreach($sale->payments as $p)
        <tr>
          <td>{{ method_exists($p, 'methodLabel') ? $p->methodLabel() : ucfirst($p->method ?? 'Payment') }}</td>
          <td class="r">{{ $m($p->amount_cents) }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  <div class="foot">
    @if($footerText){!! nl2br(e($footerText)) !!}@else Thank you!<br>{{ $tenant->name }}@endif{{-- MARKER-PATCH-330 --}}
  </div>

  @php $feedRows = (int) ceil(((int) ($print['feed_mm'] ?? 0)) / 3); @endphp{{-- MARKER-PATCH-327 --}}
  @if($feedRows > 0)<div aria-hidden="true" style="line-height:3mm;font-size:9px;color:#000">{!! str_repeat('&nbsp;<br>', $feedRows) !!}</div>@endif
</div>

<script>
  @unless($embed ?? false) setTimeout(function () { window.print(); }, 300); @endunless
</script>
</body>
</html>
BIZ3_13_EOF

cat > 'resources/views/tenant/inventory/receiving/edit.blade.php' <<'BIZ3_14_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Editing ' . $shipment->shipment_number;
  $statusOptions = [
    'expected' => 'Expected',
    'received' => 'Received',
    'backorder' => 'Backorder',
    'unexpected_pending' => 'Pending',
    'unexpected_added' => 'Added',
    'unexpected_hold' => 'On hold',
  ];
@endphp


@push('styles')
<style>
/* "Best on desktop" mobile notice (patch #38). Hidden on >640px. */
.recv-mobile-notice{display:none;background:rgba(250,180,106,.08);border:0.5px solid rgba(250,180,106,.25);border-radius:var(--ia-r-lg);padding:14px 16px;margin-bottom:16px}
.recv-mobile-notice-title{font-size:13px;font-weight:600;color:#FAB46A;margin-bottom:4px;display:flex;align-items:center;gap:6px}
.recv-mobile-notice-body{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
@media(max-width:640px){
  .recv-mobile-notice{display:block}
}
</style>
@endpush

@section('content')


{{-- Mobile "best on desktop" notice (patch #38). Receiving is line-by-line
     entry that doesn't fit a phone — v1.1 will likely add barcode scanning
     and a different mobile flow. For now we surface the limitation rather
     than rebuild the form. --}}
<div class="recv-mobile-notice">
  <div class="recv-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="recv-mobile-notice-body">
    Receiving works on mobile, but line-by-line entry is faster on a larger screen. Mobile-optimized receiving (with barcode scanning) is on the roadmap.
  </div>
</div>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $shipment->shipment_number }}</h1>
    <p class="ia-page-subtitle">
      Draft · {{ $shipment->location?->name ?? '—' }} ·
      Started {{ $shipment->created_at->diffForHumans() }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
    <form method="POST" action="{{ route('tenant.inventory.receiving.destroy', ['id' => $shipment->id]) }}"
          style="display:inline" onsubmit="return confirm('Delete this draft shipment?');">
      @csrf @method('DELETE')
      <button class="ia-btn ia-btn--ghost" style="color:var(--ia-danger,#ff8080)">Delete draft</button>
    </form>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<form method="POST" action="{{ route('tenant.inventory.receiving.update', ['id' => $shipment->id]) }}"
      class="ia-card" style="margin-bottom:14px">
  @csrf @method('PATCH')
  <div class="ia-card-body" style="padding:16px 20px">
    <div style="display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px 16px">
      <div class="ia-field">
        <label class="ia-label">Shipment number</label>
        <input name="shipment_number" class="ia-input" value="{{ $shipment->shipment_number }}" required maxlength="30">
      </div>
      <div class="ia-field">
        <label class="ia-label">Received date</label>
        <input name="received_date" type="date" class="ia-input" value="{{ $shipment->received_date?->toDateString() }}" required>
      </div>
      <div class="ia-field">
        <label class="ia-label">Distributor</label>
        <input name="distributor_name" class="ia-input" value="{{ $shipment->distributor_name }}" maxlength="128">
      </div>
      <div class="ia-field">
        <label class="ia-label">Distributor code</label>
        <input name="distributor_code" class="ia-input" value="{{ $shipment->distributor_code }}" maxlength="32">
      </div>
      <div class="ia-field">
        <label class="ia-label">Shipping cost</label>
        <input name="shipping_cost_dollars" type="text" inputmode="decimal" class="ia-input"
               value="{{ number_format($shipment->shipping_cost_cents / 100, 2, '.', '') }}"
               placeholder="0.00">
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Notes</label>
        <textarea name="notes" class="ia-input" rows="2" maxlength="2000">{{ $shipment->notes }}</textarea>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button type="submit" class="ia-btn ia-btn--secondary">Save header</button>
    </div>
  </div>
</form>

<div id="rcv-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin-bottom:14px;border:1px solid var(--ia-border);border-radius:6px;overflow:hidden">
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Expected</div>
    <div style="font-size:22px;font-weight:600;margin-top:2px" data-stat="expected">{{ $shipment->expected_count }}</div>
  </div>
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Received</div>
    <div style="font-size:22px;font-weight:600;color:var(--ia-accent);margin-top:2px" data-stat="received">{{ $shipment->received_count }}</div>
  </div>
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Backorder</div>
    <div style="font-size:22px;font-weight:600;color:#f4b400;margin-top:2px" data-stat="backorder">{{ $shipment->backorder_count }}</div>
  </div>
  <div style="padding:12px 14px">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Unexpected</div>
    <div style="font-size:22px;font-weight:600;color:#f4b400;margin-top:2px" data-stat="unexpected">{{ $shipment->unexpected_count }}</div>
  </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 8px 0">
  <h2 style="font-size:15px;margin:0">Line items</h2>
  <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvNewItem()"
          style="padding:4px 10px;font-size:12.5px;color:var(--ia-accent,#BEF264)">+ New item</button>
</div>

<div class="ia-table-wrap">
<table class="ia-table" id="rcv-lines">
  <thead>
    <tr>
      <th style="width:30%">Item</th>
      <th style="width:16%">SKU / UPC</th>
      <th style="width:9%;text-align:right">Expected</th>
      <th style="width:9%;text-align:right">Received</th>
      <th style="width:14%">Status</th>
      <th style="width:12%;text-align:right">Cost</th>
      <th style="width:5%"></th>
    </tr>
  </thead>
  <tbody id="rcv-tbody">
    @foreach($shipment->items as $line)
      @include('tenant.inventory.receiving._partials.line', ['line' => $line, 'statusOptions' => $statusOptions])
    @endforeach
    <tr id="rcv-newline" data-newline="1" style="background:var(--ia-surface-2,rgba(190,242,100,.04))">
      <td>
        <span style="color:var(--ia-accent,#BEF264);font-weight:500;font-size:13px">+ Add line</span>
        <div style="font-size:11px;color:var(--ia-text-muted);margin-top:2px">Scan or type to find an item</div>
      </td>
      <td>
        <input type="text" class="ia-input" id="rcv-newline-sku" autocomplete="off"
               placeholder="SKU, UPC, or name + Enter"
               style="padding:5px 9px;font-size:12.5px;width:100%;border:1px solid var(--ia-border,#2a2a2a);background:var(--ia-input-bg,#0a0a0a)">
      </td>
      <td style="text-align:right;color:var(--ia-text-dim,#555)">—</td>
      <td style="text-align:right;color:var(--ia-text-dim,#555)">—</td>
      <td><span style="color:var(--ia-text-dim,#555);font-size:11px">auto</span></td>
      <td style="text-align:right;color:var(--ia-text-dim,#555)">—</td>
      <td></td>
    </tr>
  </tbody>
</table>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:16px;border-top:1px solid var(--ia-border)">
  <div id="rcv-commit-note" style="font-size:13px;color:var(--ia-text-muted)">
    Commits <strong id="rcv-commit-lines" style="color:var(--ia-accent)">0 items</strong>,
    <strong id="rcv-commit-units" style="color:var(--ia-accent)">0 units</strong>.
    Backorder + unexpected lines stay on the shipment but won't write movements.
  </div>
  {{-- patch-90 SO auto-link card — appears before commit form.
       Lists matched 'ordered' SOs for received items.
       Hidden inputs (so_arrivals[so_id] = qty) ride along with the commit POST. --}}
  @isset($matchedSos)
    @if($matchedSos->isNotEmpty() || ($neededHintCount ?? 0) > 0)
      <div class="ia-card" style="margin-bottom:14px;border-left:3px solid var(--ia-accent)">
        <div class="ia-card-body" style="padding:14px 18px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">
            <strong style="font-size:13px">Match special orders</strong>
            <span style="font-size:11.5px;color:var(--ia-text-muted)">
              {{ $matchedSos->count() }} open SO{{ $matchedSos->count() === 1 ? '' : 's' }} match received items
            </span>
          </div>

          @if($matchedSos->isNotEmpty())
            <p style="font-size:12.5px;color:var(--ia-text-muted);margin:0 0 12px;line-height:1.55">
              Auto-matching these special orders will mark them arrived during commit. Uncheck any you don't want to claim from this shipment. Set received qty below the SO total for a partial receipt (the remainder stays on order as a sibling row).
            </p>

            <table class="ia-table" style="margin-bottom:8px">
              <thead>
                <tr>
                  <th style="width:32px"></th>
                  <th>SO</th>
                  <th>Item</th>
                  <th>For</th>
                  <th style="width:90px">SO qty</th>
                  <th style="width:120px">Mark arrived</th>
                </tr>
              </thead>
              <tbody>
                @foreach($matchedSos as $so)
                  <tr>
                    <td>
                      <input type="checkbox" class="rcv-so-match-cb" checked
                             data-so-id="{{ $so->id }}"
                             onchange="rcvToggleSoMatch(this)">
                    </td>
                    <td>
                      <a href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}"
                         target="_blank" style="font-weight:600">{{ $so->so_number }}</a>
                    </td>
                    <td>
                      {{ $so->item_name_snapshot }}
                      @if($so->vendor)<div style="font-size:11px;color:var(--ia-text-muted)">via {{ $so->vendor->name }}</div>@endif
                    </td>
                    <td>
                      @if($so->customer)
                        {{ $so->customer->fullName() }}
                        @if($so->appointment)<div style="font-size:11px;color:var(--ia-text-muted)">{{ $so->appointment->ra_number }}</div>@endif
                      @else
                        <span style="color:var(--ia-text-muted)">Shop stock</span>
                      @endif
                    </td>
                    <td>{{ $so->quantity }}</td>
                    <td>
                      <input type="number" class="ia-input rcv-so-qty" min="1" max="{{ $so->quantity }}"
                             value="{{ $so->quantity }}"
                             data-so-id="{{ $so->id }}"
                             style="padding:4px 8px;font-size:12px"
                             onchange="rcvUpdateSoQty(this)">
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif

          @if(($neededHintCount ?? 0) > 0)
            <p style="font-size:12px;color:var(--ia-amber);margin:8px 0 0;padding:8px 10px;background:rgba(245,158,11,0.06);border-radius:4px">
              <strong>{{ $neededHintCount }} 'needed' SO{{ $neededHintCount === 1 ? '' : 's' }}</strong> also exist{{ $neededHintCount === 1 ? 's' : '' }} for these items. They won't auto-link — promote them to 'ordered' first if you want to fulfill from this shipment.
            </p>
          @endif
        </div>
      </div>
    @endif
  @endisset

    <form method="POST" action="{{ route('tenant.inventory.receiving.commit', ['id' => $shipment->id]) }}"
        id="rcv-commit-form" onsubmit="return rcvConfirmCommit(event);">
    @csrf
    {{-- patch-90 SO hidden fields — populated by JS from the SO match card.
         Format: so_arrivals[<so_uuid>] = <received_qty> --}}
    <div id="rcv-so-hidden-fields">
      @isset($matchedSos)
        @foreach($matchedSos as $so)
          <input type="hidden" name="so_arrivals[{{ $so->id }}]" value="{{ $so->quantity }}" data-so-id="{{ $so->id }}">
        @endforeach
      @endisset
    </div>
    <button type="submit" class="ia-btn ia-btn--primary" id="rcv-commit-btn"
            @if($shipment->received_count === 0) disabled @endif>
      Commit shipment
    </button>
  </form>
</div>

<div id="rcv-item-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding-top:60px;overflow-y:auto">
  <div style="background:var(--ia-card,#111);border:1px solid var(--ia-border);border-radius:8px;padding:18px 22px;width:94%;max-width:680px;margin-bottom:60px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="font-size:15px;margin:0" id="rcv-item-modal-title">Item</h3>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseItemModal()" style="padding:2px 8px">×</button>
    </div>

    <div id="rcv-item-modal-error" style="display:none;padding:8px 12px;background:rgba(255,80,80,.12);border:1px solid rgba(255,80,80,.3);border-radius:4px;margin-bottom:12px;font-size:12.5px;color:#ff8080"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px">
      <div class="ia-field">
        <label class="ia-label">SKU *</label>
        <input type="text" class="ia-input" id="rcv-item-sku" maxlength="64" required>
      </div>
      <div class="ia-field">
        <label class="ia-label">Category *</label>
        <select class="ia-input" id="rcv-item-category" required>
          <option value="">— pick category —</option>
        </select>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Name *</label>
        <input type="text" class="ia-input" id="rcv-item-name" maxlength="255" required>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Description</label>
        <textarea class="ia-input" id="rcv-item-description" rows="2"></textarea>
      </div>
      <div class="ia-field">
        <label class="ia-label">Cost</label>
        <input type="text" inputmode="decimal" class="ia-input" id="rcv-item-cost" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Sell price</label>
        <input type="text" inputmode="decimal" class="ia-input" id="rcv-item-sell" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Case quantity</label>
        <input type="number" min="1" class="ia-input" id="rcv-item-case-qty">
      </div>
      <div class="ia-field">
        <label class="ia-label">Reorder threshold</label>
        <input type="number" min="0" class="ia-input" id="rcv-item-reorder-threshold">
      </div>
      <div class="ia-field">
        <label class="ia-label">Reorder quantity</label>
        <input type="number" min="1" class="ia-input" id="rcv-item-reorder-qty">
      </div>
      <div class="ia-field">
        <label class="ia-label">Bin location</label>
        <input type="text" maxlength="50" class="ia-input" id="rcv-item-bin">
      </div>
      <div class="ia-field" style="display:flex;align-items:center;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
          <input type="checkbox" id="rcv-item-active" checked> Active
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
          <input type="checkbox" id="rcv-item-oversell"> Allow oversell
        </label>
      </div>
      <div class="ia-field" id="rcv-item-catalog-info" style="display:none;font-size:11.5px;color:var(--ia-text-muted)">
        <span id="rcv-item-catalog-upc"></span>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseItemModal()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="rcv-item-save-btn" onclick="rcvSaveItem()">Save</button>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = '{{ csrf_token() }}';
  var urls = {
    addItem:    '{{ route("tenant.inventory.receiving.items.store",  ["id" => $shipment->id]) }}',
    updateItem: function (lineId) { return '{{ route("tenant.inventory.receiving.items.update", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    removeItem: function (lineId) { return '{{ route("tenant.inventory.receiving.items.destroy", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    search:     '{{ route("tenant.inventory.items.search") }}',
  };

  function jsonReq(method, url, body) {
    var opts = {
      method: method,
      headers: {
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
    });
  }

  function toastOk(msg)  { if (window.IntakeToast) window.IntakeToast.success(msg); }
  function toastErr(msg) { if (window.IntakeToast) window.IntakeToast.error(msg); }
  function toastInfo(msg){ if (window.IntakeToast) window.IntakeToast.info(msg); }

  function centsToDollars(c) {
    if (c == null || c === '') return '';
    return (c / 100).toFixed(2);
  }

  function applyTotals(t) {
    if (!t) return;
    document.querySelector('[data-stat="expected"]').textContent   = t.expected;
    document.querySelector('[data-stat="received"]').textContent   = t.received;
    document.querySelector('[data-stat="backorder"]').textContent  = t.backorder;
    document.querySelector('[data-stat="unexpected"]').textContent = t.unexpected;
    document.getElementById('rcv-commit-lines').textContent = t.commit_lines + ' items';
    document.getElementById('rcv-commit-units').textContent = t.commit_units + ' units';
    document.getElementById('rcv-commit-btn').disabled = !t.can_commit;
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function statusSelectHtml(current) {
    var opts = [
      ['expected', 'Expected'], ['received', 'Received'], ['backorder', 'Backorder'],
      ['unexpected_pending', 'Pending'], ['unexpected_added', 'Added'], ['unexpected_hold', 'On hold'],
    ];
    var html = '<select class="ia-input rcv-cell" data-field="status" style="padding:3px 6px;font-size:12px">';
    opts.forEach(function (o) {
      html += '<option value="' + o[0] + '"' + (o[0] === current ? ' selected' : '') + '>' + o[1] + '</option>';
    });
    return html + '</select>';
  }

  function renderRow(line) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-line-id', line.id);
    tr.setAttribute('data-status', line.status);
    if (line.is_unexpected) tr.style.background = 'rgba(244,180,0,.06)';
    tr.innerHTML =
      '<td>' +
        '<div style="font-weight:500">' + escapeHtml(line.name) + '</div>' +
        (line.category ? '<div style="font-size:11px;color:var(--ia-text-muted);margin-top:1px">' + escapeHtml(line.category) + '</div>'
          : (line.is_unexpected ? '<div style="font-size:11px;color:#f4b400;margin-top:1px">Unexpected · not on PO</div>' : '')) +
      '</td>' +
      '<td><code style="font-size:11.5px;color:var(--ia-accent)">' + escapeHtml(line.sku || '') + '</code></td>' +
      '<td style="text-align:right">' +
        (line.is_unexpected
          ? '<span style="color:var(--ia-text-muted)">—</span>'
          : '<input class="ia-input rcv-cell" data-field="expected_quantity" type="number" min="0" max="99999" value="' + line.expected_quantity + '" style="width:64px;padding:3px 6px;text-align:right">') +
      '</td>' +
      '<td style="text-align:right">' +
        '<input class="ia-input rcv-cell" data-field="received_quantity" type="number" min="0" max="99999" value="' + line.received_quantity + '" style="width:64px;padding:3px 6px;text-align:right">' +
      '</td>' +
      '<td>' + statusSelectHtml(line.status) + '</td>' +
      '<td style="text-align:right">' +
        '<input class="ia-input rcv-cell" data-field="unit_cost_dollars" type="text" inputmode="decimal" value="' + centsToDollars(line.unit_cost_cents) + '" style="width:80px;padding:3px 6px;text-align:right" placeholder="0.00">' +
      '</td>' +
      '<td style="text-align:right;white-space:nowrap">' +
        (line.inventory_item_id
          ? '<button type="button" class="ia-btn ia-btn--ghost" onclick="rcvEditItem(\'' + line.inventory_item_id + '\', \'' + line.id + '\')" style="padding:2px 6px;color:var(--ia-text-muted);margin-right:2px" title="Edit item">✎</button>'
          : '') +
        '<button type="button" class="ia-btn ia-btn--ghost" onclick="rcvRemoveLine(\'' + line.id + '\')" style="padding:2px 8px;color:var(--ia-text-muted)" title="Remove">×</button>' +
      '</td>';
    return tr;
  }

  document.getElementById('rcv-tbody').addEventListener('change', function (e) {
    var cell = e.target;
    if (!cell.classList.contains('rcv-cell')) return;
    var row = cell.closest('tr[data-line-id]');
    if (!row) return;
    var lineId = row.getAttribute('data-line-id');
    var field  = cell.getAttribute('data-field');
    var rawValue = cell.value;
    var sendValue;

    if (field === 'unit_cost_dollars') {
      sendValue = rawValue;
    } else if (cell.type === 'number') {
      sendValue = rawValue === '' ? null : parseInt(rawValue, 10);
    } else {
      sendValue = rawValue;
    }

    var payload = {};
    payload[field] = sendValue;
    jsonReq('PATCH', urls.updateItem(lineId), payload).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        applyTotals(res.body.totals);
        if (field === 'status') {
          row.setAttribute('data-status', sendValue);
          row.style.background = (sendValue && sendValue.indexOf('unexpected') === 0) ? 'rgba(244,180,0,.06)' : '';
        }
        if (field === 'unit_cost_dollars' && res.body.line) {
          cell.value = centsToDollars(res.body.line.unit_cost_cents);
        }
        toastOk('Saved');
      } else {
        toastErr((res.body && res.body.message) || 'Could not save.');
      }
    }).catch(function () { toastErr('Network error. Try again.'); });
  });

  document.getElementById('rcv-tbody').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.classList.contains('rcv-cell')) {
      e.preventDefault();
      e.target.blur();
      setTimeout(function () {
        var sku = document.getElementById('rcv-newline-sku');
        if (sku) sku.focus();
      }, 50);
    }
  });

  window.rcvRemoveLine = function (lineId) {
    if (!confirm('Remove this line?')) return;
    jsonReq('DELETE', urls.removeItem(lineId)).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        var row = document.querySelector('#rcv-tbody tr[data-line-id="' + lineId + '"]');
        if (row) {
          row.style.transition = 'opacity .2s';
          row.style.opacity = '0';
          setTimeout(function () { row.remove(); applyTotals(res.body.totals); }, 200);
        } else { applyTotals(res.body.totals); }
        toastOk('Line removed');
      } else {
        toastErr((res.body && res.body.message) || 'Could not remove.');
      }
    }).catch(function () { toastErr('Network error. Try again.'); });
  };

  var newLineInput = document.getElementById('rcv-newline-sku');
  var newLineRow   = document.getElementById('rcv-newline');
  var submittingNewLine = false;

  newLineInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (submittingNewLine) return;
    var raw = newLineInput.value.trim();
    if (raw.length < 1) return;
    submittingNewLine = true;
    newLineInput.disabled = true;

    fetch(urls.search + '?q=' + encodeURIComponent(raw), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      var results = (j.ok && j.results) ? j.results : [];
      if (results.length === 1) {
        addExpectedLine(results[0]);
      } else if (results.length > 1) {
        submittingNewLine = false;
        newLineInput.disabled = false;
        newLineInput.focus();
        toastInfo(results.length + ' matches — type more to narrow');
      } else {
        addUnexpectedLine(raw);
      }
    }).catch(function () {
      submittingNewLine = false;
      newLineInput.disabled = false;
      toastErr('Network error. Try again.');
    });
  });

  function addExpectedLine(item) {
    var payload = {
      mode: 'expected',
      inventory_item_id: item.id,
      expected_quantity: 1,
      received_quantity: 1,
    };
    jsonReq('POST', urls.addItem, payload).then(function (res) {
      finishNewLine(res, 'Line added');
    }).catch(function () { finishNewLine({ ok: false }, 'Network error.'); });
  }

  function addUnexpectedLine(raw) {
    var looksLikeUpc = /^[0-9]{8,14}$/.test(raw);
    var payload = {
      mode: 'unexpected',
      name: raw,
      sku: looksLikeUpc ? null : raw,
      upc: looksLikeUpc ? raw : null,
      expected_quantity: 0,
      received_quantity: 1,
    };
    jsonReq('POST', urls.addItem, payload).then(function (res) {
      finishNewLine(res, 'Unexpected SKU added');
    }).catch(function () { finishNewLine({ ok: false }, 'Network error.'); });
  }

  function finishNewLine(res, successMsg) {
    submittingNewLine = false;
    newLineInput.disabled = false;
    if (res.ok && res.body && res.body.ok) {
      var newRow = renderRow(res.body.line);
      newLineRow.parentNode.insertBefore(newRow, newLineRow);
      applyTotals(res.body.totals);
      newLineInput.value = '';
      newLineInput.focus();
      toastOk(successMsg);
    } else {
      toastErr((res.body && res.body.message) || 'Could not add line.');
      newLineInput.focus();
      newLineInput.select();
    }
  }

  window.rcvConfirmCommit = function (e) {
    var lines = document.getElementById('rcv-commit-lines').textContent;
    var units = document.getElementById('rcv-commit-units').textContent;
    if (!confirm('Commit will write movements for ' + lines + ', ' + units + '. This cannot be undone. Continue?')) {
      e.preventDefault();
      return false;
    }
    return true;
  };

  setTimeout(function () { newLineInput.focus(); }, 100);

  // ─── Item modal (edit existing + create new) ──────────────────────
  var modalMode = null;             // 'edit' | 'create'
  var modalEditingItemId = null;    // when mode=edit
  var modalLineId = null;           // the receiving line that opened the modal (mode=edit)
  var modalCategoriesLoaded = false;

  function ensureCategoriesLoaded() {
    if (modalCategoriesLoaded) return Promise.resolve();
    return fetch('{{ route("tenant.inventory.receiving.categories.list") }}', {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      var sel = document.getElementById('rcv-item-category');
      sel.innerHTML = '<option value="">— pick category —</option>';
      j.categories.forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        sel.appendChild(opt);
      });
      modalCategoriesLoaded = true;
    });
  }

  function clearItemModal() {
    document.getElementById('rcv-item-sku').value = '';
    document.getElementById('rcv-item-name').value = '';
    document.getElementById('rcv-item-description').value = '';
    document.getElementById('rcv-item-cost').value = '';
    document.getElementById('rcv-item-sell').value = '';
    document.getElementById('rcv-item-case-qty').value = '';
    document.getElementById('rcv-item-reorder-threshold').value = '';
    document.getElementById('rcv-item-reorder-qty').value = '';
    document.getElementById('rcv-item-bin').value = '';
    document.getElementById('rcv-item-active').checked = true;
    document.getElementById('rcv-item-oversell').checked = false;
    document.getElementById('rcv-item-category').value = '';
    document.getElementById('rcv-item-catalog-info').style.display = 'none';
    document.getElementById('rcv-item-modal-error').style.display = 'none';
  }

  function showModalError(msg) {
    var box = document.getElementById('rcv-item-modal-error');
    box.textContent = msg;
    box.style.display = '';
  }

  window.rcvNewItem = function () {
    modalMode = 'create';
    modalEditingItemId = null;
    modalLineId = null;
    document.getElementById('rcv-item-modal-title').textContent = '+ New item';
    document.getElementById('rcv-item-save-btn').textContent = 'Create + add line';
    clearItemModal();
    ensureCategoriesLoaded().then(function () {
      document.getElementById('rcv-item-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('rcv-item-sku').focus(); }, 50);
    });
  };

  window.rcvEditItem = function (itemId, lineId) {
    modalMode = 'edit';
    modalEditingItemId = itemId;
    modalLineId = lineId;
    document.getElementById('rcv-item-modal-title').textContent = 'Edit item';
    document.getElementById('rcv-item-save-btn').textContent = 'Save';
    clearItemModal();
    ensureCategoriesLoaded().then(function () {
      var url = '{{ route("tenant.inventory.receiving.items.quick.show", ["id" => "__ID__"]) }}'.replace('__ID__', itemId);
      return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok || !j.item) {
        toastErr(j.message || 'Could not load item.');
        return;
      }
      var it = j.item;
      document.getElementById('rcv-item-sku').value = it.sku || '';
      document.getElementById('rcv-item-name').value = it.name || '';
      document.getElementById('rcv-item-description').value = it.description || '';
      document.getElementById('rcv-item-cost').value = it.shop_cost_dollars || '';
      document.getElementById('rcv-item-sell').value = it.shop_sell_price_dollars || '';
      document.getElementById('rcv-item-case-qty').value = it.shop_case_quantity || '';
      document.getElementById('rcv-item-reorder-threshold').value = it.shop_reorder_threshold || '';
      document.getElementById('rcv-item-reorder-qty').value = it.shop_reorder_quantity || '';
      document.getElementById('rcv-item-bin').value = it.shop_bin_location || '';
      document.getElementById('rcv-item-active').checked = !!it.is_active;
      document.getElementById('rcv-item-oversell').checked = !!it.allow_oversell;
      document.getElementById('rcv-item-category').value = it.category_id || '';
      if (it.catalog_upc) {
        document.getElementById('rcv-item-catalog-info').style.display = '';
        document.getElementById('rcv-item-catalog-upc').textContent = 'Catalog UPC: ' + it.catalog_upc;
      }
      document.getElementById('rcv-item-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('rcv-item-name').focus(); }, 50);
    });
  };

  window.rcvCloseItemModal = function () {
    document.getElementById('rcv-item-modal').style.display = 'none';
    modalMode = null;
    modalEditingItemId = null;
    modalLineId = null;
  };

  window.rcvSaveItem = function () {
    var btn = document.getElementById('rcv-item-save-btn');
    var origLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving…';
    document.getElementById('rcv-item-modal-error').style.display = 'none';

    var payload = {
      category_id: document.getElementById('rcv-item-category').value,
      sku:         document.getElementById('rcv-item-sku').value.trim(),
      name:        document.getElementById('rcv-item-name').value.trim(),
      description: document.getElementById('rcv-item-description').value.trim() || null,
      shop_cost_dollars:       document.getElementById('rcv-item-cost').value.trim() || null,
      shop_sell_price_dollars: document.getElementById('rcv-item-sell').value.trim() || null,
      shop_case_quantity:      document.getElementById('rcv-item-case-qty').value || null,
      shop_reorder_threshold:  document.getElementById('rcv-item-reorder-threshold').value || null,
      shop_reorder_quantity:   document.getElementById('rcv-item-reorder-qty').value || null,
      shop_bin_location:       document.getElementById('rcv-item-bin').value.trim() || null,
      is_active:      document.getElementById('rcv-item-active').checked,
      allow_oversell: document.getElementById('rcv-item-oversell').checked,
    };

    if (!payload.sku || !payload.name || !payload.category_id) {
      btn.disabled = false; btn.textContent = origLabel;
      showModalError('SKU, name, and category are required.');
      return;
    }

    if (modalMode === 'edit') {
      var url = '{{ route("tenant.inventory.receiving.items.quick.update", ["id" => "__ID__"]) }}'.replace('__ID__', modalEditingItemId);
      jsonReq('PATCH', url, payload).then(function (res) {
        btn.disabled = false; btn.textContent = origLabel;
        if (res.ok && res.body && res.body.ok) {
          if (modalLineId) {
            var row = document.querySelector('#rcv-tbody tr[data-line-id="' + modalLineId + '"]');
            if (row) {
              var nameDiv = row.querySelector('td:first-child div:first-child');
              if (nameDiv) nameDiv.textContent = res.body.item.name;
              var skuCode = row.querySelector('td:nth-child(2) code');
              if (skuCode) skuCode.textContent = res.body.item.sku;
            }
          }
          rcvCloseItemModal();
          toastOk('Item saved');
        } else {
          showModalError((res.body && res.body.message) || 'Could not save.');
        }
      }).catch(function () {
        btn.disabled = false; btn.textContent = origLabel;
        showModalError('Network error. Try again.');
      });
    } else {
      payload.add_as_line = true;
      payload.received_quantity = 1;
      var createUrl = '{{ route("tenant.inventory.receiving.items.quick.create", ["id" => $shipment->id]) }}';
      jsonReq('POST', createUrl, payload).then(function (res) {
        btn.disabled = false; btn.textContent = origLabel;
        if (res.ok && res.body && res.body.ok) {
          if (res.body.line) {
            var newRow = renderRow(res.body.line);
            newLineRow.parentNode.insertBefore(newRow, newLineRow);
            applyTotals(res.body.totals);
          }
          rcvCloseItemModal();
          toastOk('Item created and added to shipment');
        } else {
          showModalError((res.body && res.body.message) || 'Could not create.');
        }
      }).catch(function () {
        btn.disabled = false; btn.textContent = origLabel;
        showModalError('Network error. Try again.');
      });
    }
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('rcv-item-modal').style.display === 'flex') {
      rcvCloseItemModal();
    }
  });
})();
</script>

@endsection

@push('scripts')
<script>
// patch-90 SO match card handlers — keep hidden so_arrivals[] in sync
// with checkbox state and qty inputs.
window.rcvToggleSoMatch = function (cb) {
  var soId = cb.dataset.soId;
  var hidden = document.querySelector('input[type="hidden"][data-so-id="' + soId + '"]');
  if (!hidden) return;
  if (cb.checked) {
    // restore from the qty input
    var qtyInput = document.querySelector('.rcv-so-qty[data-so-id="' + soId + '"]');
    hidden.value = qtyInput ? qtyInput.value : '0';
    hidden.name = 'so_arrivals[' + soId + ']';
  } else {
    // disable participation by clearing name (browser won't submit it)
    hidden.name = '__skipped_so_' + soId;
  }
};
window.rcvUpdateSoQty = function (input) {
  var soId = input.dataset.soId;
  var cb = document.querySelector('.rcv-so-match-cb[data-so-id="' + soId + '"]');
  var hidden = document.querySelector('input[type="hidden"][data-so-id="' + soId + '"]');
  if (!hidden || !cb) return;
  if (cb.checked) {
    hidden.value = input.value;
  }
};
</script>
@endpush

BIZ3_14_EOF

cat > 'resources/views/tenant/inventory/show.blade.php' <<'BIZ3_15_EOF'
@extends('layouts.tenant.app')
@php
  // MARKER-PATCH-375 — Option A (buy-box) item page, now live. Media + specs
  // left, sticky summary (status / price / margin / stock / identity) right,
  // tabbed Activity / Special orders / Sourced from below.
  $pageTitle = $item->name;
  $isMultiLocation = $locations->count() > 1;

  $itemLocByLocId = [];
  foreach ($item->locations as $il) {
      $itemLocByLocId[$il->location_id] = $il;
  }

  $reasonCodes = [
    'damaged' => 'Damaged',
    'expired' => 'Expired',
    'theft_shrinkage' => 'Theft / shrinkage',
    'count_correction' => 'Count correction',
    'found' => 'Found unexpectedly',
    'vendor_credit' => 'Returned to vendor',
    'donation' => 'Donation',
    'internal_use' => 'Internal use',
    'display' => 'Moved to display',
    'sample' => 'Sample / giveaway',
    'other' => 'Other (specify)',
  ];

  $movementTypeLabels = [
    'sale' => 'Sale', 'sale_void' => 'Sale voided', 'refund' => 'Refund',
    'receive' => 'Received', 'adjustment' => 'Adjustment',
    'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in',
    'initial' => 'Initial stock',
  ];

  $money = fn ($c) => $c !== null ? '$' . number_format($c / 100, 2) : '—';

  // --- here / status / locations (from show.blade.php hero) ---
  $hereIl = $currentLocation ? ($itemLocByLocId[$currentLocation->id] ?? null) : null;
  $hereStock = $hereIl ? (int) $hereIl->computed_stock_count : 0;
  $hereThreshold = $hereIl?->shop_reorder_threshold ?? $item->shop_reorder_threshold;
  if ($hereStock < 0) {
    $status = ['copy' => 'Oversold by ' . abs($hereStock), 'tone' => 'red'];
  } elseif ($hereStock === 0) {
    $status = ['copy' => 'Out of stock', 'tone' => 'red'];
  } elseif ($hereThreshold !== null && $hereStock <= $hereThreshold) {
    $status = ['copy' => 'Low — reorder soon', 'tone' => 'amber'];
  } else {
    $status = ['copy' => 'In stock', 'tone' => 'green'];
  }
  $otherLocations = $locations->filter(fn ($l) => !$currentLocation || $l->id !== $currentLocation->id);
  $totalAcrossLocations = (int) $item->computed_stock_count;

  // --- live HLC cost/avail ---
  $hlcSrc = $item->vendors->first(fn ($v) => ($v->pivot->distributor_code ?? null) === 'HLC');
  $liveCost = $hlcSrc?->pivot?->live_cost_cents;
  $liveAvail = $hlcSrc?->pivot?->live_avail;
  $liveCheckedRaw = $hlcSrc?->pivot?->live_checked_at;
  $liveChecked = $liveCheckedRaw ? \Illuminate\Support\Carbon::parse($liveCheckedRaw) : null;

  // --- effective + margin ---
  $effSell = $item->effectiveSellPriceCents();
  $effCost = $item->effectiveCostCents();
  $margin = ($effSell && $effCost && $effSell > 0) ? round(($effSell - $effCost) / $effSell * 100, 1) : null;

  // --- catalog image + specs ---
  $catImages = $item->distributorCatalog?->images ?? [];
  $catAttrs  = $item->distributorCatalog?->attributes ?? [];
  $catDesc   = $item->distributorCatalog?->description;
  $hideAttr  = ['inner pack','master pack','legacy #','legacy','ean','unit of measure',
               'shipping length (l)','shipping width (w)','shipping height (h)','shipping weight','case quantity'];
  $specRows = collect($catAttrs)
    ->filter(fn ($a) => is_array($a) && isset($a['Name'], $a['Value']) && trim((string) $a['Value']) !== '')
    ->reject(fn ($a) => in_array(strtolower(trim((string) $a['Name'])), $hideAttr, true));

  // --- special orders ---
  $openSos = $item->specialOrders->whereIn('status', ['needed', 'ordered', 'arrived'])->sortBy('expected_arrival_date');
  $closedSos = $item->specialOrders->whereIn('status', ['pulled', 'cancelled'])->sortByDesc('updated_at')->take(5);
  $onOrderQty = $openSos->sum('quantity');

  $brand = $item->distributorCatalog?->manufacturer;
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $item->name }}</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.inventory.index') }}">← Inventory</a>
      &nbsp;·&nbsp;
      <code>{{ $item->sku }}</code>
      @if($item->category)&nbsp;·&nbsp; {{ $item->category->name }}@endif
      @php $mpn = $item->distributorCatalog?->manufacturer_sku; @endphp {{-- MARKER-PATCH-587 --}}
      @if($mpn)&nbsp;·&nbsp; MPN <code>{{ $mpn }}</code>@endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.edit', $item->id) }}" class="ia-btn ia-btn--secondary">Edit</a>
    <button type="button" class="ia-btn ia-btn--primary" onclick="iaShowAdjust()">Adjust stock</button>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error">
    @foreach($errors->all() as $error){{ $error }}<br>@endforeach
  </div>
@endif

{{-- Adjust stock form (hidden until button clicked) --}}
<div id="adjust-stock-card" class="ia-card" style="display:{{ $errors->has('reason_code') || $errors->has('reason_text') || $errors->has('new_count') ? 'block' : 'none' }};margin-bottom:20px;border-left:4px solid var(--ia-accent)">
  <div class="ia-card-head">
    <span class="ia-card-title">Adjust stock</span>
    <button type="button" class="ia-card-action" onclick="iaHideAdjust()">Cancel</button>
  </div>
  <form method="POST" action="{{ route('tenant.inventory.stock', $item->id) }}">
    @csrf
    <div class="ia-card-body">
      @if($isMultiLocation)
        <div class="ia-form-group">
          <label class="ia-form-label">Location <span class="ia-required">*</span></label>
          <select name="location_id" class="ia-input" required>
            @foreach($locations as $loc)
              <option value="{{ $loc->id }}" @selected(old('location_id') === $loc->id)>
                {{ $loc->name }} ({{ $itemLocByLocId[$loc->id]->computed_stock_count ?? 0 }} on hand)
              </option>
            @endforeach
          </select>
        </div>
      @else
        @php $singleLoc = $locations->first(); @endphp
        <input type="hidden" name="location_id" value="{{ $singleLoc->id }}">
      @endif

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">New count <span class="ia-required">*</span></label>
          <input type="number" min="0" name="new_count" class="ia-input" required value="{{ old('new_count') }}">
          <div class="ia-form-hint">The actual count on hand right now. We'll calculate the difference.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reason <span class="ia-required">*</span></label>
          <select name="reason_code" class="ia-input" required onchange="document.getElementById('reason-other-row').style.display = this.value === 'other' ? '' : 'none'">
            <option value="">Select reason…</option>
            @foreach($reasonCodes as $code => $label)
              <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div id="reason-other-row" class="ia-form-group" style="display:{{ old('reason_code') === 'other' ? '' : 'none' }}">
        <label class="ia-form-label">Reason details <span class="ia-required">*</span></label>
        <input type="text" name="reason_text" class="ia-input" value="{{ old('reason_text') }}" maxlength="500">
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Notes (optional)</label>
        <textarea name="notes" class="ia-input" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
      </div>
    </div>
    <div class="ia-card-foot" style="display:flex;gap:8px;justify-content:flex-end;padding:12px 16px">
      <button type="submit" class="ia-btn ia-btn--primary">Save adjustment</button>
    </div>
  </form>
</div>

{{-- ============ A LAYOUT: media + specs left, sticky summary right ============ --}}
<div class="ia-show-grid">

  <div class="ia-show-main">

    {{-- Media --}}
    <div class="ia-card">
      <div class="ia-card-body">
        @php
          $imgSrcs = collect($catImages)->map(function ($img) {
            return is_array($img) ? ($img['url'] ?? $img['Url'] ?? $img['path'] ?? null) : (is_string($img) ? $img : null);
          })->filter()->values();
        @endphp
        @if($imgSrcs->isNotEmpty())
          <div class="ia-media-main"><img id="ia-media-hero" src="{{ $imgSrcs->first() }}" alt="{{ $item->name }}"></div>
          @if($imgSrcs->count() > 1)
            <div class="ia-media-thumbs">
              @foreach($imgSrcs as $i => $s)
                <button type="button" class="ia-media-thumb @if($i === 0)is-active @endif" data-src="{{ $s }}" onclick="iaPickImage(this)"><img src="{{ $s }}" alt=""></button>
              @endforeach
            </div>
          @endif
          <div class="ia-media-cap">{{ $imgSrcs->count() }} image{{ $imgSrcs->count() === 1 ? '' : 's' }} from {{ $item->distributorCatalog?->distributor_name ?? 'distributor' }}</div>
        @else
          <div class="ia-media-empty">No image from the distributor catalog.</div>
        @endif
      </div>
    </div>

    {{-- Specs --}}
    @if($specRows->isNotEmpty() || $catDesc)
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Specs</span>
        <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">from {{ $item->distributorCatalog?->distributor_name ?? 'distributor' }}</span>
      </div>
      <div class="ia-card-body">
        @if($catDesc)<p style="color:var(--ia-text-muted);font-size:13.5px;line-height:1.5;margin:0 0 14px">{{ $catDesc }}</p>@endif
        @if($specRows->isNotEmpty())
          <table class="ia-key-value">
            @foreach($specRows as $a)
              <tr><td>{{ $a['Name'] }}</td><td>{{ $a['Value'] }}</td></tr>
            @endforeach
          </table>
        @endif
      </div>
    </div>
    @endif

    {{-- Pricing & catalog (consolidated: catalog / yours / effective) --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Pricing &amp; catalog</span>
        <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">your settings override catalog · effective is what the register uses</span>
      </div>
      <div class="ia-card-body">
        <table class="ia-cmp">
          <thead><tr><th></th><th>Catalog</th><th>Your settings</th><th class="ia-cmp-eff">Effective</th></tr></thead>
          <tbody>
            <tr><td>Cost</td><td>{{ $money($item->catalog_cost_cents) }}</td><td>{{ $money($item->shop_cost_cents) }}</td><td class="ia-cmp-eff">{{ $money($effCost) }}</td></tr>
            <tr><td>Sell / MSRP</td><td>{{ $money($item->catalog_msrp_cents) }}</td><td>{{ $money($item->shop_sell_price_cents) }}</td><td class="ia-cmp-eff">{{ $money($effSell) }}</td></tr>
            <tr><td>Case qty</td><td>{{ $item->catalog_case_quantity ?? '—' }}</td><td>{{ $item->shop_case_quantity ?? '—' }}</td><td class="ia-cmp-eff">—</td></tr>
            <tr><td>Margin</td><td>—</td><td>—</td><td class="ia-cmp-eff">{{ $margin !== null ? $margin . '%' : '—' }}</td></tr>
          </tbody>
        </table>

        <table class="ia-key-value" style="margin-top:16px">
          <tr><td>Your dealer cost (live)</td><td>{{ $money($liveCost) }}</td></tr>
          <tr><td>Available (HLC)</td><td>{{ $liveAvail !== null ? $liveAvail : '—' }}</td></tr>
          <tr><td>Cost checked</td><td>{{ $liveChecked?->diffForHumans() ?? 'never' }}</td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          <tr><td>Last synced</td><td>{{ $item->catalog_synced_at?->diffForHumans() ?? 'never' }}</td></tr>
          <tr><td>Reorder at</td><td>{{ $item->shop_reorder_threshold ?? '—' }}</td></tr>
          <tr><td>Reorder qty</td><td>{{ $item->shop_reorder_quantity ?? '—' }}</td></tr>
          <tr><td>Bin location</td><td>{{ $item->shop_bin_location ?? '—' }}</td></tr>
        </table>
      </div>
    </div>

    @if($isMultiLocation)
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Stock by location</span>
      </div>
      <div class="ia-card-body">
        <table class="ia-table">
          <thead><tr><th>Location</th><th style="text-align:right">On hand</th><th style="text-align:right">Reorder at</th><th>Bin</th></tr></thead>
          <tbody>
            @foreach($locations as $loc)
              @php $il = $itemLocByLocId[$loc->id] ?? null; @endphp
              <tr>
                <td>{{ $loc->name }} @if($loc->is_default)<span class="ia-badge">default</span>@endif</td>
                <td style="text-align:right">
                  <span @if($il && 0 > $il->computed_stock_count) style="color:#E24B4A;font-weight:600" @endif>{{ $il ? $il->computed_stock_count : 0 }}</span>
                  @if($il && $il->isLowStock())<span class="ia-badge ia-badge--amber">Low</span>@endif
                </td>
                <td style="text-align:right;color:var(--ia-text-muted)">{{ $il && $il->shop_reorder_threshold !== null ? $il->shop_reorder_threshold : '—' }}</td>
                <td>{{ $il && $il->shop_bin_location ? $il->shop_bin_location : '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endif

  </div>

  {{-- sticky summary --}}
  <aside class="ia-show-side">
    <div class="ia-card">
      <div class="ia-card-body">
        <span class="ia-badge @if($status['tone']==='red')ia-badge--red @elseif($status['tone']==='amber')ia-badge--amber @else ia-badge--green @endif" style="padding:4px 10px;font-size:12px">{{ $status['copy'] }}</span>
        <div class="ia-sum-price">
          <span class="ia-sum-sell">{{ $money($effSell) }}</span>
          <span class="ia-sum-sub">sell</span>
        </div>
        <div class="ia-sum-ref">
          <span>Cost <b>{{ $money($effCost) }}</b></span>
          <span>Margin <b @if($margin!==null)style="color:var(--ia-success)"@endif>{{ $margin !== null ? $margin . '%' : '—' }}</b></span>
        </div>
        @if($item->catalog_msrp_cents !== null)
          <div class="ia-sum-msrp">MSRP {{ $money($item->catalog_msrp_cents) }} <span style="opacity:.6">(catalog)</span></div>
        @endif
        <button type="button" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center;margin-top:14px" onclick="iaShowAdjust()">Adjust stock</button>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Stock</span></div>
      <div class="ia-card-body">
        <div class="ia-sum-stock">
          <div class="row"><span>Here @if($currentLocation)· {{ $currentLocation->name }}@endif</span><span class="n @if($status['tone']==='red')neg @endif">{{ $hereStock }}</span></div>
          @if($isMultiLocation)
            <div class="row"><span>All locations</span><span class="n">{{ $totalAcrossLocations }}</span></div>
          @endif
          <div class="row"><span>On special order</span><span class="n">{{ $onOrderQty }}</span></div>
        </div>
        @if($isMultiLocation && !$otherLocations->isEmpty())
          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--ia-border)">
            @foreach($otherLocations as $ol)
              @php $oil = $itemLocByLocId[$ol->id] ?? null; $oStock = $oil ? (int)$oil->computed_stock_count : 0; @endphp
              <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;color:var(--ia-text-muted)"><span>{{ $ol->name }}</span><span>{{ $oStock }}</span></div>
            @endforeach
          </div>
        @endif
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Identity</span>
        {{-- MARKER-PATCH-569 — per-item storefront publish toggle --}}
        @if(tenant()->online_store_enabled)
          <form method="POST" action="{{ route('tenant.storefront.item.toggle', $item->id) }}" style="margin-left:auto">
            @csrf
            <button class="ia-btn ia-btn--ghost ia-btn--sm" title="{{ $item->show_online ? 'Visible at /shop — click to remove' : 'Not in your online store — click to publish' }}">
              {{ $item->show_online ? '● In online store' : '○ Not in store' }}
            </button>
          </form>
        @endif
      </div>
      <div class="ia-card-body">
        <table class="ia-key-value">
          @if($brand)<tr><td>Brand</td><td>{{ $brand }}</td></tr>@endif
          @if($item->category)<tr><td>Category</td><td>{{ $item->category->name }}</td></tr>@endif
          <tr><td>MPN</td><td>{{ $mpn ?: '—' }}</td></tr>
          <tr><td>SKU</td><td><code>{{ $item->sku }}</code></td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          <tr><td>Source</td><td>{{ $item->distributor_catalog_id ? ($item->distributorCatalog?->distributor_name ?? 'distributor') : 'manual' }}</td></tr>
        </table>
      </div>
    </div>
  </aside>

</div>

{{-- ============ tabbed: activity / special orders / sourced from ============ --}}
<div class="ia-card ia-show-tabs" style="margin-top:4px">
  <div class="ia-tabbar">
    <button type="button" class="ia-tab is-active" data-tab="activity">Recent activity</button>
    <button type="button" class="ia-tab" data-tab="so">Special orders @if($openSos->count())<span class="ia-tab-badge">{{ $openSos->count() }}</span>@endif</button>
    @if($item->vendors->count() > 0)<button type="button" class="ia-tab" data-tab="src">Sourced from</button>@endif
  </div>

  {{-- Activity --}}
  <div class="ia-tabpanel" data-panel="activity">
    @if($recentMovements->isEmpty())
      <div style="text-align:center;color:var(--ia-text-muted);padding:20px">No movements yet.</div>
    @else
      <table class="ia-table">
        <thead><tr><th>When</th><th>Type</th><th>Location</th><th style="text-align:right">Delta</th><th>Reason / Notes</th></tr></thead>
        <tbody>
          @foreach($recentMovements as $mv)
            <tr>
              <td>{{ $mv->created_at?->diffForHumans() ?? '—' }}</td>
              <td>{{ $movementTypeLabels[$mv->movement_type] ?? $mv->movement_type }}</td>
              <td>{{ $mv->location?->name ?? '—' }}</td>
              <td style="text-align:right;color:{{ $mv->quantity_delta > 0 ? 'var(--ia-success)' : ($mv->quantity_delta < 0 ? 'var(--ia-error)' : 'inherit') }}">{{ $mv->quantity_delta > 0 ? '+' : '' }}{{ $mv->quantity_delta }}</td>
              <td style="font-size:13px;color:var(--ia-text-muted)">{{ $mv->reason ? $mv->reason . ($mv->notes ? ' · ' : '') : '' }}{{ $mv->notes }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- Special orders --}}
  <div class="ia-tabpanel" data-panel="so" hidden>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div style="display:flex;align-items:baseline;gap:24px">
        <div>
          <div style="font-size:28px;font-weight:600">{{ $onOrderQty }}</div>
          <div style="font-size:13px;color:var(--ia-text-muted)">on order across {{ $openSos->count() }} SO{{ $openSos->count() === 1 ? '' : 's' }}</div>
        </div>
      </div>
      <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" onclick='SoDrawer.open({item_id: @json($item->id), item_name: @json($item->name)})'>+ Special order this item</button>
    </div>

    @if($openSos->count() > 0)
      <table class="ia-table">
        <thead><tr><th>SO</th><th>Qty</th><th>For</th><th>Vendor</th><th>Status</th><th>ETA</th></tr></thead>
        <tbody>
          @foreach($openSos as $so)
            <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
              <td><strong>{{ $so->so_number }}</strong></td>
              <td>{{ $so->quantity }}</td>
              <td>@if($so->customer){{ $so->customer->fullName() }}@else<span style="color:var(--ia-text-muted)">Shop stock</span>@endif</td>
              <td>{{ $so->vendor?->name ?? '—' }}</td>
              <td>
                @php $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast(); @endphp
                <span class="so-status so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
              </td>
              <td style="color:var(--ia-text-muted);font-size:12px">@if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <p style="font-size:13px;color:var(--ia-text-muted);margin:0">No open special orders for this item.</p>
    @endif

    @if($closedSos->count() > 0)
      <details style="margin-top:16px">
        <summary style="font-size:12px;color:var(--ia-text-muted);cursor:pointer">Recent closed ({{ $closedSos->count() }})</summary>
        <table class="ia-table" style="margin-top:8px">
          <tbody>
            @foreach($closedSos as $so)
              <tr style="cursor:pointer;opacity:.7" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                <td><strong>{{ $so->so_number }}</strong></td>
                <td>{{ $so->quantity }}</td>
                <td>{{ $so->customer ? $so->customer->fullName() : 'Stock' }}</td>
                <td><span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span></td>
                <td style="color:var(--ia-text-muted);font-size:12px">{{ $so->updated_at->format('M j, Y') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </details>
    @endif
  </div>

  {{-- Sourced from --}}
  @if($item->vendors->count() > 0)
  <div class="ia-tabpanel" data-panel="src" hidden>
    <table class="ia-table">
      <thead><tr><th>Vendor</th><th>Vendor SKU</th><th>Cost</th><th>Lead time</th><th></th></tr></thead>
      <tbody>
        @foreach($item->vendors as $vendor)
          <tr>
            <td><a href="{{ route('tenant.vendors.show', ['id' => $vendor->id]) }}"><strong>{{ $vendor->name }}</strong></a></td>
            <td style="color:var(--ia-text-muted)">{{ $vendor->pivot->vendor_sku ?: '—' }}</td>
            <td>@if($vendor->pivot->unit_cost_cents !== null){{ format_money($vendor->pivot->unit_cost_cents) }}@else<span style="color:var(--ia-text-muted)">—</span>@endif</td>
            <td>@if($vendor->pivot->lead_time_days !== null){{ $vendor->pivot->lead_time_days }}d @else<span style="color:var(--ia-text-muted)">—</span>@endif</td>
            <td>@if($vendor->pivot->is_preferred)<span class="ia-badge ia-badge--accent">Preferred</span>@endif</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif
</div>

@include('tenant.special-orders._drawer', ['vendors' => $vendors ?? collect()])

@push('styles')
<style>
  .ia-show-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start;margin-bottom:20px}
  .ia-show-main{display:flex;flex-direction:column;gap:20px;min-width:0}
  .ia-show-side{position:sticky;top:20px;display:flex;flex-direction:column;gap:16px}

  .ia-media-main{background:#f3f3f1;border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;aspect-ratio:4/3;max-height:360px}
  .ia-media-main img{max-width:100%;max-height:100%;object-fit:contain}
  .ia-media-thumbs{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
  .ia-media-thumb{width:56px;height:56px;border-radius:7px;background:#f3f3f1;border:1px solid var(--ia-border);overflow:hidden;padding:0;cursor:pointer}
  .ia-media-thumb.is-active{border-color:var(--ia-accent);box-shadow:0 0 0 1px var(--ia-accent)}
  .ia-media-thumb img{width:100%;height:100%;object-fit:contain}
  .ia-media-cap{margin-top:10px;font-size:11.5px;color:var(--ia-text-muted)}
  .ia-media-empty{color:var(--ia-text-muted);font-size:13px;padding:30px 0;text-align:center}

  .ia-sum-price{display:flex;align-items:baseline;gap:8px;margin-top:14px}
  .ia-sum-sell{font-size:30px;font-weight:680;letter-spacing:-.02em}
  .ia-sum-sub{color:var(--ia-text-muted);font-size:13px}
  .ia-sum-ref{display:flex;gap:18px;color:var(--ia-text-muted);font-size:12.5px;margin-top:4px}
  .ia-sum-ref b{color:var(--ia-text);font-weight:600}
  .ia-sum-msrp{color:var(--ia-text-muted);font-size:12px;margin-top:8px}
  .ia-sum-stock .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--ia-border)}
  .ia-sum-stock .row:last-child{border-bottom:0}
  .ia-sum-stock .row span:first-child{color:var(--ia-text-muted)}
  .ia-sum-stock .n{font-variant-numeric:tabular-nums;font-weight:600}
  .ia-sum-stock .n.neg{color:#E24B4A}

  table.ia-cmp{width:100%;border-collapse:collapse;font-size:13px}
  table.ia-cmp th,table.ia-cmp td{padding:9px 10px;border-bottom:1px solid var(--ia-border);text-align:right;font-variant-numeric:tabular-nums}
  table.ia-cmp th:first-child,table.ia-cmp td:first-child{text-align:left;color:var(--ia-text-muted)}
  table.ia-cmp thead th{color:var(--ia-text-muted);font-weight:600;font-size:11px;letter-spacing:.04em;text-transform:uppercase}
  table.ia-cmp .ia-cmp-eff{color:var(--ia-text);font-weight:600}
  table.ia-cmp thead .ia-cmp-eff{color:var(--ia-accent)}
  table.ia-cmp tr:last-child td{border-bottom:0}

  .ia-tabbar{display:flex;gap:4px;border-bottom:1px solid var(--ia-border);padding:0 4px;margin-bottom:14px}
  .ia-tab{background:none;border:0;color:var(--ia-text-muted);font:inherit;font-size:13px;font-weight:600;
    padding:11px 14px;border-bottom:2px solid transparent;cursor:pointer}
  .ia-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
  .ia-tab-badge{display:inline-block;font-size:11px;background:var(--ia-accent);color:#1a1206;border-radius:99px;padding:1px 7px;margin-left:5px;font-weight:700}
  .ia-tabpanel[hidden]{display:none}

  .so-status{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
  .so-status--needed{background:rgba(167,139,250,.10);color:#A78BFA}
  .so-status--ordered{background:rgba(96,165,250,.10);color:#60A5FA}
  .so-status--arrived{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  .so-status--pulled{background:rgba(200,200,200,.06);color:var(--ia-text-muted)}
  .so-status--cancelled{background:rgba(248,113,113,.10);color:#F87171;text-decoration:line-through}
  .so-status--overdue{background:rgba(248,113,113,.15);color:#F87171}

  @media(max-width:900px){
    .ia-show-grid{grid-template-columns:1fr}
    .ia-show-side{position:static}
  }
</style>
<script>
  function iaPickImage(btn){var h=document.getElementById('ia-media-hero');if(h){h.src=btn.getAttribute('data-src');}var p=btn.parentElement;if(p){p.querySelectorAll('.ia-media-thumb').forEach(function(t){t.classList.toggle('is-active',t===btn);});}}
  function iaShowAdjust(){var c=document.getElementById('adjust-stock-card');if(c){c.style.display='block';c.scrollIntoView({behavior:'smooth',block:'nearest'});}}
  function iaHideAdjust(){var c=document.getElementById('adjust-stock-card');if(c){c.style.display='none';}}
  (function(){
    document.querySelectorAll('.ia-show-tabs .ia-tab').forEach(function(btn){
      btn.addEventListener('click', function(){
        var root = btn.closest('.ia-show-tabs'), t = btn.getAttribute('data-tab');
        root.querySelectorAll('.ia-tab').forEach(function(x){ x.classList.toggle('is-active', x === btn); });
        root.querySelectorAll('[data-panel]').forEach(function(p){ p.hidden = (p.getAttribute('data-panel') !== t); });
      });
    });
  })();
</script>
@endpush

@endsection

BIZ3_15_EOF

cat > 'resources/views/tenant/special-orders/index.blade.php' <<'BIZ3_16_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Special Orders'; @endphp

@section('content')

{{-- SO-LIST · tab-filtered list with desktop table + mobile cards.
     Parallel render pattern matches customers/vendors. --}}

{{-- ========== DESKTOP HEAD ========== --}}
<div class="ia-page-head so-desktop-only">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Special orders</h1>
    <p class="ia-page-subtitle">
      {{ $counts['open'] }} open
      @if($counts['arrived_bench'] > 0) · {{ $counts['arrived_bench'] }} on bench @endif
      @if($counts['overdue'] > 0) · <span style="color:#F87171">{{ $counts['overdue'] }} overdue</span> @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" onclick="SoDrawer.open()">
      + New special order
    </button>
  </div>
</div>

{{-- ========== MOBILE HEAD ========== --}}
<div class="so-mobile-only so-mobile-head">
  <h1 class="so-mobile-title">Special orders</h1>
  <p class="so-mobile-sub">{{ $counts['open'] }} open · {{ $counts['arrived_bench'] }} on bench</p>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== TAB FILTERS ========== --}}
<div class="so-tabs">
  @php
    $tabs = [
      'open'          => ['label' => 'All open',      'count' => $counts['open']],
      'arrived_bench' => ['label' => 'Arrived bench', 'count' => $counts['arrived_bench']],
      'overdue'       => ['label' => 'Overdue',       'count' => $counts['overdue']],
      'pulled'        => ['label' => 'Pulled',        'count' => $counts['pulled']],
      'cancelled'     => ['label' => 'Cancelled',     'count' => $counts['cancelled']],
    ];
  @endphp
  @foreach($tabs as $key => $tab)
    <a href="{{ route('tenant.special-orders.index', ['view' => $key]) }}"
       class="so-tab {{ $view === $key ? 'active' : '' }}">
      {{ $tab['label'] }}
      <span class="so-tab-count">{{ $tab['count'] }}</span>
    </a>
  @endforeach
</div>

{{-- ========== LIST ========== --}}
@if($sos->isEmpty())
  <div class="ia-empty">
    <p>No special orders in this view.</p>
    <p class="ia-empty-sub">
      @if($view === 'open')
        Create one with "+ New special order" above. Or wait — SOs will appear here as they're created from the register, appointments, or item detail pages (those flows ship in upcoming stages).
      @else
        Try a different tab.
      @endif
    </p>
  </div>
@else

{{-- MARKER-SO-ORIGIN --}}
<style>
  .so-origin{font-size:10.5px;font-weight:700;border-radius:100px;padding:3px 9px;white-space:nowrap;border:0.5px solid var(--ia-border);color:var(--ia-text-muted)}
  .so-origin--live{border-color:rgba(143,184,240,.35);color:#8FB8F0;background:rgba(143,184,240,.08)}
  .so-origin--orphan{border-color:rgba(240,149,149,.35);color:#F09595;background:rgba(240,149,149,.08)}
  .so-origin--unknown{border-color:rgba(232,163,61,.35);color:#E8A33D;background:rgba(232,163,61,.08)}
  .so-origin--confirmed{border-color:rgba(127,217,143,.35);color:#7FD98F;background:rgba(127,217,143,.08)}
  .so-origin-acts{display:flex;gap:5px;margin-top:6px}
  .so-oa{font-family:inherit;font-size:10.5px;font-weight:700;border-radius:7px;padding:4px 8px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text)}
  .so-oa:hover{border-color:var(--ia-accent)}
  .so-oa.danger{color:#F09595;border-color:rgba(240,149,149,.35)}
  .so-oa[disabled]{opacity:.5;cursor:default}
</style>

{{-- MARKER-SO-ONESCREEN — grouped mode replaces both renderers, so the same
     orders are never shown twice, and it works on phones as well as desktop. --}}
@if($grouped) {{-- MARKER-SO-SCROLL — open orders ARE the grouped screen --}}
  @include('tenant.special-orders._vendor_groups')
@else

  {{-- Desktop table --}}
  <div class="ia-card so-desktop-only">
    <table class="ia-table">
      <thead>
        <tr>
          <th>SO</th>
          <th>Item</th>
          <th>Qty</th>
          <th>For</th>
          <th>Vendor</th>
          <th>Origin</th>{{-- MARKER-SO-ORIGIN --}}
          <th>Status</th>
          <th>ETA</th>
        </tr>
      </thead>
      <tbody>
        @foreach($sos as $so)
          <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
            <td>
              <strong>{{ $so->so_number }}</strong>
              @if($so->batch_id)
                <div class="ia-text-muted" style="font-size:10.5px">batch</div>
              @endif
            </td>
            <td>
              <strong>{{ $so->item_name_snapshot }}</strong>
              @if($so->item && $so->item->sku)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $so->item->sku }}</div>
              @endif
            </td>
            <td>{{ $so->quantity }}</td>
            <td>
              @if($so->customer)
                <strong>{{ $so->customer->fullName() }}</strong>
                @if($so->appointment)
                  <div class="ia-text-muted" style="font-size:11.5px">
                    {{ $so->appointment->ra_number }} · {{ $so->appointment->appointment_date?->format('M j') }}
                  </div>
                @endif
              @else
                <span class="ia-text-muted">Shop stock</span>
              @endif
            </td>
            <td>
              @if($so->vendor)
                {{ $so->vendor->name }}
              @else
                <span class="ia-text-muted" style="font-size:12px">TBD</span>
              @endif
            </td>
            {{-- MARKER-SO-ORIGIN — where it came from, and whether that source
                 still exists. Orphans carry their two honest choices inline. --}}
            @php $og = $origins[$so->id] ?? ['state' => 'manual', 'label' => '—']; @endphp
            <td onclick="event.stopPropagation()" style="cursor:default">
              <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
              @if($so->created_at)
                <div class="ia-text-muted" style="font-size:10.5px;margin-top:3px">
                  {{ (int) $so->created_at->diffInDays(now()) }}d old
                  @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
                    · auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}
                  @endif
                </div>
              @endif
              @if(in_array($og['state'], ['orphan', 'unknown'], true) && $so->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
                <div class="so-origin-acts" data-so="{{ $so->id }}">
                  <button type="button" class="so-oa" data-so-keep>Still needed</button>
                  <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
                </div>
              @endif
            </td>
            <td>
              @php
                $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
              @endphp
              @if($isOverdue)
                <span class="so-status so-status--overdue">Overdue</span>
              @else
                <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
              @endif
            </td>
            <td class="ia-text-muted" style="font-size:12px">
              @if($so->expected_arrival_date)
                {{ $so->expected_arrival_date->format('M j') }}
              @else
                —
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Mobile cards --}}
  <div class="so-mobile-only so-cards">
    @foreach($sos as $so)
      @php
        $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
      @endphp
      <a href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}"
         class="so-card">
        <div class="so-card-top">
          <span class="so-card-num">{{ $so->so_number }}</span>
          @if($isOverdue)
            <span class="so-status so-status--overdue">Overdue</span>
          @else
            <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
          @endif
        </div>
        <div class="so-card-item">{{ $so->item_name_snapshot }} <span class="ia-text-muted">×{{ $so->quantity }}</span></div>
        <div class="so-card-meta">
          @if($so->customer)
            {{ $so->customer->fullName() }}
          @else
            Shop stock
          @endif
          @if($so->vendor) · {{ $so->vendor->name }} @endif
          @if($so->expected_arrival_date) · ETA {{ $so->expected_arrival_date->format('M j') }} @endif
        </div>
        {{-- MARKER-SO-ORIGIN-MOBILE — the desktop table showed origin and its
             actions; the cards did not, which is where triage actually
             happens. Same data, same actions, thumb-sized. --}}
        @php $og = $origins[$so->id] ?? null; @endphp
        @if($og)
          <div class="so-card-meta" style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
            @if($so->created_at)<span class="ia-text-muted" style="font-size:11px">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>@endif
            @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
              <span class="ia-text-muted" style="font-size:11px">auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}</span>
            @endif
          </div>
          @if(in_array($og['state'], ['orphan', 'unknown'], true) && $so->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
            <div class="so-origin-acts" data-so="{{ $so->id }}" onclick="event.preventDefault();event.stopPropagation()">
              <button type="button" class="so-oa" data-so-keep>Still needed</button>
              <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
            </div>
          @endif
        @endif
      </a>
    @endforeach
  </div>
@endif{{-- MARKER-SO-ONESCREEN --}}

  @if($totalPages > 1 && !$grouped) {{-- MARKER-SO-SCROLL — the open view scrolls instead of paging --}}
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.special-orders.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">{{ $p }}</a>
      @endfor
    </div>
  @endif
@endif

{{-- ========== DRAWER (universal create surface) ========== --}}
@include('tenant.special-orders._drawer', ['vendors' => $vendors])

{{-- Mobile FAB (alternate entry to the drawer) --}}
<button type="button" class="so-fab so-mobile-only" onclick="SoDrawer.open()" aria-label="New special order">+</button>

@push('styles')
<style>
/* SO-LIST styles — parallels customers/vendors patterns */

.so-mobile-only { display: none; }

@media (max-width: 700px) {
  .so-desktop-only { display: none; }
  .so-mobile-only  { display: block; }
  .so-cards        { display: flex; }
  .so-mobile-head { padding: 16px 0 12px; }
  .so-mobile-title { font-size: 22px; font-weight: 600; margin: 0; color: var(--ia-text); }
  .so-mobile-sub { font-size: 12px; color: var(--ia-text-muted); margin: 2px 0 0; }
}

/* Tabs */
.so-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--ia-border);
  margin-bottom: 18px;
  overflow-x: auto;
  scrollbar-width: none;
}
.so-tabs::-webkit-scrollbar { display: none; }
.so-tab {
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 500;
  color: var(--ia-text-muted);
  border-bottom: 2px solid transparent;
  text-decoration: none;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.so-tab:hover { color: var(--ia-text); }
.so-tab.active {
  color: var(--ia-text);
  border-bottom-color: var(--ia-accent);
}
.so-tab-count {
  font-size: 11px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--ia-surface);
  color: var(--ia-text-muted);
}
.so-tab.active .so-tab-count {
  background: var(--ia-accent);
  color: #000;
}

/* Status pills */
.so-status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 10.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
.so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
.so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent); }
.so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-muted); }
.so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
.so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }

/* Mobile cards */
.so-cards { flex-direction: column; gap: 8px; }
.so-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 16px;
  text-decoration: none;
  color: inherit;
  display: block;
}
.so-card-top {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 6px;
}
.so-card-num { font-size: 13px; font-weight: 700; color: var(--ia-text); font-variant-numeric: tabular-nums; }
.so-card-item { font-size: 14.5px; color: var(--ia-text); margin-bottom: 3px; }
.so-card-meta { font-size: 11.5px; color: var(--ia-text-muted); }

/* Mobile FAB */
.so-fab {
  position: fixed;
  bottom: calc(76px + env(safe-area-inset-bottom, 0));
  right: 18px;
  width: 56px; height: 56px;
  border-radius: 50%;
  background: var(--ia-accent);
  color: #000;
  font-size: 28px; font-weight: 400;
  border: none;
  box-shadow: 0 4px 16px rgba(0,0,0,0.3);
  cursor: pointer;
  z-index: 30;
}
@media (min-width: 701px) {
  .so-fab { display: none; }
}
</style>
@endpush

{{-- MARKER-SO-ORIGIN — resolve an orphaned request without leaving the list --}}
<script>
(function () {
  var confirmUrl = @json(route('tenant.special-orders.confirm-source', ['id' => '__ID__']));
  var cancelUrl  = @json(route('tenant.special-orders.cancel', ['id' => '__ID__']));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function post(url, body, wrap, okText) {
    wrap.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body || {}),
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          wrap.innerHTML = '<span class="ia-text-muted" style="font-size:10.5px">' + okText + '</span>';
          if (window.IntakeToast) IntakeToast.success(okText);
        } else {
          wrap.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not save.');
        }
      })
      .catch(function () {
        wrap.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        if (window.IntakeToast) IntakeToast.error('Network error.');
      });
  }

  document.addEventListener('click', function (e) {
    var wrap = e.target.closest('.so-origin-acts');
    if (!wrap) return;
    var id = wrap.getAttribute('data-so');
    if (e.target.hasAttribute('data-so-keep')) {
      post(confirmUrl.replace('__ID__', id), {}, wrap, 'Confirmed still needed');
    } else if (e.target.hasAttribute('data-so-drop')) {
      post(cancelUrl.replace('__ID__', id), { reason: 'Source removed — abandoned request.' }, wrap, 'Cancelled');
    }
  });
})();
</script>

@endsection
BIZ3_16_EOF

cat > 'resources/views/tenant/special-orders/show.blade.php' <<'BIZ3_17_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = $so->so_number; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div class="ia-text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:4px">
      <a href="{{ route('tenant.special-orders.index') }}" style="color:inherit;text-decoration:none">← Special orders</a>
    </div>
    <h1 class="ia-page-title">{{ $so->so_number }}</h1>
    <p class="ia-page-subtitle">
      {{ $so->item_name_snapshot }} ×{{ $so->quantity }}
      @if($so->customer) · for {{ $so->customer->fullName() }} @endif
      @if($so->appointment) · {{ $so->appointment->ra_number }} @endif
    </p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== STATE STRIP ========== --}}
@php
  $stages = ['needed' => 'Needed', 'ordered' => 'Ordered', 'arrived' => 'Arrived', 'pulled' => 'Pulled'];
  $stageIdx = array_search($so->status, array_keys($stages));
  $isCancelled = $so->status === 'cancelled';
@endphp
<div class="so-state-strip">
  @foreach($stages as $key => $label)
    @php
      $i = array_search($key, array_keys($stages));
      $isDone    = !$isCancelled && $stageIdx !== false && $i < $stageIdx;
      $isCurrent = !$isCancelled && $key === $so->status;
    @endphp
    <div class="so-state-step {{ $isDone ? 'done' : '' }} {{ $isCurrent ? 'current' : '' }}">
      {{ $label }}
    </div>
  @endforeach
  @if($isCancelled)
    <div class="so-state-step cancelled current">Cancelled</div>
  @endif
</div>

<div class="so-show-grid">

  {{-- LEFT COLUMN --}}
  <div class="so-show-col">

    {{-- Order details card --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Order details</span>
        <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
      </div>
      <div class="ia-card-body">
        <div class="so-detail-grid">
          <div>
            <div class="so-detail-label">Item</div>
            <div class="so-detail-value">
              <strong>{{ $so->item_name_snapshot }}</strong>
              @if($so->item && $so->item->sku)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $so->item->sku }}</div>
              @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Quantity</div>
            <div class="so-detail-value"><strong>{{ $so->quantity }}</strong></div>
          </div>
          <div>
            <div class="so-detail-label">Vendor</div>
            <div class="so-detail-value">
              @if($so->vendor)
                <a href="{{ route('tenant.vendors.show', ['id' => $so->vendor->id]) }}">{{ $so->vendor->name }}</a>
              @else
                <span class="ia-text-muted">TBD</span>
              @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Our PO #</div>
            <div class="so-detail-value">{{ $so->po_number ?: '—' }}</div>
          </div>
          <div>
            <div class="so-detail-label">Vendor reference</div>
            <div class="so-detail-value">{{ $so->vendor_reference ?: '—' }}</div>
          </div>
          <div>
            <div class="so-detail-label">Expected arrival</div>
            <div class="so-detail-value">
              @if($so->expected_arrival_date)
                {{ $so->expected_arrival_date->format('M j, Y') }}
                @if($so->status === 'ordered' && $so->expected_arrival_date->isPast())
                  <span class="so-status so-status--overdue" style="margin-left:6px">Overdue</span>
                @endif
              @else
                —
              @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Estimated unit cost</div>
            <div class="so-detail-value">
              @if($so->unit_cost_cents_estimated !== null){{ format_money($so->unit_cost_cents_estimated) }}@else — @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Actual unit cost</div>
            <div class="so-detail-value">
              @if($so->unit_cost_cents_actual !== null){{ format_money($so->unit_cost_cents_actual) }}@else — @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Invoice #</div>
            <div class="so-detail-value">{{ $so->vendor_invoice_number ?: '—' }}</div>
          </div>
          <div>
            <div class="so-detail-label">Invoice date</div>
            <div class="so-detail-value">{{ $so->vendor_invoice_date?->format('M j, Y') ?: '—' }}</div>
          </div>
        </div>
      </div>
    </div>

    {{-- Deposit card --}}
    @if($so->deposit_cents > 0)
      <div class="ia-card" style="margin-top:16px">
        <div class="ia-card-head">
          <span class="ia-card-title">Deposit</span>
        </div>
        <div class="ia-card-body">
          <div class="so-detail-grid">
            <div>
              <div class="so-detail-label">Deposit collected</div>
              <div class="so-detail-value"><strong>{{ format_money($so->deposit_cents) }}</strong></div>
            </div>
            <div>
              <div class="so-detail-label">Paid at</div>
              <div class="so-detail-value">
                @if($so->deposit_paid_at){{ $so->deposit_paid_at->format('M j, Y') }}@else <span class="ia-text-muted">pending</span> @endif
              </div>
            </div>
          </div>
          <p class="ia-text-muted" style="font-size:11.5px;margin-top:12px">
            Deposit Stripe capture wires up in Stage 6 with the register integration. Current display reflects what's stored on the SO row.
          </p>
        </div>
      </div>
    @endif

    {{-- Notes thread --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">Notes</span>
        <span class="ia-text-muted" style="font-size:11.5px">{{ $so->notes->count() }} {{ Str::plural('note', $so->notes->count()) }}</span>
      </div>
      <div class="ia-card-body" style="padding-top:8px">
        @foreach($so->notes as $note)
          <div class="so-note {{ $note->is_system ? 'system' : '' }}">
            <div class="so-note-meta">
              <strong>{{ $note->is_system ? 'System' : ($note->user?->name ?? 'Staff') }}</strong>
              · {{ $note->created_at->format('M j, g:i a') }}
            </div>
            <div class="so-note-body">{{ $note->body }}</div>
          </div>
        @endforeach

        @if(!in_array($so->status, ['pulled', 'cancelled']))
          <form method="POST" action="{{ route('tenant.special-orders.notes.store', ['id' => $so->id]) }}" style="margin-top:14px">
            @csrf
            <textarea name="body" class="ia-input" rows="2" placeholder="Add a note (visible to staff only)…" required></textarea>
            <div style="margin-top:8px;text-align:right">
              <button type="submit" class="ia-btn ia-btn--secondary">Add note</button>
            </div>
          </form>
        @endif
      </div>
    </div>

    {{-- Batch siblings (if any) --}}
    @if($batchSiblings->isNotEmpty())
      <div class="ia-card" style="margin-top:16px">
        <div class="ia-card-head">
          <span class="ia-card-title">Other rows in this batch</span>
        </div>
        <table class="ia-table ia-table--inset">
          <thead>
            <tr>
              <th>SO</th>
              <th>For</th>
              <th>Qty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            @foreach($batchSiblings as $sib)
              <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $sib->id]) }}'">
                <td>{{ $sib->so_number }}</td>
                <td>
                  @if($sib->customer)
                    {{ $sib->customer->fullName() }}
                  @else
                    <span class="ia-text-muted">Shop stock</span>
                  @endif
                </td>
                <td>{{ $sib->quantity }}</td>
                <td><span class="so-status so-status--{{ $sib->status }}">{{ ucfirst($sib->status) }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

  </div>

  {{-- RIGHT COLUMN --}}
  <div class="so-show-col">

    {{-- Action buttons card --}}
    @if(!in_array($so->status, ['pulled', 'cancelled']))
      <div class="ia-card">
        <div class="ia-card-head"><span class="ia-card-title">Actions</span></div>
        <div class="ia-card-body" style="display:flex;flex-direction:column;gap:8px">

          @if($so->status === 'needed')
            <button type="button" class="ia-btn ia-btn--primary" onclick="SoActions.openOrdered()">Mark ordered</button>
          @endif

          @if($so->status === 'ordered')
            <button type="button" class="ia-btn ia-btn--primary" onclick="SoActions.openArrived()">Mark arrived</button>
          @endif

          @if($so->status === 'arrived')
            <form method="POST" action="{{ route('tenant.special-orders.mark-pulled', ['id' => $so->id]) }}">
              @csrf
              <button type="submit" class="ia-btn ia-btn--primary" style="width:100%">Mark pulled</button>
            </form>
          @endif

          <button type="button" class="ia-btn ia-btn--danger" onclick="SoActions.openCancel()">Cancel order</button>
        </div>
      </div>
    @endif

    {{-- Linked to card --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head"><span class="ia-card-title">Linked to</span></div>
      <div class="ia-card-body">
        @if($so->customer)
          <div style="margin-bottom:14px">
            <div class="so-detail-label">Customer</div>
            <div style="margin-top:4px">
              <a href="{{ route('tenant.customers.show', ['id' => $so->customer->id]) }}">
                <strong>{{ $so->customer->fullName() }}</strong>
              </a>
              @if($so->customer->email)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $so->customer->email }}</div>
              @endif
            </div>
          </div>
        @endif
        @if($so->appointment)
          <div>
            <div class="so-detail-label">Appointment</div>
            <div style="margin-top:4px">
              <strong>{{ $so->appointment->ra_number }}</strong>
              <div class="ia-text-muted" style="font-size:11.5px">
                {{ $so->appointment->appointment_date?->format('M j, Y') }}
              </div>
            </div>
          </div>
        @endif
        @if(!$so->customer && !$so->appointment)
          <span class="ia-text-muted" style="font-size:13px">Shop stock — not linked to a customer or appointment</span>
        @endif
      </div>
    </div>

    {{-- Created from --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head"><span class="ia-card-title">Metadata</span></div>
      <div class="ia-card-body">
        <div class="so-detail-grid">
          <div>
            <div class="so-detail-label">Created from</div>
            <div class="so-detail-value">{{ str_replace('_', ' ', ucfirst($so->created_from)) }}</div>
          </div>
          <div>
            <div class="so-detail-label">Created</div>
            <div class="so-detail-value" style="font-size:12px">{{ $so->created_at->format('M j, Y g:i a') }}</div>
          </div>
          @if($so->ordered_at)
            <div>
              <div class="so-detail-label">Ordered</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->ordered_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
          @if($so->arrived_at)
            <div>
              <div class="so-detail-label">Arrived</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->arrived_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
          @if($so->pulled_at)
            <div>
              <div class="so-detail-label">Pulled</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->pulled_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
          @if($so->cancelled_at)
            <div>
              <div class="so-detail-label">Cancelled</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->cancelled_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
        </div>
      </div>
    </div>

  </div>
</div>

{{-- Mark ordered modal --}}
<div id="so-mark-ordered-modal" class="ia-modal" style="display:none">
  <div class="ia-modal-backdrop" onclick="SoActions.closeOrdered()"></div>
  <div class="ia-modal-panel" style="max-width:500px">
    <form method="POST" action="{{ route('tenant.special-orders.mark-ordered', ['id' => $so->id]) }}">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Mark ordered</h3>
      </div>
      <div class="ia-modal-body">
        <div class="ia-form-group">
          <label class="ia-form-label">Vendor <span class="ia-required">*</span></label>
          <select name="vendor_id" class="ia-select" required>
            <option value="">— select —</option>
            @php $allVendors = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)->where('is_active', true)->orderBy('name')->get(); @endphp
            @foreach($allVendors as $v)
              <option value="{{ $v->id }}" {{ $so->vendor_id === $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">PO # <span class="ia-required">*</span></label>
            <input type="text" name="po_number" class="ia-input" required value="{{ $so->po_number }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Vendor reference</label>
            <input type="text" name="vendor_reference" class="ia-input" value="{{ $so->vendor_reference }}">
          </div>
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Expected arrival <span class="ia-required">*</span></label>
            <input type="date" name="expected_arrival_date" class="ia-input" required value="{{ $so->expected_arrival_date?->format('Y-m-d') }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Est. unit cost (cents)</label>
            <input type="number" name="unit_cost_cents_estimated" class="ia-input" min="0" value="{{ $so->unit_cost_cents_estimated }}">
          </div>
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeOrdered()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Mark ordered</button>
      </div>
    </form>
  </div>
</div>

{{-- Mark arrived modal --}}
<div id="so-mark-arrived-modal" class="ia-modal" style="display:none">
  <div class="ia-modal-backdrop" onclick="SoActions.closeArrived()"></div>
  <div class="ia-modal-panel" style="max-width:500px">
    <form method="POST" action="{{ route('tenant.special-orders.mark-arrived', ['id' => $so->id]) }}">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Mark arrived</h3>
      </div>
      <div class="ia-modal-body">
        <p class="ia-text-muted" style="font-size:12.5px;margin-bottom:14px">
          Full receipt only in Stage 4b. Partial receipts ship in Stage 6 with the receiving integration.
        </p>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Actual unit cost (cents)</label>
            <input type="number" name="unit_cost_cents_actual" class="ia-input" min="0" placeholder="optional">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Invoice date</label>
            <input type="date" name="vendor_invoice_date" class="ia-input">
          </div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Invoice #</label>
          <input type="text" name="vendor_invoice_number" class="ia-input" placeholder="From the vendor's bill">
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeArrived()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Mark arrived</button>
      </div>
    </form>
  </div>
</div>

{{-- Cancel modal --}}
<div id="so-cancel-modal" class="ia-modal" style="display:none">
  <div class="ia-modal-backdrop" onclick="SoActions.closeCancel()"></div>
  <div class="ia-modal-panel" style="max-width:500px">
    <form method="POST" action="{{ route('tenant.special-orders.cancel', ['id' => $so->id]) }}">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Cancel special order</h3>
      </div>
      <div class="ia-modal-body">
        <p class="ia-text-muted" style="font-size:13px;margin-bottom:14px">
          This won't refund any deposit — handle that separately. The SO row stays in history.
        </p>
        <div class="ia-form-group">
          <label class="ia-form-label">Reason (optional)</label>
          <textarea name="reason" class="ia-input" rows="3" placeholder="Customer changed mind, vendor backordered, etc."></textarea>
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeCancel()">Keep order</button>
        <button type="submit" class="ia-btn ia-btn--danger">Cancel order</button>
      </div>
    </form>
  </div>
</div>

@push('scripts')
<script>
window.SoActions = {
  openOrdered: function () { document.getElementById('so-mark-ordered-modal').style.display = 'flex'; },
  closeOrdered: function () { document.getElementById('so-mark-ordered-modal').style.display = 'none'; },
  openArrived: function () { document.getElementById('so-mark-arrived-modal').style.display = 'flex'; },
  closeArrived: function () { document.getElementById('so-mark-arrived-modal').style.display = 'none'; },
  openCancel: function () { document.getElementById('so-cancel-modal').style.display = 'flex'; },
  closeCancel: function () { document.getElementById('so-cancel-modal').style.display = 'none'; },
};
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    SoActions.closeOrdered(); SoActions.closeArrived(); SoActions.closeCancel();
  }
});
</script>
@endpush

@push('styles')
<style>
/* SO-SHOW styles */

.so-state-strip {
  display: flex;
  gap: 4px;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 4px;
  margin-bottom: 20px;
}
.so-state-step {
  flex: 1;
  padding: 8px 10px;
  font-size: 11px;
  text-align: center;
  font-weight: 600;
  color: var(--ia-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-radius: 4px;
}
.so-state-step.done { color: var(--ia-text); }
.so-state-step.current {
  background: var(--ia-accent);
  color: #000;
}
.so-state-step.cancelled.current {
  background: rgba(248,113,113,0.2);
  color: #F87171;
}

.so-show-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 18px;
}
@media (max-width: 900px) { .so-show-grid { grid-template-columns: 1fr; } }
.so-show-col { display: flex; flex-direction: column; }

.so-detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 18px;
}
.so-detail-label {
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--ia-text-muted); font-weight: 600; margin-bottom: 4px;
}
.so-detail-value { font-size: 13px; color: var(--ia-text); }

.so-note {
  padding: 10px 12px;
  margin-bottom: 8px;
  background: var(--ia-bg);
  border-radius: var(--ia-r-md);
  font-size: 12.5px;
}
.so-note.system { opacity: 0.85; }
.so-note-meta { font-size: 10.5px; color: var(--ia-text-muted); margin-bottom: 4px; }
.so-note-body { color: var(--ia-text); line-height: 1.55; }

/* Status pills (re-declared for show page) */
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

.ia-table--inset { border: none; border-top: 0.5px solid var(--ia-border); border-radius: 0; }

/* Modal styles — minimal, matches design language */
.ia-modal {
  position: fixed; inset: 0; z-index: 100;
  align-items: center; justify-content: center;
}
.ia-modal[style*="flex"] { display: flex !important; }
.ia-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0.55);
}
.ia-modal-panel {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  width: 92vw; max-width: 500px;
  max-height: 90vh;
  display: flex; flex-direction: column;
  z-index: 1;
}
.ia-modal-head { padding: 16px 20px; border-bottom: 0.5px solid var(--ia-border); }
.ia-modal-title { margin: 0; font-size: 15px; font-weight: 600; }
.ia-modal-body { padding: 18px 20px; overflow-y: auto; }
.ia-modal-foot {
  padding: 12px 20px; border-top: 0.5px solid var(--ia-border);
  display: flex; gap: 8px; justify-content: flex-end;
}
</style>
@endpush

@endsection
BIZ3_17_EOF

cat > 'resources/views/tenant/special-orders/_vendor_group_row.blade.php' <<'BIZ3_18_EOF'
{{-- MARKER-SO-SCROLL — one special-order row, shared by the needs-a-vendor
     box and each vendor box. Item name gets its own line so long product
     names cannot collide with the vendor picker. --}}
@php
  $opts = $voptions[$so->inventory_item_id] ?? [];
  $og   = $origins[$so->id] ?? null;
@endphp
<div class="sog-row" data-so="{{ $so->id }}">
  @if($selectable)
    <span class="sog-cb on" data-sog-cb></span>
  @endif

  {{-- MARKER-SO-OPENROW — the flat table opened the order on row click and
       the grouped view lost it. The name is the link, so clicks on the
       picker, checkbox, and inline buttons stay put. --}}
  <div class="sog-ident">
    <a class="sog-nm sog-open" href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}">{{ $so->item_name_snapshot }}</a>
    <div class="sog-mt">
      <span>{{ $so->so_number }} · qty {{ $so->quantity }} ·
        {{ $so->customer ? trim($so->customer->fullName()) : 'stock' }}</span>
      @if($og)
        <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
      @endif
      @if($so->created_at)
        <span style="opacity:.6">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>
      @endif
      @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
        <span style="opacity:.6">auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}</span>
      @endif
      @if($og && in_array($og['state'], ['orphan', 'unknown'], true))
        <span class="so-origin-acts" data-so="{{ $so->id }}">
          <button type="button" class="so-oa" data-so-keep>Still needed</button>
          <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
        </span>
      @endif
    </div>
  </div>

  <a class="sog-openall" href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}">Details →</a>

  @if(empty($opts))
    <span class="sog-noopt">No vendor carries this yet — add one on the item</span>
  @else
    <span class="sog-pick">
      <select class="sog-sel" data-sog-select>
        @foreach($opts as $o)
          <option value="{{ $o['vendor_id'] }}" @selected($o['vendor_id'] === $vendorId)>
            {{ $o['name'] }}
            · {{ $o['avail'] === null ? 'stock unknown' : ($o['avail'] > 0 ? $o['avail'] . ' avail' : 'none in stock') }}
            @if($o['cost']) · ${{ number_format($o['cost'] / 100, 2) }} @endif
            @if($o['lead']) · {{ $o['lead'] }}d @endif
            @if($o['preferred']) · preferred @endif
          </option>
        @endforeach
      </select>
      <button type="button" class="sog-assign" data-sog-assign>{{ $vendorId === '' ? 'Assign' : 'Move' }}</button>
    </span>
  @endif
</div>
BIZ3_18_EOF

cat > 'resources/views/tenant/inbox/index.blade.php' <<'BIZ3_19_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Inbox'; @endphp

{{-- MARKER-PATCH-221 — unified inbox: two-pane SMS conversations. --}}

@push('styles')
<style>
  .ib-wrap { display:grid; grid-template-columns:340px 1fr; gap:0; border-radius:12px; overflow:hidden;
             box-shadow:inset 0 0 0 .5px var(--ia-border); background:var(--ia-surface); min-height:560px; }
  @media (max-width: 980px) { .ib-wrap { grid-template-columns:1fr; } .ib-conv { display:none; } .ib-conv.has-sel { display:flex; } }
  .ib-list { border-right:.5px solid var(--ia-border); display:flex; flex-direction:column; }
  .ib-filters { display:flex; gap:6px; padding:12px; border-bottom:.5px solid var(--ia-border); }
  .ib-pill { font-size:11.5px; padding:4px 10px; border-radius:999px; box-shadow:inset 0 0 0 .5px var(--ia-border);
             text-decoration:none; color:inherit; opacity:.7; }
  .ib-pill.is-active { background:var(--ia-text); color:var(--ia-bg, #fff); opacity:1; }
  .ib-thread { display:block; padding:12px 14px; border-bottom:.5px solid var(--ia-border); text-decoration:none; color:inherit; }
  .ib-thread:hover, .ib-thread.is-sel { background:rgba(127,127,127,.06); }
  .ib-thread-top { display:flex; justify-content:space-between; gap:8px; align-items:baseline; }
  .ib-thread-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ib-thread-time { font-size:10.5px; opacity:.45; white-space:nowrap; }
  .ib-snippet { font-size:12px; opacity:.55; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ib-dot { width:8px; height:8px; border-radius:50%; background:#B8801A; display:inline-block; margin-right:6px; }
  .ib-conv { display:flex; flex-direction:column; min-width:0; }
  .ib-conv-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:12px 16px; border-bottom:.5px solid var(--ia-border); }
  .ib-msgs { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; }
  .ib-msg { max-width:72%; padding:9px 12px; border-radius:12px; font-size:13px; line-height:1.45; white-space:pre-wrap; word-break:break-word; }
  .ib-msg.in  { align-self:flex-start; background:rgba(127,127,127,.10); border-bottom-left-radius:4px; }
  .ib-msg.out { align-self:flex-end; background:var(--ia-text); color:var(--ia-bg, #fff); border-bottom-right-radius:4px; }
  .ib-msg.note { align-self:stretch; max-width:none; background:#FAEEDA; color:#854F0B; font-size:12.5px; }
  .ib-msg.sys  { align-self:center; max-width:none; background:transparent; box-shadow:inset 0 0 0 .5px var(--ia-border); font-size:11.5px; opacity:.7; }
  .ib-msg-time { font-size:10px; opacity:.45; margin-top:4px; }
  .ib-compose { border-top:.5px solid var(--ia-border); padding:12px 16px; }
  .ib-empty { display:flex; align-items:center; justify-content:center; flex:1; font-size:13px; opacity:.5; padding:40px; text-align:center; }
  /* MARKER-PATCH-433 — mobile: full-screen conversation + back arrow */
  .ib-back { display:none; }
  @media (max-width: 980px) {
    .ib-conv.has-sel { position:fixed; inset:0; z-index:500; background:var(--ia-surface); border-radius:0; }
    .ib-conv.has-sel .ib-conv-head { padding-top:max(12px, env(safe-area-inset-top)); }
    .ib-conv.has-sel .ib-msgs { overscroll-behavior:contain; }
    .ib-conv.has-sel .ib-compose { padding-bottom:max(12px, env(safe-area-inset-bottom)); }
    .ib-conv-head-left { display:flex; align-items:center; gap:10px; min-width:0; }
    .ib-back { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; flex:0 0 auto; margin:-4px 2px -4px -6px; border-radius:8px; text-decoration:none; color:inherit; font-size:21px; line-height:1; opacity:.75; }
    .ib-back:active, .ib-back:hover { background:rgba(127,127,127,.12); opacity:1; }
  }
  /* MARKER-PATCH-434 — mobile inbox styling to match the approved mockup */
  .ib-nr { display:none; }
  .ib-conv-name { font-size:14px; }
  .ib-compose-meta { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
  .ib-compose-row { display:flex; gap:10px; align-items:flex-end; }
  .ib-compose-field { flex:1 1 auto; min-width:0; }
  .ib-compose-send { flex:0 0 auto; }
  .ib-send-ar { display:none; }
  @media (max-width: 980px) {
    .ib-sub-more { display:none; }
    /* thread list — airier rows, mockup sizing */
    .ib-thread { padding:15px 16px; }
    .ib-thread-name { font-size:16px; }
    .ib-thread-time { font-size:12px; }
    .ib-snippet { font-size:14px; margin-top:4px; }
    .ib-dot { width:9px; height:9px; background:var(--ia-accent); margin-right:8px; }
    .ib-nr { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em; color:#B8801A; border:1px solid rgba(184,128,26,.45); border-radius:6px; padding:1px 6px; margin-left:8px; vertical-align:middle; }
    /* conversation — bigger text, green outbound bubbles */
    .ib-conv-name { font-size:17px; }
    .ib-msgs { padding:14px 16px; }
    .ib-msg { max-width:80%; padding:10px 13px; border-radius:15px; font-size:14px; }
    .ib-msg.in  { background:var(--ia-surface-2); border-bottom-left-radius:5px; }
    .ib-msg.out { background:#2a4a2a; color:#eafce0; border-bottom-right-radius:5px; }
    /* composer — pill field + round send */
    .ib-compose-field { border-radius:20px; min-height:44px; padding:11px 16px; }
    .ib-compose-row { gap:8px; }
    .ib-compose-send { width:44px; height:44px; min-width:44px; border-radius:50%; padding:0; display:flex; align-items:center; justify-content:center; }
    .ib-send-txt { display:none; }
    .ib-send-ar { display:inline; font-size:19px; line-height:1; }
  }
  /* MARKER-PATCH-435 — mobile: hide the empty pane, edge-to-edge list, fix row overflow */
  @media (max-width: 980px) {
    .ib-conv { display:none; }            /* empty "pick a conversation" pane stays hidden on phones */
    .ib-conv.has-sel { display:flex; }    /* a selected conversation still shows (full-screen overlay) */
    .ib-wrap { min-width:0; border-radius:0; box-shadow:none; min-height:0; background:transparent; }
    .ib-list { min-width:0; border-right:0; }
    .ib-thread-name { min-width:0; }
  }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inbox</h1>
    <p class="ia-page-subtitle">Every customer text in one place.<span class="ib-sub-more"> Replies, internal notes, and what needs your attention.</span></p>
  </div>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ib-wrap">
  <div class="ib-list">
    <div class="ib-filters">
      <a class="ib-pill {{ $filter === 'all' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index') }}">Open</a>
      <a class="ib-pill {{ $filter === 'unread' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'unread']) }}">Needs reply{{ $needsReplyCount > 0 ? ' (' . $needsReplyCount . ')' : '' }}</a>
      <a class="ib-pill {{ $filter === 'closed' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'closed']) }}">Closed</a>
    </div>
    <div style="overflow-y:auto;flex:1">
      @forelse($threads as $t)
        <a class="ib-thread {{ $selected && $selected->id === $t->id ? 'is-sel' : '' }}"
           href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null, 'thread' => $t->id])) }}">
          <div class="ib-thread-top">
            <span class="ib-thread-name">
              @if((int) $t->unread_count > 0 || $t->status === 'needs_reply')<span class="ib-dot"></span>@endif
              {{ $t->customer?->fullName() }}
              @if($t->status === 'needs_reply')<span class="ib-nr">Needs reply</span>@endif
            </span>
            <span class="ib-thread-time">{{ $t->last_message_at ? tlocal_datetime($t->last_message_at, 'M j, g:i A') : '' }}</span>
          </div>
          <div class="ib-snippet">{{ \Illuminate\Support\Str::limit($t->latestMessage?->body ?? '', 70) }}</div>
        </a>
      @empty
        <div style="padding:30px 16px;font-size:12.5px;opacity:.5;text-align:center">
          No conversations here yet. Inbound texts to your business number land in this list automatically.
        </div>
      @endforelse
    </div>
  </div>

  <div class="ib-conv {{ $selected ? 'has-sel' : '' }}">
    @if(!$selected)
      <div class="ib-empty">Pick a conversation — or text your business number to see one arrive.</div>
    @else
      <div class="ib-conv-head">
        <div class="ib-conv-head-left">
        <a class="ib-back" href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null])) }}" aria-label="Back to conversations">&lsaquo;</a>
        <div style="min-width:0">
          <a href="{{ route('tenant.customers.show', $selected->customer_id) }}" class="ib-conv-name" style="font-weight:700;text-decoration:none;color:inherit">{{ $selected->customer?->fullName() }}</a>
          <div style="font-size:11.5px;opacity:.55">
            {{ $selected->customer?->phone ?? 'no phone' }}
            @if($selected->customer?->email) · {{ $selected->customer?->email }}@endif
            @if($selected->customer?->sms_opt_out_at) · <span style="color:#A32D2D;font-weight:600">opted out (STOP)</span>@endif
          </div>
        </div>
        </div>
        <form method="POST" action="{{ route('tenant.inbox.status', $selected->id) }}">@csrf
          <button type="submit" class="ia-btn" style="font-size:11.5px">{{ $selected->status === 'closed' ? 'Reopen' : 'Close' }}</button>
        </form>
      </div>

      <div class="ib-msgs" id="ib-msgs">
        @forelse($selected->messages as $m)
          @php
            $cls = match (true) {
              $m->kind === 'internal_note' => 'note',
              $m->direction === 'system'   => 'sys',
              $m->direction === 'in'       => 'in',
              default                      => 'out',
            };
          @endphp
          <div class="ib-msg {{ $cls }}">
            {{-- MARKER-PATCH-401 — delete a single message --}}
            <form method="POST" action="{{ route('tenant.inbox.message.delete', $m->id) }}" onsubmit="return confirm('Delete this message? It will be hidden from the conversation.')" style="float:right;margin:-2px -2px 0 8px">
              @csrf
              <button type="submit" title="Delete message" style="background:none;border:0;color:inherit;opacity:.3;cursor:pointer;font-size:14px;line-height:1;padding:0">&times;</button>
            </form>
            @if($cls === 'note')<strong>Internal note · </strong>@endif{{ $m->body }}
            <div class="ib-msg-time">@if($cls === 'in' || $cls === 'out'){{ strtoupper($m->channel) }} · @endif{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div>
          </div>
        @empty
          <div class="ib-empty">No messages yet.</div>
        @endforelse
      </div>

      @php
        // MARKER-PATCH-397 — default the reply channel to the customer's last inbound.
        $lastIn = $selected->messages->where('direction', 'in')->last();
        $replyDefault = in_array($lastIn?->channel ?? '', ['web', 'email'], true) ? 'email' : 'sms';
      @endphp
      <div class="ib-compose">
        <form method="POST" action="{{ route('tenant.inbox.send', $selected->id) }}">
          @csrf
          <div class="ib-compose-meta">
            <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
              Reply via
              <select name="reply_channel" class="ia-input" style="font-size:12px;padding:3px 6px;width:auto">
                <option value="sms"   {{ $replyDefault === 'sms'   ? 'selected' : '' }}>Text (SMS)</option>
                <option value="email" {{ $replyDefault === 'email' ? 'selected' : '' }}>Email</option>
              </select>
            </label>
            <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
              <input type="checkbox" name="as_note" value="1"> Internal note
            </label>
          </div>
          <div class="ib-compose-row">
            <textarea name="body" rows="2" maxlength="1200" required placeholder="Type your reply…" class="ia-input ib-compose-field" style="resize:vertical"></textarea>
            <button type="submit" class="ia-btn ia-btn--primary ib-compose-send"><span class="ib-send-txt">Send</span><span class="ib-send-ar" aria-hidden="true">&uarr;</span></button>
          </div>
        </form>
      </div>
    @endif
  </div>
</div>

<script>
  (function () { var m = document.getElementById('ib-msgs'); if (m) m.scrollTop = m.scrollHeight; })();
</script>

@endsection
BIZ3_19_EOF

cat > 'resources/views/tenant/rentals/desk.blade.php' <<'BIZ3_20_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Desk'; @endphp

{{-- MARKER-PATCH-217 / MARKER-PATCH-218 / MARKER-PATCH-219 / MARKER-PATCH-222
     — the live view from the rental mockup (views.rentDash). --}}

@push('styles')
<style>
  .rd-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:22px; }
  .rd-grid-3 { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }
  @media (max-width: 980px) { .rd-grid-2 { grid-template-columns:1fr; } .rd-grid-3 { grid-template-columns:1fr 1fr; } }
  .rd-flex-between { display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .ia-badge--out      { background:#FAEEDA; color:#854F0B; }
  .ia-badge--overdue  { background:#FCEBEB; color:#A32D2D; }
  .ia-badge--healthy  { background:#EAF3DE; color:#3B6D11; }
  .ia-badge--tight    { background:#FAEEDA; color:#854F0B; }
  .ia-badge--maint    { background:#FCEBEB; color:#A32D2D; }
  .rd-mini { padding:14px; border-radius:10px; box-shadow:inset 0 0 0 .5px var(--ia-border); cursor:pointer; text-decoration:none; color:inherit; display:block; }
  .rd-mini:hover { background:var(--ia-surface-2, rgba(127,127,127,.06)); }
  .rd-mini-count { font-size:11.5px; opacity:.6; margin-top:8px; }
  .rd-mini-note  { font-size:11.5px; opacity:.45; }
  .rd-stat-link { text-decoration:none; color:inherit; display:block; }
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'desk'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Rental Desk</h1>
    <p class="ia-page-subtitle">Live view of your rental fleet — what's out, what's due, what's free.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary">New rental</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ia-stats-grid" style="margin-bottom:22px">
  <a class="ia-stat rd-stat-link" href="{{ route('tenant.rentals.bookings.index', ['tab' => 'out']) }}">
    <div class="ia-stat-label">Out right now</div>
    <div class="ia-stat-value">{{ $outNow }}</div>
    <div class="ia-stat-delta">of {{ $rentableUnits }} rentable units</div>
  </a>
  <div class="ia-stat">
    <div class="ia-stat-label">Due back today</div>
    <div class="ia-stat-value">{{ $dueTodayCount }}</div>
    @if($overdueCount > 0)
      <div class="ia-stat-delta down">{{ $overdueCount }} already overdue</div>
    @else
      <div class="ia-stat-delta">nothing overdue</div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Pickups today</div>
    <div class="ia-stat-value">{{ $pickupsTodayCount }}</div>
    <div class="ia-stat-delta">{{ $nextPickupAt ? 'next at ' . tlocal($nextPickupAt) : 'none scheduled' }}</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Rental revenue (MTD)</div>
    <div class="ia-stat-value">{{ format_money($mtdRevenueCents) }}</div>
    @if($revenueDeltaPct !== null)
      <div class="ia-stat-delta {{ $revenueDeltaPct >= 0 ? 'up' : 'down' }}">{{ $revenueDeltaPct >= 0 ? '▲' : '▼' }} {{ abs($revenueDeltaPct) }}% vs {{ $prevMonthLabel }}</div>
    @else
      <div class="ia-stat-delta">from the payment ledger</div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Utilization (7d)</div>
    <div class="ia-stat-value">{{ $utilizationPct }}%</div>
    <div class="ia-stat-delta">fleet-wide avg</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Held deposits</div>
    <div class="ia-stat-value">{{ format_money($heldDepositCents) }}</div>
    <div class="ia-stat-delta">{{ $heldDepositCount }} active hold{{ $heldDepositCount === 1 ? '' : 's' }}</div>
  </div>
</div>

<div class="rd-grid-2">
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div class="rd-flex-between" style="padding:16px 20px 12px;border-bottom:.5px solid var(--ia-border)">
      <span class="ia-card-title">Due back today &amp; overdue</span>
      <a class="ia-card-action" href="{{ route('tenant.rentals.bookings.index', ['tab' => 'out']) }}" style="text-decoration:none">View all bookings →</a>
    </div>
    @if($dueBack->isEmpty())
      <div style="padding:22px 20px;font-size:12.5px;opacity:.55">Nothing due back today — all clear.</div>
    @else
    <table class="ia-table">
      <thead><tr><th>Customer</th><th>Unit</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($dueBack as $r)
          @php
            $late = $r->isOverdue();
            $units = $r->lines->where('kind', 'unit');
            $unitLabel = $units->count() > 1
              ? $units->first()->name_snapshot . ' +' . ($units->count() - 1) . ' more'
              : ($units->first()->name_snapshot ?? '—');
            $lateLabel = '';
            if ($late) {
              $mins = $r->due_at->diffInMinutes(now());
              $lateLabel = $mins >= 60 ? floor($mins / 60) . 'h overdue' : $mins . 'm overdue';
            }
          @endphp
          <tr onclick="window.location='{{ route('tenant.rentals.bookings.show', $r->id) }}'">
            <td>{{ $r->customer?->fullName() }}</td>
            <td>{{ $unitLabel }}</td>
            <td class="ia-num">{{ tlocal($r->due_at) }}</td>
            <td>
              @if($late)
                <span class="ia-badge ia-badge--overdue">{{ $lateLabel }}</span>
              @else
                <span class="ia-badge ia-badge--out">Out</span>
              @endif
            </td>
            <td style="text-align:right">
              {{-- MARKER-PATCH-233 — desk returns open the guided flow. --}}
              <a href="{{ route('tenant.rentals.bookings.return.flow', $r->id) }}" onclick="event.stopPropagation()" class="ia-btn {{ $late ? 'ia-btn--primary' : '' }}" style="font-size:11.5px;padding:4px 10px;text-decoration:none">Start return</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>

  <div class="ia-card" style="padding:0;overflow:hidden">
    <div class="rd-flex-between" style="padding:16px 20px 12px;border-bottom:.5px solid var(--ia-border)">
      <span class="ia-card-title">Upcoming pickups</span>
      <a class="ia-card-action" href="{{ route('tenant.rentals.availability.timeline') }}" style="text-decoration:none">Availability →</a>
    </div>
    @if($pickups->isEmpty())
      <div style="padding:22px 20px;font-size:12.5px;opacity:.55">No reservations starting this week.</div>
    @else
    <table class="ia-table">
      <thead><tr><th>Time</th><th>Customer</th><th>Reserved</th><th></th></tr></thead>
      <tbody>
        @foreach($pickups as $r)
          @php
            $units = $r->lines->where('kind', 'unit');
            $first = $units->first();
            $durLabel = '';
            if ($first) {
              $durLabel = match ($first->rate_mode_snapshot) {
                'hourly'  => $first->duration_units . ' hr' . ($first->duration_units == 1 ? '' : 's'),
                'daily'   => $first->duration_units . ' day' . ($first->duration_units == 1 ? '' : 's'),
                'weekend' => 'weekend',
                default   => '',
              };
            }
            $resLabel = trim(($first->name_snapshot ?? '—')
              . ($units->count() > 1 ? ' +' . ($units->count() - 1) : '')
              . ($durLabel !== '' ? ' · ' . $durLabel : ''));
          @endphp
          <tr onclick="window.location='{{ route('tenant.rentals.bookings.show', $r->id) }}'">
            <td class="ia-num">{{ $r->starts_at->copy()->setTimezone(tenant()->timezone())->isToday() ? tlocal($r->starts_at) : tlocal_datetime($r->starts_at, 'M j, g:i A') }}</td>
            <td>{{ $r->customer?->fullName() }}</td>
            <td>{{ $resLabel }}</td>
            <td style="text-align:right">
              {{-- MARKER-PATCH-232 — desk pickups open the guided flow. --}}
              <a href="{{ route('tenant.rentals.bookings.checkout.flow', $r->id) }}" onclick="event.stopPropagation()" class="ia-btn ia-btn--primary" style="font-size:11.5px;padding:4px 10px;text-decoration:none">Check out</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head">
    <span class="ia-card-title">Fleet snapshot</span>
    <a class="ia-card-action" href="{{ route('tenant.rentals.fleet') }}" style="text-decoration:none">Manage fleet →</a>
  </div>
  @if($fleetSnapshot->isEmpty())
    <div class="ia-empty" style="padding:36px;text-align:center">
      <div class="ia-empty-title">Your fleet starts here</div>
      <div class="ia-empty-body" style="margin-top:6px">Add rental categories and units, then this desk lights up with live availability.</div>
      <p style="margin-top:14px"><a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--primary">Set up your fleet</a></p>
    </div>
  @else
    <div class="rd-grid-3">
      @foreach($fleetSnapshot as $cat)
        <a class="rd-mini" href="{{ route('tenant.rentals.fleet') }}">
          <div class="rd-flex-between">
            <span style="font-size:13px;font-weight:600">{{ $cat['name'] }}</span>
            <span class="ia-badge ia-badge--{{ $cat['badge'] }}">{{ $cat['label'] }}</span>
          </div>
          <div class="rd-mini-count">{{ $cat['total'] }} unit{{ $cat['total'] === 1 ? '' : 's' }}</div>
          <div class="rd-mini-note">
            @if($cat['maint'] > 0)
              {{ $cat['maint'] }} in maintenance · {{ $cat['avail'] }} available
            @else
              {{ $cat['avail'] }} available now
            @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>

@endsection
BIZ3_20_EOF

cat > 'resources/views/tenant/rentals/units/show.blade.php' <<'BIZ3_21_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = ($unit->identifier ?: 'Unit') . ' — ' . ($unit->model?->name ?? 'Fleet'); @endphp

{{-- MARKER-PATCH-235 — unit detail: the serial's whole story. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'fleet'])

@php
  [$uBg, $uColor, $uLabel] = match ($derived) {
      'out'         => ['rgba(91,163,208,.13)', '#5BA3D0', 'out'],
      'reserved'    => ['rgba(224,168,46,.13)', '#E0A82E', 'reserved'],
      'maintenance' => ['rgba(224,87,62,.13)', '#E0573E', 'maintenance'],
      'retired'     => ['rgba(255,255,255,.06)', 'rgba(255,255,255,.45)', 'retired'],
      default       => ['rgba(123,201,111,.13)', '#7BC96F', 'available'],
  };
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title" style="display:flex;align-items:center;gap:10px">
      {{ $unit->identifier ?: 'Unit' }} — {{ $unit->model?->name }}{{ $unit->size ? ' (' . $unit->size . ')' : '' }}
      <span style="font-size:10.5px;font-weight:600;border-radius:999px;padding:2.5px 10px;display:inline-flex;align-items:center;gap:6px;background:{{ $uBg }};color:{{ $uColor }}"><span style="width:5px;height:5px;border-radius:50%;background:currentColor"></span>{{ $uLabel }}</span>
    </h1>
    <p class="ia-page-subtitle">{{ $unit->category?->name }}{{ $unit->name ? ' · ' . $unit->name : '' }} · in fleet since {{ tlocal_date($unit->created_at) }}{{ $unit->conditionTemplate ? ' · ' . $unit->conditionTemplate->name . ' checklist' : '' }}</p>
  </div>
  <a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn">Back to fleet</a>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:22px">
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Utilization 30d</div>
    <div style="font-size:23px;font-weight:500;line-height:1">{{ $utilizationPct }}%</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:6px">rented time ÷ window</div>
  </div>
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Revenue lifetime</div>
    <div style="font-size:23px;font-weight:500;line-height:1">{{ format_money($lifetimeCents) }}</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:6px">{{ $rentals->count() }}{{ $rentals->count() === 25 ? '+' : '' }} rentals</div>
  </div>
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Flagged returns</div>
    <div style="font-size:23px;font-weight:500;line-height:1;{{ $flaggedReturns > 0 ? 'color:#E0A82E' : '' }}">{{ $flaggedReturns }}</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:6px">in-checks with flags</div>
  </div>
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Rates</div>
    <div style="font-size:14px;font-weight:600;line-height:1.5">{{ $unit->effectiveDailyCents() ? format_money($unit->effectiveDailyCents()) . '/day' : '—' }}</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:2px">deposit {{ format_money($unit->effectiveDepositCents()) }}</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start" class="unit-cols">
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Rental history</span></div>
    @if($rentals->isEmpty())
      <div style="padding:24px 16px;font-size:12.5px;opacity:.55">Never been out. Its day will come.</div>
    @else
      <div style="display:grid;grid-template-columns:100px 1.2fr 1.2fr 110px 80px;gap:10px;padding:9px 16px;border-bottom:.5px solid var(--ia-border);font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">
        <span>Rental</span><span>Customer</span><span>Window</span><span>Status</span><span style="text-align:right">Revenue</span>
      </div>
      @foreach($rentals as $r)
        @php
          $inCheck = $r->conditionChecks->firstWhere('phase', 'check_in');
          $rev = (int) $r->lines->sum('line_total_cents');
        @endphp
        <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}" style="display:grid;grid-template-columns:100px 1.2fr 1.2fr 110px 80px;gap:10px;align-items:center;padding:10px 16px;border-bottom:.5px solid var(--ia-border);text-decoration:none;color:inherit">
          <span style="font-size:12px;opacity:.6;font-family:var(--ia-font-mono,monospace)">{{ $r->rental_number }}</span>
          <span style="font-size:12.5px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->customer?->fullName() }}</span>
          <span style="font-size:11.5px;opacity:.6">{{ tlocal_date($r->starts_at, 'M j') }} – {{ tlocal_date($r->returned_at ?? $r->due_at, 'M j') }}</span>
          <span style="display:flex;align-items:center;gap:5px">
            @include('tenant.rentals._status-pill', ['rental' => $r])
            @if($inCheck?->flagged)<span title="flagged at return" style="color:#E0A82E;font-size:11px">⚑</span>@endif
          </span>
          <span style="font-size:12.5px;text-align:right">{{ format_money($rev) }}</span>
        </a>
      @endforeach
    @endif
  </div>

  <div>
    {{-- MARKER-PATCH-236 — per-instance fields edit here now (roster rows
         are read-first). Saves field-by-field via the fleet updateUnit
         endpoint. --}}
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Edit unit</span>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px" id="unit-edit" data-unit="{{ $unit->id }}">
        <div><div style="font-size:11px;opacity:.5;margin-bottom:4px">Serial / tag</div><input class="ia-input" style="width:100%;font-family:var(--ia-font-mono,monospace)" value="{{ $unit->identifier }}" data-uf="identifier" placeholder="#tag"></div>
        <div><div style="font-size:11px;opacity:.5;margin-bottom:4px">Size</div><input class="ia-input" style="width:100%" value="{{ $unit->size }}" data-uf="size" placeholder="size"></div>
        <div><div style="font-size:11px;opacity:.5;margin-bottom:4px">Status</div>
          <select class="ia-input" style="width:100%" data-uf="status">
            @foreach(['available'=>'Available','maintenance'=>'Maintenance','retired'=>'Retired'] as $sk=>$sv)
              <option value="{{ $sk }}" {{ $unit->status === $sk ? 'selected':'' }}>{{ $sv }}</option>
            @endforeach
          </select>
        </div>
        <div><div style="font-size:11px;opacity:.5;margin-bottom:4px">Booking</div>
          <select class="ia-input" style="width:100%" data-uf="available_for_rent">
            <option value="1" {{ $unit->available_for_rent ? 'selected':'' }}>Rentable</option>
            <option value="0" {{ $unit->available_for_rent ? '':'selected' }}>Off — hidden from booking</option>
          </select>
        </div>
      </div>
      <div style="font-size:11px;opacity:.45;margin-top:8px" id="unit-edit-status">Changes save as you go.</div>
    </div>

    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Notes &amp; maintenance log</span>
      @if($unit->notes)
        <p style="font-size:12.5px;margin-top:10px;white-space:pre-wrap;line-height:1.6">{{ $unit->notes }}</p>
      @else
        <p style="font-size:12.5px;opacity:.5;margin-top:10px">Nothing logged. Return-flow maintenance routing writes dated lines here automatically.</p>
      @endif
      <p style="font-size:11px;opacity:.45;margin-top:10px">Maintenance routing notes from the return flow land here automatically; clear the status in the Edit card above when work is done.</p>
    </div>

    @if($photoChecks->isNotEmpty())
    <div class="ia-card" style="padding:16px">
      <span class="ia-label">Recent check photos</span>
      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;font-size:12.5px">
        @foreach($photoChecks as $check)
          <div style="display:flex;justify-content:space-between;gap:10px">
            <span style="opacity:.75">{{ $check->phase === 'check_out' ? 'Out-check' : 'In-check' }} · {{ tlocal_date($check->performed_at) }}{{ $check->flagged ? ' ⚑' : '' }}</span>
            <span>
              @foreach($check->photos as $pi => $p)
                <a href="{{ Storage::disk('public')->url($p) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">{{ $pi + 1 }}</a>{{ !$loop->last ? ' ' : '' }}
              @endforeach
            </span>
          </div>
        @endforeach
      </div>
    </div>
    @endif
  </div>
</div>

<style>@media(max-width:980px){.unit-cols{grid-template-columns:1fr !important}}</style>

{{-- MARKER-PATCH-236 — field-by-field save to the fleet updateUnit endpoint. --}}
<script>
(function () {
  var wrap = document.getElementById('unit-edit');
  if (!wrap) return;
  var url = '{{ url('admin/rentals/fleet/units') }}/' + wrap.getAttribute('data-unit');
  var csrf = '{{ csrf_token() }}';
  var statusEl = document.getElementById('unit-edit-status');
  wrap.querySelectorAll('[data-uf]').forEach(function (el) {
    el.addEventListener('change', function () {
      statusEl.textContent = 'Saving…';
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-HTTP-Method-Override': 'PATCH' },
        body: JSON.stringify({ field: el.getAttribute('data-uf'), value: el.value })
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.success === false) { statusEl.textContent = j.message || 'Could not save.'; statusEl.style.color = '#ef4444'; }
        else { statusEl.textContent = 'Saved.'; statusEl.style.color = ''; setTimeout(function () { statusEl.textContent = 'Changes save as you go.'; }, 1800); }
      }).catch(function () { statusEl.textContent = 'Could not save.'; statusEl.style.color = '#ef4444'; });
    });
  });
})();
</script>

@endsection
BIZ3_21_EOF

cat > 'resources/views/tenant/rentals/agreement-pdf.blade.php' <<'BIZ3_22_EOF'
<!DOCTYPE html>
{{-- MARKER-PATCH-232 — rendered + stored at signature; never re-rendered. --}}
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; line-height: 1.55; }
  h1 { font-size: 16px; margin: 0 0 2px; }
  .sub { color: #777; font-size: 10px; margin-bottom: 18px; }
  .body { white-space: pre-wrap; margin-bottom: 26px; }
  .sig { border-top: 1px solid #999; padding-top: 10px; width: 60%; }
  .sig b { font-size: 13px; }
  .meta { color: #777; font-size: 9.5px; margin-top: 4px; }
</style>
</head>
<body>
  <h1>{{ $template->title }}</h1>
  <div class="sub">{{ $tenant->name }} · Rental {{ $rental->rental_number }} · {{ tlocal_datetime($rental->starts_at, 'M j, Y g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, Y g:i A') }}</div>
  <div class="body">{{ $template->body }}</div>
  <div class="sig">
    <b>{{ $signerName }}</b>
    <div class="meta">Signed at the counter · {{ tlocal_datetime($signedAt, 'M j, Y g:i A') }} · Agreement v{{ $template->version }} · Customer: {{ $rental->customer?->fullName() }}</div>
  </div>
</body>
</html>
BIZ3_22_EOF

cat > 'resources/views/tenant/rentals/bookings/check-out.blade.php' <<'BIZ3_23_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Check out ' . $rental->rental_number; @endphp

{{-- MARKER-PATCH-232 — guided check-out: Verify → Agreement → Condition →
     Deposit & go. Resumable: every write step is its own POST; done steps
     render done after reload. --}}

@push('styles')
<style>
  .co-steps{display:flex;align-items:center;margin-bottom:24px;flex-wrap:wrap}
  .co-step{display:flex;align-items:center;gap:9px;padding:0 4px;cursor:pointer}
  .co-n{width:24px;height:24px;border-radius:50%;border:1.5px solid var(--ia-border-strong);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:650;color:var(--ia-text-dim,rgba(255,255,255,.55));flex-shrink:0}
  .co-step.done .co-n{background:var(--ia-accent,#BEF264);border-color:var(--ia-accent,#BEF264);color:#0a0a0a}
  .co-step.cur .co-n{border-color:var(--ia-accent,#BEF264);color:var(--ia-accent,#BEF264)}
  .co-t{font-size:12.5px;font-weight:550;color:var(--ia-text-dim,rgba(255,255,255,.55))}
  .co-step.cur .co-t,.co-step.done .co-t{color:var(--ia-text,#f0f0f0)}
  .co-bar{width:34px;height:1.5px;background:var(--ia-border);margin:0 6px}
  .co-pane{display:none}
  .co-pane.on{display:block;animation:cofade .15s ease}
  @keyframes cofade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
  .co-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
  @media(max-width:980px){.co-grid{grid-template-columns:1fr}}
  .co-kv{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:5px 0}
  .co-kv span:first-child{opacity:.55}
  .co-chk{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:.5px solid var(--ia-border)}
  .co-chk:last-child{border-bottom:none}
  .co-seg{display:inline-flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);overflow:hidden;flex-shrink:0}
  .co-seg button{padding:5px 12px;font-size:11.5px;background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-weight:600;cursor:pointer}
  .co-seg button.ok{background:rgba(123,201,111,.18);color:#7BC96F}
  .co-seg button.flag{background:rgba(239,68,68,.16);color:#ef4444}
  .co-agree-body{max-height:300px;overflow-y:auto;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);padding:14px 16px;font-size:12.5px;line-height:1.65;white-space:pre-wrap;background:rgba(255,255,255,.02)}
  .co-foot{display:flex;justify-content:space-between;margin-top:16px}
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Check out — {{ $rental->rental_number }}</h1>
    <p class="ia-page-subtitle">{{ $rental->customer?->fullName() }} · {{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn">Back to booking</a>
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

@php
  $agreementDone  = $agreementSigned || !$agreementTemplate;
  $allUnits       = $unitLines->count();
  $checkedUnits   = $unitLines->filter(fn ($l) => $checksByUnit->has($l->unit_id))->count();
  $conditionDone  = $allUnits > 0 && $checkedUnits >= $allUnits;
  $startStep      = !$agreementDone ? 2 : (!$conditionDone ? 3 : 4);
@endphp

<div class="co-steps" id="co-steps">
  <div class="co-step done" data-step="1"><span class="co-n">✓</span><span class="co-t">Verify</span></div>
  <div class="co-bar"></div>
  <div class="co-step {{ $agreementDone ? 'done' : '' }}" data-step="2"><span class="co-n">{{ $agreementDone ? '✓' : '2' }}</span><span class="co-t">Agreement</span></div>
  <div class="co-bar"></div>
  <div class="co-step {{ $conditionDone ? 'done' : '' }}" data-step="3"><span class="co-n">{{ $conditionDone ? '✓' : '3' }}</span><span class="co-t">Condition ({{ $checkedUnits }}/{{ $allUnits }})</span></div>
  <div class="co-bar"></div>
  <div class="co-step" data-step="4"><span class="co-n">4</span><span class="co-t">Deposit &amp; go</span></div>
</div>

<div class="co-grid">
  <div>
    {{-- ---------------------------------------------------- step 1 verify --}}
    <div class="co-pane" data-pane="1">
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        <h2 class="ia-h3" style="margin-bottom:10px">Who &amp; what</h2>
        <div class="co-kv"><span>Customer</span><span style="font-weight:600">{{ $rental->customer?->fullName() }}</span></div>
        <div class="co-kv"><span>Contact</span><span>{{ $rental->customer?->email ?: '—' }}{{ $rental->customer?->phone ? ' · ' . $rental->customer->phone : '' }}</span></div>
        <div class="co-kv"><span>Window</span><span>{{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</span></div>
        <div style="border-top:.5px solid var(--ia-border);margin-top:8px;padding-top:8px">
          @foreach($unitLines as $line)
            <div class="co-kv"><span>{{ $line->name_snapshot }}{{ $line->unit?->identifier ? ' · ' . $line->unit->identifier : '' }}</span><span>{{ $line->duration_units }} × {{ format_money($line->rate_cents_snapshot) }}</span></div>
          @endforeach
        </div>
      </div>
      <div class="co-foot"><span></span><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(2)">Looks right →</button></div>
    </div>

    {{-- ------------------------------------------------- step 2 agreement --}}
    <div class="co-pane" data-pane="2">
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        @if(!$agreementTemplate)
          <h2 class="ia-h3" style="margin-bottom:8px">No agreement configured</h2>
          <p style="font-size:12.5px;opacity:.55;line-height:1.6">You haven't set up a rental agreement yet, so this step is skipped. Add one in Rental Settings and every check-out from then on will require a signature.</p>
        @elseif($agreementSigned)
          <h2 class="ia-h3" style="margin-bottom:8px">Agreement signed</h2>
          <p style="font-size:12.5px;opacity:.55">v{{ $rental->agreement_template_version }} · {{ tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') }}
            @if($rental->agreement_pdf_path) · <a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">PDF →</a>@endif
          </p>
        @else
          <h2 class="ia-h3" style="margin-bottom:10px">{{ $agreementTemplate->title }} <span style="font-size:11px;opacity:.5;font-weight:400">v{{ $agreementTemplate->version }}</span></h2>
          <div class="co-agree-body">{{ $agreementTemplate->body }}</div>
          <form method="POST" action="{{ route('tenant.rentals.bookings.agreement.sign', $rental->id) }}" style="margin-top:14px">
            @csrf
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
              <div style="flex:1;min-width:220px">
                <label class="ia-label" style="display:block;margin-bottom:5px">Customer signs by typing their full name</label>
                <input type="text" name="signer_name" maxlength="160" required class="ia-input" style="width:100%" placeholder="{{ $rental->customer?->fullName() }}">
              </div>
              <button type="submit" class="ia-btn ia-btn--primary">Sign agreement</button>
            </div>
            <label style="display:flex;gap:9px;align-items:center;font-size:12.5px;margin-top:10px;cursor:pointer">
              <input type="checkbox" name="agreed" value="1" required> Customer has read and agrees to the terms above
            </label>
          </form>
        @endif
      </div>
      <div class="co-foot"><button type="button" class="ia-btn" onclick="coGo(1)">← Back</button><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(3)" {{ $agreementDone ? '' : 'disabled' }}>Continue →</button></div>
    </div>

    {{-- ------------------------------------------------- step 3 condition --}}
    <div class="co-pane" data-pane="3">
      @foreach($unitLines as $line)
        @php
          $unit  = $line->unit;
          $check = $unit ? $checksByUnit->get($unit->id) : null;
          $tpl   = $unit?->conditionTemplate;
          $items = $tpl ? (array) $tpl->items : [];
        @endphp
        <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:.5px solid var(--ia-border)">
            <div>
              <span style="font-size:12px;font-weight:550;text-transform:uppercase;letter-spacing:.06em">{{ $line->name_snapshot }}{{ $unit?->identifier ? ' · ' . $unit->identifier : '' }}</span>
              <div style="font-size:11px;opacity:.5;margin-top:2px">{{ $tpl ? $tpl->name . ' template' : 'No template — quick visual' }}</div>
            </div>
            @if($check)<span style="font-size:11px;font-weight:600;color:{{ $check->flagged ? '#E0A82E' : '#7BC96F' }}">{{ $check->flagged ? 'noted with flags' : 'recorded' }}</span>@endif
          </div>
          @if($check)
            <div style="padding:14px 18px;font-size:12.5px;opacity:.7">Out-check recorded {{ tlocal_datetime($check->performed_at, 'M j, g:i A') }}{{ $check->notes ? ' — ' . $check->notes : '' }}{{ is_array($check->photos) && count($check->photos) ? ' · ' . count($check->photos) . ' photo(s)' : '' }}</div>
          @else
            <form method="POST" action="{{ route('tenant.rentals.bookings.condition.store', $rental->id) }}" enctype="multipart/form-data" class="co-cond-form">
              @csrf
              <input type="hidden" name="unit_id" value="{{ $unit?->id }}">
              <input type="hidden" name="phase" value="check_out">
              @if(count($items))
                @foreach($items as $item)
                  @php $k = $item['key'] ?? ('item_' . $loop->index); @endphp
                  <div class="co-chk">
                    <span style="font-size:13px;flex:1">{{ $item['label'] ?? $k }}</span>
                    <input type="hidden" name="results[{{ $k }}]" value="ok">
                    <div class="co-seg" data-key="{{ $k }}">
                      <button type="button" class="ok">OK</button>
                      <button type="button">Flag</button>
                    </div>
                  </div>
                @endforeach
              @else
                <div class="co-chk">
                  <span style="font-size:13px;flex:1">Visual check — unit is complete and ready to go out</span>
                  <input type="hidden" name="results[visual]" value="ok">
                  <div class="co-seg" data-key="visual"><button type="button" class="ok">OK</button><button type="button">Flag</button></div>
                </div>
              @endif
              <div style="display:flex;gap:10px;align-items:end;padding:12px 14px;border-top:.5px solid var(--ia-border);flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                  <label class="ia-label" style="display:block;margin-bottom:4px">Notes — existing condition, scuffs, etc.</label>
                  <input type="text" name="notes" maxlength="2000" class="ia-input" style="width:100%">
                </div>
                <div>
                  <label class="ia-label" style="display:block;margin-bottom:4px">Photos (≤4)</label>
                  <input type="file" name="photos[]" accept="image/*" multiple class="ia-input" style="padding:6px">
                </div>
                <button type="submit" class="ia-btn ia-btn--primary">Save out-check</button>
              </div>
            </form>
          @endif
        </div>
      @endforeach
      <div class="co-foot"><button type="button" class="ia-btn" onclick="coGo(2)">← Back</button><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(4)">Continue →</button></div>
    </div>

    {{-- --------------------------------------------- step 4 deposit & go --}}
    <div class="co-pane" data-pane="4">
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        <h2 class="ia-h3" style="margin-bottom:8px">Deposit hold</h2>
        @if($rental->deposit_status === 'authorized')
          <p style="font-size:13px"><b>{{ format_money($rental->deposit_hold_cents) }}</b> on hold — you're set.</p>
        @elseif(tenant()->direct_payments_enabled)
          <div id="dep-start">
            <div style="display:flex;gap:6px;max-width:380px">
              <input type="number" id="dep-amount" min="0.50" step="0.01" value="{{ number_format(max(0, $rental->lines->where('kind','unit')->sum(fn ($l) => (int) ($l->unit?->effectiveDepositCents() ?? 0))) / 100, 2, '.', '') }}" class="ia-input" style="flex:1;text-align:right">
              <button type="button" class="ia-btn ia-btn--primary" id="dep-authorize">Authorize hold</button>
            </div>
            <p style="font-size:11px;opacity:.45;margin-top:6px">Authorizes the card without charging it. Skippable — cash shops can just continue.</p>
          </div>
          <div id="dep-element-wrap" style="display:none;margin-top:10px;max-width:480px">
            <div id="dep-element"></div>
            <button type="button" class="ia-btn ia-btn--primary" id="dep-confirm" style="width:100%;margin-top:8px">Place hold</button>
            <div id="dep-error" style="font-size:12px;color:#ef4444;margin-top:6px"></div>
          </div>
        @else
          <p style="font-size:12.5px;opacity:.55">Card payments aren't enabled — deposits can be taken in cash through the register, or skipped.</p>
        @endif
      </div>

      @if($balanceCents > 0)
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        <h2 class="ia-h3" style="margin-bottom:8px">Balance due — {{ format_money($balanceCents) }}</h2>
        <form method="POST" action="{{ route('tenant.rentals.bookings.collect', $rental->id) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
          @csrf
          {{-- MARKER-PATCH-232B — come back to this flow after payment. --}}
          <input type="hidden" name="return_to" value="{{ parse_url(route('tenant.rentals.bookings.checkout.flow', $rental->id), PHP_URL_PATH) }}">
          <div>
            <label class="ia-label" style="display:block;margin-bottom:4px">Amount $</label>
            <input type="number" name="amount" min="0.01" step="0.01" required value="{{ number_format($balanceCents / 100, 2, '.', '') }}" class="ia-input" style="width:140px;text-align:right">
          </div>
          <button type="submit" class="ia-btn">Collect in register</button>
          <span style="font-size:11px;opacity:.45;align-self:center">Opens the register with a linked sale — cash, card, or payment link.</span>
        </form>
      </div>
      @endif

      <form method="POST" action="{{ route('tenant.rentals.bookings.checkout.complete', $rental->id) }}">
        @csrf
        <div class="co-foot">
          <button type="button" class="ia-btn" onclick="coGo(3)">← Back</button>
          <button type="submit" class="ia-btn ia-btn--primary" style="font-size:14px;padding:10px 22px">Complete check-out ✓</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ------------------------------------------------------- money rail --}}
  <div>
    <div class="ia-card" style="padding:16px 18px;margin-bottom:14px">
      <span class="ia-label">Money</span>
      <div class="co-kv" style="margin-top:6px"><span>Subtotal</span><span>{{ format_money($rental->subtotal_cents) }}</span></div>
      <div class="co-kv"><span>Tax</span><span>{{ format_money($rental->tax_cents) }}</span></div>
      <div class="co-kv" style="font-weight:650;border-top:.5px solid var(--ia-border);padding-top:8px"><span style="opacity:1">Total</span><span>{{ format_money($rental->total_cents) }}</span></div>
      <div class="co-kv"><span>Paid</span><span>{{ format_money($rental->paid_cents) }}</span></div>
      <div class="co-kv" style="font-weight:650;{{ $balanceCents > 0 ? 'color:#E0A82E' : 'color:#7BC96F' }}"><span style="opacity:1;color:inherit">Balance</span><span>{{ format_money($balanceCents) }}</span></div>
    </div>
    <div class="ia-card" style="padding:16px 18px">
      <span class="ia-label">This flow</span>
      <p style="font-size:11.5px;opacity:.55;margin-top:8px;line-height:1.6">Each step saves on its own — close this page and pick up where you left off. The agreement and condition checks stay on the rental record and come back at return time.</p>
    </div>
  </div>
</div>

<script>
function coGo(n) {
  document.querySelectorAll('.co-pane').forEach(function (p) { p.classList.toggle('on', p.dataset.pane == n); });
  document.querySelectorAll('.co-step').forEach(function (s) {
    s.classList.toggle('cur', s.dataset.step == n);
  });
  window.scrollTo({ top: 0 });
}
document.querySelectorAll('.co-step').forEach(function (s) {
  s.addEventListener('click', function () { coGo(s.dataset.step); });
});
coGo({{ $startStep }});

// OK/Flag segmented toggles write into the hidden results[] input.
document.querySelectorAll('.co-seg').forEach(function (seg) {
  var btns = seg.querySelectorAll('button');
  var input = seg.parentElement.querySelector('input[type=hidden][name^="results"]');
  btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'ok'; });
  btns[1].addEventListener('click', function () { btns[1].className = 'flag'; btns[0].className = ''; if (input) input.value = 'flag'; });
});
</script>

@if($rental->deposit_status === 'none' && tenant()->direct_payments_enabled)
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var btn = document.getElementById('dep-authorize');
  if (!btn) return;
  var intentUrl  = '{{ route('tenant.rentals.bookings.deposit.intent', $rental->id) }}';
  var confirmUrl = '{{ route('tenant.rentals.bookings.deposit.confirm', $rental->id) }}';
  var csrf = '{{ csrf_token() }}';
  var stripe = null, elements = null, piId = null;

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    var dollars = parseFloat(document.getElementById('dep-amount').value || '0');
    post(intentUrl, { amount_cents: Math.round(dollars * 100) }).then(function (res) {
      if (!res.ok || !res.json.ok) {
        alert(res.json.error || 'Could not start the hold.');
        btn.disabled = false;
        return;
      }
      piId = res.json.payment_intent;
      stripe = Stripe(res.json.publishable_key);
      elements = stripe.elements({ clientSecret: res.json.client_secret });
      elements.create('payment').mount('#dep-element');
      document.getElementById('dep-element-wrap').style.display = 'block';
    }).catch(function () { alert('Could not start the hold.'); btn.disabled = false; });
  });

  document.getElementById('dep-confirm').addEventListener('click', function () {
    var confirmBtn = this;
    confirmBtn.disabled = true;
    document.getElementById('dep-error').textContent = '';
    stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
      if (result.error) {
        document.getElementById('dep-error').textContent = result.error.message || 'Card was not authorized.';
        confirmBtn.disabled = false;
        return;
      }
      post(confirmUrl, { payment_intent: piId }).then(function (res) {
        if (res.ok && res.json.ok) { window.location.reload(); }
        else {
          document.getElementById('dep-error').textContent = (res.json && res.json.error) || 'Could not verify the hold.';
          confirmBtn.disabled = false;
        }
      });
    });
  });
})();
</script>
@endif

@endsection
BIZ3_23_EOF

cat > 'resources/views/tenant/rentals/bookings/return.blade.php' <<'BIZ3_24_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Return ' . $rental->rental_number; @endphp

{{-- MARKER-PATCH-233 — guided return: Inspect → Charges → Close. In-checks
     render beside the 232 out-checks; charges collect through the register
     (232B round-trip); deposit + routing decisions close it out. --}}

@push('styles')
<style>
  .rt-steps{display:flex;align-items:center;margin-bottom:24px;flex-wrap:wrap}
  .rt-step{display:flex;align-items:center;gap:9px;padding:0 4px;cursor:pointer}
  .rt-n{width:24px;height:24px;border-radius:50%;border:1.5px solid var(--ia-border-strong);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:650;color:var(--ia-text-dim,rgba(255,255,255,.55));flex-shrink:0}
  .rt-step.done .rt-n{background:var(--ia-accent,#BEF264);border-color:var(--ia-accent,#BEF264);color:#0a0a0a}
  .rt-step.cur .rt-n{border-color:var(--ia-accent,#BEF264);color:var(--ia-accent,#BEF264)}
  .rt-t{font-size:12.5px;font-weight:550;color:var(--ia-text-dim,rgba(255,255,255,.55))}
  .rt-step.cur .rt-t,.rt-step.done .rt-t{color:var(--ia-text,#f0f0f0)}
  .rt-bar{width:34px;height:1.5px;background:var(--ia-border);margin:0 6px}
  .rt-pane{display:none}
  .rt-pane.on{display:block;animation:rtfade .15s ease}
  @keyframes rtfade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
  .rt-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
  @media(max-width:980px){.rt-grid{grid-template-columns:1fr}}
  .rt-kv{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:5px 0}
  .rt-kv span:first-child{opacity:.55}
  .rt-chk{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:.5px solid var(--ia-border)}
  .rt-chk:last-child{border-bottom:none}
  .rt-seg{display:inline-flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);overflow:hidden;flex-shrink:0}
  .rt-seg button{padding:5px 12px;font-size:11.5px;background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-weight:600;cursor:pointer}
  .rt-seg button.ok{background:rgba(123,201,111,.18);color:#7BC96F}
  .rt-seg button.flag{background:rgba(239,68,68,.16);color:#ef4444}
  .rt-seg button.mt{background:rgba(224,87,62,.16);color:#E0573E}
  .rt-out-note{font-size:11.5px;opacity:.55;padding:9px 14px;background:rgba(255,255,255,.025);border-bottom:.5px solid var(--ia-border)}
  .rt-foot{display:flex;justify-content:space-between;margin-top:16px}
  .rt-dmg-row{display:flex;gap:8px;margin-bottom:8px}
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

@php $late = $rental->isOverdue(); @endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Return — {{ $rental->rental_number }}</h1>
    <p class="ia-page-subtitle">{{ $rental->customer?->fullName() }} · due {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}
      @if($lateMinutes > 0)<span style="color:#ef4444;font-weight:600"> — {{ $lateMinutes >= 60 ? floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm' : $lateMinutes . 'm' }} overdue</span>@endif
    </p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn">Back to booking</a>
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

@php
  $allUnits     = $unitLines->count();
  $checkedUnits = $unitLines->filter(fn ($l) => $inChecks->has($l->unit_id))->count();
  $inspectDone  = $allUnits > 0 && $checkedUnits >= $allUnits;
  $startStep    = $inspectDone ? 2 : 1;
@endphp

<div class="rt-steps" id="rt-steps">
  <div class="rt-step {{ $inspectDone ? 'done' : '' }}" data-step="1"><span class="rt-n">{{ $inspectDone ? '✓' : '1' }}</span><span class="rt-t">Inspect ({{ $checkedUnits }}/{{ $allUnits }})</span></div>
  <div class="rt-bar"></div>
  <div class="rt-step" data-step="2"><span class="rt-n">2</span><span class="rt-t">Charges</span></div>
  <div class="rt-bar"></div>
  <div class="rt-step" data-step="3"><span class="rt-n">3</span><span class="rt-t">Deposit &amp; close</span></div>
</div>

<div class="rt-grid">
  <div>
    {{-- ---------------------------------------------------- step 1 inspect --}}
    <div class="rt-pane" data-pane="1">
      @foreach($unitLines as $line)
        @php
          $unit     = $line->unit;
          $outCheck = $unit ? $outChecks->get($unit->id) : null;
          $inCheck  = $unit ? $inChecks->get($unit->id) : null;
          $tpl      = $unit?->conditionTemplate;
          $items    = $tpl ? (array) $tpl->items : [];
        @endphp
        <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:.5px solid var(--ia-border)">
            <div>
              <span style="font-size:12px;font-weight:550;text-transform:uppercase;letter-spacing:.06em">{{ $line->name_snapshot }}{{ $unit?->identifier ? ' · ' . $unit->identifier : '' }}</span>
              <div style="font-size:11px;opacity:.5;margin-top:2px">{{ $tpl ? $tpl->name . ' template' : 'No template — quick visual' }}</div>
            </div>
            @if($inCheck)<span style="font-size:11px;font-weight:600;color:{{ $inCheck->flagged ? '#ef4444' : '#7BC96F' }}">{{ $inCheck->flagged ? 'flagged' : 'clear' }}</span>@endif
          </div>

          @if($outCheck)
            <div class="rt-out-note">
              Out-check {{ tlocal_datetime($outCheck->performed_at, 'M j, g:i A') }}{{ $outCheck->notes ? ' — "' . $outCheck->notes . '"' : '' }}
              @if(is_array($outCheck->photos) && count($outCheck->photos))
                ·
                @foreach($outCheck->photos as $i => $p)
                  <a href="{{ Storage::disk('public')->url($p) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">photo {{ $i + 1 }}</a>{{ !$loop->last ? ', ' : '' }}
                @endforeach
              @endif
              @php $outFlags = collect((array) $outCheck->results)->filter(fn ($v) => $v === 'flag')->keys(); @endphp
              @if($outFlags->count()) · <span style="color:#E0A82E">flagged going out: {{ $outFlags->implode(', ') }}</span>@endif
            </div>
          @else
            <div class="rt-out-note">No out-check on file — this rental went out before condition checks (or via quick check-out).</div>
          @endif

          @if($inCheck)
            <div style="padding:14px 18px;font-size:12.5px;opacity:.7">In-check recorded {{ tlocal_datetime($inCheck->performed_at, 'M j, g:i A') }}{{ $inCheck->notes ? ' — ' . $inCheck->notes : '' }}{{ is_array($inCheck->photos) && count($inCheck->photos) ? ' · ' . count($inCheck->photos) . ' photo(s)' : '' }}</div>
          @else
            <form method="POST" action="{{ route('tenant.rentals.bookings.condition.store', $rental->id) }}" enctype="multipart/form-data">
              @csrf
              <input type="hidden" name="unit_id" value="{{ $unit?->id }}">
              <input type="hidden" name="phase" value="check_in">
              @if(count($items))
                @foreach($items as $item)
                  @php $k = $item['key'] ?? ('item_' . $loop->index); @endphp
                  <div class="rt-chk">
                    <span style="font-size:13px;flex:1">{{ $item['label'] ?? $k }}</span>
                    <input type="hidden" name="results[{{ $k }}]" value="ok">
                    <div class="rt-seg" data-key="{{ $k }}">
                      <button type="button" class="ok">OK</button>
                      <button type="button">Flag</button>
                    </div>
                  </div>
                @endforeach
              @else
                <div class="rt-chk">
                  <span style="font-size:13px;flex:1">Visual check — returned complete, no new damage</span>
                  <input type="hidden" name="results[visual]" value="ok">
                  <div class="rt-seg" data-key="visual"><button type="button" class="ok">OK</button><button type="button">Flag</button></div>
                </div>
              @endif
              <div style="display:flex;gap:10px;align-items:end;padding:12px 14px;border-top:.5px solid var(--ia-border);flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                  <label class="ia-label" style="display:block;margin-bottom:4px">Notes — new damage, missing parts, etc.</label>
                  <input type="text" name="notes" maxlength="2000" class="ia-input" style="width:100%">
                </div>
                <div>
                  <label class="ia-label" style="display:block;margin-bottom:4px">Photos (≤4)</label>
                  <input type="file" name="photos[]" accept="image/*" multiple class="ia-input" style="padding:6px">
                </div>
                <button type="submit" class="ia-btn ia-btn--primary">Save in-check</button>
              </div>
            </form>
          @endif
        </div>
      @endforeach
      <div class="rt-foot"><span></span><button type="button" class="ia-btn ia-btn--primary" onclick="rtGo(2)">Continue to charges →</button></div>
    </div>

    {{-- ---------------------------------------------------- step 2 charges --}}
    <div class="rt-pane" data-pane="2">
      <form method="POST" action="{{ route('tenant.rentals.bookings.return.charges', $rental->id) }}">
        @csrf
        <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
          <h2 class="ia-h3" style="margin-bottom:8px">Late fee</h2>
          @if($lateMinutes > 0)
            <p style="font-size:12.5px;opacity:.55;margin-bottom:10px">{{ $lateMinutes >= 60 ? floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm' : $lateMinutes . 'm' }} past due.
              @if($suggestedLateFeeCents > 0)Policy suggests <b style="opacity:1">{{ format_money($suggestedLateFeeCents) }}</b> — edit or zero it to waive.@elseif($latePolicy['per_hour_cents'] === 0)No late-fee rate is set (Rental Settings).@else Within the {{ $latePolicy['grace_minutes'] }}-minute grace period.@endif
            </p>
          @else
            <p style="font-size:12.5px;opacity:.55;margin-bottom:10px">Returned on time — nothing suggested.</p>
          @endif
          <div style="display:flex;gap:8px;align-items:center">
            <span style="font-size:13px;opacity:.55">$</span>
            <input type="number" name="late_fee" min="0" step="0.01" value="{{ number_format($suggestedLateFeeCents / 100, 2, '.', '') }}" class="ia-input" style="width:130px;text-align:right">
          </div>
        </div>

        <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
          <h2 class="ia-h3" style="margin-bottom:8px">Damage &amp; missing items</h2>
          <p style="font-size:12.5px;opacity:.55;margin-bottom:10px">Anything flagged in step 1 that costs money goes here.</p>
          <div id="rt-dmg-rows">
            <div class="rt-dmg-row">
              <input type="text" name="damage_labels[]" maxlength="200" placeholder="e.g. Rear tire sidewall cut — Maxxis Dissector" class="ia-input" style="flex:1">
              <input type="number" name="damage_amounts[]" min="0" step="0.01" placeholder="0.00" class="ia-input" style="width:110px;text-align:right">
            </div>
          </div>
          <button type="button" class="ia-btn ia-btn--sm" onclick="rtAddDmgRow()">+ Another line</button>
        </div>

        <div class="ia-card" style="padding:14px 18px;margin-bottom:14px;font-size:11.5px;opacity:.6;line-height:1.55">
          Collecting here opens the register with one linked sale (cash, card, or payment link) and brings you back. <b style="opacity:1">Taking charges from the deposit instead?</b> Skip this — capture in step 3 writes its own charge line.
        </div>

        <div class="rt-foot">
          <button type="button" class="ia-btn" onclick="rtGo(1)">← Back</button>
          <div style="display:flex;gap:8px">
            <button type="button" class="ia-btn" onclick="rtGo(3)">No charges — skip →</button>
            <button type="submit" class="ia-btn ia-btn--primary">Add charges &amp; collect in register →</button>
          </div>
        </div>
      </form>
    </div>

    {{-- ---------------------------------------------- step 3 deposit & close --}}
    <div class="rt-pane" data-pane="3">
      <form method="POST" action="{{ route('tenant.rentals.bookings.return.complete', $rental->id) }}">
        @csrf

        <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
          <h2 class="ia-h3" style="margin-bottom:8px">Deposit</h2>
          @if($rental->deposit_status === 'authorized')
            <p style="font-size:13px;margin-bottom:12px"><b>{{ format_money($rental->deposit_hold_cents) }}</b> on hold.</p>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:12.5px">
              <label style="display:flex;gap:9px;align-items:center;cursor:pointer"><input type="radio" name="deposit_action" value="release" checked> Release the full hold — clean return</label>
              <label style="display:flex;gap:9px;align-items:center;cursor:pointer"><input type="radio" name="deposit_action" value="hold"> Keep holding — decide later from the booking page</label>
            </div>
            <p style="font-size:11.5px;opacity:.5;margin-top:10px">Need to capture for damage? Finish the return with "keep holding", then capture from the booking page — the capture writes its own charge line and sale.</p>
          @elseif(in_array($rental->deposit_status, ['captured', 'partially_captured'], true))
            <p style="font-size:12.5px;opacity:.65">Hold {{ $rental->deposit_status === 'captured' ? 'fully' : 'partially' }} captured — nothing to decide.</p>
          @else
            <p style="font-size:12.5px;opacity:.55">No deposit was held on this rental.</p>
          @endif
        </div>

        <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:14px">
          <div style="padding:13px 18px;border-bottom:.5px solid var(--ia-border)"><span style="font-size:12px;font-weight:550;text-transform:uppercase;letter-spacing:.06em">Where does each unit go?</span></div>
          @foreach($unitLines as $line)
            @php $unit = $line->unit; @endphp
            @if($unit)
              <div style="display:flex;gap:12px;align-items:center;padding:11px 18px;border-bottom:.5px solid var(--ia-border);flex-wrap:wrap">
                <div style="flex:1;min-width:180px">
                  <div style="font-size:13px;font-weight:600">{{ $line->name_snapshot }}{{ $unit->identifier ? ' · ' . $unit->identifier : '' }}</div>
                  @if(($inChecks->get($unit->id)?->flagged) ?? false)<div style="font-size:11px;color:#ef4444">flagged at inspection</div>@endif
                </div>
                <div class="rt-seg rt-route" data-unit="{{ $unit->id }}">
                  <button type="button" class="{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? '' : 'ok' }}">Available</button>
                  <button type="button" class="{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? 'mt' : '' }}">Maintenance</button>
                </div>
                <input type="hidden" name="routing[{{ $unit->id }}]" value="{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? 'maintenance' : 'available' }}">
                <input type="text" name="routing_note[{{ $unit->id }}]" maxlength="500" placeholder="Maintenance note…" class="ia-input" style="width:220px;{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? '' : 'display:none' }}">
              </div>
            @endif
          @endforeach
          <div style="padding:10px 18px;font-size:11.5px;opacity:.5">Maintenance blocks the unit from new bookings until you clear it on the Fleet page.</div>
        </div>

        <div class="rt-foot">
          <button type="button" class="ia-btn" onclick="rtGo(2)">← Back</button>
          <button type="submit" class="ia-btn ia-btn--primary" style="font-size:14px;padding:10px 22px">Complete return ✓</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ------------------------------------------------------- money rail --}}
  <div>
    <div class="ia-card" style="padding:16px 18px;margin-bottom:14px">
      <span class="ia-label">Money</span>
      <div class="rt-kv" style="margin-top:6px"><span>Total</span><span>{{ format_money($rental->total_cents) }}</span></div>
      <div class="rt-kv"><span>Paid</span><span>{{ format_money($rental->paid_cents) }}</span></div>
      <div class="rt-kv" style="font-weight:650;{{ $balanceCents > 0 ? 'color:#E0A82E' : 'color:#7BC96F' }}"><span style="opacity:1;color:inherit">Balance</span><span>{{ format_money($balanceCents) }}</span></div>
      <div class="rt-kv" style="border-top:.5px solid var(--ia-border);padding-top:8px"><span>Deposit</span><span>{{ $rental->deposit_status === 'authorized' ? format_money($rental->deposit_hold_cents) . ' held' : ucfirst(str_replace('_', ' ', $rental->deposit_status)) }}</span></div>
    </div>
    <div class="ia-card" style="padding:16px 18px">
      <span class="ia-label">This flow</span>
      <p style="font-size:11.5px;opacity:.55;margin-top:8px;line-height:1.6">Each step saves on its own. Charges land on the rental and route through the register; nothing here touches the ledger directly.</p>
    </div>
  </div>
</div>

<script>
function rtGo(n) {
  document.querySelectorAll('.rt-pane').forEach(function (p) { p.classList.toggle('on', p.dataset.pane == n); });
  document.querySelectorAll('.rt-step').forEach(function (s) { s.classList.toggle('cur', s.dataset.step == n); });
  window.scrollTo({ top: 0 });
}
document.querySelectorAll('.rt-step').forEach(function (s) {
  s.addEventListener('click', function () { rtGo(s.dataset.step); });
});
rtGo({{ $startStep }});

// OK/Flag toggles (inspect step) — writes into hidden results[] input.
document.querySelectorAll('.rt-seg:not(.rt-route)').forEach(function (seg) {
  var btns = seg.querySelectorAll('button');
  var input = seg.parentElement.querySelector('input[type=hidden][name^="results"]');
  btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'ok'; });
  btns[1].addEventListener('click', function () { btns[1].className = 'flag'; btns[0].className = ''; if (input) input.value = 'flag'; });
});

// Available/Maintenance routing toggles — writes routing[unit] and shows the note field.
document.querySelectorAll('.rt-route').forEach(function (seg) {
  var btns = seg.querySelectorAll('button');
  var row = seg.parentElement;
  var input = row.querySelector('input[type=hidden][name^="routing["]');
  var note = row.querySelector('input[name^="routing_note"]');
  btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'available'; if (note) note.style.display = 'none'; });
  btns[1].addEventListener('click', function () { btns[1].className = 'mt'; btns[0].className = ''; if (input) input.value = 'maintenance'; if (note) note.style.display = ''; });
});

function rtAddDmgRow() {
  var wrap = document.getElementById('rt-dmg-rows');
  var row = document.createElement('div');
  row.className = 'rt-dmg-row';
  row.innerHTML = '<input type="text" name="damage_labels[]" maxlength="200" placeholder="Description" class="ia-input" style="flex:1">'
    + '<input type="number" name="damage_amounts[]" min="0" step="0.01" placeholder="0.00" class="ia-input" style="width:110px;text-align:right">';
  wrap.appendChild(row);
}
</script>

@endsection
BIZ3_24_EOF

cat > 'resources/views/tenant/rentals/bookings/index.blade.php' <<'BIZ3_25_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Bookings'; @endphp

{{-- MARKER-PATCH-219, rebuilt by MARKER-PATCH-234 — triage-first list:
     search + filters on every tab, "Needs attention" pinned first. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Rental Bookings</h1>
    <p class="ia-page-subtitle">Every rental, one pipeline. Overdue floats to the top, always.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary">New rental</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

<form method="GET" action="{{ route('tenant.rentals.bookings.index') }}" style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <input type="text" name="q" value="{{ $q }}" placeholder="Search customer, rental #, unit…" class="ia-input" style="flex:1 1 240px;width:auto;min-width:200px">
  <select name="category" class="ia-input" style="width:auto;flex:0 0 auto" onchange="this.form.submit()">
    <option value="">All categories</option>
    @foreach($categories as $cat)
      <option value="{{ $cat->id }}" {{ $category === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
    @endforeach
  </select>
  <select name="when" class="ia-input" style="width:auto;flex:0 0 auto" onchange="this.form.submit()">
    <option value="">Any date</option>
    <option value="today" {{ $when === 'today' ? 'selected' : '' }}>Today</option>
    <option value="week" {{ $when === 'week' ? 'selected' : '' }}>Next 7 days</option>
  </select>
  <button type="submit" class="ia-btn">Search</button>
  @if($q !== '' || $category !== '' || $when !== '')
    <a href="{{ route('tenant.rentals.bookings.index', ['tab' => $tab]) }}" class="ia-btn" style="opacity:.7">Clear</a>
  @endif
</form>

@php $keep = array_filter(['q' => $q, 'category' => $category, 'when' => $when], fn ($v) => $v !== ''); @endphp
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'attention']) }}" class="ia-btn {{ $tab === 'attention' ? 'ia-btn--primary' : '' }}">Needs attention ({{ $counts['attention'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'out']) }}" class="ia-btn {{ $tab === 'out' ? 'ia-btn--primary' : '' }}">Out ({{ $counts['out'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'upcoming']) }}" class="ia-btn {{ $tab === 'upcoming' ? 'ia-btn--primary' : '' }}">Upcoming ({{ $counts['upcoming'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'done']) }}" class="ia-btn {{ $tab === 'done' ? 'ia-btn--primary' : '' }}">Done ({{ $counts['done'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'all']) }}" class="ia-btn {{ $tab === 'all' ? 'ia-btn--primary' : '' }}">All</a>
</div>

<div class="ia-card" style="padding:0;overflow:hidden">
  @if($rentals->isEmpty())
    <div class="ia-empty" style="padding:40px;text-align:center">
      <div class="ia-empty-title">Nothing here</div>
      <div class="ia-empty-body" style="margin-top:6px">
        @if($q !== '' || $category !== '' || $when !== '') Nothing matches those filters.
        @elseif($tab === 'attention') Nothing needs you — no overdue rentals, no unpaid pickups today.
        @elseif($tab === 'out') Nothing is out right now.
        @elseif($tab === 'upcoming') No upcoming reservations.
        @else No rentals yet.
        @endif
      </div>
    </div>
  @else
    <div style="display:grid;grid-template-columns:100px 1.3fr 1.5fr 1fr 1fr 90px 130px;gap:12px;padding:10px 18px;border-bottom:.5px solid var(--ia-border);font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">
      <span>Rental</span><span>Customer</span><span>Units</span><span>Out</span><span>Due</span><span style="text-align:right">Balance</span><span>Status</span>
    </div>
    @foreach($rentals as $r)
      @php
        $late = $r->isOverdue();
        $bal  = max(0, (int) $r->total_cents - (int) $r->paid_cents);
        $units = $r->lines->where('kind', 'unit');
      @endphp
      <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}"
         style="display:grid;grid-template-columns:100px 1.3fr 1.5fr 1fr 1fr 90px 130px;gap:12px;align-items:center;padding:12px 18px;border-bottom:0.5px solid var(--ia-border);text-decoration:none;color:inherit">
        <span style="font-size:12px;opacity:.6;font-family:var(--ia-font-mono,monospace)">{{ $r->rental_number }}</span>
        <span style="font-size:13.5px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->customer?->fullName() }}</span>
        <span style="font-size:12.5px;opacity:.7;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $units->pluck('name_snapshot')->take(2)->implode(', ') }}{{ $units->count() > 2 ? ' +' . ($units->count() - 2) : '' }}</span>
        <span style="font-size:12px;opacity:.65">{{ tlocal_datetime($r->starts_at, 'M j, g:i A') }}</span>
        <span style="font-size:12px;{{ $late ? 'color:#ef4444;font-weight:700' : 'opacity:.65' }}">{{ tlocal_datetime($r->due_at, 'M j, g:i A') }}</span>
        <span style="font-size:12.5px;text-align:right;{{ $bal > 0 ? 'color:#E0A82E;font-weight:600' : 'opacity:.45' }}">{{ $bal > 0 ? format_money($bal) : '—' }}</span>
        <span>@include('tenant.rentals._status-pill', ['rental' => $r])</span>
      </a>
    @endforeach
  @endif
</div>
<p style="font-size:11.5px;opacity:.45;margin-top:12px">"Needs attention" = overdue, or balance due on a pickup starting today. Showing up to 200 rows — narrow with search if you need more history.</p>

@endsection
BIZ3_25_EOF

cat > 'resources/views/tenant/rentals/bookings/show.blade.php' <<'BIZ3_26_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = $rental->rental_number; @endphp

{{-- MARKER-PATCH-219 — rental detail: lines, ledger, transitions. --}}

@section('content')

@php
  $late = $rental->isOverdue();
  $statusColor = $late ? '#ef4444' : ($rental->status === 'out' ? '#f59e0b' : ($rental->status === 'returned' ? '#34d399' : ($rental->status === 'cancelled' ? '#ef4444' : 'inherit')));
  $balance = $rental->total_cents - $rental->paid_cents;
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title" style="display:flex;align-items:center;gap:10px">{{ $rental->rental_number }}
      {{-- MARKER-PATCH-234 — shared pill vocabulary. --}}
      @include('tenant.rentals._status-pill', ['rental' => $rental])
    </h1>
    <p class="ia-page-subtitle">{{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.index') }}" class="ia-btn">All bookings</a>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

{{-- MARKER-PATCH-234 — pipeline stepper: real timestamps per stage, red
     missed-due, cancelled short-circuits. --}}
@php
  $missedDue = $rental->due_at && $rental->due_at->isPast() && in_array($rental->status, ['out'], true);
  $stages = $rental->status === 'cancelled'
    ? [
        ['t' => 'Reserved',  'at' => $rental->created_at,   'state' => 'hit'],
        ['t' => 'Cancelled', 'at' => $rental->cancelled_at, 'state' => 'bad'],
      ]
    : [
        ['t' => 'Reserved',    'at' => $rental->created_at,     'state' => 'hit'],
        ['t' => 'Checked out', 'at' => $rental->checked_out_at, 'state' => in_array($rental->status, ['out', 'returned'], true) ? 'hit' : 'next'],
        ['t' => 'Due back',    'at' => $rental->due_at,         'state' => $rental->status === 'returned' ? 'hit' : ($missedDue ? 'bad' : ($rental->status === 'out' ? 'now' : 'next'))],
        ['t' => 'Returned',    'at' => $rental->returned_at,    'state' => $rental->status === 'returned' ? 'hit' : 'next'],
      ];
@endphp
<div class="ia-card" style="margin-bottom:16px;padding:14px 18px;display:flex;align-items:center;flex-wrap:wrap">
  @foreach($stages as $i => $st)
    @php
      [$dotStyle, $txtColor] = match ($st['state']) {
        'hit' => ['background:var(--ia-accent,#BEF264)', 'inherit'],
        'now' => ['background:#5BA3D0;box-shadow:0 0 0 4px rgba(91,163,208,.18)', 'inherit'],
        'bad' => ['background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.16)', '#ef4444'],
        default => ['background:var(--ia-border-strong,rgba(255,255,255,.22))', 'rgba(255,255,255,.55)'],
      };
    @endphp
    <div style="display:flex;align-items:center;gap:8px">
      <span style="width:9px;height:9px;border-radius:50%;{{ $dotStyle }}"></span>
      <div>
        <div style="font-size:11.5px;font-weight:550;color:{{ $txtColor }}">{{ $st['t'] }}</div>
        <div style="font-size:10px;opacity:.5;{{ $st['state'] === 'bad' ? 'color:#ef4444;opacity:1' : '' }}">{{ $st['at'] ? tlocal_datetime($st['at'], 'M j, g:i a') . ($st['state'] === 'bad' ? ' — missed' : '') : '—' }}</div>
      </div>
    </div>
    @if(!$loop->last)<span style="flex:1;min-width:18px;height:1.5px;background:{{ $st['state'] === 'hit' ? 'rgba(190,242,100,.5)' : 'var(--ia-border)' }};margin:0 10px"></span>@endif
  @endforeach
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start">

  <div>
    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Lines</span></div>
      @foreach($rental->lines as $line)
        <div style="display:flex;justify-content:space-between;gap:10px;padding:10px 16px;border-bottom:0.5px solid var(--ia-border)">
          <span style="font-size:13px">{{ $line->name_snapshot }}
            <span style="opacity:.5;font-size:11.5px">{{ $line->duration_units }} × {{ format_money($line->rate_cents_snapshot) }} ({{ $line->rate_mode_snapshot }})</span>
          </span>
          <span style="font-size:13px;font-weight:600">{{ format_money($line->line_total_cents) }}</span>
        </div>
      @endforeach
      <div style="padding:12px 16px;font-size:13px">
        <div style="display:flex;justify-content:space-between"><span style="opacity:.65">Subtotal</span><span>{{ format_money($rental->subtotal_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between"><span style="opacity:.65">Tax</span><span>{{ format_money($rental->tax_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:800;margin-top:4px"><span>Total</span><span>{{ format_money($rental->total_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between;margin-top:4px"><span style="opacity:.65">Paid (ledger)</span><span>{{ format_money($rental->paid_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:700;{{ $balance > 0 ? 'color:#f59e0b' : '' }}"><span>Balance</span><span>{{ format_money(max(0, $balance)) }}</span></div>
      </div>
    </div>

    {{-- MARKER-PATCH-219B — sales-as-money: payments flow through the register. --}}
    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Payments — via register</span></div>
      @if($rental->sales->isEmpty())
        <div style="padding:18px 16px;font-size:12.5px;opacity:.55">No register sales linked yet. Use Collect payment below.</div>
      @else
        @foreach($rental->sales as $sale)
          <div style="padding:10px 16px;border-bottom:0.5px solid var(--ia-border)">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px">
              <span style="font-weight:600">{{ $sale->sale_number }}
                <span style="font-size:11px;font-weight:700;margin-left:6px;{{ $sale->payment_status === 'paid' ? 'color:#34d399' : ($sale->payment_status === 'refunded' ? 'color:#ef4444' : 'opacity:.55') }}">{{ strtoupper($sale->payment_status) }}</span>
              </span>
              <span style="font-weight:700">{{ format_money($sale->total_cents) }}</span>
            </div>
            @foreach($sale->payments as $p)
              <div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;opacity:.75;margin-top:4px">
                <span>{{ tlocal_datetime($p->recorded_at, 'M j, g:i A') }} · {{ ucfirst($p->kind) }} · {{ $p->method ?? '—' }}</span>
                <span style="{{ $p->amount_cents < 0 ? 'color:#ef4444' : '' }}">{{ format_money(abs($p->amount_cents)) }}{{ $p->amount_cents < 0 ? ' refund' : '' }}</span>
              </div>
            @endforeach
          </div>
        @endforeach
      @endif
      @if($rental->status !== 'cancelled' && $balance > 0)
      <form method="POST" action="{{ route('tenant.rentals.bookings.collect', $rental->id) }}" style="display:flex;gap:8px;padding:12px 16px;align-items:end">
        @csrf
        {{-- MARKER-PATCH-232B — come back to this booking after payment. --}}
        <input type="hidden" name="return_to" value="{{ parse_url(route('tenant.rentals.bookings.show', $rental->id), PHP_URL_PATH) }}">
        <div>
          <label class="ia-label" style="display:block;margin-bottom:4px">Amount $</label>
          <input type="number" name="amount" min="0.01" step="0.01" required value="{{ number_format($balance / 100, 2, '.', '') }}" class="ia-input" style="width:140px;text-align:right">
        </div>
        <button type="submit" class="ia-btn ia-btn--primary">Collect payment</button>
        <span style="font-size:11px;opacity:.45;align-self:center">Creates a register sale — take cash, card, or send a payment link there. Refunds: open the sale in register history.</span>
      </form>
      @endif
    </div>

    {{-- MARKER-PATCH-234 — derived activity feed. --}}
    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Activity</span></div>
      @if($feed->isEmpty())
        <div style="padding:18px 16px;font-size:12.5px;opacity:.55">Nothing yet.</div>
      @else
        @foreach($feed as $i => $ev)
          @php
            $dotColor = match ($ev['dot']) {
              'lime' => 'var(--ia-accent,#BEF264)',
              'blue' => '#5BA3D0',
              'red'  => '#ef4444',
              default => 'var(--ia-border-strong,rgba(255,255,255,.3))',
            };
          @endphp
          <div style="display:grid;grid-template-columns:20px 1fr;gap:12px;padding:9px 18px;position:relative">
            @if(!$loop->last)<span style="position:absolute;left:27px;top:30px;bottom:-4px;width:1px;background:var(--ia-border)"></span>@endif
            <span style="width:9px;height:9px;border-radius:50%;background:{{ $dotColor }};margin-top:6px;justify-self:center"></span>
            <div>
              <div style="font-size:12.5px">{{ $ev['text'] }}</div>
              <div style="font-size:11px;opacity:.5;font-family:var(--ia-font-mono,monospace)">{{ tlocal_datetime($ev['at'], 'M j, g:i a') }}</div>
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>

  <div>
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Customer</span>
      <div style="margin-top:8px">
        <a href="{{ route('tenant.customers.show', $rental->customer_id) }}" style="font-size:14px;font-weight:700;text-decoration:none;color:inherit">{{ $rental->customer?->fullName() }}</a>
        <div style="font-size:12px;opacity:.6;margin-top:2px">{{ $rental->customer?->email }}</div>
        @if($rental->customer?->phone)<div style="font-size:12px;opacity:.6">{{ $rental->customer?->phone }}</div>@endif
      </div>
    </div>

    {{-- MARKER-PATCH-220 — deposit hold panel --}}
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Deposit</span>
      <div style="margin-top:10px;font-size:12.5px">
        @if($rental->deposit_status === 'authorized')
          <div style="font-weight:700;margin-bottom:8px">{{ format_money($rental->deposit_hold_cents) }} on hold</div>
          <form method="POST" action="{{ route('tenant.rentals.bookings.deposit.release', $rental->id) }}" style="margin-bottom:8px">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Release hold</button>
          </form>
          <form method="POST" action="{{ route('tenant.rentals.bookings.deposit.capture', $rental->id) }}" onsubmit="return confirm('Capture from the customer\'s card?')">@csrf
            <div style="display:flex;gap:6px;margin-bottom:6px">
              <input type="number" name="amount" min="0.50" step="0.01" max="{{ number_format($rental->deposit_hold_cents / 100, 2, '.', '') }}" placeholder="Amount $" required class="ia-input" style="flex:1;text-align:right">
              <button type="submit" class="ia-btn ia-btn--primary">Capture</button>
            </div>
            <input type="text" name="reason" maxlength="500" placeholder="Reason (shows on the sale)" class="ia-input" style="width:100%">
          </form>
          <p style="font-size:11px;opacity:.45;margin-top:8px">Release = no charge, no ledger entry. Capture = damage charge through the register ledger.</p>
        @elseif($rental->deposit_status === 'released')
          <div style="opacity:.65">Hold released — no charge.</div>
        @elseif(in_array($rental->deposit_status, ['captured', 'partially_captured'], true))
          <div style="opacity:.85">{{ $rental->deposit_status === 'captured' ? 'Hold fully captured' : 'Hold partially captured' }} — see the linked sale above.</div>
        @elseif(in_array($rental->status, ['reserved', 'out'], true))
          @if(tenant()->direct_payments_enabled)
            <div id="dep-start">
              <div style="display:flex;gap:6px">
                <input type="number" id="dep-amount" min="0.50" step="0.01" value="{{ number_format(max(0, $rental->lines->where('kind','unit')->sum(fn ($l) => (int) ($l->unit?->deposit_cents ?? 0))) / 100, 2, '.', '') }}" class="ia-input" style="flex:1;text-align:right">
                <button type="button" class="ia-btn ia-btn--primary" id="dep-authorize">Authorize hold</button>
              </div>
              <p style="font-size:11px;opacity:.45;margin-top:6px">Authorizes the customer's card without charging it.</p>
            </div>
            <div id="dep-element-wrap" style="display:none;margin-top:10px">
              <div id="dep-element"></div>
              <button type="button" class="ia-btn ia-btn--primary" id="dep-confirm" style="width:100%;margin-top:8px">Place hold</button>
              <div id="dep-error" style="font-size:12px;color:#ef4444;margin-top:6px"></div>
            </div>
          @else
            <div style="opacity:.55">Enable card payments in Settings → Payments to take deposit holds.</div>
          @endif
        @else
          <div style="opacity:.55">No deposit was held on this rental.</div>
        @endif
      </div>
    </div>

    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Actions</span>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
        @if($rental->status === 'reserved')
          {{-- MARKER-PATCH-232 — guided flow is the front door; one-click stays as the escape hatch. --}}
          <a href="{{ route('tenant.rentals.bookings.checkout.flow', $rental->id) }}" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center;text-decoration:none">Check out →</a>
          <form method="POST" action="{{ route('tenant.rentals.bookings.checkout', $rental->id) }}" onsubmit="return confirm('Skip the agreement, condition check, and deposit steps?')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Quick check out (skip flow)</button>
          </form>
          <form method="POST" action="{{ route('tenant.rentals.bookings.cancel', $rental->id) }}" onsubmit="return confirm('Cancel this reservation?')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Cancel reservation</button>
          </form>
        @elseif($rental->status === 'out')
          {{-- MARKER-PATCH-233 — guided return is the front door; one-click stays as the escape hatch. --}}
          <a href="{{ route('tenant.rentals.bookings.return.flow', $rental->id) }}" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center;text-decoration:none">Start return →</a>
          <form method="POST" action="{{ route('tenant.rentals.bookings.checkin', $rental->id) }}" onsubmit="return confirm('Skip inspection and charges? A clean check-in auto-releases any deposit hold.')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Quick check in (skip flow)</button>
          </form>
        @else
          <p style="font-size:12.5px;opacity:.55;margin:0">
            {{ $rental->status === 'returned' ? 'Returned ' . ($rental->returned_at ? tlocal_datetime($rental->returned_at, 'M j, g:i A') : '') : 'Cancelled.' }}
          </p>
        @endif
      </div>

    </div>

    {{-- MARKER-PATCH-234 — documents: signed agreement + check photos. --}}
    @php
      $docChecks = $rental->conditionChecks->filter(fn ($c) => is_array($c->photos) && count($c->photos));
    @endphp
    @if($rental->agreement_pdf_path || $docChecks->isNotEmpty())
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Documents</span>
      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;font-size:12.5px">
        @if($rental->agreement_pdf_path)
          <div style="display:flex;justify-content:space-between;gap:10px">
            <span>Agreement v{{ $rental->agreement_template_version }} — signed</span>
            <a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">PDF →</a>
          </div>
        @endif
        @foreach($docChecks as $check)
          <div style="display:flex;justify-content:space-between;gap:10px">
            <span>{{ $check->phase === 'check_out' ? 'Out-check' : 'In-check' }} — {{ $check->unit?->identifier ?: 'unit' }} ({{ count($check->photos) }} photo{{ count($check->photos) === 1 ? '' : 's' }})</span>
            <span>
              @foreach($check->photos as $pi => $p)
                <a href="{{ Storage::disk('public')->url($p) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">{{ $pi + 1 }}</a>{{ !$loop->last ? ' ' : '' }}
              @endforeach
            </span>
          </div>
        @endforeach
      </div>
    </div>
    @endif

    @if($rental->notes)
    <div class="ia-card" style="padding:16px">
      <span class="ia-label">Notes</span>
      <p style="font-size:12.5px;margin-top:8px;white-space:pre-wrap">{{ $rental->notes }}</p>
    </div>
    @endif
  </div>

</div>

@if($rental->deposit_status === 'none' && in_array($rental->status, ['reserved', 'out'], true) && tenant()->direct_payments_enabled)
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var btn = document.getElementById('dep-authorize');
  if (!btn) return;
  var intentUrl  = '{{ route('tenant.rentals.bookings.deposit.intent', $rental->id) }}';
  var confirmUrl = '{{ route('tenant.rentals.bookings.deposit.confirm', $rental->id) }}';
  var csrf = '{{ csrf_token() }}';
  var stripe = null, elements = null, piId = null;

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    var dollars = parseFloat(document.getElementById('dep-amount').value || '0');
    post(intentUrl, { amount_cents: Math.round(dollars * 100) }).then(function (res) {
      if (!res.ok || !res.json.ok) {
        alert(res.json.error || 'Could not start the hold.');
        btn.disabled = false;
        return;
      }
      piId = res.json.payment_intent;
      stripe = Stripe(res.json.publishable_key);
      elements = stripe.elements({ clientSecret: res.json.client_secret });
      elements.create('payment').mount('#dep-element');
      document.getElementById('dep-element-wrap').style.display = 'block';
    }).catch(function () { alert('Could not start the hold.'); btn.disabled = false; });
  });

  document.getElementById('dep-confirm').addEventListener('click', function () {
    var confirmBtn = this;
    confirmBtn.disabled = true;
    document.getElementById('dep-error').textContent = '';
    stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
      if (result.error) {
        document.getElementById('dep-error').textContent = result.error.message || 'Card was not authorized.';
        confirmBtn.disabled = false;
        return;
      }
      post(confirmUrl, { payment_intent: piId }).then(function (res) {
        if (res.ok && res.json.ok) { window.location.reload(); }
        else {
          document.getElementById('dep-error').textContent = (res.json && res.json.error) || 'Could not verify the hold.';
          confirmBtn.disabled = false;
        }
      });
    });
  });
})();
</script>
@endif

@endsection
BIZ3_26_EOF

echo "business-customers-3-surfaces applied — server: git pull && php artisan view:clear"
