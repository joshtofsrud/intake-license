{{-- MARKER-DEMO-SECTION — Try the demo. Content: demo_slug, layout (card|button),
     eyebrow, heading, subheading, button_label, accent_color, anchor_id --}}
@php
    $slug    = $c['demo_slug'] ?? 'demo';
    $demo    = \App\Models\Tenant::where('subdomain', $slug)->where('is_demo', true)->first();
    $offline = \App\Models\DemoSetting::get('offline:' . $slug) === '1';
    $layout  = ($c['layout'] ?? 'card') === 'button' ? 'button' : 'card';
    $label   = trim((string) ($c['button_label'] ?? '')) ?: 'Try the demo';
    $preview = ! empty($builderPreview);
    $url     = $slug === 'demo' ? url('/demo') : url('/demo/' . $slug);

    $vars = '';
    if (! empty($c['accent_color'])) $vars .= "--mk-accent:{$c['accent_color']};";
    $style = trim(($inlineStyle ?? '') . $vars);

    $heading = trim((string) ($c['heading'] ?? '')) ?: 'Walk around a real shop';
    $sub     = trim((string) ($c['subheading'] ?? '')) ?: 'A working shop with real work in it — bookings, work orders, inventory, the lot. No signup, nothing to install.';
    // never optional: the two things a visitor cannot see for themselves
    $promise = 'Everything resets on the hour, and emails and texts are never really sent.';
@endphp
<section class="{{ $padding }}" @if($style !== '') style="{{ $style }}" @endif @if(!empty($c['anchor_id'])) id="{{ $c['anchor_id'] }}" @endif>
    <div class="mk-container">
        @if(! $demo)
            @if($preview)
                <div style="border:0.5px dashed rgba(255,255,255,.25);border-radius:12px;padding:20px;font-size:14px;opacity:.7">
                    Try the demo: no demo tenant called <code>{{ $slug }}</code>. Pick one in the panel, or build it on the server.
                </div>
            @endif
        @elseif($offline && ! $preview)
            {{-- switched off in master admin: say nothing rather than send people to a dead end --}}
        @else
            @if($layout === 'button')
                <a href="{{ $url }}" class="mk-btn mk-btn--primary">{{ $label }}</a>
                <div style="font-size:12.5px;color:var(--mk-dim);margin-top:10px">{{ $promise }}</div>
            @else
                <div style="background:var(--mk-bg2);border:.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:26px 28px;display:flex;gap:22px;align-items:center;flex-wrap:wrap">
                    <div style="flex:1 1 320px;min-width:0">
                        @if(!empty($c['eyebrow']))<div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>@endif
                        <h2 class="mk-section-title" style="margin-top:6px">{{ $heading }}</h2>
                        <p class="mk-section-sub" style="margin-bottom:0">{{ $sub }}</p>
                    </div>
                    <div style="flex:0 0 auto">
                        <a href="{{ $url }}" class="mk-btn mk-btn--primary">{{ $label }}</a>
                        @if($preview && $offline)<div style="font-size:12px;color:#f87171;margin-top:8px">Switched off right now — visitors will not see this section.</div>@endif
                    </div>
                    <div style="flex:1 1 100%;font-size:12.5px;color:var(--mk-dim);border-top:.5px solid var(--mk-border);padding-top:12px">
                        {{ $promise }}
                    </div>
                </div>
            @endif
        @endif
    </div>
</section>
