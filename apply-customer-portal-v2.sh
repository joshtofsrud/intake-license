#!/usr/bin/env bash
set -euo pipefail
# apply-customer-portal-v2.sh — MARKER-PORTAL-V2
# The approved customer-portal redesign, built on backends that already exist:
#   Home     — next-appointment hero (real local time), "Rental due" banner
#              (red when overdue), "Upcoming rental" banner, recent activity,
#              quick actions
#   Bookings — audit fixes 1–5: appointment_time rendered (naive local, no tz
#              shift), tlocal() on class instants, date-aware upcoming/history,
#              vanished 'registered'-past classes land in History, real counts
#   Orders   — online orders + NEW in-store purchases (tenant_sales)
#   Rentals  — active (waiver/deposit/due), reserved, past
#   Messages — the customer side of the SAME unified-inbox thread; sending
#              posts inbound ('web') so staff see it as needs-reply
#   Account  — saved assets (tenant asset noun), profile edit (email locked),
#              SMS notification state (STOP-compliant: portal can opt out,
#              only texting START can opt back in)
# Gift cards tab is deliberately absent until the gift-card ledger exists.
# Old portal() + portal.blade.php stay in place, unused (route repointed).

CTRL=app/Http/Controllers/Tenant/CustomerPortalController.php
ROUTES=routes/web.php
VDIR=resources/views/public/account/portal

[ -f "$ROUTES" ] || { echo "MISSING $ROUTES — run from the repo root"; exit 1; }

if grep -q "MARKER-PORTAL-V2" "$ROUTES"; then
  echo "Already applied (MARKER-PORTAL-V2 present) — no-op."
  exit 0
fi

mkdir -p "$VDIR"

# ================================================================ controller
cat <<'EOF' > "$CTRL"
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
EOF
echo "ok   controller created"

# ================================================================ views
cat <<'EOF' > "$VDIR/_nav.blade.php"
{{-- MARKER-PORTAL-V2 — portal section nav (underline pattern). $active = key --}}
<div class="ac-nav">
  <a href="{{ route('tenant.customer.portal') }}" class="{{ $active === 'home' ? 'on' : '' }}">Home</a>
  <a href="{{ route('tenant.customer.portal.bookings') }}" class="{{ $active === 'bookings' ? 'on' : '' }}">Bookings</a>
  <a href="{{ route('tenant.customer.portal.orders') }}" class="{{ $active === 'orders' ? 'on' : '' }}">Orders</a>
  <a href="{{ route('tenant.customer.portal.rentals') }}" class="{{ $active === 'rentals' ? 'on' : '' }}">Rentals</a>
  <a href="{{ route('tenant.customer.portal.messages') }}" class="{{ $active === 'messages' ? 'on' : '' }}">Messages</a>
  <a href="{{ route('tenant.customer.portal.account') }}" class="{{ $active === 'account' ? 'on' : '' }}">Account</a>
</div>
EOF
echo "ok   _nav view"

