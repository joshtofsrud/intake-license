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
            $startsAt = Carbon::parse($date->format('Y-m-d') . ' ' . $time);
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
            $startsAt = Carbon::parse($data['starts_at']);
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
