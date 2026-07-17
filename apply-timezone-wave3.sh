#!/bin/bash
# timezone-wave3 — class times, per-tenant job days, honest location tz.
#   · Class sessions (create + edit): entered wall-clock times parse in the
#     TENANT timezone and store UTC — a 10:00 AM class no longer becomes
#     3:00 AM PT. Existing sessions are NOT bulk-corrected (inspect first;
#     pre-fix rows were stored shifted).
#   · Memberships/packs daily tick + waitlist expiry: business-date
#     comparisons now use EACH tenant's local today (jobs run on UTC hours =
#     the previous US evening; periods no longer roll the night before).
#     Instant comparisons (offer_expires_at, addon period ends) untouched.
#   · Locations: the do-nothing per-location timezone input is removed from
#     the form; effectiveTimezone() gains a NOT-YET-WIRED docblock. Column
#     and validation stay for the future multi-location feature.
# No routes, no migrations.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-TZ-WAVE3" app/Http/Controllers/Tenant/ClassController.php; then
  echo "timezone-wave3 already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TZ-WAVE2" config/database.php; then
  echo "timezone-wave2 not applied — wrong base, aborting."; exit 1
fi

cat > 'app/Http/Controllers/Tenant/ClassController.php' <<'TZW3_0_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantClassTemplate;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassMembershipProduct;
use App\Models\Tenant\TenantClassPackProduct;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Services\ClassRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ClassController extends Controller
{
    public function __construct(private ClassRegistrationService $registrationService) {}

    // ------------------------------------------------------------------
    // Templates
    // ------------------------------------------------------------------

    public function templates()
    {
        $tenant    = tenant();
        $templates = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->withCount(['sessions' => fn($q) => $q->where('starts_at', '>', now())])
            ->orderBy('name')
            ->get();

        $resources = TenantResource::where('tenant_id', $tenant->id)
            ->active()->ordered()->get();

        return view('tenant.classes.templates', compact('templates', 'resources'));
    }

    public function storeTemplate(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'name'                    => ['required', 'string', 'max:120'],
            'description'             => ['nullable', 'string', 'max:1000'],
            'class_notes'             => ['nullable', 'string', 'max:2000'],
            'duration_minutes'        => ['required', 'integer', 'min:5', 'max:480'],
            'default_capacity'        => ['required', 'integer', 'min:1', 'max:500'],
            'instructor_resource_id'  => ['nullable', 'uuid', 'exists:tenant_resources,id'],
            'price_cents'             => ['required', 'integer', 'min:0'],
            'is_active'               => ['boolean'],
        ]);

        $data['tenant_id'] = $tenant->id;
        $data['slug']      = $this->uniqueSlug($data['name'], $tenant->id);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        TenantClassTemplate::create($data);

        return back()->with('success', 'Class template created.');
    }

    public function updateTemplate(Request $request, string $id)
    {
        $tenant   = tenant();
        $template = TenantClassTemplate::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:120'],
            'description'            => ['nullable', 'string', 'max:1000'],
            'class_notes'            => ['nullable', 'string', 'max:2000'],
            'duration_minutes'       => ['required', 'integer', 'min:5', 'max:480'],
            'default_capacity'       => ['required', 'integer', 'min:1', 'max:500'],
            'instructor_resource_id' => ['nullable', 'uuid', 'exists:tenant_resources,id'],
            'price_cents'            => ['required', 'integer', 'min:0'],
            'is_active'              => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        $template->update($data);

        return back()->with('success', 'Template updated.');
    }

    public function destroyTemplate(string $id)
    {
        $tenant   = tenant();
        $template = TenantClassTemplate::where('tenant_id', $tenant->id)->findOrFail($id);

        // Block delete if future sessions exist
        if ($template->sessions()->where('starts_at', '>', now())->exists()) {
            return back()->withErrors(['template' => 'Cannot delete a template with upcoming sessions.']);
        }

        $template->delete();

        return back()->with('success', 'Template deleted.');
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    public function sessions(Request $request)
    {
        $tenant   = tenant();
        $from     = $request->date('from', 'Y-m-d') ?? now()->startOfWeek();
        $to       = $request->date('to', 'Y-m-d')   ?? $from->copy()->addDays(6);

        $sessions = TenantClassSession::where('tenant_id', $tenant->id)
            ->whereBetween('starts_at', [$from, $to->endOfDay()])
            ->with(['template', 'instructorResource', 'registrations.customer'])
            ->withCount(['activeRegistrations', 'waitlist'])
            ->orderBy('starts_at')
            ->get();

        $templates = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->active()->orderBy('name')->get();

        return view('tenant.classes.sessions', compact('sessions', 'templates', 'from', 'to'));
    }

    public function storeSession(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'class_template_id' => ['required', 'uuid', 'exists:tenant_class_templates,id'],
            'starts_date'       => ['required', 'date', 'after_or_equal:today'],
            'starts_time'       => ['required', 'date_format:H:i'],
            'capacity_override' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'repeat_until'      => ['nullable', 'date', 'after:starts_date'],
            'repeat_until_daily'=> ['nullable', 'date', 'after:starts_date'],
            'repeat_days'       => ['nullable', 'string'],
        ]);

        $template = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->findOrFail($request->class_template_id);

        $instructor = $template->instructor_resource_id;
        $resource   = $instructor ? TenantResource::find($instructor) : null;
        $capacity   = $request->capacity_override ?? $template->default_capacity;
        $notes      = $request->notes;

        // Build list of dates to create sessions for
        $dates = [];
        $startDate = Carbon::parse($request->starts_date);
        $time      = $request->starts_time;

        if ($request->filled('repeat_days') && $request->filled('repeat_until')) {
            // Weekly repeat — specific days of week
            $dows  = array_map('intval', explode(',', $request->repeat_days));
            $until = Carbon::parse($request->repeat_until)->endOfDay();
            $cur   = $startDate->copy();
            while ($cur->lte($until) && count($dates) < 365) {
                if (in_array($cur->dayOfWeek, $dows)) {
                    $dates[] = $cur->copy();
                }
                $cur->addDay();
            }
        } elseif ($request->filled('repeat_until_daily')) {
            // Daily repeat
            $until = Carbon::parse($request->repeat_until_daily)->endOfDay();
            $cur   = $startDate->copy();
            while ($cur->lte($until) && count($dates) < 365) {
                $dates[] = $cur->copy();
                $cur->addDay();
            }
        } else {
            // Single session
            $dates[] = $startDate->copy();
        }

        $created = 0;
        foreach ($dates as $date) {
            // MARKER-TZ-WAVE3 — the entered time is tenant wall clock;
            // parse it in the tenant timezone, store the UTC instant.
            // (Parsing in UTC made a 10:00 AM class display as 3:00 AM PT.)
            $startsAt = Carbon::parse($date->format('Y-m-d') . ' ' . $time, tenant()->timezone())->utc();
            $endsAt   = $startsAt->copy()->addMinutes($template->duration_minutes);

            TenantClassSession::create([
                'tenant_id'              => $tenant->id,
                'class_template_id'      => $template->id,
                'starts_at'              => $startsAt,
                'ends_at'                => $endsAt,
                'instructor_resource_id' => $instructor,
                'instructor_snapshot'    => $resource?->name,
                'capacity_snapshot'      => $capacity,
                'status'                 => 'confirmed',
                'notes'                  => $notes,
            ]);
            $created++;
        }

        $msg = $created === 1 ? 'Session created.' : "{$created} sessions created.";
        return back()->with('success', $msg);
    }

    public function updateSession(Request $request, string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'starts_at'              => ['sometimes', 'date', 'after:now'],
            'capacity_snapshot'      => ['sometimes', 'integer', 'min:1', 'max:500'],
            'status'                 => ['sometimes', 'in:scheduled,confirmed,cancelled,completed'],
            'instructor_resource_id' => ['nullable', 'uuid', 'exists:tenant_resources,id'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
            'session_notes_override' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['starts_at'])) {
            // MARKER-TZ-WAVE3 — same wall-clock parse fix as creation.
            $startsAt = Carbon::parse($data['starts_at'], tenant()->timezone())->utc();
            $data['starts_at'] = $startsAt;
            $data['ends_at'] = $startsAt->copy()->addMinutes($session->template->duration_minutes);
        }

        if (isset($data['instructor_resource_id'])) {
            $resource = TenantResource::find($data['instructor_resource_id']);
            $data['instructor_snapshot'] = $resource?->name;
        }

        $session->update($data);

        return back()->with('success', 'Session updated.');
    }

    public function destroySession(string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)->findOrFail($id);

        if ($session->activeRegistrations()->exists()) {
            return back()->withErrors(['session' => 'Cannot delete a session with active registrations.']);
        }

        $session->delete();

        return back()->with('success', 'Session deleted.');
    }

    public function showSession(string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)
            ->with(['template', 'instructorResource',
                    'registrations.customer',
                    'waitlist.customer'])
            ->findOrFail($id);

        return view('tenant.classes.session-detail', compact('session'));
    }

    // ------------------------------------------------------------------
    // Registrations (admin actions)
    // ------------------------------------------------------------------

    public function registerCustomer(Request $request, string $sessionId)
    {
        $tenant = tenant();

        $data = $request->validate([
            'customer_id'    => ['required', 'uuid', 'exists:tenant_customers,id'],
            'payment_method' => ['required', 'in:membership,pack,per_class,cash'],
        ]);

        // Cash diverts to the register flow: open a draft sale with the class
        // drop-in as a line item, redirect admin to the register where they
        // take payment (cash, card, gift card, etc.). On sale commit, the
        // hook in SaleService::commitDraft() creates the registration row.
        if ($data['payment_method'] === 'cash') {
            return $this->registerViaCash($sessionId, $data['customer_id']);
        }

        // resolvePayment() throws RuntimeException when admin explicitly picks
        // pack/membership and the customer has neither. Surface that as a flash
        // message instead of letting it bubble to a 500.
        try {
            $registration = $this->registrationService->register(
                $sessionId,
                $data['customer_id'],
                $tenant->id,
                $data['payment_method']
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success',
            $registration->status === 'waitlisted'
                ? 'Customer added to waitlist.'
                : 'Customer registered.'
        );
    }

    /**
     * Cash-pays-for-class flow.
     *
     * Creates a draft sale with a single open_item line for the class drop-in,
     * stashes the class_session_id in sale metadata so the commit hook knows
     * to register the customer afterward, then redirects to the register with
     * ?draft={id} so the cart loads automatically.
     *
     * Why open_item rather than a real product/service: drop-ins are dynamic
     * — the line description includes the specific session date/time which
     * would otherwise need a product per session. open_item with a snapshot
     * price from the template is the cleanest path.
     */
    private function registerViaCash(string $sessionId, string $customerId)
    {
        $tenant = tenant();

        $session = \App\Models\Tenant\TenantClassSession::where('tenant_id', $tenant->id)
            ->with('template')
            ->findOrFail($sessionId);

        if (in_array($session->status, ['cancelled', 'completed'])) {
            return back()->with('error', 'This class session is not accepting registrations.');
        }

        $price = (int) ($session->template->price_cents ?? 0);
        if ($price <= 0) {
            return back()->with('error', 'This class has no drop-in price set. Set a price on the template first.');
        }

        $locationId = request()->session()->get('current_location_id');
        if (!$locationId) {
            return back()->with('error', 'Pick a register location first.');
        }

        // Snapshot the line description with specific session details. Same
        // pattern as service-line snapshotting elsewhere — protects the cart
        // display if the template is renamed later.
        $lineName = sprintf(
            'Drop-in: %s · %s %s',
            $session->template->name,
            $session->starts_at->format('D M j'),
            $session->starts_at->format('g:i A')
        );

        $draft = app(\App\Services\Tenant\SaleService::class)->saveDraft([
            'tenant_id'          => $tenant->id,
            'rang_up_by_user_id' => auth('tenant')->id(),
            'location_id'        => $locationId,
            'customer_id'        => $customerId,
            'notes'              => null,
            'tip_cents'          => 0,
            'metadata'           => [
                'kind'             => 'class_drop_in',
                'class_session_id' => $sessionId,
            ],
            'items'              => [[
                'type'             => 'open_item',
                'name_snapshot'    => $lineName,
                'unit_price_cents' => $price,
                'quantity'         => 1,
                'is_taxable'       => true,
            ]],
        ]);

        return redirect()->route('tenant.register.index', [
            'draft'     => $draft->id,
        ])->with('success', 'Cart prepared — take payment to complete registration.');
    }

    public function cancelRegistration(string $id)
    {
        $tenant = tenant();
        $this->registrationService->cancel($id, $tenant->id);

        return back()->with('success', 'Registration cancelled.');
    }

    public function checkIn(string $id)
    {
        $tenant = tenant();
        $this->registrationService->checkIn($id, $tenant->id);

        return back()->with('success', 'Checked in.');
    }

    public function markNoShow(string $id)
    {
        $tenant = tenant();
        $this->registrationService->markNoShow($id, $tenant->id);

        return back()->with('success', 'Marked as no-show.');
    }

    // ------------------------------------------------------------------
    // Membership products
    // ------------------------------------------------------------------

    public function membershipProducts()
    {
        $tenant   = tenant();
        $products = TenantClassMembershipProduct::where('tenant_id', $tenant->id)
            ->withCount('memberships')
            ->orderBy('name')
            ->get();

        return view('tenant.classes.membership-products', compact('products'));
    }

    public function storeMembershipProduct(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'description'   => ['nullable', 'string', 'max:500'],
            'type'          => ['required', 'in:unlimited,capped'],
            'monthly_limit' => ['nullable', 'integer', 'min:1', 'max:999',
                                'required_if:type,capped'],
            'price_dollars' => ['required', 'numeric', 'min:0'],
            'is_active'     => ['boolean'],
        ]);

        $data['tenant_id'] = $tenant->id;
        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        if ($data['type'] === 'unlimited') {
            $data['monthly_limit'] = null;
        }

        TenantClassMembershipProduct::create($data);

        return back()->with('success', 'Membership product created.');
    }

    public function updateMembershipProduct(Request $request, string $id)
    {
        $tenant  = tenant();
        $product = TenantClassMembershipProduct::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'description'   => ['nullable', 'string', 'max:500'],
            'type'          => ['required', 'in:unlimited,capped'],
            'monthly_limit' => ['nullable', 'integer', 'min:1', 'max:999',
                                'required_if:type,capped'],
            'price_dollars' => ['required', 'numeric', 'min:0'],
            'is_active'     => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        if ($data['type'] === 'unlimited') {
            $data['monthly_limit'] = null;
        }

        $product->update($data);

        return back()->with('success', 'Membership product updated.');
    }

    // ------------------------------------------------------------------
    // Pack products
    // ------------------------------------------------------------------

    public function packProducts()
    {
        $tenant   = tenant();
        $products = TenantClassPackProduct::where('tenant_id', $tenant->id)
            ->withCount('customerPacks')
            ->orderBy('name')
            ->get();

        return view('tenant.classes.pack-products', compact('products'));
    }

    public function storePackProduct(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'credit_count'=> ['required', 'integer', 'min:1', 'max:999'],
            'expiry_days' => ['required', 'integer', 'min:1', 'max:730'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);

        $data['tenant_id'] = $tenant->id;
        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        TenantClassPackProduct::create($data);

        return back()->with('success', 'Pack product created.');
    }

    public function updatePackProduct(Request $request, string $id)
    {
        $tenant  = tenant();
        $product = TenantClassPackProduct::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'credit_count'=> ['required', 'integer', 'min:1', 'max:999'],
            'expiry_days' => ['required', 'integer', 'min:1', 'max:730'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        $product->update($data);

        return back()->with('success', 'Pack product updated.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function uniqueSlug(string $name, string $tenantId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 2;

        while (TenantClassTemplate::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    // ------------------------------------------------------------------
    // Customer-side: grant memberships and packs to specific customers.
    // These are "comp/manual" grants for v1 — Stripe purchase flow comes
    // later. For real purchases, customer-facing flow will create rows
    // here too but with stripe_*_id populated.
    // ------------------------------------------------------------------

    /**
     * Grant a membership to a customer. Enforces "one active membership per
     * customer" by refusing if one already exists. Creates an audit note on
     * the customer record.
     */
    public function grantCustomerMembership(Request $request, string $customerId)
    {
        $tenant = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->findOrFail($customerId);

        $data = $request->validate([
            'product_id' => ['required', 'string'],
            'note'       => ['nullable', 'string', 'max:300'],
        ]);

        $product = TenantClassMembershipProduct::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->findOrFail($data['product_id']);

        // One-active-membership rule. Existing one must be cancelled first.
        $existing = TenantCustomerMembership::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->first();
        if ($existing) {
            return response()->json([
                'ok' => false,
                'message' => 'Customer already has an active membership. Cancel it first.',
            ], 422);
        }

        // Period: starts today, ends one calendar month from today.
        // Period rollover command (TODO) will advance these monthly.
        $start = now($tenant->timezone())->startOfDay();
        $end   = $start->copy()->addMonth();

        $membership = TenantCustomerMembership::create([
            'tenant_id'                => $tenant->id,
            'customer_id'              => $customer->id,
            'product_id'               => $product->id,
            'status'                   => 'active',
            'current_period_start'     => $start,
            'current_period_end'       => $end,
            'classes_used_this_period' => 0,
            'stripe_subscription_id'   => null,
            'metadata'                 => ['granted_by' => 'admin', 'granted_at' => now()->toIso8601String()],
        ]);

        $this->writeCustomerAuditNote($customer, sprintf(
            'Membership granted: %s (period %s → %s).%s',
            $product->name,
            $start->format('M j'),
            $end->format('M j, Y'),
            !empty($data['note']) ? ' Note: ' . $data['note'] : ''
        ));

        return response()->json([
            'ok'         => true,
            'membership' => [
                'id'           => $membership->id,
                'product_name' => $product->name,
                'period_end'   => $end->format('M j, Y'),
            ],
        ]);
    }

    /**
     * Cancel an active membership. Sets status='cancelled' (kept for audit),
     * does not soft-delete. Records audit note.
     */
    public function revokeCustomerMembership(Request $request, string $customerId, string $membershipId)
    {
        $tenant = tenant();
        $membership = TenantCustomerMembership::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->findOrFail($membershipId);

        if ($membership->status !== 'active') {
            return response()->json(['ok' => false, 'message' => 'Membership is not active.'], 422);
        }

        $membership->update(['status' => 'cancelled']);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)->find($customerId);
        if ($customer) {
            $productName = $membership->product?->name ?? 'membership';
            $this->writeCustomerAuditNote($customer, "Membership cancelled: {$productName}.");
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Grant a pack to a customer. Multiple active packs are allowed (booking
     * service uses oldest-expiry-first). Sets credits_remaining = credit_count.
     */
    public function grantCustomerPack(Request $request, string $customerId)
    {
        $tenant = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->findOrFail($customerId);

        $data = $request->validate([
            'product_id' => ['required', 'string'],
            'note'       => ['nullable', 'string', 'max:300'],
        ]);

        $product = TenantClassPackProduct::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->findOrFail($data['product_id']);

        $expiresAt = now($tenant->timezone())->startOfDay()->addDays((int) $product->expiry_days);

        $pack = TenantCustomerPack::create([
            'tenant_id'                => $tenant->id,
            'customer_id'              => $customer->id,
            'product_id'               => $product->id,
            'credits_total'            => (int) $product->credit_count,
            'credits_remaining'        => (int) $product->credit_count,
            'expires_at'               => $expiresAt,
            'status'                   => 'active',
            'stripe_payment_intent_id' => null,
            'metadata'                 => ['granted_by' => 'admin', 'granted_at' => now()->toIso8601String()],
        ]);

        $this->writeCustomerAuditNote($customer, sprintf(
            '%d-class pack granted: %s (expires %s).%s',
            $product->credit_count,
            $product->name,
            $expiresAt->format('M j, Y'),
            !empty($data['note']) ? ' Note: ' . $data['note'] : ''
        ));

        return response()->json([
            'ok'   => true,
            'pack' => [
                'id'                => $pack->id,
                'product_name'      => $product->name,
                'credits_remaining' => $pack->credits_remaining,
                'credits_total'     => $pack->credits_total,
                'expires_at'        => $expiresAt->format('M j, Y'),
            ],
        ]);
    }

    /**
     * Revoke a pack. Sets status='cancelled' (preserves credit history).
     */
    public function revokeCustomerPack(Request $request, string $customerId, string $packId)
    {
        $tenant = tenant();
        $pack = TenantCustomerPack::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->findOrFail($packId);

        if ($pack->status !== 'active') {
            return response()->json(['ok' => false, 'message' => 'Pack is not active.'], 422);
        }

        $pack->update(['status' => 'cancelled']);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)->find($customerId);
        if ($customer) {
            $productName = $pack->product?->name ?? 'pack';
            $remaining = $pack->credits_remaining;
            $this->writeCustomerAuditNote($customer, "Pack cancelled: {$productName} ({$remaining} credits forfeited).");
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Helper for writing system notes to the customer history. Used by all
     * grant/revoke actions so admins have a clear record of comps and changes.
     */
    private function writeCustomerAuditNote(TenantCustomer $customer, string $note): void
    {
        try {
            \App\Models\Tenant\TenantCustomerNote::create([
                'tenant_id'   => $customer->tenant_id,
                'customer_id' => $customer->id,
                'user_id'     => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note'        => $note,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — audit note failure shouldn't block the grant/revoke action.
            \Illuminate\Support\Facades\Log::warning('writeCustomerAuditNote failed', [
                'customer_id' => $customer->id,
                'note'        => $note,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Reports — member health, churn signals, conversion targets
    // ------------------------------------------------------------------

    /**
     * /admin/classes/reports — full panel page. Pulls all 6 panels at once.
     * If page-load time becomes a problem at scale, lazy-load some panels
     * via AJAX. For now batch fetch is fine — total query count is bounded.
     */
    public function reports(\App\Services\Tenant\ClassReportsService $service)
    {
        $tenant = tenant();
        $tid    = $tenant->id;

        $headline           = $service->headline($tid);
        $dropInRegulars     = $service->dropInRegulars($tid);
        $atRiskMembers      = $service->atRiskMembers($tid, 30);
        $usedUpPacks        = $service->usedUpPacks($tid);
        $recentlyCancelled  = $service->recentlyCancelled($tid);
        $lapsedMemberships  = $service->lapsedMemberships($tid);
        $topProducts        = $service->topEarningProducts($tid);

        return view('tenant.classes.reports', compact(
            'headline',
            'dropInRegulars',
            'atRiskMembers',
            'usedUpPacks',
            'recentlyCancelled',
            'lapsedMemberships',
            'topProducts'
        ));
    }

    /**
     * CSV export for any panel. Single endpoint, panel slug routes to the
     * right service method. We pass a generous limit so exports return
     * the full set, not just the page-rendered first 25.
     *
     * Slugs match the panel keys used in the blade for consistency. If a
     * future panel is added, register it here and in the service.
     */
    public function reportExport(string $panel, \App\Services\Tenant\ClassReportsService $service)
    {
        $tenant = tenant();
        $tid    = $tenant->id;
        $exportLimit = 5000; // soft cap; one tenant is unlikely to exceed this

        $rows = match ($panel) {
            'drop-in-regulars'      => $service->dropInRegulars($tid, $exportLimit),
            'at-risk-members'       => $service->atRiskMembers($tid, 30, $exportLimit),
            'used-up-packs'         => $service->usedUpPacks($tid, $exportLimit),
            'recently-cancelled'    => $service->recentlyCancelled($tid, $exportLimit),
            'lapsed-memberships'    => $service->lapsedMemberships($tid, $exportLimit),
            default                 => abort(404, 'Unknown report panel'),
        };

        $filename = sprintf(
            'classes-report-%s-%s.csv',
            $panel,
            now()->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // Headers: customer-facing columns
            fputcsv($out, ['Customer name', 'Email', 'Detail', 'Date', 'Customer ID']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['name'],
                    $row['email'] ?? '',
                    $row['fact'],
                    $row['meta'],
                    $row['customer_id'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
TZW3_0_EOF

cat > 'app/Console/Commands/MembershipsTickCommand.php' <<'TZW3_1_EOF'
<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * memberships:tick
 *
 * Runs daily. Two jobs in one command:
 *
 *   1. Membership period rollover. For every active membership whose
 *      current_period_end has passed, advance the period by one month and
 *      reset classes_used_this_period. Without this, "monthly limit"
 *      memberships stay frozen on a single period's usage forever.
 *
 *   2. Pack expiry. For every active or exhausted pack whose expires_at
 *      has passed, flip status='expired'. Existing credits stay on the row
 *      for accounting honesty, but the pack stops being eligible for class
 *      coverage.
 *
 * Both operations are idempotent — running twice in a day is a no-op the
 * second time. Safe to retry on failure.
 *
 * Wire-up: Josh runs the existing intake-scheduler externally (see
 * addons:expire pattern). Add this command to the same crontab/systemd
 * timer as `0 4 * * * php artisan memberships:tick` or similar. Or add to
 * routes/console.php Schedule::command() if standardizing on Laravel scheduler.
 */
class MembershipsTickCommand extends Command
{
    protected $signature = 'memberships:tick';

    protected $description = 'Roll over membership periods and expire stale packs.';

    public function handle(): int
    {
        $now = now();

        $rolled  = $this->rolloverMemberships($now);
        $expired = $this->expirePacks($now);

        $this->info("Rolled over {$rolled} membership period(s). Expired {$expired} pack(s).");
        return self::SUCCESS;
    }

    /**
     * Find every active membership whose period has lapsed and advance it.
     *
     * Loop chunks to avoid loading the whole table — at scale a tenant could
     * have thousands of active memberships. Each row is a tiny update so the
     * cursor is fine.
     */
    private function rolloverMemberships(Carbon $now): int
    {
        $count = 0;

        // MARKER-TZ-WAVE3 — period_end is a tenant-local business date; the
        // job runs on UTC hours (early UTC morning = the previous evening in
        // the US), so comparing against the UTC date rolled memberships the
        // night before their period actually ended. Compare per tenant
        // against that tenant's local today.
        $tenantToday = self::tenantLocalDates();
        TenantCustomerMembership::where('status', 'active')
            ->whereNotNull('current_period_end')
            ->cursor()
            ->each(function (TenantCustomerMembership $m) use (&$count, $now, $tenantToday) {
                $localToday = $tenantToday[$m->tenant_id] ?? $now->toDateString(); // MARKER-TZ-WAVE3
                if ($m->current_period_end->toDateString() >= $localToday) return;
                try {
                    DB::transaction(function () use ($m, $now) {
                        // Advance the period. We anchor off the existing end so
                        // a membership that lapsed mid-month still rolls cleanly,
                        // but if the period_end is way in the past (system was
                        // down for a month), we catch up to "next from now" so
                        // we don't loop. Most cases: period_end was yesterday,
                        // new start = today, new end = today + 1 month.
                        $newStart = $m->current_period_end->copy()->addDay();
                        if ($newStart->lt($now->copy()->startOfDay())) {
                            $newStart = $now->copy()->startOfDay();
                        }
                        $newEnd = $newStart->copy()->addMonth()->subDay();

                        $m->update([
                            'current_period_start'     => $newStart,
                            'current_period_end'       => $newEnd,
                            'classes_used_this_period' => 0,
                        ]);
                    });
                    $count++;
                } catch (\Throwable $e) {
                    // Don't fail the whole batch on one bad row.
                    Log::warning('memberships:tick rollover failed', [
                        'membership_id' => $m->id,
                        'tenant_id'     => $m->tenant_id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            });

        return $count;
    }

    /**
     * Mark packs whose expires_at has passed as expired. Includes both
     * 'active' (had unused credits) and 'exhausted' (zero credits remaining)
     * — both should transition to 'expired' for accurate reporting.
     *
     * Note: 'cancelled' packs are left alone. Their cancellation is the
     * terminal event — expiring them would obscure the history.
     */
    private function expirePacks(Carbon $now): int
    {
        // MARKER-TZ-WAVE3 — same per-tenant local-date treatment as rollover.
        $count = 0;
        foreach (self::tenantLocalDates() as $tenantId => $localToday) {
            $count += TenantCustomerPack::where('tenant_id', $tenantId)
                ->whereIn('status', ['active', 'exhausted'])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $localToday)
                ->update(['status' => 'expired']);
        }
        return $count;
    }

    /**
     * MARKER-TZ-WAVE3 — map of tenant_id => that tenant's local "today"
     * (Y-m-d). One query; used by daily jobs so business-date comparisons
     * respect each tenant's timezone instead of the UTC calendar.
     */
    public static function tenantLocalDates(): array
    {
        return \App\Models\Tenant::query()
            ->pluck('timezone', 'id')
            ->map(fn ($tz) => now($tz ?: config('app.timezone', 'UTC'))->toDateString())
            ->all();
    }
}
TZW3_1_EOF

cat > 'app/Console/Commands/ExpireWaitlistEntries.php' <<'TZW3_2_EOF'
<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantWaitlistOffer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireWaitlistEntries extends Command
{
    protected $signature   = 'waitlist:expire';
    protected $description = 'Mark waitlist entries past their date range as expired, and pending offers past slot time.';

    public function handle(): int
    {
        // MARKER-TZ-WAVE3 — date_range_end is a tenant-local business date;
        // expire per tenant against that tenant's local today, not UTC's.
        $entryCount = 0;
        foreach (\App\Console\Commands\MembershipsTickCommand::tenantLocalDates() as $tenantId => $localToday) {
            $entryCount += TenantWaitlistEntry::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereDate('date_range_end', '<', $localToday)
                ->update(['status' => 'expired', 'updated_at' => now()]);
        }

        $offerCount = TenantWaitlistOffer::whereIn('status', ['pending', 'viewed'])
            ->where('offer_expires_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $this->info("Expired {$entryCount} entries and {$offerCount} offers.");
        return self::SUCCESS;
    }
}
TZW3_2_EOF

cat > 'app/Models/Tenant/TenantLocation.php' <<'TZW3_3_EOF'
<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical location for a tenant.
 *
 * Every tenant has at least one location (the default "Main" location),
 * even if they're single-location forever. Multi-location is a capability
 * flag — controls whether the UI lets them create more than one.
 *
 * Falls back to tenant-level values for booking_window_days, min_notice_hours,
 * and timezone when the location-level override is null.
 */
class TenantLocation extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_locations';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'is_default',
        'is_active',
        'sort_order',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'email',
        'timezone',
        'booking_window_days_override',
        'min_notice_hours_override',
        'settings',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'booking_window_days_override' => 'integer',
        'min_notice_hours_override' => 'integer',
        'settings' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function capacityRules(): HasMany
    {
        return $this->hasMany(TenantCapacityRule::class, 'location_id');
    }

    public function inventoryItemLocations(): HasMany
    {
        return $this->hasMany(TenantInventoryItemLocation::class, 'location_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(TenantInventoryMovement::class, 'location_id');
    }

    public function receiveShipments(): HasMany
    {
        return $this->hasMany(TenantInventoryReceiveShipment::class, 'location_id');
    }

    /**
     * Effective timezone — falls back to tenant timezone when null.
     */
    /**
     * MARKER-TZ-WAVE3 — NOT YET WIRED: no runtime code calls this. It is the
     * intended resolution point when scheduling becomes location-aware; the
     * locations form no longer exposes the field until then.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: $this->tenant->timezone();
    }

    /**
     * Effective booking window — falls back to tenant value when override is null.
     */
    public function effectiveBookingWindowDays(): ?int
    {
        return $this->booking_window_days_override ?? $this->tenant->booking_window_days;
    }

    /**
     * Effective min notice — falls back to tenant value when override is null.
     */
    public function effectiveMinNoticeHours(): ?int
    {
        return $this->min_notice_hours_override ?? $this->tenant->min_notice_hours;
    }
}
TZW3_3_EOF

cat > 'resources/views/tenant/locations/index.blade.php' <<'TZW3_4_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Locations';
@endphp

@push('styles')
<style>
.loc-add-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 20px 24px;
  margin-bottom: 24px;
  display: none;
}
.loc-add-card.open { display: block; }

.loc-list {
  display: flex; flex-direction: column; gap: 10px;
}
.loc-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 16px 18px;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 16px;
  align-items: center;
}
.loc-card.is-inactive { opacity: .55; }

.loc-card-main { min-width: 0; }
.loc-card-name {
  display: flex; align-items: center; gap: 8px;
  font-size: 15px; font-weight: 600; margin-bottom: 4px;
}
.loc-card-meta {
  font-size: 12.5px; opacity: .6; line-height: 1.5;
  display: flex; flex-wrap: wrap; gap: 12px;
}
.loc-card-meta-item { display: inline-flex; align-items: center; gap: 4px; }

.loc-actions { display: flex; gap: 4px; align-items: center; }
.loc-icon-btn {
  width: 32px; height: 32px;
  border-radius: 6px;
  border: 0.5px solid var(--ia-border);
  background: transparent;
  color: var(--ia-text-muted, rgba(255,255,255,.55));
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  transition: background var(--ia-t), color var(--ia-t), border-color var(--ia-t);
  padding: 0;
}
.loc-icon-btn:hover {
  background: var(--ia-hover, rgba(255,255,255,.05));
  color: var(--ia-text);
  border-color: var(--ia-border-strong, rgba(255,255,255,.18));
}
.loc-icon-btn.is-danger:hover {
  background: rgba(226,75,74,.10);
  color: #F09595;
  border-color: rgba(226,75,74,.30);
}
.loc-icon-svg { width: 15px; height: 15px; }

/* Inline edit form */
.loc-edit-form { display: none; margin-top: 14px; padding-top: 14px; border-top: 0.5px solid var(--ia-border); }
.loc-edit-form.open { display: block; }
.loc-edit-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 12px; }
.loc-edit-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }

@media (max-width: 720px) {
  .loc-card { grid-template-columns: 1fr; }
  .loc-actions { justify-content: flex-start; }
  .loc-edit-grid, .loc-edit-grid-2 { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Locations</h1>
    <p class="ia-page-subtitle">{{ $locations->count() }} {{ Str::plural('location', $locations->count()) }}</p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="loc-add-toggle">+ Add location</button>
  </div>
</div>

{{-- Add location form --}}
<div class="loc-add-card" id="loc-add-card">
  <div style="font-size:13px;font-weight:500;margin-bottom:16px">New location</div>
  <form method="POST" action="{{ route('tenant.locations.store') }}">
    @csrf
    <div class="loc-edit-grid">
      <div class="ia-form-group">
        <label class="ia-form-label">Name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" value="{{ old('name') }}" placeholder="e.g. Westside" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Phone</label>
        <input type="tel" name="phone" class="ia-input" value="{{ old('phone') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Email</label>
        <input type="email" name="email" class="ia-input" value="{{ old('email') }}">
      </div>
      {{-- MARKER-TZ-WAVE3 — timezone field removed from the form: nothing
           consumes it yet (effectiveTimezone() is unwired). Column and
           validation retained for the future multi-location tz feature. --}}
    </div>
    <div class="loc-edit-grid-2" style="margin-top:14px">
      <div class="ia-form-group">
        <label class="ia-form-label">Street address</label>
        <input type="text" name="address_line_1" class="ia-input" value="{{ old('address_line_1') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Suite, unit (optional)</label>
        <input type="text" name="address_line_2" class="ia-input" value="{{ old('address_line_2') }}">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-top:10px">
      <div class="ia-form-group">
        <label class="ia-form-label">City</label>
        <input type="text" name="city" class="ia-input" value="{{ old('city') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">State</label>
        <input type="text" name="state" class="ia-input" value="{{ old('state') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">ZIP</label>
        <input type="text" name="postal_code" class="ia-input" value="{{ old('postal_code') }}">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:18px">
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Add location</button>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="loc-add-cancel">Cancel</button>
    </div>
  </form>
</div>

{{-- Location list --}}
<div class="loc-list">
  @foreach($locations as $loc)
    <div class="loc-card {{ $loc->is_active ? '' : 'is-inactive' }}" data-loc-card="{{ $loc->id }}">
      <div class="loc-card-main">
        <div class="loc-card-name">
          {{ $loc->name }}
          @if($loc->is_default)
            <span class="ia-badge ia-badge--completed" style="font-size:10.5px">Default</span>
          @endif
          @if(! $loc->is_active)
            <span class="ia-badge ia-badge--cancelled" style="font-size:10.5px">Inactive</span>
          @endif
        </div>
        <div class="loc-card-meta">
          @if($loc->address_line_1)
            <span class="loc-card-meta-item">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
              </svg>
              {{ trim($loc->address_line_1 . ', ' . ($loc->city ?? '') . ' ' . ($loc->state ?? ''), ', ') }}
            </span>
          @endif
          @if($loc->phone)
            <span class="loc-card-meta-item">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/>
              </svg>
              {{ $loc->phone }}
            </span>
          @endif
          @if($loc->timezone)
            <span class="loc-card-meta-item">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
              </svg>
              {{ $loc->timezone }}
            </span>
          @endif
          @if(! $loc->address_line_1 && ! $loc->phone && ! $loc->timezone)
            <span style="opacity:.4;font-style:italic">No address or contact set</span>
          @endif
        </div>

        {{-- Inline edit form (hidden by default) --}}
        <form method="POST" action="{{ route('tenant.locations.update', $loc->id) }}" class="loc-edit-form" data-loc-edit="{{ $loc->id }}">
          @csrf @method('PATCH')
          <div class="loc-edit-grid">
            <div class="ia-form-group">
              <label class="ia-form-label">Name <span class="ia-required">*</span></label>
              <input type="text" name="name" class="ia-input" value="{{ $loc->name }}" required>
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Phone</label>
              <input type="tel" name="phone" class="ia-input" value="{{ $loc->phone }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Email</label>
              <input type="email" name="email" class="ia-input" value="{{ $loc->email }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Timezone</label>
              <input type="text" name="timezone" class="ia-input" value="{{ $loc->timezone }}" placeholder="America/Los_Angeles">
            </div>
          </div>
          <div class="loc-edit-grid-2" style="margin-top:10px">
            <div class="ia-form-group">
              <label class="ia-form-label">Street address</label>
              <input type="text" name="address_line_1" class="ia-input" value="{{ $loc->address_line_1 }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Suite, unit</label>
              <input type="text" name="address_line_2" class="ia-input" value="{{ $loc->address_line_2 }}">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-top:10px">
            <div class="ia-form-group">
              <label class="ia-form-label">City</label>
              <input type="text" name="city" class="ia-input" value="{{ $loc->city }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">State</label>
              <input type="text" name="state" class="ia-input" value="{{ $loc->state }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">ZIP</label>
              <input type="text" name="postal_code" class="ia-input" value="{{ $loc->postal_code }}">
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:18px">
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-loc-edit-cancel="{{ $loc->id }}">Cancel</button>
          </div>
        </form>
      </div>

      <div class="loc-actions">
        {{-- Edit --}}
        <button type="button" class="loc-icon-btn" title="Edit" aria-label="Edit" data-loc-edit-toggle="{{ $loc->id }}">
          <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9"/>
            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
          </svg>
        </button>

        {{-- Set default (only if not already default and is active) --}}
        @if(! $loc->is_default && $loc->is_active)
        <form method="POST" action="{{ route('tenant.locations.set-default', $loc->id) }}">
          @csrf
          <button type="submit" class="loc-icon-btn" title="Set as default" aria-label="Set as default" data-confirm="Set {{ $loc->name }} as the default location?">
            <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </button>
        </form>
        @endif

        {{-- Toggle active --}}
        @if(! $loc->is_default)
        <form method="POST" action="{{ route('tenant.locations.toggle-active', $loc->id) }}">
          @csrf
          <button type="submit" class="loc-icon-btn" title="{{ $loc->is_active ? 'Deactivate' : 'Reactivate' }}" aria-label="{{ $loc->is_active ? 'Deactivate' : 'Reactivate' }}" data-confirm="{{ $loc->is_active ? 'Deactivate' : 'Reactivate' }} {{ $loc->name }}?">
            @if($loc->is_active)
              <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            @else
              <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
            @endif
          </button>
        </form>
        @endif

        {{-- Delete --}}
        @if(! $loc->is_default)
        <form method="POST" action="{{ route('tenant.locations.destroy', $loc->id) }}">
          @csrf @method('DELETE')
          <button type="submit" class="loc-icon-btn is-danger" title="Delete location" aria-label="Delete location" data-confirm="Delete {{ $loc->name }}? This cannot be undone.">
            <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            </svg>
          </button>
        </form>
        @endif
      </div>
    </div>
  @endforeach
</div>

@endsection

@push('scripts')
<script>
// Add-card toggle
(function() {
  var toggle = document.getElementById('loc-add-toggle');
  var card   = document.getElementById('loc-add-card');
  var cancel = document.getElementById('loc-add-cancel');
  if (toggle) toggle.addEventListener('click', function() {
    card.classList.add('open');
    toggle.style.display = 'none';
  });
  if (cancel) cancel.addEventListener('click', function() {
    card.classList.remove('open');
    toggle.style.display = '';
  });
})();

// Inline edit toggles
document.querySelectorAll('[data-loc-edit-toggle]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id = btn.dataset.locEditToggle;
    var form = document.querySelector('[data-loc-edit="' + id + '"]');
    if (form) form.classList.add('open');
  });
});
document.querySelectorAll('[data-loc-edit-cancel]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id = btn.dataset.locEditCancel;
    var form = document.querySelector('[data-loc-edit="' + id + '"]');
    if (form) form.classList.remove('open');
  });
});
</script>
@endpush
TZW3_4_EOF

echo "timezone-wave3 applied — server needs view:clear"