cat <<'EOF' > "$VDIR/_portal-css.blade.php"
{{-- MARKER-PORTAL-V2 — shared portal styles, pushed by each portal view --}}
<style>
.ac-nav{display:flex;gap:2px;border-bottom:1px solid var(--p-border);margin:-6px 0 26px;overflow-x:auto}
.ac-nav a{padding:9px 13px;font-size:13.5px;opacity:.45;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ac-nav a:hover{opacity:.75}
.ac-nav a.on{opacity:1;border-bottom-color:var(--p-accent);font-weight:600}
.ac-hero{background:var(--p-accent);color:var(--p-accent-text);border-radius:var(--p-r-lg);padding:20px;margin-bottom:12px}
.ac-hero-k{font-size:11px;text-transform:uppercase;letter-spacing:.07em;opacity:.65;margin-bottom:4px}
.ac-hero-t{font-size:19px;font-weight:700}
.ac-hero-m{font-size:13.5px;opacity:.8;margin-top:2px}
.ac-hero-actions{display:flex;gap:8px;margin-top:14px}
.ac-hero-actions a{font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:20px;background:rgba(0,0,0,.14)}
.ac-banner{display:flex;justify-content:space-between;align-items:center;gap:12px;border-radius:var(--p-r-lg);padding:13px 16px;margin-bottom:12px;font-size:13.5px;border:1px solid}
.ac-banner--due{background:#FAEEDA;border-color:rgba(99,56,6,.25);color:#633806}
.ac-banner--overdue{background:#FCEBEB;border-color:rgba(163,45,45,.3);color:#A32D2D}
.ac-banner--soon{background:#EAF3DE;border-color:rgba(59,109,17,.25);color:#3B6D11}
.ac-banner b{font-weight:700}
.ac-banner a{font-weight:600;white-space:nowrap;border-bottom:1px solid currentColor}
.ac-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0 22px}
.ac-chip-card{border:1px solid var(--p-border);border-radius:var(--p-r);padding:12px 14px}
.ac-chip-k{font-size:11px;opacity:.5}
.ac-chip-v{font-size:16px;font-weight:700;margin-top:1px}
.ac-chip-s{font-size:11px;opacity:.45;margin-top:1px}
.ac-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-top:6px}
.ac-quick a{border:1.5px solid var(--p-border);border-radius:var(--p-r);padding:13px 10px;text-align:center;font-weight:600;font-size:13.5px}
.ac-msg-wrap{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:14px}
.ac-msgs{padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--p-surface);max-height:480px;overflow-y:auto}
.ac-msg{max-width:78%;padding:9px 12px;border-radius:12px;font-size:13.5px;line-height:1.45;background:var(--p-bg);border:1px solid var(--p-border);word-break:break-word;white-space:pre-wrap}
.ac-msg.me{align-self:flex-end;background:var(--p-accent);color:var(--p-accent-text);border-color:transparent;border-bottom-right-radius:4px}
.ac-msg.shop{align-self:flex-start;border-bottom-left-radius:4px}
.ac-msg.txn{align-self:stretch;max-width:none;background:transparent;border-style:dashed;font-size:12px;opacity:.65;white-space:normal}
.ac-msg-t{font-size:10px;opacity:.5;margin-top:4px;white-space:normal}
.ac-chan{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.07em;padding:1px 5px;border-radius:4px;background:rgba(0,0,0,.1);margin-right:5px;vertical-align:1px}
.ac-compose{display:flex;gap:8px;padding:12px;border-top:1px solid var(--p-border)}
.ac-compose textarea{flex:1;padding:10px 13px;border:1.5px solid var(--p-border);border-radius:14px;font-family:inherit;font-size:14px;background:transparent;color:inherit;resize:none}
.ac-compose button{border:0;background:var(--p-accent);color:var(--p-accent-text);border-radius:50%;width:42px;height:42px;font-size:16px;font-weight:700;flex:0 0 auto;align-self:flex-end}
</style>
EOF
echo "ok   _portal-css view"

cat <<'EOF' > "$VDIR/home.blade.php"
@extends('public.account._shell')
@php $pageTitle = 'My Account'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'home'])

<div style="margin-bottom:16px">
  <div style="font-size:22px;font-weight:700;font-family:var(--p-font-heading)">Hi, {{ $customer->first_name }}</div>
  <div style="font-size:14px;opacity:.45;margin-top:2px">{{ $customer->email }}</div>
</div>

@if($nextAppointment)
  @php
    /* appointment_date + appointment_time are naive tenant-local — format,
       never convert (PATCH-361). */
    $naDate = \Carbon\Carbon::parse($nextAppointment->appointment_date)->format('D, M j');
    $naTime = $nextAppointment->appointment_time
        ? \Carbon\Carbon::parse($nextAppointment->appointment_time)->format('g:i A')
        : null;
  @endphp
  <div class="ac-hero">
    <div class="ac-hero-k">Next up</div>
    <div class="ac-hero-t">{{ $nextAppointment->label ?? 'Appointment' }}</div>
    <div class="ac-hero-m">{{ $naDate }}@if($naTime) &middot; {{ $naTime }}@endif</div>
    <div class="ac-hero-actions">
      <a href="{{ route('tenant.customer.portal.messages') }}">Message the shop</a>
      <a href="{{ route('tenant.customer.portal.bookings') }}">All bookings</a>
    </div>
  </div>
@endif

{{-- MARKER-PORTAL-V2 — rental banners --}}
@if($activeRental)
  @php $overdue = $activeRental->due_at && $activeRental->due_at->isPast(); @endphp
  <div class="ac-banner {{ $overdue ? 'ac-banner--overdue' : 'ac-banner--due' }}">
    <span>
      @if($overdue)
        <b>Rental overdue</b> — {{ $activeRental->lines->first()?->name_snapshot ?? 'your rental' }} was due {{ tlocal_datetime($activeRental->due_at, 'D, M j \a\t g:i A') }}
      @else
        <b>Rental due</b> {{ tlocal_datetime($activeRental->due_at, 'D, M j \a\t g:i A') }} — {{ $activeRental->lines->first()?->name_snapshot ?? 'your rental' }}
      @endif
    </span>
    <a href="{{ route('tenant.customer.portal.rentals') }}">Details</a>
  </div>
