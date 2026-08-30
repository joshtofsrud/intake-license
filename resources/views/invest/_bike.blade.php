{{-- MARKER-INVEST-UNIFY — the one copy of this section. Rendered by the public
     page, the gated page and the investor portal, so they cannot drift apart
     again.

     The swipe-rail script travels WITH the markup rather than sitting in one
     page's footer — that is what made this hard to share before. --}}
<section><div class="wrap">
  <p class="sub">Why bike first</p>
  {{-- MARKER-BIKE-HEADING — no inline size: this sits inside a collapsed
       panel now, at the same weight as every other section heading. --}}
  <h2>The hardest version of the problem.</h2>
  <p class="lede">Specialty bike is a service business, a retail business and a rental business at
    once. A platform that runs a bike shop runs a ski shop, a paddle shop or a fitness studio without
    being rebuilt.</p>

  <div class="railwrap">
  <div class="grid3" id="rail">
    <div class="card"><div class="n">~97k</div><div class="k">Catalog rows, three distributors</div>
      <p>Cross-distributor product matching — months of work and supplier relationships a competitor starts
        from zero on. More distributors are being added, the architecture has no ceiling on how many it
        carries, and the same pipes serve industries beyond bike.</p></div>
    <div class="card"><div class="n">8</div><div class="k">States, founding rep group</div>
      <p>Sold by reps who already walk into every shop in the territory, rather than cold outbound into an
        industry that ignores it.</p></div>
    <div class="card"><div class="n">Live</div><div class="k">In production</div>
      <p>Not a prototype. A founding shop is signed and converting its full point of sale, and the founder's
        own mobile service business runs on it daily.</p></div>
  </div>
    <span class="fade l" id="fadeL"></span><span class="fade r" id="fadeR"></span>
    <button type="button" class="chev l" id="chevL" aria-label="Previous">&#8249;</button>
    <button type="button" class="chev r" id="chevR" aria-label="Next">&#8250;</button>
  </div>
  <div class="railfoot">
    <span class="dots" id="dots"><i class="on"></i><i></i><i></i></span>
    <p class="railhint" id="railhint">1 of 3 &mdash; swipe or tap &#8250;</p>
  </div>

  <ul class="tick">
    <li><b>Owner and GM of a multi-store specialty bicycle retailer</b> — buying, building, hiring,
      scheduling, opening locations, every vendor relationship</li>
    <li><b>70+ cycling events produced</b> through Velo Northwest, and a component brand designed and shipped</li>
    <li><b>Twenty years in the market</b> this is being sold into</li>
  </ul>
</div></section>

{{-- Only where a proposal actually exists to point at. --}}
@if(!empty($docs))
  <section><div class="wrap">
    <p class="fine">All of this is set out properly in the proposal above, with the assumptions behind
      every number and a page on what has to go right. This is the short version.</p>
  </div></section>
@endif

<script>
(function () {
  var rail = document.getElementById('rail');
  if (!rail) { return; }

  var cards = rail.querySelectorAll('.card');
  var dots  = document.getElementById('dots');
  var hint  = document.getElementById('railhint');
  var chevL = document.getElementById('chevL'), chevR = document.getElementById('chevR');
  var fadeL = document.getElementById('fadeL'), fadeR = document.getElementById('fadeR');
  if (!cards.length || !dots || !hint || !chevL || !chevR) { return; }

  function step() { return cards[0].offsetWidth + 11; }

  function sync() {
    var i = Math.round(rail.scrollLeft / step());
    i = Math.max(0, Math.min(cards.length - 1, i));

    for (var d = 0; d < dots.children.length; d++) {
      dots.children[d].className = d === i ? 'on' : '';
    }
    hint.textContent = (i + 1) + ' of ' + cards.length +
      (i < cards.length - 1 ? ' \u2014 swipe or tap \u203A' : '');

    var atStart = rail.scrollLeft < 8;
    var atEnd   = rail.scrollLeft > rail.scrollWidth - rail.clientWidth - 8;
    chevL.style.opacity = atStart ? 0 : 1;
    chevL.style.pointerEvents = atStart ? 'none' : 'auto';
    chevR.style.opacity = atEnd ? 0 : 1;
    chevR.style.pointerEvents = atEnd ? 'none' : 'auto';
    fadeL.style.opacity = atStart ? 0 : 1;
    fadeR.style.opacity = atEnd ? 0 : 1;
  }

  rail.addEventListener('scroll', sync, {passive: true});
  chevR.addEventListener('click', function () { rail.scrollBy({left: step(), behavior: 'smooth'}); });
  chevL.addEventListener('click', function () { rail.scrollBy({left: -step(), behavior: 'smooth'}); });
  window.addEventListener('resize', sync);
  sync();
})();
</script>
