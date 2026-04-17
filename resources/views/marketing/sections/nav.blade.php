{{-- Navigation bar. Content: show_logo, cta_label, cta_url, bg_style, nav_height, bg_color --}}
@php
    $bgStyle = $c['bg_style'] ?? 'solid';
    $navHeight = $c['nav_height'] ?? 'normal';
    $heightPx = ['compact' => 52, 'normal' => 64, 'tall' => 80][$navHeight] ?? 64;
    $bgColor = $c['bg_color'] ?? null;
@endphp
<nav style="
    position: {{ $bgStyle === 'transparent' ? 'absolute' : 'sticky' }};
    top: 0; left: 0; right: 0;
    z-index: 100;
    height: {{ $heightPx }}px;
    display: flex; align-items: center;
    background: {{ $bgStyle === 'transparent' ? 'transparent' : ($bgColor ?: 'rgba(255,255,255,.95)') }};
    backdrop-filter: {{ $bgStyle === 'transparent' ? 'none' : 'blur(8px)' }};
    border-bottom: {{ $bgStyle === 'transparent' ? 'none' : '1px solid var(--mk-border)' }};
">
    <div class="mk-container" style="display:flex;align-items:center;justify-content:space-between;width:100%">
        <a href="/" style="display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px">
            @if($c['show_logo'] ?? true)
                <span style="width:28px;height:28px;background:var(--mk-accent);border-radius:6px;display:flex;align-items:center;justify-content:center;color:white;font-size:14px">I</span>
            @endif
            <span>Intake</span>
        </a>

        <div style="display:flex;align-items:center;gap:24px">
            @foreach($navItems as $item)
                <a href="{{ $item->url }}" style="font-size:14px;font-weight:500;opacity:.75"
                   @if($item->open_in_new_tab ?? false) target="_blank" rel="noopener" @endif>
                    {{ $item->label }}
                </a>
            @endforeach

            @if(!empty($c['cta_label']))
                <a href="{{ $c['cta_url'] ?? '#' }}" class="mk-btn mk-btn--primary">
                    {{ $c['cta_label'] }}
                </a>
            @endif
        </div>
    </div>
</nav>
