#!/usr/bin/env bash
# apply-archive-move-and-restore.sh
# MARKER-ARCHIVE-MOVE — take Archive off the edit form, put it on the item
# page, and make archiving reversible.
#
# WHY IT MOVES. The Archive form was nested inside the item-update form,
# which is what made "Save changes" archive the item — browsers keep the
# inner form's contents while dropping its tag, so its @method('DELETE')
# joined the outer form and Laravel spoofed the PATCH into a DELETE. Both
# routes share /{id}. That is fixed, but a destructive control sitting
# beside Save is the arrangement that produced it, so it does not belong on
# a form at all.
#
# WHY RESTORE. destroy() does is_active=false AND a soft delete, and the
# index filters on is_active plus SoftDeletes' own scope — so an archived
# item vanishes from search with no way back through the UI. Archiving
# something you cannot recover is a one-way door on a data-entry mistake.
#
# Restore reverses exactly what destroy() did and nothing else: restore()
# then is_active=true. Stock, sources, history and every relation are
# untouched by a soft delete, so the item comes back as it was.
#
# The archived list is reachable from the index toolbar rather than hidden,
# because nobody thinks to look for a URL parameter when an item goes
# missing — which is precisely the moment they need it.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

# archived view on the index
old = """        $q = TenantInventoryItem::with(['category.parent']) // MARKER-CAT-TREE — path without N+1
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);"""
assert s.count(old) == 1, 'C1 index query anchor'
s = s.replace(old, """        // MARKER-ARCHIVE-MOVE — an archived item is soft-deleted AND
        // is_active=false, so it is invisible twice over. This is the only
        // way back to it.
        $archived = $request->boolean('archived');

        $q = TenantInventoryItem::with(['category.parent']) // MARKER-CAT-TREE — path without N+1
            ->where('tenant_id', $tenant->id);

        if ($archived) {
            $q->onlyTrashed();
        } else {
            $q->where('is_active', true);
        }""")

# pass the flag to the view
old = """            'categoryTree', 'includeSubs', 'locStocks', 'allLocations', // MARKER-CAT-TREE"""
assert s.count(old) == 1, 'C2 compact anchor'
s = s.replace(old, """            'categoryTree', 'includeSubs', 'locStocks', 'allLocations', // MARKER-CAT-TREE
            'archived', // MARKER-ARCHIVE-MOVE""")

# show() must find a trashed item, or the Restore button can never render
# and the archived list links to a 404.
old = """        $item = TenantInventoryItem::with(['category', 'distributorCatalog', 'locations.location', 'specialOrders.vendor', 'specialOrders.customer', 'specialOrders.appointment', 'vendors'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);"""
assert s.count(old) == 1, 'C4 show query anchor'
s = s.replace(old, """        // MARKER-ARCHIVE-MOVE — withTrashed, otherwise the archived list links
        // to a 404 and Restore is unreachable.
        $item = TenantInventoryItem::withTrashed()
            ->with(['category', 'distributorCatalog', 'locations.location', 'specialOrders.vendor', 'specialOrders.customer', 'specialOrders.appointment', 'vendors'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);""")

