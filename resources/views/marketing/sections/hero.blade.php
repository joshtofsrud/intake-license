@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp {{-- MARKER-MKT-SECTION-BG --}}
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
    Hero (intake.works). MARKER-MKT-HERO-LAYOUT — reads the same editor
    settings as a shop's hero: height, vertical alignment, content width,
    headline and subheading size, text colors and the buttons list.
    Padding, hide on mobile/desktop, anchor and custom classes are applied
    to every section by the wrapper in marketing/page.blade.php.

    Size presets match the shop hero, except "auto" headline and "medium"
    subheading, which keep the intake.works look.

    accent_words: any occurrence of that phrase in the headline is wrapped
    in <em> and shown in the accent color.
--}}
@php
    $headline = $c['headline'] ?? 'Your headline here';
    $safeHeadline = e($headline);
    if (!empty($c['accent_words'])) {
        // Escape first, then wrap the escaped phrase, so no XSS hole opens.
        $safeAccent = e($c['accent_words']);
        $pos = strpos($safeHeadline, $safeAccent);
        if ($pos !== false) {
            $safeHeadline = substr($safeHeadline, 0, $pos)
                . '<em>' . $safeAccent . '</em>'
                . substr($safeHeadline, $pos + strlen($safeAccent));
        }
    }
    $safeHeadline = nl2br($safeHeadline);
    $align = in_array($c['text_align'] ?? 'center', ['left', 'center', 'right'], true) ? ($c['text_align'] ?? 'center') : 'center';

    $hid = 'mkh-' . substr(md5((string) ($section->id ?? uniqid())), 0, 8);

    $heights   = ['small' => '380px', 'medium' => '520px', 'large' => '680px', 'fullscreen' => '100vh'];
    $minHeight = $heights[$c['height'] ?? ''] ?? null;
    $vAlign    = ['top' => 'flex-start', 'center' => 'center', 'bottom' => 'flex-end'][$c['vertical_align'] ?? 'center'] ?? 'center';

    $maxW = (int) ($c['content_max_width'] ?? 720);
    if ($maxW < 320 || $maxW > 1600) $maxW = 720;
    $subW = (int) round($maxW * 0.7);

    // MARKER-MKT-HERO-PAD — editor padding presets, same scale as a shop hero.
    // A hero saved before these fields existed keeps its original padding.
    $padTokens = ['none' => '0', 'compact' => 'clamp(24px, 4vw, 40px)', 'normal' => 'clamp(48px, 7vw, 80px)', 'spacious' => 'clamp(72px, 10vw, 120px)'];
    $padTop    = $padTokens[$c['padding_top'] ?? ''] ?? 'clamp(64px, 10vw, 120px)';
    $padBottom = $padTokens[$c['padding_bottom'] ?? ''] ?? 'clamp(48px, 7vw, 88px)';

    $headSizes = ['auto' => 'clamp(36px, 6vw, 72px)', 'small' => '32px', 'medium' => '44px', 'large' => '56px', 'xl' => '72px'];
    $subSizes  = ['small' => '16px', 'medium' => 'clamp(15px, 2vw, 19px)', 'large' => '22px'];
    $headSize  = $headSizes[$c['headline_size'] ?? 'auto'] ?? $headSizes['auto'];
    $subSize   = $subSizes[$c['subheading_size'] ?? 'medium'] ?? $subSizes['medium'];

    $okColor = fn ($v) => is_string($v) && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\)|[a-zA-Z]+)$/', trim($v)) ? trim($v) : null;
    $headColor = $okColor($c['text_color'] ?? null);
    $bodyColor = $okColor($c['text_color_body'] ?? null);

    // Buttons: the buttons list wins; the editor blanks the two legacy CTA
    // fields once the list is used, so reading only those dropped buttons.
    $buttons = $c['buttons'] ?? [];
    if (is_string($buttons)) { $d = json_decode($buttons, true); $buttons = is_array($d) ? $d : []; }
    if (! is_array($buttons)) $buttons = [];
    $buttons = array_values(array_filter($buttons, fn ($b) => is_array($b) && trim((string) ($b['label'] ?? '')) !== ''));
    if (! $buttons && ! array_key_exists('buttons', $c)) { // MARKER-HERO-NO-LEGACY-CTA — only never-edited heroes
        if (! empty($c['cta_primary_label'])) {
            $buttons[] = ['label' => $c['cta_primary_label'], 'url' => $c['cta_primary_url'] ?? '#', 'style' => 'primary'];
        }
        if (! empty($c['cta_secondary_label'])) {
            $buttons[] = ['label' => $c['cta_secondary_label'], 'url' => $c['cta_secondary_url'] ?? '#', 'style' => 'outline'];
        }
    }
    $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$align];
    $heroPill = ($c['buttons_style'] ?? 'separate') === 'pill'; // MARKER-HERO-PILL
@endphp

