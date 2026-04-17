{{--
    Step timeline. New section type for the "Up and running in minutes" block.
    Content: eyebrow, heading, subheading,
             steps[{title, desc, done(bool)}]  ← done=true = filled lime circle

    Steps laid out as equal columns with a horizontal connecting line
    behind the numbered circles. Mobile collapses to 2-column grid and
    hides the connector.
--}}
<style>
    .mk-how-steps {
        display: grid;
        grid-template-columns: repeat({{ count($c['steps'] ?? []) ?: 4 }}, 1fr);
        gap: 0;
        position: relative;
        margin-top: 8px;
    }
    .mk-how-steps::before {
        content: '';
        position: absolute;
        top: 20px;
        left: calc(100% / {{ count($c['steps'] ?? []) ?: 4 }} / 2);
        right: calc(100% / {{ count($c['steps'] ?? []) ?: 4 }} / 2);
        height: 0.5px;
        background: rgba(255,255,255,.08);
        z-index: 0;
    }
    .mk-how-step { text-align: center; padding: 0 12px; }
    .mk-how-num {
        width: 40px; height: 40px;
        border-radius: 50%;
        border: 0.5px solid var(--mk-border2);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 14px;
        font-size: 14px; font-weight: 600;
        background: var(--mk-bg);
        position: relative; z-index: 1;
        color: var(--mk-muted);
        transition: all .2s;
    }
    .mk-how-step.done .mk-how-num {
        background: var(--mk-accent);
        color: var(--mk-accent-text);
        border-color: var(--mk-accent);
    }
    .mk-how-title { font-size: 14px; font-weight: 600; margin-bottom: 5px; }
    .mk-how-desc  { font-size: 12px; color: var(--mk-muted); line-height: 1.55; }

    @media(max-width: 860px) {
        .mk-how-steps { grid-template-columns: 1fr 1fr; gap: 24px; }
        .mk-how-steps::before { display: none; }
    }
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

        <div class="mk-how-steps">
            @foreach(($c['steps'] ?? []) as $i => $step)
                <div class="mk-how-step {{ ($step['done'] ?? false) ? 'done' : '' }}">
                    <div class="mk-how-num">{{ $i + 1 }}</div>
                    <div class="mk-how-title">{{ $step['title'] ?? '' }}</div>
                    <p class="mk-how-desc">{{ $step['desc'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
