{{-- Text + image block. Content: heading, body, image_url, image_position (left|right), cta_label, cta_url --}}
@php
    $imgRight = ($c['image_position'] ?? 'right') === 'right';
@endphp
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;max-width:1100px;margin:0 auto">
            <div style="order: {{ $imgRight ? 1 : 2 }}">
                @if(!empty($c['heading']))
                    <h2>{{ $c['heading'] }}</h2>
                @endif
                <div style="font-size:16px;line-height:1.7;color:var(--mk-text-muted)">
                    {!! nl2br(e($c['body'] ?? '')) !!}
                </div>
                @if(!empty($c['cta_label']))
                    <div style="margin-top:24px">
                        <a href="{{ $c['cta_url'] ?? '#' }}" class="mk-btn mk-btn--primary">
                            {{ $c['cta_label'] }}
                        </a>
                    </div>
                @endif
            </div>
            <div style="order: {{ $imgRight ? 2 : 1 }}">
                @if(!empty($c['image_url']))
                    <img src="{{ $c['image_url'] }}" alt="" style="border-radius:16px;width:100%;box-shadow:0 20px 40px -12px rgba(0,0,0,.1)">
                @else
                    <div style="aspect-ratio:4/3;background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(124,58,237,.03));border-radius:16px;display:flex;align-items:center;justify-content:center;color:var(--mk-accent);opacity:.4;font-size:14px">
                        Image placeholder
                    </div>
                @endif
            </div>
        </div>
    </div>

    <style>
        @media (max-width: 760px) {
            .mk-container > div[style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
            }
            .mk-container > div > div[style*="order: 2"] { order: 2 !important; }
            .mk-container > div > div[style*="order: 1"] { order: 1 !important; }
        }
    </style>
</section>
