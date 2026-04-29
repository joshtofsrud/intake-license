@extends('public.account._shell')
@php $pageTitle = $registration->status === 'waitlisted' ? 'Waitlist confirmed' : 'Registration confirmed'; @endphp

@push('styles')
<style>
.cl-confirm-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.cl-confirm-icon.success{background:#EAF3DE}
.cl-confirm-icon.waitlist{background:#FAEEDA}
.cl-confirm-title{font-size:22px;font-weight:700;font-family:var(--p-font-heading);text-align:center;margin-bottom:6px}
.cl-confirm-sub{font-size:14px;opacity:.55;text-align:center;margin-bottom:28px;line-height:1.6}
.cl-confirm-card{background:var(--p-surface);border-radius:var(--p-r-lg);padding:16px;margin-bottom:20px}
.cl-confirm-row{display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--p-border);font-size:14px}
.cl-confirm-row:last-child{border-bottom:none}
.cl-confirm-label{opacity:.55}
.cl-confirm-value{font-weight:500;text-align:right;max-width:60%}
.cl-waitlist-card{background:#FAEEDA;border-radius:var(--p-r-lg);padding:16px;margin-bottom:20px;font-size:14px;color:#633806;line-height:1.6}
.cl-waitlist-pos{font-size:32px;font-weight:700;text-align:center;margin-bottom:4px}
.cl-waitlist-label{text-align:center;font-size:13px;opacity:.75}
</style>
@endpush

@section('content')

@php $isWaitlisted = $registration->status === 'waitlisted'; @endphp

<div style="padding:8px 0">
  <div class="cl-confirm-icon {{ $isWaitlisted ? 'waitlist' : 'success' }}">
    @if($isWaitlisted)
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M12 6v6l4 2" stroke="#BA7517" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="9" stroke="#BA7517" stroke-width="2"/></svg>
    @else
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#3B6D11" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    @endif
  </div>

  <div class="cl-confirm-title">
    {{ $isWaitlisted ? "You're on the waitlist" : "You're registered!" }}
  </div>
  <div class="cl-confirm-sub">
    @if($isWaitlisted)
      We'll send you an email at <strong>{{ $registration->customer->email }}</strong> if a spot opens up.
    @else
      A confirmation has been sent to <strong>{{ $registration->customer->email }}</strong>.
    @endif
  </div>

  @if($isWaitlisted)
    <div class="cl-waitlist-card">
      <div class="cl-waitlist-pos">#{{ $registration->waitlist_position }}</div>
      <div class="cl-waitlist-label">Your position in the queue</div>
    </div>
  @endif

  <div class="cl-confirm-card">
    <div class="cl-confirm-row">
      <div class="cl-confirm-label">Class</div>
      <div class="cl-confirm-value">{{ $registration->session->template->name }}</div>
    </div>
    <div class="cl-confirm-row">
      <div class="cl-confirm-label">Date</div>
      <div class="cl-confirm-value">{{ $registration->session->starts_at->format('l, M j, Y') }}</div>
    </div>
    <div class="cl-confirm-row">
      <div class="cl-confirm-label">Time</div>
      <div class="cl-confirm-value">{{ $registration->session->starts_at->format('g:i A') }} – {{ $registration->session->ends_at->format('g:i A') }}</div>
    </div>
    @if($registration->session->instructor_snapshot)
      <div class="cl-confirm-row">
        <div class="cl-confirm-label">Instructor</div>
        <div class="cl-confirm-value">{{ $registration->session->instructor_snapshot }}</div>
      </div>
    @endif
    <div class="cl-confirm-row">
      <div class="cl-confirm-label">Payment</div>
      <div class="cl-confirm-value">{{ ucfirst(str_replace('_', ' ', $registration->payment_method)) }}</div>
    </div>
    <div class="cl-confirm-row">
      <div class="cl-confirm-label">Name</div>
      <div class="cl-confirm-value">{{ $registration->customer->fullName() }}</div>
    </div>
  </div>

  <a href="{{ route('tenant.customer.classes', ['subdomain' => request()->route('subdomain')]) }}"
     class="ac-btn ac-btn--primary" style="display:block;text-align:center;padding:13px;border-radius:var(--p-r);font-weight:600;font-size:15px;margin-bottom:10px">
    Browse more classes
  </a>

  @if(Auth::guard('customer')->check())
    <a href="{{ route('tenant.customer.portal') }}"
       class="ac-btn ac-btn--ghost" style="display:block;text-align:center;padding:13px;border-radius:var(--p-r);font-weight:600;font-size:15px;border:1.5px solid var(--p-border)">
      My account
    </a>
  @else
    <a href="{{ route('tenant.customer.register') }}"
       class="ac-btn ac-btn--ghost" style="display:block;text-align:center;padding:13px;border-radius:var(--p-r);font-weight:600;font-size:15px;border:1.5px solid var(--p-border)">
      Create account to manage bookings
    </a>
  @endif
</div>

@endsection
