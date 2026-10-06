@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp {{-- MARKER-MKT-SECTION-BG --}}
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
  Text + image. MARKER-TI-ACCORDION — two styles:
    classic   heading, body, one image, up to three buttons (today's block)
    accordion several items; opening one fades its text in and crossfades the image
  Both now honour the editor's eyebrow, highlight phrase, buttons, image ratio/aspect/corners,
  content width, alignment and accent colour (they were ignored before), and the body text
  uses --mk-muted (it pointed at --mk-text-muted, which doesn't exist).
--}}
@php
    $tiId     = 'ti-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $style    = ($c['ti_style'] ?? 'classic') === 'accordion' ? 'accordion' : 'classic';
    $imgRight = ($c['image_position'] ?? 'right') === 'right';
    $cols     = ['equal' => '1fr 1fr', 'wide_text' => '6fr 4fr', 'wide_image' => '4fr 6fr'][$c['image_ratio'] ?? 'equal'] ?? '1fr 1fr';
    $aspect   = in_array($c['image_aspect'] ?? '4/3', ['4/3', '1/1', '3/4', '16/9', 'auto'], true) ? ($c['image_aspect'] ?? '4/3') : '4/3';
    $radius   = ['none' => '0', 'small' => '4px', 'medium' => '8px', 'large' => '16px', 'full' => '24px'][$c['image_radius'] ?? 'medium'] ?? '8px';
    $maxW     = max(480, min(1600, (int) ($c['content_max_width'] ?? 1100)));
    $align    = in_array($c['text_align'] ?? 'left', ['left', 'center', 'right'], true) ? ($c['text_align'] ?? 'left') : 'left';
    $hex      = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
    $accent   = $hex($c['accent_color'] ?? null) ?: 'var(--mk-accent)';

    $heading = e($c['heading'] ?? '');
    $aw = trim((string) ($c['accent_words'] ?? ''));
    if ($aw !== '' && stripos($heading, e($aw)) !== false) {
        $heading = preg_replace('/' . preg_quote(e($aw), '/') . '/i', '<span class="ti-accent">$0</span>', $heading, 1);
    }

    $buttons = $c['buttons'] ?? [];
    if (is_string($buttons)) { $d = json_decode($buttons, true); $buttons = is_array($d) ? $d : []; }
    if (! is_array($buttons)) $buttons = [];
    if (! $buttons && ! empty($c['cta_label'])) $buttons = [['label' => $c['cta_label'], 'url' => $c['cta_url'] ?? '#', 'style' => 'primary']];
    $buttons = array_slice(array_values(array_filter($buttons, fn ($b) => trim((string) ($b['label'] ?? '')) !== '')), 0, 3);
    $btnClass = fn ($s) => match ($s) { 'outline', 'ghost' => 'mk-btn mk-btn--ghost', 'link' => 'ti-link', default => 'mk-btn mk-btn--primary' };
    $safeUrl  = fn ($u) => preg_match('#^(https?://|/|\#|mailto:|tel:)#i', trim((string) $u)) ? trim((string) $u) : '#';

    $items = $c['acc_items'] ?? [];
    if (is_string($items)) { $d = json_decode($items, true); $items = is_array($d) ? $d : []; }
    if (! is_array($items)) $items = [];
    $items = array_values(array_filter($items, fn ($it) => is_array($it) && (trim((string) ($it['title'] ?? '')) !== '' || trim((string) ($it['body'] ?? '')) !== '')));
    $flag     = fn ($k, $d) => array_key_exists($k, $c) ? ! in_array((string) $c[$k], ['', '0', 'false'], true) : $d;
    $firstOpen = $flag('acc_first_open', true);
    $multi    = $flag('acc_multi', false);
    $auto     = $flag('acc_auto', false) && ! $multi;
    $secs     = max(3, min(20, (int) ($c['acc_auto_secs'] ?? 6)));
    $icon     = ($c['acc_icon'] ?? 'plus') === 'arrow' ? 'arrow' : 'plus';
    $numbers  = ($c['acc_numbers'] ?? 'show') !== 'hide';
    $lightbox = $flag('img_lightbox', false); // MARKER-TI-LIGHTBOX
