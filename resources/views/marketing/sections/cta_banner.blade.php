{{-- CTA banner. Content: headline, subheading, cta_label, cta_url, bg_color, text_color --}}
<section class="{{ $padding }}" style="
    background: {{ $c['bg_color'] ?: 'var(--mk-accent)' }};
    color: {{ $c['text_color'] ?: '#ffffff' }};
    text-align: center;
">
    <div class="mk-container" style="max-width:760px">
        <h2 style="color:inherit;margin-bottom:12px">{{ $c['headline'] ?? 'Ready to get started?' }}</h2>
        @if(!empty($c['subheading']))
            <p style="color:inherit;opacity:.85;font-size:18px;margin-bottom:24px">{{ $c['subheading'] }}</p>
        @endif
        <a href="{{ $c['cta_url'] ?? '#' }}"
           class="mk-btn"
           style="background:white;color:var(--mk-accent);font-weight:700">
            {{ $c['cta_label'] ?? 'Get started' }}
        </a>
    </div>
</section>
