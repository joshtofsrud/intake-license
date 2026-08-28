{{-- MARKER-CAROUSEL-SECTION — image_carousel public render. Scroll-snap
     track, no dependencies. Arrows/dots/autoplay per section settings;
     autoplay pauses on hover/touch and respects prefers-reduced-motion. --}}
@php
  $images = $c['images'] ?? [];
  if (is_string($images)) { $dd = json_decode($images, true); $images = is_array($dd) ? $dd : []; }
  if (!is_array($images)) $images = [];
  $images = array_values(array_filter(array_map(function ($img) {
      if (is_string($img)) return ['url' => $img, 'caption' => '', 'alt' => '', 'link' => ''];
      return [
        'url'     => $img['url'] ?? '',
        'caption' => $img['caption'] ?? '',
        'alt'     => $img['alt'] ?? '',
        'link'    => $img['link'] ?? '',
      ];
  }, $images), fn ($i) => ($i['url'] ?? '') !== ''));

  $spv    = max(1, min(3, (int)($c['slides_per_view'] ?? 1)));
  $aspect = ['wide'=>'16 / 9','landscape'=>'4 / 3','square'=>'1 / 1'][$c['aspect_ratio'] ?? 'wide'] ?? '16 / 9';
  $gapPx  = ['tight'=>6,'normal'=>12,'loose'=>20][$c['gap'] ?? 'normal'] ?? 12;
  $radius = ($c['radius'] ?? 'default') === 'default'
              ? 'var(--p-r)'
              : (['none'=>'0','sm'=>'6px','md'=>'12px','lg'=>'20px'][$c['radius'] ?? ''] ?? 'var(--p-r)');
  $truthy       = fn ($v) => $v === true || $v === 1 || $v === '1';
  $showArrows   = $truthy($c['show_arrows'] ?? '1');
  $showDots     = $truthy($c['show_dots'] ?? '1');
  $loop         = $truthy($c['loop'] ?? '1');
  $autoplay     = $truthy($c['autoplay'] ?? '0');
  $apSeconds    = max(2, min(30, (int)($c['autoplay_seconds'] ?? 5)));
  $showCaptions = $truthy($c['show_captions'] ?? false);
  $heading      = trim($c['heading'] ?? '');
  $sub          = trim($c['subheading'] ?? '');
  $uid          = 'crsl' . substr(md5(($section->id ?? '') . 'carousel'), 0, 6);
  $bgMode       = $c['bg_mode']  ?? 'none';
  $bgColor      = $c['bg_color'] ?? '';
  $hideMobile   = !empty($c['hide_on_mobile']);
  $hideDesktop  = !empty($c['hide_on_desktop']);
  $customClass  = trim($c['custom_classes'] ?? '');
@endphp

