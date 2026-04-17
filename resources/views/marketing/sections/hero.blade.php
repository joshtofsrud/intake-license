{{--
    Hero. Content: eyebrow, headline, accent_words, subheading,
                   cta_primary_label, cta_primary_url,
                   cta_secondary_label, cta_secondary_url, note,
                   text_align (defaults center)

    The accent_words field is the old design's <em> trick — if set, any
    occurrence of that phrase in the headline is wrapped in <em> and
    shown in the lime accent color. e.g. headline="Online booking for
    bike shops, ski shops, and beyond", accent_words="bike shops, ski shops,"
--}}
@php
    $headline = $c['headline'] ?? 'Your headline here';
    if (!empty($c['accent_words'])) {
        // Case-sensitive first match, wrapped in <em>. Escape the whole
        // headline first, then replace escaped accent_words with the em'd
        // version so we don't open an XSS hole.
        $safeHeadline = e($headline);
        $safeAccent = e($c['accent_words']);
        $pos = strpos($safeHeadline, $safeAccent);
        if ($pos !== false) {
            $safeHeadline = substr($safeHeadline, 0, $pos)
                . '<em>' . $safeAccent . '</em>'
                . substr($safeHeadline, $pos + strlen($safeAccent));
        }
    } else {
        $safeHeadline = e($headline);
    }
    $align = $c['text_align'] ?? 'center';
@endphp

<style>
    .mk-hero {
        padding: clamp(64px, 10vw, 120px) 0 clamp(48px, 7vw, 88px);
        text-align: {{ $align }};
        border-bottom: 0.5px solid var(--mk-border);
    }
    .mk-hero h1 {
        font-size: clamp(36px, 6vw, 72px);
        font-weight: 800;
        letter-spacing: -.03em;
        line-height: 1.04;
        margin-bottom: 20px;
        max-width: 720px;
        @if($align === 'center') margin-left: auto; margin-right: auto; @endif
    }
    .mk-hero h1 em { font-style: normal; color: var(--mk-accent); }
    .mk-hero-sub {
        font-size: clamp(15px, 2vw, 19px);
        color: var(--mk-muted);
        max-width: 500px;
        @if($align === 'center') margin: 0 auto 32px; @else margin: 0 0 32px; @endif
        line-height: 1.65;
    }
    .mk-hero-actions {
        display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;
        @if($align === 'center') justify-content: center; @endif
    }
    .mk-hero-note { font-size: 12px; color: var(--mk-dim); }
</style>

<section class="mk-hero">
    <div class="mk-container">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif

        <h1>{!! $safeHeadline !!}</h1>

        @if(!empty($c['subheading']))
            <p class="mk-hero-sub">{{ $c['subheading'] }}</p>
        @endif

        @if(!empty($c['cta_primary_label']) || !empty($c['cta_secondary_label']))
            <div class="mk-hero-actions">
                @if(!empty($c['cta_primary_label']))
                    <a href="{{ $c['cta_primary_url'] ?? '#' }}" class="mk-btn mk-btn--primary">
                        {{ $c['cta_primary_label'] }} →
                    </a>
                @endif
                @if(!empty($c['cta_secondary_label']))
                    <a href="{{ $c['cta_secondary_url'] ?? '#' }}" class="mk-btn mk-btn--ghost">
                        {{ $c['cta_secondary_label'] }}
                    </a>
                @endif
            </div>
        @endif

        @if(!empty($c['note']))
            <p class="mk-hero-note">{{ $c['note'] }}</p>
        @endif
    </div>
</section>
