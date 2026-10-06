@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp {{-- MARKER-MKT-SECTION-BG --}}
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
  MARKER-FEATURE-TILES — tiles that open a drawer under their row, pushing the page down.
  Each tile: icon, title, body, chips (one per line; "flow" joins them with arrows), wide.
  Its drawer: d_kicker, d_heading, d_body, d_points (one per line), d_image, d_address,
  d_cta1_label/_url, d_cta2_label/_url. A tile with no drawer text simply doesn't open.
--}}
@php
    $ftId   = 'ft-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $hex    = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
    $accent = $hex($c['accent_color'] ?? null) ?: 'var(--mk-accent)';
    $tiles  = $c['tiles'] ?? [];
    if (is_string($tiles)) { $d = json_decode($tiles, true); $tiles = is_array($d) ? $d : []; }
    $tiles  = array_values(array_filter(is_array($tiles) ? $tiles : [], fn ($t) => is_array($t) && trim((string) ($t['title'] ?? '')) !== ''));
    $lines  = fn ($v) => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $v)), fn ($x) => $x !== ''));
    $safe   = fn ($u) => preg_match('#^(https?://|/|\#|mailto:|tel:)#i', trim((string) $u)) ? trim((string) $u) : '#';
    $heading = e($c['heading'] ?? '');
    $aw = trim((string) ($c['accent_words'] ?? ''));
    if ($aw !== '' && stripos($heading, e($aw)) !== false) $heading = preg_replace('/' . preg_quote(e($aw), '/') . '/i', '<span style="color:' . $accent . '">$0</span>', $heading, 1);
    $icons = [
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/>',
        'register'  => '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M3 10h18M7 21h10"/>',
        'people'    => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M16 5a3 3 0 0 1 0 6M21 20c0-2-1.5-4-4-4.6"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'pulse'     => '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
        'chart'     => '<path d="M4 20V4M4 20h16"/><path d="M7 15l4-4 3 3 5-6"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'gift'      => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13M12 8c-2-4-6-4-6-1s6 1 6 1 6 2 6-1-4-3-6 1"/>',
        'wrench'    => '<path d="M14 6a4 4 0 0 0 5 5l-9 9-3-3 9-9a4 4 0 0 1-2-2z"/>',
        'box'       => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
@endphp
<section class="{{ $padding }} {{ $bgId }} {{ $ftId }}" @if(!empty($inlineStyle ?? '')) style="{{ $inlineStyle }}" @endif>
<style>
  .{{ $ftId }} .ft-wrap { max-width: 1100px; margin: 0 auto; }
  .{{ $ftId }} .ft-head { max-width: 760px; margin-bottom: 36px; }
  .{{ $ftId }} .ft-head p { color: rgba(255,255,255,.62); font-size: 18px; line-height: 1.6; }
  .{{ $ftId }} .ft-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
  .{{ $ftId }} .ft-tile { position: relative; display: flex; flex-direction: column; align-items: flex-start; text-align: left; font: inherit; color: inherit; background: #121212; border: 1px solid rgba(255,255,255,.08); border-radius: 18px; padding: 26px 26px 24px; transition: border-color .25s ease, background .25s ease, opacity .25s ease; }
  .{{ $ftId }} .ft-tile.is-wide { grid-column: span 2; background: radial-gradient(120% 140% at 0% 0%, color-mix(in srgb, {{ $accent }} 9%, transparent), transparent 55%), #121212; }
  .{{ $ftId }} button.ft-tile { cursor: pointer; }
  .{{ $ftId }} button.ft-tile:hover { border-color: color-mix(in srgb, {{ $accent }} 40%, transparent); }
  .{{ $ftId }} .ft-top { display: flex; flex-direction: column; align-items: flex-start; gap: 22px; }
  .{{ $ftId }} .ft-ic { width: 42px; height: 42px; border-radius: 11px; background: color-mix(in srgb, {{ $accent }} 12%, transparent); color: {{ $accent }}; display: flex; align-items: center; justify-content: center; flex: none; }
  .{{ $ftId }} .ft-ic svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
  .{{ $ftId }} .ft-tile h3 { font-size: 22px; font-weight: 700; letter-spacing: -.015em; margin: 0 0 10px; padding-right: 30px; }
  .{{ $ftId }} .ft-tile p { color: rgba(255,255,255,.66); font-size: 15.5px; line-height: 1.6; margin: 0; }
  .{{ $ftId }} .ft-chips { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 18px; font-size: 13.5px; }
  .{{ $ftId }} .ft-chips span { background: rgba(255,255,255,.06); border-radius: 8px; padding: 6px 11px; color: rgba(255,255,255,.88); }
  .{{ $ftId }} .ft-chips i { font-style: normal; color: {{ $accent }}; }
  {{-- the + morphs to × with the accordion's easing --}}
  .{{ $ftId }} .ft-tg { position: absolute; top: 26px; right: 24px; width: 18px; height: 18px; color: rgba(255,255,255,.55); }
  .{{ $ftId }} .ft-tg span { position: absolute; left: 1px; top: 8px; width: 16px; height: 2px; border-radius: 2px; background: currentColor; transition: transform .25s ease; }
  .{{ $ftId }} .ft-tg span:nth-child(2) { transform: rotate(90deg); }
  .{{ $ftId }} .ft-tile.is-open { border-color: {{ $accent }}; background: color-mix(in srgb, {{ $accent }} 4%, #121212); }
  .{{ $ftId }} .ft-tile.is-open .ft-tg { color: {{ $accent }}; }
  .{{ $ftId }} .ft-tile.is-open .ft-tg span:nth-child(1) { transform: rotate(45deg); }
  .{{ $ftId }} .ft-tile.is-open .ft-tg span:nth-child(2) { transform: rotate(135deg); }
  .{{ $ftId }} .ft-grid.has-open .ft-tile:not(.is-open) { opacity: .55; }
  .{{ $ftId }} .ft-grid.has-open .ft-tile:not(.is-open):hover { opacity: .9; }
  {{-- the drawer: one element, moved under the open tile's row --}}
  .{{ $ftId }} .ft-drawer { grid-column: 1 / -1; display: grid; grid-template-rows: 0fr; transition: grid-template-rows .45s ease; }
  .{{ $ftId }} .ft-drawer.is-on { grid-template-rows: 1fr; }
  .{{ $ftId }} .ft-drawer[hidden] { display: none; }
  .{{ $ftId }} .ft-drawer > div { overflow: hidden; }
  .{{ $ftId }} .ft-din { position: relative; display: grid; grid-template-columns: 1fr 1.15fr; gap: 48px; align-items: center; padding: 36px 72px 38px 36px; border-radius: 20px; background: linear-gradient(180deg, #141414, #0f0f0f); border: 1px solid color-mix(in srgb, {{ $accent }} 22%, transparent); opacity: 0; transform: translateY(8px); transition: opacity .3s ease .12s, transform .3s ease .12s; }
  .{{ $ftId }} .ft-drawer.is-on .ft-din { opacity: 1; transform: none; }
  .{{ $ftId }} .ft-din.no-img { grid-template-columns: 1fr; max-width: none; }
  .{{ $ftId }} .ft-k { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; font-weight: 600; color: {{ $accent }}; margin-bottom: 10px; }
  .{{ $ftId }} .ft-din h4 { font-size: 30px; font-weight: 700; letter-spacing: -.025em; line-height: 1.1; margin: 0 0 12px; }
  .{{ $ftId }} .ft-din .ft-lp { color: rgba(255,255,255,.7); font-size: 16px; line-height: 1.65; margin: 0 0 20px; }
  .{{ $ftId }} .ft-din ul { list-style: none; padding: 0; margin: 0 0 24px; display: grid; gap: 10px; }
  .{{ $ftId }} .ft-din li { display: flex; gap: 10px; font-size: 15px; color: rgba(255,255,255,.88); line-height: 1.5; }
  .{{ $ftId }} .ft-din li::before { content: ""; flex: none; width: 6px; height: 6px; border-radius: 50%; background: {{ $accent }}; margin-top: 8px; }
  .{{ $ftId }} .ft-acts { display: flex; gap: 10px; flex-wrap: wrap; }
  .{{ $ftId }} .ft-acts .mk-btn--ghost { border: 1px solid rgba(255,255,255,.18); }
  .{{ $ftId }} .ft-x { position: absolute; top: 18px; right: 18px; width: 36px; height: 36px; border-radius: 50%; border: 0; background: rgba(255,255,255,.06); cursor: pointer; color: #fff; }
  .{{ $ftId }} .ft-x span { position: absolute; left: 10px; top: 17px; width: 16px; height: 2px; border-radius: 2px; background: currentColor; transform: rotate(45deg); }
  .{{ $ftId }} .ft-x span + span { transform: rotate(-45deg); }
  .{{ $ftId }} .ft-frame { padding: 0 10px 10px; border-radius: 16px; background: linear-gradient(180deg, #1a1a1a, #0f0f0f); border: .5px solid rgba(255,255,255,.1); box-shadow: 0 30px 60px -24px rgba(0,0,0,.65), 0 0 90px -30px color-mix(in srgb, {{ $accent }} 35%, transparent); }
  .{{ $ftId }} .ft-bar { display: flex; align-items: center; gap: 6px; height: 32px; }
  .{{ $ftId }} .ft-bar i { width: 9px; height: 9px; border-radius: 50%; background: rgba(255,255,255,.16); }
  .{{ $ftId }} .ft-bar b { margin: 0 auto; transform: translateX(-14px); font-weight: 400; font-size: 11px; color: var(--mk-muted); background: rgba(255,255,255,.05); border-radius: 6px; padding: 3px 14px; max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .{{ $ftId }} .ft-frame img { display: block; width: 100%; border-radius: 0 0 10px 10px; }
  .{{ $ftId }} .ft-foot { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 32px; padding-top: 22px; border-top: .5px solid var(--mk-border2); color: rgba(255,255,255,.62); font-size: 15px; }
  .{{ $ftId }} .ft-foot a { color: {{ $accent }}; font-weight: 600; }
  @media (max-width: 900px) {
    .{{ $ftId }} .ft-grid { grid-template-columns: 1fr 1fr; }
    .{{ $ftId }} .ft-din { grid-template-columns: 1fr; padding: 28px 24px; gap: 24px; }
  }
  @media (max-width: 640px) {
    .{{ $ftId }} .ft-grid { grid-template-columns: 1fr; }
    .{{ $ftId }} .ft-tile.is-wide { grid-column: auto; }
    .{{ $ftId }} .ft-top { flex-direction: row; align-items: center; gap: 14px; margin-bottom: 10px; }
    .{{ $ftId }} .ft-top h3 { margin: 0; font-size: 19px; }
    .{{ $ftId }} .ft-tile { padding: 20px; }
    .{{ $ftId }} .ft-tg { top: 32px; }
  }
  @media (prefers-reduced-motion: reduce) { .{{ $ftId }} * { transition: none !important; } }
</style>
    <div class="mk-container"><div class="ft-wrap">
        @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
        <div class="ft-head">
            @if(!empty($c['eyebrow']))<div class="mk-eyebrow" style="color:{{ $accent }}">{{ $c['eyebrow'] }}</div>@endif
            @if(!empty($c['heading']))<h2 class="mk-section-title">{!! $heading !!}</h2>@endif
            @if(!empty($c['subheading']))<p>{!! nl2br(e($c['subheading'])) !!}</p>@endif
        </div>
        @endif
        <div class="ft-grid" data-ft>
            @foreach($tiles as $i => $t)
                @php
                    $hasD = trim((string) ($t['d_heading'] ?? '')) !== '' || trim((string) ($t['d_body'] ?? '')) !== '';
                    $chips = $lines($t['chips'] ?? '');
                    $tag = $hasD ? 'button' : 'div';
                @endphp
                <{{ $tag }} @if($hasD) type="button" aria-expanded="false" aria-controls="{{ $ftId }}-drawer" data-ft-i="{{ $i }}" @endif class="ft-tile {{ ! empty($t['wide']) && ! in_array((string) $t['wide'], ['0', 'false'], true) ? 'is-wide' : '' }}">
                    @if($hasD)<span class="ft-tg" aria-hidden="true"><span></span><span></span></span>@endif
                    <div class="ft-top">
                        <div class="ft-ic" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $icons[$t['icon'] ?? ''] ?? $icons['pulse'] !!}</svg></div>
                        <h3 class="ft-mob">{{ $t['title'] }}</h3>
                    </div>
                    @if(trim((string) ($t['body'] ?? '')) !== '')<p>{{ $t['body'] }}</p>@endif
                    @if($chips)
                        <div class="ft-chips">@foreach($chips as $k => $ch)@if($k > 0 && ! empty($t['flow']) && ! in_array((string) $t['flow'], ['0', 'false'], true))<i>→</i>@endif<span>{{ $ch }}</span>@endforeach</div>
                    @endif
                </{{ $tag }}>
                @if($hasD)
                    <template data-ft-d="{{ $i }}">
                        <div class="ft-din{{ empty($t['d_image']) ? ' no-img' : '' }}">
                            <button type="button" class="ft-x" aria-label="Close" data-ft-close><span></span><span></span></button>
                            <div>
                                @if(!empty($t['d_kicker']))<div class="ft-k">{{ $t['d_kicker'] }}</div>@endif
                                @if(!empty($t['d_heading']))<h4>{{ $t['d_heading'] }}</h4>@endif
                                @if(!empty($t['d_body']))<p class="ft-lp">{{ $t['d_body'] }}</p>@endif
                                @php $pts = $lines($t['d_points'] ?? ''); @endphp
                                @if($pts)<ul>@foreach($pts as $pt)<li>{{ $pt }}</li>@endforeach</ul>@endif
                                <div class="ft-acts">
                                    @if(!empty($t['d_cta1_label']))<a class="mk-btn mk-btn--primary" href="{{ $safe($t['d_cta1_url'] ?? '#') }}">{{ $t['d_cta1_label'] }}</a>@endif
                                    @if(!empty($t['d_cta2_label']))<a class="mk-btn mk-btn--ghost" href="{{ $safe($t['d_cta2_url'] ?? '#') }}">{{ $t['d_cta2_label'] }}</a>@endif
                                </div>
                            </div>
                            @if(!empty($t['d_image']))
                                <div class="ft-frame"><div class="ft-bar" aria-hidden="true"><i></i><i></i><i></i><b>{{ $t['d_address'] ?? '' }}</b></div><img src="{{ $t['d_image'] }}" alt="{{ $t['d_image_alt'] ?? '' }}" loading="lazy"></div>
                            @endif
                        </div>
                    </template>
                @endif
            @endforeach
            <div class="ft-drawer" id="{{ $ftId }}-drawer" role="region" hidden><div></div></div>
        </div>
        @if(!empty($c['footer_text']) || !empty($c['footer_cta_label']))
            <div class="ft-foot"><span>{{ $c['footer_text'] ?? '' }}</span>@if(!empty($c['footer_cta_label']))<a href="{{ $safe($c['footer_cta_url'] ?? '#') }}">{{ $c['footer_cta_label'] }}</a>@endif</div>
        @endif
    </div></div>
</section>
<script>
  // MARKER-FEATURE-TILES — one listener for every tile grid on the page.
  (function () {
    if (window.__ftTiles) return; window.__ftTiles = true;
    function close(grid) {
      var d = grid.querySelector('.ft-drawer'); if (!d) return;
      grid.classList.remove('has-open');
      grid.querySelectorAll('.ft-tile.is-open').forEach(function (t) { t.classList.remove('is-open'); t.setAttribute('aria-expanded', 'false'); });
      d.classList.remove('is-on');
      setTimeout(function () { if (!d.classList.contains('is-on')) { d.hidden = true; d.firstElementChild.innerHTML = ''; } }, 450);
    }
    function lastInRow(grid, tile) {
      var top = tile.offsetTop, last = tile;
      grid.querySelectorAll('.ft-tile').forEach(function (t) { if (Math.abs(t.offsetTop - top) < 4 && t.compareDocumentPosition(last) & Node.DOCUMENT_POSITION_PRECEDING) last = t; });
      return last;
    }
    function open(grid, tile) {
      var i = tile.getAttribute('data-ft-i'), tpl = grid.querySelector('template[data-ft-d="' + i + '"]'), d = grid.querySelector('.ft-drawer');
      if (!tpl || !d) return;
      grid.querySelectorAll('.ft-tile.is-open').forEach(function (t) { t.classList.remove('is-open'); t.setAttribute('aria-expanded', 'false'); });
      tile.classList.add('is-open'); tile.setAttribute('aria-expanded', 'true'); grid.classList.add('has-open');
      d.classList.remove('is-on');
      d.firstElementChild.innerHTML = ''; d.firstElementChild.appendChild(tpl.content.cloneNode(true));
      lastInRow(grid, tile).after(d); d.hidden = false; void d.offsetWidth; d.classList.add('is-on');
      setTimeout(function () { var r = d.getBoundingClientRect(); if (r.bottom > innerHeight) window.scrollBy({ top: Math.min(r.bottom - innerHeight + 24, r.top - 90), behavior: 'smooth' }); }, 480);
    }
    document.addEventListener('click', function (e) {
      var x = e.target.closest && e.target.closest('[data-ft-close]');
      if (x) { close(x.closest('[data-ft]')); return; }
      var t = e.target.closest && e.target.closest('[data-ft] button.ft-tile'); if (!t) return;
      var grid = t.closest('[data-ft]');
      if (t.classList.contains('is-open')) close(grid); else open(grid, t);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('[data-ft].has-open').forEach(close); });
    window.addEventListener('resize', function () { document.querySelectorAll('[data-ft].has-open').forEach(function (g) { var t = g.querySelector('.ft-tile.is-open'), d = g.querySelector('.ft-drawer'); if (t && d) lastInRow(g, t).after(d); }); });
  })();
</script>
