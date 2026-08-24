#!/usr/bin/env python3
"""Service categories: rename, hide, delete — safely.

Categories were create-only from the UI. `update_category` (name,
is_active) and `delete_category` were both implemented and routed, but
nothing in the front end ever called them, so a typo or a discontinued
category stayed on the booking page forever.

Delete could NOT simply be wired up: tenant_service_items.category_id is
cascadeOnDelete, so calling the existing endpoint on a category with
services in it silently destroys every one of them plus their add-on
links. So delete now counts first and requires a destination:

  * Category has services → list them, pick where they move, then delete.
    There is no "delete anyway" — if the services really should go, they
    get deleted individually first. One destructive act at a time.
  * Category is empty → confirm, then delete.
  * The server re-checks independently, so a stale tab can't post past it.

Also fixes the create row, which committed only on Enter or blur and had
no error path — type a name, click elsewhere, and it could vanish with no
explanation. That is what read as "adding a category isn't working".
Run from repo root: python3 apply-service-category-manage.py
"""
import sys

def read(p):
    with open(p) as f: return f.read()
def write(p, s):
    with open(p, 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new == '':
        if old not in s:
            print(f"SKIP (already applied): {label}"); return
    elif new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

CTRL = 'app/Http/Controllers/Tenant/ServiceController.php'
JS   = 'public/js/tenant/services.js'
VIEW = 'resources/views/tenant/services/index.blade.php'

# ============================================================
# 1) Controller — delete refuses to cascade
# ============================================================
sub(CTRL,
    """        if ($op === 'delete_category') {
            TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $id)->delete();
            return response()->json(['ok' => true]);
        }""",
    """        if ($op === 'delete_category') {
            // MARKER-SVC-CAT — tenant_service_items.category_id is
            // cascadeOnDelete. Deleting a category with services in it would
            // destroy them and their add-on links, silently. Never cascade:
            // the services move somewhere first, or nothing happens.
            $cat = TenantServiceCategory::where('tenant_id', $tenant->id)
                ->where('id', $id)->first();
            if (! $cat) return response()->json(['ok' => true]);

            $count = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('category_id', $cat->id)->count();

            if ($count > 0) {
                $moveTo = $request->input('move_to');
                if (! $moveTo) {
                    return $this->err(
                        'That category still has ' . $count . ' ' .
                        \\Illuminate\\Support\\Str::plural('service', $count) .
                        ' in it. Choose where they should move first.'
                    );
                }

                $dest = TenantServiceCategory::where('tenant_id', $tenant->id)
                    ->where('id', $moveTo)->where('id', '!=', $cat->id)->first();
                if (! $dest) return $this->err('Pick a different category to move them to.');

                \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($tenant, $cat, $dest) {
                    TenantServiceItem::where('tenant_id', $tenant->id)
                        ->where('category_id', $cat->id)
                        ->update(['category_id' => $dest->id]);
                    $cat->delete();
                });

                return response()->json([
                    'ok' => true, 'moved' => $count, 'moved_to' => $dest->id,
                ]);
            }

            $cat->delete();
            return response()->json(['ok' => true, 'moved' => 0]);
        }""",
    "controller: delete guard")

# ============================================================
# 2) JS — category menu on each group header
# ============================================================
sub(JS,
    '  function svGroupHead(name, count, catId, warn) {\n    return \'<div class="sv-cat-grouphead\' + (warn ? \' is-warn\' : \'\') + \'">\'\n      + \'<span class="sv-cat-groupname">\' + esc(name) + \'</span>\'\n      + \'<span class="sv-cat-groupcount">\' + count + \'</span>\'\n      + (warn ? \'<span class="sv-cat-groupwarn">Won\\\'t group on booking page</span>\' : \'\')\n      + \'<span class="sv-cat-groupspacer"></span>\'\n      + (catId ? \'<button type="button" class="sv-cat-groupadd" data-add-to-cat="\' + esc(catId) + \'">+ Add service</button>\' : \'\')\n      + \'</div>\';\n  }\n',
    '  function svGroupHead(name, count, catId, warn, isActive) {\n    // MARKER-SVC-CAT — rename / hide / delete were implemented server-side\n    // but had no control anywhere in the UI.\n    var hidden = (isActive === false);\n    return \'<div class="sv-cat-grouphead\' + (warn ? \' is-warn\' : \'\') + (hidden ? \' is-hidden-cat\' : \'\') + \'"\'\n      + (catId ? \' data-cat-head="\' + esc(catId) + \'"\' : \'\') + \'>\'\n      + \'<span class="sv-cat-groupname">\' + esc(name) + \'</span>\'\n      + \'<span class="sv-cat-groupcount">\' + count + \'</span>\'\n      + (hidden ? \'<span class="sv-cat-hidden-pill">Hidden</span>\' : \'\')\n      + (warn ? \'<span class="sv-cat-groupwarn">Won\\\'t group on booking page</span>\' : \'\')\n      + \'<span class="sv-cat-groupspacer"></span>\'\n      + (catId ? \'<button type="button" class="sv-cat-groupadd" data-add-to-cat="\' + esc(catId) + \'">+ Add service</button>\' : \'\')\n      + (catId ? \'<button type="button" class="sv-cat-menubtn" data-cat-menu="\' + esc(catId) + \'" title="Category options" aria-label="Category options">⋯</button>\' : \'\')\n      + \'</div>\';\n  }\n',
    "js: group head menu")

sub(JS,
    """      html += svGroupHead(cat.name, rows.length, cat.id, false) + rows.map(rowHtml).join('');""",
    """      html += svGroupHead(cat.name, rows.length, cat.id, false, cat.is_active !== false) + rows.map(rowHtml).join('');""",
    "js: pass is_active")

# ============================================================
# 3) JS — menu behaviour, rename, hide, delete
# ============================================================
sub(JS,
    '  function renderInlineCategoryCreator(onCreated) {',
    '  // ─── MARKER-SVC-CAT — category management ────────────────────────────\n  function closeCatMenus() {\n    document.querySelectorAll(\'.sv-cat-menu\').forEach(function (m) { m.remove(); });\n  }\n\n  function catById(id) {\n    for (var i = 0; i < state.categories.length; i++) {\n      if (String(state.categories[i].id) === String(id)) return state.categories[i];\n    }\n    return null;\n  }\n\n  function openCatMenu(catId, anchorBtn) {\n    closeCatMenus();\n    var cat = catById(catId);\n    if (!cat) return;\n    var hidden = cat.is_active === false;\n\n    var menu = document.createElement(\'div\');\n    menu.className = \'sv-cat-menu\';\n    menu.innerHTML = \'\'\n      + \'<button type="button" data-cat-act="rename">Rename</button>\'\n      + \'<button type="button" data-cat-act="toggle">\' + (hidden ? \'Show on booking page\' : \'Hide from booking page\') + \'</button>\'\n      + \'<div class="sv-cat-menu-sep"></div>\'\n      + \'<button type="button" class="danger" data-cat-act="delete">Delete category…</button>\';\n\n    anchorBtn.parentNode.appendChild(menu);\n\n    menu.addEventListener(\'click\', function (ev) {\n      var b = ev.target.closest(\'[data-cat-act]\');\n      if (!b) return;\n      var act = b.getAttribute(\'data-cat-act\');\n      closeCatMenus();\n      if (act === \'rename\') renameCategory(catId);\n      if (act === \'toggle\') toggleCategoryVisible(catId);\n      if (act === \'delete\') confirmDeleteCategory(catId);\n    });\n  }\n\n  function renameCategory(catId) {\n    var cat = catById(catId);\n    var head = document.querySelector(\'[data-cat-head="\' + catId + \'"]\');\n    if (!cat || !head) return;\n\n    var original = head.innerHTML;\n    head.innerHTML = \'<input type="text" class="sv-cat-rename" value="">\'\n      + \'<button type="button" class="sv-cat-mini primary" data-rename-save>Save</button>\'\n      + \'<button type="button" class="sv-cat-mini" data-rename-cancel>Cancel</button>\';\n    var input = head.querySelector(\'.sv-cat-rename\');\n    input.value = cat.name;\n    input.focus();\n    input.select();\n\n    function restore() { head.innerHTML = original; renderAll(); }\n\n    function save() {\n      var name = input.value.trim();\n      if (!name) { input.focus(); return; }\n      if (name === cat.name) { restore(); return; }\n      input.disabled = true;\n      ajax(D.urls.servicesBase + \'/\' + catId, \'PATCH\', { op: \'update_category\', field: \'name\', value: name })\n        .then(function (r) {\n          if (!serviceResponseOk(r)) {\n            input.disabled = false;\n            showCatError(head, serviceErrorMessage(r));\n            return;\n          }\n          cat.name = r.json.data.name;\n          cat.slug = r.json.data.slug;\n          renderAll();\n        })\n        .catch(function () { input.disabled = false; showCatError(head, \'Could not save — check your connection.\'); });\n    }\n\n    head.querySelector(\'[data-rename-save]\').addEventListener(\'click\', save);\n    head.querySelector(\'[data-rename-cancel]\').addEventListener(\'click\', restore);\n    input.addEventListener(\'keydown\', function (ev) {\n      if (ev.key === \'Enter\') { ev.preventDefault(); save(); }\n      if (ev.key === \'Escape\') { ev.preventDefault(); restore(); }\n    });\n  }\n\n  function showCatError(head, msg) {\n    var e = head.querySelector(\'.sv-cat-err\');\n    if (!e) {\n      e = document.createElement(\'span\');\n      e.className = \'sv-cat-err\';\n      head.appendChild(e);\n    }\n    e.textContent = msg;\n  }\n\n  function toggleCategoryVisible(catId) {\n    var cat = catById(catId);\n    if (!cat) return;\n    var next = cat.is_active === false;\n    ajax(D.urls.servicesBase + \'/\' + catId, \'PATCH\',\n         { op: \'update_category\', field: \'is_active\', value: next ? 1 : 0 })\n      .then(function (r) {\n        if (!serviceResponseOk(r)) { alert(serviceErrorMessage(r)); return; }\n        cat.is_active = next;\n        renderAll();\n      })\n      .catch(function () { alert(\'Could not save — check your connection.\'); });\n  }\n\n  function confirmDeleteCategory(catId) {\n    var cat = catById(catId);\n    var head = document.querySelector(\'[data-cat-head="\' + catId + \'"]\');\n    if (!cat || !head) return;\n\n    var mine = flatServices().filter(function (s) { return String(s.category_id) === String(catId); });\n    var others = state.categories.filter(function (c) { return String(c.id) !== String(catId); });\n\n    var panel = document.createElement(\'div\');\n    panel.className = \'sv-cat-confirm\' + (mine.length ? \' is-danger\' : \'\');\n\n    if (mine.length) {\n      // Deleting would cascade to every service in here — make the trade\n      // explicit and require a destination. No "delete anyway".\n      var opts = others.map(function (c) {\n        return \'<option value="\' + esc(c.id) + \'">\' + esc(c.name) + \'</option>\';\n      }).join(\'\');\n\n      panel.innerHTML = \'\'\n        + \'<div class="t">“\' + esc(cat.name) + \'” has \' + mine.length + \' service\' + (mine.length === 1 ? \'\' : \'s\') + \' in it</div>\'\n        + \'<div class="s">Deleting the category deletes these too, along with their add-ons and pricing:</div>\'\n        + \'<ul>\' + mine.slice(0, 6).map(function (s) { return \'<li>\' + esc(s.name) + \'</li>\'; }).join(\'\')\n        + (mine.length > 6 ? \'<li>and \' + (mine.length - 6) + \' more…</li>\' : \'\') + \'</ul>\'\n        + (others.length\n            ? \'<div class="r"><span>Move them to</span>\'\n              + \'<select data-cat-move>\' + opts + \'</select>\'\n              + \'<button type="button" class="sv-cat-mini primary" data-cat-do-delete>Move &amp; delete</button></div>\'\n            : \'<div class="s" style="margin-top:8px">There is nowhere to move them — create another category first.</div>\')\n        + \'<div class="r"><button type="button" class="sv-cat-mini" data-cat-hide-instead>Hide it instead</button>\'\n        + \'<button type="button" class="sv-cat-mini" data-cat-cancel>Cancel</button></div>\';\n    } else {\n      panel.innerHTML = \'\'\n        + \'<div class="t">Delete “\' + esc(cat.name) + \'”?</div>\'\n        + \'<div class="s">Nothing is filed under it, so nothing else is affected.</div>\'\n        + \'<div class="r"><button type="button" class="sv-cat-mini danger" data-cat-do-delete>Delete category</button>\'\n        + \'<button type="button" class="sv-cat-mini" data-cat-cancel>Cancel</button></div>\';\n    }\n\n    head.parentNode.insertBefore(panel, head.nextSibling);\n\n    panel.addEventListener(\'click\', function (ev) {\n      if (ev.target.closest(\'[data-cat-cancel]\')) { panel.remove(); return; }\n      if (ev.target.closest(\'[data-cat-hide-instead]\')) { panel.remove(); toggleCategoryVisible(catId); return; }\n      if (!ev.target.closest(\'[data-cat-do-delete]\')) return;\n\n      var sel = panel.querySelector(\'[data-cat-move]\');\n      var payload = { op: \'delete_category\' };\n      if (sel) payload.move_to = sel.value;\n\n      var btn = ev.target.closest(\'[data-cat-do-delete]\');\n      btn.disabled = true;\n\n      ajax(D.urls.servicesBase + \'/\' + catId, \'DELETE\', payload)\n        .then(function (r) {\n          if (!serviceResponseOk(r)) {\n            btn.disabled = false;\n            var e = panel.querySelector(\'.sv-cat-err\') || document.createElement(\'div\');\n            e.className = \'sv-cat-err\';\n            e.textContent = serviceErrorMessage(r);\n            panel.appendChild(e);\n            return;\n          }\n          // Move the services locally so the list is right without a reload.\n          if (payload.move_to) {\n            flatServices().forEach(function (s) {\n              if (String(s.category_id) === String(catId)) s.category_id = payload.move_to;\n            });\n          }\n          state.categories = state.categories.filter(function (c) {\n            return String(c.id) !== String(catId);\n          });\n          panel.remove();\n          renderAll();\n        })\n        .catch(function () {\n          btn.disabled = false;\n          alert(\'Could not delete — check your connection.\');\n        });\n    });\n  }\n\n  document.addEventListener(\'click\', function (e) {\n    var btn = e.target.closest(\'[data-cat-menu]\');\n    if (btn) { e.preventDefault(); openCatMenu(btn.getAttribute(\'data-cat-menu\'), btn); return; }\n    if (!e.target.closest(\'.sv-cat-menu\')) closeCatMenus();\n  });\n\n  function renderInlineCategoryCreator(onCreated) {',
    "js: category management")

# ============================================================
# 4) JS — create row gets a Save button and an error path
# ============================================================
# Minimal anchors: this line carries literal typographic characters, so
# matching a short unambiguous fragment beats reproducing the whole string.
sub(JS,
    """<div><button type="button" class="sv-expand-btn" id="sv-inline-cat-cancel" title="Cancel">""",
    """<div style="text-align:center"><button type="button" class="sv-cat-mini primary" id="sv-inline-cat-save">Save</button></div>'
      + '<div><button type="button" class="sv-expand-btn" id="sv-inline-cat-cancel" title="Cancel">""",
    "js: create row save button")

sub(JS,
    """      ajax(D.urls.servicesBase, 'POST', { op: 'save_category', name: name }).then(function (r) {
        row.remove();
        if (!serviceResponseOk(r)) { alert('Category save failed: ' + serviceErrorMessage(r)); return; }""",
    """      ajax(D.urls.servicesBase, 'POST', { op: 'save_category', name: name }).then(function (r) {
        // MARKER-SVC-CAT — keep the row on failure so the typing isn't lost,
        // and say what went wrong instead of leaving a frozen input.
        if (!serviceResponseOk(r)) {
          committed = false;
          nameInput.disabled = false;
          var e = row.querySelector('.sv-cat-err');
          if (!e) { e = document.createElement('div'); e.className = 'sv-cat-err'; row.appendChild(e); }
          e.textContent = serviceErrorMessage(r);
          nameInput.focus();
          return;
        }
        row.remove();""",
    "js: create row error path")

sub(JS,
    """    cancelBtn.addEventListener('click', cancel);
  }

  function createLibraryAddon() {""",
    """    cancelBtn.addEventListener('click', cancel);
    var saveBtn = row.querySelector('#sv-inline-cat-save');
    if (saveBtn) saveBtn.addEventListener('click', function () { commit(); });
  }

  function createLibraryAddon() {""",
    "js: wire save button")

sub(JS,
    """    nameInput.addEventListener('blur', function () {
      setTimeout(function () {
        if (document.activeElement !== cancelBtn) commit();
      }, 120);
    });""",
    """    nameInput.addEventListener('blur', function () {
      setTimeout(function () {
        var el = document.activeElement;
        if (el !== cancelBtn && el !== row.querySelector('#sv-inline-cat-save')) commit();
      }, 120);
    });""",
    "js: blur respects save button")

# ============================================================
# 5) Styles
# ============================================================
sub(VIEW,
    """.sv-empty{padding:60px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px}""",
    """.sv-empty{padding:60px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px}
/* MARKER-SVC-CAT */
.sv-cat-grouphead{position:relative}
.sv-cat-grouphead.is-hidden-cat{opacity:.6}
.sv-cat-hidden-pill{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
  background:rgba(255,255,255,.07);color:var(--ia-text-muted);border-radius:99px;padding:2px 8px}
.sv-cat-menubtn{background:transparent;border:none;color:var(--ia-text-muted);cursor:pointer;
  font-family:inherit;padding:3px 7px;border-radius:6px;font-size:15px;line-height:1;
  opacity:0;transition:opacity .13s,background .13s}
.sv-cat-grouphead:hover .sv-cat-menubtn,.sv-cat-menubtn:focus-visible{opacity:1}
.sv-cat-menubtn:hover{background:rgba(127,127,127,.14);color:var(--ia-text)}
.sv-cat-menu{position:absolute;right:8px;top:calc(100% - 4px);z-index:30;min-width:196px;padding:5px;
  background:var(--ia-surface,#141414);border:1px solid var(--ia-border-strong,rgba(255,255,255,.2));
  border-radius:9px;box-shadow:0 10px 30px rgba(0,0,0,.45)}
.sv-cat-menu button{display:block;width:100%;text-align:left;background:none;border:none;
  color:var(--ia-text);font-size:12.5px;padding:7px 9px;border-radius:6px;cursor:pointer;font-family:inherit}
.sv-cat-menu button:hover{background:var(--ia-surface-2,#1a1a1a)}
.sv-cat-menu button.danger{color:#F0999B}
.sv-cat-menu-sep{height:.5px;background:var(--ia-border);margin:4px 0}
.sv-cat-rename{flex:1;min-width:0;background:var(--ia-input-bg);border:1px solid var(--ia-accent);
  color:var(--ia-text);border-radius:var(--ia-r-sm,6px);padding:5px 8px;font-size:13px;font-family:inherit}
.sv-cat-mini{background:none;border:1px solid var(--ia-border-strong,rgba(255,255,255,.2));
  color:var(--ia-text);border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:600;
  cursor:pointer;font-family:inherit;white-space:nowrap}
.sv-cat-mini.primary{background:var(--ia-accent);border-color:var(--ia-accent);color:var(--ia-accent-text,#0d0d0d)}
.sv-cat-mini.danger{background:#E88B8B;border-color:#E88B8B;color:#160b0b}
.sv-cat-mini:disabled{opacity:.5;cursor:not-allowed}
.sv-cat-confirm{margin:0 14px 10px;padding:11px 13px;border-radius:9px;font-size:12px;line-height:1.6;
  border:1px solid rgba(251,191,36,.4);background:rgba(251,191,36,.07)}
.sv-cat-confirm.is-danger{border-color:rgba(240,120,120,.4);background:rgba(240,120,120,.07)}
.sv-cat-confirm .t{font-weight:700;font-size:12.5px;margin-bottom:4px;color:#FBBF24}
.sv-cat-confirm.is-danger .t{color:#F0999B}
.sv-cat-confirm .s{color:var(--ia-text-dim)}
.sv-cat-confirm ul{margin:7px 0 0 16px;color:var(--ia-text-dim)}
.sv-cat-confirm li{margin-bottom:2px}
.sv-cat-confirm .r{display:flex;gap:7px;align-items:center;margin-top:10px;flex-wrap:wrap;font-size:11.5px;color:var(--ia-text-dim)}
.sv-cat-confirm select{background:var(--ia-input-bg);border:1px solid var(--ia-border-strong,rgba(255,255,255,.2));
  color:var(--ia-text);border-radius:6px;padding:5px 8px;font-size:12px;font-family:inherit}
.sv-cat-err{display:block;color:#F0999B;font-size:11.5px;margin-top:7px}""",
    "view: styles")

print("\\nDone. No migration needed. view:clear after deploy.")