<style>
    .mk-hero.{{ $hid }} {
        padding: {{ $padTop }} 0 {{ $padBottom }};
        text-align: {{ $align }};
        border-bottom: 0.5px solid var(--mk-border);
        @if($minHeight)
        min-height: {{ $minHeight }};
        display: flex; flex-direction: column; justify-content: {{ $vAlign }};
        @endif
    }
    .{{ $hid }} > .mk-container { width: 100%; box-sizing: border-box; }
    .{{ $hid }} h1 {
        font-size: {{ $headSize }};
        font-weight: 800;
        letter-spacing: -.03em;
        line-height: 1.04;
        margin-bottom: 20px;
        max-width: {{ $maxW }}px;
        @if($headColor) color: {{ $headColor }}; @endif
        @if($align === 'center') margin-left: auto; margin-right: auto; @elseif($align === 'right') margin-left: auto; @endif
    }
    .{{ $hid }} h1 em { font-style: normal; color: var(--mk-accent); }
    .{{ $hid }} .mk-hero-sub {
        font-size: {{ $subSize }};
        color: {{ $bodyColor ?: 'var(--mk-muted)' }};
        max-width: {{ $subW }}px;
        line-height: 1.65;
        margin: 0 0 32px;
        @if($align === 'center') margin-left: auto; margin-right: auto; @elseif($align === 'right') margin-left: auto; @endif
    }
    .{{ $hid }} .mk-hero-actions {
        display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;
        justify-content: {{ $justify }};
    }
    .{{ $hid }} .mk-btn--link { background: transparent; border: 0; padding-left: 0; padding-right: 0; color: var(--mk-text); text-decoration: underline; }
    /* MARKER-HERO-PILL */
    .{{ $hid }} .mk-hero-pill { display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 5px; border-radius: 999px; max-width: 100%;
        background: rgba(10,10,10,.5); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 0.5px solid rgba(255,255,255,.14); }
    .{{ $hid }} .mk-hero-pill .mk-btn { border-radius: 999px; }
    .{{ $hid }} .mk-btn--pilllink { background: transparent; border: 0; color: var(--mk-text); opacity: .85; }
    .{{ $hid }} .mk-btn--pilllink:hover { opacity: 1; }
    /* MARKER-HERO-PILL-MOBILE */
    @media (max-width: 600px) {
        .{{ $hid }} .mk-hero-pill { display: flex; flex-direction: column; width: 100%; border-radius: 22px; gap: 2px; padding: 6px; }
        .{{ $hid }} .mk-hero-pill .mk-btn { width: 100%; justify-content: center; text-align: center; }
    }
    .mk-hero-note { font-size: 12px; color: var(--mk-dim); }
</style>

@php
    // MARKER-HERO-SCROLLFX — 0 = off
    $hfxP = max(0, min(100, (int) ($c['scroll_parallax'] ?? 0)));
    $hfxF = max(0, min(100, (int) ($c['scroll_fade'] ?? 0)));
    $hfxB = max(0, min(20,  (int) ($c['scroll_blur'] ?? 0)));
    $hfxOn = $hfxP || $hfxF || $hfxB;
@endphp
<section class="mk-hero {{ $bgId }} {{ $hid }}">
    <div class="mk-container"><div class="mk-hero-fx" @if($hfxOn) data-hfx="{{ $hfxP }},{{ $hfxF }},{{ $hfxB }}" @endif>
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif

        <h1>{!! $safeHeadline !!}</h1>

        @if(!empty($c['subheading']))
            <p class="mk-hero-sub">{{ $c['subheading'] }}</p>
        @endif

        @if($buttons)
            <div class="mk-hero-actions">
                @if($heroPill) <span class="mk-hero-pill"> @endif
                @foreach($buttons as $b)
                    @php $st = ($heroPill && ! $loop->first) ? 'pilllink' : ($b['style'] ?? 'primary'); @endphp
                    <a href="{{ $b['url'] ?? '#' }}" class="mk-btn {{ $st === 'primary' ? 'mk-btn--primary' : ($st === 'link' ? 'mk-btn--link' : ($st === 'pilllink' ? 'mk-btn--pilllink' : 'mk-btn--ghost')) }}">
                        {{ $b['label'] }} @if($st === 'primary') → @endif
                    </a>
                @endforeach
                @if($heroPill) </span> @endif
            </div>
        @endif

        @if(!empty($c['note']))
            <p class="mk-hero-note">{{ $c['note'] }}</p>
        @endif
    </div></div>
</section>
@if($hfxOn)
<script>
/* MARKER-HERO-SCROLLFX — content drifts, fades and blurs as the hero scrolls away */
(function () {
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var fx = document.querySelector('.{{ $hid }} .mk-hero-fx[data-hfx]');
  if (!fx || fx.dataset.hfxReady) return;
  fx.dataset.hfxReady = '1';
  var v = fx.dataset.hfx.split(',').map(Number), P = v[0] / 100, F = v[1] / 100, B = v[2];
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
