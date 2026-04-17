{{-- Testimonials. Content: heading, testimonials[{quote, author, role, avatar_url}] --}}
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <h2 style="text-align:center;margin-bottom:48px">{{ $c['heading'] }}</h2>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;max-width:1100px;margin:0 auto">
            @foreach(($c['testimonials'] ?? []) as $t)
                <blockquote style="
                    margin: 0; padding: 32px;
                    background: white;
                    border: 1px solid var(--mk-border);
                    border-radius: 16px;
                ">
                    <div style="color:var(--mk-accent);font-size:24px;line-height:1;margin-bottom:12px">"</div>
                    <p style="font-size:16px;color:var(--mk-text);line-height:1.6;margin-bottom:20px">
                        {{ $t['quote'] ?? '' }}
                    </p>
                    <footer style="display:flex;align-items:center;gap:12px;margin:0">
                        @if(!empty($t['avatar_url']))
                            <img src="{{ $t['avatar_url'] }}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
                        @else
                            <div style="width:40px;height:40px;border-radius:50%;background:var(--mk-accent);opacity:.15"></div>
                        @endif
                        <div>
                            <div style="font-weight:600;font-size:14px">{{ $t['author'] ?? '' }}</div>
                            <div style="font-size:13px;color:var(--mk-text-muted)">{{ $t['role'] ?? '' }}</div>
                        </div>
                    </footer>
                </blockquote>
            @endforeach
        </div>
    </div>
</section>