@endphp
<section class="{{ $padding }} {{ $bgId }} {{ $tiId }}" @if($lightbox) data-ti-lb @endif @if(!empty($inlineStyle ?? '')) style="{{ $inlineStyle }}" @endif>
<style>
  .{{ $tiId }} .ti-wrap { max-width: {{ $maxW }}px; margin: 0 auto; }
  .{{ $tiId }} .ti-accent { color: {{ $accent }}; font-style: {{ ($c['accent_italic'] ?? true) && ! in_array((string) ($c['accent_italic'] ?? '1'), ['0', 'false'], true) ? 'italic' : 'normal' }}; }
  .{{ $tiId }} .ti-text { text-align: {{ $align }}; }
  .{{ $tiId }} .ti-body { font-size: 16px; line-height: 1.7; color: var(--mk-muted); }
  .{{ $tiId }} .ti-btns { margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: {{ ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$align] }}; }
  .{{ $tiId }} .ti-link { color: {{ $accent }}; font-weight: 600; font-size: 14px; align-self: center; }
  .{{ $tiId }} .ti-img { border-radius: {{ $radius }}; overflow: hidden; background: var(--mk-bg2); box-shadow: 0 20px 40px -12px rgba(0,0,0,.35); @if($aspect !== 'auto') aspect-ratio: {{ $aspect }}; @endif }
  .{{ $tiId }} .ti-img img { width: 100%; @if($aspect !== 'auto') height: 100%; object-fit: cover; @endif }
  @if($lightbox) .{{ $tiId }} .ti-img img { cursor: zoom-in; } @endif
  .{{ $tiId }} .ti-ph { aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; color: var(--mk-dim); font-size: 14px; }
  .{{ $tiId }} .ti-grid { display: grid; grid-template-columns: {{ $cols }}; gap: clamp(28px, 5vw, 64px); align-items: {{ $style === 'accordion' ? 'start' : 'center' }}; }
  .{{ $tiId }} .ti-grid > .ti-side-img { order: {{ $imgRight ? 2 : 1 }}; }
  .{{ $tiId }} .ti-grid > .ti-side-text { order: {{ $imgRight ? 1 : 2 }}; }
  @media (max-width: 760px) {
    .{{ $tiId }} .ti-grid { grid-template-columns: 1fr; }
    .{{ $tiId }} .ti-grid > .ti-side-img { order: 2; }
  }
