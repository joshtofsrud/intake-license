<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassTemplate;
use App\Models\Tenant\TenantCustomer;
use App\Services\ClassRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerClassController extends Controller
{
    public function __construct(private ClassRegistrationService $registrationService) {}

    private function customer(): ?TenantCustomer
    {
        return Auth::guard('customer')->user();
    }

    // ------------------------------------------------------------------
    // Browse — /classes
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $tenant   = tenant();
        $from     = $request->date('from', 'Y-m-d') ?? now()->startOfWeek();
        $to       = $from->copy()->addDays(6)->endOfDay();

        $templateFilter = $request->query('template');

        $sessions = TenantClassSession::where('tenant_id', $tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereBetween('starts_at', [$from, $to])
            ->when($templateFilter, fn($q) => $q->where('class_template_id', $templateFilter))
            ->with(['template', 'instructorResource'])
            ->withCount('activeRegistrations')
            ->orderBy('starts_at')
            ->get();

        $templates = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->active()->orderBy('name')->get();

        $customer = $this->customer();

        return view('public.classes.index', compact(
            'sessions', 'templates', 'from', 'to', 'customer', 'templateFilter'
        ));
    }

    // ------------------------------------------------------------------
    // Detail — /classes/{id}
    // ------------------------------------------------------------------

    public function show(string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->with(['template', 'instructorResource'])
            ->withCount(['activeRegistrations', 'waitlist'])
            ->findOrFail($id);

        $customer         = $this->customer();
        $activeMembership = $customer?->activeMembership();
        $activePacks      = $customer ? $customer->activePacks()->get() : collect();

        // Check if customer already registered
        $existingRegistration = $customer
            ? $session->registrations()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['registered', 'waitlisted', 'checked_in'])
                ->first()
            : null;

        $spotsRemaining = max(0, $session->capacity_snapshot - $session->active_registrations_count);
        $isFull         = $spotsRemaining === 0;

        return view('public.classes.show', compact(
            'session', 'customer', 'activeMembership', 'activePacks',
            'existingRegistration', 'spotsRemaining', 'isFull'
        ));
    }

    // ------------------------------------------------------------------
    // Register — POST /classes/{id}/register
    // ------------------------------------------------------------------

    public function register(Request $request, string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->findOrFail($id);

        $customer = $this->customer();

        // Determine payment method
        $paymentMethod = $request->input('payment_method', 'per_class');

        // Guest drop-in — no account needed for per_class or cash
        if (!$customer) {
            if (!in_array($paymentMethod, ['per_class', 'cash'])) {
                return back()->withErrors(['auth' => 'Please sign in to use a membership or pack.']);
            }

            $request->validate([
                'first_name' => ['required', 'string', 'max:80'],
                'last_name'  => ['required', 'string', 'max:80'],
                'email'      => ['required', 'email', 'max:180'],
            ]);

            // Find or create guest customer
            $customer = TenantCustomer::firstOrCreate(
                ['tenant_id' => $tenant->id, 'email' => $request->email],
                [
                    'first_name' => $request->first_name,
                    'last_name'  => $request->last_name,
                ]
            );
        }

        try {
            $registration = $this->registrationService->register(
                $id,
                $customer->id,
                $tenant->id,
                $paymentMethod
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('tenant.customer.classes.confirm', [
            'id'        => $registration->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Confirmation
    // ------------------------------------------------------------------

    public function confirm(string $id)
    {
        $tenant       = tenant();
        $registration = \App\Models\Tenant\TenantClassRegistration::where('tenant_id', $tenant->id)
            ->with(['session.template', 'session.instructorResource', 'customer'])
            ->findOrFail($id);

        return view('public.classes.confirm', compact('registration'));
    }

    // ------------------------------------------------------------------
    // Cancel own registration
    // ------------------------------------------------------------------

    public function cancelRegistration(Request $request, string $id)
    {
        $customer = $this->customer();

        if (!$customer) {
            return redirect()->route('tenant.customer.login');
        }

        $tenant       = tenant();
        $registration = \App\Models\Tenant\TenantClassRegistration::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        $this->registrationService->cancel($registration->id, $tenant->id);

        return back()->with('success', 'Your registration has been cancelled.');
    }
}
