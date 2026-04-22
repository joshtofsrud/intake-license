{{-- FAQ accordion. Content: heading, items[{q, a}] --}}
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container" style="max-width:760px">
        @if(!empty($c['heading']))
            <h2 class="mk-section-title" style="text-align:center;margin-bottom:32px">{{ $c['heading'] }}</h2>
        @endif

        <div style="display:flex;flex-direction:column;gap:8px">
            @foreach(($c['items'] ?? []) as $item)
                <details style="
                    border: 0.5px solid var(--mk-border);
                    border-radius: 12px;
                    padding: 16px 20px;
                    background: rgba(255,255,255,.03);
                    transition: border-color .15s, background .15s;
                " onmouseover="this.style.borderColor='rgba(190,242,100,.3)';this.style.background='rgba(255,255,255,.04)'" onmouseout="this.style.borderColor='var(--mk-border)';this.style.background='rgba(255,255,255,.03)'">
                    <summary style="
                        font-weight: 600;
                        cursor: pointer;
                        list-style: none;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        font-size: 16px;
                        color: var(--mk-text);
                    ">
                        <span>{{ $item['q'] ?? '' }}</span>
                        <span style="color: var(--mk-accent);font-size:20px;transition:transform .15s">+</span>
                    </summary>
                    <div style="margin-top:12px;color:var(--mk-muted);font-size:15px;line-height:1.65">
                        {!! nl2br(e($item['a'] ?? '')) !!}
                    </div>
                </details>
            @endforeach
        </div>
    </div>

    <style>
        details[open] summary > span:last-child { transform: rotate(45deg); }
        summary::-webkit-details-marker { display: none; }
    </style>
</section>
