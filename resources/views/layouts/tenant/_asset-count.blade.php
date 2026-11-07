{{-- MARKER-MULTI-ASSET-COUNT: one list for every "3 items" button on the page. --}}
<style>
  .ia-asset-count { display: inline-block; flex: none; font: inherit; font-size: 10.5px; font-weight: 700; line-height: 1.5; padding: 0 7px; border-radius: 99px; border: 1px solid var(--ia-border-strong, rgba(127,127,127,.35)); background: var(--ia-surface, transparent); color: var(--ia-text, inherit); cursor: help; vertical-align: middle; white-space: nowrap; }
  .ia-asset-count:hover, .ia-asset-count:focus-visible { border-color: var(--ia-text, currentColor); outline: none; }
  .ia-asset-pop { position: fixed; z-index: 1200; min-width: 240px; max-width: 340px; padding: 10px 12px; border-radius: 12px; font-size: 12.5px; background: var(--ia-surface, #fff); color: var(--ia-text, #111); border: 1px solid var(--ia-border-strong, rgba(0,0,0,.2)); box-shadow: 0 12px 32px rgba(0,0,0,.22); }
  .ia-asset-pop h4 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; opacity: .6; font-weight: 600; margin: 0 0 6px; }
  .ia-asset-pop div { padding: 6px 0; border-top: 1px solid var(--ia-border, rgba(127,127,127,.2)); }
  .ia-asset-pop div:first-of-type { border-top: 0; }
  .ia-asset-pop b { display: flex; justify-content: space-between; gap: 12px; font-weight: 600; }
  .ia-asset-pop b span { font: 500 11px var(--ia-font-mono, monospace); opacity: .7; white-space: nowrap; }
  .ia-asset-pop small { display: block; opacity: .7; font-size: 11.5px; margin-top: 2px; }
</style>
<script>
(function () {
  if (window.iaAssetCount) return; window.iaAssetCount = 1;
  var pop = null, forBtn = null;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function show(btn) {
    var list; try { list = JSON.parse(btn.dataset.assets || '[]'); } catch (e) { list = []; }
    if (!list.length) return;
    if (!pop) { pop = document.createElement('div'); pop.className = 'ia-asset-pop'; pop.setAttribute('role', 'tooltip'); document.body.appendChild(pop); }
    pop.innerHTML = '<h4>' + list.length + ' on this booking</h4>' + list.map(function (a) {
      return '<div><b>' + esc(a.name) + (a.when ? '<span>' + esc(a.when) + '</span>' : '') + '</b><small>' + esc(a.services) + '</small></div>';
    }).join('');
    pop.hidden = false;
    var r = btn.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
    pop.style.left = Math.max(8, Math.min(r.left, innerWidth - w - 8)) + 'px';
    pop.style.top = (r.bottom + 8 + h > innerHeight ? Math.max(8, r.top - h - 8) : r.bottom + 8) + 'px';
    forBtn = btn;
  }
  function hide() { if (pop) pop.hidden = true; forBtn = null; }
  var hover = window.matchMedia && matchMedia('(hover: hover)').matches;
  document.addEventListener('mouseover', function (e) { var b = e.target.closest && e.target.closest('.ia-asset-count'); if (b && hover) show(b); });
  document.addEventListener('mouseout', function (e) { if (hover && e.target.closest && e.target.closest('.ia-asset-count')) hide(); });
  document.addEventListener('focusin', function (e) { var b = e.target.closest && e.target.closest('.ia-asset-count'); if (b) show(b); });
  document.addEventListener('focusout', function (e) { if (e.target.closest && e.target.closest('.ia-asset-count')) hide(); });
  // a tap shows the list and never opens the card or row it sits on
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.ia-asset-count');
    if (b) { e.preventDefault(); e.stopPropagation(); forBtn === b ? hide() : show(b); return; }
    if (forBtn) hide();
  }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
  addEventListener('scroll', hide, { passive: true, capture: true });
})();
</script>
