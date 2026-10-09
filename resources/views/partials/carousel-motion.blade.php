{{--
  the "Motion" style of the Image carousel, shared by intake.works and shop
  sites. One row of photos at their own widths that glides: wheel, drag or
  swipe, eased to a stop; each photo drifts slightly inside its frame.
  The wheel only moves the row sideways while it has room, then the page
  scrolls on, so visitors never get stuck. Optional black & white (colour
  on hover). Each image's caption shows under it; its link makes it clickable.
  Expects: $images (url, caption, alt, link), $c, $uid, $radius, $customClass.
--}}
@php
  $cmId     = $uid . 'm';
  $cmH      = ['m' => 360, 'l' => 460][$c['motion_height'] ?? 'm'] ?? 360;
  $cmMono   = in_array((string) ($c['motion_mono'] ?? ''), ['1', 'true'], true) || ($c['motion_mono'] ?? null) === true;
  $cmCaps   = in_array((string) ($c['show_captions'] ?? ''), ['1', 'true'], true) || ($c['show_captions'] ?? null) === true;
  $cmAnchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
  $cmCls    = trim($customClass . (empty($mkWrap) && ! empty($c['hide_on_mobile']) ? ' cm-hide-m' : '') . (empty($mkWrap) && ! empty($c['hide_on_desktop']) ? ' cm-hide-d' : ''));
  $cmW      = [1.45, .82, 1.2, .95, .75, 1.35, .85, 1.05, .8];
@endphp
<style>
  .{{ $cmId }} { padding: clamp(48px, 7vw, 96px) 0; overflow: hidden; }
  .{{ $cmId }} .cm-head { max-width: 1180px; margin: 0 auto 28px; padding: 0 24px; }
  .{{ $cmId }} .cm-head h2 { margin: 0 0 8px; font-size: clamp(26px, 3.6vw, 40px); letter-spacing: -.02em; }
  .{{ $cmId }} .cm-head p { margin: 0; opacity: .65; max-width: 620px; }
  .{{ $cmId }} .cm-view { overflow: hidden; cursor: grab; touch-action: pan-y; user-select: none; -webkit-user-select: none; padding: 8px 0; outline: none; }
  .{{ $cmId }} .cm-view.is-drag { cursor: grabbing; }
  .{{ $cmId }} .cm-track { display: flex; gap: 8px; width: max-content; padding: 0 24px; will-change: transform; }
  .{{ $cmId }} .cm-item { flex: none; margin: 0; }
  .{{ $cmId }} .cm-frame { position: relative; display: block; height: {{ $cmH }}px; overflow: hidden; border-radius: {{ $radius }}; background: rgba(127,127,127,.12); transform-origin: 50% 50%; }
  .{{ $cmId }} .cm-frame img { position: absolute; top: 0; left: -9%; width: 118%; height: 100%; object-fit: cover; pointer-events: none; will-change: transform; transition: filter .5s ease; -webkit-user-drag: none; }
  .{{ $cmId }}.is-mono .cm-frame img { filter: grayscale(1) contrast(1.05); }
  .{{ $cmId }}.is-mono .cm-item:hover .cm-frame img { filter: none; }
  .{{ $cmId }} figcaption { padding: 10px 2px 0; font-size: 13px; opacity: .7; max-width: 100%; }
  .{{ $cmId }} .cm-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; max-width: none; padding: 14px 24px 0; font-size: 11.5px; opacity: .55; letter-spacing: .02em; }
  .{{ $cmId }} .cm-prog { display: flex; align-items: center; gap: 12px; font-variant-numeric: tabular-nums; }
  .{{ $cmId }} .cm-bar { width: 120px; height: 2px; border-radius: 2px; background: rgba(127,127,127,.3); overflow: hidden; }
  .{{ $cmId }} .cm-bar b { display: block; width: 100%; height: 100%; transform-origin: left; transform: scaleX(.08); background: var(--mk-accent, var(--p-accent, currentColor)); }
  @media (max-width: 768px) { .cm-hide-m { display: none !important; } .{{ $cmId }} .cm-frame { height: {{ (int) round($cmH * .62) }}px; } .{{ $cmId }} .cm-track { padding: 0 16px; } }
  @media (min-width: 769px) { .cm-hide-d { display: none !important; } }
  @media (prefers-reduced-motion: reduce) { .{{ $cmId }} .cm-frame img { transform: none !important; } }
