{{-- MARKER-IA-TIP: a small "i" that holds help text, so a screen isn't a wall of words.
     Use: <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Help text, HTML allowed.</span>
     Shows on hover and keyboard focus; on a touch screen a tap opens it and a tap anywhere closes it.
     Looks like the booking "3 items" pop (_asset-count), which is the app's other tooltip. --}}
<style>
  .ia-tip { display: inline-grid; place-content: center; width: 15px; height: 15px; padding: 0; margin: 0 0 0 5px; border-radius: 50%; border: 1px solid var(--ia-border-strong, rgba(127,127,127,.45)); background: transparent; color: var(--ia-text-muted, inherit); font: italic 600 10px/1 Georgia, 'Times New Roman', serif; cursor: help; vertical-align: 1px; flex: none; text-transform: none; letter-spacing: 0; }
  .ia-tip:hover, .ia-tip:focus-visible, .ia-tip.on { border-color: var(--ia-text, currentColor); color: var(--ia-text, inherit); outline: none; }
  .ia-tip-pop { position: fixed; z-index: 1200; max-width: 320px; padding: 10px 12px; border-radius: 12px; font-size: 12.5px; line-height: 1.55; font-weight: 400; text-transform: none; letter-spacing: 0; text-align: left; white-space: normal; background: var(--ia-surface, #fff); color: var(--ia-text, #111); border: 1px solid var(--ia-border-strong, rgba(0,0,0,.2)); box-shadow: 0 12px 32px rgba(0,0,0,.22); }
  .ia-tip-pop b { font-weight: 600; }
  .ia-tip-pop a { color: var(--ia-accent, inherit); }
</style>
<script>
(function () {
  if (window.iaTip) return; window.iaTip = 1;
  var pop = null, forBtn = null;
  function show(btn) {
    var src = btn.nextElementSibling;
    var html = src && src.classList.contains('ia-tip-text') ? src.innerHTML : '';
    if (!html && btn.dataset.tip) { var d = document.createElement('div'); d.textContent = btn.dataset.tip; html = d.innerHTML; }
    if (!html) return;
    if (!pop) {
      pop = document.createElement('div'); pop.className = 'ia-tip-pop'; pop.setAttribute('role', 'tooltip'); document.body.appendChild(pop);
      pop.addEventListener('mouseleave', function () { if (hover) hide(); });
    }
    if (forBtn) forBtn.classList.remove('on');
    pop.innerHTML = html; pop.hidden = false;
    var r = btn.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
    pop.style.left = Math.max(8, Math.min(r.left - 10, innerWidth - w - 8)) + 'px';
    pop.style.top = (r.bottom + 6 + h > innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6) + 'px';
    forBtn = btn; btn.classList.add('on');
  }
  function hide() { if (pop) pop.hidden = true; if (forBtn) forBtn.classList.remove('on'); forBtn = null; }
  var hover = window.matchMedia && matchMedia('(hover: hover)').matches;
  function tipOf(e) { return e.target.closest ? e.target.closest('.ia-tip') : null; }
  document.addEventListener('mouseover', function (e) { var b = tipOf(e); if (b && hover) show(b); });
  // moving from the "i" onto the pop keeps it open, so a link inside it can be clicked
  document.addEventListener('mouseout', function (e) { if (hover && tipOf(e) && !(pop && e.relatedTarget && pop.contains(e.relatedTarget))) hide(); });
  document.addEventListener('focusin', function (e) { var b = tipOf(e); if (b) show(b); });
  document.addEventListener('focusout', function (e) { if (tipOf(e)) hide(); });
  // a tap shows the help and never presses the row, header or label it sits in
  document.addEventListener('click', function (e) {
    var b = tipOf(e);
    if (b) { e.preventDefault(); e.stopPropagation(); (forBtn === b && !hover) ? hide() : show(b); return; }
    if (forBtn && !(pop && pop.contains(e.target))) hide();
  }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
  addEventListener('scroll', hide, { passive: true, capture: true });
})();
</script>
