@extends('layouts.tenant.app')
@php $pageTitle = 'Return ' . $rental->rental_number; @endphp

{{-- MARKER-PATCH-233 — guided return: Inspect → Charges → Close. In-checks
     render beside the 232 out-checks; charges collect through the register
     (232B round-trip); deposit + routing decisions close it out. --}}

@push('styles')
<style>
  .rt-steps{display:flex;align-items:center;margin-bottom:24px;flex-wrap:wrap}
  .rt-step{display:flex;align-items:center;gap:9px;padding:0 4px;cursor:pointer}
  .rt-n{width:24px;height:24px;border-radius:50%;border:1.5px solid var(--ia-border-strong);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:650;color:var(--ia-text-dim,rgba(255,255,255,.55));flex-shrink:0}
  .rt-step.done .rt-n{background:var(--ia-accent,#BEF264);border-color:var(--ia-accent,#BEF264);color:#0a0a0a}
  .rt-step.cur .rt-n{border-color:var(--ia-accent,#BEF264);color:var(--ia-accent,#BEF264)}
  .rt-t{font-size:12.5px;font-weight:550;color:var(--ia-text-dim,rgba(255,255,255,.55))}
  .rt-step.cur .rt-t,.rt-step.done .rt-t{color:var(--ia-text,#f0f0f0)}
  .rt-bar{width:34px;height:1.5px;background:var(--ia-border);margin:0 6px}
  .rt-pane{display:none}
  .rt-pane.on{display:block;animation:rtfade .15s ease}
  @keyframes rtfade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
  .rt-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
  @media(max-width:980px){.rt-grid{grid-template-columns:1fr}}
  .rt-kv{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:5px 0}
  .rt-kv span:first-child{opacity:.55}
  .rt-chk{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:.5px solid var(--ia-border)}
  .rt-chk:last-child{border-bottom:none}
  .rt-seg{display:inline-flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);overflow:hidden;flex-shrink:0}
  .rt-seg button{padding:5px 12px;font-size:11.5px;background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-weight:600;cursor:pointer}
  .rt-seg button.ok{background:rgba(123,201,111,.18);color:#7BC96F}
  .rt-seg button.flag{background:rgba(239,68,68,.16);color:#ef4444}
  .rt-seg button.mt{background:rgba(224,87,62,.16);color:#E0573E}
  .rt-out-note{font-size:11.5px;opacity:.55;padding:9px 14px;background:rgba(255,255,255,.025);border-bottom:.5px solid var(--ia-border)}
  .rt-foot{display:flex;justify-content:space-between;margin-top:16px}
  .rt-dmg-row{display:flex;gap:8px;margin-bottom:8px}
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

@php $late = $rental->isOverdue(); @endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Return — {{ $rental->rental_number }}</h1>
    <p class="ia-page-subtitle">{{ $rental->customer?->first_name }} {{ $rental->customer?->last_name }} · due {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}
      @if($lateMinutes > 0)<span style="color:#ef4444;font-weight:600"> — {{ $lateMinutes >= 60 ? floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm' : $lateMinutes . 'm' }} overdue</span>@endif
    </p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.show', $rental->id) }}" class="ia-btn">Back to booking</a>
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

@php
  $allUnits     = $unitLines->count();
  $checkedUnits = $unitLines->filter(fn ($l) => $inChecks->has($l->unit_id))->count();
  $inspectDone  = $allUnits > 0 && $checkedUnits >= $allUnits;
  $startStep    = $inspectDone ? 2 : 1;
@endphp

<div class="rt-steps" id="rt-steps">
  <div class="rt-step {{ $inspectDone ? 'done' : '' }}" data-step="1"><span class="rt-n">{{ $inspectDone ? '✓' : '1' }}</span><span class="rt-t">Inspect ({{ $checkedUnits }}/{{ $allUnits }})</span></div>
  <div class="rt-bar"></div>
  <div class="rt-step" data-step="2"><span class="rt-n">2</span><span class="rt-t">Charges</span></div>
  <div class="rt-bar"></div>
  <div class="rt-step" data-step="3"><span class="rt-n">3</span><span class="rt-t">Deposit &amp; close</span></div>
</div>

<div class="rt-grid">
  <div>
    {{-- ---------------------------------------------------- step 1 inspect --}}
    <div class="rt-pane" data-pane="1">
      @foreach($unitLines as $line)
        @php
          $unit     = $line->unit;
          $outCheck = $unit ? $outChecks->get($unit->id) : null;
          $inCheck  = $unit ? $inChecks->get($unit->id) : null;
          $tpl      = $unit?->conditionTemplate;
          $items    = $tpl ? (array) $tpl->items : [];
        @endphp
        <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:.5px solid var(--ia-border)">
            <div>
              <span style="font-size:12px;font-weight:550;text-transform:uppercase;letter-spacing:.06em">{{ $line->name_snapshot }}{{ $unit?->identifier ? ' · ' . $unit->identifier : '' }}</span>
              <div style="font-size:11px;opacity:.5;margin-top:2px">{{ $tpl ? $tpl->name . ' template' : 'No template — quick visual' }}</div>
            </div>
            @if($inCheck)<span style="font-size:11px;font-weight:600;color:{{ $inCheck->flagged ? '#ef4444' : '#7BC96F' }}">{{ $inCheck->flagged ? 'flagged' : 'clear' }}</span>@endif
          </div>

          @if($outCheck)
            <div class="rt-out-note">
              Out-check {{ tlocal_datetime($outCheck->performed_at, 'M j, g:i A') }}{{ $outCheck->notes ? ' — "' . $outCheck->notes . '"' : '' }}
              @if(is_array($outCheck->photos) && count($outCheck->photos))
                ·
                @foreach($outCheck->photos as $i => $p)
                  <a href="{{ Storage::disk('public')->url($p) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">photo {{ $i + 1 }}</a>{{ !$loop->last ? ', ' : '' }}
                @endforeach
              @endif
              @php $outFlags = collect((array) $outCheck->results)->filter(fn ($v) => $v === 'flag')->keys(); @endphp
              @if($outFlags->count()) · <span style="color:#E0A82E">flagged going out: {{ $outFlags->implode(', ') }}</span>@endif
            </div>
          @else
            <div class="rt-out-note">No out-check on file — this rental went out before condition checks (or via quick check-out).</div>
          @endif

          @if($inCheck)
            <div style="padding:14px 18px;font-size:12.5px;opacity:.7">In-check recorded {{ tlocal_datetime($inCheck->performed_at, 'M j, g:i A') }}{{ $inCheck->notes ? ' — ' . $inCheck->notes : '' }}{{ is_array($inCheck->photos) && count($inCheck->photos) ? ' · ' . count($inCheck->photos) . ' photo(s)' : '' }}</div>
          @else
            <form method="POST" action="{{ route('tenant.rentals.bookings.condition.store', $rental->id) }}" enctype="multipart/form-data">
              @csrf
              <input type="hidden" name="unit_id" value="{{ $unit?->id }}">
              <input type="hidden" name="phase" value="check_in">
              @if(count($items))
                @foreach($items as $item)
                  @php $k = $item['key'] ?? ('item_' . $loop->index); @endphp
                  <div class="rt-chk">
                    <span style="font-size:13px;flex:1">{{ $item['label'] ?? $k }}</span>
                    <input type="hidden" name="results[{{ $k }}]" value="ok">
                    <div class="rt-seg" data-key="{{ $k }}">
                      <button type="button" class="ok">OK</button>
                      <button type="button">Flag</button>
                    </div>
                  </div>
                @endforeach
              @else
                <div class="rt-chk">
                  <span style="font-size:13px;flex:1">Visual check — returned complete, no new damage</span>
                  <input type="hidden" name="results[visual]" value="ok">
                  <div class="rt-seg" data-key="visual"><button type="button" class="ok">OK</button><button type="button">Flag</button></div>
                </div>
              @endif
              <div style="display:flex;gap:10px;align-items:end;padding:12px 14px;border-top:.5px solid var(--ia-border);flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                  <label class="ia-label" style="display:block;margin-bottom:4px">Notes — new damage, missing parts, etc.</label>
                  <input type="text" name="notes" maxlength="2000" class="ia-input" style="width:100%">
                </div>
                <div>
                  <label class="ia-label" style="display:block;margin-bottom:4px">Photos (≤4)</label>
                  <input type="file" name="photos[]" accept="image/*" multiple class="ia-input" style="padding:6px">
                </div>
                <button type="submit" class="ia-btn ia-btn--primary">Save in-check</button>
              </div>
            </form>
          @endif
        </div>
      @endforeach
      <div class="rt-foot"><span></span><button type="button" class="ia-btn ia-btn--primary" onclick="rtGo(2)">Continue to charges →</button></div>
    </div>

    {{-- ---------------------------------------------------- step 2 charges --}}
    <div class="rt-pane" data-pane="2">
      <form method="POST" action="{{ route('tenant.rentals.bookings.return.charges', $rental->id) }}">
        @csrf
        <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
          <h2 class="ia-h3" style="margin-bottom:8px">Late fee</h2>
          @if($lateMinutes > 0)
            <p style="font-size:12.5px;opacity:.55;margin-bottom:10px">{{ $lateMinutes >= 60 ? floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm' : $lateMinutes . 'm' }} past due.
              @if($suggestedLateFeeCents > 0)Policy suggests <b style="opacity:1">{{ format_money($suggestedLateFeeCents) }}</b> — edit or zero it to waive.@elseif($latePolicy['per_hour_cents'] === 0)No late-fee rate is set (Rental Settings).@else Within the {{ $latePolicy['grace_minutes'] }}-minute grace period.@endif
            </p>
          @else
            <p style="font-size:12.5px;opacity:.55;margin-bottom:10px">Returned on time — nothing suggested.</p>
          @endif
          <div style="display:flex;gap:8px;align-items:center">
            <span style="font-size:13px;opacity:.55">$</span>
            <input type="number" name="late_fee" min="0" step="0.01" value="{{ number_format($suggestedLateFeeCents / 100, 2, '.', '') }}" class="ia-input" style="width:130px;text-align:right">
          </div>
        </div>

        <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
          <h2 class="ia-h3" style="margin-bottom:8px">Damage &amp; missing items</h2>
          <p style="font-size:12.5px;opacity:.55;margin-bottom:10px">Anything flagged in step 1 that costs money goes here.</p>
          <div id="rt-dmg-rows">
            <div class="rt-dmg-row">
              <input type="text" name="damage_labels[]" maxlength="200" placeholder="e.g. Rear tire sidewall cut — Maxxis Dissector" class="ia-input" style="flex:1">
              <input type="number" name="damage_amounts[]" min="0" step="0.01" placeholder="0.00" class="ia-input" style="width:110px;text-align:right">
            </div>
          </div>
          <button type="button" class="ia-btn ia-btn--sm" onclick="rtAddDmgRow()">+ Another line</button>
        </div>

        <div class="ia-card" style="padding:14px 18px;margin-bottom:14px;font-size:11.5px;opacity:.6;line-height:1.55">
          Collecting here opens the register with one linked sale (cash, card, or payment link) and brings you back. <b style="opacity:1">Taking charges from the deposit instead?</b> Skip this — capture in step 3 writes its own charge line.
        </div>

        <div class="rt-foot">
          <button type="button" class="ia-btn" onclick="rtGo(1)">← Back</button>
          <div style="display:flex;gap:8px">
            <button type="button" class="ia-btn" onclick="rtGo(3)">No charges — skip →</button>
            <button type="submit" class="ia-btn ia-btn--primary">Add charges &amp; collect in register →</button>
          </div>
        </div>
      </form>
    </div>

    {{-- ---------------------------------------------- step 3 deposit & close --}}
    <div class="rt-pane" data-pane="3">
      <form method="POST" action="{{ route('tenant.rentals.bookings.return.complete', $rental->id) }}">
        @csrf

        <div class="ia-card" style="padding:18px 20px;margin-bottom:14px">
          <h2 class="ia-h3" style="margin-bottom:8px">Deposit</h2>
          @if($rental->deposit_status === 'authorized')
            <p style="font-size:13px;margin-bottom:12px"><b>{{ format_money($rental->deposit_hold_cents) }}</b> on hold.</p>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:12.5px">
              <label style="display:flex;gap:9px;align-items:center;cursor:pointer"><input type="radio" name="deposit_action" value="release" checked> Release the full hold — clean return</label>
              <label style="display:flex;gap:9px;align-items:center;cursor:pointer"><input type="radio" name="deposit_action" value="hold"> Keep holding — decide later from the booking page</label>
            </div>
            <p style="font-size:11.5px;opacity:.5;margin-top:10px">Need to capture for damage? Finish the return with "keep holding", then capture from the booking page — the capture writes its own charge line and sale.</p>
          @elseif(in_array($rental->deposit_status, ['captured', 'partially_captured'], true))
            <p style="font-size:12.5px;opacity:.65">Hold {{ $rental->deposit_status === 'captured' ? 'fully' : 'partially' }} captured — nothing to decide.</p>
          @else
            <p style="font-size:12.5px;opacity:.55">No deposit was held on this rental.</p>
          @endif
        </div>

        <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:14px">
          <div style="padding:13px 18px;border-bottom:.5px solid var(--ia-border)"><span style="font-size:12px;font-weight:550;text-transform:uppercase;letter-spacing:.06em">Where does each unit go?</span></div>
          @foreach($unitLines as $line)
            @php $unit = $line->unit; @endphp
            @if($unit)
              <div style="display:flex;gap:12px;align-items:center;padding:11px 18px;border-bottom:.5px solid var(--ia-border);flex-wrap:wrap">
                <div style="flex:1;min-width:180px">
                  <div style="font-size:13px;font-weight:600">{{ $line->name_snapshot }}{{ $unit->identifier ? ' · ' . $unit->identifier : '' }}</div>
                  @if(($inChecks->get($unit->id)?->flagged) ?? false)<div style="font-size:11px;color:#ef4444">flagged at inspection</div>@endif
                </div>
                <div class="rt-seg rt-route" data-unit="{{ $unit->id }}">
                  <button type="button" class="{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? '' : 'ok' }}">Available</button>
                  <button type="button" class="{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? 'mt' : '' }}">Maintenance</button>
                </div>
                <input type="hidden" name="routing[{{ $unit->id }}]" value="{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? 'maintenance' : 'available' }}">
                <input type="text" name="routing_note[{{ $unit->id }}]" maxlength="500" placeholder="Maintenance note…" class="ia-input" style="width:220px;{{ (($inChecks->get($unit->id)?->flagged) ?? false) ? '' : 'display:none' }}">
              </div>
            @endif
          @endforeach
          <div style="padding:10px 18px;font-size:11.5px;opacity:.5">Maintenance blocks the unit from new bookings until you clear it on the Fleet page.</div>
        </div>

        <div class="rt-foot">
          <button type="button" class="ia-btn" onclick="rtGo(2)">← Back</button>
          <button type="submit" class="ia-btn ia-btn--primary" style="font-size:14px;padding:10px 22px">Complete return ✓</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ------------------------------------------------------- money rail --}}
  <div>
    <div class="ia-card" style="padding:16px 18px;margin-bottom:14px">
      <span class="ia-label">Money</span>
      <div class="rt-kv" style="margin-top:6px"><span>Total</span><span>{{ format_money($rental->total_cents) }}</span></div>
      <div class="rt-kv"><span>Paid</span><span>{{ format_money($rental->paid_cents) }}</span></div>
      <div class="rt-kv" style="font-weight:650;{{ $balanceCents > 0 ? 'color:#E0A82E' : 'color:#7BC96F' }}"><span style="opacity:1;color:inherit">Balance</span><span>{{ format_money($balanceCents) }}</span></div>
      <div class="rt-kv" style="border-top:.5px solid var(--ia-border);padding-top:8px"><span>Deposit</span><span>{{ $rental->deposit_status === 'authorized' ? format_money($rental->deposit_hold_cents) . ' held' : ucfirst(str_replace('_', ' ', $rental->deposit_status)) }}</span></div>
    </div>
    <div class="ia-card" style="padding:16px 18px">
      <span class="ia-label">This flow</span>
      <p style="font-size:11.5px;opacity:.55;margin-top:8px;line-height:1.6">Each step saves on its own. Charges land on the rental and route through the register; nothing here touches the ledger directly.</p>
    </div>
  </div>
</div>

<script>
function rtGo(n) {
  document.querySelectorAll('.rt-pane').forEach(function (p) { p.classList.toggle('on', p.dataset.pane == n); });
  document.querySelectorAll('.rt-step').forEach(function (s) { s.classList.toggle('cur', s.dataset.step == n); });
  window.scrollTo({ top: 0 });
}
document.querySelectorAll('.rt-step').forEach(function (s) {
  s.addEventListener('click', function () { rtGo(s.dataset.step); });
});
rtGo({{ $startStep }});

// OK/Flag toggles (inspect step) — writes into hidden results[] input.
document.querySelectorAll('.rt-seg:not(.rt-route)').forEach(function (seg) {
  var btns = seg.querySelectorAll('button');
  var input = seg.parentElement.querySelector('input[type=hidden][name^="results"]');
  btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'ok'; });
  btns[1].addEventListener('click', function () { btns[1].className = 'flag'; btns[0].className = ''; if (input) input.value = 'flag'; });
});

// Available/Maintenance routing toggles — writes routing[unit] and shows the note field.
document.querySelectorAll('.rt-route').forEach(function (seg) {
  var btns = seg.querySelectorAll('button');
  var row = seg.parentElement;
  var input = row.querySelector('input[type=hidden][name^="routing["]');
  var note = row.querySelector('input[name^="routing_note"]');
  btns[0].addEventListener('click', function () { btns[0].className = 'ok'; btns[1].className = ''; if (input) input.value = 'available'; if (note) note.style.display = 'none'; });
  btns[1].addEventListener('click', function () { btns[1].className = 'mt'; btns[0].className = ''; if (input) input.value = 'maintenance'; if (note) note.style.display = ''; });
});

function rtAddDmgRow() {
  var wrap = document.getElementById('rt-dmg-rows');
  var row = document.createElement('div');
  row.className = 'rt-dmg-row';
  row.innerHTML = '<input type="text" name="damage_labels[]" maxlength="200" placeholder="Description" class="ia-input" style="flex:1">'
    + '<input type="number" name="damage_amounts[]" min="0" step="0.01" placeholder="0.00" class="ia-input" style="width:110px;text-align:right">';
  wrap.appendChild(row);
}
</script>

@endsection
