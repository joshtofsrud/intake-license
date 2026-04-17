{{-- Footer. Content: show_logo, copyright_text, show_nav, show_powered_by, footer_bg, footer_text --}}
<footer class="{{ $padding }}" style="
    background: {{ $c['footer_bg'] ?? '#0F172A' }};
    color: {{ $c['footer_text'] ?? '#E5E7EB' }};
    padding: 48px 0 32px;
">
    <div class="mk-container">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:48px;margin-bottom:32px">
            <div>
                @if($c['show_logo'] ?? true)
                    <div style="font-size:20px;font-weight:800;margin-bottom:12px">Intake</div>
                @endif
                <p style="color:inherit;opacity:.6;max-width:420px;margin:0">
                    Booking & intake software for service businesses. Beautiful sites, smart scheduling, built-in payments.
                </p>
            </div>

            @if($c['show_nav'] ?? true)
                <div style="display:flex;flex-direction:column;gap:10px">
                    <div style="font-weight:600;margin-bottom:4px;opacity:.8">Product</div>
                    @foreach($navItems as $item)
                        <a href="{{ $item->url }}" style="font-size:14px;opacity:.6">{{ $item->label }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        <div style="border-top:1px solid rgba(255,255,255,.1);padding-top:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div style="font-size:13px;opacity:.5">
                {{ $c['copyright_text'] ?? ('© ' . date('Y') . ' Intake. All rights reserved.') }}
            </div>
            @if($c['show_powered_by'] ?? false)
                <div style="font-size:12px;opacity:.4">Built with Intake</div>
            @endif
        </div>
    </div>
</footer>
