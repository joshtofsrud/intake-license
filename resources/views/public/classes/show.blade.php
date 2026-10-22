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

<a href="{{ route('tenant.customer.classes', []) }}" class="cl-back">
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
    {{ tlocal($session->starts_at, 'l, M j · g:i A') }} – {{ tlocal($session->ends_at) }}
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
        : "See you " . tlocal($session->starts_at, 'M j') . " at " . tlocal($session->starts_at) . "." }}
    </div>
    @if(in_array($existingRegistration->status, ['registered', 'waitlisted']))
      <form method="POST" action="{{ route('tenant.customer.classes.cancel', ['id' => $existingRegistration->id]) }}"
            style="margin-top:12px">
        @csrf
        {{-- MARKER-CLASSES-P0: in-page confirm instead of the browser's confirm() --}}
        <button type="button" onclick="this.hidden=true;this.nextElementSibling.hidden=false" style="font-size:13px;color:#A32D2D;background:none;border:none;cursor:pointer;text-decoration:underline">Cancel registration</button>
        <span hidden style="font-size:13px">Cancel this booking?
          <button type="submit" style="font-size:13px;color:#A32D2D;background:none;border:none;cursor:pointer;text-decoration:underline;margin-left:6px">Yes, cancel</button>
          <button type="button" onclick="this.parentElement.hidden=true;this.parentElement.previousElementSibling.hidden=false" style="font-size:13px;background:none;border:none;cursor:pointer;opacity:.7;margin-left:6px">Keep it</button>
        </span>
      </form>
    @endif
  </div>

{{-- Started: no booking (MARKER-CLASSES-P0) --}}
@elseif($session->starts_at->isPast())
  <div class="cl-waitlist-note">This class has already started. Pick another time from the schedule.</div>

{{-- Waitlist --}}
@elseif($isFull)
  <div class="cl-waitlist-note">
    This class is full — you can join the waitlist and we'll notify you if a spot opens.
  </div>
  <form method="POST" action="{{ route('tenant.customer.classes.register', ['id' => $session->id]) }}">
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

{{-- Register. MARKER-CLASSES-P3: passes they own first, then the intro
     offer and paying online, then paying at the studio, as the studio allows. --}}
@else
  @php
    $signedIn = Auth::guard('customer')->check();
    $price    = (int) $session->template->price_cents;
    $opts     = [];
    if ($signedIn && $coverMemberships->isNotEmpty()) {
        $m = $coverMemberships->first();
        $opts[] = ['key' => 'membership', 'via' => 'form', 'name' => $m->product->name,
            'sub' => ($m->product->isUnlimited() ? 'Unlimited' : max(0, $m->product->monthly_limit - $m->classes_used_this_period) . ' classes left')
                . ' · renews ' . $m->current_period_end?->copy()->addDay()->format('M j'),
            'right' => '<span class="cl-pay-badge">Included</span>', 'btn' => 'Reserve my spot'];
    }
    if ($signedIn && $coverPacks->isNotEmpty()) {
        $pk = $coverPacks->first();
        $opts[] = ['key' => 'pack', 'via' => 'form', 'name' => $pk->product->name,
            'sub' => $pk->credits_remaining . ' credits · expires ' . $pk->expires_at->format('M j'),
            'right' => '<span class="cl-pay-badge">Use 1 credit</span>', 'btn' => 'Reserve my spot'];
    }
    if ($introOffer) {
        $opts[] = ['key' => 'intro', 'via' => 'card', 'name' => $introOffer->name . ' · new here',
            'sub' => $introOffer->credit_count . ' classes in ' . $introOffer->expiry_days . ' days, this one included',
            'right' => '<span class="cl-pay-price">' . e(format_money((int) $introOffer->price_cents)) . '</span>',
            'btn' => 'Pay ' . format_money($introQuote['total']) . ' and book', 'amount' => $introQuote['total'],
            'product' => $introOffer->id];
    }
    if ($price > 0 && $cardsOn && in_array($dropinMode, ['both', 'card'], true)) {
        $opts[] = ['key' => 'dropin', 'via' => 'card', 'name' => 'Drop in', 'sub' => 'Pay for this class now',
            'right' => '<span class="cl-pay-price">' . e(format_money($price)) . '</span>',
            'btn' => 'Pay ' . format_money($dropinQuote['total']) . ' and book', 'amount' => $dropinQuote['total']];
    }
    if ($price > 0 && in_array($dropinMode, ['both', 'desk'], true)) {
        $opts[] = ['key' => 'per_class', 'via' => 'form', 'name' => 'Reserve, pay at the studio',
            'sub' => 'Pay when you arrive', 'right' => '<span class="cl-pay-price">' . e(format_money($price)) . '</span>',
            'btn' => 'Reserve, pay at the studio'];
    }
    if ($price <= 0 && $coverMemberships->isEmpty() && $coverPacks->isEmpty()) {
        $opts[] = ['key' => 'cash', 'via' => 'form', 'name' => 'Free class', 'sub' => 'No payment needed', 'right' => '', 'btn' => 'Reserve my spot'];
    }
    $first   = $opts[0] ?? null;
    $hasCard = collect($opts)->contains(fn ($o) => $o['via'] === 'card');
  @endphp

  @if(! $first)
    <div class="cl-waitlist-note">
      @if($signedIn)
        Book this class with a pass, or ask the studio.
        <a class="ac-link" href="{{ route('tenant.customer.classes.passes') }}">See passes</a>
      @else
        <a class="ac-link" href="{{ route('tenant.customer.login') }}">Sign in</a> to book with your membership or pack, or
        <a class="ac-link" href="{{ route('tenant.customer.classes.passes') }}">see passes</a>.
      @endif
    </div>
  @else
  <form method="POST" action="{{ route('tenant.customer.classes.register', ['id' => $session->id]) }}" id="register-form">
    @csrf
    <input type="hidden" name="payment_method" id="payment_method_input" value="{{ $first['via'] === 'form' ? $first['key'] : '' }}">

    @if(count($opts) > 1 || $first['key'] !== 'cash')
      <div class="cl-section-label" style="margin-bottom:10px">How would you like to pay?</div>
      @foreach($opts as $i => $o)
        <label class="cl-pay-option {{ $i === 0 ? 'selected' : '' }}"
               data-key="{{ $o['key'] }}" data-via="{{ $o['via'] }}" data-btn="{{ $o['btn'] }}"
               data-amount="{{ $o['amount'] ?? '' }}" data-product="{{ $o['product'] ?? '' }}"
               onclick="selectPay(this)">
          <div class="cl-pay-radio"></div>
          <div>
            <div class="cl-pay-name">{{ $o['name'] }}</div>
            <div class="cl-pay-sub">{{ $o['sub'] }}</div>
          </div>
          {!! $o['right'] !!}
        </label>
      @endforeach
    @endif

    {{-- Guest fields --}}
    @if(! $signedIn)
      <div class="cl-guest-fields" style="margin-top:16px">
        <div class="cl-guest-title">Your details</div>
        <div class="cl-row">
          <div class="ac-field">
            <label class="ac-label">First name</label>
            <input type="text" name="first_name" id="g-first" class="ac-input" required maxlength="80" value="{{ old('first_name') }}">
          </div>
          <div class="ac-field">
            <label class="ac-label">Last name</label>
            <input type="text" name="last_name" id="g-last" class="ac-input" required maxlength="80" value="{{ old('last_name') }}">
          </div>
        </div>
        <div class="ac-field" style="margin-bottom:0">
          <label class="ac-label">Email</label>
          <input type="email" name="email" id="g-email" class="ac-input" required maxlength="180" value="{{ old('email') }}" placeholder="For your confirmation">
        </div>
      </div>
      <div style="font-size:12px;opacity:.45;margin:10px 0 16px;text-align:center">
        Already have an account? <a href="{{ route('tenant.customer.login') }}" class="ac-link">Sign in</a> to use your membership or pack credits.
      </div>
    @endif

    @if($hasCard)
      <div id="cl-card-wrap" style="{{ $first['via'] === 'card' ? '' : 'display:none' }};margin-top:{{ $signedIn ? '16px' : '0' }}">
        <div class="cl-section-label" style="margin-bottom:10px">Card</div>
        <div class="co-pe" id="cl-card"></div>
      </div>
    @endif

    <div class="ac-flash ac-flash--error co-err" id="cl-pay-err"></div>
    <button type="submit" class="ac-btn ac-btn--primary" id="cl-book-btn" style="margin-top:{{ $signedIn ? '16px' : '0' }}">{{ $first['btn'] }}</button>
    @if($first['key'] === 'per_class' || collect($opts)->contains('key', 'per_class'))
      <div class="co-fine" id="cl-desk-note" style="{{ $first['key'] === 'per_class' ? '' : 'display:none' }}">Can't make it? Cancel from your account so the spot goes to someone else.</div>
    @endif
  </form>
  @endif
@endif

@endsection

@push('styles')
  @include('public.classes._online-css')
@endpush

@push('scripts')
@if(! empty($hasCard))
  @include('public.classes._pay-js')
@endif
<script>
(function () {
  var hasCard = {{ ! empty($hasCard) ? 'true' : 'false' }};
  var mounted = false;
  function current() { return document.querySelector('.cl-pay-option.selected'); }
  function sync() {
    var o = current(); if (!o) return;
    var card = o.dataset.via === 'card';
    var wrap = document.getElementById('cl-card-wrap');
    if (wrap) wrap.style.display = card ? '' : 'none';
    document.getElementById('payment_method_input').value = card ? '' : o.dataset.key;
    document.getElementById('cl-book-btn').textContent = o.dataset.btn;
    var desk = document.getElementById('cl-desk-note');
    if (desk) desk.style.display = o.dataset.key === 'per_class' ? '' : 'none';
    if (card && hasCard) {
      if (!mounted) { mounted = ClassPay.mount({ pk: @json($stripePk ?? null), el: '#cl-card', amount: parseInt(o.dataset.amount, 10) }); }
      else { ClassPay.update({ amount: parseInt(o.dataset.amount, 10) }); }
    }
  }
  window.selectPay = function (el) {
    document.querySelectorAll('.cl-pay-option').forEach(function (o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    sync();
  };
  var form = document.getElementById('register-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      var o = current();
      if (!o || o.dataset.via !== 'card') return; // reserve: a normal form post
      e.preventDefault();
      var err = document.getElementById('cl-pay-err');
      var payload = { session_id: @json($session->id), kind: o.dataset.key === 'intro' ? 'pack' : 'dropin', product_id: o.dataset.product || null };
      var f = document.getElementById('g-first');
      if (f) {
        payload.first_name = f.value.trim();
        payload.last_name  = document.getElementById('g-last').value.trim();
        payload.email      = document.getElementById('g-email').value.trim();
        if (!payload.first_name || !payload.email) { err.textContent = 'Enter your name and email.'; err.style.display = 'block'; return; }
      }
      ClassPay.pay(@json(route('tenant.customer.classes.checkout')), payload, err, document.getElementById('cl-book-btn'));
    });
  }
  sync();
})();
</script>
@endpush