# restore action
old = """    public function destroy(string $id): RedirectResponse"""
assert s.count(old) == 1, 'C3 destroy anchor'
s = s.replace(old, """    /**
     * MARKER-ARCHIVE-MOVE — undo an archive.
     *
     * destroy() does two things, so this undoes both: the soft delete and
     * the is_active flag. Nothing else is touched by either, so the item
     * returns exactly as it was — stock, vendor sources and history
     * included.
     */
    public function restore(string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::withTrashed()
            ->where('tenant_id', $tenant->id)->findOrFail($id);

        $item->restore();
        $item->update(['is_active' => true]);

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => \"'{$item->name}' restored.\"]);
    }

    public function destroy(string $id): RedirectResponse""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- routes
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()
old = """                Route::delete('/{id}',           [TenantControllers\\InventoryController::class, 'destroy'])->name('destroy');"""
assert s.count(old) == 1, 'R1 destroy route anchor'
s = s.replace(old, """                Route::delete('/{id}',           [TenantControllers\\InventoryController::class, 'destroy'])->name('destroy');
                Route::post('/{id}/restore',     [TenantControllers\\InventoryController::class, 'restore'])->name('restore'); // MARKER-ARCHIVE-MOVE""")
io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- edit: remove archive entirely
p = 'resources/views/tenant/inventory/edit.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """</form>

  <div style="display:flex;gap:8px;justify-content:space-between;align-items:center">
    <form method="POST" action="{{ route('tenant.inventory.destroy', $item->id) }}"
      onsubmit="return confirm('Archive this item? It will be hidden but not permanently deleted. You can restore it from the master admin.')">
      @csrf
      @method('DELETE')
      <button type="submit" class="ia-btn ia-btn--ghost" style="color:var(--ia-error)">Archive item</button>
    </form>
    <div style="display:flex;gap:8px">
      <a href="{{ route('tenant.inventory.show', $item->id) }}" class="ia-btn ia-btn--ghost">Cancel</a>
      <button type="submit" form="item-edit-form" class="ia-btn ia-btn--primary">Save changes</button>
    </div>
  </div>"""
assert s.count(old) == 1, 'E1 edit actions anchor (run the glue/nesting patch first)'
s = s.replace(old, """  {{-- MARKER-ARCHIVE-MOVE — Archive lives on the item page now. A
       destructive control next to Save is what turned a save into a delete. --}}
  <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
    <a href="{{ route('tenant.inventory.show', $item->id) }}" class="ia-btn ia-btn--ghost">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Save changes</button>
  </div>
</form>""")

# no sibling form left, so the id and the form= reference are redundant
s = s.replace('<form id="item-edit-form" method="POST"', '<form method="POST"')
io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- show: archive / restore
p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.edit', $item->id) }}" class="ia-btn ia-btn--secondary">Edit</a>
    <button type="button" class="ia-btn ia-btn--primary" onclick="iaShowAdjust()">Adjust stock</button>
  </div>"""
assert s.count(old) == 1, 'S1 show actions anchor'
s = s.replace(old, """  <div class="ia-page-actions">
    {{-- MARKER-ARCHIVE-MOVE — archiving belongs here, away from Save. --}}
    @if($item->trashed())
      <form method="POST" action="{{ route('tenant.inventory.restore', $item->id) }}">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary">Restore item</button>
      </form>
    @else
      <form method="POST" action="{{ route('tenant.inventory.destroy', $item->id) }}"
            onsubmit="return confirm('Archive this item? It disappears from search and the register, but nothing is lost — you can restore it from Inventory → Archived.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="ia-btn ia-btn--ghost" style="color:var(--ia-error)">Archive</button>
      </form>
      <a href="{{ route('tenant.inventory.edit', $item->id) }}" class="ia-btn ia-btn--secondary">Edit</a>
      <button type="button" class="ia-btn ia-btn--primary" onclick="iaShowAdjust()">Adjust stock</button>
    @endif
  </div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- index: archived toggle
p = 'resources/views/tenant/inventory/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar">"""
assert s.count(old) == 1, 'I1 toolbar anchor'
s = s.replace(old, """{{-- MARKER-ARCHIVE-MOVE — reachable, because nobody guesses a URL parameter
     when an item goes missing. --}}
@if($archived)
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;padding:10px 14px;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md)">
    <span style="font-size:13px">Showing archived items. Open one to restore it.</span>
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--sm" style="margin-left:auto">Back to inventory</a>
  </div>
@endif

<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar">""")

old = """    <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn">Categories</a>"""
assert s.count(old) == 1, 'I2 categories button anchor'
s = s.replace(old, """    <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn">Categories</a>
    {{-- MARKER-ARCHIVE-MOVE --}}
    <a href="{{ route('tenant.inventory.index', ['archived' => 1]) }}" class="ia-btn">Archived</a>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- no form nesting anywhere in these views ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/inventory/edit.blade.php',
          'resources/views/tenant/inventory/show.blade.php',
          'resources/views/tenant/inventory/index.blade.php']:
    s = io.open(f, encoding='utf-8').read()
    depth = 0; bad = False
    for m in re.finditer(r'<form\b|</form>', s):
        if m.group(0) == '</form>':
            depth -= 1
        else:
            depth += 1
            if depth > 1: bad = True
    print('%-46s forms balanced=%s nested=%s' % (f.split('/')[-1], depth == 0, bad))
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/inventory/edit.blade.php',
          'resources/views/tenant/inventory/show.blade.php',
          'resources/views/tenant/inventory/index.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    g = len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s))
    out = [f.split('/')[-1], 'glued=%d' % g]
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        if o != c: out.append('MISMATCH %s %d/%d' % (a, o, c))
    print('  '.join(out))
PY

echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Http/Controllers/Tenant/InventoryController.php', 'routes/web.php']:
    s = io.open(p, encoding='utf-8').read()
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
    print(p, 'braces', d, 'parens', par)
PY

echo
echo "apply-archive-move-and-restore: OK"
