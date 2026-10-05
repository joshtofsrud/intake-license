{{--
  MARKER-SCROLL-WORDS — shared renderer for the "Scroll words" section, used
  by intake.works (marketing.sections.scroll_words) and shop sites
  (public.sections._scroll_words). A lead-in with a word list that changes
  as the section scrolls through the screen (normal height, no pinning):
    fade      — words appear in turn and stay
    spotlight — only the current word is lit
    slide     — one word at a time, sliding up
  Screen readers get the whole sentence. Reduced motion: every word shown,
  nothing pinned or animated.
--}}
@php
  $swWords = preg_split('/\r\n|\r|\n/', (string) ($c['words'] ?? ''));
  $swWords = array_values(array_filter(array_map('trim', $swWords), fn ($w) => $w !== ''));
  if (! $swWords) $swWords = ['booking', 'service', 'retail', 'rentals', 'marketing'];
  $swWords = array_slice($swWords, 0, 12);
  $swPrefix = trim((string) ($c['prefix'] ?? 'One system for'));
  $swMode   = in_array($c['mode'] ?? 'spotlight', ['fade', 'spotlight', 'slide'], true) ? ($c['mode'] ?? 'spotlight') : 'spotlight';
  $swAlign  = ($c['align'] ?? 'left') === 'center' ? 'center' : 'left';
  $swSize   = ['m' => 'clamp(30px,4.5vw,48px)', 'l' => 'clamp(36px,6vw,68px)', 'xl' => 'clamp(42px,8vw,96px)'][$c['size'] ?? 'l'] ?? 'clamp(36px,6vw,68px)';
  $swOk     = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
  $swText   = $swOk($c['text_color'] ?? null) ?: 'currentColor';
  $swAccent = $swOk($c['accent_color'] ?? null) ?: 'var(--mk-accent, var(--p-accent, #BEF264))';
  $swId     = 'sw' . substr(md5((string) ($section->id ?? uniqid())), 0, 8);
  // MARKER-SW-SCROLLFX — 0 = off
  $swFxP = max(0, min(100, (int) ($c['scroll_parallax'] ?? 0)));
  $swFxF = max(0, min(100, (int) ($c['scroll_fade'] ?? 0)));
  $swFxB = max(0, min(20,  (int) ($c['scroll_blur'] ?? 0)));
  $swFxOn = $swFxP || $swFxF || $swFxB;
  $swAnchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
  $swCls    = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($c['custom_classes'] ?? ''))
              . (empty($mkBg) && ! empty($c['hide_on_mobile']) ? ' sw-hide-m' : '') . (empty($mkBg) && ! empty($c['hide_on_desktop']) ? ' sw-hide-d' : '')); // MARKER-MKT-HIDE-TABLET — intake.works wrapper hides
  $swSentence = $swPrefix . ' ' . implode(', ', $swWords) . '.';
  // MARKER-SCROLL-WORDS-BG — shop sites: draw the colour or gradient here.
  // intake.works uses its shared background renderer (blend, fade, continue).
  $swBg = '';
  if (empty($mkBg)) {
      $swHex = fn ($v, $d) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : $d;
      if (($c['bg_mode'] ?? '') === 'gradient') {
          $swBg = 'background:linear-gradient(' . (int) ($c['bg_gradient_angle'] ?? 135) . 'deg,'
              . $swHex($c['bg_gradient_from'] ?? null, '#0a0f1a') . ',' . $swHex($c['bg_gradient_to'] ?? null, '#0f1828') . ');';
      } elseif (($c['bg_mode'] ?? '') === 'color') {
          $swBg = 'background:' . $swHex($c['bg_color'] ?? ($section->bg_color ?? null), 'transparent') . ';';
      }
  }
@endphp
@if(!empty($mkBg))
  @include('marketing.sections._section_bg', ['bgId' => $swId])
