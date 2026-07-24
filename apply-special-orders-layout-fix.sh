#!/bin/bash
# special-orders-layout-fix — fixes the overlapping text and splits the
# screen the way it should have been.
#   THE BUG: the vendor header was position:sticky inside one shared scroll
#   box, painted with background var(--ia-surface-2) — a token that does not
#   exist in any Intake theme. With no background it rendered transparent and
#   rows scrolled underneath the header text. (Only --ia-surface exists.)
#   THE FIX, and the better structure:
#     · ONE scrollable box at the top: items still needing a vendor, each
#       with its picker and Assign
#     · A SEPARATE box per vendor below, each sized to its content with its
#       own total, freight bar, and Mark-ordered action
#     · No sticky positioning anywhere, so nothing can overlap
#     · Item name now sits on its own line above the metadata, so long
#       product names cannot collide with the vendor picker
#     · Row markup extracted to a shared partial used by both sections
# No routes, no migration, no controller change. Server: view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "sog-box" resources/views/tenant/special-orders/_vendor_groups.blade.php; then
  echo "layout fix already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-SCROLL" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-scroll not applied — wrong base, aborting."; exit 1
fi

cat > 'resources/views/tenant/special-orders/_vendor_groups.blade.php' <<'SOFIX_0_EOF'
{{-- MARKER-SO-SCROLL — open special orders, in two parts:
     1. one scrollable box of items still needing a vendor
     2. a separate box per vendor below, each with its own batch action

     No sticky headers anywhere: the earlier version stuck the vendor header
     to the top of a single shared scroll box, and with a background token
     that does not exist in these themes (--ia-surface-2) it painted
     transparent, so rows scrolled underneath the header text. Separate
     boxes remove the need for stickiness entirely. --}}

