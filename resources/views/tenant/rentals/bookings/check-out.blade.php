@extends('layouts.tenant.app')
@php $pageTitle = 'Check out ' . $rental->rental_number; @endphp

{{-- MARKER-RENTAL-CHECKOUT-STUDIO: guided check-out in the Studio design.
     Who & what → Agreement → Condition → Deposit & go. Resumable: every write
     step is its own POST, so finished steps render finished after a reload. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $tones = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $ini = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
  $who   = $rental->customer?->fullName() ?: 'Walk-in';
  $first = explode(' ', trim($who))[0];
  $tone  = $tones[crc32((string) ($rental->customer_id ?? $rental->id)) % 4];

  $agreementDone  = $agreementSigned || !$agreementTemplate;
  $allUnits       = $unitLines->count();
  $checkedUnits   = $unitLines->filter(fn ($l) => $checksByUnit->has($l->unit_id))->count();
  $conditionDone  = $allUnits > 0 && $checkedUnits >= $allUnits;
  $depositHeld    = $rental->deposit_status === 'authorized';
  $balancePaid    = $balanceCents <= 0;
  $startStep      = !$agreementDone ? 2 : (!$conditionDone ? 3 : 4);

  $stepDefs = [
    1 => ['Who & what', 'Confirm the booking', false],
    2 => ['Agreement', 'Signed before it leaves', $agreementDone],
    3 => ['Condition', 'Out-check each unit', $conditionDone],
    4 => ['Deposit & go', 'Hold and balance', false],
  ];
  $prog = [
    ['Booking confirmed', true, 'pr-book'],
    [$agreementTemplate ? 'Agreement signed' : 'No agreement needed', $agreementDone, 'pr-agree'],
    ["Units checked ({$checkedUnits} of {$allUnits})", $conditionDone, 'pr-cond'],
    ['Deposit held', $depositHeld, 'pr-dep'],
    ['Balance paid', $balancePaid, 'pr-bal'],
  ];
  $nowI = null; foreach ($prog as $i => $p) { if (!$p[1]) { $nowI = $i; break; } }
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Check out · {{ $who }}</h1>
      <p class="sd-sub">{{ $rental->rental_number }} · {{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn ia-btn--sm">Back to booking</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'bookings'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <div class="rf-flowbar sd-in">
    <div class="rf-flow" id="coSteps">
      @foreach($stepDefs as $n => $s)
        <button type="button" data-step="{{ $n }}" data-done="{{ $s[2] ? 1 : 0 }}"><i>{{ $n }}</i><span>{{ $s[0] }}<small>{{ $n === 3 ? "{$checkedUnits} of {$allUnits} checked" : $s[1] }}</small></span></button>
      @endforeach
    </div>
  </div>

  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">

      {{-- ---------------------------------------------------- step 1 verify --}}
      <div class="co-pane" data-pane="1" hidden>
        <div class="rf-step-h"><div><h2>Who &amp; what</h2><p>Check the customer and the units before anything is signed.</p></div></div>
        <div class="rf-person" style="margin-bottom:6px">
          <div class="sd-av" style="--c:var({{ $tone }})">{{ $ini($who) }}</div>
          <div><b>{{ $who }}</b><small>{{ $rental->customer?->phone ?: '—' }}{{ $rental->customer?->email ? ' · ' . $rental->customer->email : '' }}</small></div>
        </div>
        <dl class="rf-kv" style="margin-bottom:18px">
          <dt>Goes out</dt><dd>{{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }}</dd>
          <dt>Due back</dt><dd>{{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</dd>
        </dl>
        @foreach($unitLines as $line)
          <div class="rf-line">
            <div class="rf-thumb">{{ $ini($line->name_snapshot) }}</div>
            <div><b>{{ $line->name_snapshot }}</b><small>@if($line->unit?->identifier)<span class="rf-mono">{{ $line->unit->identifier }}</span>@endif{{ $line->unit?->size ? ' · Size ' . $line->unit->size : '' }} · {{ $line->duration_units }} × {{ format_money($line->rate_cents_snapshot) }}</small></div>
            <div></div>
            <div class="amt">{{ format_money($line->line_total_cents ?? ($line->rate_cents_snapshot * $line->duration_units)) }}</div>
          </div>
        @endforeach
        <div class="rf-step-f"><a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn">← Booking</a><button type="button" class="ia-btn ia-btn--primary" data-step="2">Looks right →</button></div>
      </div>

      {{-- ------------------------------------------------- step 2 agreement --}}
      <div class="co-pane" data-pane="2" hidden>
        <div class="rf-step-h"><div><h2>Agreement</h2><p>{{ $first }} signs before anything leaves.</p></div></div>
        @if(!$agreementTemplate)
          <div class="sd-empty">No agreement is set up yet, so this step is skipped. Add one in Rental settings and every check-out from then on will need a signature.</div>
        @elseif($agreementSigned)
          <div class="rf-signed">
            <i>✓</i>
            <div>
              <b>Agreement signed</b>
              <small class="rf-dim">
                @if($rental->agreement_signer_name){{ $rental->agreement_signer_name }} · @endif
                v{{ $rental->agreement_template_version }} · {{ tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') }}
                @if($rental->agreement_method === 'display') · on the customer display @endif
                @if($rental->agreement_pdf_path) · <a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" rel="noopener">PDF →</a>@endif
              </small>
            </div>
            @if($rental->agreement_signature_path)
              <img src="{{ Storage::disk('public')->url($rental->agreement_signature_path) }}" alt="Signature" class="rf-sigimg">
            @endif
          </div>
        @else
          <div class="rf-agree"><b style="color:var(--ia-text)">{{ $agreementTemplate->title }}</b> <span class="rf-dim">v{{ $agreementTemplate->version }}</span><br><span style="white-space:pre-wrap">{{ $agreementTemplate->body }}</span></div>

          <div class="rf-choose" id="ag-methods">
            <button type="button" class="rf-opt ag-method on" data-method="display"><b>Send to the customer display</b><small>They read and sign on the paired screen.</small></button>
            <button type="button" class="rf-opt ag-method" data-method="desk"><b>Sign at this screen</b><small>They type their name here to confirm.</small></button>
          </div>

          <div id="ag-display-pane" style="margin-top:14px">
            <div id="ag-send-row">
              <button type="button" id="ag-send" class="ia-btn ia-btn--primary">Send to display →</button>
              <span id="ag-send-note" class="rf-dim" style="font-size:12px;margin-left:10px"></span>
            </div>

            <div id="ag-waiting" class="rf-wait" style="display:none">
              <i></i>
              <div>
                <b>Waiting on {{ $first }}…</b>
                <small id="ag-waiting-note">The agreement is up on the customer display. This step completes on its own when they sign — you can start the condition check and come back.</small>
              </div>
              <span class="rf-wait-acts">
                <button type="button" id="ag-recall" class="ia-btn ia-btn--sm">Recall</button>
                <button type="button" class="ia-btn ia-btn--sm" data-step="3">Start condition check →</button>
              </span>
            </div>

            <div id="ag-pick" class="rf-pickreg" style="display:none">
              <b>Pick the register at this counter</b>
              <small>The customer display is paired to a register, so choose the one in front of you — or have them sign at this screen instead.</small>
              <div class="rf-collect">
                <select id="ag-registers" class="ia-input" style="min-width:220px"></select>
                <button type="button" id="ag-select-register" class="ia-btn ia-btn--sm">Use this register</button>
              </div>
            </div>
          </div>

          <form method="POST" action="{{ route('tenant.rentals.bookings.agreement.sign', $rental->id) }}" style="margin-top:14px;display:none" id="ag-desk-pane">
            @csrf
            <label class="rf-note" style="margin-top:0"><span class="rf-lbl">Customer types their full name</span>
              <input type="text" name="signer_name" maxlength="160" class="ia-input" placeholder="{{ $who }}">
            </label>
            <label class="rf-agreed"><input type="checkbox" name="agreed" value="1"> Customer has read and agrees to the terms above</label>
            <button type="submit" class="ia-btn ia-btn--primary" style="margin-top:12px">Sign agreement</button>
          </form>

          {{-- filled by the status poll the moment a signature lands --}}
          <div id="ag-signed-live" class="rf-signed" style="display:none;margin-top:14px">
            <i>✓</i>
            <div><b id="ag-signed-name"></b><small class="rf-dim" id="ag-signed-meta"></small></div>
          </div>
        @endif
        <div class="rf-step-f"><button type="button" class="ia-btn" data-step="1">← Back</button><button type="button" id="ag-continue" class="ia-btn ia-btn--primary" data-step="3" {{ $agreementDone ? '' : 'disabled' }}>Continue to condition →</button></div>
      </div>

      {{-- ------------------------------------------------- step 3 condition --}}
      <div class="co-pane" data-pane="3" hidden>
        <div class="rf-step-h"><div><h2>Out-check</h2><p>Mark each item OK or flag it. Flags and photos go on the unit's record, so a return can be compared against them.</p></div></div>
        @foreach($unitLines as $line)
          @php
            $unit  = $line->unit;
            $check = $unit ? $checksByUnit->get($unit->id) : null;
            $tpl   = $unit?->conditionTemplate;
            $items = $tpl ? (array) $tpl->items : [];
            $nPhotos = $check && is_array($check->photos) ? count($check->photos) : 0;
          @endphp
          <div class="rf-cu">
            <div class="rf-cu-h">
              <div class="rf-thumb">{{ $ini($line->name_snapshot) }}</div>
              <div><b>{{ $line->name_snapshot }}</b><small>@if($unit?->identifier)<span class="rf-mono">{{ $unit->identifier }}</span> · @endif{{ $tpl ? $tpl->name . ' template' : 'No template — quick visual' }}</small></div>
              @if($check)
                <span class="rf-cu-st {{ $check->flagged ? 'flag' : 'ok' }}">{{ $check->flagged ? '⚑ Saved with flags' : '✓ Saved' }}</span>
              @else
                <span class="rf-cu-st" data-cu-st>All OK</span>
              @endif
            </div>
            @if($check)
              <p class="rf-cu-done">Out-check recorded {{ tlocal_datetime($check->performed_at, 'M j, g:i A') }}{{ $check->notes ? ' — ' . $check->notes : '' }}{{ $nPhotos ? ' · ' . $nPhotos . ' photo' . ($nPhotos === 1 ? '' : 's') : '' }}</p>
            @else
              <form method="POST" action="{{ route('tenant.rentals.bookings.condition.store', $rental->id) }}" enctype="multipart/form-data" class="co-cond-form">
                @csrf
                <input type="hidden" name="unit_id" value="{{ $unit?->id }}">
                <input type="hidden" name="phase" value="check_out">
                @forelse($items as $item)
                  @php $k = $item['key'] ?? ('item_' . $loop->index); @endphp
                  <div class="rf-chk">
                    <span>{{ $item['label'] ?? $k }}</span>
                    <input type="hidden" name="results[{{ $k }}]" value="ok">
                    <span class="rf-tog co-seg"><button type="button" class="ok">OK</button><button type="button">Flag</button></span>
                  </div>
                @empty
                  <div class="rf-chk">
                    <span>Visual check — unit is complete and ready to go out</span>
                    <input type="hidden" name="results[visual]" value="ok">
                    <span class="rf-tog co-seg"><button type="button" class="ok">OK</button><button type="button">Flag</button></span>
                  </div>
                @endforelse
                <div class="rf-cu-more">
                  <label><span class="rf-lbl">Notes — existing scuffs, marks</span><textarea name="notes" rows="2" maxlength="2000" class="ia-input"></textarea></label>
                  <label><span class="rf-lbl">Photos (up to 4)</span>
                    <span class="rf-drop-ph"><span data-ph-t>+ Add photos</span><input type="file" name="photos[]" accept="image/*" multiple hidden></span>
                  </label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:10px"><button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Save out-check</button></div>
              </form>
            @endif
          </div>
        @endforeach
        @if($unitLines->isEmpty())<div class="sd-empty">This rental has no units to check.</div>@endif
        <div class="rf-step-f"><button type="button" class="ia-btn" data-step="2">← Back</button><button type="button" class="ia-btn ia-btn--primary" data-step="4">Continue →</button></div>
      </div>

      {{-- --------------------------------------------- step 4 deposit & go --}}
      <div class="co-pane" data-pane="4" hidden>
        <div class="rf-step-h"><div><h2>Deposit &amp; go</h2><p>Place the hold and settle anything owing, then it's theirs.</p></div></div>

        <div class="sd-sec-h"><div><h2>Deposit hold</h2></div></div>
        @if($rental->deposit_status === 'authorized')
          <div class="rf-hold"><b>{{ format_money($rental->deposit_hold_cents) }} held</b><small>On the customer's card · authorised, not charged. Released when everything comes back.</small></div>
        @elseif(tenant()->direct_payments_enabled)
          <div class="rf-hold" id="dep-start">
            <small style="margin-bottom:8px">Authorises the customer's card without charging it. Skippable — cash shops can just continue.</small>
            <div class="rf-collect" style="margin-top:0">
              <div class="rf-money"><input type="number" id="dep-amount" min="0.50" step="0.01" aria-label="Hold amount" value="{{ number_format(max(0, $rental->lines->where('kind','unit')->sum(fn ($l) => (int) ($l->unit?->effectiveDepositCents() ?? 0))) / 100, 2, '.', '') }}" class="ia-input" style="text-align:right"></div>
              <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" id="dep-authorize">Authorize hold</button>
            </div>
          </div>
          <div id="dep-element-wrap" class="rf-hold" style="display:none;margin-top:10px;max-width:520px">
            <div id="dep-element"></div>
            <button type="button" class="ia-btn ia-btn--primary" id="dep-confirm" style="width:100%;margin-top:10px;justify-content:center">Place hold</button>
            <div id="dep-error" class="rf-err"></div>
          </div>
        @else
          <div class="sd-empty">Card payments aren't enabled — deposits can be taken in cash through the register, or skipped.</div>
        @endif

        <div class="sd-sec-h" style="margin-top:24px"><div><h2>{{ $balanceCents > 0 ? 'Balance due — ' . format_money($balanceCents) : 'Balance' }}</h2></div></div>
        @if($balanceCents > 0)
          <form method="POST" action="{{ route('tenant.rentals.bookings.collect', $rental->id) }}" class="rf-collect" style="margin-top:0">
            @csrf
            {{-- come back to this flow after payment. --}}
            <input type="hidden" name="return_to" value="{{ parse_url(route('tenant.rentals.bookings.checkout.flow', $rental->id), PHP_URL_PATH) }}">
            <div class="rf-money"><input type="number" name="amount" min="0.01" step="0.01" required aria-label="Amount" value="{{ number_format($balanceCents / 100, 2, '.', '') }}" class="ia-input" style="text-align:right"></div>
            <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Collect in register</button>
            <span class="rf-dim" style="font-size:11.5px">The register opens with a linked sale — cash, card or payment link — and comes back here when it's paid.</span>
          </form>
        @else
          <div class="rf-signed"><i>✓</i><div><b>Paid in full</b><small class="rf-dim">{{ format_money($rental->paid_cents) }} taken</small></div></div>
        @endif

        <form method="POST" action="{{ route('tenant.rentals.bookings.checkout.complete', $rental->id) }}">
          @csrf
          <div class="rf-step-f">
            <button type="button" class="ia-btn" data-step="3">← Back</button>
            <button type="submit" class="ia-btn ia-btn--primary rf-big">Complete check-out ✓</button>
          </div>
        </form>
      </div>
    </div>

    {{-- ---------------------------------------------------------- the rail --}}
    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail rf-rail sd-in" style="--d:120ms">
        <div class="rf-rh"><b>This check-out</b><span>{{ $rental->rental_number }}</span></div>
        <ul class="rf-prog" id="coProg">
          @foreach($prog as $i => $p)
            <li id="{{ $p[2] }}" class="{{ $p[1] ? 'ok' : ($i === $nowI ? 'now' : '') }}"><i>{{ $p[1] ? '✓' : '' }}</i><span>{{ $p[0] }}</span></li>
          @endforeach
        </ul>
        <div class="rf-rh"><b>Money</b></div>
        <dl class="rf-tot">
          <dt>Subtotal</dt><dd>{{ format_money($rental->subtotal_cents) }}</dd>
          <dt>Tax</dt><dd>{{ format_money($rental->tax_cents) }}</dd>
          <dt class="big">Total</dt><dd>{{ format_money($rental->total_cents) }}</dd>
          <dt>Paid</dt><dd>{{ format_money($rental->paid_cents) }}</dd>
          <dt class="{{ $balanceCents > 0 ? 'owe' : '' }}">Balance</dt><dd>{{ format_money($balanceCents) }}</dd>
        </dl>
      </aside>
    </div>
  </div>

  <p class="sd-legend">Each step saves on its own — close this page and pick up where you left off. The deposit hold only authorises the card; nothing is charged by it. Completing marks the units Out and starts the due-back clock; the agreement, out-checks and photos stay on the booking and on each unit's history, and come back at return time.</p>
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
  var cur = {{ $startStep }};
  function go(n) {
    cur = parseInt(n, 10);
    $$('.co-pane').forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-pane'), 10) !== cur; });
    $$('#coSteps button').forEach(function (b) {
      var k = parseInt(b.getAttribute('data-step'), 10), done = b.getAttribute('data-done') === '1' || (k === 1 && cur > 1);
      b.className = k === cur ? 'on' : (done ? 'ok' : '');
      b.querySelector('i').textContent = (done && k !== cur) ? '✓' : k;
    });
    scrollTo({ top: 0 });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]');
    if (!b || b.disabled) return;
    go(b.getAttribute('data-step'));
  });
  go(cur);

  // ---- OK / Flag toggles write into the hidden results[] input
  function summarize(form) {
    var flags = $$('input[type=hidden][name^="results"]', form).filter(function (i) { return i.value === 'flag'; }).length;
    var st = form.parentElement.querySelector('[data-cu-st]');
    if (!st) return;
    st.textContent = flags ? '⚑ ' + flags + ' flagged' : 'All OK';
    st.className = 'rf-cu-st' + (flags ? ' flag' : '');
  }
  $$('.co-seg').forEach(function (seg) {
    var btns = seg.querySelectorAll('button');
    var input = seg.parentElement.querySelector('input[type=hidden][name^="results"]');
    btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'ok'; summarize(seg.closest('form')); });
    btns[1].addEventListener('click', function () { btns[1].className = 'flag'; btns[0].className = ''; if (input) input.value = 'flag'; summarize(seg.closest('form')); });
  });
  // photo picker label shows how many were chosen
  $$('.rf-drop-ph').forEach(function (z) {
    var f = z.querySelector('input[type=file]'), t = z.querySelector('[data-ph-t]');
    z.addEventListener('click', function (e) { if (e.target !== f) f.click(); });
    f.addEventListener('change', function () {
      var n = Math.min(f.files.length, 4);
      t.textContent = n ? n + ' photo' + (n === 1 ? '' : 's') + ' ready' : '+ Add photos';
    });
  });
  // a double tap on Save shouldn't send the out-check twice
  $$('.co-cond-form').forEach(function (f) { f.addEventListener('submit', function () { var b = f.querySelector('[type=submit]'); if (b) { b.disabled = true; b.textContent = 'Saving…'; } }); });
  var cf = document.querySelector('form[action="{{ route('tenant.rentals.bookings.checkout.complete', $rental->id) }}"]');
  if (cf) cf.addEventListener('submit', function () { var b = cf.querySelector('[type=submit]'); if (b) b.disabled = true; });
})();
</script>

@if($rental->deposit_status === 'none' && tenant()->direct_payments_enabled)
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var btn = document.getElementById('dep-authorize');
  if (!btn) return;
  var intentUrl  = '{{ route('tenant.rentals.bookings.deposit.intent', $rental->id) }}';
  var confirmUrl = '{{ route('tenant.rentals.bookings.deposit.confirm', $rental->id) }}';
  var csrf = '{{ csrf_token() }}';
  var stripe = null, elements = null, piId = null;
  var errEl = document.getElementById('dep-error');

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }
  function fail(msg) {
    // the card element may not be open yet, so show the message where the person is looking
    var wrap = document.getElementById('dep-element-wrap');
    wrap.style.display = 'block';
    errEl.textContent = msg;
  }

  btn.addEventListener('click', function () {
    if (typeof Stripe === 'undefined') { fail('Card payments could not load. Check your connection and refresh.'); return; }
    btn.disabled = true;
    errEl.textContent = '';
    var dollars = parseFloat(document.getElementById('dep-amount').value || '0');
    post(intentUrl, { amount_cents: Math.round(dollars * 100) }).then(function (res) {
      if (!res.ok || !res.json.ok) {
        fail(res.json.error || 'Could not start the hold.');
        btn.disabled = false;
        return;
      }
      piId = res.json.payment_intent;
      stripe = Stripe(res.json.publishable_key);
      elements = stripe.elements({ clientSecret: res.json.client_secret });
      elements.create('payment').mount('#dep-element');
      document.getElementById('dep-element-wrap').style.display = 'block';
    }).catch(function () { fail('Could not start the hold.'); btn.disabled = false; });
  });

  document.getElementById('dep-confirm').addEventListener('click', function () {
    var confirmBtn = this;
    confirmBtn.disabled = true;
    errEl.textContent = '';
    stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
      if (result.error) {
        errEl.textContent = result.error.message || 'Card was not authorized.';
        confirmBtn.disabled = false;
        return;
      }
      post(confirmUrl, { payment_intent: piId }).then(function (res) {
        if (res.ok && res.json.ok) { window.location.reload(); }
        else {
          errEl.textContent = (res.json && res.json.error) || 'Could not verify the hold.';
          confirmBtn.disabled = false;
        }
      });
    });
  });
})();
</script>
@endif

