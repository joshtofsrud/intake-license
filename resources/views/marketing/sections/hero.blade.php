{{-- Hero section. Content: headline, subheading, bg_color, text_color, cta_primary_*, cta_secondary_*, text_align, height --}}
@php
    $align = $c['text_align'] ?? 'center';
    $height = $c['height'] ?? 'large';
    $minH = ['small' => 320, 'medium' => 480, 'large' => 600, 'fullscreen' => '100vh'][$height] ?? 600;
    $minHCss = is_numeric($minH) ? $minH . 'px' : $minH;
    $bgImage = $c['bg_image_url'] ?? null;
    $overlay = $c['overlay_opacity'] ?? 0.4;
@endphp
<section class="{{ $padding }}" style="
    background: {{ $bgImage ? 'url(' . e($bgImage) . ') center/cover no-repeat' : ($c['bg_color'] ?? '#0F172A') }};
    color: {{ $c['text_color'] ?? '#ffffff' }};
    min-height: {{ $minHCss }};
    display: flex; align-items: center;
    position: relative;
    text-align: {{ $align }};
">
    @if($bgImage)
        <div style="position:absolute;inset:0;background:rgba(0,0,0,{{ $overlay }})"></div>
    @endif

    <div class="mk-container" style="position:relative;z-index:1;width:100%">
        <div style="max-width:{{ $align === 'center' ? '820px' : '640px' }};margin:{{ $align === 'center' ? '0 auto' : '0' }}">
            <h1 style="color:inherit">{{ $c['headline'] ?? 'Your headline here' }}</h1>
            @if(!empty($c['subheading']))
                <p style="font-size:19px;color:inherit;opacity:.85;margin:0 0 32px">{{ $c['subheading'] }}</p>
            @endif

            <div style="display:flex;gap:12px;justify-content:{{ $align === 'center' ? 'center' : 'flex-start' }};flex-wrap:wrap">
                @if(!empty($c['cta_primary_label']))
                    <a href="{{ $c['cta_primary_url'] ?? '#' }}" class="mk-btn mk-btn--primary">{{ $c['cta_primary_label'] }}</a>
                @endif
                @if(!empty($c['cta_secondary_label']))
                    <a href="{{ $c['cta_secondary_url'] ?? '#' }}" class="mk-btn mk-btn--ghost">{{ $c['cta_secondary_label'] }}</a>
                @endif
            </div>
        </div>
    </div>
</section>
