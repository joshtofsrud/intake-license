{{-- Logo bar (trusted by). Content: heading, logos[{url, alt}] --}}
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <p style="text-align:center;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--mk-text-muted);margin-bottom:32px">
                {{ $c['heading'] }}
            </p>
        @endif

        @if(empty($c['logos']))
            <p style="text-align:center;color:var(--mk-text-muted);font-size:14px;opacity:.6">
                Logos coming soon.
            </p>
        @else
            <div style="display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:48px;opacity:.6">
                @foreach($c['logos'] as $logo)
                    <img src="{{ $logo['url'] }}" alt="{{ $logo['alt'] ?? '' }}" style="height:32px;width:auto;filter:grayscale(100%)">
                @endforeach
            </div>
        @endif
    </div>
</section>