@endif

@if($upcomingRental)
  <div class="ac-banner ac-banner--soon">
    <span><b>Upcoming rental</b> — {{ $upcomingRental->lines->first()?->name_snapshot ?? 'reserved' }} starts {{ tlocal_datetime($upcomingRental->starts_at, 'D, M j \a\t g:i A') }}</span>
    <a href="{{ route('tenant.customer.portal.rentals') }}">Details</a>
  </div>
@endif

<div class="ac-strip">
  @if($activeRental)
    <div class="ac-chip-card"><div class="ac-chip-k">Active rental</div>
      <div class="ac-chip-v">{{ $activeRental->lines->count() }} {{ \Illuminate\Support\Str::plural('item', $activeRental->lines->count()) }}</div>
      <div class="ac-chip-s">due {{ tlocal($activeRental->due_at, 'D, M j') }}</div></div>
  @endif
  @if($lastMessage)
    <div class="ac-chip-card"><div class="ac-chip-k">Messages</div>
      <div class="ac-chip-v">{{ $lastMessage->direction === 'in' ? 'You wrote' : tenant()->name }}</div>
      <div class="ac-chip-s">{{ tlocal_datetime($lastMessage->created_at, 'M j, g:i A') }}</div></div>
  @endif
</div>

<div class="ac-section-title">Recent activity</div>
<div class="ac-list">
  @if($lastOrder)
    <div class="ac-list-row">
      <div><div class="ac-list-name">Order {{ $lastOrder->order_number }}</div>
        <div class="ac-list-meta">{{ tlocal_date($lastOrder->created_at) }} &middot; {{ $lastOrder->fulfillment_type === 'local_delivery' ? 'delivery' : 'pickup' }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($lastOrder->total_cents / 100, 2) }}</div>
        <div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $lastOrder->status) }}</div></div>
    </div>
  @endif
  @if($lastSale)
    <div class="ac-list-row">
      <div><div class="ac-list-name">In-store purchase {{ $lastSale->sale_number ? '#' . $lastSale->sale_number : '' }}</div>
        <div class="ac-list-meta">{{ \Carbon\Carbon::parse($lastSale->sale_date)->format('M j, Y') }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($lastSale->total_cents / 100, 2) }}</div></div>
    </div>
  @endif
  @if(!$lastOrder && !$lastSale)
    <div class="ac-empty">Nothing here yet.</div>
  @endif
</div>

<div class="ac-quick">
  <a href="{{ route('tenant.booking') }}">Book service</a>
  <a href="{{ route('tenant.shop.index') }}">Shop online</a>
  <a href="{{ route('tenant.customer.portal.messages') }}">Message us</a>
</div>
@endsection
EOF
echo "ok   home view"

cat <<'EOF' > "$VDIR/bookings.blade.php"
@extends('public.account._shell')
@php $pageTitle = 'Bookings'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'bookings'])

<div class="ac-section-title">Upcoming</div>
<div class="ac-list">
  @forelse($upcomingAppointments as $appt)
    @php
      $adDate = \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j');
      $adTime = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') : null;
    @endphp
    <div class="ac-list-row">
      <div><div class="ac-list-name">{{ $appt->label ?? 'Appointment' }}</div>
        <div class="ac-list-meta">{{ $adDate }}@if($adTime) &middot; {{ $adTime }}@endif</div></div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $appt->status }}">{{ ucfirst(str_replace('_', ' ', $appt->status)) }}</span></div>
    </div>
  @empty
    <div class="ac-empty">No upcoming appointments</div>
  @endforelse
</div>