{{-- push, recall, and live status --}}
@if($agreementTemplate && !$agreementSigned)
<script>
(function () {
  var SEND   = @json(route('tenant.rentals.bookings.agreement.send_display', $rental->id));
  var RECALL = @json(route('tenant.rentals.bookings.agreement.recall_display', $rental->id));
  var STATUS = @json(route('tenant.rentals.bookings.agreement.status', $rental->id));
  var SELECT = @json(route('tenant.register.select'));
  var CSRF   = @json(csrf_token());

  var sendRow = document.getElementById('ag-send-row');
  var waiting = document.getElementById('ag-waiting');
  var pick    = document.getElementById('ag-pick');
  var note    = document.getElementById('ag-send-note');
  var signed  = document.getElementById('ag-signed-live');
  var timer   = null;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  // method chooser
  document.querySelectorAll('.ag-method').forEach(function (m) {
    m.addEventListener('click', function () {
      var isDisplay = m.dataset.method === 'display';
      document.querySelectorAll('.ag-method').forEach(function (x) { x.classList.toggle('on', x === m); });
      document.getElementById('ag-display-pane').style.display = isDisplay ? '' : 'none';
      document.getElementById('ag-desk-pane').style.display    = isDisplay ? 'none' : '';
    });
  });

  function showWaiting(on, label) {
    sendRow.style.display = on ? 'none' : '';
    waiting.style.display = on ? '' : 'none';
    if (on && label) {
      document.getElementById('ag-waiting-note').textContent =
        'The agreement is up on ' + label + '. This step completes on its own when they sign — you can start the condition check and come back.';
    }
  }

  document.getElementById('ag-send').addEventListener('click', function () {
    note.textContent = 'Sending…';
    post(SEND).then(function (j) {
      note.textContent = '';
      if (j.ok) {
        pick.style.display = 'none';
        showWaiting(true, 'Register ' + j.register.number + ' · ' + j.register.name);
        start();
        return;
      }
      if (j.code === 'no_register') {
        var sel = document.getElementById('ag-registers');
        sel.innerHTML = '';
        (j.registers || []).forEach(function (r) {
          var o = document.createElement('option');
          o.value = r.id; o.textContent = r.label; sel.appendChild(o);
        });
        if (!(j.registers || []).length) {
          sel.innerHTML = '<option value="">No registers set up yet</option>';
        }
        pick.style.display = '';
        return;
      }
      if (j.code === 'already_signed') { check(); return; }
      note.textContent = j.code === 'no_template'
        ? 'No agreement template is configured.'
        : 'This rental is no longer reserved.';
    }).catch(function () { note.textContent = 'Could not reach the server — try again.'; });
  });

  document.getElementById('ag-select-register').addEventListener('click', function () {
    var v = document.getElementById('ag-registers').value;
    if (!v) { return; }
    post(SELECT, { register_id: parseInt(v, 10) }).then(function () {
      pick.style.display = 'none';
      document.getElementById('ag-send').click();
    });
  });

  document.getElementById('ag-recall').addEventListener('click', function () {
    post(RECALL).then(function () { showWaiting(false); stop(); });
  });

  function markSigned() {
    var chip = document.querySelector('#coSteps button[data-step="2"]');
    if (chip) { chip.setAttribute('data-done', '1'); if (!chip.classList.contains('on')) { chip.className = 'ok'; chip.querySelector('i').textContent = '✓'; } }
    var li = document.getElementById('pr-agree');
    if (li) {
      li.className = 'ok'; li.querySelector('i').textContent = '✓';
      var next = Array.prototype.filter.call(document.querySelectorAll('#coProg li'), function (x) { return !x.classList.contains('ok'); })[0];
      document.querySelectorAll('#coProg li.now').forEach(function (x) { x.classList.remove('now'); });
      if (next) next.classList.add('now');
    }
  }

  function check() {
    return fetch(STATUS, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { return; }
      if (j.signed) {
        stop();
        showWaiting(false);
        sendRow.style.display = 'none';
        pick.style.display = 'none';
        document.getElementById('ag-methods').style.display = 'none';
        document.getElementById('ag-display-pane').style.display = 'none';
        document.getElementById('ag-desk-pane').style.display = 'none';
        document.getElementById('ag-signed-name').textContent = 'Signed by ' + (j.signer_name || 'the renter');
        document.getElementById('ag-signed-meta').textContent =
          'v' + j.version + ' · ' + (j.method === 'display' ? 'on the customer display' : 'at the desk') + ' · ' + j.signed_at;
        signed.style.display = '';
        var cont = document.getElementById('ag-continue');
        if (cont) { cont.removeAttribute('disabled'); }
        markSigned();
        return;
      }
      if (j.display === 'expired') {
        // The push aged out. Say so rather than leaving a Waiting box that
        // will never resolve.
        showWaiting(false);
        note.textContent = 'That agreement timed out on the screen — send it again.';
        stop();
      }
    }).catch(function () { /* transient; next tick */ });
  }

  function start() { stop(); timer = setInterval(check, 3000); }
  function stop()  { if (timer) { clearInterval(timer); timer = null; } }

  // If an agreement is already live for this rental (staff reloaded the page or
  // came back from the condition step), pick the waiting state back up.
  check().then(function () {
    fetch(STATUS, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.ok && !j.signed && j.display === 'waiting') { showWaiting(true, j.register); start(); }
    }).catch(function () {});
  });

  window.addEventListener('beforeunload', stop);
})();
</script>
@endif
@endpush
