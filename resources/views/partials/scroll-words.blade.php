{{--
  MARKER-SCROLL-WORDS — shared renderer for the "Scroll words" section, used
  by intake.works (marketing.sections.scroll_words) and shop sites
  (public.sections._scroll_words). A fixed lead-in with a word list that
  changes as the visitor scrolls:
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
  $swAnchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
  $swCls    = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($c['custom_classes'] ?? ''))
              . (! empty($c['hide_on_mobile']) ? ' sw-hide-m' : '') . (! empty($c['hide_on_desktop']) ? ' sw-hide-d' : ''));
  $swSentence = $swPrefix . ' ' . implode(', ', $swWords) . '.';
@endphp
<style>
  .{{ $swId }} { position: relative; height: calc({{ count($swWords) }} * 55vh + 45vh); color: {{ $swText }}; }
  .{{ $swId }} .sw-pin { position: sticky; top: 0; height: 100vh; display: flex; align-items: center; justify-content: {{ $swAlign === 'center' ? 'center' : 'flex-start' }}; padding: 0 clamp(20px, 6vw, 80px); box-sizing: border-box; overflow: hidden; }
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
    .{{ $swId }} { height: auto; }
    .{{ $swId }} .sw-pin { position: static; height: auto; padding-top: 80px; padding-bottom: 80px; }
    .{{ $swId }} .sw-w { opacity: 1 !important; transform: none !important; transition: none; }
    .{{ $swId }}.sw-slide .sw-words { height: auto; }
  }
</style>
<section class="{{ $swId }} sw-{{ $swMode }} {{ $swCls }}" @if($swAnchor !== '') id="{{ $swAnchor }}" @endif aria-label="{{ $swSentence }}">
  <div class="sw-pin">
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
    var r = el.getBoundingClientRect(), span = el.offsetHeight - window.innerHeight;
    var p = span > 0 ? Math.min(1, Math.max(0, -r.top / span)) : 0;
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