@if($upcomingClasses->isNotEmpty())
  <div class="ac-section-title">Upcoming classes</div>
  <div class="ac-list">
    @foreach($upcomingClasses as $reg)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $reg->session->template->name }}</div>
          <div class="ac-list-meta">{{ tlocal($reg->session->starts_at, 'D, M j · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $reg->status }}">{{ ucfirst($reg->status) }}</span>
          @if($reg->status === 'waitlisted')<div style="font-size:11px;opacity:.4;margin-top:3px">#{{ $reg->waitlist_position }} in queue</div>@endif</div>
      </div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">History</div>
<div class="ac-list">
  @forelse($pastAppointments as $appt)
    @php
      $pdDate = \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j, Y');
      $pdTime = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') : null;
      $pdLabel = in_array($appt->status, ['completed', 'cancelled'], true)
          ? ucfirst($appt->status) : 'Past';
      $pdPill = in_array($appt->status, ['completed', 'cancelled'], true) ? $appt->status : 'completed';
    @endphp
    <div class="ac-list-row">
      <div><div class="ac-list-name">{{ $appt->label ?? 'Appointment' }}</div>
        <div class="ac-list-meta">{{ $pdDate }}@if($pdTime) &middot; {{ $pdTime }}@endif</div></div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $pdPill }}">{{ $pdLabel }}</span></div>
    </div>
  @empty
    <div class="ac-empty">No past appointments</div>
  @endforelse
</div>

@if($pastClasses->isNotEmpty())
  <div class="ac-section-title">Past classes</div>
  <div class="ac-list">
    @foreach($pastClasses as $reg)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $reg->session->template->name }}</div>
          <div class="ac-list-meta">{{ tlocal($reg->session->starts_at, 'D, M j, Y · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $reg->status === 'registered' ? 'completed' : $reg->status }}">{{ $reg->status === 'checked_in' ? 'Attended' : ucfirst(str_replace('_', ' ', $reg->status)) }}</span></div>
      </div>
    @endforeach
  </div>
@endif

<a href="{{ route('tenant.booking') }}" class="ac-btn ac-btn--primary" style="text-decoration:none">Book an appointment</a>
@endsection
EOF
echo "ok   bookings view"

cat <<'EOF' > "$VDIR/orders.blade.php"
@extends('public.account._shell')
@php $pageTitle = 'Orders'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'orders'])

<div class="ac-section-title">Online orders</div>
<div class="ac-list">
  @forelse($onlineOrders as $o)
    <a class="ac-list-row" href="{{ route('tenant.order.confirmation', $o->token) }}" style="text-decoration:none">
      <div><div class="ac-list-name">{{ $o->order_number }}</div>
        <div class="ac-list-meta">{{ (int) $o->items->sum('quantity') }} {{ \Illuminate\Support\Str::plural('item', (int) $o->items->sum('quantity')) }} &middot; {{ $o->fulfillment_type === 'local_delivery' ? 'delivery' : 'pickup' }} &middot; {{ tlocal_date($o->created_at) }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($o->total_cents / 100, 2) }}</div>
        <div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $o->status) }}</div></div>
    </a>
  @empty
    <div class="ac-empty">No online orders yet</div>
  @endforelse
</div>

{{-- MARKER-PORTAL-V2 — in-store purchase history off tenant_sales --}}
<div class="ac-section-title">In-store purchases</div>
<div class="ac-list">
  @forelse($sales as $s)
    <div class="ac-list-row">
      <div><div class="ac-list-name">Receipt {{ $s->sale_number ? '#' . $s->sale_number : '' }}</div>
        <div class="ac-list-meta">{{ \Carbon\Carbon::parse($s->sale_date)->format('M j, Y') }}@if($s->payment_method) &middot; {{ str_replace('_', ' ', $s->payment_method) }}@endif</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($s->total_cents / 100, 2) }}</div>
        @if($s->payment_status && $s->payment_status !== 'paid')<div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $s->payment_status) }}</div>@endif</div>
    </div>
  @empty
    <div class="ac-empty">No in-store purchases yet</div>
  @endforelse
</div>
@endsection
EOF
echo "ok   orders view"

cat <<'EOF' > "$VDIR/rentals.blade.php"
@extends('public.account._shell')
@php $pageTitle = 'Rentals'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'rentals'])

