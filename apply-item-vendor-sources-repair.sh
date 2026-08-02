#!/usr/bin/env bash
# apply-item-vendor-sources-repair.sh
# MARKER-ITEM-SOURCES-EDIT — reapply the vendor sources editor, anchored on
# the tree-patched controller.
#
# WHY THIS EXISTS. apply-item-vendor-sources and apply-item-form-category-tree
# both rewrite the SAME two lines in InventoryController — the category fetch
# duplicated across create() and edit(). The tree patch replaced that fetch
# with self::categoryOptions(), so the sources patch could no longer find it
# and aborted on its first assertion. Because that assertion is the first
# operation, NOTHING from it applied: no $vendors, no syncItemSources(), no
# include in either form. Only _sources.blade.php landed, written by a `cat`
# before the python block, sitting unused.
#
# Verified on the server: categoryOptions present, syncItemSources absent.
#
# This version anchors on the post-tree line instead, and tolerates the item
# edit form in either state — before or after apply-archive-move-and-restore,
# which changes that form's action row.
#
# Everything else is identical to the original patch. Synced rows stay
# read-only (tier-2 owns their cost and availability, so a value typed here
# would be reverted on the next sync); rows dropped from the form are
# deleted; preferred is single-choice across all sources because
# autoAssignVendor reads exactly one flag.
set -e

cat <<'EOF' > resources/views/tenant/inventory/_sources.blade.php
{{-- MARKER-ITEM-SOURCES-EDIT — shared by create and edit.
     Expects $vendors, and $item when editing. --}}
@php
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
echo "wrote resources/views/tenant/inventory/_sources.blade.php"

python3 <<'PY'
import io, re

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

assert 'syncItemSources' not in s, 'already applied — syncItemSources present'

# Post-tree anchor. This is what the original patch could not find.
old = """        $categories = self::categoryOptions($tenant->id);"""
assert s.count(old) == 2, 'C1 expected categoryOptions in create() and edit(), got %d' % s.count(old)
s = s.replace(old, """        $categories = self::categoryOptions($tenant->id);

        // MARKER-ITEM-SOURCES-EDIT — an item can come from several vendors,
        // same as everywhere else in the system.
        $vendors = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('name')->get();""")

old = """        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => \"Item '{$item->name}' created.\"]);"""
assert s.count(old) == 1, 'C2 store tail anchor'
s = s.replace(old, """        $this->syncItemSources($request, $item); // MARKER-ITEM-SOURCES-EDIT

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => \"Item '{$item->name}' created.\"]);""")

old = """        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Item updated.']);"""
assert s.count(old) == 1, 'C3 update tail anchor'
s = s.replace(old, """        $this->syncItemSources($request, $item); // MARKER-ITEM-SOURCES-EDIT

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Item updated.']);""")

# Signature line is untouched by the archive-move patch, so this anchor holds
# whether or not that one has been applied.
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

            // firstOrNew on the pair, because (inventory_item_id, vendor_id)
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
# The edit form's action row differs depending on whether the archive-move
# patch has run (flex-end vs space-between), so match either.
for f, pattern in [
    ('resources/views/tenant/inventory/create.blade.php',
     r'\n([ \t]*)<div style="display:flex;gap:8px;justify-content:flex-end[^"]*">'),
    ('resources/views/tenant/inventory/edit.blade.php',
     r'\n([ \t]*)<div style="display:flex;gap:8px;justify-content:(?:flex-end|space-between)[^"]*">'),
]:
    s = io.open(f, encoding='utf-8').read()
    if "@include('tenant.inventory._sources')" in s:
        print('  already included', f); continue
    m = re.search(pattern, s)
    assert m, 'V anchor not found in ' + f
    indent = m.group(1)
    s = s[:m.start()] + "\n" + indent + "@include('tenant.inventory._sources')\n" + s[m.start():]
    io.open(f, 'w', encoding='utf-8').write(s)
    print('patched', f)
PY

echo
echo "--- include placement + form nesting ---"
grep -n "_sources" resources/views/tenant/inventory/create.blade.php resources/views/tenant/inventory/edit.blade.php
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/inventory/create.blade.php',
          'resources/views/tenant/inventory/edit.blade.php']:
    s = io.open(f, encoding='utf-8').read()
    d = 0; nested = False
    for m in re.finditer(r'<form\b|</form>', s):
        d += -1 if m.group(0) == '</form>' else 1
        if d > 1: nested = True
    inside = s.index("@include('tenant.inventory._sources')")
    before = s[:inside]
    open_forms = before.count('<form') - before.count('</form>')
    print('%-40s balanced=%s nested=%s include-inside-form=%s'
          % (f.split('/')[-1], d == 0, nested, open_forms == 1))
PY

echo
echo "--- blade + js ---"
python3 - <<'PY'
import io, re, subprocess, os
for f in ['resources/views/tenant/inventory/_sources.blade.php',
          'resources/views/tenant/inventory/create.blade.php',
          'resources/views/tenant/inventory/edit.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    out = [f.split('/')[-1], 'glued=%d' % len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s))]
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        if o != c: out.append('MISMATCH %s %d/%d' % (a, o, c))
    print('  '.join(out))
os.makedirs('/tmp/vs', exist_ok=True)
js = re.findall(r'<script[^>]*>(.*?)</script>', io.open('resources/views/tenant/inventory/_sources.blade.php', encoding='utf-8').read(), flags=re.S)[0]
io.open('/tmp/vs/s.js','w',encoding='utf-8').write(js)
r = subprocess.run(['node','--check','/tmp/vs/s.js'], capture_output=True, text=True)
print('sources JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:300])
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
echo "apply-item-vendor-sources-repair: OK"
