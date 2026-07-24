{{-- MARKER-SO-SCROLL — the open special-orders screen: always grouped by
     vendor (that is how orders get placed), one scroll region instead of
     pages, and the batch action riding in a sticky group header so it stays
     in reach while scrolling a long vendor. --}}

<style>
  .sog-scroll{max-height:66vh;overflow-y:auto;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);background:var(--ia-surface)}
  .sog-scroll::-webkit-scrollbar{width:9px}
  .sog-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.14);border-radius:100px}

  .sog-head{position:sticky;top:0;z-index:3;background:var(--ia-surface-2,#171717);border-bottom:0.5px solid var(--ia-border);padding:11px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .sog-head.none{background:rgba(240,149,149,.07)}
  .sog-name{font-weight:800;font-size:13.5px}
  .sog-name .warn{color:#F09595}
  .sog-count{font-size:11.5px;color:var(--ia-text-muted)}
  .sog-act{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .sog-tot{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums}
  .sog-in{background:rgba(0,0,0,.22);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:11.5px;padding:6px 8px}

  .sog-freight{display:flex;align-items:center;gap:9px;padding:7px 14px;background:rgba(0,0,0,.16);border-bottom:0.5px solid rgba(255,255,255,.05);font-size:11px}
  .sog-bar{flex:1;min-width:80px;height:4px;border-radius:100px;background:rgba(255,255,255,.09);overflow:hidden}
  .sog-bar span{display:block;height:100%;background:var(--ia-accent)}
  .sog-bar.met span{background:#7FD98F}
  .sog-fnote{color:var(--ia-text-muted)}
  .sog-fnote b{color:var(--ia-accent)}
  .sog-fnote.met b{color:#7FD98F}

  .sog-row{display:flex;align-items:center;gap:11px;padding:11px 14px;border-bottom:0.5px solid rgba(255,255,255,.05);flex-wrap:wrap}
  .sog-row:hover{background:rgba(255,255,255,.02)}
  .sog-cb{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.25);flex:none;display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:#0B0B0B;font-weight:900;cursor:pointer}
  .sog-cb.on{background:var(--ia-accent);border-color:var(--ia-accent)}
  .sog-cb.on:after{content:"\2713"}
  .sog-ident{flex:1;min-width:165px}
  .sog-nm{font-weight:600;font-size:13px}
  .sog-mt{font-size:11.5px;color:var(--ia-text-muted);margin-top:3px;display:flex;gap:7px;flex-wrap:wrap;align-items:center}
  .sog-sel{background:rgba(0,0,0,.22);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:6px 8px;min-width:200px}
  .sog-sel:focus{outline:none;border-color:var(--ia-accent)}
  .sog-assign{font-family:inherit;font-size:11px;font-weight:700;border-radius:8px;padding:6px 10px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text-muted)}
  .sog-assign:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  .sog-noopt{font-size:11px;color:#F09595}
  .sog-foot{display:flex;align-items:center;gap:10px;padding:11px 3px;font-size:11.5px;color:var(--ia-text-muted);flex-wrap:wrap}
  .sog-empty{padding:34px;text-align:center;color:var(--ia-text-muted);font-size:13px}
  @media(max-width:640px){
    .sog-scroll{max-height:none}
    .sog-head{position:static}
    .sog-act{width:100%;margin-left:0}
    .sog-sel{min-width:0;flex:1}
  }
</style>

@php
  // Footer totals describe the WHOLE list — the sentence is only ever true
  // without pagination, which is half the reason this scrolls.
  $sogAllTotal = 0; $sogNoVendor = 0; $sogVendorsUsed = [];
  foreach ($vgroups as $vid => $rows) {
    foreach ($rows as $r) {
      $opt  = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vid);
      $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
      if ($vid === '') { $sogNoVendor++; }
      else { $sogAllTotal += (int) $unit * (int) $r->quantity; $sogVendorsUsed[$vid] = true; }
    }
  }
@endphp

<div class="sog-scroll" id="sog">
  @forelse($vgroups as $vendorId => $rows)
    @php
      $vendor = $vendorId !== '' ? ($vvendors[$vendorId] ?? null) : null;
      $groupTotal = 0;
      foreach ($rows as $r) {
        $opt  = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vendorId);
        $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
        $groupTotal += (int) $unit * (int) $r->quantity;
      }
      $min = $vendor->free_freight_cents ?? null;
    @endphp

    <div class="sog-head {{ $vendorId === '' ? 'none' : '' }}" data-vendor="{{ $vendorId }}">
      <span class="sog-name">
        @if($vendorId === '')<span class="warn">No vendor yet</span>@else{{ $vendor->name ?? 'Vendor' }}@endif
      </span>
      <span class="sog-count" data-sog-count>
        {{ count($rows) }} {{ \Illuminate\Support\Str::plural('item', count($rows)) }}@if($vendorId === '') — choose a vendor before ordering @endif
      </span>
      @if($vendorId !== '')
        <span class="sog-act">
          <span class="sog-tot">${{ number_format($groupTotal / 100, 2) }}</span>
          <input type="text" class="sog-in" placeholder="PO #" data-sog-po style="width:92px">
          <input type="date" class="sog-in" data-sog-eta value="{{ now()->addDays(7)->toDateString() }}">
          <button type="button" class="ia-btn ia-btn--primary" style="padding:6px 12px;font-size:11.5px" data-sog-order>
            Mark ordered
          </button>
        </span>
      @endif
    </div>

    @if($vendorId !== '' && $min)
      @php $pct = min(100, (int) round($groupTotal / max(1, $min) * 100)); $met = $groupTotal >= $min; @endphp
      <div class="sog-freight">
        <span class="sog-bar {{ $met ? 'met' : '' }}"><span style="width:{{ $pct }}%"></span></span>
        <span class="sog-fnote {{ $met ? 'met' : '' }}">
          @if($met)
            <b>free freight met</b> · ${{ number_format($groupTotal / 100, 2) }}
          @else
            <b>${{ number_format(($min - $groupTotal) / 100, 2) }}</b> more for free freight
          @endif
        </span>
      </div>
    @endif

    @foreach($rows as $so)
      @php
        $opts = $voptions[$so->inventory_item_id] ?? [];
        $og   = $origins[$so->id] ?? null;
      @endphp
      <div class="sog-row" data-so="{{ $so->id }}">
        @if($vendorId !== '')
          <span class="sog-cb on" data-sog-cb></span>
        @else
          <span class="sog-cb" style="visibility:hidden"></span>
        @endif

        <div class="sog-ident">
          <div class="sog-nm">{{ $so->item_name_snapshot }}</div>
          <div class="sog-mt">
            <span>{{ $so->so_number }} · qty {{ $so->quantity }} ·
              {{ $so->customer ? trim($so->customer->first_name . ' ' . $so->customer->last_name) : 'stock' }}</span>
            @if($og)
              <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
            @endif
            <span style="opacity:.6">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>
            @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
              <span style="opacity:.6">auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}</span>
            @endif
            @if($og && in_array($og['state'], ['orphan', 'unknown'], true))
              <span class="so-origin-acts" data-so="{{ $so->id }}">
                <button type="button" class="so-oa" data-so-keep>Still needed</button>
                <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
              </span>
            @endif
          </div>
        </div>

        @if(empty($opts))
          <span class="sog-noopt">No vendor carries this yet — add one on the item</span>
        @else
          <select class="sog-sel" data-sog-select>
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
          <button type="button" class="sog-assign" data-sog-assign>{{ $vendorId === '' ? 'Assign' : 'Move' }}</button>
        @endif
      </div>
    @endforeach
  @empty
    <div class="sog-empty">Nothing waiting to be placed.</div>
  @endforelse
</div>

<div class="sog-foot">
  <span>
    {{ $total }} open · ${{ number_format($sogAllTotal / 100, 2) }} across
    {{ count($sogVendorsUsed) }} {{ \Illuminate\Support\Str::plural('vendor', count($sogVendorsUsed)) }}
    @if($sogNoVendor) · <span style="color:#F09595">{{ $sogNoVendor }} still need a vendor</span> @endif
  </span>
  @if($total > $scrollCap)
    <span style="color:#F5C56B">showing the first {{ $scrollCap }} — clear some to see the rest</span>
  @endif
  <span style="margin-left:auto">
    @if($vcheckedAt) live cost/stock checked {{ $vcheckedAt->diffForHumans() }} @else costs from your catalog; live stock not checked yet @endif
  </span>
</div>

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

  // Rows between one sticky header and the next belong to that group.
  function rowsFor(head) {
    var out = [], n = head.nextElementSibling;
    while (n && !n.classList.contains('sog-head')) {
      if (n.classList.contains('sog-row')) out.push(n);
      n = n.nextElementSibling;
    }
    return out;
  }

  function headFor(row) {
    var h = row.previousElementSibling;
    while (h && !h.classList.contains('sog-head')) h = h.previousElementSibling;
    return h;
  }

  function refresh(head) {
    if (!head) return;
    var rows = rowsFor(head);
    var sel  = rows.filter(function (r) {
      var c = r.querySelector('[data-sog-cb]');
      return c && c.classList.contains('on');
    });
    var c = head.querySelector('[data-sog-count]');
    if (c) c.textContent = rows.length + ' items · ' + sel.length + ' selected';
    var btn = head.querySelector('[data-sog-order]');
    if (btn) btn.disabled = sel.length === 0;
  }

  document.addEventListener('click', function (e) {
    var cb = e.target.closest('[data-sog-cb]');
    if (cb) {
      cb.classList.toggle('on');
      refresh(headFor(cb.closest('.sog-row')));
      return;
    }

    var as = e.target.closest('[data-sog-assign]');
    if (as) {
      var row = as.closest('.sog-row');
      as.disabled = true;
      post(assignUrl.replace('__ID__', row.dataset.so), { vendor_id: row.querySelector('[data-sog-select]').value })
        .then(function (j) {
          if (j && j.ok) { window.location.reload(); }
          else { as.disabled = false; if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not assign.'); }
        })
        .catch(function () { as.disabled = false; if (window.IntakeToast) IntakeToast.error('Network error.'); });
      return;
    }

    var ob = e.target.closest('[data-sog-order]');
    if (ob) {
      var head = ob.closest('.sog-head');
      var ids = rowsFor(head)
        .filter(function (r) { var c = r.querySelector('[data-sog-cb]'); return c && c.classList.contains('on'); })
        .map(function (r) { return r.dataset.so; });
      if (!ids.length) { if (window.IntakeToast) IntakeToast.error('Nothing selected.'); return; }

      var po  = head.querySelector('[data-sog-po]').value.trim();
      var eta = head.querySelector('[data-sog-eta]').value;
      if (!po)  { if (window.IntakeToast) IntakeToast.error('PO number is required.'); return; }
      if (!eta) { if (window.IntakeToast) IntakeToast.error('Expected date is required.'); return; }

      ob.disabled = true;
      post(batchUrl, { ids: ids, vendor_id: head.dataset.vendor, po_number: po, expected_arrival_date: eta })
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

  // Initial state for each group
  document.querySelectorAll('.sog-head').forEach(refresh);
})();
</script>
