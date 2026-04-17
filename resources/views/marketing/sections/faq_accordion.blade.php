{{-- FAQ accordion. Content: heading, items[{q, a}] --}}
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container" style="max-width:760px">
        @if(!empty($c['heading']))
            <h2 style="text-align:center;margin-bottom:32px">{{ $c['heading'] }}</h2>
        @endif

        <div style="display:flex;flex-direction:column;gap:8px">
            @foreach(($c['items'] ?? []) as $item)
                <details style="
                    border: 1px solid var(--mk-border);
                    border-radius: 12px;
                    padding: 16px 20px;
                    background: white;
                    transition: border-color .15s;
                " onmouseover="this.style.borderColor='var(--mk-accent)'" onmouseout="this.style.borderColor='var(--mk-border)'">
                    <summary style="
                        font-weight: 600;
                        cursor: pointer;
                        list-style: none;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        font-size: 16px;
                    ">
                        <span>{{ $item['q'] ?? '' }}</span>
                        <span style="opacity:.4;font-size:20px;transition:transform .15s">+</span>
                    </summary>
                    <div style="margin-top:12px;color:var(--mk-text-muted);font-size:15px;line-height:1.6">
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
