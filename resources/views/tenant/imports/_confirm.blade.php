{{-- MARKER-IMPORT-PRESETS — in-app confirm. Any form carrying data-confirm
     is intercepted; no native confirm() anywhere in Intake UI. --}}
<style>
.imp-cf-back{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;
  justify-content:center;z-index:900;padding:20px}
.imp-cf-back[hidden]{display:none}
.imp-cf{background:var(--ia-surface);border-radius:var(--ia-r-lg);max-width:420px;width:100%;
  box-shadow:inset 0 0 0 .5px var(--ia-border),0 18px 50px rgba(0,0,0,.5);padding:22px 24px}
.imp-cf h3{font-size:15px;font-weight:650;margin-bottom:7px}
.imp-cf p{font-size:13px;color:var(--ia-text-dim);line-height:1.55;margin-bottom:18px}
.imp-cf-acts{display:flex;gap:9px;justify-content:flex-end}
</style>

<div class="imp-cf-back" id="imp-cf-back" hidden>
  <div class="imp-cf" role="dialog" aria-modal="true" aria-labelledby="imp-cf-t">
    <h3 id="imp-cf-t">Are you sure?</h3>
    <p id="imp-cf-b"></p>
    <div class="imp-cf-acts">
      <button type="button" class="ia-btn ia-btn--ghost" id="imp-cf-no">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="imp-cf-yes">Confirm</button>
    </div>
  </div>
</div>

<script>
(function () {
  var back = document.getElementById('imp-cf-back');
  var body = document.getElementById('imp-cf-b');
  var yes  = document.getElementById('imp-cf-yes');
  var no   = document.getElementById('imp-cf-no');
  var pending = null;

  function close() { back.hidden = true; pending = null; }

  no.addEventListener('click', close);
  back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !back.hidden) { close(); } });

  yes.addEventListener('click', function () {
    var f = pending;
    close();
    if (f) { f.dataset.confirmed = '1'; f.submit(); }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.dataset || !f.dataset.confirm || f.dataset.confirmed === '1') { return; }
    e.preventDefault();
    body.textContent = f.dataset.confirm;
    yes.textContent  = f.dataset.confirmLabel || 'Confirm';
    pending = f;
    back.hidden = false;
    yes.focus();
  }, true);
})();
</script>
