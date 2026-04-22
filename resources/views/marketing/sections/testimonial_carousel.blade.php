{{-- Testimonials. Content: heading, testimonials[{quote, author, role, avatar_url}] --}}
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <h2 class="mk-section-title" style="text-align:center;margin-bottom:48px">{{ $c['heading'] }}</h2>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;max-width:1100px;margin:0 auto">
            @foreach(($c['testimonials'] ?? []) as $t)
                <blockquote style="
                    margin: 0; padding: 28px;
                    background: rgba(255,255,255,.03);
                    border: 0.5px solid var(--mk-border);
                    border-radius: 14px;
                    transition: border-color .15s;
                " onmouseover="this.style.borderColor='rgba(190,242,100,.25)'" onmouseout="this.style.borderColor='var(--mk-border)'">
                    <div style="color:var(--mk-accent);font-size:28px;line-height:1;margin-bottom:14px;font-family:Georgia,serif">"</div>
                    <p style="font-size:15px;color:var(--mk-text);line-height:1.65;margin-bottom:22px">
                        {{ $t['quote'] ?? '' }}
                    </p>
                    <footer style="display:flex;align-items:center;gap:12px;margin:0">
                        @if(!empty($t['avatar_url']))
                            <img src="{{ $t['avatar_url'] }}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:0.5px solid var(--mk-border)">
                        @else
                            <div style="width:40px;height:40px;border-radius:50%;background:var(--mk-accent-dim);border:0.5px solid rgba(190,242,100,.2)"></div>
                        @endif
                        <div>
                            <div style="font-weight:600;font-size:14px;color:var(--mk-text)">{{ $t['author'] ?? '' }}</div>
                            <div style="font-size:12px;color:var(--mk-muted)">{{ $t['role'] ?? '' }}</div>
                        </div>
                    </footer>
                </blockquote>
            @endforeach
        </div>
    </div>
</section>
