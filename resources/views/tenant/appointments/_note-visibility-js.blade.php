{{-- MARKER-NOTE-VISIBILITY: styles + one delegated handler for every
     [data-note-vis] toggle on the page, including notes added after load. --}}
<style>
  .nv-pill{display:inline-flex;align-items:center;gap:5px;font:600 10.5px var(--ia-font);padding:2px 9px;border-radius:99px;border:0;
    background:var(--ia-surface-2,rgba(255,255,255,.06));color:var(--ia-text-dim);white-space:nowrap;line-height:1.6}
  .nv-pill::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;opacity:.7}
  .nv-toggle{cursor:pointer}
  .nv-toggle:hover{color:var(--ia-text);box-shadow:inset 0 0 0 1px var(--ia-border)}
  .nv-toggle.is-on{background:color-mix(in srgb,var(--ia-accent) 16%,transparent);color:var(--ia-accent)}
  body.ia-theme-a .nv-toggle.is-on,body.ia-theme-b .nv-toggle.is-on{color:var(--ia-text)}
  .nv-toggle:disabled{opacity:.5;cursor:default}
  .nv-hint{font-size:11.5px;color:var(--ia-text-dim);margin:6px 0 0}
</style>
<script>
(function () {
  if (window.__noteVisBound) { return; }
  window.__noteVisBound = true;
  var url  = @json(route('tenant.appointments.update', $appointment->id));
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-note-vis]');
    if (!b) { return; }
    e.preventDefault();
    var next = b.dataset.on === '1' ? '0' : '1';
    var fd = new FormData();
    fd.append('_token', (document.querySelector('meta[name="csrf-token"]') || {}).content);
    fd.append('_method', 'PATCH');
    fd.append('op', 'note_visibility');
    fd.append('note_id', b.dataset.noteVis);
    fd.append('is_customer_visible', next);
    b.disabled = true;
    fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        b.disabled = false;
        if (!j || !j.ok) { if (window.IntakeToast) { IntakeToast.error((j && j.message) || 'Could not change it.'); } return; }
        var on = !!j.is_customer_visible;
        b.dataset.on = on ? '1' : '0';
        b.classList.toggle('is-on', on);
        b.textContent = on ? 'Customer can see' : 'Staff only';
        if (window.IntakeToast) { IntakeToast.success(on ? 'Customer can now see this note' : 'Note is staff-only now'); }
      })
      .catch(function () { b.disabled = false; if (window.IntakeToast) { IntakeToast.error('Network error.'); } });
  });
})();
</script>