@if($style === 'accordion')
  .{{ $tiId }} .ti-intro { margin-bottom: 36px; }
  .{{ $tiId }} .ti-list { border-top: .5px solid var(--mk-border2); text-align: left; }
  .{{ $tiId }} .ti-item { border-bottom: .5px solid var(--mk-border2); position: relative; }
  .{{ $tiId }} .ti-item::before { content: ""; position: absolute; left: 0; top: -1px; height: 1.5px; width: 0; background: {{ $accent }}; }
  .{{ $tiId }} .ti-item.is-open::before { width: 100%; transition: width .45s ease; }
  .{{ $tiId }}.ti-auto .ti-item.is-open::before { width: 0; transition: none; }
  .{{ $tiId }}.ti-auto .ti-item.is-open.is-run::before { width: 100%; transition: width {{ $secs }}s linear; }
  .{{ $tiId }} .ti-head { display: flex; align-items: center; gap: 16px; width: 100%; background: none; border: 0; color: inherit; text-align: left; padding: 22px 0; font-size: clamp(17px, 1.9vw, 21px); font-weight: 600; letter-spacing: -.01em; font-family: inherit; }
  .{{ $tiId }} .ti-num { font-size: 12px; color: var(--mk-dim); font-weight: 500; font-variant-numeric: tabular-nums; width: 22px; flex: none; }
  .{{ $tiId }} .ti-t { flex: 1; transition: opacity .2s; }
  .{{ $tiId }} .ti-item:not(.is-open) .ti-t { opacity: .62; }
  .{{ $tiId }} .ti-item:not(.is-open) .ti-head:hover .ti-t { opacity: 1; }
  .{{ $tiId }} .ti-ic { position: relative; width: 18px; height: 18px; flex: none; }
  .{{ $tiId }} .ti-item.is-open .ti-ic { color: {{ $accent }}; }
  {{-- Same easing as the mobile menu's hamburger (.mk-hamburger span: transform .25s ease). --}}
  .{{ $tiId }} .ti-ic span { position: absolute; top: 8px; height: 2px; border-radius: 2px; background: currentColor; transition: transform .25s ease; }
@if($icon === 'plus')
  .{{ $tiId }} .ti-ic span { left: 1px; width: 16px; }
  .{{ $tiId }} .ti-ic span:nth-child(2) { transform: rotate(90deg); }
  .{{ $tiId }} .ti-item.is-open .ti-ic span:nth-child(1) { transform: rotate(45deg); }
  .{{ $tiId }} .ti-item.is-open .ti-ic span:nth-child(2) { transform: rotate(135deg); }
@else
  .{{ $tiId }} .ti-ic span { width: 9px; }
  .{{ $tiId }} .ti-ic span:nth-child(1) { left: 0; transform-origin: 100% 50%; transform: translateY(3px) rotate(40deg); }
  .{{ $tiId }} .ti-ic span:nth-child(2) { left: 9px; transform-origin: 0 50%; transform: translateY(3px) rotate(-40deg); }
  .{{ $tiId }} .ti-item.is-open .ti-ic span:nth-child(1) { transform: translateY(-3px) rotate(-40deg); }
  .{{ $tiId }} .ti-item.is-open .ti-ic span:nth-child(2) { transform: translateY(-3px) rotate(40deg); }
@endif
  .{{ $tiId }} .ti-panel { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .4s ease; }
  .{{ $tiId }} .ti-item.is-open .ti-panel { grid-template-rows: 1fr; }
  .{{ $tiId }} .ti-panel > div { overflow: hidden; }
  .{{ $tiId }} .ti-inner { padding: 0 0 24px {{ $numbers ? '38px' : '0' }}; opacity: 0; transform: translateY(6px); transition: opacity .3s ease, transform .3s ease; }
  .{{ $tiId }} .ti-item.is-open .ti-inner { opacity: 1; transform: none; transition-delay: .12s; }
  .{{ $tiId }} .ti-inner .ti-body { max-width: 46ch; }
  .{{ $tiId }} .ti-inner .mk-btn { margin-top: 18px; }
  .{{ $tiId }} .ti-inner .ti-img-in { display: none; }
  .{{ $tiId }} .ti-stage { position: sticky; top: 24px; }
  .{{ $tiId }} .ti-stage .ti-img { position: relative; }
  .{{ $tiId }} .ti-stage .ti-img img, .{{ $tiId }} .ti-stage .ti-img .ti-ph { position: absolute; inset: 0; opacity: 0; transform: scale(1.02); transition: opacity .45s ease, transform .6s ease; }
  .{{ $tiId }} .ti-stage .ti-img .is-on { opacity: 1; transform: none; }
  .{{ $tiId }} .ti-stage .ti-img img:not(.is-on) { pointer-events: none; } /* MARKER-TI-LIGHTBOX — hidden stacked images mustn't catch the click */
  @media (max-width: 760px) {
    .{{ $tiId }} .ti-stage { display: none; }
    .{{ $tiId }} .ti-inner { padding-left: 0; }
    .{{ $tiId }} .ti-inner .ti-img-in { display: block; margin-bottom: 16px; }
  }
  @media (prefers-reduced-motion: reduce) { .{{ $tiId }} * { transition: none !important; } }
@endif
</style>
    <div class="mk-container"><div class="ti-wrap">
@if($style === 'classic')
        <div class="ti-grid">
            <div class="ti-side-text ti-text">
                @if(!empty($c['eyebrow']))<div class="mk-eyebrow" style="color:{{ $accent }}">{{ $c['eyebrow'] }}</div>@endif
                @if(!empty($c['heading']))<h2 class="mk-section-title">{!! $heading !!}</h2>@endif
                @if(trim((string) ($c['body'] ?? '')) !== '')<p class="ti-body">{!! nl2br(e($c['body'])) !!}</p>@endif
                @if($buttons)
                    <div class="ti-btns">@foreach($buttons as $b)<a href="{{ $safeUrl($b['url'] ?? '#') }}" class="{{ $btnClass($b['style'] ?? 'primary') }}">{{ $b['label'] }}</a>@endforeach</div>
                @endif
            </div>
            <div class="ti-side-img">
                @if(!empty($c['image_url']))
                    <div class="ti-img"><img src="{{ $c['image_url'] }}" alt="{{ $c['image_alt'] ?? '' }}" loading="lazy"></div>
                @else
                    <div class="ti-img ti-ph">Image placeholder</div>
                @endif
            </div>
        </div>
@else
        @if(!empty($c['eyebrow']) || !empty($c['heading']) || trim((string) ($c['body'] ?? '')) !== '')
            <div class="ti-intro ti-text">
                @if(!empty($c['eyebrow']))<div class="mk-eyebrow" style="color:{{ $accent }}">{{ $c['eyebrow'] }}</div>@endif
                @if(!empty($c['heading']))<h2 class="mk-section-title">{!! $heading !!}</h2>@endif
                @if(trim((string) ($c['body'] ?? '')) !== '')<p class="mk-section-sub ti-body" style="margin-bottom:0;{{ $align === 'center' ? 'margin-left:auto;margin-right:auto' : ($align === 'right' ? 'margin-left:auto' : '') }}">{!! nl2br(e($c['body'])) !!}</p>@endif
            </div>
        @endif
        @if(! $items)
            <div class="ti-body">Add items to this section in the editor.</div>
        @else
        <div class="ti-grid ti-acc" data-ti-acc data-ti-multi="{{ $multi ? 1 : 0 }}" data-ti-auto="{{ $auto ? $secs : 0 }}">
            <div class="ti-side-text ti-list">
                @foreach($items as $i => $it)
                    @php $open = $firstOpen && $i === 0; $pid = $tiId . '-p' . $i; @endphp
                    <div class="ti-item {{ $open ? 'is-open' : '' }}" data-i="{{ $i }}">
                        <button type="button" class="ti-head" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $pid }}">
                            @if($numbers)<span class="ti-num">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>@endif
                            <span class="ti-t">{{ $it['title'] ?? '' }}</span>
                            <span class="ti-ic" aria-hidden="true"><span></span><span></span></span>
                        </button>
                        <div class="ti-panel" id="{{ $pid }}" role="region"><div><div class="ti-inner">
                            @if(!empty($it['image_url']))<div class="ti-img ti-img-in"><img src="{{ $it['image_url'] }}" alt="{{ $it['image_alt'] ?? '' }}" loading="lazy"></div>@endif
                            @if(trim((string) ($it['body'] ?? '')) !== '')<p class="ti-body">{!! nl2br(e($it['body'])) !!}</p>@endif
                            @if(trim((string) ($it['cta_label'] ?? '')) !== '')<a class="mk-btn mk-btn--primary mk-btn--sm" href="{{ $safeUrl($it['cta_url'] ?? '#') }}">{{ $it['cta_label'] }}</a>@endif
                        </div></div></div>
                    </div>
                @endforeach
            </div>
            <div class="ti-side-img ti-stage">
                <div class="ti-img">
                    @php $shown = false; @endphp
                    @foreach($items as $i => $it)
                        @if(!empty($it['image_url']))
                            <img src="{{ $it['image_url'] }}" alt="{{ $it['image_alt'] ?? '' }}" data-i="{{ $i }}" data-cap="{{ $it['title'] ?? '' }}" class="{{ ! $shown ? 'is-on' : '' }}" loading="lazy">
                            @php $shown = true; @endphp
                        @endif
                    @endforeach
                    @if(! $shown)<div class="ti-ph is-on">Image placeholder</div>@endif
                </div>
            </div>
        </div>
        @endif