@if($active->isNotEmpty())
  <div class="ac-section-title">Active</div>
  @foreach($active as $r)
    @php $overdue = $r->due_at && $r->due_at->isPast(); @endphp
    <div class="ac-card" style="padding:20px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div>
          <div style="font-weight:700;font-size:16px">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}@if($r->lines->count() > 1) <span style="opacity:.5;font-weight:500">+ {{ $r->lines->count() - 1 }} more</span>@endif</div>
          <div class="ac-list-meta" style="margin-top:3px">{{ $r->rental_number }} &middot; out since {{ tlocal_datetime($r->checked_out_at ?? $r->starts_at, 'D, M j · g:i A') }}</div>
        </div>
        <span class="ac-pill {{ $overdue ? 'ac-pill--refunded' : 'ac-pill--due' }}">{{ $overdue ? 'Overdue' : 'Due ' . tlocal($r->due_at, 'D, M j') }}</span>
      </div>
      <div style="display:flex;gap:18px;margin-top:14px;font-size:13px;flex-wrap:wrap">
        <div><div class="ac-chip-k">Due back</div><div style="font-weight:600">{{ tlocal_datetime($r->due_at, 'D, M j · g:i A') }}</div></div>
        <div><div class="ac-chip-k">Waiver</div><div style="font-weight:600">{{ $r->agreement_signed_at ? 'Signed ✓' : 'Not signed' }}</div></div>
        @if($r->deposit_hold_cents)
          <div><div class="ac-chip-k">Deposit hold</div><div style="font-weight:600">${{ number_format($r->deposit_hold_cents / 100, 2) }}</div></div>
        @endif
        <div><div class="ac-chip-k">Total</div><div style="font-weight:600">${{ number_format($r->total_cents / 100, 2) }}</div></div>
      </div>
      <div style="margin-top:16px">
        <a href="{{ route('tenant.customer.portal.messages') }}" class="ac-btn ac-btn--ghost" style="padding:10px;font-size:13.5px;text-decoration:none">Ask about this rental</a>
      </div>
    </div>
  @endforeach
@endif

@if($reserved->isNotEmpty())
  <div class="ac-section-title">Reserved</div>
  <div class="ac-list">
    @foreach($reserved as $r)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}</div>
          <div class="ac-list-meta">starts {{ tlocal_datetime($r->starts_at, 'D, M j · g:i A') }} &middot; back {{ tlocal_datetime($r->due_at, 'D, M j · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--pending">Reserved</span></div>
      </div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">Past rentals</div>
<div class="ac-list">
  @forelse($past as $r)
    <div class="ac-list-row">
      <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}</div>
        <div class="ac-list-meta">{{ tlocal_date($r->starts_at) }}@if($r->returned_at) &ndash; {{ tlocal_date($r->returned_at) }}@endif</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($r->total_cents / 100, 2) }}</div>
        <span class="ac-pill ac-pill--{{ $r->status === 'returned' ? 'returned' : 'cancelled' }}">{{ ucfirst($r->status) }}</span></div>
    </div>
  @empty
    <div class="ac-empty">No rentals yet</div>
  @endforelse
</div>
@endsection
EOF
echo "ok   rentals view"

cat <<'EOF' > "$VDIR/messages.blade.php"
@extends('public.account._shell')
@php $pageTitle = 'Messages'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'messages'])

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

<div class="ac-section-title">{{ $currentTenant->name }}</div>
<div class="ac-msg-wrap">
  <div class="ac-msgs" id="ac-msgs">
    @forelse($messages as $m)
      @if($m->kind === 'transactional')
        <div class="ac-msg txn">{{ $m->body }}
          <div class="ac-msg-t">{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div></div>
      @else
        <div class="ac-msg {{ $m->direction === 'in' ? 'me' : 'shop' }}">{{ $m->body }}
          <div class="ac-msg-t"><span class="ac-chan">{{ strtoupper($m->channel ?? 'web') }}</span>{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div></div>
      @endif
    @empty
      <div class="ac-empty" style="background:transparent">No messages yet &mdash; say hi below.</div>
    @endforelse
  </div>
  <form method="POST" action="{{ route('tenant.customer.portal.messages.send') }}" class="ac-compose">
    @csrf
    <textarea name="body" rows="2" maxlength="1200" required placeholder="Message {{ $currentTenant->name }}&hellip;"></textarea>
    <button type="submit" aria-label="Send">&uarr;</button>
  </form>
</div>
<div style="font-size:12px;opacity:.45">Replies land here and by text or email &mdash; same conversation either way.</div>

<script>
  (function () { var m = document.getElementById('ac-msgs'); if (m) { m.scrollTop = m.scrollHeight; } })();
</script>
@endsection
EOF
echo "ok   messages view"

cat <<'EOF' > "$VDIR/account.blade.php"
@extends('public.account._shell')
@php $pageTitle = 'Account'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'account'])

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif

