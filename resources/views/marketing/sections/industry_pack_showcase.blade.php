{{-- Industry pack showcase. Content: heading, subheading, limit, show_all_link --}}
@php
    $packs = config('industry_packs', []);
    $limit = (int)($c['limit'] ?? 12);
    $shown = array_slice($packs, 0, $limit, true);
@endphp
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']) || !empty($c['subheading']))
            <div style="text-align:center;margin-bottom:40px;max-width:640px;margin-left:auto;margin-right:auto">
                @if(!empty($c['heading']))      <h2 class="mk-section-title">{{ $c['heading'] }}</h2>      @endif
                @if(!empty($c['subheading']))   <p class="mk-section-sub">{{ $c['subheading'] }}</p>       @endif
            </div>
        @endif

        @if(empty($shown))
            <p style="text-align:center;color:var(--mk-muted);opacity:.6">
                Industry packs loading…
            </p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;max-width:1100px;margin:0 auto">
                @foreach($shown as $slug => $pack)
                    <a href="/for/{{ $slug }}" style="
                        padding: 20px;
                        border: 0.5px solid var(--mk-border);
                        border-radius: 12px;
                        background: rgba(255,255,255,.03);
                        display: block;
                        transition: all .15s;
                    " onmouseover="this.style.borderColor='rgba(190,242,100,.4)';this.style.background='rgba(190,242,100,.03)';this.style.transform='translateY(-2px)'"
                       onmouseout="this.style.borderColor='var(--mk-border)';this.style.background='rgba(255,255,255,.03)';this.style.transform='translateY(0)'">
                        <div style="font-size:28px;margin-bottom:10px">{{ $pack['icon'] ?? '📦' }}</div>
                        <h3 style="font-size:15px;margin-bottom:4px;color:var(--mk-text);font-weight:600">{{ $pack['name'] }}</h3>
                        <p style="font-size:12px;margin:0;line-height:1.5;color:var(--mk-muted)">{{ $pack['tagline'] ?? '' }}</p>
                    </a>
                @endforeach
            </div>

            @if(count($packs) > $limit && ($c['show_all_link'] ?? true))
                <div style="text-align:center;margin-top:32px">
                    <a href="/features" class="mk-btn mk-btn--ghost mk-btn--sm">See all {{ count($packs) }} industries →</a>
                </div>
            @endif
        @endif
    </div>
</section>