<style>
.{{ $uid }}-sec { @if($bgMode === 'color' && $bgColor !== '') background: {{ $bgColor }}; padding-top:48px; padding-bottom:48px; @endif }
.{{ $uid }}-wrap { position:relative; }
.{{ $uid }}-track { display:flex; gap:{{ $gapPx }}px; overflow-x:auto; scroll-snap-type:x mandatory; scroll-behavior:smooth; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
.{{ $uid }}-track::-webkit-scrollbar { display:none; }
.{{ $uid }}-slide { position:relative; flex:0 0 calc((100% - {{ ($spv - 1) * $gapPx }}px) / {{ $spv }}); scroll-snap-align:start; aspect-ratio: {{ $aspect }}; overflow:hidden; border-radius: {{ $radius }}; background: rgba(0,0,0,.06); }
.{{ $uid }}-slide img { width:100%; height:100%; object-fit:cover; display:block; }
.{{ $uid }}-slide a { display:block; width:100%; height:100%; }
.{{ $uid }}-cap { position:absolute; left:0; right:0; bottom:0; padding:24px 14px 10px; font-size:13px; color:#fff; background:linear-gradient(transparent, rgba(0,0,0,.55)); pointer-events:none; }
.{{ $uid }}-arrow { position:absolute; top:50%; transform:translateY(-50%); width:38px; height:38px; border-radius:50%; border:none; cursor:pointer; background:rgba(0,0,0,.45); color:#fff; font-size:18px; line-height:1; display:flex; align-items:center; justify-content:center; z-index:2; transition:background .15s; }
.{{ $uid }}-arrow:hover { background:rgba(0,0,0,.7); }
.{{ $uid }}-prev { left:10px; }
.{{ $uid }}-next { right:10px; }
.{{ $uid }}-dots { display:flex; justify-content:center; gap:7px; margin-top:14px; }
.{{ $uid }}-dot { width:8px; height:8px; border-radius:50%; border:none; padding:0; cursor:pointer; background:currentColor; opacity:.25; transition:opacity .15s; }
.{{ $uid }}-dot.on { opacity:.9; }
@media (max-width:768px){ .{{ $uid }}-slide { flex-basis:100%; } }
@if($hideMobile)
@media (max-width:768px){ .{{ $uid }}-sec { display:none; } }
@endif
@if($hideDesktop)
@media (min-width:769px){ .{{ $uid }}-sec { display:none; } }
@endif
</style>

<section class="p-section--tight {{ $uid }}-sec {{ $customClass }}"@if(!empty($c['anchor_id'])) id="{{ $c['anchor_id'] }}"@endif>
  <div class="p-container">
    @if($heading !== '' || $sub !== '')
      <div style="margin-bottom:18px">
        @if($heading !== '')<h2 style="font-size:26px;font-weight:700;line-height:1.15;margin:0 0 6px">{{ $heading }}</h2>@endif
        @if($sub !== '')<p style="opacity:.7;margin:0;max-width:60ch">{{ $sub }}</p>@endif
      </div>
    @endif

    @if(!empty($images))
      <div class="{{ $uid }}-wrap" id="{{ $uid }}"
           data-loop="{{ $loop ? 1 : 0 }}"
           data-autoplay="{{ $autoplay ? 1 : 0 }}"
           data-interval="{{ $apSeconds * 1000 }}">
        <div class="{{ $uid }}-track" data-crsl-track tabindex="0" aria-roledescription="carousel" aria-label="{{ $heading !== '' ? $heading : 'Image carousel' }}">
          @foreach($images as $img)
            <div class="{{ $uid }}-slide" data-crsl-slide>
              @if($img['link'] !== '')
                <a href="{{ $img['link'] }}"><img src="{{ $img['url'] }}" alt="{{ $img['alt'] }}" loading="lazy" draggable="false"></a>
              @else
                <img src="{{ $img['url'] }}" alt="{{ $img['alt'] }}" loading="lazy" draggable="false">
              @endif
              @if($showCaptions && $img['caption'] !== '')
                <div class="{{ $uid }}-cap">{{ $img['caption'] }}</div>
              @endif
            </div>
          @endforeach
        </div>
        @if($showArrows && count($images) > $spv)
          <button type="button" class="{{ $uid }}-arrow {{ $uid }}-prev" data-crsl-prev aria-label="Previous slide">&#8249;</button>
          <button type="button" class="{{ $uid }}-arrow {{ $uid }}-next" data-crsl-next aria-label="Next slide">&#8250;</button>
        @endif
        @if($showDots && count($images) > $spv)
          <div class="{{ $uid }}-dots" data-crsl-dots>
            @foreach($images as $i => $img)
              <button type="button" class="{{ $uid }}-dot {{ $i === 0 ? 'on' : '' }}" data-crsl-dot="{{ $i }}" aria-label="Go to slide {{ $i + 1 }}"></button>
            @endforeach
          </div>
        @endif
      </div>

      <script>
      (function () {
        var root = document.getElementById('{{ $uid }}');
        if (!root) return;
        var track  = root.querySelector('[data-crsl-track]');
        var slides = Array.prototype.slice.call(root.querySelectorAll('[data-crsl-slide]'));
        if (!track || slides.length === 0) return;
        var dots   = Array.prototype.slice.call(root.querySelectorAll('[data-crsl-dot]'));
        var doLoop = root.getAttribute('data-autoplay') === '1' || root.getAttribute('data-loop') === '1';
        var wantAuto = root.getAttribute('data-autoplay') === '1';
        var interval = parseInt(root.getAttribute('data-interval'), 10) || 5000;
        var timer = null, paused = false;

        function slideStep() {
          if (slides.length < 2) return track.clientWidth;
          return slides[1].offsetLeft - slides[0].offsetLeft;
        }
        function activeIndex() {
          var step = slideStep();
          return step > 0 ? Math.round(track.scrollLeft / step) : 0;
        }
        function atEnd() {
          return track.scrollLeft >= track.scrollWidth - track.clientWidth - 4;
        }
        function goTo(i) {
          var step = slideStep();
          track.scrollTo({ left: Math.max(0, i) * step, behavior: 'smooth' });
        }
        function move(dir) {
          if (dir > 0 && atEnd()) {
            if (root.getAttribute('data-loop') === '1') goTo(0);
            return;
          }
          if (dir < 0 && track.scrollLeft <= 4) {
            if (root.getAttribute('data-loop') === '1') goTo(slides.length - 1);
            return;
          }
          goTo(activeIndex() + dir);
        }
        function syncDots() {
          if (!dots.length) return;
          var idx = Math.min(activeIndex(), dots.length - 1);
          dots.forEach(function (d, i) { d.classList.toggle('on', i === idx); });
        }

        var prev = root.querySelector('[data-crsl-prev]');
        var next = root.querySelector('[data-crsl-next]');
        if (prev) prev.addEventListener('click', function () { move(-1); });
        if (next) next.addEventListener('click', function () { move(1); });
        dots.forEach(function (d) {
          d.addEventListener('click', function () { goTo(parseInt(d.getAttribute('data-crsl-dot'), 10) || 0); });
        });
        track.addEventListener('keydown', function (e) {
          if (e.key === 'ArrowLeft')  { e.preventDefault(); move(-1); }
          if (e.key === 'ArrowRight') { e.preventDefault(); move(1); }
        });

        var raf = null;
        track.addEventListener('scroll', function () {
          if (raf) return;
          raf = requestAnimationFrame(function () { raf = null; syncDots(); });
        }, { passive: true });

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (wantAuto && !reduced && slides.length > 1) {
          var tick = function () {
            if (paused || document.hidden) return;
            if (atEnd()) { goTo(0); } else { move(1); }
          };
          timer = setInterval(tick, interval);
          ['pointerenter', 'touchstart', 'focusin'].forEach(function (ev) {
            root.addEventListener(ev, function () { paused = true; }, { passive: true });
          });
          ['pointerleave', 'focusout'].forEach(function (ev) {
            root.addEventListener(ev, function () { paused = false; });
          });
          window.addEventListener('beforeunload', function () { if (timer) clearInterval(timer); });
        }

        syncDots();
      })();
      </script>
    @else
      <div style="aspect-ratio:{{ $aspect }};border-radius:{{ $radius }};background:rgba(0,0,0,.06);display:flex;align-items:center;justify-content:center;font-size:13px;opacity:.25">Carousel</div>
    @endif
  </div>
</section>
