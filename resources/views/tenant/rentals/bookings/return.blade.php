@extends('layouts.tenant.app')
@php $pageTitle = 'Return ' . $rental->rental_number; @endphp

{{-- MARKER-RENTAL-RETURN-STUDIO: guided return in the Studio design.
     Condition → Charges → Deposit & close. In-checks sit beside the out-checks;
     charges collect through the register round-trip; the deposit and routing
     decisions close it out. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $tones = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $ini = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
  $who = $rental->customer?->fullName() ?: 'Walk-in';
  $lateTxt = $lateMinutes >= 60 ? floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm' : $lateMinutes . 'm';

  $allUnits     = $unitLines->count();
  $checkedUnits = $unitLines->filter(fn ($l) => $inChecks->has($l->unit_id))->count();
  $inspectDone  = $allUnits > 0 && $checkedUnits >= $allUnits;
  $startStep    = $inspectDone ? 2 : 1;
  $flaggedAny   = $unitLines->contains(fn ($l) => (bool) ($inChecks->get($l->unit_id)?->flagged ?? false));

  $stepDefs = [
    1 => ['Condition', "{$checkedUnits} of {$allUnits} checked", $inspectDone],
    2 => ['Charges', 'Late fee and damage', false],
    3 => ['Deposit & close', 'Hold and where units go', false],
  ];
  $prog = [
    ["Units checked ({$checkedUnits} of {$allUnits})", $inspectDone, 'pr-cond'],
    ['Charges settled', false, 'pr-chg'],
    ['Deposit decided', false, 'pr-dep'],
    ['Units put away', false, 'pr-put'],
  ];
  $nowI = $inspectDone ? 1 : 0;
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Return · {{ $who }}</h1>
      <p class="sd-sub">{{ $rental->rental_number }} · due {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}@if($lateMinutes > 0) <span class="rf-st late" style="margin-left:8px">{{ $lateTxt }} overdue</span>@endif</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn ia-btn--sm">Back to booking</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'bookings'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <div class="rf-flowbar sd-in">
    <div class="rf-flow" id="rtSteps">
      @foreach($stepDefs as $n => $s)
        <button type="button" data-step="{{ $n }}" data-done="{{ $s[2] ? 1 : 0 }}"><i>{{ $n }}</i><span>{{ $s[0] }}<small>{{ $s[1] }}</small></span></button>
      @endforeach
    </div>
  </div>

  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">

      {{-- --------------------------------------------------- step 1 condition --}}
      <div class="rt-pane" data-pane="1" hidden>
        <div class="rf-step-h"><div><h2>In-check</h2><p>Compare against how each unit went out. Anything flagged that costs money goes on the next step.</p></div></div>
        @foreach($unitLines as $line)
          @php
            $unit     = $line->unit;
            $outCheck = $unit ? $outChecks->get($unit->id) : null;
            $inCheck  = $unit ? $inChecks->get($unit->id) : null;
            $tpl      = $unit?->conditionTemplate;
            $items    = $tpl ? (array) $tpl->items : [];
            $outFlags = $outCheck ? collect((array) $outCheck->results)->filter(fn ($v) => $v === 'flag')->keys() : collect();
            $inPhotos = $inCheck && is_array($inCheck->photos) ? count($inCheck->photos) : 0;
          @endphp
          <div class="rf-cu">
            <div class="rf-cu-h">
              <div class="rf-thumb">{{ $ini($line->name_snapshot) }}</div>
              <div><b>{{ $line->name_snapshot }}</b><small>@if($unit?->identifier)<span class="rf-mono">{{ $unit->identifier }}</span> · @endif{{ $tpl ? $tpl->name . ' template' : 'No template — quick visual' }}</small></div>
              @if($inCheck)
                <span class="rf-cu-st {{ $inCheck->flagged ? 'flag' : 'ok' }}">{{ $inCheck->flagged ? '⚑ Saved with flags' : '✓ Saved' }}</span>
              @else
                <span class="rf-cu-st" data-cu-st>All OK</span>
              @endif
            </div>

            <div class="rf-outnote">
              @if($outCheck)
                <b>Went out</b> {{ tlocal_datetime($outCheck->performed_at, 'M j, g:i A') }}{{ $outCheck->notes ? ' — “' . $outCheck->notes . '”' : '' }}
                @if(is_array($outCheck->photos) && count($outCheck->photos))
                  · @foreach($outCheck->photos as $i => $p)<a href="{{ Storage::disk('public')->url($p) }}" target="_blank" rel="noopener">photo {{ $i + 1 }}</a>{{ !$loop->last ? ', ' : '' }}@endforeach
                @endif
                @if($outFlags->count()) · <span class="rf-flag">⚑ flagged going out: {{ $outFlags->implode(', ') }}</span>@endif
              @else
                No out-check on file — this rental went out before condition checks (or via quick check-out).
              @endif
            </div>

            @if($inCheck)
              <p class="rf-cu-done">In-check recorded {{ tlocal_datetime($inCheck->performed_at, 'M j, g:i A') }}{{ $inCheck->notes ? ' — ' . $inCheck->notes : '' }}{{ $inPhotos ? ' · ' . $inPhotos . ' photo' . ($inPhotos === 1 ? '' : 's') : '' }}</p>
            @else
              <form method="POST" action="{{ route('tenant.rentals.bookings.condition.store', $rental->id) }}" enctype="multipart/form-data" class="rt-cond-form">
                @csrf
                <input type="hidden" name="unit_id" value="{{ $unit?->id }}">
                <input type="hidden" name="phase" value="check_in">
                @forelse($items as $item)
                  @php $k = $item['key'] ?? ('item_' . $loop->index); @endphp
                  <div class="rf-chk">
                    <span>{{ $item['label'] ?? $k }}</span>
                    <input type="hidden" name="results[{{ $k }}]" value="ok">
                    <span class="rf-tog rt-seg"><button type="button" class="ok">OK</button><button type="button">Flag</button></span>
                  </div>
                @empty
                  <div class="rf-chk">
                    <span>Visual check — returned complete, no new damage</span>
                    <input type="hidden" name="results[visual]" value="ok">
                    <span class="rf-tog rt-seg"><button type="button" class="ok">OK</button><button type="button">Flag</button></span>
                  </div>
                @endforelse
                <div class="rf-cu-more">
                  <label><span class="rf-lbl">Notes — new damage, missing parts</span><textarea name="notes" rows="2" maxlength="2000" class="ia-input"></textarea></label>
                  <label><span class="rf-lbl">Photos (up to 4)</span>
                    <span class="rf-drop-ph"><span data-ph-t>+ Add photos</span><input type="file" name="photos[]" accept="image/*" multiple hidden></span>
                  </label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:10px"><button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Save in-check</button></div>
              </form>
            @endif
          </div>
        @endforeach
        @if($unitLines->isEmpty())<div class="sd-empty">This rental has no units to check.</div>@endif
        <div class="rf-step-f"><a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn">← Booking</a><button type="button" class="ia-btn ia-btn--primary" data-step="2">Continue to charges →</button></div>
      </div>

      {{-- ---------------------------------------------------- step 2 charges --}}
      <div class="rt-pane" data-pane="2" hidden>
        <form method="POST" action="{{ route('tenant.rentals.bookings.return.charges', $rental->id) }}" id="rtCharges">
          @csrf
          <div class="rf-step-h"><div><h2>Charges</h2><p>Anything owing on top of the rental.</p></div></div>

          <div class="sd-sec-h"><div><h2>Late fee</h2></div></div>
          @if($lateMinutes > 0)
            <div class="rf-sugg">
              <div>
                @if($suggestedLateFeeCents > 0)
                  <b>Suggested {{ format_money($suggestedLateFeeCents) }}</b>
                  <small>{{ $lateTxt }} past due. Edit it, or waive it. Set in Rental settings.</small>
                @elseif($latePolicy['per_hour_cents'] === 0)
                  <b>No late-fee rate is set</b>
                  <small>{{ $lateTxt }} past due. Add a rate in Rental settings, or enter an amount by hand.</small>
                @else
                  <b>Within the grace period</b>
                  <small>{{ $lateTxt }} past due — inside the {{ $latePolicy['grace_minutes'] }}-minute grace.</small>
                @endif
              </div>
              <div class="rf-money" style="width:120px"><input type="number" name="late_fee" id="rtLate" min="0" step="0.01" aria-label="Late fee" value="{{ number_format($suggestedLateFeeCents / 100, 2, '.', '') }}" class="ia-input" style="text-align:right"></div>
              <span class="rf-tog"><button type="button" class="{{ $suggestedLateFeeCents > 0 ? 'ok' : '' }}" data-late="{{ number_format($suggestedLateFeeCents / 100, 2, '.', '') }}">Charge it</button><button type="button" data-late="0.00">Waive</button></span>
            </div>
          @else
            <div class="sd-empty">Back on time — nothing suggested.</div>
            <input type="hidden" name="late_fee" value="{{ number_format($suggestedLateFeeCents / 100, 2, '.', '') }}">
          @endif

          <div class="sd-sec-h" style="margin-top:24px"><div><h2>Damage &amp; missing items</h2><span>Anything flagged in the in-check that costs money</span></div></div>
          <div id="rt-dmg-rows">
            <div class="rf-charge rf-charge--2">
              <input type="text" name="damage_labels[]" maxlength="200" placeholder="What it's for — e.g. rear tire sidewall cut" class="ia-input" aria-label="Charge description">
              <div class="rf-money"><input type="number" name="damage_amounts[]" min="0" step="0.01" placeholder="0.00" class="ia-input" style="text-align:right" aria-label="Charge amount"></div>
              <button type="button" class="rf-ib" data-dx aria-label="Remove">×</button>
            </div>
          </div>
          <button type="button" class="ia-btn ia-btn--sm" id="rtAddDmg" style="margin-top:10px">+ Add a charge</button>

          <p class="rf-fine" style="text-align:left;margin-top:16px">Collecting here opens the register with one linked sale (cash, card or payment link) and brings you back. <b style="color:var(--ia-text)">Taking charges from the deposit instead?</b> Skip this — the capture on the booking page writes its own charge line.</p>

          <div class="rf-step-f">
            <button type="button" class="ia-btn" data-step="1">← Back</button>
            <span style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
              <button type="button" class="ia-btn" data-step="3">No charges — skip →</button>
              <button type="submit" class="ia-btn ia-btn--primary">Add charges &amp; collect in register →</button>
            </span>
          </div>
        </form>
      </div>

      {{-- ---------------------------------------------- step 3 deposit & close --}}
      <div class="rt-pane" data-pane="3" hidden>
        <form method="POST" action="{{ route('tenant.rentals.bookings.return.complete', $rental->id) }}" id="rtComplete">
          @csrf
          <div class="rf-step-h"><div><h2>Deposit &amp; close</h2><p>Decide the hold, then send each unit where it belongs.</p></div></div>

          <div class="sd-sec-h"><div><h2>Deposit{{ $rental->deposit_status === 'authorized' ? ' — ' . format_money($rental->deposit_hold_cents) . ' held' : '' }}</h2></div></div>
          @if($rental->deposit_status === 'authorized')
            <div class="rf-radio">
              <label class="rf-opt on" data-dep><input type="radio" name="deposit_action" value="release" checked hidden><b>Release the full hold</b><small>Clean return — nothing is charged.</small></label>
              <label class="rf-opt" data-dep><input type="radio" name="deposit_action" value="hold" hidden><b>Keep holding</b><small>Decide later from the booking page. Need to capture for damage? Finish the return with this, then capture from the booking page — it writes its own charge line and sale.</small></label>
            </div>
          @elseif(in_array($rental->deposit_status, ['captured', 'partially_captured'], true))
            <div class="rf-signed"><i>✓</i><div><b>Hold {{ $rental->deposit_status === 'captured' ? 'fully' : 'partially' }} captured</b><small class="rf-dim">Nothing to decide.</small></div></div>
          @else
            <div class="sd-empty">No deposit was held on this rental.</div>
          @endif

          <div class="sd-sec-h" style="margin-top:24px"><div><h2>Where does each unit go?</h2><span>Flagged units default to Maintenance</span></div></div>
          @foreach($unitLines as $line)
            @php
              $unit = $line->unit;
              $fl   = $unit ? (bool) ($inChecks->get($unit->id)?->flagged ?? false) : false;
            @endphp
            @if($unit)
              <div class="rf-dest rf-route-row">
                <div class="rf-thumb">{{ $ini($line->name_snapshot) }}</div>
                <div><b>{{ $line->name_snapshot }}</b><small>@if($unit->identifier)<span class="rf-mono">{{ $unit->identifier }}</span>@endif{!! $fl ? ' · <span class="rf-flag">⚑ flagged at inspection</span>' : '' !!}</small></div>
                <span class="rf-tog rt-route">
                  <button type="button" class="{{ $fl ? '' : 'ok' }}">Available</button>
                  <button type="button" class="{{ $fl ? 'mt' : '' }}">Maintenance</button>
                </span>
                <input type="hidden" name="routing[{{ $unit->id }}]" value="{{ $fl ? 'maintenance' : 'available' }}">
                <input type="text" name="routing_note[{{ $unit->id }}]" maxlength="500" placeholder="Maintenance note…" class="ia-input rf-route-note" aria-label="Maintenance note for {{ $line->name_snapshot }}" style="{{ $fl ? '' : 'display:none' }}">
              </div>
            @endif
          @endforeach

          <div class="rf-step-f">
            <button type="button" class="ia-btn" data-step="2">← Back</button>
            <button type="submit" class="ia-btn ia-btn--primary rf-big">Complete return ✓</button>
          </div>
        </form>
      </div>
    </div>

    {{-- ---------------------------------------------------------- the rail --}}
    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail rf-rail sd-in" style="--d:120ms">
        <div class="rf-rh"><b>This return</b><span>{{ $rental->rental_number }}</span></div>
        <ul class="rf-prog" id="rtProg">
          @foreach($prog as $i => $p)
            <li id="{{ $p[2] }}" class="{{ $p[1] ? 'ok' : ($i === $nowI ? 'now' : '') }}"><i>{{ $p[1] ? '✓' : '' }}</i><span>{{ $p[0] }}</span></li>
          @endforeach
        </ul>
        <div class="rf-rh"><b>Money</b></div>
        <dl class="rf-tot">
          <dt>Rental</dt><dd>{{ format_money($rental->total_cents) }}</dd>
          <dt>Paid</dt><dd>{{ format_money($rental->paid_cents) }}</dd>
          <dt class="big {{ $balanceCents > 0 ? 'owe' : '' }}">Owing</dt><dd>{{ format_money($balanceCents) }}</dd>
          <dt>Deposit hold</dt><dd>{{ $rental->deposit_status === 'authorized' ? format_money($rental->deposit_hold_cents) : ucfirst(str_replace('_', ' ', $rental->deposit_status)) }}</dd>
        </dl>
      </aside>
    </div>
  </div>

  <p class="sd-legend">Each step saves on its own. Completing marks the booking Returned and moves each unit to the status you chose. Return charges are collected in the register or captured from the hold — never both. Flags and photos are saved to each unit's history, and maintenance keeps a unit off new bookings until you clear it on the Fleet page.</p>
</div>
@endsection

@push('scripts')
<script>
(function () {
  'use strict';
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');
  function reveal() { var l = innerHeight * 0.92; $$('.sd-in:not(.is-in)').forEach(function (el) { if (el.getBoundingClientRect().top < l) el.classList.add('is-in'); }); }
  reveal(); addEventListener('scroll', reveal, { passive: true }); addEventListener('resize', reveal);
  setTimeout(function () { $$('.sd-in').forEach(function (el) { el.classList.add('is-in'); }); }, 2500);
  var head = document.getElementById('sdHead');
  function frost() { if (head) head.classList.toggle('frost', scrollY > 24 && innerWidth > 1023); }
  addEventListener('scroll', frost, { passive: true }); frost();

  // ---- steps
  var cur = {{ $startStep }}, seen = {};
  function prog(id, ok) {
    var li = document.getElementById(id); if (!li) return;
    li.classList.toggle('ok', ok); li.querySelector('i').textContent = ok ? '✓' : '';
  }
  function go(n) {
    cur = parseInt(n, 10); seen[cur] = true;
    $$('.rt-pane').forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-pane'), 10) !== cur; });
    $$('#rtSteps button').forEach(function (b) {
      var k = parseInt(b.getAttribute('data-step'), 10), done = b.getAttribute('data-done') === '1' || (k === 2 && cur > 2);
      b.className = k === cur ? 'on' : (done ? 'ok' : '');
      b.querySelector('i').textContent = (done && k !== cur) ? '✓' : k;
    });
    // the rail follows where the person has been, so "Charges settled" ticks once they are past it
    prog('pr-chg', cur > 2);
    if (cur === 3 && document.querySelector('[data-dep]')) prog('pr-dep', true); // the release default counts as a decision
    $$('#rtProg li.now').forEach(function (x) { x.classList.remove('now'); });
    var next = $$('#rtProg li').filter(function (x) { return !x.classList.contains('ok'); })[0];
    if (next) next.classList.add('now');
    scrollTo({ top: 0 });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]');
    if (!b || b.disabled) return;
    go(b.getAttribute('data-step'));
  });
  go(cur);

  // ---- in-check OK / Flag toggles
  function summarize(form) {
    var flags = $$('input[type=hidden][name^="results"]', form).filter(function (i) { return i.value === 'flag'; }).length;
    var st = form.parentElement.querySelector('[data-cu-st]');
    if (!st) return;
    st.textContent = flags ? '⚑ ' + flags + ' flagged' : 'All OK';
    st.className = 'rf-cu-st' + (flags ? ' flag' : '');
  }
  $$('.rt-seg').forEach(function (seg) {
    var btns = seg.querySelectorAll('button');
    var input = seg.parentElement.querySelector('input[type=hidden][name^="results"]');
    btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'ok'; summarize(seg.closest('form')); });
    btns[1].addEventListener('click', function () { btns[1].className = 'flag'; btns[0].className = ''; if (input) input.value = 'flag'; summarize(seg.closest('form')); });
  });
  $$('.rf-drop-ph').forEach(function (z) {
    var f = z.querySelector('input[type=file]'), t = z.querySelector('[data-ph-t]');
    z.addEventListener('click', function (e) { if (e.target !== f) f.click(); });
    f.addEventListener('change', function () {
      var n = Math.min(f.files.length, 4);
      t.textContent = n ? n + ' photo' + (n === 1 ? '' : 's') + ' ready' : '+ Add photos';
    });
  });
  $$('.rt-cond-form').forEach(function (f) { f.addEventListener('submit', function () { var b = f.querySelector('[type=submit]'); if (b) { b.disabled = true; b.textContent = 'Saving…'; } }); });

  // ---- late fee: Charge it / Waive
  var late = document.getElementById('rtLate');
  $$('[data-late]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!late) return;
      late.value = b.getAttribute('data-late');
      $$('[data-late]').forEach(function (x) { x.className = ''; });
      b.className = parseFloat(late.value) > 0 ? 'ok' : 'flag';
    });
  });
  if (late) late.addEventListener('input', function () {
    var on = parseFloat(late.value) > 0;
    var bs = $$('[data-late]'); bs[0].className = on ? 'ok' : ''; bs[1].className = on ? '' : 'flag';
  });

  // ---- damage rows
  var rows = document.getElementById('rt-dmg-rows');
  document.getElementById('rtAddDmg').addEventListener('click', function () {
    var r = document.createElement('div');
    r.className = 'rf-charge rf-charge--2';
    r.innerHTML = '<input type="text" name="damage_labels[]" maxlength="200" placeholder="What it\'s for" class="ia-input" aria-label="Charge description">' +
      '<div class="rf-money"><input type="number" name="damage_amounts[]" min="0" step="0.01" placeholder="0.00" class="ia-input" style="text-align:right" aria-label="Charge amount"></div>' +
      '<button type="button" class="rf-ib" data-dx aria-label="Remove">×</button>';
    rows.appendChild(r); r.querySelector('input').focus();
  });
  rows.addEventListener('click', function (e) {
    var x = e.target.closest('[data-dx]'); if (!x) return;
    var r = x.closest('.rf-charge');
    if (rows.children.length > 1) r.remove(); else $$('input', r).forEach(function (i) { i.value = ''; });
  });

  // ---- deposit choice cards
  $$('[data-dep]').forEach(function (l) {
    l.addEventListener('click', function () {
      $$('[data-dep]').forEach(function (x) { x.classList.toggle('on', x === l); });
      var r = l.querySelector('input'); if (r) r.checked = true;
      prog('pr-dep', true);
    });
  });
  if (!document.querySelector('[data-dep]')) prog('pr-dep', true);

  // ---- routing toggles
  $$('.rt-route').forEach(function (seg) {
    var btns = seg.querySelectorAll('button'), row = seg.closest('.rf-route-row');
    var input = row.querySelector('input[type=hidden][name^="routing["]');
    var note = row.querySelector('input[name^="routing_note"]');
    btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; input.value = 'available'; note.style.display = 'none'; prog('pr-put', true); });
    btns[1].addEventListener('click', function () { btns[1].className = 'mt'; btns[0].className = ''; input.value = 'maintenance'; note.style.display = ''; prog('pr-put', true); });
  });
  var cf = document.getElementById('rtComplete');
  cf.addEventListener('submit', function () { var b = cf.querySelector('[type=submit]'); if (b) b.disabled = true; });
})();
</script>
@endpush
