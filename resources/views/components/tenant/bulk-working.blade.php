{{-- MARKER-BULK-WORKING — the reassurance a long action needs.

     Any form carrying data-bulk-count="N" shows this the moment it is
     submitted, when N is over the threshold. It appears on SUBMIT rather than
     on a response, so a request that takes forty seconds still feels
     deliberate from the first click. It also disables the submit button,
     because the instinct on a frozen page is to press it again. --}}
@props(['threshold' => 250])

<div id="ia-working" hidden aria-live="polite" aria-busy="true">
  <div class="ia-working-box">
    <span class="ia-working-spin" aria-hidden="true"></span>
    <div>
      <div class="ia-working-t">Working on <span id="ia-working-n">these</span> items</div>
      <div class="ia-working-s">This can take a minute. You can leave this page — it keeps going.</div>
    </div>
  </div>
</div>

<style>
  #ia-working{position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.55);
    display:none;align-items:center;justify-content:center;padding:20px}
  #ia-working.on{display:flex}
  .ia-working-box{display:flex;align-items:center;gap:14px;background:var(--ia-surface);
    border:.5px solid var(--ia-border);border-radius:14px;padding:18px 22px;max-width:420px}
  .ia-working-t{font-size:14px;font-weight:600}
  .ia-working-s{font-size:12.5px;color:var(--ia-text-dim);margin-top:3px;line-height:1.5}
  .ia-working-spin{width:20px;height:20px;border-radius:50%;flex:none;
    border:2px solid var(--ia-border);border-top-color:var(--ia-accent);
    animation:ia-working-turn .8s linear infinite}
  @keyframes ia-working-turn{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.ia-working-spin{animation:none;border-top-color:var(--ia-accent)}}
</style>

<script>
(function () {
  var THRESHOLD = {{ (int) $threshold }};

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches('[data-bulk-count]')) { return; }

    // The count can be a number on the form, or the name of a field holding
    // one (a select-all scope), or the number of ticked boxes.
    var raw = form.getAttribute('data-bulk-count');
    var n = parseInt(raw, 10);
    if (isNaN(n)) {
      var src = form.querySelector('[name="' + raw + '"]');
      n = src ? parseInt(src.value, 10) : NaN;
    }
    if (isNaN(n)) { n = form.querySelectorAll('input[type=checkbox]:checked').length; }
    if (!n || n < THRESHOLD) { return; }

    var box = document.getElementById('ia-working');
    if (box) {
      var label = document.getElementById('ia-working-n');
      if (label) { label.textContent = n.toLocaleString(); }
      box.hidden = false;
      box.classList.add('on');
    }

    // Disable the submit buttons AFTER the browser has collected the form's
    // values — a disabled button's own name/value is not submitted, and on
    // some of these forms that value is the action.
    var btns = form.querySelectorAll('button[type=submit], input[type=submit]');
    setTimeout(function () {
      btns.forEach(function (b) {
        b.disabled = true;
        if (b.tagName === 'BUTTON' && !b.dataset.keepLabel) {
          b.dataset.wasLabel = b.textContent;
          b.textContent = 'Working on ' + n.toLocaleString() + '…';
        }
      });
    }, 0);
  }, true);

  // Coming back via the bfcache would otherwise show a stale overlay.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) { return; }
    var box = document.getElementById('ia-working');
    if (box) { box.classList.remove('on'); box.hidden = true; }
    document.querySelectorAll('[data-bulk-count] button[type=submit]').forEach(function (b) {
      b.disabled = false;
      if (b.dataset.wasLabel) { b.textContent = b.dataset.wasLabel; }
    });
  });
})();
</script>
