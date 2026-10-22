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

        // Class settings live on Classes › Settings (MARKER-CLASSES-P8)
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
            'instructor_resource_id'  => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_resources', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
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
            'instructor_resource_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_resources', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
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
        // MARKER-CLASSES-P7: a repeating class would keep making classes for it
        if (\App\Models\Tenant\TenantClassSchedule::where('class_template_id', $template->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()))->exists()) {
            return back()->withErrors(['template' => 'This class repeats on the schedule. Stop the repeating class first.']);
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
        // MARKER-CLASSES-P7: the week is the studio's week. In UTC, an
        // evening class on the last day showed up in the next week.
        $tz       = $tenant->timezone();
        $from     = $request->date('from', 'Y-m-d', $tz) ?? now($tz)->startOfWeek();
        $to       = $request->date('to', 'Y-m-d', $tz)   ?? $from->copy()->addDays(6);

        $sessions = TenantClassSession::where('tenant_id', $tenant->id)
            ->whereBetween('starts_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
            ->with(['template', 'instructorResource', 'registrations.customer'])
            ->withCount(['activeRegistrations', 'waitlist'])
            ->orderBy('starts_at')
            ->get();

        $templates = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->active()->orderBy('name')->get();

        $schedules = \App\Models\Tenant\TenantClassSchedule::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now($tz)->toDateString()))
            ->with('template')->orderBy('start_time')->get();
        $publishWeeks = \App\Services\ClassRules::for($tenant)->get('publish_weeks');

        return view('tenant.classes.sessions', compact('sessions', 'templates', 'from', 'to', 'schedules', 'publishWeeks'));
    }

    public function storeSession(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'class_template_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_class_templates', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'starts_date'       => ['required', 'date', 'after_or_equal:today'],
            'starts_time'       => ['required', 'date_format:H:i'],
            'capacity_override' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'repeat_until'      => ['nullable', 'date', 'after:starts_date'],
            'repeat_until_daily'=> ['nullable', 'date', 'after:starts_date'],
            'repeat_days'       => ['nullable', 'regex:/^[0-6](,[0-6])*$/'],
            'repeat'            => ['nullable', 'in:once,weekly,daily'], // MARKER-CLASSES-P7
        ]);

        $template = TenantClassTemplate::where('tenant_id', $tenant->id)
            ->findOrFail($request->class_template_id);

        $instructor = $template->instructor_resource_id;
        $resource   = $instructor ? TenantResource::find($instructor) : null;
        $capacity   = $request->capacity_override ?? $template->default_capacity;
        $notes      = $request->notes;

        // MARKER-CLASSES-P7: Weekly and Daily set up a repeating class; its
        // classes are created ahead on a rolling window, with no year cap.
        $mode = $request->input('repeat', 'once');
        if (in_array($mode, ['weekly', 'daily'], true)) {
            $days  = $mode === 'daily' ? [0, 1, 2, 3, 4, 5, 6] : explode(',', (string) $request->repeat_days);
            $until = $mode === 'daily' ? $request->repeat_until_daily : $request->repeat_until;
            try {
                $s = app(\App\Services\ClassScheduleService::class)->create(
                    $tenant, $template, $mode === 'weekly' && ! $request->filled('repeat_days') ? [] : $days,
                    $request->starts_time, Carbon::parse($request->starts_date)->toDateString(),
                    $until ?: null, $request->capacity_override ? (int) $request->capacity_override : null, $notes
                );
            } catch (\RuntimeException $e) {
                return back()->withErrors(['session' => $e->getMessage()])->withInput();
            }
            $n = TenantClassSession::where('schedule_id', $s->id)->count();
            return back()->with('success', "Repeating class set up: {$s->daysLabel()} at {$s->timeLabel()}"
                . ($s->ends_on ? ' until ' . $s->ends_on->format('M j') : '') . ". {$n} classes created so far; more are added as time goes on.");
        }

        $time  = $request->starts_time;
        $dates = [Carbon::parse($request->starts_date)];

        $created = 0;
        foreach ($dates as $date) {
            // the entered time is tenant wall clock;
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
            'instructor_resource_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_resources', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'notes'                  => ['nullable', 'string', 'max:1000'],
            'session_notes_override' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['starts_at'])) {
            // same wall-clock parse fix as creation.
            $startsAt = Carbon::parse($data['starts_at'], tenant()->timezone())->utc();
            $data['starts_at'] = $startsAt;
            $data['ends_at'] = $startsAt->copy()->addMinutes($session->template->duration_minutes);
        }

        if (isset($data['instructor_resource_id'])) {
            // this is the line that actually
            // copied another tenant's resource name into the snapshot. The
            // rule above scopes the id now; scope the lookup as well.
            $resource = TenantResource::where('tenant_id', $tenant->id)
                ->find($data['instructor_resource_id']);
            $data['instructor_snapshot'] = $resource?->name;
        }

        // MARKER-CLASSES-P0: cancelling a session cancels its bookings and gives
        // credits and membership uses back. It used to change the status only.
        if (($data['status'] ?? null) === 'cancelled' && $session->status !== 'cancelled') {
            $c = $this->registrationService->cancelSession($session->id, $tenant->id);
            $session->update($data);
            $msg = 'Session cancelled.';
            if ($session->schedule_id) {
                $msg .= ' The repeating class carries on; this date stays cancelled.'; // MARKER-CLASSES-P7
            }
            if ($c['cancelled'] || $c['waitlist']) {
                $msg .= " {$c['cancelled']} booking(s) cancelled, {$c['restored']} credit(s) or membership use(s) given back"
                     . ($c['waitlist'] ? ", {$c['waitlist']} waitlist place(s) cleared" : '') . '.';
                if ($c['paid_at_desk']) {
                    $msg .= " {$c['paid_at_desk']} paid at the desk: refund them from the sale in the register.";
                }
                // MARKER-CLASSES-P5: online drop-ins are refunded, and everyone is emailed
                if ($c['refunded']) {
                    $msg .= " {$c['refunded']} online payment(s) refunded to the card.";
                }
                if ($c['refund_failed']) {
                    $msg .= " {$c['refund_failed']} online payment(s) could not be refunded automatically: refund them from the sale in Sales.";
                }
                $msg .= $c['emails_on']
                    ? ($c['emailed'] ? " {$c['emailed']} customer(s) emailed." : ' Nobody booked has an email on file, so let them know.')
                    : ' Class cancellation emails are off in Communication, so let customers know.';
            }
            return back()->with('success', $msg);
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

        // MARKER-CLASSES-P7
        $schedule    = $session->schedule_id ? \App\Models\Tenant\TenantClassSchedule::find($session->schedule_id) : null;
        $instructors = TenantResource::where('tenant_id', $tenant->id)->active()->ordered()->get();

        return view('tenant.classes.session-detail', compact('session', 'schedule', 'instructors'));
    }

    // ------------------------------------------------------------------
    // Registrations (admin actions)
    // ------------------------------------------------------------------

    public function registerCustomer(Request $request, string $sessionId)
    {
        $tenant = tenant();

        $data = $request->validate([
            'customer_id'    => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_customers', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
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
                $data['payment_method'],
                true // staff: may book until the class ends (MARKER-CLASSES-P0)
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
        // MARKER-CLASSES-P0: never take cash for a class that can't seat them.
        if ($session->ends_at->isPast()) {
            return back()->with('error', 'This class has already finished.');
        }
        if ($session->spotsRemaining() <= 0) {
            return back()->with('error', 'This class is full. Add them to the waitlist with a pass or membership, or pick another class.');
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
            tlocal($session->starts_at, 'D M j'),
            tlocal($session->starts_at, 'g:i A')
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
        try {
            $this->registrationService->cancel($id, $tenant->id, true);
        } catch (\RuntimeException $e) { // MARKER-CLASSES-P0
            return back()->with('error', $e->getMessage());
        }

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
        $templates = \App\Models\Tenant\TenantClassTemplate::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']);

        return view('tenant.classes.membership-products', compact('products', 'templates'));
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
            'covers_mode' => ['nullable', 'in:all,some'],
            'covers'      => ['nullable', 'array'],
            'covers.*'    => ['uuid', \Illuminate\Validation\Rule::exists('tenant_class_templates', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'is_taxable'  => ['boolean'],
        ]);
        // MARKER-CLASSES-P1: which classes it pays for, and tax.
        $data['covers'] = ($request->input('covers_mode') === 'some' && $request->filled('covers'))
            ? array_values(array_unique($request->input('covers'))) : null;
        unset($data['covers_mode']);
        $data['is_taxable'] = $request->boolean('is_taxable');
        $data += $this->onlineFlags($request, __FUNCTION__); // MARKER-CLASSES-P3

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
            'covers_mode' => ['nullable', 'in:all,some'],
            'covers'      => ['nullable', 'array'],
            'covers.*'    => ['uuid', \Illuminate\Validation\Rule::exists('tenant_class_templates', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'is_taxable'  => ['boolean'],
        ]);
        // MARKER-CLASSES-P1: which classes it pays for, and tax.
        $data['covers'] = ($request->input('covers_mode') === 'some' && $request->filled('covers'))
            ? array_values(array_unique($request->input('covers'))) : null;
        unset($data['covers_mode']);
        $data['is_taxable'] = $request->boolean('is_taxable');
        $data += $this->onlineFlags($request, __FUNCTION__); // MARKER-CLASSES-P3

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
        $templates = \App\Models\Tenant\TenantClassTemplate::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']);

        return view('tenant.classes.pack-products', compact('products', 'templates'));
    }

    public function storePackProduct(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'credit_count'=> ['required', 'integer', 'min:1', 'max:999'],
            'expiry_days' => ['required', 'integer', 'min:1', 'max:730'],
            'price_dollars' => ['required', 'numeric', 'min:0'], // MARKER-CLASSES-P1: was price_cents, which the form never sends
            'is_active'   => ['boolean'],
            'covers_mode' => ['nullable', 'in:all,some'],
            'covers'      => ['nullable', 'array'],
            'covers.*'    => ['uuid', \Illuminate\Validation\Rule::exists('tenant_class_templates', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'is_taxable'  => ['boolean'],
        ]);
        // MARKER-CLASSES-P1: which classes it pays for, and tax.
        $data['covers'] = ($request->input('covers_mode') === 'some' && $request->filled('covers'))
            ? array_values(array_unique($request->input('covers'))) : null;
        unset($data['covers_mode']);
        $data['is_taxable'] = $request->boolean('is_taxable');
        $data += $this->onlineFlags($request, __FUNCTION__); // MARKER-CLASSES-P3

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
            'price_dollars' => ['required', 'numeric', 'min:0'], // MARKER-CLASSES-P1: was price_cents, which the form never sends
            'is_active'   => ['boolean'],
            'covers_mode' => ['nullable', 'in:all,some'],
            'covers'      => ['nullable', 'array'],
            'covers.*'    => ['uuid', \Illuminate\Validation\Rule::exists('tenant_class_templates', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'is_taxable'  => ['boolean'],
        ]);
        // MARKER-CLASSES-P1: which classes it pays for, and tax.
        $data['covers'] = ($request->input('covers_mode') === 'some' && $request->filled('covers'))
            ? array_values(array_unique($request->input('covers'))) : null;
        unset($data['covers_mode']);
        $data['is_taxable'] = $request->boolean('is_taxable');
        $data += $this->onlineFlags($request, __FUNCTION__); // MARKER-CLASSES-P3

        $data['is_active'] = $request->boolean('is_active', true);
        $data['price_cents'] = (int) round($request->input('price_dollars', 0) * 100);

        $product->update($data);

        return back()->with('success', 'Pack product updated.');
    }

    // ------------------------------------------------------------------
    // Selling online (MARKER-CLASSES-P3)
    // ------------------------------------------------------------------

    /** "Sell online" for both product kinds; "Intro offer" for packs. */
    private function onlineFlags(Request $request, string $fn): array
    {
        $flags = ['sell_online' => $request->boolean('sell_online')];
        if (str_contains($fn, 'Pack')) {
            $flags['is_intro'] = $request->boolean('is_intro');
        }
        return $flags;
    }

    // ------------------------------------------------------------------
    // Repeating classes (MARKER-CLASSES-P7)
    // ------------------------------------------------------------------

    /** Change this class, or this and later classes on its schedule. */
    public function editSession(Request $request, string $id)
    {
        $tenant  = tenant();
        $session = TenantClassSession::where('tenant_id', $tenant->id)->findOrFail($id);
        $d = $request->validate([
            'scope'                  => ['required', 'in:one,later'],
            'date'                   => ['nullable', 'date_format:Y-m-d'],
            'time'                   => ['required', 'date_format:H:i'],
            'capacity'               => ['nullable', 'integer', 'min:1', 'max:500'],
            'instructor_resource_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_resources', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);
        if (! in_array($session->status, ['scheduled', 'confirmed'], true)) {
            return back()->withErrors(['session' => 'This class can no longer be changed.']);
        }

        $c   = ['time' => $d['time'], 'capacity' => $d['capacity'] ?? null, 'instructor_resource_id' => $d['instructor_resource_id'] ?? null];
        $svc = app(\App\Services\ClassScheduleService::class);
        try {
            if ($d['scope'] === 'later' && $session->schedule_id) {
                $r   = $svc->editFrom($session, $c);
                $msg = $r['updated'] === 1 ? 'Class updated.' : "{$r['updated']} classes updated, this one and later. New classes on this schedule use the new details.";
            } else {
                $r   = $svc->editOne($session, $c + ['date' => $d['date'] ?? null]);
                $msg = 'Class updated.';
            }
        } catch (\RuntimeException $e) {
            return back()->withErrors(['session' => $e->getMessage()]);
        }
        if ($r['emailed']) {
            $msg .= $tenant->notificationEnabled('class_changed_email')
                ? " {$r['emailed']} people booked were emailed the new time."
                : " {$r['emailed']} people are booked, and class change emails are off in Communication, so let them know.";
        }
        return back()->with('success', $msg);
    }

    /** Stop a repeating class after a given day; booked classes stay. */
    public function stopSchedule(Request $request, string $id)
    {
        $tenant = tenant();
        $s      = \App\Models\Tenant\TenantClassSchedule::where('tenant_id', $tenant->id)->findOrFail($id);
        $d      = $request->validate(['last_day' => ['required', 'date_format:Y-m-d']]);
        $r      = app(\App\Services\ClassScheduleService::class)->stopAfter($s, $d['last_day']);

        $msg = 'Stopped after ' . Carbon::parse($d['last_day'])->format('M j') . '.';
        if ($r['removed']) {
            $msg .= " {$r['removed']} later class(es) nobody had booked were removed.";
        }
        if ($r['kept']) {
            $msg .= " {$r['kept']} later class(es) have people booked and were kept: cancel or keep them one by one.";
        }
        return back()->with('success', $msg);
    }

    /** Classes › Settings: every class rule and message switch (MARKER-CLASSES-P8). */
    public function settings()
    {
        $tenant   = tenant();
        $rules    = \App\Services\ClassRules::for($tenant)->all();
        $messages = collect(\App\Services\ClassMessages::LABELS)->map(fn ($x, $k) => $tenant->notificationEnabled($k . '_email'))->all();
        $onlineDropin = \App\Services\ClassOnlineSaleService::dropinSetting($tenant);
        $cardsOn      = \App\Services\ClassOnlineSaleService::cardsOn($tenant);

        return view('tenant.classes.settings', compact('rules', 'messages', 'onlineDropin', 'cardsOn'));
    }

    /** A blank box means "no limit". Message switches are the same ones Communication shows. */
    public function saveSettings(Request $request)
    {
        $tenant = tenant();
        $d = $request->validate([
            'book_ahead_days'     => ['nullable', 'integer', 'min:0', 'max:365'],
            'book_cutoff_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cancel_cutoff_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'after_cutoff'        => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Services\ClassRules::AFTER_CUTOFF))],
            'online_refund'       => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Services\ClassRules::ONLINE_REFUND))],
            'waitlist_max'        => ['nullable', 'integer', 'min:1', 'max:500'],
            'waitlist_stop_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'publish_weeks'       => ['nullable', 'integer', 'min:1', 'max:26'],
            'reminder_hours'      => ['nullable', 'integer', 'min:1', 'max:48'],
            'class_online_dropin' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Services\ClassOnlineSaleService::DROPIN_MODES))],
            'notify'              => ['nullable', 'array'],
        ]);
        $settings = (array) ($tenant->settings ?? []);
        $settings['class_rules'] = collect($d)->only([
            'book_ahead_days', 'book_cutoff_minutes', 'cancel_cutoff_hours', 'after_cutoff', 'online_refund',
            'waitlist_max', 'waitlist_stop_hours', 'publish_weeks', 'reminder_hours',
        ])->all() + ['waitlist_on' => $request->boolean('waitlist_on')];
        if (! empty($d['class_online_dropin'])) {
            $settings['class_online_dropin'] = $d['class_online_dropin'];
        }
        foreach (array_keys(\App\Services\ClassMessages::LABELS) as $key) {
            if (array_key_exists($key, (array) ($d['notify'] ?? []))) {
                $settings['notify_' . $key . '_email'] = (bool) $d['notify'][$key];
            }
        }
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('success', 'Settings saved.');
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

        // MARKER-CLASSES-P1: several memberships are allowed (each covers its own
        // classes); the same membership twice is not.
        $existing = TenantCustomerMembership::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->first();
        if ($existing) {
            return response()->json([
                'ok' => false,
                'message' => 'Customer already has this membership.',
            ], 422);
        }

        // Period: today through the day before the same date next month,
        // the same shape memberships:tick uses when it rolls a period over
        // (it used to be one day longer on the first period). MARKER-CLASSES-P0
        $start = now($tenant->timezone())->startOfDay();
        $end   = $start->copy()->addMonth()->subDay();

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
        \App\Services\ClassCreditLedger::record($tenant->id, $customer->id, 0, 'granted', ['membership_id' => $membership->id],
            $product->name . ' · given by staff' . (!empty($data['note']) ? ' · ' . $data['note'] : '')); // MARKER-CLASSES-P1

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
        \App\Services\ClassCreditLedger::record($tenant->id, $membership->customer_id, 0, 'revoked', ['membership_id' => $membership->id], 'Cancelled by staff'); // MARKER-CLASSES-P1

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
        \App\Services\ClassCreditLedger::record($tenant->id, $customer->id, (int) $product->credit_count, 'granted', ['pack_id' => $pack->id],
            $product->name . ' · given by staff' . (!empty($data['note']) ? ' · ' . $data['note'] : '')); // MARKER-CLASSES-P1

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
        \App\Services\ClassCreditLedger::record($tenant->id, $pack->customer_id, -((int) $pack->credits_remaining), 'revoked', ['pack_id' => $pack->id], 'Cancelled by staff'); // MARKER-CLASSES-P1

        $customer = TenantCustomer::where('tenant_id', $tenant->id)->find($customerId);
        if ($customer) {
            $productName = $pack->product?->name ?? 'pack';
            $remaining = $pack->credits_remaining;
            $this->writeCustomerAuditNote($customer, "Pack cancelled: {$productName} ({$remaining} credits forfeited).");
        }

        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-CLASSES-P2: staff billing actions on one membership.
     *   pause         skip renewals until a date (from the next renewal)
     *   resume        clear a pause
     *   cancel_end    end it on the renewal date, no more charges
     *   undo_cancel   keep renewing
     */
    public function membershipAction(Request $request, string $customerId, string $id)
    {
        $tenant = tenant();
        $m = TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customerId)->findOrFail($id);
        $data = $request->validate([
            'action'      => ['required', 'in:pause,resume,cancel_end,undo_cancel'],
            'pause_until' => ['required_if:action,pause', 'nullable', 'date', 'after:today'],
        ]);
        $label = $m->product?->name ?? 'Membership';

        switch ($data['action']) {
            case 'pause':
                $until = \Carbon\Carbon::parse($data['pause_until']);
                $m->update(['pause_until' => $until]);
                $msg = "{$label} pauses at its renewal on {$m->current_period_end->copy()->addDay()->format('M j')} and restarts {$until->format('M j')}.";
                break;
            case 'resume':
                $m->update(['pause_until' => null]);
                $msg = $m->status === 'paused' ? "{$label} restarts at tonight's renewal run." : "{$label} won't pause.";
                if ($m->status === 'paused') {
                    $m->update(['pause_until' => now($tenant->timezone())->startOfDay()]);
                }
                break;
            case 'cancel_end':
                $m->update(['cancel_at_period_end' => true]);
                $msg = "{$label} ends on {$m->current_period_end->format('M j')}. No more charges.";
                break;
            default:
                $m->update(['cancel_at_period_end' => false]);
                $msg = "{$label} keeps renewing.";
        }
        \App\Services\ClassCreditLedger::record($tenant->id, $m->customer_id, 0, 'billing_change', ['membership_id' => $m->id], $msg);
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->find($customerId);
        if ($customer) {
            $this->writeCustomerAuditNote($customer, $msg);
        }

        return back()->with('success', $msg);
    }

    /**
     * MARKER-CLASSES-P2: take a membership's renewal at the register (pays at the
     * desk, or a card that failed). Opens the register with the renewal on
     * the sale; paying it renews the membership. Paying by card there also
     * saves that card for future renewals.
     */
    public function renewMembershipAtRegister(string $customerId, string $id)
    {
        $tenant = tenant();
        $m = TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customerId)->with('product')->findOrFail($id);
        $locationId = request()->session()->get('current_location_id');
        if (! $locationId) {
            return back()->with('error', 'Pick a register location first.');
        }
        $price = (int) ($m->renewal_price_cents ?? $m->product?->price_cents ?? 0);

        $draft = app(\App\Services\Tenant\SaleService::class)->saveDraft([
            'tenant_id'          => $tenant->id,
            'rang_up_by_user_id' => auth('tenant')->id(),
            'location_id'        => $locationId,
            'customer_id'        => $m->customer_id,
            'notes'              => null,
            'tip_cents'          => 0,
            'items'              => [[
                'type'             => 'class_pass',
                'quantity'         => 1,
                'unit_price_cents' => $price,
                'name_snapshot'    => ($m->product?->name ?? 'Membership') . ' · renewal',
                'class_pass'       => ['kind' => 'membership', 'product_id' => $m->product_id, 'renew_membership_id' => $m->id],
            ]],
        ]);

        return redirect()->route('tenant.register.index', ['draft' => $draft->id])
            ->with('success', 'Renewal is on the sale. Take payment to renew the membership.');
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
