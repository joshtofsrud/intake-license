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

    public function updateTemplate(Request $request, string $subdomain, string $id)
    {
        $tenant   = tenant();
        $template = TenantClassTemplate::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:120'],
            'description'            => ['nullable', 'string', 'max:1000'],
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

    public function destroyTemplate(string $subdomain, string $id)
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

    public function storeSession(Request $request, string $subdomain)
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

    public function updateSession(Request $request, string $subdomain, string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'starts_at'              => ['sometimes', 'date', 'after:now'],
            'capacity_snapshot'      => ['sometimes', 'integer', 'min:1', 'max:500'],
            'status'                 => ['sometimes', 'in:scheduled,confirmed,cancelled,completed'],
            'instructor_resource_id' => ['nullable', 'uuid', 'exists:tenant_resources,id'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
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

    public function destroySession(string $subdomain, string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)->findOrFail($id);

        if ($session->activeRegistrations()->exists()) {
            return back()->withErrors(['session' => 'Cannot delete a session with active registrations.']);
        }

        $session->delete();

        return back()->with('success', 'Session deleted.');
    }

    public function showSession(string $subdomain, string $id)
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

    public function registerCustomer(Request $request, string $subdomain, string $sessionId)
    {
        $tenant = tenant();

        $data = $request->validate([
            'customer_id'    => ['required', 'uuid', 'exists:tenant_customers,id'],
            'payment_method' => ['required', 'in:membership,pack,per_class,cash'],
        ]);

        $registration = $this->registrationService->register(
            $sessionId,
            $data['customer_id'],
            $tenant->id,
            $data['payment_method']
        );

        return back()->with('success',
            $registration->status === 'waitlisted'
                ? 'Customer added to waitlist.'
                : 'Customer registered.'
        );
    }

    public function cancelRegistration(string $subdomain, string $id)
    {
        $tenant = tenant();
        $this->registrationService->cancel($id, $tenant->id);

        return back()->with('success', 'Registration cancelled.');
    }

    public function checkIn(string $subdomain, string $id)
    {
        $tenant = tenant();
        $this->registrationService->checkIn($id, $tenant->id);

        return back()->with('success', 'Checked in.');
    }

    public function markNoShow(string $subdomain, string $id)
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

    public function updateMembershipProduct(Request $request, string $subdomain, string $id)
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

    public function updatePackProduct(Request $request, string $subdomain, string $id)
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
}
