@extends('layouts.tenant.app')
@php $pageTitle = 'Check out ' . $rental->rental_number; @endphp

{{-- MARKER-PATCH-232 — guided check-out: Verify → Agreement → Condition →
     Deposit & go. Resumable: every write step is its own POST; done steps
     render done after reload. --}}

@push('styles')
<style>
  .co-steps{display:flex;align-items:center;margin-bottom:24px;flex-wrap:wrap}
  .co-step{display:flex;align-items:center;gap:9px;padding:0 4px;cursor:pointer}
  .co-n{width:24px;height:24px;border-radius:50%;border:1.5px solid var(--ia-border-strong);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:650;color:var(--ia-text-dim,rgba(255,255,255,.55));flex-shrink:0}
  .co-step.done .co-n{background:var(--ia-accent,#BEF264);border-color:var(--ia-accent,#BEF264);color:#0a0a0a}
  .co-step.cur .co-n{border-color:var(--ia-accent,#BEF264);color:var(--ia-accent,#BEF264)}
  .co-t{font-size:12.5px;font-weight:550;color:var(--ia-text-dim,rgba(255,255,255,.55))}
  .co-step.cur .co-t,.co-step.done .co-t{color:var(--ia-text,#f0f0f0)}
  .co-bar{width:34px;height:1.5px;background:var(--ia-border);margin:0 6px}
  .co-pane{display:none}
  .co-pane.on{display:block;animation:cofade .15s ease}
  @keyframes cofade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
  .co-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
  @media(max-width:980px){.co-grid{grid-template-columns:1fr}}
  .co-kv{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:5px 0}
  .co-kv span:first-child{opacity:.55}
  .co-chk{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:.5px solid var(--ia-border)}
  .co-chk:last-child{border-bottom:none}
  .co-seg{display:inline-flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);overflow:hidden;flex-shrink:0}
  .co-seg button{padding:5px 12px;font-size:11.5px;background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-weight:600;cursor:pointer}
  .co-seg button.ok{background:rgba(123,201,111,.18);color:#7BC96F}
  .co-seg button.flag{background:rgba(239,68,68,.16);color:#ef4444}
  .co-agree-body{max-height:300px;overflow-y:auto;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);padding:14px 16px;font-size:12.5px;line-height:1.65;white-space:pre-wrap;background:rgba(255,255,255,.02)}
  .co-foot{display:flex;justify-content:space-between;margin-top:16px}
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Check out — {{ $rental->rental_number }}</h1>
    <p class="ia-page-subtitle">{{ $rental->customer?->first_name }} {{ $rental->customer?->last_name }} · {{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn">Back to booking</a>
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

@php
  $agreementDone  = $agreementSigned || !$agreementTemplate;
  $allUnits       = $unitLines->count();
  $checkedUnits   = $unitLines->filter(fn ($l) => $checksByUnit->has($l->unit_id))->count();
  $conditionDone  = $allUnits > 0 && $checkedUnits >= $allUnits;
  $startStep      = !$agreementDone ? 2 : (!$conditionDone ? 3 : 4);
@endphp

<div class="co-steps" id="co-steps">
  <div class="co-step done" data-step="1"><span class="co-n">✓</span><span class="co-t">Verify</span></div>
  <div class="co-bar"></div>
  <div class="co-step {{ $agreementDone ? 'done' : '' }}" data-step="2"><span class="co-n">{{ $agreementDone ? '✓' : '2' }}</span><span class="co-t">Agreement</span></div>
  <div class="co-bar"></div>
  <div class="co-step {{ $conditionDone ? 'done' : '' }}" data-step="3"><span class="co-n">{{ $conditionDone ? '✓' : '3' }}</span><span class="co-t">Condition ({{ $checkedUnits }}/{{ $allUnits }})</span></div>
  <div class="co-bar"></div>
  <div class="co-step" data-step="4"><span class="co-n">4</span><span class="co-t">Deposit &amp; go</span></div>
</div>

<div class="co-grid">
  <div>
    {{-- ---------------------------------------------------- step 1 verify --}}
    <div class="co-pane" data-pane="1">
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        <h2 class="ia-h3" style="margin-bottom:10px">Who &amp; what</h2>
        <div class="co-kv"><span>Customer</span><span style="font-weight:600">{{ $rental->customer?->first_name }} {{ $rental->customer?->last_name }}</span></div>
        <div class="co-kv"><span>Contact</span><span>{{ $rental->customer?->email ?: '—' }}{{ $rental->customer?->phone ? ' · ' . $rental->customer->phone : '' }}</span></div>
        <div class="co-kv"><span>Window</span><span>{{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</span></div>
        <div style="border-top:.5px solid var(--ia-border);margin-top:8px;padding-top:8px">
          @foreach($unitLines as $line)
            <div class="co-kv"><span>{{ $line->name_snapshot }}{{ $line->unit?->identifier ? ' · ' . $line->unit->identifier : '' }}</span><span>{{ $line->duration_units }} × {{ format_money($line->rate_cents_snapshot) }}</span></div>
          @endforeach
        </div>
      </div>
      <div class="co-foot"><span></span><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(2)">Looks right →</button></div>
    </div>

    {{-- ------------------------------------------------- step 2 agreement --}}
    <div class="co-pane" data-pane="2">
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        @if(!$agreementTemplate)
          <h2 class="ia-h3" style="margin-bottom:8px">No agreement configured</h2>
          <p style="font-size:12.5px;opacity:.55;line-height:1.6">You haven't set up a rental agreement yet, so this step is skipped. Add one in Rental Settings and every check-out from then on will require a signature.</p>
        @elseif($agreementSigned)
          <h2 class="ia-h3" style="margin-bottom:8px">Agreement signed</h2>
          <p style="font-size:12.5px;opacity:.55">v{{ $rental->agreement_template_version }} · {{ tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') }}
            @if($rental->agreement_pdf_path) · <a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">PDF →</a>@endif
          </p>
        @else
          <h2 class="ia-h3" style="margin-bottom:10px">{{ $agreementTemplate->title }} <span style="font-size:11px;opacity:.5;font-weight:400">v{{ $agreementTemplate->version }}</span></h2>
          <div class="co-agree-body">{{ $agreementTemplate->body }}</div>
          <form method="POST" action="{{ route('tenant.rentals.bookings.agreement.sign', $rental->id) }}" style="margin-top:14px">
            @csrf
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
              <div style="flex:1;min-width:220px">
                <label class="ia-label" style="display:block;margin-bottom:5px">Customer signs by typing their full name</label>
                <input type="text" name="signer_name" maxlength="160" required class="ia-input" style="width:100%" placeholder="{{ $rental->customer?->first_name }} {{ $rental->customer?->last_name }}">
              </div>
              <button type="submit" class="ia-btn ia-btn--primary">Sign agreement</button>
            </div>
            <label style="display:flex;gap:9px;align-items:center;font-size:12.5px;margin-top:10px;cursor:pointer">
              <input type="checkbox" name="agreed" value="1" required> Customer has read and agrees to the terms above
            </label>
          </form>
        @endif
      </div>
      <div class="co-foot"><button type="button" class="ia-btn" onclick="coGo(1)">← Back</button><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(3)" {{ $agreementDone ? '' : 'disabled' }}>Continue →</button></div>
    </div>

    {{-- ------------------------------------------------- step 3 condition --}}
    <div class="co-pane" data-pane="3">
      @foreach($unitLines as $line)
        @php
          $unit  = $line->unit;
          $check = $unit ? $checksByUnit->get($unit->id) : null;
          $tpl   = $unit?->conditionTemplate;
          $items = $tpl ? (array) $tpl->items : [];
        @endphp
        <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:.5px solid var(--ia-border)">
            <div>
              <span style="font-size:12px;font-weight:550;text-transform:uppercase;letter-spacing:.06em">{{ $line->name_snapshot }}{{ $unit?->identifier ? ' · ' . $unit->identifier : '' }}</span>
              <div style="font-size:11px;opacity:.5;margin-top:2px">{{ $tpl ? $tpl->name . ' template' : 'No template — quick visual' }}</div>
            </div>
            @if($check)<span style="font-size:11px;font-weight:600;color:{{ $check->flagged ? '#E0A82E' : '#7BC96F' }}">{{ $check->flagged ? 'noted with flags' : 'recorded' }}</span>@endif
          </div>
          @if($check)
            <div style="padding:14px 18px;font-size:12.5px;opacity:.7">Out-check recorded {{ tlocal_datetime($check->performed_at, 'M j, g:i A') }}{{ $check->notes ? ' — ' . $check->notes : '' }}{{ is_array($check->photos) && count($check->photos) ? ' · ' . count($check->photos) . ' photo(s)' : '' }}</div>
          @else
            <form method="POST" action="{{ route('tenant.rentals.bookings.condition.store', $rental->id) }}" enctype="multipart/form-data" class="co-cond-form">
              @csrf
              <input type="hidden" name="unit_id" value="{{ $unit?->id }}">
              <input type="hidden" name="phase" value="check_out">
              @if(count($items))
                @foreach($items as $item)
                  @php $k = $item['key'] ?? ('item_' . $loop->index); @endphp
                  <div class="co-chk">
                    <span style="font-size:13px;flex:1">{{ $item['label'] ?? $k }}</span>
                    <input type="hidden" name="results[{{ $k }}]" value="ok">
                    <div class="co-seg" data-key="{{ $k }}">
                      <button type="button" class="ok">OK</button>
                      <button type="button">Flag</button>
                    </div>
                  </div>
                @endforeach
              @else
                <div class="co-chk">
                  <span style="font-size:13px;flex:1">Visual check — unit is complete and ready to go out</span>
                  <input type="hidden" name="results[visual]" value="ok">
                  <div class="co-seg" data-key="visual"><button type="button" class="ok">OK</button><button type="button">Flag</button></div>
                </div>
              @endif
              <div style="display:flex;gap:10px;align-items:end;padding:12px 14px;border-top:.5px solid var(--ia-border);flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                  <label class="ia-label" style="display:block;margin-bottom:4px">Notes — existing condition, scuffs, etc.</label>
                  <input type="text" name="notes" maxlength="2000" class="ia-input" style="width:100%">
                </div>
                <div>
                  <label class="ia-label" style="display:block;margin-bottom:4px">Photos (≤4)</label>
                  <input type="file" name="photos[]" accept="image/*" multiple class="ia-input" style="padding:6px">
                </div>
                <button type="submit" class="ia-btn ia-btn--primary">Save out-check</button>
              </div>
            </form>
          @endif
        </div>
      @endforeach
      <div class="co-foot"><button type="button" class="ia-btn" onclick="coGo(2)">← Back</button><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(4)">Continue →</button></div>
    </div>

    {{-- --------------------------------------------- step 4 deposit & go --}}
    <div class="co-pane" data-pane="4">
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        <h2 class="ia-h3" style="margin-bottom:8px">Deposit hold</h2>
        @if($rental->deposit_status === 'authorized')
          <p style="font-size:13px"><b>{{ format_money($rental->deposit_hold_cents) }}</b> on hold — you're set.</p>
        @elseif(tenant()->direct_payments_enabled)
          <div id="dep-start">
            <div style="display:flex;gap:6px;max-width:380px">
              <input type="number" id="dep-amount" min="0.50" step="0.01" value="{{ number_format(max(0, $rental->lines->where('kind','unit')->sum(fn ($l) => (int) ($l->unit?->effectiveDepositCents() ?? 0))) / 100, 2, '.', '') }}" class="ia-input" style="flex:1;text-align:right">
              <button type="button" class="ia-btn ia-btn--primary" id="dep-authorize">Authorize hold</button>
            </div>
            <p style="font-size:11px;opacity:.45;margin-top:6px">Authorizes the card without charging it. Skippable — cash shops can just continue.</p>
          </div>
          <div id="dep-element-wrap" style="display:none;margin-top:10px;max-width:480px">
            <div id="dep-element"></div>
            <button type="button" class="ia-btn ia-btn--primary" id="dep-confirm" style="width:100%;margin-top:8px">Place hold</button>
            <div id="dep-error" style="font-size:12px;color:#ef4444;margin-top:6px"></div>
          </div>
        @else
          <p style="font-size:12.5px;opacity:.55">Card payments aren't enabled — deposits can be taken in cash through the register, or skipped.</p>
        @endif
      </div>

      @if($balanceCents > 0)
      <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
        <h2 class="ia-h3" style="margin-bottom:8px">Balance due — {{ format_money($balanceCents) }}</h2>
        <form method="POST" action="{{ route('tenant.rentals.bookings.collect', $rental->id) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
          @csrf
          {{-- MARKER-PATCH-232B — come back to this flow after payment. --}}
          <input type="hidden" name="return_to" value="{{ parse_url(route('tenant.rentals.bookings.checkout.flow', $rental->id), PHP_URL_PATH) }}">
          <div>
            <label class="ia-label" style="display:block;margin-bottom:4px">Amount $</label>
            <input type="number" name="amount" min="0.01" step="0.01" required value="{{ number_format($balanceCents / 100, 2, '.', '') }}" class="ia-input" style="width:140px;text-align:right">
          </div>
          <button type="submit" class="ia-btn">Collect in register</button>
          <span style="font-size:11px;opacity:.45;align-self:center">Opens the register with a linked sale — cash, card, or payment link.</span>
        </form>
      </div>
      @endif

      <form method="POST" action="{{ route('tenant.rentals.bookings.checkout.complete', $rental->id) }}">
        @csrf
        <div class="co-foot">
          <button type="button" class="ia-btn" onclick="coGo(3)">← Back</button>
          <button type="submit" class="ia-btn ia-btn--primary" style="font-size:14px;padding:10px 22px">Complete check-out ✓</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ------------------------------------------------------- money rail --}}
  <div>
    <div class="ia-card" style="padding:16px 18px;margin-bottom:14px">
      <span class="ia-label">Money</span>
      <div class="co-kv" style="margin-top:6px"><span>Subtotal</span><span>{{ format_money($rental->subtotal_cents) }}</span></div>
      <div class="co-kv"><span>Tax</span><span>{{ format_money($rental->tax_cents) }}</span></div>
      <div class="co-kv" style="font-weight:650;border-top:.5px solid var(--ia-border);padding-top:8px"><span style="opacity:1">Total</span><span>{{ format_money($rental->total_cents) }}</span></div>
      <div class="co-kv"><span>Paid</span><span>{{ format_money($rental->paid_cents) }}</span></div>
      <div class="co-kv" style="font-weight:650;{{ $balanceCents > 0 ? 'color:#E0A82E' : 'color:#7BC96F' }}"><span style="opacity:1;color:inherit">Balance</span><span>{{ format_money($balanceCents) }}</span></div>
    </div>
    <div class="ia-card" style="padding:16px 18px">
      <span class="ia-label">This flow</span>
      <p style="font-size:11.5px;opacity:.55;margin-top:8px;line-height:1.6">Each step saves on its own — close this page and pick up where you left off. The agreement and condition checks stay on the rental record and come back at return time.</p>
    </div>
  </div>
</div>

<script>
function coGo(n) {
  document.querySelectorAll('.co-pane').forEach(function (p) { p.classList.toggle('on', p.dataset.pane == n); });
  document.querySelectorAll('.co-step').forEach(function (s) {
    s.classList.toggle('cur', s.dataset.step == n);
  });
  window.scrollTo({ top: 0 });
}
document.querySelectorAll('.co-step').forEach(function (s) {
  s.addEventListener('click', function () { coGo(s.dataset.step); });
});
coGo({{ $startStep }});

// OK/Flag segmented toggles write into the hidden results[] input.
document.querySelectorAll('.co-seg').forEach(function (seg) {
  var btns = seg.querySelectorAll('button');
  var input = seg.parentElement.querySelector('input[type=hidden][name^="results"]');
  btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'ok'; });
  btns[1].addEventListener('click', function () { btns[1].className = 'flag'; btns[0].className = ''; if (input) input.value = 'flag'; });
});
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

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    var dollars = parseFloat(document.getElementById('dep-amount').value || '0');
    post(intentUrl, { amount_cents: Math.round(dollars * 100) }).then(function (res) {
      if (!res.ok || !res.json.ok) {
        alert(res.json.error || 'Could not start the hold.');
        btn.disabled = false;
        return;
      }
      piId = res.json.payment_intent;
      stripe = Stripe(res.json.publishable_key);
      elements = stripe.elements({ clientSecret: res.json.client_secret });
      elements.create('payment').mount('#dep-element');
      document.getElementById('dep-element-wrap').style.display = 'block';
    }).catch(function () { alert('Could not start the hold.'); btn.disabled = false; });
  });

  document.getElementById('dep-confirm').addEventListener('click', function () {
    var confirmBtn = this;
    confirmBtn.disabled = true;
    document.getElementById('dep-error').textContent = '';
    stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
      if (result.error) {
        document.getElementById('dep-error').textContent = result.error.message || 'Card was not authorized.';
        confirmBtn.disabled = false;
        return;
      }
      post(confirmUrl, { payment_intent: piId }).then(function (res) {
        if (res.ok && res.json.ok) { window.location.reload(); }
        else {
          document.getElementById('dep-error').textContent = (res.json && res.json.error) || 'Could not verify the hold.';
          confirmBtn.disabled = false;
        }
      });
    });
  });
})();
</script>
@endif

@endsection
