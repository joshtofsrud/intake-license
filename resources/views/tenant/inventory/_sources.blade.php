{{-- MARKER-ITEM-SOURCES-EDIT — shared by create and edit.
     Expects $vendors, and $item when editing. --}}
@php
  // Rows the importer owns. Shown for context, never edited here.
  // TenantInventoryItem has only vendors() (BelongsToMany), so read the
  // pivot model directly — it's the row ids the form needs to post back.
  $srcRows = isset($item)
      ? \App\Models\Tenant\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
          ->with('vendor')->get()
      : collect();

  $synced      = $srcRows->whereNotNull('distributor_code')->values();
  $manual      = $srcRows->whereNull('distributor_code')->values();
  $preferredId = optional($srcRows->firstWhere('is_preferred', true))->id;
@endphp

<div class="ia-card" style="margin-bottom:18px">
  <div class="ia-card-head">
    <span class="ia-card-title">Where you buy this</span>
    <span style="margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)">
      Optional — add one row per vendor who carries it
    </span>
  </div>

  <div style="padding:16px">

    @if($synced->count())
      <div style="margin-bottom:14px">
        <div style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ia-text-dim);margin-bottom:8px">
          From your distributor feeds
        </div>
        @foreach($synced as $s)
          <div style="display:flex;gap:12px;align-items:center;padding:8px 10px;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:6px;font-size:13px">
            <label style="display:flex;align-items:center;gap:7px;cursor:pointer">
              <input type="radio" name="preferred_source" value="{{ $s->id }}" @checked($preferredId === $s->id)>
              <span>{{ $s->vendor?->name ?? $s->distributor_code }}</span>
            </label>
            <span style="color:var(--ia-text-dim)">{{ $s->vendor_sku ?: '—' }}</span>
            <span style="margin-left:auto;color:var(--ia-text-dim);font-variant-numeric:tabular-nums">
              {{ $s->live_cost_cents !== null ? '$' . number_format($s->live_cost_cents / 100, 2) : '—' }}
            </span>
            <span style="font-size:11px;color:var(--ia-text-dim)">synced</span>
          </div>
        @endforeach
        <div style="font-size:11.5px;color:var(--ia-text-dim);line-height:1.5">
          Cost and availability on these refresh from the distributor, so they
          aren't editable here. You can still mark one preferred.
        </div>
      </div>
    @endif

    <div id="src-rows">
      @foreach($manual as $i => $m)
        <div class="src-row" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:10px;flex-wrap:wrap">
          <input type="hidden" name="sources[{{ $i }}][id]" value="{{ $m->id }}">
          <div style="flex:1;min-width:170px">
            <label class="ia-form-label">Vendor</label>
            <select name="sources[{{ $i }}][vendor_id]" class="ia-input">
              <option value="">Select vendor…</option>
              @foreach($vendors as $v)
                <option value="{{ $v->id }}" @selected($m->vendor_id === $v->id)>{{ $v->name }}</option>
              @endforeach
            </select>
          </div>
          <div style="width:150px">
            <label class="ia-form-label">Their part no.</label>
            <input type="text" name="sources[{{ $i }}][vendor_sku]" class="ia-input" value="{{ $m->vendor_sku }}">
          </div>
          <div style="width:110px">
            <label class="ia-form-label">Your cost</label>
            <input type="number" step="0.01" min="0" name="sources[{{ $i }}][unit_cost]" class="ia-input"
                   value="{{ $m->unit_cost_cents !== null ? number_format($m->unit_cost_cents / 100, 2, '.', '') : '' }}">
          </div>
          <div style="width:100px">
            <label class="ia-form-label">Lead days</label>
            <input type="number" min="0" name="sources[{{ $i }}][lead_time_days]" class="ia-input" value="{{ $m->lead_time_days }}">
          </div>
          <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;padding-bottom:9px;cursor:pointer">
            <input type="radio" name="preferred_source" value="{{ $m->id }}" @checked($preferredId === $m->id)>
            Preferred
          </label>
          <button type="button" class="ia-btn src-remove" style="padding-bottom:9px">Remove</button>
        </div>
      @endforeach
    </div>

    <button type="button" class="ia-btn" id="src-add">+ Add vendor</button>
  </div>
</div>

<template id="src-template">
  <div class="src-row" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:170px">
      <label class="ia-form-label">Vendor</label>
      <select name="sources[__IDX__][vendor_id]" class="ia-input">
        <option value="">Select vendor…</option>
        @foreach($vendors as $v)
          <option value="{{ $v->id }}">{{ $v->name }}</option>
        @endforeach
      </select>
    </div>
    <div style="width:150px">
      <label class="ia-form-label">Their part no.</label>
      <input type="text" name="sources[__IDX__][vendor_sku]" class="ia-input">
    </div>
    <div style="width:110px">
      <label class="ia-form-label">Your cost</label>
      <input type="number" step="0.01" min="0" name="sources[__IDX__][unit_cost]" class="ia-input">
    </div>
    <div style="width:100px">
      <label class="ia-form-label">Lead days</label>
      <input type="number" min="0" name="sources[__IDX__][lead_time_days]" class="ia-input">
    </div>
    <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;padding-bottom:9px;cursor:pointer">
      <input type="radio" name="preferred_source" value="new___IDX__">
      Preferred
    </label>
    <button type="button" class="ia-btn src-remove" style="padding-bottom:9px">Remove</button>
  </div>
</template>

<script>
(function () {
  var rows = document.getElementById('src-rows');
  var tpl  = document.getElementById('src-template');
  var add  = document.getElementById('src-add');
  if (!rows || !tpl || !add) { return; }

  // Keep climbing past existing rows so a new one never reuses an index and
  // overwrites the row above it in the posted array.
  var next = rows.querySelectorAll('.src-row').length;

  add.addEventListener('click', function () {
    var html = tpl.innerHTML.replace(/__IDX__/g, String(next));
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    rows.appendChild(wrap.firstChild);
    next += 1;
  });

  rows.addEventListener('click', function (e) {
    var btn = e.target.closest('.src-remove');
    if (!btn) { return; }
    var row = btn.closest('.src-row');
    if (row) { row.remove(); }
  });
})();
</script>
