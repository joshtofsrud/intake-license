<?php

namespace App\Http\Controllers\Tenant;

// MARKER-PORTAL-V2 — customer portal, section per page. Auth stays in
// CustomerAccountController; this only renders for a signed-in customer.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerAsset;
use App\Models\Tenant\TenantMessage;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantThread;
use App\Services\Tenant\InboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerPortalController extends Controller
{
    private function customer(): ?TenantCustomer
    {
        return Auth::guard('customer')->user();
    }

    /** Tenant-local "today" for naive appointment_date comparisons. */
    private function today(): string
    {
        return tnow()->toDateString();
    }

    // ---------------------------------------------------------------- home
    public function home()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();

        // MARKER-PORTAL-V2 — appointment_time is naive tenant-local wall
        // clock (PATCH-361): compare dates against tenant-local today, never
        // shift the time.
        $nextAppointment = $customer->appointments()
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->whereDate('appointment_date', '>=', $this->today())
            ->orderBy('appointment_date')->orderBy('appointment_time')
            ->first();

        $activeRental = TenantRental::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->where('status', 'out')
            ->orderBy('due_at')->first();

        $upcomingRental = TenantRental::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->where('status', 'reserved')
            ->orderBy('starts_at')->first();

        $lastOrder = \App\Models\Tenant\TenantOrder::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->whereNotNull('order_number')
            ->orderByDesc('created_at')->first();

        $lastSale = TenantSale::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->where('status', 'completed')->where('was_quote', false)
            ->orderByDesc('sale_date')->orderByDesc('created_at')->first();

        $thread = TenantThread::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->orderBy('created_at')->first();
        $lastMessage = $thread
            ? TenantMessage::where('thread_id', $thread->id)->where('kind', 'message')
                ->orderByDesc('created_at')->first()
            : null;

        return view('public.account.portal.home', compact(
            'customer', 'nextAppointment', 'activeRental', 'upcomingRental',
            'lastOrder', 'lastSale', 'lastMessage'
        ));
    }

    // ------------------------------------------------------------ bookings
    public function bookings()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();
        $today  = $this->today();

        $upcomingAppointments = $customer->appointments()
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->whereDate('appointment_date', '>=', $today)
            ->orderBy('appointment_date')->orderBy('appointment_time')
            ->limit(25)->get();

        // Past = terminal status, OR still-active but the date has gone by
        // (the stale-"upcoming" fix). Newest first.
        $pastAppointments = $customer->appointments()
            ->where(function ($q) use ($today) {
                $q->whereIn('status', ['completed', 'cancelled'])
                  ->orWhereDate('appointment_date', '<', $today);
            })
            ->orderByDesc('appointment_date')->orderByDesc('appointment_time')
            ->limit(25)->get();

        $upcomingClasses = collect();
        $pastClasses     = collect();
        if ($tenant->classes_enabled) {
            $upcomingClasses = $customer->classRegistrations()
                ->whereIn('status', ['registered', 'waitlisted'])
                ->with(['session.template'])
                ->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))
                ->orderBy('registered_at', 'desc')->limit(25)->get();

            // Any registration whose session has passed belongs in history —
            // including ones still marked 'registered' (they used to vanish).
            $pastClasses = $customer->classRegistrations()
                ->with(['session.template'])
                ->whereHas('session', fn ($q) => $q->where('starts_at', '<', now()))
                ->orderBy('registered_at', 'desc')->limit(25)->get();
        }

        return view('public.account.portal.bookings', compact(
            'customer', 'upcomingAppointments', 'pastAppointments',
            'upcomingClasses', 'pastClasses'
        ));
    }

    // -------------------------------------------------------------- orders
    public function orders()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();

        $onlineOrders = \App\Models\Tenant\TenantOrder::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->whereNotNull('order_number')
            ->with('items')
            ->orderByDesc('created_at')->limit(20)->get();

        $sales = TenantSale::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->where('status', 'completed')->where('was_quote', false)
            ->orderByDesc('sale_date')->orderByDesc('created_at')
            ->limit(25)->get();

        return view('public.account.portal.orders', compact('customer', 'onlineOrders', 'sales'));
    }

    // ------------------------------------------------------------- rentals
    public function rentals()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();

        $base = TenantRental::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->with('lines');

        $active   = (clone $base)->where('status', 'out')->orderBy('due_at')->get();
        $reserved = (clone $base)->where('status', 'reserved')->orderBy('starts_at')->get();
        $past     = (clone $base)->whereIn('status', ['returned', 'cancelled'])
            ->orderByDesc('returned_at')->orderByDesc('starts_at')->limit(15)->get();

        return view('public.account.portal.rentals', compact('customer', 'active', 'reserved', 'past'));
    }

    // ------------------------------------------------------------ messages
    public function messages()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();

        $thread = TenantThread::query()
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->orderBy('created_at')->first();

        // The customer sees their side of the SAME inbox thread: real
        // messages plus transactional records. Never internal notes or
        // system events.
        $messages = $thread
            ? TenantMessage::where('thread_id', $thread->id)
                ->whereIn('kind', ['message', 'transactional'])
                ->orderByDesc('created_at')->limit(100)->get()->reverse()->values()
            : collect();

        return view('public.account.portal.messages', compact('customer', 'thread', 'messages'));
    }

    public function messagesSend(Request $request, InboxService $inbox)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();

        $request->validate(['body' => ['required', 'string', 'max:1200']]);

        $thread = $inbox->threadFor($tenant, $customer, 'web');
        // postInbound flips the thread to needs_reply and bumps unread, so
        // this lands in the shop inbox exactly like a text or email would.
        $inbox->postInbound($thread, $request->input('body'), null, ['source' => 'portal'], 'web');

        return redirect()->route('tenant.customer.portal.messages')
            ->with('success', 'Message sent — we\'ll get back to you here.');
    }

    // ------------------------------------------------------------- account
    public function account()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }

        $assets = TenantCustomerAsset::query()
            ->where('tenant_id', tenant()->id)->where('customer_id', $customer->id)
            ->orderBy('name')->get();

        return view('public.account.portal.account', compact('customer', 'assets'));
    }

    public function accountUpdate(Request $request)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }

        // Email is deliberately not editable here — it is the login identity.
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name'  => ['required', 'string', 'max:80'],
            'phone'      => ['nullable', 'string', 'max:40'],
        ]);

        $customer->update($data);

        return redirect()->route('tenant.customer.portal.account')
            ->with('success', 'Profile updated.');
    }

    public function notificationsUpdate(Request $request)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }

        $wantsSms = $request->boolean('sms');

        if (! $wantsSms && $customer->sms_opt_out_at === null) {
            $customer->update(['sms_opt_out_at' => now()]);
            return redirect()->route('tenant.customer.portal.account')
                ->with('success', 'Text messages turned off.');
        }

        if ($wantsSms && $customer->sms_opt_out_at !== null) {
            // Carrier rules: STOP can only be undone by texting START.
            return redirect()->route('tenant.customer.portal.account')
                ->with('success', 'To turn texts back on, reply START to our last text — carriers require it come from your phone.');
        }

        return redirect()->route('tenant.customer.portal.account')
            ->with('success', 'Notification settings saved.');
    }
}