</style>
<section class="{{ $cmId }} {{ $cmCls }}{{ $cmMono ? ' is-mono' : '' }}" @if($cmAnchor !== '') id="{{ $cmAnchor }}" @endif>
  @if(!empty($c['heading']) || !empty($c['subheading']))
    <div class="cm-head">
      @if(!empty($c['heading']))<h2>{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['subheading']))<p>{{ $c['subheading'] }}</p>@endif
    </div>
  @endif
  <div class="cm-view" tabindex="0" aria-label="{{ $c['heading'] ?? 'Photo gallery' }}">
    <div class="cm-track">
      @foreach($images as $cmI => $cmImg)
        @php $cmLink = trim((string) ($cmImg['link'] ?? '')); $cmSafe = preg_match('#^(https?://|/|\#)#i', $cmLink) ? $cmLink : ''; @endphp
        <figure class="cm-item" style="width: {{ (int) round($cmH * $cmW[$cmI % count($cmW)]) }}px" data-w="{{ $cmW[$cmI % count($cmW)] }}">
          @if($cmSafe !== '')
            <a class="cm-frame" href="{{ $cmSafe }}" draggable="false"><img src="{{ $cmImg['url'] }}" alt="{{ $cmImg['alt'] ?: $cmImg['caption'] }}" loading="lazy" draggable="false"></a>
          @else
            <div class="cm-frame"><img src="{{ $cmImg['url'] }}" alt="{{ $cmImg['alt'] ?: $cmImg['caption'] }}" loading="lazy" draggable="false"></div>
          @endif
          @if($cmCaps && ($cmImg['caption'] ?? '') !== '')<figcaption>{{ $cmImg['caption'] }}</figcaption>@endif
        </figure>
      @endforeach
    </div>
  </div>
  @if(count($images) > 1)
    <div class="cm-foot"><span>( Scroll, drag or swipe )</span><span class="cm-prog"><span class="cm-n">01 / {{ str_pad((string) count($images), 2, '0', STR_PAD_LEFT) }}</span><span class="cm-bar"><b></b></span></span></div>
  @endif
</section>
<script>
(function () {
  var root = document.querySelector('.{{ $cmId }}');
  if (!root || root.dataset.cmReady) return;
  root.dataset.cmReady = '1';
  var view = root.querySelector('.cm-view'), track = root.querySelector('.cm-track');
  var items = [].slice.call(root.querySelectorAll('.cm-item'));
  if (!view || !track || !items.length) return;
  var bar = root.querySelector('.cm-bar b'), num = root.querySelector('.cm-n');
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var pos = 0, target = 0, max = 0, running = false, down = false, moved = 0, sx = 0, st = 0, lx = 0, lt = 0, v = 0;
  // once a photo loads, blend its real shape into the varied width
  items.forEach(function (it) {
    var img = it.querySelector('img'), f = it.querySelector('.cm-frame');
    function size() { if (!img.naturalWidth) return; var r = img.naturalWidth / img.naturalHeight, w = parseFloat(it.dataset.w) || 1;
      it.style.width = Math.round(f.clientHeight * Math.min(1.6, Math.max(.68, r * .7 + w * .3))) + 'px'; measure(); }
    if (img.complete) size(); else img.addEventListener('load', size);
  });
  function measure() { max = Math.max(0, track.scrollWidth - view.clientWidth); target = Math.min(target, max); kick(); }
  function clamp(x) { return Math.max(0, Math.min(max, x)); }
  view.addEventListener('wheel', function (e) {
    var d = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
    if ((d > 0 && target < max - 1) || (d < 0 && target > 1)) { e.preventDefault(); target = clamp(target + d * 1.1); kick(); }
  }, { passive: false });
  view.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    down = true; moved = 0; sx = lx = e.clientX; st = target; lt = performance.now(); v = 0;
  });
  view.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now(); v = (lx - e.clientX) / Math.max(1, now - lt); lx = e.clientX; lt = now;
    moved = Math.max(moved, Math.abs(e.clientX - sx));
    if (moved > 5 && !view.classList.contains('is-drag')) { view.classList.add('is-drag'); try { view.setPointerCapture(e.pointerId); } catch (x) {} }
    if (moved > 5) { target = Math.max(-80, Math.min(max + 80, st + (sx - e.clientX))); kick(); }
  });
  function up() { if (!down) return; down = false; view.classList.remove('is-drag'); target = clamp(target + v * 380); kick(); }
  view.addEventListener('pointerup', up); view.addEventListener('pointercancel', up);
  // a drag is not a click on a linked photo
  view.addEventListener('click', function (e) { if (moved > 5) { e.preventDefault(); e.stopPropagation(); } }, true);
  view.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); target = clamp(target + view.clientWidth * .6); kick(); }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); target = clamp(target - view.clientWidth * .6); kick(); }
  });
  window.addEventListener('resize', measure);
  function kick() { if (!running) { running = true; requestAnimationFrame(frame); } }
  function frame() {
    if (!down && (target < 0 || target > max)) target += ((target < 0 ? 0 : max) - target) * .15;
    var prev = pos; pos += (target - pos) * (reduce ? 1 : .085); var vel = pos - prev;
    track.style.transform = 'translate3d(' + (-pos) + 'px,0,0)';
    var vw = view.clientWidth, sk = Math.max(-1, Math.min(1, vel / 40));
    if (!reduce) items.forEach(function (it) {
      var r = it.getBoundingClientRect(), c = (r.left + r.width / 2) / vw - .5, f = it.firstElementChild, img = f.querySelector('img');
      img.style.transform = 'translate3d(' + (-c * 8) + '%,0,0)';
      f.style.transform = 'scale(' + (1 - Math.abs(sk) * .03) + ') skewX(' + (-sk * 1.5) + 'deg)';
    });
    var p = max ? pos / max : 0;
    if (bar) bar.style.transform = 'scaleX(' + Math.max(.08, p) + ')';
    if (num) { var n = items.length, i = Math.min(n, Math.round(p * (n - 1)) + 1); num.textContent = (i < 10 ? '0' : '') + i + ' / ' + (n < 10 ? '0' : '') + n; }
    if (Math.abs(target - pos) > .3 || down || Math.abs(vel) > .05) requestAnimationFrame(frame); else running = false;
  }
  measure();
})();
</script>
