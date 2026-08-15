{{-- MARKER-SPLASH — overlay rendering of the splash page's own sections.

     The homepage is already in the DOM underneath this; that is the whole
     point of overlay mode. Everything here is inert to crawlers (they do not
     run the dismiss script and they have already read the real page).

     Accessibility: the dismiss control is a real <button>, focus moves to it
     on load, Esc closes, and the overlay is aria-modal so a screen reader
     does not wander into the page behind it. --}}
@php
  $spStyle = $splashCfg['style'] ?? 'full';
  $spFreq  = $splashCfg['frequency'] ?? 'session';
@endphp

<div id="p-splash" class="p-splash p-splash--{{ $spStyle }}" role="dialog" aria-modal="true"
     aria-label="Welcome">
  <div class="p-splash-inner">
    @foreach($splashSections as $section)
      @php $partial = 'public.sections._' . $section->section_type; @endphp
      @if(view()->exists($partial))
        @php
          $sc = $section->content ?? [];
          $sc['bg_color'] = \App\Support\DesignTokens::sectionBg(
              $sc['bg_color'] ?? null, $section->section_type, $dt
          );
        @endphp
        @include($partial, [
          'c'        => $sc,
          'section'  => $section,
          'navItems' => $navItems,
          'catalog'  => $catalog,
          'tenant'   => $currentTenant,
        ])
      @endif
    @endforeach

    <div class="p-splash-actions">
      <button type="button" id="p-splash-enter" class="p-btn p-btn--primary">Enter site</button>
    </div>
  </div>
</div>

<style>
  .p-splash{
    position:fixed;inset:0;z-index:9000;overflow-y:auto;
    background:var(--p-bg,#111);
    display:flex;flex-direction:column;
  }
  .p-splash--full .p-splash-inner{margin:auto;width:100%}
  .p-splash--sheet{background:rgba(0,0,0,.55);justify-content:flex-end}
  .p-splash--sheet .p-splash-inner{
    background:var(--p-bg,#111);border-radius:18px 18px 0 0;
    max-height:86vh;overflow-y:auto;box-shadow:0 -12px 40px rgba(0,0,0,.35)
  }
  .p-splash-actions{display:flex;justify-content:center;padding:22px 20px 30px}
  .p-splash-actions .p-btn{min-width:180px;justify-content:center}
  /* No JS, no overlay: the homepage underneath is the honest fallback. */
  .p-splash{display:none}
</style>
<script>
(function () {
  var el = document.getElementById('p-splash');
  if (!el) return;
  el.style.display = 'flex';                 // only ever shown with JS available
  document.body.style.overflow = 'hidden';

  var FREQ = @json($spFreq);

  function remember() {
    if (FREQ === 'always') return;           // deliberately never remembered
    var days = FREQ === '7' ? 7 : (FREQ === '30' ? 30 : 0);
    var bits = '{{ \App\Support\SplashSettings::COOKIE }}=1; path=/; samesite=lax';
    if (days) bits += '; max-age=' + (days * 86400);
    if (location.protocol === 'https:') bits += '; secure';
    document.cookie = bits;
  }

  function dismiss() {
    remember();
    el.style.display = 'none';
    document.body.style.overflow = '';
    var h = document.querySelector('h1, [role=main], main');
    if (h && h.focus) { h.setAttribute('tabindex', '-1'); h.focus(); }
  }

  document.getElementById('p-splash-enter').addEventListener('click', dismiss);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dismiss(); });

  // Any link inside the splash (Book now, Shop) should also count as entering,
  // or the visitor gets the splash again the moment they arrive.
  el.querySelectorAll('a[href]').forEach(function (a) { a.addEventListener('click', remember); });

  document.getElementById('p-splash-enter').focus();
})();
</script>
