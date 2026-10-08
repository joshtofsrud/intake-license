@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
    Feature grid (intake.works). reads every setting the
    editor offers, like a shop's feature grid does:
      layout (grid | intro_split), columns 1–4, card style (card | minimal),
      show icons, heading alignment, accent phrase, content width, text /
      body / accent / card background / card border colors, and per card:
      icon, title, price, body (line breaks kept), button label + link.
    Empty colors fall back to the intake.works palette, so a grid that was
    never styled keeps its look. Styles are scoped to this section, so two
    grids with different column counts no longer overwrite each other.
    Padding, hide on mobile/desktop, anchor and classes come from the wrapper
    in marketing/page.blade.php.

    Icon accepts an emoji or raw SVG shape markup (master admin content only).
--}}
@php
    $features = $c['features'] ?? [];
    if (is_string($features)) { $d = json_decode($features, true); $features = is_array($d) ? $d : []; }
    if (! is_array($features)) $features = [];
    $features = array_values(array_filter($features, 'is_array'));

    $layout    = ($c['layout'] ?? 'grid') === 'intro_split' ? 'intro_split' : 'grid';
    $cols      = max(1, min(4, (int) ($c['columns'] ?? 3)));
    $cardStyle = ($c['card_style'] ?? 'card') === 'minimal' ? 'minimal' : 'card';
    $showIcons = ! in_array((string) ($c['show_icons'] ?? '1'), ['0', 'false', ''], true);
    $align     = in_array($c['text_align'] ?? 'left', ['left', 'center', 'right'], true) ? ($c['text_align'] ?? 'left') : 'left';
    $maxW      = (int) ($c['content_max_width'] ?? 0);
    $maxW      = ($maxW >= 320 && $maxW <= 1600) ? $maxW : null;
    $splitCols = max(1, min(3, count($features)));

    $okColor = fn ($v) => is_string($v) && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\)|[a-zA-Z]+)$/', trim($v)) ? trim($v) : null;
    $txt    = $okColor($c['text_color'] ?? null)      ?: 'var(--mk-text)';
    $body   = $okColor($c['text_color_body'] ?? null) ?: 'var(--mk-muted)';
    $accent = $okColor($c['accent_color'] ?? null)    ?: 'var(--mk-accent)';
    $cardBg = $okColor($c['card_bg'] ?? null)         ?: 'rgba(255,255,255,.03)';
    $cardBd = $okColor($c['card_border'] ?? null)     ?: 'var(--mk-border)';

    $headingHtml = e($c['heading'] ?? '');
    $accentWords = trim((string) ($c['accent_words'] ?? ''));
    if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
        $headingHtml = str_ireplace(e($accentWords), '<em>' . e($accentWords) . '</em>', $headingHtml);
    }
    $headingHtml = nl2br($headingHtml);

    $isSvg = fn ($i) => is_string($i) && str_contains($i, '<') && preg_match('/<(path|rect|circle|line|polyline|polygon)\b/i', $i);

    $fid = 'mkfg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 8);
@endphp

