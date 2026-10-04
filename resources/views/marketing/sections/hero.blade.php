@php $bgId = 'mkbg-' . substr((string) ($section->id ?? uniqid()), 0, 8); @endphp {{-- MARKER-MKT-SECTION-BG --}}
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
    Hero (intake.works). MARKER-MKT-HERO-LAYOUT — reads the same editor
    settings as a shop's hero: height, vertical alignment, content width,
    headline and subheading size, text colours and the buttons list.
    Padding, hide on mobile/desktop, anchor and custom classes are applied
    to every section by the wrapper in marketing/page.blade.php.

    Size presets match the shop hero, except "auto" headline and "medium"
    subheading, which keep the intake.works look.

    accent_words: any occurrence of that phrase in the headline is wrapped
    in <em> and shown in the accent colour.
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
    if (! $buttons) {
        if (! empty($c['cta_primary_label'])) {
            $buttons[] = ['label' => $c['cta_primary_label'], 'url' => $c['cta_primary_url'] ?? '#', 'style' => 'primary'];
        }
        if (! empty($c['cta_secondary_label'])) {
            $buttons[] = ['label' => $c['cta_secondary_label'], 'url' => $c['cta_secondary_url'] ?? '#', 'style' => 'outline'];
        }
    }
    $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$align];
@endphp

<style>
    .mk-hero.{{ $hid }} {
        padding: clamp(64px, 10vw, 120px) 0 clamp(48px, 7vw, 88px);
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
    .mk-hero-note { font-size: 12px; color: var(--mk-dim); }
</style>

<section class="mk-hero {{ $bgId }} {{ $hid }}">
    <div class="mk-container">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif

        <h1>{!! $safeHeadline !!}</h1>

        @if(!empty($c['subheading']))
            <p class="mk-hero-sub">{{ $c['subheading'] }}</p>
        @endif

        @if($buttons)
            <div class="mk-hero-actions">
                @foreach($buttons as $b)
                    @php $st = $b['style'] ?? 'primary'; @endphp
                    <a href="{{ $b['url'] ?? '#' }}" class="mk-btn {{ $st === 'primary' ? 'mk-btn--primary' : ($st === 'link' ? 'mk-btn--link' : 'mk-btn--ghost') }}">
                        {{ $b['label'] }} @if($st === 'primary') → @endif
                    </a>
                @endforeach
            </div>
        @endif

        @if(!empty($c['note']))
            <p class="mk-hero-note">{{ $c['note'] }}</p>
        @endif
    </div>
</section>