@php $assetPlural = $currentTenant->asset_label_plural ?: 'items'; @endphp
@if($assets->isNotEmpty())
  <div class="ac-section-title">Your {{ $assetPlural }}</div>
  <div class="ac-list">
    @foreach($assets as $a)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $a->name }}</div>
          @if($a->identifier)<div class="ac-list-meta">{{ $a->identifier }}</div>@endif</div>
      </div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">Profile</div>
<div class="ac-card" style="padding:20px;margin-bottom:22px">
  <form method="POST" action="{{ route('tenant.customer.portal.profile') }}">
    @csrf
    <div class="ac-row">
      <div class="ac-field"><label class="ac-label">First name</label>
        <input class="ac-input" name="first_name" value="{{ old('first_name', $customer->first_name) }}" required></div>
      <div class="ac-field"><label class="ac-label">Last name</label>
        <input class="ac-input" name="last_name" value="{{ old('last_name', $customer->last_name) }}" required></div>
    </div>
    <div class="ac-field"><label class="ac-label">Phone</label>
      <input class="ac-input" name="phone" value="{{ old('phone', $customer->phone) }}"></div>
    <div class="ac-field" style="margin-bottom:18px"><label class="ac-label">Email</label>
      <input class="ac-input" value="{{ $customer->email }}" disabled style="opacity:.55">
      <div style="font-size:12px;opacity:.45;margin-top:5px">Your email is your sign-in &mdash; message us to change it.</div></div>
    <button type="submit" class="ac-btn ac-btn--primary" style="max-width:200px;padding:11px">Save changes</button>
  </form>
</div>

<div class="ac-section-title">Notifications</div>
<div class="ac-card" style="padding:20px">
  <form method="POST" action="{{ route('tenant.customer.portal.notifications') }}">
    @csrf
    <div class="ac-check-row" style="margin-bottom:14px">
      <input type="checkbox" name="sms" value="1" id="n-sms" {{ $customer->sms_opt_out_at ? '' : 'checked' }}>
      <label for="n-sms">Text me confirmations and reminders</label>
    </div>
    @if($customer->sms_opt_out_at)
      <div style="font-size:12.5px;opacity:.55;margin:-6px 0 14px">You texted STOP &mdash; reply START to our last text to turn these back on. Carriers require it come from your phone.</div>
    @endif
    <button type="submit" class="ac-btn ac-btn--ghost" style="max-width:200px;padding:10px;font-size:13.5px">Save notifications</button>
  </form>
</div>
@endsection
EOF
echo "ok   account view"

# ================================================================ routes
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """    Route::get('/account',               [TenantControllers\\CustomerAccountController::class, 'portal'])->name('tenant.customer.portal');"""
new = """    // MARKER-PORTAL-V2 — portal sections. The old single-page portal() is
    // superseded; tenant.customer.portal now lands on Home so every existing
    // login/redirect keeps working.
    Route::get('/account',                [TenantControllers\\CustomerPortalController::class, 'home'])->name('tenant.customer.portal');
    Route::get('/account/bookings',       [TenantControllers\\CustomerPortalController::class, 'bookings'])->name('tenant.customer.portal.bookings');
    Route::get('/account/orders',         [TenantControllers\\CustomerPortalController::class, 'orders'])->name('tenant.customer.portal.orders');
    Route::get('/account/rentals',        [TenantControllers\\CustomerPortalController::class, 'rentals'])->name('tenant.customer.portal.rentals');
    Route::get('/account/messages',       [TenantControllers\\CustomerPortalController::class, 'messages'])->name('tenant.customer.portal.messages');
    Route::post('/account/messages',      [TenantControllers\\CustomerPortalController::class, 'messagesSend'])->name('tenant.customer.portal.messages.send');
    Route::post('/account/profile',       [TenantControllers\\CustomerPortalController::class, 'accountUpdate'])->name('tenant.customer.portal.profile');
    Route::get('/account/settings',       [TenantControllers\\CustomerPortalController::class, 'account'])->name('tenant.customer.portal.account');
    Route::post('/account/notifications', [TenantControllers\\CustomerPortalController::class, 'notificationsUpdate'])->name('tenant.customer.portal.notifications');"""
n = src.count(old)
if n != 1:
    print(f"FAIL portal routes: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   portal routes")

open(path, 'w').write(src)
PY

php -l "$CTRL"

echo ""
echo "SUCCESS — apply-customer-portal-v2 applied."
echo "Deploy's optimize covers route + view cache."
