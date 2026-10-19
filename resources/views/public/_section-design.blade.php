{{-- MARKER-SECTION-DESIGN: per-section design settings on shop sites, ported
     from intake.works (marketing/page.blade.php + partials/button-fx): appear
     on scroll, background blend and "continue from above", button effects,
     hide on tablet, divider line. Sections only get a .pbw wrapper when one of
     these is set (App\Support\ShopSectionDesign), so untouched pages render as
     before. Included twice by public/layout.blade.php: part=head and part=foot. --}}
@if(($sdPart ?? 'head') === 'head')
<script>/* hide appearing sections only when they can be shown again */
  if (!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) && 'IntersectionObserver' in window) document.documentElement.classList.add('pb-appear-on');
</script>
<style>
  /* the content appears; the background is always there */
  html.pb-appear-on .pbw-appear > section > *, html.pb-appear-on .pbw-appear > footer > * { opacity: 0; transition: opacity var(--pb-appear-dur, 700ms) ease, transform var(--pb-appear-dur, 700ms) ease; transition-delay: var(--pb-appear-delay, 0ms); }
  html.pb-appear-on .pbw-appear-up > section > *, html.pb-appear-on .pbw-appear-up > footer > * { transform: translateY(28px); }
  html.pb-appear-on .pbw-appear.is-in > section > *, html.pb-appear-on .pbw-appear.is-in > footer > * { opacity: 1; transform: none; }
  .pbw-divider > section, .pbw-divider > footer { border-bottom: 1px solid var(--p-border, rgba(0,0,0,.1)) !important; background-origin: border-box !important; }
  /* sections sharing one gradient show no line between them */
  .pbw[data-bg-chain] > section { border-bottom-width: 0 !important; }
  /* phone ≤768, tablet 769–1024, desktop ≥769 (the shop sections' own breakpoint) */
  @media (max-width: 768px) { .pbw-hide-m { display: none !important; } }
  @media (min-width: 769px) and (max-width: 1024px) { .pbw-hide-t { display: none !important; } }
  @media (min-width: 769px) { .pbw-hide-d { display: none !important; } }
  @media (min-width: 769px) and (max-width: 1024px) { [data-pb-section]:has(> .pbw-hide-t) { display: none; } }

  /* button effects (Section › Button effect) */
  @property --bfx-a { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
  .pbw[data-btn-fx] .bfx, .pbw[data-btn-ripple] .bfx { position: relative; isolation: isolate; overflow: hidden; }
  .pbw[data-btn-fx="glow"] .bfx { overflow: visible; }
  .bfx .bfx-arw { position: relative; display: inline-block; overflow: hidden; width: 1em; height: 1.2em; line-height: 1.2em; vertical-align: -.18em; margin-left: .15em; }
  .bfx .bfx-arw i { position: absolute; left: 0; top: 0; font-style: normal; transition: transform .35s cubic-bezier(.6,0,.2,1); }
  .bfx .bfx-arw i + i { transform: translateX(-120%); }
  .bfx:hover .bfx-arw i { transform: translateX(120%); }
  .bfx:hover .bfx-arw i + i { transform: translateX(0); }
  .bfx .bfx-roll { display: inline-block; overflow: hidden; height: 1.25em; line-height: 1.25em; vertical-align: bottom; }
  .bfx .bfx-roll > span { display: block; transition: transform .38s cubic-bezier(.6,0,.2,1); }
  .bfx .bfx-roll > span::after { content: attr(data-t); display: block; }
  .bfx:hover .bfx-roll > span { transform: translateY(-50%); }
  .bfx .bfx-fill { position: absolute; inset: 0; z-index: -1; border-radius: inherit; transform: scaleX(0); transform-origin: left; transition: transform .32s cubic-bezier(.6,0,.2,1); background: var(--p-accent, #0a0a0a); pointer-events: none; }
  .bfx.bfx-on .bfx-fill { transform: scaleX(1); }
  .bfx.bfx-swept { transition: color .2s; }
  .bfx.bfx-on.bfx-swept { color: var(--p-accent-text, #ffffff) !important; border-color: transparent !important; }
  .bfx.bfx-onaccent .bfx-fill { background: #ffffff; }
  .bfx.bfx-on.bfx-onaccent { color: #0a0a0a !important; }
  .pbw[data-btn-fx="glow"] .bfx::before { content: ""; position: absolute; inset: -1.5px; border-radius: inherit; z-index: -1; padding: 1.5px; pointer-events: none;
    background: conic-gradient(from var(--bfx-a), transparent 0 62%, color-mix(in srgb, var(--p-accent, #0a0a0a) 40%, transparent) 72%, var(--p-accent, #0a0a0a) 86%, #fff 90%, transparent 94%);
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; animation: bfx-spin 2.6s linear infinite; }
  .pbw[data-btn-fx="glow"] .bfx::after { content: ""; position: absolute; inset: -10px; border-radius: inherit; z-index: -2; filter: blur(14px); opacity: .5; pointer-events: none;
    background: conic-gradient(from var(--bfx-a), transparent 0 70%, var(--p-accent, #0a0a0a) 86%, transparent 96%); animation: bfx-spin 2.6s linear infinite; transition: opacity .25s; }
  .pbw[data-btn-fx="glow"] .bfx:hover::after { opacity: .9; }
  @keyframes bfx-spin { to { --bfx-a: 360deg; } }
  .pbw[data-btn-ripple] .bfx { transition: transform .12s; }
  .pbw[data-btn-ripple] .bfx:active { transform: scale(.96); }
  .bfx .bfx-rip { position: absolute; border-radius: 50%; pointer-events: none; background: currentColor; opacity: .28; transform: scale(0); animation: bfx-rip .55s ease-out forwards; z-index: 0; }
  @keyframes bfx-rip { to { transform: scale(1); opacity: 0; } }
  @media (prefers-reduced-motion: reduce) { .bfx *, .bfx::before, .bfx::after { animation: none !important; transition: none !important; } }
</style>
@else
<script>
/* reveal each section when its top reaches 85% of the window. */
(function () {
  if (!document.documentElement.classList.contains('pb-appear-on')) return;
  var pending = Array.prototype.slice.call(document.querySelectorAll('.pbw-appear'));
  var raf = 0;
  function check() {
    raf = 0;
    var line = window.innerHeight * 0.85;
    pending = pending.filter(function (el) {
      if (el.getClientRects().length === 0) return true;          // hidden on this screen size
      if (el.getBoundingClientRect().top < line) { el.classList.add('is-in'); return false; }
      return true;
    });
    if (!pending.length) { window.removeEventListener('scroll', onScroll); window.removeEventListener('resize', onScroll); }
  }
  function onScroll() { if (!raf) raf = requestAnimationFrame(check); }
  // the builder preview calls this after it redraws a section
  window.mkAppearScan = function () {
    var fresh = Array.prototype.slice.call(document.querySelectorAll('.pbw-appear:not(.is-in)'));
    if (!fresh.length) return;
    pending = fresh;
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    check();
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  window.addEventListener('load', check);
  check();
})();
</script>
<script>
/* join each gradient with the visible sections that continue it (sections
   hidden on this screen size are skipped) and paint one gradient across them. */
(function () {
  if (!document.querySelector('.pbw[data-bg-cont]')) { window.mkPaintBg = function () {}; return; }
  var PROPS = ['background', 'background-size', 'background-position', 'background-repeat'];
  function sec(el) { return el.querySelector(':scope > section') || el.querySelector(':scope > footer'); }
  function docTop(el) { return el.getBoundingClientRect().top + window.scrollY; }
  function paint() {
    document.querySelectorAll('.pbw[data-bg-chain]').forEach(function (el) {
      el.removeAttribute('data-bg-chain');
      el.removeAttribute('data-bg-end-eff');
      var s = sec(el); if (s) PROPS.forEach(function (p) { s.style.removeProperty(p); });
    });
    var all = Array.prototype.filter.call(document.querySelectorAll('.pbw'), function (el) { return el.getClientRects().length > 0; });
    for (var i = 0; i < all.length; i++) {
      var head = all[i];
      if (!head.dataset.bgGrad || head.dataset.bgCont) continue;
      var j = i + 1;
      while (j < all.length && all[j].dataset.bgCont) j++;
      if (j === i + 1) continue;
      var chain = all.slice(i, j);
      var top = docTop(chain[0]);
      var total = chain[chain.length - 1].getBoundingClientRect().bottom + window.scrollY - top;
      chain.forEach(function (el) {
        var s = sec(el); if (!s) return;
        el.setAttribute('data-bg-chain', '1');
        el.setAttribute('data-bg-end-eff', head.dataset.bgEnd || '');
        s.style.setProperty('background', head.dataset.bgGrad, 'important');
        s.style.setProperty('background-size', '100% ' + total + 'px', 'important');
        s.style.setProperty('background-position', '0 ' + (top - docTop(s)) + 'px', 'important');
        s.style.setProperty('background-repeat', 'no-repeat', 'important');
      });
      i = j - 1;
    }
    // continuing sections not in a run start from whatever is visibly above them
    for (var k = 1; k < all.length; k++) {
      var el = all[k];
      if (!el.dataset.bgCont || el.hasAttribute('data-bg-chain') || !el.dataset.bgContCss) continue;
      var prev = all[k - 1];
      var end = prev.hasAttribute('data-bg-end-eff') ? prev.getAttribute('data-bg-end-eff') : prev.dataset.bgEnd;
      var s2 = sec(el); if (!s2) continue;
      el.setAttribute('data-bg-chain', '1');
      var startCol = end || 'var(--p-bg, #ffffff)';
      var over = prev.getBoundingClientRect().bottom - s2.getBoundingClientRect().top;
      var css = over > 0.5
        ? el.dataset.bgContCss.replace('{PREV} 0%', 'transparent 0px, transparent ' + over.toFixed(2) + 'px, ' + startCol + ' ' + over.toFixed(2) + 'px')
        : el.dataset.bgContCss.replace('{PREV}', startCol);
      s2.style.setProperty('background', css, 'important');
    }
  }
  var t;
  function soon() { clearTimeout(t); t = setTimeout(paint, 60); }
  window.mkPaintBg = paint;
  paint();
  window.addEventListener('load', paint);
  window.addEventListener('resize', soon);
  if (window.ResizeObserver) new ResizeObserver(soon).observe(document.body);
})();
</script>
<script>
/* button effects: set up the buttons inside sections that ask for one */
(function () {
  if (!document.querySelector('.pbw[data-btn-fx], .pbw[data-btn-ripple]') && !document.querySelector('[data-pb-section]')) return;
  var SEL = '.p-btn, .p-hero-btn, .p-ti-btn, .p-cta-btn, .p-ftr-cta-btn';
  function rgb(s) { var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(s || ''); return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null; }
  function accent() {
    var probe = document.createElement('span'); probe.style.color = 'var(--p-accent)'; probe.style.display = 'none';
    document.body.appendChild(probe); var c = rgb(getComputedStyle(probe).color); probe.remove(); return c;
  }
  // a button already filled with the accent sweeps white instead
  function onAccent(el) {
    var b = rgb(getComputedStyle(el).backgroundColor), a = accent();
    if (!b || !a || b[3] === 0) return false;
    return Math.abs(b[0] - a[0]) + Math.abs(b[1] - a[1]) + Math.abs(b[2] - a[2]) < 30;
  }
  function textNodes(el) { return Array.prototype.filter.call(el.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim(); }); }
  function setup(wrap) {
    var fx = wrap.getAttribute('data-btn-fx') || '', rip = wrap.hasAttribute('data-btn-ripple');
    wrap.querySelectorAll(SEL).forEach(function (b) {
      if (b.dataset.bfx) return; b.dataset.bfx = '1'; b.classList.add('bfx');
      if (fx === 'arrow') {
        var done = false;
        textNodes(b).forEach(function (n) {
          var m = /\s*(→|->|›)\s*$/.exec(n.textContent);
          if (m && !done) { n.textContent = n.textContent.slice(0, m.index); done = true; }
        });
        var a = document.createElement('span'); a.className = 'bfx-arw'; a.setAttribute('aria-hidden', 'true');
        a.innerHTML = '<i>→</i><i>→</i>'; b.appendChild(a);
      } else if (fx === 'roll') {
        textNodes(b).forEach(function (n) {
          var t = n.textContent.trim(); if (!t) return;
          var lead = /^\s/.test(n.textContent) ? ' ' : '', trail = /\s$/.test(n.textContent) ? ' ' : '';
          var o = document.createElement('span'); o.className = 'bfx-roll';
          var i = document.createElement('span'); i.textContent = t; i.setAttribute('data-t', t);
          o.appendChild(i);
          if (lead) b.insertBefore(document.createTextNode(lead), n);
          b.insertBefore(o, n);
          if (trail) b.insertBefore(document.createTextNode(trail), n);
          b.removeChild(n);
        });
      } else if (fx === 'sweep') {
        var f = document.createElement('span'); f.className = 'bfx-fill'; b.insertBefore(f, b.firstChild);
        b.classList.add(onAccent(b) ? 'bfx-onaccent' : 'bfx-swept');
        b.addEventListener('mouseenter', function (e) {
          var r = b.getBoundingClientRect(); f.style.transformOrigin = (e.clientX - r.left < r.width / 2) ? 'left' : 'right';
          b.classList.add('bfx-on');
        });
        b.addEventListener('mouseleave', function (e) {
          var r = b.getBoundingClientRect(); f.style.transformOrigin = (e.clientX - r.left < r.width / 2) ? 'left' : 'right';
          b.classList.remove('bfx-on');
        });
      }
      if (rip) {
        b.addEventListener('pointerdown', function (e) {
          var r = b.getBoundingClientRect(), s = Math.max(r.width, r.height) * 2.2;
          var d = document.createElement('span'); d.className = 'bfx-rip';
          d.style.width = d.style.height = s + 'px';
          d.style.left = (e.clientX - r.left - s / 2) + 'px'; d.style.top = (e.clientY - r.top - s / 2) + 'px';
          b.appendChild(d); setTimeout(function () { d.remove(); }, 600);
        });
      }
    });
  }
  function run() { document.querySelectorAll('.pbw[data-btn-fx], .pbw[data-btn-ripple]').forEach(setup); }
  run();
  // the builder preview redraws sections in place: set up whatever arrives
  if (document.querySelector('[data-pb-section]')) {
    var t; new MutationObserver(function () { clearTimeout(t); t = setTimeout(run, 60); }).observe(document.body, { childList: true, subtree: true });
  }
})();
</script>
@endif
