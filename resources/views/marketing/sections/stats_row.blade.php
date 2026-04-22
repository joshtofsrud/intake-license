{{-- Stats row. Content: heading, stats[{number, label}] --}}
<section class="{{ $padding }}" @if(!empty($inlineStyle ?? \'\')) style="{{ $inlineStyle }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <h2 style="text-align:center;margin-bottom:40px">{{ $c['heading'] }}</h2>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:32px;max-width:900px;margin:0 auto;text-align:center">
            @foreach(($c['stats'] ?? []) as $stat)
                <div>
                    <div style="font-size:clamp(32px, 5vw, 48px);font-weight:800;color:var(--mk-accent);line-height:1;letter-spacing:-.02em;margin-bottom:8px">
                        {{ $stat['number'] ?? '' }}
                    </div>
                    <div style="font-size:14px;color:var(--mk-text-muted);font-weight:500">
                        {{ $stat['label'] ?? '' }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
