<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassTemplate;
use App\Models\Tenant\TenantCustomer;
use App\Services\ClassRegistrationService;
use App\Services\ClassOnlineSaleService; // MARKER-CLASSES-P3
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
        // MARKER-CLASSES-P0: weeks are the studio's weeks, and classes that have
        // started are not offered. (This used to start the week in UTC and
        // list earlier classes this week as bookable.)
        $tz = $tenant->timezone();
        try {
            $from = $request->filled('from')
                ? \Carbon\Carbon::createFromFormat('Y-m-d', (string) $request->query('from'), $tz)->startOfDay()
                : now($tz)->startOfWeek();
        } catch (\Throwable $e) {
            $from = now($tz)->startOfWeek();
        }
        $to       = $from->copy()->addDays(6)->endOfDay();

        $templateFilter = $request->query('template');

        $sessions = TenantClassSession::where('tenant_id', $tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereBetween('starts_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->where('starts_at', '>', now())
            ->when($templateFilter, fn($q) => $q->where('class_template_id', $templateFilter))
            ->with(['template', 'instructorResource'])
            ->withCount('activeRegistrations')
            ->orderBy('starts_at')
            ->get();

        $templates = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->active()->orderBy('name')->get();

        $customer = $this->customer();

        // MARKER-CLASSES-P3: the "New here?" line, for people who never had the intro.
        $introPromo = (ClassOnlineSaleService::cardsOn($tenant) && ! ClassOnlineSaleService::introUsed($tenant->id, $customer))
            ? ClassOnlineSaleService::products($tenant)['intro']->first() : null;

        return view('public.classes.index', compact(
            'sessions', 'templates', 'from', 'to', 'customer', 'templateFilter', 'introPromo'
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

        // MARKER-CLASSES-P3: what this customer can book with here, and what
        // the studio offers online: the intro offer, paying now, or paying at
        // the studio, as the studio's setting allows.
        $tid        = $session->class_template_id;
        $coversThis = fn ($p) => $p && (empty($p->covers) || in_array($tid, (array) $p->covers, true));
        $coverMemberships = $customer
            ? \App\Models\Tenant\TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
                ->whereIn('status', ['active', 'past_due'])->with('product')->get()
                ->filter(fn ($m) => $m->product && $m->canCoverClass() && $coversThis($m->product))
                ->sortBy(fn ($m) => $m->product->isUnlimited() ? 0 : 1)->values()
            : collect();
        $coverPacks = $customer
            ? \App\Models\Tenant\TenantCustomerPack::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
                ->consumptionOrder()->with('product')->get()->filter(fn ($p) => $coversThis($p->product))->values()
            : collect();
        $cardsOn    = ClassOnlineSaleService::cardsOn($tenant);
        $dropinMode = ClassOnlineSaleService::dropinMode($tenant);
        $introOffer = null;
        if ($cardsOn && $coverMemberships->isEmpty() && $coverPacks->isEmpty()
            && ! ClassOnlineSaleService::introUsed($tenant->id, $customer)) {
            $introOffer = ClassOnlineSaleService::products($tenant)['intro']->first(fn ($p) => $coversThis($p) && $p->price_cents > 0);
        }
        $introQuote  = $introOffer ? ClassOnlineSaleService::quote($tenant, 'pack', $introOffer, null, $customer) : null;
        $dropinQuote = ClassOnlineSaleService::quote($tenant, 'dropin', null, $session, $customer);
        $stripePk    = $cardsOn ? ClassOnlineSaleService::payments($tenant)->publishableKey() : null;
        $rules        = \App\Services\ClassRules::for($tenant); // MARKER-CLASSES-P4
        $bookingBlock = $rules->bookingBlock($session);
        $waitlistOpen = $rules->get('waitlist_on')
            && ($rules->get('waitlist_max') === null || $session->waitlist_count < $rules->get('waitlist_max'));
        $cancelNote   = $existingRegistration && $existingRegistration->status === 'registered' ? $rules->cancelNote($session) : null;
        if ($cancelNote && str_contains($cancelNote, 'use up') && ! in_array($existingRegistration->payment_method, ['pack', 'membership'], true)) {
            $cancelNote = null; // nothing was taken from a pass, so nothing is used up
        }
        if ($existingRegistration && $existingRegistration->status === 'registered'
            && $existingRegistration->payment_method === 'online' && ! $rules->isLateCancel($session)) { // MARKER-CLASSES-P6
            $cancelNote = trim(($cancelNote ?? '') . ' ' . ($rules->get('online_refund') === 'card'
                ? 'If you cancel now, your payment is refunded to your card.'
                : "Online payments aren't refunded here. Contact the studio."));
        }

        return view('public.classes.show', compact(
            'session', 'customer', 'activeMembership', 'activePacks',
            'existingRegistration', 'spotsRemaining', 'isFull',
            'coverMemberships', 'coverPacks', 'cardsOn', 'dropinMode', 'introOffer', 'introQuote', 'dropinQuote', 'stripePk', 'bookingBlock', 'waitlistOpen', 'cancelNote'
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

        // MARKER-CLASSES-P3: what a booking form may ask for. 'cash' means no
        // charge, so only a free class; 'online' only ever comes from a paid
        // checkout. Before this a form could post 'cash' and book a paid class
        // as "Paid at the desk".
        $session->loadMissing('template');
        $price = (int) ($session->template?->price_cents ?? 0);
        if (! in_array($paymentMethod, ['membership', 'pack', 'per_class', 'cash'], true)
            || ($paymentMethod === 'cash' && $price > 0)) {
            return back()->withInput()->withErrors(['booking' => 'Pick how you\'d like to pay.']);
        }
        if ($paymentMethod === 'per_class' && $price > 0 && $session->spotsRemaining() > 0
            && ClassOnlineSaleService::dropinMode($tenant) === 'card') {
            return back()->withInput()->withErrors(['booking' => 'Pay online to book this class.']);
        }

        // Guest drop-in — no account needed for per_class or cash.
        // MARKER-CLASSES-P0: a guest never spends a matching customer's pack or
        // membership; the email alone proves nothing.
        $isGuest = ! $customer;
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
                $paymentMethod,
                false,
                ! $isGuest
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
    // Add to calendar — GET /classes/{id}/calendar.ics (MARKER-CLASSES-P5)
    // ------------------------------------------------------------------

    public function calendar(string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)->with('template')->findOrFail($id);
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], (string) $s);
        $fmt = fn ($t) => $t->copy()->utc()->format('Ymd\THis\Z');
        $desc = trim(($session->instructor_snapshot ? 'With ' . $session->instructor_snapshot . "\n" : '')
            . (string) ($session->template?->class_notes ?? '') . "\n" . $tenant->urlTo('/classes/' . $session->id));
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Intake//Classes//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:class-' . $session->id . '@intake.works',
            'DTSTAMP:' . $fmt(now()),
            'DTSTART:' . $fmt($session->starts_at),
            'DTEND:' . $fmt($session->ends_at ?? $session->starts_at->copy()->addHour()),
            'SUMMARY:' . $esc(($session->template?->name ?? 'Class') . ' · ' . $tenant->name),
            'DESCRIPTION:' . $esc($desc),
            'URL:' . $esc($tenant->urlTo('/classes/' . $session->id)),
            $session->status === 'cancelled' ? 'STATUS:CANCELLED' : 'STATUS:CONFIRMED',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="class.ics"',
        ]);
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

        try {
            $refunded = $this->registrationService->cancel($registration->id, $tenant->id, false);
        } catch (\RuntimeException $e) { // MARKER-CLASSES-P0
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        // MARKER-CLASSES-P6: say what happened to an online payment
        $msg = 'Your registration has been cancelled.';
        if ($refunded !== null) {
            $msg .= ' ' . format_money($refunded) . ' has been refunded to your card. It can take a few days to show.';
        } elseif ($registration->payment_method === 'online'
            && \App\Services\ClassRules::for($tenant)->get('online_refund') === 'card'
            && empty($registration->fresh()->metadata['late_cancel'])) {
            $msg .= ' The studio will refund your payment.';
        }

        return back()->with('success', $msg);
    }
}
