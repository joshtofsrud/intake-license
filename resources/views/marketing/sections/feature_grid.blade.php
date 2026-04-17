{{--
    Feature grid. Content: eyebrow, heading, subheading, columns (2|3|4),
                          features[{icon, title, body}], cta_label, cta_url

    Icon field accepts either an emoji (📅) or raw SVG path data. If the
    features content was saved as a JSON string by the editor, decode it
    defensively before iterating.
--}}
@php
    $cols = max(2, min(4, (int)($c['columns'] ?? 3)));
    $isSvgPath = function ($icon) {
        return is_string($icon) && str_contains($icon, '<') && (
            str_contains($icon, 'path') ||
            str_contains($icon, 'rect') ||
            str_contains($icon, 'circle') ||
            str_contains($icon, 'line')
        );
    };

    $features = $c['features'] ?? [];
    if (is_string($features)) {
        $decoded = json_decode($features, true);
        $features = is_array($decoded) ? $decoded : [];
    }
@endphp

<style>
    .mk-feat-grid {
        display: grid;
        grid-template-columns: repeat({{ $cols }}, 1fr);
        gap: 14px;
    }
    .mk-feat-card {
        background: rgba(255,255,255,.03);
        border: 0.5px solid var(--mk-border);
        border-radius: var(--mk-r-lg);
        padding: 22px;
        transition: border-color .15s;
    }
    .mk-feat-card:hover { border-color: rgba(255,255,255,.16); }
    .mk-feat-icon {
        width: 36px; height: 36px;
        background: var(--mk-accent-dim);
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 14px;
        font-size: 18px;
    }
    .mk-feat-icon svg {
        width: 18px; height: 18px;
        stroke: var(--mk-accent);
        fill: none;
        stroke-width: 1.5;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .mk-feat-title { font-size: 14px; font-weight: 600; margin-bottom: 6px; }
    .mk-feat-desc  { font-size: 13px; color: var(--mk-muted); line-height: 1.6; }

    @media(max-width: 860px) { .mk-feat-grid { grid-template-columns: 1fr 1fr; } }
    @media(max-width: 560px) { .mk-feat-grid { grid-template-columns: 1fr; } }
</style>

<section class="mk-section">
    <div class="mk-container">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
            <h2 class="mk-section-title">{{ $c['heading'] }}</h2>
        @endif
        @if(!empty($c['subheading']))
            <p class="mk-section-sub">{{ $c['subheading'] }}</p>
        @endif

        <div class="mk-feat-grid">
            @foreach($features as $feat)
                <div class="mk-feat-card">
                    <div class="mk-feat-icon">
                        @if($isSvgPath($feat['icon'] ?? ''))
                            <svg viewBox="0 0 16 16">{!! $feat['icon'] !!}</svg>
                        @elseif(!empty($feat['icon']))
                            <span>{{ $feat['icon'] }}</span>
                        @endif
                    </div>
                    <div class="mk-feat-title">{{ $feat['title'] ?? '' }}</div>
                    <p class="mk-feat-desc">{{ $feat['body'] ?? '' }}</p>
                </div>
            @endforeach
        </div>

        @if(!empty($c['cta_label']))
            <div style="margin-top:24px">
                <a href="{{ $c['cta_url'] ?? '#' }}" class="mk-btn mk-btn--ghost mk-btn--sm">
                    {{ $c['cta_label'] }} →
                </a>
            </div>
        @endif
    </div>
</section>
