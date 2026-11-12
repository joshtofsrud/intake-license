@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Settings'; @endphp

{{-- MARKER-RENTAL-SETTINGS-STUDIO: rental settings in the Studio design.
     Same fields, same endpoints. Settings save together with Save settings;
     the agreement publishes on its own. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $extOn   = (bool) tenant()->rental_extensions_enabled;
  $extS    = $extOn ? app(\App\Services\RentalExtensionOfferService::class)->settings(tenant()) : [];
  $current = $agreementTemplates->first();
  $nextV   = $current ? $current->version + 1 : 1;
  $secs    = ['rentals' => 'Rentals', 'booking' => 'Hours & payment', 'leasing' => 'Leasing', 'late' => 'Late & overdue'];
  if ($extOn) $secs['offers'] = 'Extension offers';
  $secs['deposits'] = 'Deposits'; $secs['agreement'] = 'Agreement';
  // a switch is a real (hidden) checkbox, so the form posts exactly what it did before
  $tog = function (string $name, bool $on, string $label) {
      return '<button type="button" class="ia-toggle ' . ($on ? 'on' : '') . '" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '" aria-label="' . e($label) . '" data-tg="' . $name . '"><span class="ia-toggle-sr">' . e($label) . '</span></button>'
           . '<input type="checkbox" name="' . $name . '" value="1" ' . ($on ? 'checked' : '') . ' hidden>';
  };
@endphp

<style>
  .rh-grid{display:grid;grid-template-columns:90px 130px 130px;gap:8px 10px;align-items:center;margin:10px 0 16px}
  .rh-day{display:flex;gap:8px;align-items:center;font-size:13px;font-weight:500}
  .rh-date{display:inline-flex;gap:4px;align-items:center}
  @media (max-width:520px){.rh-grid{grid-template-columns:70px 1fr 1fr}}
</style>
<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Rental settings</h1>
      <p class="sd-sub">Season window and what's switched on for your rental operation.</p>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'settings'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <div class="st-wrap">
    <nav class="st-nav" id="stNav" aria-label="Sections">
      @foreach($secs as $k => $label)<a href="#ss-{{ $k }}" data-ss="{{ $k }}" class="{{ $loop->first ? 'on' : '' }}">{{ $label }}</a>@endforeach
    </nav>

    <div>
      <form method="POST" action="{{ route('tenant.rentals.settings.save') }}" id="stForm">
        @csrf

        <section class="st-sec sd-in" id="ss-rentals">
          <h2>Rentals</h2>
          <p>When on, the rental desk, fleet, bookings and availability show in your menu. Turn off to hide rentals entirely.</p>
          <div class="st-row"><div><b>Show rentals in my menu</b></div>{!! $tog('rentals_visible', $rentalsVisible, 'Show rentals in my menu') !!}</div>
        </section>

        {{-- MARKER-RENTAL-HOURS / MARKER-RENTAL-PAY-MODE --}}
        <section class="st-sec sd-in" id="ss-booking">
          <h2>Pickup and return hours</h2>
          <p>Online bookings can only start and end inside these hours, and never on a closed date. Your desk can still book any time.</p>
          <div class="rh-grid">
            @foreach(\App\Support\Launch\RentalRules::DAY_NAMES as $dn => $dname)
              @php $dh = $rentalHours['days'][$dn]; @endphp
              <label class="rh-day"><input type="checkbox" name="hours[{{ $dn }}][open]" value="1" @checked($dh['open'])> {{ $dname }}</label>
              <input class="ia-input" type="time" name="hours[{{ $dn }}][from]" value="{{ $dh['from'] }}" aria-label="{{ $dname }} opens">
              <input class="ia-input" type="time" name="hours[{{ $dn }}][to]" value="{{ $dh['to'] }}" aria-label="{{ $dname }} closes">
            @endforeach
          </div>
          <div class="st-row" style="display:block">
            <div><b>Closed dates</b><small>Holidays and days off. Past dates drop off by themselves.</small></div>
            <div id="rhClosed" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
              @foreach($rentalHours['closed'] as $cd)
                @if($cd >= now()->toDateString())
                  <span class="rh-date"><input class="ia-input" type="date" name="closed[]" value="{{ $cd }}"><button type="button" class="ia-btn ia-btn--sm" data-rh-del aria-label="Remove date">✕</button></span>
                @endif
              @endforeach
            </div>
            <button type="button" class="ia-btn ia-btn--sm" id="rhAdd" style="margin-top:8px">Add a closed date</button>
          </div>

          <h2 style="margin-top:22px">How renters pay</h2>
          <p>For bookings made on your booking page. The desk takes payment however you like.</p>
          @foreach(\App\Support\Launch\RentalRules::PAY_MODES as $pm => $pmLabel)
            <label class="st-row" style="cursor:pointer">
              <div><b>{{ $pmLabel }}</b><small>{{ ['online' => 'They pay by card to finish booking.', 'pickup' => 'Nothing is charged online; you take payment at the counter.', 'choice' => 'They pick: pay now by card, or at pickup.'][$pm] }}</small></div>
              <input type="radio" name="pay_mode" value="{{ $pm }}" @checked($payMode === $pm) style="width:18px;height:18px;accent-color:var(--ia-accent)">
            </label>
          @endforeach
          @unless($paymentsOn)
            <p style="margin-top:8px;color:var(--ia-text-muted)">Card payments are not connected yet, so every booking is paid at pickup until you <a href="{{ route('tenant.settings.payments.index') }}" style="text-decoration:underline">connect Stripe</a>.</p>
          @endunless
        </section>

        <section class="st-sec sd-in" id="ss-leasing">
          <h2>Season-long leasing @unless($leasingAvailable)<span class="st-cur" style="margin-left:4px">Scale plan</span>@endunless</h2>
          @if($leasingAvailable)
            <p>Tiered season packages (for example “Junior Complete”) that pull from your rental fleet. When off, leasing is hidden and your shop runs pure rentals.</p>
            <div class="st-row"><div><b>Enable leasing</b><small>Adds Leases to your rentals menu.</small></div>{!! $tog('leases_enabled', $leasesEnabled, 'Enable leasing') !!}</div>
            <div class="st-sub">
              <h3>Season window</h3>
              <p>Season-long leases default their return date to the season end.</p>
              <div class="st-fields">
                <label><span class="rf-lbl">Season starts (MM-DD)</span><input class="ia-input" style="font-family:var(--ia-font-mono)" name="season_start" value="{{ $seasonStart }}" placeholder="11-01" maxlength="5"></label>
                <label><span class="rf-lbl">Season ends (MM-DD)</span><input class="ia-input" style="font-family:var(--ia-font-mono)" name="season_end" value="{{ $seasonEnd }}" placeholder="04-15" maxlength="5"></label>
              </div>
            </div>
          @else
            <p>Season-long leasing — tiered packages that pull from your fleet — is available on the <b>Scale</b> plan and up. Upgrade to turn it on.</p>
            {{-- keep the season config across saves even while leasing is unavailable --}}
            <input type="hidden" name="season_start" value="{{ $seasonStart }}">
            <input type="hidden" name="season_end" value="{{ $seasonEnd }}">
          @endif
        </section>

        <section class="st-sec sd-in" id="ss-late">
          <h2>Late &amp; overdue policy</h2>
          <p>The return flow suggests a late fee from these rules. Staff can edit or waive it on every return — this sets the default, not a mandate.</p>
          <div class="st-fields">
            <label><span class="rf-lbl">Grace period (minutes)</span><input class="ia-input" type="number" name="late_grace_minutes" value="{{ $lateGraceMinutes }}" min="0" max="1440"></label>
            <label><span class="rf-lbl">Late fee ($ / hour)</span><input class="ia-input" type="number" name="late_fee_per_hour" value="{{ $lateFeePerHour }}" min="0" step="0.01" style="text-align:right"></label>
            <label><span class="rf-lbl">Cap the fee at</span>
              <select class="ia-input" name="late_fee_cap">
                <option value="day_rate" {{ $lateFeeCap === 'day_rate' ? 'selected' : '' }}>One day's rate</option>
                <option value="none" {{ $lateFeeCap === 'none' ? 'selected' : '' }}>No cap</option>
              </select>
            </label>
          </div>
          <p class="st-fine">Within the grace period nothing is suggested. Past it, full hours from the due time are billed. Set $0 per hour to turn suggestions off.</p>
        </section>

        @if($extOn)
        <section class="st-sec sd-in" id="ss-offers">
          <h2>Last-minute extension offers</h2>
          <p>When a rental is coming back and nobody has the unit booked next, Intake texts the renter a discounted extension with a one-tap pay link.</p>
          <div class="st-row"><div><b>Send automatic offers</b><small>Results appear under Offers.</small></div>{!! $tog('ext_enabled', (bool) $extS['enabled'], 'Send automatic offers') !!}</div>
          <div class="st-fields">
            <label><span class="rf-lbl">Discount %</span><input class="ia-input" type="number" name="ext_discount_pct" value="{{ $extS['discount_pct'] }}" min="0" max="90"></label>
            <label><span class="rf-lbl">Minimum gap (min)</span><input class="ia-input" type="number" name="ext_min_gap_minutes" value="{{ $extS['min_gap'] }}" min="30" max="1440"></label>
            <label><span class="rf-lbl">Send before return (min)</span><input class="ia-input" type="number" name="ext_send_before_minutes" value="{{ $extS['send_before'] }}" min="15" max="480"></label>
            <label><span class="rf-lbl">Extend up to (daily cutoff)</span><input class="ia-input" type="time" name="ext_until" value="{{ $extS['until'] }}"></label>
            <label><span class="rf-lbl">Quiet hours start</span><input class="ia-input" type="time" name="ext_quiet_start" value="{{ $extS['quiet_start'] }}"></label>
            <label><span class="rf-lbl">Quiet hours end</span><input class="ia-input" type="time" name="ext_quiet_end" value="{{ $extS['quiet_end'] }}"></label>
          </div>
        </section>
        @endif

        <section class="st-sec sd-in" id="ss-deposits">
          <h2>Deposits</h2>
          <div class="st-row">
            <div><b>Auto-release holds on quick check-in</b><small>The guided return flow always asks explicitly — this only controls the one-click escape hatch. Turn off if you'd rather every hold be a deliberate decision.</small></div>
            {!! $tog('deposit_autorelease_quick', $depositAutoRelease, 'Auto-release deposit holds on quick check-in') !!}
          </div>
        </section>

        <div class="st-savebar" id="stBar">
          <span>All changes saved</span>
          <div class="acts">
            <button type="button" class="ia-btn" data-st-discard disabled>Discard</button>
            <button type="submit" class="ia-btn ia-btn--primary" data-st-save disabled>Save settings</button>
          </div>
        </div>
      </form>

      {{-- own form: publishing is separate from saving settings --}}
      <section class="st-sec sd-in" id="ss-agreement" style="margin-top:34px">
        <h2>Rental agreement @if($current)<span class="st-cur" style="margin-left:2px">v{{ $current->version }} current</span>@endif</h2>
        @if($agreementTemplates->isEmpty())
          <p>No agreement yet — the check-out flow currently skips the signature step. Publish v1 below and every guided check-out from then on requires a signed agreement (PDF snapshotted to the rental record).</p>
        @else
          <p>Publishing is separate from saving settings. Every guided check-out from then on needs a signed agreement, and a PDF is kept on the rental record. Past rentals keep the version they signed.</p>
          <div>
            @foreach($agreementTemplates as $tpl)
              <details class="st-ver">
                <summary><b>{{ $tpl->title }}</b><span class="rf-mono rf-dim">v{{ $tpl->version }}</span>@if($loop->first)<span class="st-cur">current</span>@endif<span class="d">{{ tlocal_date($tpl->created_at) }}</span></summary>
                <pre>{{ $tpl->body }}</pre>
              </details>
            @endforeach
          </div>
        @endif

        <form method="POST" action="{{ route('tenant.rentals.settings.agreements.store') }}" id="agForm" class="st-sub">
          @csrf
          <h3>Publish v{{ $nextV }}</h3>
          <p>{{ $current ? 'Starts from the current terms. Edit them, then publish.' : 'Write the terms every renter will sign at check-out.' }}</p>
          <div class="rf-fg">
            <label class="rf-c4"><span class="rf-lbl">Title</span><input class="ia-input" name="title" maxlength="160" required value="{{ old('title', $current?->title ?? 'Rental agreement') }}"></label>
            <label class="rf-c4"><span class="rf-lbl">Terms</span><textarea class="ia-input" name="body" rows="9" required maxlength="50000" style="line-height:1.6" placeholder="The customer agrees to…">{{ old('body', $current?->body) }}</textarea></label>
          </div>
          <div style="display:flex;gap:12px;align-items:center;margin-top:12px;flex-wrap:wrap">
            <button type="submit" class="ia-btn ia-btn--primary">Publish v{{ $nextV }}</button>
            <span class="rf-dim" style="font-size:12px">Publish-only — past rentals keep the version they signed.</span>
          </div>
        </form>
      </section>

      <p class="sd-legend">Settings above save together with Save settings. The agreement publishes on its own. Leasing and extension offers are plan features: on plans without them their sections show what the plan adds instead of the controls, and the matching tabs stay hidden.</p>
    </div>
  </div>
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
<script>
(function () {
  'use strict';
  function $(s, e) { return (e || document).querySelector(s); }
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  var form = $('#stForm'), bar = $('#stBar');
  function snap() {
    var o = [];
    $$('input,select,textarea', form).forEach(function (el) {
      if (el.name === '_token') return;
      o.push(el.name + '=' + (el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value));
    });
    return o.join('&');
  }
  var saved = snap();
  function sync() {
    var dirty = snap() !== saved;
    bar.classList.toggle('dirty', dirty);
    $('span', bar).textContent = dirty ? 'You have unsaved changes' : 'All changes saved';
    $('[data-st-save]', bar).disabled = !dirty; $('[data-st-discard]', bar).disabled = !dirty;
  }
  function paintToggles() {
    $$('.ia-toggle[data-tg]', form).forEach(function (b) {
      var cb = $('input[name="' + b.dataset.tg + '"]', form);
      b.classList.toggle('on', cb.checked); b.setAttribute('aria-checked', cb.checked ? 'true' : 'false');
    });
  }
  form.addEventListener('click', function (e) {
    var b = e.target.closest('.ia-toggle[data-tg]'); if (!b) return;
    var cb = $('input[name="' + b.dataset.tg + '"]', form); cb.checked = !cb.checked; paintToggles(); sync();
  });
  form.addEventListener('input', sync); form.addEventListener('change', sync);
  // MARKER-RENTAL-HOURS: closed dates
  $('#rhAdd').addEventListener('click', function () {
    var w = document.createElement('span'); w.className = 'rh-date';
    w.innerHTML = '<input class="ia-input" type="date" name="closed[]"><button type="button" class="ia-btn ia-btn--sm" data-rh-del aria-label="Remove date">✕</button>';
    $('#rhClosed').appendChild(w); $('input', w).focus(); sync();
  });
  $('#rhClosed').addEventListener('click', function (e) { var b = e.target.closest('[data-rh-del]'); if (b) { b.parentNode.remove(); sync(); } });
  $('[data-st-discard]', bar).addEventListener('click', function () { form.reset(); paintToggles(); sync(); });

  // publishing a new agreement version asks first, in the app's own dialog
  var ag = $('#agForm');
  ag.addEventListener('submit', function (e) {
    if (ag.dataset.ok === '1') return;
    e.preventDefault();
    IntakeConfirm.show({
      title: 'Publish v{{ $nextV }}?',
      message: 'Every check-out from now on uses v{{ $nextV }}. Rentals that already signed keep the version they signed{{ $current ? ', and v' . $current->version . ' stays in the history' : '' }}.',
      confirmText: 'Publish v{{ $nextV }}'
    }).then(function (ok) { if (ok) { ag.dataset.ok = '1'; ag.submit(); } });
  });

  // side nav follows the scroll
  var links = $$('#stNav a'), secs = links.map(function (a) { return document.getElementById('ss-' + a.dataset.ss); });
  addEventListener('scroll', function () {
    var cur = 0; secs.forEach(function (s, i) { if (s && s.getBoundingClientRect().top < 140) cur = i; });
    links.forEach(function (a, i) { a.classList.toggle('on', i === cur); });
  }, { passive: true });
  links.forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); var s = document.getElementById('ss-' + a.dataset.ss); if (s) s.scrollIntoView({ behavior: 'smooth', block: 'start' }); }); });
})();
</script>
@endpush
