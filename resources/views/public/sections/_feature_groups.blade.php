{{--
    MARKER-SHOP-PORT4: Feature groups with an index, on shop sites. Port of
    marketing/sections/feature_groups.
    Content: eyebrow, heading, subheading, groups[{label, heading, lead, features[{title, body}]}]
    Desktop: the group labels sit in a column on the left and stay on screen while the
    groups scroll past; the one in view is highlighted; a click jumps to it. Phones: the
    index is a row of chips that sticks near the top and scrolls sideways.
--}}
@php
    $c  = $c ?? [];
    $sh = \App\Support\ShopSectionShell::make($c, $section, 'sfg');
    $fgxId = $sh['id'];
    $groups = $c['groups'] ?? [];
    if (is_string($groups)) { $decoded = json_decode($groups, true); $groups = is_array($decoded) ? $decoded : []; }
    $groups = array_values(array_filter(is_array($groups) ? $groups : [], fn ($g) => is_array($g) && trim((string) ($g['label'] ?? $g['heading'] ?? '')) !== ''));
@endphp
<style>
    {!! $sh['css'] !!}
    .{{ $fgxId }} .fgx-outer { max-width: var(--p-page-max, 1200px); margin: 0 auto; padding: 0 clamp(20px, 5vw, 48px); }
    .{{ $fgxId }} .fgx-eyebrow { font-family: ui-monospace, monospace; font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: {{ $sh['accent'] }}; margin-bottom: 14px; }
    .{{ $fgxId }} .fgx-title { font-size: clamp(26px, 3.6vw, 44px); font-weight: var(--p-heading-weight, 600); text-transform: var(--p-heading-transform, none); line-height: 1.1; letter-spacing: -.02em; margin: 0 0 12px; }
    .{{ $fgxId }} .fgx-sub { opacity: .7; font-size: 17px; line-height: 1.6; margin: 0; max-width: 640px; }
    .{{ $fgxId }} .fgx-layout { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: clamp(32px, 5vw, 64px); align-items: start; }
    .{{ $fgxId }} .fgx-index { position: sticky; top: 84px; display: grid; gap: 2px; }
    .{{ $fgxId }} .fgx-index a { display: block; padding: 8px 12px; border-radius: 8px; font-size: 14px; color: color-mix(in srgb, currentColor 60%, transparent); text-decoration: none; transition: background .15s, color .15s; }
    .{{ $fgxId }} .fgx-index { color: inherit; }
    .{{ $fgxId }} .fgx-index a:hover { color: inherit; }
    .{{ $fgxId }} .fgx-index a.is-on { background: color-mix(in srgb, currentColor 7%, transparent); color: inherit; }
    .{{ $fgxId }} .fgx-group { scroll-margin-top: 84px; padding-bottom: clamp(48px, 6vw, 80px); }
    .{{ $fgxId }} .fgx-group:last-child { padding-bottom: 0; }
    .{{ $fgxId }} .fgx-k { font-size: 13px; font-weight: 600; color: {{ $sh['accent'] }}; margin-bottom: 8px; }
    .{{ $fgxId }} .fgx-h { font-size: clamp(26px, 3.2vw, 40px); font-weight: 800; letter-spacing: -.03em; line-height: 1.05; margin: 0 0 12px; }
    .{{ $fgxId }} .fgx-lead { font-size: 16px; opacity: .7; line-height: 1.65; max-width: 620px; margin: 0 0 24px; }
    .{{ $fgxId }} .fgx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .{{ $fgxId }} .fgx-item { background: color-mix(in srgb, currentColor 4%, transparent); border: 1px solid color-mix(in srgb, currentColor 10%, transparent); border-radius: var(--p-card-r, 14px); padding: 20px; }
    .{{ $fgxId }} .fgx-item b { display: block; font-size: 15.5px; margin-bottom: 4px; }
    .{{ $fgxId }} .fgx-item span { font-size: 14px; opacity: .7; line-height: 1.55; }
    @media (max-width: 860px) {
        .{{ $fgxId }} .fgx-layout { grid-template-columns: 1fr; gap: 20px; }
        .{{ $fgxId }} .fgx-index { top: 60px; z-index: 5; display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none;
            margin: 0 calc(-1 * clamp(20px, 5vw, 48px)); padding: 10px clamp(20px, 5vw, 48px); background: color-mix(in srgb, var(--p-bg, #ffffff) 92%, transparent); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); }
        .{{ $fgxId }} .fgx-index::-webkit-scrollbar { display: none; }
        .{{ $fgxId }} .fgx-index a { flex: none; border: 1px solid color-mix(in srgb, currentColor 14%, transparent); border-radius: 99px; padding: 7px 13px; font-size: 13px; }
        .{{ $fgxId }} .fgx-index a.is-on { background: {{ $sh['accent'] }}; color: {{ $sh['accentText'] }}; border-color: {{ $sh['accent'] }}; }
        .{{ $fgxId }} .fgx-grid { grid-template-columns: 1fr; }
        .{{ $fgxId }} .fgx-group { scroll-margin-top: 120px; }
    }
</style>
<section {!! $sh['attrs'] !!}>
    <div class="fgx-outer">
        @if(!empty($c['eyebrow']) || $sh['heading'] !== '' || !empty($c['subheading']))
            <div style="margin-bottom: clamp(28px, 4vw, 48px)">
                @if(!empty($c['eyebrow']))<div class="fgx-eyebrow">{{ $c['eyebrow'] }}</div>@endif
                @if($sh['heading'] !== '')<h2 class="fgx-title">{!! $sh['heading'] !!}</h2>@endif
                @if(!empty($c['subheading']))<p class="fgx-sub">{{ $c['subheading'] }}</p>@endif
            </div>
        @endif

        <div class="fgx-layout">
            <nav class="fgx-index" aria-label="Sections">
                @foreach($groups as $gi => $g)
                    <a href="#{{ $fgxId }}-{{ $gi }}" data-fgx-link="{{ $gi }}" class="{{ $gi === 0 ? 'is-on' : '' }}">{{ ($g['label'] ?? '') ?: ($g['heading'] ?? '') }}</a>
                @endforeach
            </nav>
            <div>
                @foreach($groups as $gi => $g)
                    @php
                        $items = $g['features'] ?? [];
                        if (is_string($items)) { $d = json_decode($items, true); $items = is_array($d) ? $d : []; }
                        if (! is_array($items)) $items = [];
                    @endphp
                    <div class="fgx-group" id="{{ $fgxId }}-{{ $gi }}" data-fgx-group="{{ $gi }}">
                        @if(!empty($g['label']))<div class="fgx-k">{{ $g['label'] }}</div>@endif
                        @if(!empty($g['heading']))<h3 class="fgx-h">{{ $g['heading'] }}</h3>@endif
                        @if(!empty($g['lead']))<p class="fgx-lead">{{ $g['lead'] }}</p>@endif
                        @if($items)
                            <div class="fgx-grid">
                                @foreach($items as $it)
                                    @if(is_array($it) && trim((string) ($it['title'] ?? '')) !== '')
                                        <div class="fgx-item"><b>{{ $it['title'] }}</b>@if(!empty($it['body']))<span>{{ $it['body'] }}</span>@endif</div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    var root = document.querySelector('.{{ $fgxId }}');
    if (!root || !('IntersectionObserver' in window)) { return; }
    var links = root.querySelectorAll('[data-fgx-link]');
    var set = function (i) {
        links.forEach(function (a) {
            var on = a.getAttribute('data-fgx-link') === String(i);
            a.classList.toggle('is-on', on);
            if (on && a.parentNode.scrollWidth > a.parentNode.clientWidth) {
                a.parentNode.scrollTo({ left: a.offsetLeft - 24, behavior: 'smooth' });
            }
        });
    };
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { set(e.target.getAttribute('data-fgx-group')); } });
    }, { rootMargin: '-35% 0px -60% 0px' });
    root.querySelectorAll('[data-fgx-group]').forEach(function (g) { io.observe(g); });
})();
</script>