<style>
  .sog-box{border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);background:var(--ia-surface);margin-bottom:14px;overflow:hidden}
  .sog-box.needs{border-color:rgba(240,149,149,.35)}

  .sog-head{background:rgba(0,0,0,.22);border-bottom:0.5px solid var(--ia-border);padding:12px 15px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .sog-name{font-weight:800;font-size:13.5px}
  .sog-name .warn{color:#F09595}
  .sog-count{font-size:11.5px;color:var(--ia-text-muted)}
  .sog-act{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .sog-tot{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums}
  .sog-in{background:rgba(0,0,0,.25);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:11.5px;padding:6px 8px}

  .sog-freight{display:flex;align-items:center;gap:9px;padding:8px 15px;background:rgba(0,0,0,.12);border-bottom:0.5px solid rgba(255,255,255,.05);font-size:11px}
  .sog-bar{flex:1;min-width:80px;height:4px;border-radius:100px;background:rgba(255,255,255,.09);overflow:hidden}
  .sog-bar span{display:block;height:100%;background:var(--ia-accent)}
  .sog-bar.met span{background:#7FD98F}
  .sog-fnote{color:var(--ia-text-muted)}
  .sog-fnote b{color:var(--ia-accent)}
  .sog-fnote.met b{color:#7FD98F}

  {{-- only the needs-a-vendor list scrolls; vendor boxes size to content --}}
  .sog-body.scrolls{max-height:52vh;overflow-y:auto}
  .sog-body.scrolls::-webkit-scrollbar{width:9px}
  .sog-body.scrolls::-webkit-scrollbar-thumb{background:rgba(255,255,255,.14);border-radius:100px}

  .sog-row{display:flex;align-items:center;gap:12px;padding:12px 15px;border-bottom:0.5px solid rgba(255,255,255,.05);flex-wrap:wrap}
  .sog-row:last-child{border-bottom:none}
  .sog-row:hover{background:rgba(255,255,255,.02)}
  .sog-cb{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.25);flex:none;display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:#0B0B0B;font-weight:900;cursor:pointer}
  .sog-cb.on{background:var(--ia-accent);border-color:var(--ia-accent)}
  .sog-cb.on:after{content:"\2713"}
  .sog-ident{flex:1;min-width:200px}
  .sog-nm{font-weight:600;font-size:13.5px;line-height:1.35}
  .sog-mt{font-size:11.5px;color:var(--ia-text-muted);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .sog-pick{display:flex;align-items:center;gap:7px;flex:none}
  .sog-sel{background:rgba(0,0,0,.25);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:7px 9px;min-width:210px}
  .sog-sel:focus{outline:none;border-color:var(--ia-accent)}
  .sog-assign{font-family:inherit;font-size:11.5px;font-weight:700;border-radius:8px;padding:7px 12px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text-muted);white-space:nowrap}
  .sog-assign:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  .sog-noopt{font-size:11.5px;color:#F09595;flex:none;max-width:260px;text-align:right}

  .sog-foot{display:flex;align-items:center;gap:10px;padding:4px 3px 0;font-size:11.5px;color:var(--ia-text-muted);flex-wrap:wrap}
  .sog-empty{padding:34px;text-align:center;color:var(--ia-text-muted);font-size:13px}

  @media(max-width:720px){
    .sog-body.scrolls{max-height:none}
    .sog-act{width:100%;margin-left:0}
    .sog-pick{width:100%}
    .sog-sel{flex:1;min-width:0}
    .sog-noopt{text-align:left;max-width:none}
  }
</style>

@php
  $sogAllTotal = 0; $sogNoVendor = 0; $sogVendorsUsed = [];
  foreach ($vgroups as $vid => $rows) {
    foreach ($rows as $r) {
      $opt  = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vid);
      $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
      if ($vid === '') { $sogNoVendor++; }
      else { $sogAllTotal += (int) $unit * (int) $r->quantity; $sogVendorsUsed[$vid] = true; }
    }
  }
  $sogUnassigned = $vgroups[''] ?? [];
@endphp

{{-- ---------- 1. items still needing a vendor: one scrollable box ---------- --}}
@if(count($sogUnassigned))
  <div class="sog-box needs">
    <div class="sog-head">
      <span class="sog-name"><span class="warn">Needs a vendor</span></span>
      <span class="sog-count">{{ count($sogUnassigned) }} {{ \Illuminate\Support\Str::plural('item', count($sogUnassigned)) }} — pick a vendor to move them into a group below</span>
    </div>
    <div class="sog-body scrolls">
      @foreach($sogUnassigned as $so)
        @include('tenant.special-orders._vendor_group_row', ['so' => $so, 'vendorId' => '', 'selectable' => false])
      @endforeach
    </div>
  </div>
@endif

{{-- ---------- 2. one box per vendor ---------- --}}
@forelse($vgroups as $vendorId => $rows)
  @continue($vendorId === '')
  @php
    $vendor = $vvendors[$vendorId] ?? null;
    $groupTotal = 0;
    foreach ($rows as $r) {
      $opt  = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vendorId);
      $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
      $groupTotal += (int) $unit * (int) $r->quantity;
    }
    $min = $vendor->free_freight_cents ?? null;
  @endphp

  <div class="sog-box" data-vendor="{{ $vendorId }}">
    <div class="sog-head">
      <span class="sog-name">{{ $vendor->name ?? 'Vendor' }}</span>
      <span class="sog-count" data-sog-count>{{ count($rows) }} {{ \Illuminate\Support\Str::plural('item', count($rows)) }}</span>
      <span class="sog-act">
        <span class="sog-tot">${{ number_format($groupTotal / 100, 2) }}</span>
        <input type="text" class="sog-in" placeholder="PO #" data-sog-po style="width:92px">
        <input type="date" class="sog-in" data-sog-eta value="{{ now()->addDays(7)->toDateString() }}">
        <button type="button" class="ia-btn ia-btn--primary" style="padding:7px 13px;font-size:11.5px" data-sog-order>
          Mark ordered
        </button>
      </span>
    </div>

    @if($min)
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

    <div class="sog-body">
      @foreach($rows as $so)
        @include('tenant.special-orders._vendor_group_row', ['so' => $so, 'vendorId' => $vendorId, 'selectable' => true])
      @endforeach
    </div>
  </div>
@empty
@endforelse

@if(!count($vgroups))
  <div class="sog-box"><div class="sog-empty">Nothing waiting to be placed.</div></div>
@endif

<div class="sog-foot">
  <span>
    {{ $total }} open · ${{ number_format($sogAllTotal / 100, 2) }} across
    {{ count($sogVendorsUsed) }} {{ \Illuminate\Support\Str::plural('vendor', count($sogVendorsUsed)) }}
    @if($sogNoVendor) · <span style="color:#F09595">{{ $sogNoVendor }} still need a vendor</span> @endif
  </span>
  @if($total > $scrollCap)
    <span style="color:#F5C56B">showing the first {{ $scrollCap }}</span>
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

  function refresh(box) {
    if (!box) return;
    var rows = box.querySelectorAll('.sog-row');
    var sel  = box.querySelectorAll('[data-sog-cb].on');
    var c = box.querySelector('[data-sog-count]');
    if (c) c.textContent = rows.length + ' items · ' + sel.length + ' selected';
    var btn = box.querySelector('[data-sog-order]');
    if (btn) btn.disabled = sel.length === 0;
  }

  document.addEventListener('click', function (e) {
    var cb = e.target.closest('[data-sog-cb]');
    if (cb) { cb.classList.toggle('on'); refresh(cb.closest('.sog-box')); return; }

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
      var box = ob.closest('.sog-box');
      var ids = Array.prototype.map.call(box.querySelectorAll('[data-sog-cb].on'), function (c) {
        return c.closest('.sog-row').dataset.so;
      });
      if (!ids.length) { if (window.IntakeToast) IntakeToast.error('Nothing selected.'); return; }

      var po  = box.querySelector('[data-sog-po]').value.trim();
      var eta = box.querySelector('[data-sog-eta]').value;
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

  document.querySelectorAll('.sog-box[data-vendor]').forEach(refresh);
})();
</script>
SOFIX_0_EOF

cat > 'resources/views/tenant/special-orders/_vendor_group_row.blade.php' <<'SOFIX_1_EOF'
{{-- MARKER-SO-SCROLL — one special-order row, shared by the needs-a-vendor
     box and each vendor box. Item name gets its own line so long product
     names cannot collide with the vendor picker. --}}
@php
  $opts = $voptions[$so->inventory_item_id] ?? [];
  $og   = $origins[$so->id] ?? null;
@endphp
<div class="sog-row" data-so="{{ $so->id }}">
  @if($selectable)
    <span class="sog-cb on" data-sog-cb></span>
  @endif

  <div class="sog-ident">
    <div class="sog-nm">{{ $so->item_name_snapshot }}</div>
    <div class="sog-mt">
      <span>{{ $so->so_number }} · qty {{ $so->quantity }} ·
        {{ $so->customer ? trim($so->customer->first_name . ' ' . $so->customer->last_name) : 'stock' }}</span>
      @if($og)
        <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
      @endif
      @if($so->created_at)
        <span style="opacity:.6">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>
      @endif
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
    <span class="sog-pick">
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
    </span>
  @endif
</div>
SOFIX_1_EOF

echo "special-orders-layout-fix applied — server: git pull && php artisan view:clear"
