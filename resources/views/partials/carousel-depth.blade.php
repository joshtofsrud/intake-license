{{--
  MARKER-CAROUSEL-DEPTH — the "Depth" style of the Image carousel, shared by
  intake.works and shop sites. The centre card is large and in focus; the
  neighbours sit smaller and dimmed behind. Swipe or drag (native scroll
  snap), click a side card to bring it forward, arrows optional. Each
  image's caption is its title; its link adds a button.
  Expects: $images (url, caption, alt, link), $c, $uid, $aspect, $radius,
  $customClass.
--}}
@php
  $cdId     = $uid . 'd';
  $cdBtn    = trim((string) ($c['depth_button'] ?? 'View')) ?: 'View';
  $cdArrows = ! in_array((string) ($c['show_arrows'] ?? '1'), ['0', 'false', ''], true);
  $cdAnchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
  $cdCls    = trim($customClass . (! empty($c['hide_on_mobile']) ? ' cd-hide-m' : '') . (! empty($c['hide_on_desktop']) ? ' cd-hide-d' : ''));
@endphp
<style>
  .{{ $cdId }} { padding: clamp(48px, 7vw, 96px) 0; }
  .{{ $cdId }} .cd-head { max-width: 1180px; margin: 0 auto 28px; padding: 0 24px; }
  .{{ $cdId }} .cd-head h2 { margin: 0 0 8px; font-size: clamp(26px, 3.6vw, 40px); letter-spacing: -.02em; }
  .{{ $cdId }} .cd-head p { margin: 0; opacity: .65; max-width: 620px; }
  .{{ $cdId }} .cd-wrap { position: relative; }
  .{{ $cdId }} .cd-track { display: flex; gap: 20px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; padding: 20px calc(50% - min(280px, 39vw)); outline: none; }
  .{{ $cdId }} .cd-track::-webkit-scrollbar { display: none; }
  .{{ $cdId }} .cd-card { flex: 0 0 min(560px, 78vw); scroll-snap-align: center; margin: 0; cursor: pointer; transform: scale(.86); opacity: .5; transition: transform .45s ease, opacity .45s ease; }
  .{{ $cdId }} .cd-card.is-active { transform: scale(1); opacity: 1; cursor: default; }
  .{{ $cdId }} .cd-img { aspect-ratio: {{ $aspect }}; border-radius: {{ $radius }}; overflow: hidden; background: rgba(127,127,127,.12); box-shadow: 0 24px 60px rgba(0,0,0,.35); }
  .{{ $cdId }} .cd-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .{{ $cdId }} figcaption { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 4px 0; font-weight: 600; }
  .{{ $cdId }} .cd-btn { flex: none; font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: 999px; text-decoration: none; background: var(--mk-accent, var(--p-accent, #BEF264)); color: #111; }
  .{{ $cdId }} .cd-arrow { position: absolute; top: 50%; transform: translateY(-50%); width: 42px; height: 42px; border-radius: 50%; border: 0; cursor: pointer; background: rgba(0,0,0,.5); color: #fff; font-size: 20px; line-height: 1; z-index: 2; }
  .{{ $cdId }} .cd-prev { left: 16px; } .{{ $cdId }} .cd-next { right: 16px; }
  @media (max-width: 768px) { .cd-hide-m { display: none !important; } .{{ $cdId }} .cd-arrow { display: none; } }
  @media (min-width: 769px) { .cd-hide-d { display: none !important; } }
  @media (prefers-reduced-motion: reduce) { .{{ $cdId }} .cd-card { transition: none; } }
</style>
<section class="{{ $cdId }} {{ $cdCls }}" @if($cdAnchor !== '') id="{{ $cdAnchor }}" @endif>
  @if(!empty($c['heading']) || !empty($c['subheading']))
    <div class="cd-head">
      @if(!empty($c['heading']))<h2>{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['subheading']))<p>{{ $c['subheading'] }}</p>@endif
    </div>
  @endif
  <div class="cd-wrap">
    <div class="cd-track" tabindex="0" aria-label="{{ $c['heading'] ?? 'Carousel' }}">
      @foreach($images as $cdI => $cdImg)
        <figure class="cd-card{{ $cdI === 0 ? ' is-active' : '' }}" data-i="{{ $cdI }}">
          <div class="cd-img"><img src="{{ $cdImg['url'] }}" alt="{{ $cdImg['alt'] ?: $cdImg['caption'] }}" loading="lazy"></div>
          @if(($cdImg['caption'] ?? '') !== '' || ($cdImg['link'] ?? '') !== '')
            <figcaption>
              <span>{{ $cdImg['caption'] }}</span>
              @if(($cdImg['link'] ?? '') !== '')<a class="cd-btn" href="{{ $cdImg['link'] }}">{{ $cdBtn }}</a>@endif
            </figcaption>
          @endif
        </figure>
      @endforeach
    </div>
    @if($cdArrows && count($images) > 1)
      <button type="button" class="cd-arrow cd-prev" aria-label="Previous">‹</button>
      <button type="button" class="cd-arrow cd-next" aria-label="Next">›</button>
    @endif
  </div>
</section>
<script>
(function () {
  var root = document.querySelector('.{{ $cdId }}');
  if (!root || root.dataset.cdReady) return;
  root.dataset.cdReady = '1';
  var track = root.querySelector('.cd-track'), cards = root.querySelectorAll('.cd-card');
  if (!track || !cards.length) return;
  var active = 0, raf = 0;
  function centre(i) {
    var c = cards[Math.max(0, Math.min(cards.length - 1, i))];
    track.scrollTo({ left: c.offsetLeft - (track.clientWidth - c.offsetWidth) / 2, behavior: 'smooth' });
  }
  function update() {
    raf = 0;
    var mid = track.scrollLeft + track.clientWidth / 2, best = 0, d = Infinity;
    cards.forEach(function (c, i) { var m = Math.abs(c.offsetLeft + c.offsetWidth / 2 - mid); if (m < d) { d = m; best = i; } });
    if (best === active && cards[best].classList.contains('is-active')) return;
    active = best;
    cards.forEach(function (c, i) { c.classList.toggle('is-active', i === best); });
  }
  track.addEventListener('scroll', function () { if (!raf) raf = requestAnimationFrame(update); }, { passive: true });
  cards.forEach(function (c, i) { c.addEventListener('click', function (e) { if (i !== active) { e.preventDefault(); centre(i); } }); });
  var p = root.querySelector('.cd-prev'), n = root.querySelector('.cd-next');
  if (p) p.addEventListener('click', function () { centre(active - 1); });
  if (n) n.addEventListener('click', function () { centre(active + 1); });
  track.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft')  { e.preventDefault(); centre(active - 1); }
    if (e.key === 'ArrowRight') { e.preventDefault(); centre(active + 1); }
  });
  update();
})();
</script>
