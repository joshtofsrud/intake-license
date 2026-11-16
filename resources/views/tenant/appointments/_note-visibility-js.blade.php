{{-- MARKER-NOTE-VISIBILITY: styles + one delegated handler for every
     [data-note-vis] toggle on the page, including notes added after load. --}}
<style>
  .nv-pill{display:inline-flex;align-items:center;gap:5px;font:600 10.5px var(--ia-font);padding:2px 9px;border-radius:99px;border:0;
    background:var(--ia-surface-2,rgba(255,255,255,.06));color:var(--ia-text-dim);white-space:nowrap;line-height:1.6}
  .nv-pill::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;opacity:.7}
  /* a real on/off switch for notes staff wrote; activity notes get the plain pill */
  .nv-toggle{display:inline-flex;align-items:center;gap:7px;border:0;background:none;padding:2px 4px;margin:0;cursor:pointer;
    font:600 11px var(--ia-font);color:var(--ia-text-dim);border-radius:99px;white-space:nowrap}
  .nv-toggle:hover{color:var(--ia-text)}
  .nv-sw{position:relative;width:26px;height:15px;border-radius:99px;background:var(--ia-surface-2,rgba(127,127,127,.25));
    box-shadow:inset 0 0 0 1px var(--ia-border);transition:background .15s;flex:none}
  .nv-sw::after{content:'';position:absolute;top:2px;left:2px;width:11px;height:11px;border-radius:50%;background:var(--ia-text-dim);transition:transform .15s,background .15s}
  .nv-toggle.is-on{color:var(--ia-text)}
  .nv-toggle.is-on .nv-sw{background:var(--ia-accent);box-shadow:none}
  .nv-toggle.is-on .nv-sw::after{transform:translateX(11px);background:var(--ia-accent-text,#111)}
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
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        if (window.IntakeToast) { IntakeToast.success(on ? 'Customer can now see this note' : 'Note is staff-only now'); }
      })
      .catch(function () { b.disabled = false; if (window.IntakeToast) { IntakeToast.error('Network error.'); } });
  });
})();
</script>
