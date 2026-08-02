#!/usr/bin/env bash
# apply-item-vendor-sources.sh
# MARKER-ITEM-SOURCES-EDIT — you cannot attach a vendor to an item by hand.
#
# Vendors reach items exactly one way today: DistributorCatalogImportService
# when a catalog item is imported. Neither create nor edit has a vendor
# field, and the Sourcing table on the item page is read-only display that
# only renders when sources already exist.
#
# So a hand-entered item can never have a vendor — no cost, no vendor part
# number, no lead time — and is therefore invisible to special-order
# auto-assign, the placement board, and every vendor-driven report. The
# schema was always ready: tenant_inventory_item_vendors carries vendor_sku,
# unit_cost_cents, lead_time_days and is_preferred, and unit_cost_cents is
# specifically the manual counterpart to the synced live_cost_cents.
#
# Multi-vendor, matching the rest of the system. The sourcing table, the
# pivot, autoAssignVendor and the placement board all assume an item can
# come from several vendors; a single-vendor create form would have been the
# odd one out.
#
# SYNCED ROWS ARE NOT EDITABLE HERE. A row with distributor_code set is
# owned by the importer and its cost/availability are refreshed by tier-2 —
# letting this form rewrite them would mean the next sync silently reverts
# whatever was typed. Those rows are listed read-only for context; the
# editor adds, changes and removes MANUAL rows only.
#
# Preferred is single-choice across all sources, synced ones included, since
# autoAssignVendor's `preferred` rule reads exactly one flag.
set -e

cat <<'EOF' > resources/views/tenant/inventory/_sources.blade.php
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
EOF
echo "created resources/views/tenant/inventory/_sources.blade.php"

python3 <<'PY'
import io, re

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

# vendors for both forms
old = """        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();"""
assert s.count(old) == 2, 'C1 expected the category fetch twice, got %d' % s.count(old)
s = s.replace(old, """        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // MARKER-ITEM-SOURCES-EDIT — an item can come from several vendors,
        // same as everywhere else in the system.
        $vendors = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('name')->get();""")

# persist after create
old = """        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => \"Item '{$item->name}' created.\"]);"""
assert s.count(old) == 1, 'C2 store tail anchor'
s = s.replace(old, """        $this->syncItemSources($request, $item); // MARKER-ITEM-SOURCES-EDIT

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => \"Item '{$item->name}' created.\"]);""")

# persist after update
old = """        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Item updated.']);"""
assert s.count(old) == 1, 'C3 update tail anchor'
s = s.replace(old, """        $this->syncItemSources($request, $item); // MARKER-ITEM-SOURCES-EDIT

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Item updated.']);""")

# the writer
old = """    public function show(Request $request, string $id): View"""
assert s.count(old) == 1, 'C4 helper insert anchor'
s = s.replace(old, """    /**
     * MARKER-ITEM-SOURCES-EDIT — write the manual vendor rows from the form.
     *
     * Only touches rows with a NULL distributor_code. Anything the importer
     * owns is left alone: tier-2 refreshes its cost and availability, so a
     * value typed here would be silently reverted on the next sync.
     *
     * Rows the form no longer lists are deleted, so removing a vendor in the
     * UI actually removes it.
     */
    private function syncItemSources(Request $request, TenantInventoryItem $item): void
    {
        $tenantId = $item->tenant_id;

        $rows = collect((array) $request->input('sources', []))
            ->filter(fn ($r) => filled($r['vendor_id'] ?? null));

        $validVendorIds = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', $tenantId)
            ->pluck('id')->flip();

        $keptIds = [];

        foreach ($rows as $r) {
            if (! $validVendorIds->has($r['vendor_id'])) {
                continue; // not this tenant's vendor
            }

            $cost = $r['unit_cost'] ?? null;

            $payload = [
                'vendor_sku'      => filled($r['vendor_sku'] ?? null) ? $r['vendor_sku'] : null,
                'unit_cost_cents' => ($cost === null || $cost === '') ? null : (int) round((float) $cost * 100),
                'lead_time_days'  => filled($r['lead_time_days'] ?? null) ? (int) $r['lead_time_days'] : null,
            ];

            // firstOrCreate on the pair, because (inventory_item_id, vendor_id)
            // is unique — the same vendor twice in the form must not collide.
            $row = \\App\\Models\\Tenant\\TenantInventoryItemVendor::firstOrNew([
                'inventory_item_id' => $item->id,
                'vendor_id'         => $r['vendor_id'],
            ]);

            if ($row->exists && $row->distributor_code !== null) {
                $keptIds[] = $row->id; // synced row, leave it be
                continue;
            }

            $row->fill($payload)->save();
            $keptIds[] = $row->id;
        }

        // Drop manual rows the form dropped. Synced rows are never removed
        // here — only the importer owns those.
        \\App\\Models\\Tenant\\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
            ->whereNull('distributor_code')
            ->whereNotIn('id', $keptIds ?: ['-'])
            ->delete();

        // Preferred is single-choice across every source, synced included,
        // because autoAssignVendor's `preferred` rule reads exactly one flag.
        $preferred = (string) $request->input('preferred_source', '');
        if ($preferred !== '') {
            \\App\\Models\\Tenant\\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
                ->update(['is_preferred' => false]);
            \\App\\Models\\Tenant\\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
                ->where('id', $preferred)->update(['is_preferred' => true]);
        }
    }

    public function show(Request $request, string $id): View""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- views
# Explicit anchors per file. edit.blade.php has a NESTED archive form inside
# the main one, so a generic "first submit button" search would have put the
# sources card inside the DELETE form.
for f, anchor in [
    ('resources/views/tenant/inventory/create.blade.php',
     '  <div style="display:flex;gap:8px;justify-content:flex-end">'),
    ('resources/views/tenant/inventory/edit.blade.php',
     '  <div style="display:flex;gap:8px;justify-content:space-between;align-items:center">'),
]:
    s = io.open(f, encoding='utf-8').read()
    assert s.count(anchor) == 1, 'V anchor not unique in ' + f
    s = s.replace(anchor, "  @include('tenant.inventory._sources')\n\n" + anchor)
    io.open(f, 'w', encoding='utf-8').write(s)
    print('patched', f)
PY

echo "--- include placement ---"
grep -n "_sources" resources/views/tenant/inventory/create.blade.php resources/views/tenant/inventory/edit.blade.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
files = ['resources/views/tenant/inventory/_sources.blade.php',
         'resources/views/tenant/inventory/create.blade.php',
         'resources/views/tenant/inventory/edit.blade.php']
for f in files:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
    print(f, 'glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)))
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@section','@endsection')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        if o != c: print('    MISMATCH', a, o, b, c)
os.makedirs('/tmp/src', exist_ok=True)
raw = io.open(files[0], encoding='utf-8').read()
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)[0]
io.open('/tmp/src/s.js','w',encoding='utf-8').write(js)
r = subprocess.run(['node','--check','/tmp/src/s.js'], capture_output=True, text=True)
print('sources JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:400])
PY

echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/InventoryController.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('InventoryController braces', d, 'parens', par)
PY

echo
echo "apply-item-vendor-sources: OK"
