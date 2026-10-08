@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
    feature groups with an index.
    Content: eyebrow, heading, subheading,
             groups[{label, heading, lead, features[{title, body}]}]

    Desktop: the group labels sit in a column on the left and stay on screen
    while the groups scroll past; the one in view is highlighted; a click
    jumps to it. Phones: the index is a row of chips that sticks under the
    site's top bar and scrolls sideways. No divider lines anywhere.
--}}
@php
    $groups = $c['groups'] ?? [];
    if (is_string($groups)) {
        $decoded = json_decode($groups, true);
        $groups = is_array($decoded) ? $decoded : [];
    }
    $groups = array_values(array_filter($groups, fn ($g) => is_array($g) && trim((string) ($g['label'] ?? $g['heading'] ?? '')) !== ''));
    $fgxId = 'fgx-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
@endphp

<style>
    .{{ $fgxId }} .fgx-layout { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: clamp(32px, 5vw, 64px); align-items: start; }
    .{{ $fgxId }} .fgx-index { position: sticky; top: 84px; display: grid; gap: 2px; }
    .{{ $fgxId }} .fgx-index a { display: block; padding: 8px 12px; border-radius: 8px; font-size: 14px; color: var(--mk-muted); text-decoration: none; transition: background .15s, color .15s; }
    .{{ $fgxId }} .fgx-index a:hover { color: var(--mk-text); }
    .{{ $fgxId }} .fgx-index a.is-on { background: var(--mk-bg2); color: var(--mk-text); }
    .{{ $fgxId }} .fgx-group { scroll-margin-top: 84px; padding-bottom: clamp(48px, 6vw, 80px); }
    .{{ $fgxId }} .fgx-group:last-child { padding-bottom: 0; }
    .{{ $fgxId }} .fgx-k { font-size: 13px; font-weight: 600; color: var(--mk-accent); margin-bottom: 8px; }
    .{{ $fgxId }} .fgx-h { font-size: clamp(26px, 3.2vw, 40px); font-weight: 800; letter-spacing: -.03em; line-height: 1.05; margin: 0 0 12px; }
    .{{ $fgxId }} .fgx-lead { font-size: 16px; color: var(--mk-muted); line-height: 1.65; max-width: 620px; margin: 0 0 24px; }
    .{{ $fgxId }} .fgx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .{{ $fgxId }} .fgx-item { background: rgba(255,255,255,.03); border: 0.5px solid var(--mk-border); border-radius: var(--mk-r-lg); padding: 20px; }
    .{{ $fgxId }} .fgx-item b { display: block; font-size: 15.5px; margin-bottom: 4px; }
    .{{ $fgxId }} .fgx-item span { font-size: 14px; color: var(--mk-muted); line-height: 1.55; }
    @media (max-width: 860px) {
        .{{ $fgxId }} .fgx-layout { grid-template-columns: 1fr; gap: 20px; }
        .{{ $fgxId }} .fgx-index { top: 60px; z-index: 5; display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none;
            margin: 0 calc(-1 * var(--mk-gutter)); padding: 10px var(--mk-gutter); background: rgba(12,12,12,.92); backdrop-filter: blur(12px); }
        .{{ $fgxId }} .fgx-index::-webkit-scrollbar { display: none; }
        .{{ $fgxId }} .fgx-index a { flex: none; border: 0.5px solid var(--mk-border2); border-radius: 99px; padding: 7px 13px; font-size: 13px; }
        .{{ $fgxId }} .fgx-index a.is-on { background: var(--mk-accent); color: var(--mk-accent-text); border-color: var(--mk-accent); }
        .{{ $fgxId }} .fgx-grid { grid-template-columns: 1fr; }
        .{{ $fgxId }} .fgx-group { scroll-margin-top: 120px; }
    }
</style>

<section class="mk-section {{ $bgId }} {{ $fgxId }}" @if(!empty($c['anchor_id'])) id="{{ $c['anchor_id'] }}" @endif>
    <div class="mk-container">
        @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
            <div style="margin-bottom: clamp(28px, 4vw, 48px)">
                @if(!empty($c['eyebrow']))<div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>@endif
                @if(!empty($c['heading']))<h2 class="mk-section-title">{{ $c['heading'] }}</h2>@endif
                @if(!empty($c['subheading']))<p class="mk-section-sub">{{ $c['subheading'] }}</p>@endif
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
                    @endphp
                    <div class="fgx-group" id="{{ $fgxId }}-{{ $gi }}" data-fgx-group="{{ $gi }}">
                        @if(!empty($g['label']))<div class="fgx-k">{{ $g['label'] }}</div>@endif
                        @if(!empty($g['heading']))<h2 class="fgx-h">{{ $g['heading'] }}</h2>@endif
                        @if(!empty($g['lead']))<p class="fgx-lead">{{ $g['lead'] }}</p>@endif
                        @if($items)
                            <div class="fgx-grid">
                                @foreach($items as $it)
                                    @if(trim((string) ($it['title'] ?? '')) !== '')
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
