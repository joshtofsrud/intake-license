{{-- MARKER-BUTTON-FX: per-section button effects on intake.works.
     A section's wrapper carries data-btn-fx="arrow|roll|sweep|glow" and/or
     data-btn-ripple="1" (chosen in the builder: Section > Button effect).
     Applies to the buttons inside that section only; nav and footer are untouched. --}}
<style>
  @property --bfx-a{syntax:'<angle>';initial-value:0deg;inherits:false}
  .mkw[data-btn-fx] .bfx, .mkw[data-btn-ripple] .bfx{position:relative;isolation:isolate;overflow:hidden}
  .mkw[data-btn-fx="glow"] .bfx{overflow:visible}
  /* 01 arrow slide-through */
  .bfx .bfx-arw{position:relative;display:inline-block;overflow:hidden;width:1em;height:1.2em;line-height:1.2em;vertical-align:-.18em;margin-left:.15em}
  .bfx .bfx-arw i{position:absolute;left:0;top:0;font-style:normal;transition:transform .35s cubic-bezier(.6,0,.2,1)}
  .bfx .bfx-arw i+i{transform:translateX(-120%)}
  .bfx:hover .bfx-arw i{transform:translateX(120%)}
  .bfx:hover .bfx-arw i+i{transform:translateX(0)}
  /* 02 text roll */
  .bfx .bfx-roll{display:inline-block;overflow:hidden;height:1.25em;line-height:1.25em;vertical-align:bottom}
  .bfx .bfx-roll>span{display:block;transition:transform .38s cubic-bezier(.6,0,.2,1)}
  .bfx .bfx-roll>span::after{content:attr(data-t);display:block}
  .bfx:hover .bfx-roll>span{transform:translateY(-50%)}
  /* 05 fill sweep: fills from the side the pointer came in, drains out the side it leaves */
  .bfx .bfx-fill{position:absolute;inset:0;z-index:-1;border-radius:inherit;transform:scaleX(0);transform-origin:left;transition:transform .32s cubic-bezier(.6,0,.2,1);
    background:var(--mk-accent,#BEF264);pointer-events:none}
  .bfx.bfx-on .bfx-fill{transform:scaleX(1)}
  .bfx.bfx-swept{transition:color .2s}
  .bfx.bfx-on.bfx-swept{color:var(--mk-accent-text,#0a0a0a)!important;border-color:transparent!important}
  .bfx.bfx-onlime .bfx-fill{background:#fff}
  /* 08 glow border */
  .mkw[data-btn-fx="glow"] .bfx::before{content:"";position:absolute;inset:-1.5px;border-radius:inherit;z-index:-1;padding:1.5px;pointer-events:none;
    background:conic-gradient(from var(--bfx-a),transparent 0 62%,color-mix(in srgb,var(--mk-accent,#BEF264) 40%,transparent) 72%,var(--mk-accent,#BEF264) 86%,#fff 90%,transparent 94%);
    -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;animation:bfx-spin 2.6s linear infinite}
  .mkw[data-btn-fx="glow"] .bfx::after{content:"";position:absolute;inset:-10px;border-radius:inherit;z-index:-2;filter:blur(14px);opacity:.5;pointer-events:none;
    background:conic-gradient(from var(--bfx-a),transparent 0 70%,var(--mk-accent,#BEF264) 86%,transparent 96%);animation:bfx-spin 2.6s linear infinite;transition:opacity .25s}
  .mkw[data-btn-fx="glow"] .bfx:hover::after{opacity:.9}
  @keyframes bfx-spin{to{--bfx-a:360deg}}
  /* 10 press + ripple */
  .mkw[data-btn-ripple] .bfx{transition:transform .12s}
  .mkw[data-btn-ripple] .bfx:active{transform:scale(.96)}
  .bfx .bfx-rip{position:absolute;border-radius:50%;pointer-events:none;background:currentColor;opacity:.28;transform:scale(0);animation:bfx-rip .55s ease-out forwards;z-index:0}
  @keyframes bfx-rip{to{transform:scale(1);opacity:0}}
  @media (prefers-reduced-motion:reduce){
    .bfx *, .bfx::before, .bfx::after{animation:none!important;transition:none!important}
  }
</style>
<script>
(function () {
  var SEL = '.mk-btn:not(.mk-btn--link), .cta-btn, .lp-btn, .pq-btn';
  function lime(el) {
    var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(getComputedStyle(el).backgroundColor || '');
    if (!m) return false;
    var r = +m[1], g = +m[2], b = +m[3];
    return g > 180 && r > 140 && b < 160; // the lime primary
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
        if (lime(b)) b.classList.add('bfx-onlime'); else b.classList.add('bfx-swept');
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
  function run() { document.querySelectorAll('.mkw[data-btn-fx], .mkw[data-btn-ripple]').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  // the builder preview redraws sections in place: set up whatever arrives
  var t; new MutationObserver(function () { clearTimeout(t); t = setTimeout(run, 60); }).observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