@endif
<style>
  /* MARKER-SCROLL-WORDS-V2 — a normal-height section: the words change while
     it crosses the screen, instead of pinning it over a very tall scroll. */
  .{{ $swId }} { position: relative; color: {{ $swText }}; padding: clamp(48px, 7vw, 96px) 0; }
  .{{ $swId }} .sw-pin { display: flex; align-items: center; justify-content: {{ $swAlign === 'center' ? 'center' : 'flex-start' }}; padding: 0 clamp(20px, 6vw, 80px); box-sizing: border-box; overflow: hidden; }
  .{{ $swId }} .sw-line { display: flex; flex-wrap: wrap; align-items: baseline; gap: .3em; font-size: {{ $swSize }}; font-weight: 800; letter-spacing: -.03em; line-height: 1.05; max-width: 1180px; width: 100%; {{ $swAlign === 'center' ? 'justify-content:center;text-align:center;' : '' }} }
  .{{ $swId }} .sw-prefix { opacity: .55; }
  .{{ $swId }} .sw-words { position: relative; display: inline-flex; flex-direction: column; }
  .{{ $swId }} .sw-w { color: {{ $swAccent }}; transition: opacity .35s ease, transform .45s ease; }
  /* fade: appear in turn and stay */
  .{{ $swId }}.sw-fade .sw-w { opacity: .12; }
  .{{ $swId }}.sw-fade .sw-w.on, .{{ $swId }}.sw-fade .sw-w.past { opacity: 1; }
  /* spotlight: only the current word */
  .{{ $swId }}.sw-spotlight .sw-w { opacity: .14; }
  .{{ $swId }}.sw-spotlight .sw-w.on { opacity: 1; }
  /* slide: one word in a window */
  .{{ $swId }}.sw-slide .sw-words { height: 1.1em; overflow: hidden; }
  .{{ $swId }}.sw-slide .sw-w { height: 1.1em; transform: translateY(calc(var(--sw-i, 0) * -1.1em)); }
  @media (max-width: 768px) { .sw-hide-m { display: none !important; } }
  @media (min-width: 769px) { .sw-hide-d { display: none !important; } }
  @media (prefers-reduced-motion: reduce) {
    .{{ $swId }} .sw-w { opacity: 1 !important; transform: none !important; transition: none; }
    .{{ $swId }}.sw-slide .sw-words { height: auto; }
  }
</style>
<section class="{{ $swId }} sw-{{ $swMode }} {{ $swCls }}" @if($swAnchor !== '') id="{{ $swAnchor }}" @endif @if($swBg !== '' || ! empty($inlineStyle)) style="{{ $swBg }}{{ $inlineStyle ?? '' }}" @endif aria-label="{{ $swSentence }}">
  <div class="sw-pin" @if($swFxOn) data-swfx="{{ $swFxP }},{{ $swFxF }},{{ $swFxB }}" @endif>
    <div class="sw-line" aria-hidden="true">
      @if($swPrefix !== '')<span class="sw-prefix">{{ $swPrefix }}</span>@endif
      <span class="sw-words">
        @foreach($swWords as $swI => $swW)
          <span class="sw-w{{ $swI === 0 ? ' on' : '' }}">{{ $swW }}</span>
        @endforeach
      </span>
    </div>
  </div>
</section>
<script>
(function () {
  var el = document.querySelector('.{{ $swId }}');
  if (!el || el.dataset.swReady) return;
  el.dataset.swReady = '1';
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var words = el.querySelectorAll('.sw-w'), n = words.length, last = -1;
  function tick() {
    // 0 as the section's centre enters the lower part of the screen, 1 as it
    // nears the top — the words run through while it's comfortably in view.
    var r = el.getBoundingClientRect(), vh = window.innerHeight || 1;
    var centre = r.top + r.height / 2;
    var p = Math.min(1, Math.max(0, (vh * 0.8 - centre) / (vh * 0.6)));
    var i = Math.min(n - 1, Math.floor(p * n));
    if (i === last) return;
    last = i;
    el.style.setProperty('--sw-i', i);
    for (var k = 0; k < n; k++) {
      words[k].classList.toggle('on', k === i);
      words[k].classList.toggle('past', k < i);
    }
  }
  window.addEventListener('scroll', tick, { passive: true });
  window.addEventListener('resize', tick);
  tick();
})();
</script>
@if($swFxOn)
<script>
/* MARKER-SW-SCROLLFX — words drift, fade and blur as the section leaves the top */
(function () {
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var fx = document.querySelector('.{{ $swId }} [data-swfx]');
  if (!fx || fx.dataset.swfxReady) return;
  fx.dataset.swfxReady = '1';
  var v = fx.dataset.swfx.split(',').map(Number), P = v[0] / 100, F = v[1] / 100, B = v[2];
  var sec = fx.closest('section'), raf = 0;
  fx.style.willChange = 'transform, opacity, filter';
  function paint() {
    raf = 0;
    var r = sec.getBoundingClientRect(), gone = Math.max(0, -r.top), h = Math.max(1, r.height);
    var t = Math.min(1, gone / h);
    fx.style.transform = P ? 'translate3d(0,' + (gone * P * 0.6).toFixed(1) + 'px,0)' : '';
    fx.style.opacity = F ? String(Math.max(0, 1 - t * F * 1.6)) : '';
    fx.style.filter = B ? 'blur(' + (t * B).toFixed(2) + 'px)' : '';
  }
  function onScroll() { if (!raf) raf = requestAnimationFrame(paint); }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  paint();
})();
</script>
@endif
