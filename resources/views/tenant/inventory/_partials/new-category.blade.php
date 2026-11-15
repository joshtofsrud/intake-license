{{-- MARKER-INV-NEW-CATEGORY: make a category without leaving the item form.
     The "+ New category" button sits beside the Category label, outside the
     list, so it is there however many categories a shop has. The category is
     made straight away (kept even if the item is never saved) and picked. --}}
@php $ncParents = $catOpts ?? []; @endphp
<div class="nc-panel" id="ncPanel" hidden>
  <div class="nc-h">New category</div>
  <div class="nc-row">
    <label><span class="ia-form-label">Name</span><input type="text" class="ia-input" id="ncName" maxlength="128" autocomplete="off"></label>
    <label><span class="ia-form-label">Sits under</span>
      <x-tenant.searchable-select name="_nc_parent" :options="$ncParents" selected="" any="Top level (no parent)" noun="categories" :searchable="count($ncParents) >= 12" :assoc="true" />
    </label>
    <div class="nc-acts">
      <button type="button" class="ia-btn ia-btn--sm" id="ncCancel">Cancel</button>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" id="ncGo">Create and use</button>
    </div>
  </div>
  <div class="nc-err" id="ncErr" hidden></div>
  <div class="ia-form-hint" style="margin-top:6px">Made straight away and kept even if you leave this item unsaved. Rename or move it later under Categories.</div>
</div>
<style>
  .nc-lbl{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .nc-add{background:none;border:0;padding:0;font-size:12.5px;font-weight:500;color:var(--ia-accent);cursor:pointer}
  .nc-add:hover{text-decoration:underline}
  .nc-panel{margin-top:10px;padding:12px 14px;border:.5px dashed var(--ia-border-strong);border-radius:var(--ia-r-md)}
  .nc-h{font-size:12.5px;font-weight:500;margin-bottom:8px}
  .nc-row{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}
  .nc-row label{margin:0}
  .nc-acts{display:flex;gap:8px}
  .nc-err{margin-top:8px;font-size:12.5px;color:var(--ia-error,#F09595)}
  @media (max-width:640px){.nc-row{grid-template-columns:1fr}}
</style>
<script>
(function () {
  var btn = document.getElementById('ncOpen'), panel = document.getElementById('ncPanel');
  if (!btn || !panel) return;
  var name = document.getElementById('ncName'), err = document.getElementById('ncErr'), go = document.getElementById('ncGo');
  function catPicker() { var v = document.querySelector('.ssel[data-name="category_id"]'); return v && v.__sselApi; }
  function parentVal() { var v = panel.querySelector('.ssel[data-name="_nc_parent"] .ssel-val'); return v ? v.value : ''; }
  btn.addEventListener('click', function () { panel.hidden = !panel.hidden; if (!panel.hidden) { err.hidden = true; name.focus(); } });
  document.getElementById('ncCancel').addEventListener('click', function () { panel.hidden = true; });
  name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go.click(); } });
  go.addEventListener('click', function () {
    var n = name.value.trim();
    if (!n) { err.textContent = 'Give the category a name.'; err.hidden = false; name.focus(); return; }
    go.disabled = true; err.hidden = true;
    fetch(@json(route('tenant.inventory.categories.quick')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
      body: JSON.stringify({ name: n, parent_id: parentVal() || null })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        go.disabled = false;
        if (!res.ok || !res.j.id) {
          err.textContent = (res.j && res.j.message) || 'That category could not be made. Try again.';
          err.hidden = false; return;
        }
        var d = res.j.depth || 0;
        var label = '   '.repeat(Math.max(0, d - 1)) + (d ? '└ ' : '') + res.j.name;
        var api = catPicker();
        if (api && api.addOption) api.addOption(res.j.id, label, true);
        // offer it as a parent too, for the next one
        var pp = panel.querySelector('.ssel[data-name="_nc_parent"]');
        if (pp && pp.__sselApi && pp.__sselApi.addOption) pp.__sselApi.addOption(res.j.id, label, false);
        name.value = ''; panel.hidden = true;
        if (window.IntakeToast && window.IntakeToast.success) { window.IntakeToast.success('Category "' + res.j.name + '" made and chosen.'); }
      })
      .catch(function () { go.disabled = false; err.textContent = 'That category could not be made. Check your connection and try again.'; err.hidden = false; });
  });
})();
</script>
