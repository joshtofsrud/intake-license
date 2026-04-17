{{--
    CTA banner. Content: headline, subheading, cta_label, cta_url, bg_color, text_color

    Old design: centered, borderless section at the bottom of marketing pages.
    "Ready to fill your calendar?" style final push.
--}}
@php
    $bg   = !empty($c['bg_color'])   ? $c['bg_color']   : null;
    $text = !empty($c['text_color']) ? $c['text_color'] : null;
@endphp

<style>
    .mk-cta-strip {
        padding: clamp(48px, 7vw, 88px) 0;
        text-align: center;
        @if($bg) background: {{ $bg }}; @endif
        @if($text) color: {{ $text }}; @endif
    }
    .mk-cta-h2 {
        font-size: clamp(26px, 4vw, 48px);
        font-weight: 800;
        letter-spacing: -.03em;
        margin-bottom: 10px;
    }
    .mk-cta-sub {
        font-size: 16px;
        color: var(--mk-muted);
        margin-bottom: 28px;
    }
</style>

<section class="mk-cta-strip">
    <div class="mk-container">
        <h2 class="mk-cta-h2">{{ $c['headline'] ?? 'Ready to get started?' }}</h2>
        @if(!empty($c['subheading']))
            <p class="mk-cta-sub">{{ $c['subheading'] }}</p>
        @endif
        @if(!empty($c['cta_label']))
            <a href="{{ $c['cta_url'] ?? '#' }}" class="mk-btn mk-btn--primary">
                {{ $c['cta_label'] }} →
            </a>
        @endif
    </div>
</section>
