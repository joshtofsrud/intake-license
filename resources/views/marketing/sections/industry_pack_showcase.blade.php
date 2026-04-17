{{-- Industry pack showcase. Content: heading, subheading, limit, show_all_link --}}
@php
    $packs = config('industry_packs', []);
    $limit = (int)($c['limit'] ?? 12);
    $shown = array_slice($packs, 0, $limit, true);
@endphp
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']) || !empty($c['subheading']))
            <div style="text-align:center;margin-bottom:48px;max-width:640px;margin-left:auto;margin-right:auto">
                @if(!empty($c['heading']))      <h2>{{ $c['heading'] }}</h2>      @endif
                @if(!empty($c['subheading']))   <p style="font-size:18px">{{ $c['subheading'] }}</p>   @endif
            </div>
        @endif

        @if(empty($shown))
            <p style="text-align:center;color:var(--mk-text-muted);opacity:.6">
                Industry packs loading…
            </p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;max-width:1100px;margin:0 auto">
                @foreach($shown as $slug => $pack)
                    <a href="/for/{{ $slug }}" style="
                        padding: 24px;
                        border: 1px solid var(--mk-border);
                        border-radius: 12px;
                        background: white;
                        display: block;
                        transition: all .15s;
                    " onmouseover="this.style.borderColor='var(--mk-accent)';this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px -8px rgba(124,58,237,.2)'"
                       onmouseout="this.style.borderColor='var(--mk-border)';this.style.transform='translateY(0)';this.style.boxShadow='none'">
                        <div style="font-size:32px;margin-bottom:12px">{{ $pack['icon'] ?? '📦' }}</div>
                        <h3 style="font-size:16px;margin-bottom:6px">{{ $pack['name'] }}</h3>
                        <p style="font-size:13px;margin:0;line-height:1.5">{{ $pack['tagline'] ?? '' }}</p>
                    </a>
                @endforeach
            </div>

            @if(count($packs) > $limit && ($c['show_all_link'] ?? true))
                <div style="text-align:center;margin-top:32px">
                    <a href="/features" class="mk-btn mk-btn--secondary">See all {{ count($packs) }} industries</a>
                </div>
            @endif
        @endif
    </div>
</section>