<style>
    .{{ $fid }} .mk-fg-inner { @if($maxW) max-width: {{ $maxW }}px; margin-left: auto; margin-right: auto; @endif }
    .{{ $fid }} .mk-fg-head { text-align: {{ $align }}; margin-bottom: 32px; }
    .{{ $fid }} .mk-fg-head .mk-section-sub { @if($align === 'center') margin-left: auto; margin-right: auto; @elseif($align === 'right') margin-left: auto; @endif }
    .{{ $fid }} .mk-section-title { color: {{ $txt }}; }
    .{{ $fid }} .mk-section-title em { font-style: normal; color: {{ $accent }}; }
    .{{ $fid }} .mk-section-sub { color: {{ $body }}; }
    .{{ $fid }} .mk-eyebrow { color: {{ $accent }}; }

    .{{ $fid }} .mk-fg-grid { display: grid; grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr)); gap: 14px; }
    @media (max-width: 860px) { .{{ $fid }} .mk-fg-grid { grid-template-columns: repeat({{ min($cols, 2) }}, minmax(0, 1fr)); } }
    @media (max-width: 560px) { .{{ $fid }} .mk-fg-grid { grid-template-columns: 1fr; } }

    .{{ $fid }} .mk-fg-split {
        background: {{ $cardBg }}; border: 0.5px solid {{ $cardBd }}; border-radius: var(--mk-r-lg);
        padding: clamp(24px, 4vw, 40px); display: grid; gap: 24px; align-items: start;
        grid-template-columns: minmax(240px, 1.05fr) repeat({{ $splitCols }}, minmax(0, 1fr));
    }
    .{{ $fid }} .mk-fg-split .mk-fg-head { margin-bottom: 0; }
    @media (max-width: 1000px) { .{{ $fid }} .mk-fg-split { grid-template-columns: 1fr 1fr; } .{{ $fid }} .mk-fg-split .mk-fg-head { grid-column: 1 / -1; } }
    @media (max-width: 600px)  { .{{ $fid }} .mk-fg-split { grid-template-columns: 1fr; } }

    .{{ $fid }} .mk-fg-card { display: flex; flex-direction: column; color: {{ $txt }};
        @if($cardStyle === 'card')
        background: {{ $cardBg }}; border: 0.5px solid {{ $cardBd }}; border-radius: var(--mk-r-lg); padding: 22px; transition: border-color .15s;
        @else
        padding: 4px 0;
        @endif
    }
    @if($cardStyle === 'card') .{{ $fid }} .mk-fg-card:hover { border-color: rgba(255,255,255,.16); } @endif
    .{{ $fid }} .mk-fg-split .mk-fg-card { background: rgba(255,255,255,.025); border: 0.5px solid rgba(255,255,255,.05); border-radius: 10px; padding: 20px; }

    .{{ $fid }} .mk-fg-icon { width: 36px; height: 36px; background: var(--mk-accent-dim); border-radius: 8px;
        display: flex; align-items: center; justify-content: center; margin-bottom: 14px; font-size: 18px; color: {{ $accent }}; }
    .{{ $fid }} .mk-fg-icon svg { width: 18px; height: 18px; stroke: {{ $accent }}; fill: none; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }
    .{{ $fid }} .mk-fg-title { font-size: 14px; font-weight: 600; margin: 0 0 6px; color: {{ $txt }}; }
    .{{ $fid }} .mk-fg-price { font-family: var(--mk-mono, ui-monospace, monospace); font-size: 14px; font-weight: 600; color: {{ $accent }}; margin: 0 0 8px; }
    .{{ $fid }} .mk-fg-desc  { font-size: 13px; color: {{ $body }}; line-height: 1.6; margin: 0; flex: 1; white-space: pre-line; }
    .{{ $fid }} .mk-fg-cta   { display: inline-flex; gap: 4px; margin-top: 12px; font-size: 13px; font-weight: 500; color: {{ $accent }}; text-decoration: none; }
    .{{ $fid }} .mk-fg-cta:hover { text-decoration: underline; }
</style>

<section class="mk-section {{ $bgId }} {{ $fid }}">
    <div class="mk-container">
        <div class="mk-fg-inner">
            @php ob_start(); @endphp
                @if(!empty($c['eyebrow']))
                    <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
                @endif
                @if(!empty($c['heading']))
                    <h2 class="mk-section-title">{!! $headingHtml !!}</h2>
                @endif
                @if(!empty($c['subheading']))
                    <p class="mk-section-sub">{{ $c['subheading'] }}</p>
                @endif
            @php $headHtml = trim(ob_get_clean()); ob_start(); @endphp
                @foreach($features as $f)
                    <div class="mk-fg-card">
                        @if($showIcons && !empty($f['icon']))
                            <div class="mk-fg-icon">
                                @if($isSvg($f['icon']))
                                    <svg viewBox="0 0 16 16">{!! $f['icon'] !!}</svg>
                                @else
                                    <span>{{ $f['icon'] }}</span>
                                @endif
                            </div>
                        @endif
                        @if(!empty($f['title']))
                            <h3 class="mk-fg-title">{{ $f['title'] }}</h3>
                        @endif
                        @if(!empty($f['price']))
                            <div class="mk-fg-price">{{ $f['price'] }}</div>
                        @endif
                        @if(!empty($f['body']))
                            <p class="mk-fg-desc">{{ $f['body'] }}</p>
                        @endif
                        @if(!empty($f['cta_label']))
                            <a href="{{ $f['cta_url'] ?? '#' }}" class="mk-fg-cta">{{ $f['cta_label'] }} →</a>
                        @endif
                    </div>
                @endforeach
            @php $cardsHtml = ob_get_clean(); @endphp

            @if($layout === 'intro_split')
                <div class="mk-fg-split">
                    <div class="mk-fg-head">{!! $headHtml !!}</div>
                    {!! $cardsHtml !!}
                </div>
            @else
                @if($headHtml !== '')
                    <div class="mk-fg-head">{!! $headHtml !!}</div>
                @endif
                @if($features)
                    <div class="mk-fg-grid">{!! $cardsHtml !!}</div>
                @endif
            @endif

            @if(!empty($c['cta_label']))
                <div style="margin-top:24px">
                    <a href="{{ $c['cta_url'] ?? '#' }}" class="mk-btn mk-btn--ghost mk-btn--sm">{{ $c['cta_label'] }} →</a>
                </div>
            @endif
        </div>
    </div>
</section>
