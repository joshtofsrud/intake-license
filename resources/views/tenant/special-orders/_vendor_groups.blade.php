
{{-- MARKER-SO-PLACEMENT — the vendor placement board. Every needed order with
     the vendors that actually carry it, grouped by current assignment. Assign
     moves buckets (reversible, no side effects); Mark ordered is the
     committing action. --}}

<style>
  .pb-head{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:6px}
  .pb-fresh{font-size:11.5px;color:var(--ia-text-muted);margin-bottom:18px}
  .pb-vend{border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);margin-bottom:12px;overflow:hidden;background:var(--ia-surface)}
  .pb-vend.none{border-color:rgba(240,149,149,.35)}
  .pb-hd{display:flex;align-items:center;gap:11px;padding:13px 15px;background:rgba(0,0,0,.18);flex-wrap:wrap}
  .pb-vn{font-weight:800;font-size:14px}
  .pb-vn .warn{color:#F09595}
  .pb-vc{font-size:11.5px;color:var(--ia-text-muted)}
  .pb-vtot{margin-left:auto;font-size:13.5px;font-weight:800;font-variant-numeric:tabular-nums}
  .pb-freight{display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:0.5px solid var(--ia-border);font-size:11.5px;flex-wrap:wrap}
  .pb-bar{flex:1;min-width:110px;height:5px;border-radius:100px;background:rgba(255,255,255,.08);overflow:hidden}
  .pb-bar span{display:block;height:100%;background:var(--ia-accent);border-radius:100px}
  .pb-bar.met span{background:#7FD98F}
  .pb-fnote{color:var(--ia-text-muted)}
  .pb-fnote b{color:var(--ia-accent)}
  .pb-fnote.met b{color:#7FD98F}
  .pb-row{display:flex;align-items:center;gap:11px;padding:11px 15px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap}
  .pb-row:last-of-type{border-bottom:none}
  .pb-cb{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.25);flex:none;display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:#0B0B0B;font-weight:900;cursor:pointer}
  .pb-cb.on{background:var(--ia-accent);border-color:var(--ia-accent)}
  .pb-cb.on:after{content:"\2713"}
  .pb-ident{flex:1;min-width:170px}
  .pb-nm{font-weight:600;font-size:13px}
  .pb-mt{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
  .pb-sel{background:rgba(0,0,0,.2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:7px 9px;min-width:230px}
  .pb-sel:focus{outline:none;border-color:var(--ia-accent)}
  .pb-assign{font-family:inherit;font-size:11.5px;font-weight:700;border-radius:8px;padding:7px 12px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text)}
  .pb-assign:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  .pb-noopt{font-size:11.5px;color:#F09595}
  .pb-bar-row{display:flex;align-items:center;gap:9px;padding:11px 15px;border-top:0.5px solid var(--ia-border);flex-wrap:wrap;background:rgba(0,0,0,.18)}
  .pb-sum{font-size:12px;color:var(--ia-text-muted)}
  .pb-sum b{color:var(--ia-text)}
  .pb-in{background:rgba(0,0,0,.2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:7px 9px}
  .pb-empty{padding:30px;text-align:center;color:var(--ia-text-muted);font-size:13px}
</style>

<div class="pb-fresh">
  @if($vcheckedAt)
    Live cost and availability last checked {{ $vcheckedAt->diffForHumans() }} — from your item-vendor catalog.
  @else
    Costs shown are your catalog costs; live availability has not been checked yet.
  @endif
</div>

@if(empty($vgroups))
  <div class="ia-card"><div class="pb-empty">Nothing waiting to be placed.</div></div>
@endif

@foreach($vgroups as $vendorId => $rows)
  @php
    $vendor = $vendorId !== '' ? ($vvendors[$vendorId] ?? null) : null;
    $groupTotal = 0;
    foreach ($rows as $r) {
      $opt = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vendorId);
      $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
      $groupTotal += (int) $unit * (int) $r->quantity;
    }
    $min = $vendor->free_freight_cents ?? null;
  @endphp

  <div class="pb-vend {{ $vendorId === '' ? 'none' : '' }}" data-vendor="{{ $vendorId }}">
    <div class="pb-hd">
      <span class="pb-vn">
        @if($vendorId === '')<span class="warn">No vendor yet</span>@else{{ $vendor->name ?? 'Vendor' }}@endif
      </span>
      <span class="pb-vc">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('item', count($rows)) }}</span>
      @if($vendorId !== '')
        <span class="pb-vtot">${{ number_format($groupTotal / 100, 2) }}</span>
      @endif
    </div>

    @if($vendorId !== '' && $min)
      @php $pct = min(100, (int) round($groupTotal / max(1, $min) * 100)); $met = $groupTotal >= $min; @endphp
      <div class="pb-freight">
        <span class="pb-bar {{ $met ? 'met' : '' }}"><span style="width:{{ $pct }}%"></span></span>
        <span class="pb-fnote {{ $met ? 'met' : '' }}">
          @if($met)
            <b>Free freight met</b> — ${{ number_format($groupTotal / 100, 2) }} of ${{ number_format($min / 100, 2) }}
          @else
            <b>${{ number_format(($min - $groupTotal) / 100, 2) }}</b> more for free freight
            (${{ number_format($groupTotal / 100, 2) }} of ${{ number_format($min / 100, 2) }})
          @endif
        </span>
      </div>
    @endif

    @foreach($rows as $so)
      @php $opts = $voptions[$so->inventory_item_id] ?? []; @endphp
      <div class="pb-row" data-so="{{ $so->id }}">
        @if($vendorId !== '')
          <span class="pb-cb on" data-pb-cb></span>
        @else
          <span class="pb-cb" style="visibility:hidden"></span>
        @endif

        <div class="pb-ident">
          <div class="pb-nm">{{ $so->item_name_snapshot }}</div>
          <div class="pb-mt">
            {{ $so->so_number }} · qty {{ $so->quantity }} ·
            {{ $so->customer ? trim($so->customer->first_name . ' ' . $so->customer->last_name) : 'stock' }}
          </div>
        </div>

        @if(empty($opts))
          <span class="pb-noopt">No vendor carries this yet — add one on the item</span>
        @else
          <select class="pb-sel" data-pb-select>
            @foreach($opts as $o)
              <option value="{{ $o['vendor_id'] }}" @selected($o['vendor_id'] === $vendorId)>
                {{ $o['name'] }}
                · {{ $o['avail'] === null ? 'stock unknown' : ($o['avail'] > 0 ? $o['avail'] . ' avail' : 'none in stock') }}
                @if($o['cost']) · ${{ number_format($o['cost'] / 100, 2) }} @endif
                @if($o['lead']) · {{ $o['lead'] }}d @endif
                @if($o['preferred']) · preferred @endif
              </option>
            @endforeach
          </select>
          <button type="button" class="pb-assign" data-pb-assign>{{ $vendorId === '' ? 'Assign' : 'Reassign' }}</button>
        @endif
      </div>
    @endforeach

    @if($vendorId !== '')
      <div class="pb-bar-row">
        <span class="pb-sum" data-pb-sum><b>{{ count($rows) }}</b> selected</span>
        <input type="text" class="pb-in" placeholder="PO number" data-pb-po style="width:130px">
        <input type="date" class="pb-in" data-pb-eta value="{{ now()->addDays(7)->toDateString() }}">
        <button type="button" class="ia-btn ia-btn--primary" data-pb-order>
          Mark ordered from {{ $vendor->name ?? 'vendor' }}
        </button>
      </div>
    @endif
  </div>
@endforeach

<script>
(function () {
  var assignUrl = @json(route('tenant.special-orders.assign-vendor', ['id' => '__ID__']));
  var batchUrl  = @json(route('tenant.special-orders.mark-ordered-batch'));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });
  }

  function refreshSum(box) {
    var sel = box.querySelectorAll('[data-pb-cb].on').length;
    var el  = box.querySelector('[data-pb-sum]');
    if (el) el.innerHTML = '<b>' + sel + '</b> selected';
  }

  document.addEventListener('click', function (e) {
    // toggle selection
    var cb = e.target.closest('[data-pb-cb]');
    if (cb) {
      cb.classList.toggle('on');
      refreshSum(cb.closest('.pb-vend'));
      return;
    }

    // assign / reassign
    var btn = e.target.closest('[data-pb-assign]');
    if (btn) {
      var row = btn.closest('.pb-row');
      var vid = row.querySelector('[data-pb-select]').value;
      btn.disabled = true;
      post(assignUrl.replace('__ID__', row.dataset.so), { vendor_id: vid })
        .then(function (j) {
          if (j && j.ok) { window.location.reload(); }
          else { btn.disabled = false; if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not assign.'); }
        })
        .catch(function () { btn.disabled = false; if (window.IntakeToast) IntakeToast.error('Network error.'); });
      return;
    }

    // batch order
    var ob = e.target.closest('[data-pb-order]');
    if (ob) {
      var box = ob.closest('.pb-vend');
      var ids = Array.prototype.map.call(box.querySelectorAll('[data-pb-cb].on'), function (c) {
        return c.closest('.pb-row').dataset.so;
      });
      if (!ids.length) { if (window.IntakeToast) IntakeToast.error('Nothing selected.'); return; }
      var po  = box.querySelector('[data-pb-po]').value.trim();
      var eta = box.querySelector('[data-pb-eta]').value;
      if (!po)  { if (window.IntakeToast) IntakeToast.error('PO number is required.'); return; }
      if (!eta) { if (window.IntakeToast) IntakeToast.error('Expected date is required.'); return; }

      ob.disabled = true;
      post(batchUrl, { ids: ids, vendor_id: box.dataset.vendor, po_number: po, expected_arrival_date: eta })
        .then(function (j) {
          if (j && j.ordered) {
            if (window.IntakeToast) {
              IntakeToast.success(j.ordered + ' marked ordered' + (j.failed && j.failed.length ? ' — ' + j.failed.length + ' failed' : ''));
            }
            window.location.reload();
          } else {
            ob.disabled = false;
            if (window.IntakeToast) IntakeToast.error((j.failed && j.failed[0]) || 'Could not mark ordered.');
          }
        })
        .catch(function () { ob.disabled = false; if (window.IntakeToast) IntakeToast.error('Network error.'); });
    }
  });
})();
</script>
