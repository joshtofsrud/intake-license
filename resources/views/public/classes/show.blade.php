@extends('public.account._shell')
@php $pageTitle = $session->template->name; @endphp

@push('styles')
<style>
.cl-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;opacity:.55;margin-bottom:20px;transition:opacity .12s}
.cl-back:hover{opacity:1}
.cl-hero{background:var(--p-accent);color:var(--p-accent-text);border-radius:var(--p-r-lg);padding:22px;margin-bottom:16px}
.cl-hero-name{font-size:22px;font-weight:700;font-family:var(--p-font-heading);margin-bottom:4px}
.cl-hero-meta{font-size:14px;opacity:.75}
.cl-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px}
.cl-stat{background:var(--p-surface);border-radius:var(--p-r);padding:13px 15px}
.cl-stat-label{font-size:11px;opacity:.5;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.cl-stat-value{font-size:20px;font-weight:600}
.cl-desc{font-size:14px;opacity:.6;line-height:1.65;margin-bottom:20px}
.cl-section-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin-bottom:10px}
.cl-pay-option{display:flex;align-items:center;gap:12px;padding:13px 15px;border:1.5px solid var(--p-border);border-radius:var(--p-r-lg);margin-bottom:8px;cursor:pointer;transition:all .15s}
.cl-pay-option.selected{border-color:var(--p-accent)}
.cl-pay-radio{width:18px;height:18px;border-radius:50%;border:2px solid var(--p-border);flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .15s}
.cl-pay-option.selected .cl-pay-radio{border-color:var(--p-accent);background:var(--p-accent)}
.cl-pay-option.selected .cl-pay-radio::after{content:'';width:6px;height:6px;border-radius:50%;background:var(--p-accent-text)}
.cl-pay-name{font-size:14px;font-weight:500}
.cl-pay-sub{font-size:12px;opacity:.5;margin-top:1px}
.cl-pay-badge{margin-left:auto;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:500;background:#EAF3DE;color:#3B6D11;white-space:nowrap}
.cl-pay-price{margin-left:auto;font-size:14px;font-weight:600}
.cl-guest-fields{border:1.5px solid var(--p-border);border-radius:var(--p-r-lg);padding:16px;margin-bottom:16px}
.cl-guest-title{font-size:13px;font-weight:600;margin-bottom:12px}
.cl-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.cl-already{background:var(--p-surface);border-radius:var(--p-r-lg);padding:16px;text-align:center;margin-bottom:16px}
.cl-already-title{font-size:15px;font-weight:600;margin-bottom:4px}
.cl-already-sub{font-size:13px;opacity:.55}
.cl-waitlist-note{background:#FAEEDA;border-radius:var(--p-r);padding:12px 15px;font-size:13px;color:#633806;margin-bottom:16px}
.cl-cap-bar{height:4px;background:var(--p-border);border-radius:2px;overflow:hidden;margin-top:8px}
.cl-cap-fill{height:100%;border-radius:2px}
</style>
@endpush

@section('content')

<a href="{{ route('tenant.customer.classes', ['subdomain' => request()->route('subdomain')]) }}" class="cl-back">
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9 2L5 7l4 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Back to classes
</a>

@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

{{-- Hero --}}
@php
  $active = $session->active_registrations_count;
  $cap    = $session->capacity_snapshot;
  $pct    = $cap > 0 ? min(100, round($active / $cap * 100)) : 0;
@endphp
<div class="cl-hero">
  <div class="cl-hero-name">{{ $session->template->name }}</div>
  <div class="cl-hero-meta">
    {{ $session->starts_at->format('l, M j · g:i A') }} – {{ $session->ends_at->format('g:i A') }}
    @if($session->instructor_snapshot)
      · {{ $session->instructor_snapshot }}
    @endif
  </div>
</div>

<div class="cl-stats">
  <div class="cl-stat">
    <div class="cl-stat-label">{{ $isFull ? 'Waitlist' : 'Spots left' }}</div>
    <div class="cl-stat-value">{{ $isFull ? $session->waitlist_count : $spotsRemaining }}</div>
  </div>
  <div class="cl-stat">
    <div class="cl-stat-label">Duration</div>
    <div class="cl-stat-value">{{ $session->template->duration_minutes }}m</div>
  </div>
</div>

@if($session->template->description)
  <div class="cl-desc">{{ $session->template->description }}</div>
@endif

{{-- Already registered --}}
@if($existingRegistration)
  <div class="cl-already">
    <div class="cl-already-title">
      {{ $existingRegistration->status === 'waitlisted' ? "You're on the waitlist" : "You're registered!" }}
    </div>
    <div class="cl-already-sub">
      {{ $existingRegistration->status === 'waitlisted'
        ? "You're #" . $existingRegistration->waitlist_position . " in the queue. We'll notify you if a spot opens."
        : "See you " . $session->starts_at->format('M j') . " at " . $session->starts_at->format('g:i A') . "." }}
    </div>
    @if(in_array($existingRegistration->status, ['registered', 'waitlisted']))
      <form method="POST" action="{{ route('tenant.customer.classes.cancel', ['subdomain' => request()->route('subdomain'), 'id' => $existingRegistration->id]) }}"
            style="margin-top:12px" onsubmit="return confirm('Cancel your registration?')">
        @csrf
        <button type="submit" style="font-size:13px;color:#A32D2D;background:none;border:none;cursor:pointer;text-decoration:underline">Cancel registration</button>
      </form>
    @endif
  </div>

{{-- Waitlist --}}
@elseif($isFull)
  <div class="cl-waitlist-note">
    This class is full — you can join the waitlist and we'll notify you if a spot opens.
  </div>
  <form method="POST" action="{{ route('tenant.customer.classes.register', ['subdomain' => request()->route('subdomain'), 'id' => $session->id]) }}">
    @csrf
    <input type="hidden" name="payment_method" value="per_class">
    @if(!Auth::guard('customer')->check())
      <div class="cl-guest-fields">
        <div class="cl-guest-title">Your details</div>
        <div class="cl-row">
          <div class="ac-field">
            <label class="ac-label">First name</label>
            <input type="text" name="first_name" class="ac-input" required value="{{ old('first_name') }}">
          </div>
          <div class="ac-field">
            <label class="ac-label">Last name</label>
            <input type="text" name="last_name" class="ac-input" required value="{{ old('last_name') }}">
          </div>
        </div>
        <div class="ac-field">
          <label class="ac-label">Email</label>
          <input type="email" name="email" class="ac-input" required value="{{ old('email') }}" placeholder="For waitlist notifications">
        </div>
      </div>
    @endif
    <button type="submit" class="ac-btn ac-btn--primary">Join waitlist</button>
  </form>

{{-- Register --}}
@else
  <form method="POST" action="{{ route('tenant.customer.classes.register', ['subdomain' => request()->route('subdomain'), 'id' => $session->id]) }}" id="register-form">
    @csrf

    {{-- Payment options (only for logged-in customers with credits) --}}
    @if(Auth::guard('customer')->check() && ($activeMembership || $activePacks->isNotEmpty() || $session->template->price_cents > 0))
      <div class="cl-section-label" style="margin-bottom:10px">How would you like to pay?</div>

      @if($activeMembership && $activeMembership->canCoverClass())
        <label class="cl-pay-option selected" onclick="selectPay(this,'membership')">
          <div class="cl-pay-radio"></div>
          <div>
            <div class="cl-pay-name">{{ $activeMembership->product->name }}</div>
            <div class="cl-pay-sub">
              {{ $activeMembership->product->isUnlimited() ? 'Unlimited' : ($activeMembership->product->monthly_limit - $activeMembership->classes_used_this_period) . ' classes remaining' }}
              · renews {{ $activeMembership->current_period_end->format('M j') }}
            </div>
          </div>
          <span class="cl-pay-badge">Included</span>
        </label>
      @endif

      @foreach($activePacks as $pack)
        <label class="cl-pay-option {{ !$activeMembership ? 'selected' : '' }}" onclick="selectPay(this,'pack')">
          <div class="cl-pay-radio"></div>
          <div>
            <div class="cl-pay-name">{{ $pack->product->name }}</div>
            <div class="cl-pay-sub">{{ $pack->credits_remaining }} credits · expires {{ $pack->expires_at->format('M j') }}</div>
          </div>
          <span class="cl-pay-badge">Use 1 credit</span>
        </label>
      @endforeach

      @if($session->template->price_cents > 0)
        <label class="cl-pay-option {{ !$activeMembership && $activePacks->isEmpty() ? 'selected' : '' }}" onclick="selectPay(this,'per_class')">
          <div class="cl-pay-radio"></div>
          <div>
            <div class="cl-pay-name">Pay per class</div>
            <div class="cl-pay-sub">Charged at checkout</div>
          </div>
          <span class="cl-pay-price">${{ number_format($session->template->price_cents / 100, 2) }}</span>
        </label>
      @endif

      <input type="hidden" name="payment_method" id="payment_method_input" value="{{ $activeMembership ? 'membership' : ($activePacks->isNotEmpty() ? 'pack' : 'per_class') }}">

    @else
      <input type="hidden" name="payment_method" value="{{ $session->template->price_cents > 0 ? 'per_class' : 'cash' }}">
      @if($session->template->price_cents > 0)
        <div style="margin-bottom:16px;padding:13px 15px;background:var(--p-surface);border-radius:var(--p-r-lg);display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:14px;opacity:.7">Class price</span>
          <span style="font-size:18px;font-weight:700">${{ number_format($session->template->price_cents / 100, 2) }}</span>
        </div>
      @endif
    @endif

    {{-- Guest fields --}}
    @if(!Auth::guard('customer')->check())
      <div class="cl-guest-fields" style="margin-top:16px">
        <div class="cl-guest-title">Your details</div>
        <div class="cl-row">
          <div class="ac-field">
            <label class="ac-label">First name</label>
            <input type="text" name="first_name" class="ac-input" required value="{{ old('first_name') }}">
          </div>
          <div class="ac-field">
            <label class="ac-label">Last name</label>
            <input type="text" name="last_name" class="ac-input" required value="{{ old('last_name') }}">
          </div>
        </div>
        <div class="ac-field" style="margin-bottom:0">
          <label class="ac-label">Email</label>
          <input type="email" name="email" class="ac-input" required value="{{ old('email') }}" placeholder="For your confirmation">
        </div>
      </div>
      <div style="font-size:12px;opacity:.45;margin:10px 0 16px;text-align:center">
        Already have an account? <a href="{{ route('tenant.customer.login') }}" class="ac-link">Sign in</a> to use your membership or pack credits.
      </div>
    @endif

    <button type="submit" class="ac-btn ac-btn--primary" style="margin-top:{{ Auth::guard('customer')->check() ? '16px' : '0' }}">
      Reserve my spot
    </button>
  </form>
@endif

@endsection

@push('scripts')
<script>
function selectPay(el, method) {
  document.querySelectorAll('.cl-pay-option').forEach(function(o){ o.classList.remove('selected'); });
  el.classList.add('selected');
  document.getElementById('payment_method_input').value = method;
}
</script>
@endpush