@endif
    </div></div>
</section>
@if($style === 'accordion')
<script>
  // MARKER-TI-ACCORDION — one listener for every accordion on the page (and any the builder preview redraws).
  (function () {
    if (window.__tiAcc) { window.__tiAcc.scan(); return; }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function items(acc) { return Array.prototype.slice.call(acc.querySelectorAll('.ti-item')); }
    function show(acc, idx) {
      var imgs = acc.querySelectorAll('.ti-stage img[data-i]'); if (!imgs.length) return;
      var target = null;
      imgs.forEach(function (im) { if (+im.getAttribute('data-i') === idx) target = im; });
      if (!target) return; // no image for this item: keep the last one showing
      imgs.forEach(function (im) { im.classList.toggle('is-on', im === target); });
    }
    function setOpen(acc, idx, on) {
      var multi = acc.getAttribute('data-ti-multi') === '1';
      items(acc).forEach(function (it) {
        var i = +it.getAttribute('data-i');
        var open = i === idx ? on : (multi ? it.classList.contains('is-open') : false);
        it.classList.toggle('is-open', open); it.classList.remove('is-run');
        it.querySelector('.ti-head').setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      if (on) show(acc, idx);
    }
    function tick(acc) {
      clearTimeout(acc.__tiT);
      var secs = +acc.getAttribute('data-ti-auto');
      if (!secs || reduce || acc.__tiStop || acc.__tiPause) return;
      var list = items(acc), cur = list.findIndex(function (it) { return it.classList.contains('is-open'); });
      if (cur < 0) { setOpen(acc, 0, true); cur = 0; }
      var el = list[cur]; void el.offsetWidth; el.classList.add('is-run');
      acc.__tiT = setTimeout(function () { setOpen(acc, (cur + 1) % list.length, true); tick(acc); }, secs * 1000);
    }
    function init(acc) {
      if (acc.__tiReady) return; acc.__tiReady = true;
      var sec = acc.closest('section'); if (sec && +acc.getAttribute('data-ti-auto')) sec.classList.add('ti-auto');
      acc.addEventListener('mouseenter', function () { acc.__tiPause = true; clearTimeout(acc.__tiT); items(acc).forEach(function (it) { it.classList.remove('is-run'); }); });
      acc.addEventListener('mouseleave', function () { acc.__tiPause = false; tick(acc); });
      tick(acc);
    }
    document.addEventListener('click', function (e) {
      var h = e.target.closest && e.target.closest('[data-ti-acc] .ti-head'); if (!h) return;
      var acc = h.closest('[data-ti-acc]'), it = h.closest('.ti-item');
      acc.__tiStop = true; clearTimeout(acc.__tiT);
      setOpen(acc, +it.getAttribute('data-i'), !it.classList.contains('is-open'));
    });
    function scan() { document.querySelectorAll('[data-ti-acc]').forEach(init); }
    window.__tiAcc = { scan: scan };
    scan();
    if (window.MutationObserver) new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
  })();
</script>
@endif

@if($lightbox)
<script>
  // MARKER-TI-LIGHTBOX — one lightbox for every Text + image section that has "Click image to enlarge" on.
  (function () {
    if (window.__tiLb) return;
    var box, img, cap, prev, next, closeBtn, list = [], at = 0, lastFocus = null, x0 = null;
    function build() {
      box = document.createElement('div');
      box.className = 'ti-lb'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', 'Image');
      box.innerHTML = '<style>'
        + '.ti-lb{position:fixed;inset:0;z-index:9999;background:rgba(5,5,5,.92);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s ease}'
        + '.ti-lb.on{opacity:1;pointer-events:auto}'
        + '.ti-lb figure{margin:0;max-width:92vw;text-align:center;transform:scale(.97);transition:transform .25s ease}'
        + '.ti-lb.on figure{transform:none}'
        + '.ti-lb img{max-width:92vw;max-height:82vh;border-radius:10px;display:block;margin:0 auto;transition:opacity .2s ease}'
        + '.ti-lb figcaption{color:rgba(255,255,255,.75);font-size:14px;margin-top:14px;font-family:inherit}'
        + '.ti-lb button{position:absolute;background:rgba(255,255,255,.08);border:0;border-radius:50%;width:44px;height:44px;cursor:pointer;color:#fff}'
        + '.ti-lb button:hover{background:rgba(255,255,255,.16)}'
        + '.ti-lb .x{top:20px;right:20px}'
        + '.ti-lb .x span{position:absolute;left:13px;top:21px;width:18px;height:2px;border-radius:2px;background:#fff;transition:transform .25s ease}'
        + '.ti-lb .x span:nth-child(1){transform:translateY(-5px)}.ti-lb .x span:nth-child(2){transform:translateY(5px)}'
        + '.ti-lb.on .x span:nth-child(1){transform:rotate(45deg)}.ti-lb.on .x span:nth-child(2){transform:rotate(-45deg)}'
        + '.ti-lb .pv{left:20px;top:50%;margin-top:-22px}.ti-lb .nx{right:20px;top:50%;margin-top:-22px}'
        + '.ti-lb .pv::before,.ti-lb .nx::before{content:"";position:absolute;left:17px;top:16px;width:10px;height:10px;border-left:2px solid #fff;border-bottom:2px solid #fff;transform:rotate(45deg)}'
        + '.ti-lb .nx::before{left:14px;transform:rotate(-135deg)}'
        + '@media (prefers-reduced-motion:reduce){.ti-lb,.ti-lb *{transition:none!important}}'
        + '</style><button type="button" class="x" aria-label="Close"><span></span><span></span></button>'
        + '<button type="button" class="pv" aria-label="Previous image"></button><button type="button" class="nx" aria-label="Next image"></button>'
        + '<figure><img alt=""><figcaption></figcaption></figure>';
      document.body.appendChild(box);
      img = box.querySelector('img'); cap = box.querySelector('figcaption');
      prev = box.querySelector('.pv'); next = box.querySelector('.nx'); closeBtn = box.querySelector('.x');
      closeBtn.addEventListener('click', close);
      prev.addEventListener('click', function () { go(at - 1); });
      next.addEventListener('click', function () { go(at + 1); });
      box.addEventListener('click', function (e) { if (e.target === box) close(); });
      box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
      box.addEventListener('touchend', function (e) {
        if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null;
        if (Math.abs(dx) > 40 && list.length > 1) go(at + (dx < 0 ? 1 : -1));
      });
      document.addEventListener('keydown', function (e) {
        if (!box.classList.contains('on')) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft' && list.length > 1) go(at - 1);
        else if (e.key === 'ArrowRight' && list.length > 1) go(at + 1);
      });
    }
    function go(i) {
      at = (i + list.length) % list.length;
      img.src = list[at].src; img.alt = list[at].alt || '';
      cap.textContent = list[at].cap || ''; cap.hidden = !list[at].cap;
    }
    function open(items, i) {
      if (!box) build();
      list = items; lastFocus = document.activeElement;
      prev.hidden = next.hidden = list.length < 2;
      go(i);
      box.classList.add('on'); document.documentElement.style.overflow = 'hidden';
      closeBtn.focus();
    }
    function close() {
      box.classList.remove('on'); document.documentElement.style.overflow = '';
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-ti-lb] img'); if (!t) return;
      var sec = t.closest('[data-ti-lb]');
      var stage = sec.querySelectorAll('.ti-stage img[data-i]');
      var items, i = 0;
      if (stage.length) {
        items = Array.prototype.map.call(stage, function (im) { return { src: im.currentSrc || im.src, alt: im.alt, cap: im.getAttribute('data-cap') || '', i: +im.getAttribute('data-i') }; });
        var item = t.closest('.ti-item');
        var want = item ? +item.getAttribute('data-i') : +t.getAttribute('data-i');
        items.forEach(function (it, k) { if (it.i === want) i = k; });
      } else {
        items = [{ src: t.currentSrc || t.src, alt: t.alt, cap: t.alt || '' }];
      }
      e.preventDefault();
      open(items, i);
    });
    window.__tiLb = true;
  })();
</script>
@endif
