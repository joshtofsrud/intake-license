{{--
    Sticky nav — part of the marketing shell, rendered once per page.
    Not an editable section block. If tenants want to tweak marketing
    nav links, that's the TenantNavItems table (editable via the same
    nav-items UI used for tenant sites; for the platform tenant this
    is editable from the page editor's nav editor panel).
--}}
<style>
    .mk-nav {
        position: sticky; top: 0; z-index: 100;
        background: rgba(12,12,12,.92);
        backdrop-filter: blur(12px);
        border-bottom: 0.5px solid var(--mk-border);
    }
    .mk-nav-inner {
        max-width: var(--mk-max);
        margin: 0 auto;
        padding: 0 var(--mk-gutter);
        height: 60px;
        display: flex;
        align-items: center;
        gap: 32px;
    }
    .mk-nav-links { display: flex; align-items: center; gap: 2px; flex: 1; }
    .mk-nav-link {
        padding: 6px 14px;
        font-size: 14px;
        color: var(--mk-muted);
        border-radius: 6px;
        transition: color .12s, background .12s;
    }
    .mk-nav-link:hover { color: var(--mk-text); }
    .mk-nav-link.active { color: var(--mk-text); }
    .mk-nav-end { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .mk-nav-signin { font-size: 14px; color: var(--mk-muted); padding: 6px 14px; transition: color .12s; }
    .mk-nav-signin:hover { color: var(--mk-text); }

    .mk-hamburger {
        display: none;
        background: none; border: none;
        padding: 4px;
        flex-direction: column; gap: 5px;
        margin-left: auto;
    }
    .mk-hamburger span {
        display: block; width: 20px; height: 1.5px;
        background: var(--mk-text); border-radius: 2px;
    }
    .mk-mobile-nav {
        display: none;
        flex-direction: column; gap: 2px;
        padding: 12px var(--mk-gutter) 16px;
        border-top: 0.5px solid var(--mk-border);
        background: rgba(12,12,12,.96);
    }
    .mk-mobile-nav.open { display: flex; }
    .mk-mobile-nav a {
        padding: 10px 0;
        font-size: 15px;
        color: var(--mk-muted);
        border-bottom: 0.5px solid var(--mk-border);
    }

    @media (max-width: 860px) {
        .mk-nav-links, .mk-nav-end .mk-nav-signin { display: none; }
        .mk-hamburger { display: flex; }
    }

    /* MARKER-MKT-NAV-FLOAT — "Floating" header style (settings arrive as CSS variables) */
    .mk-nav.is-float { background: transparent; border-bottom: 0; backdrop-filter: none; -webkit-backdrop-filter: none; padding: 14px var(--mk-gutter) 0; }
    .mk-nav.is-float .mk-nav-inner {
        background: color-mix(in srgb, var(--mkf-bg) var(--mkf-op), transparent);
        backdrop-filter: blur(var(--mkf-blur)); -webkit-backdrop-filter: blur(var(--mkf-blur));
        border: 0.5px solid rgba(255,255,255,.08); border-radius: 999px;
        padding: 10px 12px 10px 22px; height: auto;
    }
    .mk-nav.is-float .mk-nav-links { flex: 0 1 auto; margin: 0 auto; background: var(--mkf-pill); border-radius: 999px; padding: 4px; gap: 2px; }
    .mk-nav.is-float .mk-nav-link { padding: 7px 14px; border-radius: 999px; }
    .mk-nav.is-float .mk-nav-link.active { background: rgba(255,255,255,.08); }
    .mk-nav.is-float .mk-btn { border-radius: 999px; }
    .mk-nav.is-float .mk-mobile-nav { margin: 8px auto 0; max-width: var(--mk-max); border-radius: 20px; border: 0.5px solid rgba(255,255,255,.08); background: color-mix(in srgb, var(--mkf-bg) 96%, transparent); }
    @media (max-width: 860px) { .mk-nav.is-float { padding: 10px 12px 0; } .mk-nav.is-float .mk-nav-inner { padding: 8px 8px 8px 16px; } }

    /* MARKER-MKT-FLOAT-OVERLAP — the first section runs up behind the floating bar:
       the header gives back its height, and that section gains the same space as a
       transparent top border its background paints under. */
    .mk-nav.is-float { margin-bottom: calc(-1 * var(--mkf-h, 76px)); }

    /* MARKER-MKT-NAV-POLISH — spacing presets, link color, glass phone menu */
    .mk-nav.is-float { padding-top: var(--mkf-out, 18px); }
    .mk-nav.is-float .mk-nav-inner { padding: var(--mkf-pad, 12px 14px 12px 24px); }
    .mk-nav.has-link .mk-nav-link, .mk-nav.has-link .mk-nav-signin, .mk-nav.has-link .mk-mobile-nav a { color: var(--mkf-link); opacity: .78; }
    .mk-nav.has-link .mk-nav-link:hover, .mk-nav.has-link .mk-nav-link.active, .mk-nav.has-link .mk-nav-signin:hover { opacity: 1; }
    .mk-nav.has-link .mk-hamburger span { background: var(--mkf-link); }
    .mk-nav.is-float .mk-mobile-nav {
        position: absolute; left: 12px; right: 12px; top: calc(100% + 8px); margin: 0;
        background: color-mix(in srgb, var(--mkf-bg) var(--mkf-op), transparent);
        backdrop-filter: blur(var(--mkf-blur)); -webkit-backdrop-filter: blur(var(--mkf-blur));
        border: 0.5px solid rgba(255,255,255,.12); border-radius: 20px; box-shadow: 0 18px 40px rgba(0,0,0,.35);
    }
    .mk-nav.is-float .mk-mobile-nav a { border-color: rgba(127,127,127,.18); }
    @media (max-width: 860px) {
        .mk-nav.is-float { padding: var(--mkf-out-m, 10px) 12px 0; }
        .mk-nav.is-float .mk-nav-inner { padding: var(--mkf-pad-m, 8px 8px 8px 16px); }
    }

    /* MARKER-MKT-HAMBURGER — three lines morph into an X */
    .mk-hamburger { cursor: pointer; transition: transform .2s ease; }
    .mk-hamburger:hover { transform: scale(1.06); }
    .mk-hamburger span { transition: transform .25s ease, opacity .2s ease; }
    .mk-hamburger.is-open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
    .mk-hamburger.is-open span:nth-child(2) { opacity: 0; }
    .mk-hamburger.is-open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }
    @media (prefers-reduced-motion: reduce) { .mk-hamburger, .mk-hamburger span { transition: none; } }
    /* MARKER-MKT-MENU-GROUPS — dropdown panels (desktop) and accordion (phone) */
    .mk-dd { position: relative; }
    .mk-dd-btn { display: inline-flex; align-items: center; gap: 6px; background: none; border: 0; font: inherit; cursor: pointer; }
    .mk-dd-btn svg { transition: transform .2s; opacity: .7; }
    .mk-dd.on .mk-dd-btn svg { transform: rotate(180deg); }
    .mk-dd-panel { position: absolute; top: calc(100% + 14px); left: 50%; z-index: 60; transform: translateX(-50%) translateY(-6px); opacity: 0; visibility: hidden; pointer-events: none;
        transition: opacity .18s ease, transform .18s ease, visibility .18s; display: flex; gap: 10px; padding: 10px; border-radius: 18px; width: max-content; max-width: min(720px, 92vw);
        background: color-mix(in srgb, var(--mkf-menu-bg, var(--mk-bg, #0c0c0c)) 94%, transparent); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
        border: .5px solid rgba(255,255,255,.12); box-shadow: 0 30px 60px rgba(0,0,0,.45); }
    .mk-dd-panel::before { content: ''; position: absolute; left: 0; right: 0; top: -16px; height: 16px; }
    .mk-dd.on .mk-dd-panel { opacity: 1; visibility: visible; transform: translateX(-50%); pointer-events: auto; }
    .mk-dd-items { display: grid; grid-template-columns: 1fr; gap: 2px; min-width: 250px; }
    .mk-dd-items.is-two { grid-template-columns: 1fr 1fr; width: 470px; }
    .mk-dd-item, .mk-mg-item { display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; text-decoration: none; color: var(--mk-text); }
    .mk-dd-item:hover, .mk-dd-item:focus-visible { background: rgba(255,255,255,.06); outline: 0; }
    .mk-dd-ic { flex: none; width: 32px; height: 32px; border-radius: 9px; background: color-mix(in srgb, var(--mk-accent) 12%, transparent); color: var(--mk-accent); display: flex; align-items: center; justify-content: center; }
    .mk-dd-ic svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    .mk-dd-item b, .mk-mg-item b { display: block; font-size: 14px; font-weight: 600; color: var(--mk-text); }
    .mk-dd-item small, .mk-mg-item small { display: block; font-size: 12.5px; color: var(--mk-muted); margin-top: 2px; line-height: 1.4; }
    .mk-dd-feat { position: relative; width: 190px; border-radius: 12px; padding: 14px; text-decoration: none; display: flex; flex-direction: column; gap: 6px;
        background: linear-gradient(160deg, color-mix(in srgb, var(--mk-accent) 16%, transparent), color-mix(in srgb, var(--mk-accent) 4%, transparent)); }
    .mk-dd-feat b { color: var(--mk-text); font-size: 14.5px; margin-top: auto; }
    .mk-dd-feat small { color: var(--mk-muted); font-size: 12.5px; line-height: 1.4; padding-right: 14px; }
    .mk-dd-feat i { position: absolute; right: 14px; bottom: 14px; font-style: normal; color: var(--mk-accent); }
    .mk-mg summary { list-style: none; display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 12px 4px; font-weight: 600; color: var(--mkf-menu-link, var(--mkf-link, var(--mk-text))); }
    .mk-mg summary::-webkit-details-marker { display: none; }
    .mk-mg summary i { font-style: normal; color: var(--mk-accent); font-size: 20px; transition: transform .2s; }
    .mk-mg[open] summary i { transform: rotate(45deg); }
    .mk-mg-in { display: grid; gap: 2px; padding-bottom: 6px; }
    #mk-nav .mk-mobile-nav .mk-mg-item { border: 0; padding: 9px 6px; }
    .mk-mg-feat b { color: var(--mk-accent) !important; }
</style>

{{-- MARKER-MKT-NAV — drawn from master admin › Site & content › Navigation
     (App\Support\MarketingNav). The only source: no hard-coded links or
     buttons. $menuItems is passed only by the Navigation page's preview.
     MARKER-MKT-LOGO — the Brand page's logo, sized by height only. --}}
@php
    $mkMenu  = isset($menuItems) && is_array($menuItems) ? $menuItems : \App\Support\MarketingNav::items();
    $mkHead  = \App\Support\MarketingNav::header(isset($menuHeader) && is_array($menuHeader) ? $menuHeader : null); // MARKER-MKT-NAV-FLOAT
    // MARKER-MKT-NAV-POLISH
    $mkSpace = ['tight'  => ['10px', '8px 10px 8px 18px',   '8px',  '6px 6px 6px 14px'],
                'normal' => ['18px', '12px 14px 12px 24px', '10px', '8px 8px 8px 16px'],
                'roomy'  => ['26px', '16px 18px 16px 30px', '14px', '10px 10px 10px 18px']][$mkHead['space']];
    $mkLink  = \App\Support\MarketingNav::linkColour($mkHead);
    // MARKER-MKT-NAV-PHONE
    $mkHeadP  = \App\Support\MarketingNav::phoneHeader($mkHead);
    $mkSpaceP = ['tight'  => ['8px',  '6px 6px 6px 14px'],
                 'normal' => ['10px', '8px 8px 8px 16px'],
                 'roomy'  => ['14px', '10px 10px 10px 18px']][$mkHeadP['space']];
    $mkLinkP  = \App\Support\MarketingNav::linkColour($mkHeadP);
    // MARKER-MKT-NAV-EDGEROOM — a chosen edge room replaces the preset's left/right padding.
    $mkEdge   = function ($pad, $x) { if (! $x) return $pad; $p = explode(' ', $pad); $p[1] = $p[3] = $x . 'px'; return implode(' ', $p); };
    $mkSpace[1]  = $mkEdge($mkSpace[1], $mkHead['pad_x']);
    $mkSpaceP[1] = $mkEdge($mkSpaceP[1], $mkHeadP['pad_x']);
    // MARKER-MKT-NAV-TOP — a chosen distance from the top replaces the preset's
    if (($mkHead['top_gap'] ?? -1) >= 0)  $mkSpace[0]  = $mkHead['top_gap'] . 'px';
    if (($mkHeadP['top_gap'] ?? -1) >= 0) $mkSpaceP[0] = $mkHeadP['top_gap'] . 'px';
    $mkVars   = fn ($h, $out, $pad, $link) => '--mkf-bg:' . $h['bg'] . ';--mkf-op:' . $h['opacity'] . '%;--mkf-blur:' . $h['blur'] . 'px;'
        . '--mkf-pill:color-mix(in srgb, ' . $h['pill'] . ' ' . $h['pill_strength'] . '%, transparent);'
        . '--mkf-out:' . $out . ';--mkf-pad:' . $pad . ';--mkf-link:' . ($link ?: 'var(--mk-muted)') . ';'
    . '--mkf-fade:' . (! empty($h['fade']) ? '1' : '0') . ';' // MARKER-MKT-NAV-EDGE
    // MARKER-MKT-NAV-BUTTONS / MENU — unset values fall back ("initial" makes var() use its fallback)
    . '--mkf-btn-text:' . ($h['btn_text'] ?: 'initial') . ';--mkf-btn-fill:' . ($h['btn_fill'] ?: 'initial') . ';'
    . '--mkf-btn-dist:' . (int) $h['btn_dist'] . 'px;'
    . '--mkf-menu-bg:' . ($h['menu_bg'] ?: 'initial') . ';--mkf-menu-link:' . ($h['menu_link'] ?: 'initial') . ';';
    $mkHere  = '/' . ltrim(request()->path(), '/');
    $mkLeft  = array_values(array_filter($mkMenu, fn ($i) => $i['side'] === 'left'));
    $mkRight = array_values(array_filter($mkMenu, fn ($i) => $i['side'] === 'right'));
    // MARKER-MKT-MENU-GROUPS
    $mkGroups = isset($menuGroups) && is_array($menuGroups) ? $menuGroups : \App\Support\MarketingNav::menuGroups();
    $mkBar    = \App\Support\MarketingNav::structure($mkLeft, $mkGroups);
    $mkClass = function (array $i, string $where) use ($mkHere): string {
        if ($i['style'] === 'button')  return 'mk-btn mk-btn--primary mk-btn--sm';
        if ($i['style'] === 'outline') return 'mk-btn mk-btn--ghost mk-btn--sm';
        if ($where === 'right')        return 'mk-nav-signin';
        $path = rtrim((string) parse_url($i['url'], PHP_URL_PATH), '/') ?: '/';
        return 'mk-nav-link' . ($path === (rtrim($mkHere, '/') ?: '/') && ! parse_url($i['url'], PHP_URL_HOST) ? ' active' : '');
    };
@endphp
<style>
    /* MARKER-MKT-NAV-EDGE — clear the phone's status bar; soft fade behind the bar */
    #mk-nav { padding-top: env(safe-area-inset-top, 0px); }
    #mk-nav.is-float { padding-top: calc(var(--mkf-out) + env(safe-area-inset-top, 0px)) !important; }
    #mk-nav.is-float::before {
        content: ''; position: absolute; left: 0; right: 0; top: 0; height: calc(100% + 28px);
        pointer-events: none; z-index: -1; opacity: var(--mkf-fade, 0); transition: opacity .2s;
        background: linear-gradient(to bottom, color-mix(in srgb, var(--mk-bg, #0a0a0a) 55%, transparent), transparent);
        backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        mask-image: linear-gradient(to bottom, #000 45%, transparent); -webkit-mask-image: linear-gradient(to bottom, #000 45%, transparent);
    }
    /* MARKER-MKT-NAV-BUTTONS — button colors, same in the bar and the ☰ menu */
    #mk-nav .mk-btn--primary { background: var(--mkf-btn-fill, var(--mk-accent)); color: var(--mkf-btn-text, var(--mk-accent-text)); }
    #mk-nav .mk-btn--ghost { color: var(--mkf-btn-text, var(--mk-text)); border-color: var(--mkf-btn-fill, rgba(255,255,255,.2)); }
    /* MARKER-MKT-NAV-MENU — the phone menu's own colors */
    #mk-nav .mk-mobile-nav a:not(.mk-btn) { color: var(--mkf-menu-link, var(--mkf-link, var(--mk-muted))); }
    #mk-nav .mk-mobile-nav { background-color: var(--mkf-menu-bg, var(--mk-bg, #0c0c0c)); }
    #mk-nav.is-float .mk-mobile-nav { background: color-mix(in srgb, var(--mkf-menu-bg, var(--mkf-bg)) var(--mkf-op), transparent); }
    /* MARKER-MKT-NAV-BTNPOS — where the header buttons sit on phones */
    #mk-nav .mk-mobile-cta { display: none; }
    @media (max-width: 860px) {
        /* MARKER-MKT-NAV-BUTTONS — in the bar, a set distance from ☰ */
        /* MARKER-MKT-NAV-BTNDIST-V2 — logo left, button + ☰ right, slider = gap between them */
        #mk-nav[data-btnpos="bar"] .mk-nav-inner { gap: 0 !important; }
        #mk-nav[data-btnpos="bar"] .mk-logo { margin-right: auto !important; }
        #mk-nav[data-btnpos="bar"] .mk-nav-end { margin-left: 0 !important; margin-right: 0 !important; }
        #mk-nav[data-btnpos="bar"] .mk-hamburger { margin-left: calc(6px + var(--mkf-btn-dist, 0px)) !important; }
        #mk-nav[data-btnpos="menu"] .mk-nav-end .mk-btn { display: none; }
        #mk-nav[data-btnpos="menu"] .mk-mobile-cta { display: flex; justify-content: center; width: 100%; box-sizing: border-box; margin: 4px 0 8px; border-bottom: 0; }
    }
    /* MARKER-MKT-NAV-PHONE — desktop and phone header settings */
    #mk-nav { {{ $mkVars($mkHead, $mkSpace[0], $mkSpace[1], $mkLink) }} }
    @media (max-width: 860px) {
        #mk-nav { {{ $mkVars($mkHeadP, $mkSpaceP[0], $mkSpaceP[1], $mkLinkP) }} }
        #mk-nav.is-float { padding: var(--mkf-out) 12px 0; }
        #mk-nav.is-float .mk-nav-inner { padding: var(--mkf-pad); }
    }
</style>
<nav id="mk-nav" class="mk-nav{{ $mkHead['style'] === 'float' ? ' is-float' : '' }}{{ ($mkLink || $mkLinkP) ? ' has-link' : '' }}"
     data-style-desktop="{{ $mkHead['style'] }}" data-style-phone="{{ $mkHeadP['style'] }}" data-btnpos="{{ $mkHeadP['btn_pos'] === 'menu' ? 'menu' : 'bar' }}">
    <div class="mk-nav-inner">
        <a href="{{ route('marketing.home') }}" class="mk-logo">
            <img src="{{ \App\Support\Brand::url('logo') }}" alt="Intake" style="display:block;height:26px;width:auto">
        </a>

        <div class="mk-nav-links">
            {{-- MARKER-MKT-MENU-GROUPS — plain links and grouped dropdowns --}}
            @foreach($mkBar as $mkB)
                @if($mkB['kind'] === 'link')
                    @php $i = $mkB['item']; @endphp
                    <a href="{{ $i['url'] }}" class="{{ $mkClass($i, 'left') }}" @if($i['tab']) target="_blank" rel="noopener" @endif>{{ $i['label'] }}</a>
                @else
                    @php $g = $mkB['group']; @endphp
                    <div class="mk-dd">
                        <button type="button" class="mk-nav-link mk-dd-btn" aria-expanded="false" aria-haspopup="true">{{ $g['title'] }}<svg viewBox="0 0 12 12" width="10" height="10" aria-hidden="true"><path d="m3 4.5 3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button>
                        <div class="mk-dd-panel{{ $g['feature'] ? ' has-feat' : '' }}" role="menu">
                            <div class="mk-dd-items{{ count($g['items']) > 3 ? ' is-two' : '' }}">
                                @foreach($g['items'] as $i)
                                    <a href="{{ $i['url'] }}" class="mk-dd-item" role="menuitem" @if($i['tab']) target="_blank" rel="noopener" @endif>
                                        @if(($i['icon'] ?? '') !== '')<span class="mk-dd-ic"><svg viewBox="0 0 24 24" aria-hidden="true">{!! \App\Support\MarketingNav::ICONS[$i['icon']] !!}</svg></span>@endif
                                        <span><b>{{ $i['label'] }}</b>@if(($i['desc'] ?? '') !== '')<small>{{ $i['desc'] }}</small>@endif</span>
                                    </a>
                                @endforeach
                            </div>
                            @if($g['feature'])
                                <a href="{{ $g['feature']['url'] }}" class="mk-dd-feat" role="menuitem">
                                    <span class="mk-dd-ic"><svg viewBox="0 0 24 24" aria-hidden="true">{!! \App\Support\MarketingNav::ICONS[$g['feature']['icon']] !!}</svg></span>
                                    <b>{{ $g['feature']['label'] }}</b>
                                    @if($g['feature']['desc'] !== '')<small>{{ $g['feature']['desc'] }}</small>@endif
                                    <i aria-hidden="true">→</i>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="mk-nav-end">
            @foreach($mkRight as $i)
                <a href="{{ $i['url'] }}" class="{{ $mkClass($i, 'right') }}" @if($i['tab']) target="_blank" rel="noopener" @endif>{{ $i['label'] }}</a>
            @endforeach
        </div>

        <button class="mk-hamburger" type="button" aria-label="Menu" aria-expanded="false" aria-controls="mk-mobile-nav"
                onclick="this.classList.toggle('is-open'); this.setAttribute('aria-expanded', this.classList.contains('is-open')); toggleMobileNav()">
            <span></span><span></span><span></span>
        </button>
    </div>

    {{-- Phone: links (and right-side links) live in the panel; right-side
         buttons stay in the bar above. --}}
    <div class="mk-mobile-nav" id="mk-mobile-nav">
        {{-- MARKER-MKT-NAV-BTNPOS — the header buttons, shown here when "In menu only" --}}
        @foreach($mkRight as $i)
            @if($i['style'] !== 'link')
                <a href="{{ $i['url'] }}" class="mk-btn {{ $i['style'] === 'button' ? 'mk-btn--primary' : 'mk-btn--ghost' }} mk-mobile-cta" @if($i['tab']) target="_blank" rel="noopener" @endif>{{ $i['label'] }}</a>
            @endif
        @endforeach
        {{-- MARKER-MKT-MENU-GROUPS — groups as an accordion, plain links as rows --}}
        @foreach($mkBar as $mkB)
            @if($mkB['kind'] === 'link')
                <a href="{{ $mkB['item']['url'] }}" @if($mkB['item']['tab']) target="_blank" rel="noopener" @endif>{{ $mkB['item']['label'] }}</a>
            @else
                @php $g = $mkB['group']; @endphp
                <details class="mk-mg">
                    <summary>{{ $g['title'] }}<i aria-hidden="true">+</i></summary>
                    <div class="mk-mg-in">
                        @foreach($g['items'] as $i)
                            <a href="{{ $i['url'] }}" class="mk-mg-item" @if($i['tab']) target="_blank" rel="noopener" @endif>
                                @if(($i['icon'] ?? '') !== '')<span class="mk-dd-ic"><svg viewBox="0 0 24 24" aria-hidden="true">{!! \App\Support\MarketingNav::ICONS[$i['icon']] !!}</svg></span>@endif
                                <span><b>{{ $i['label'] }}</b>@if(($i['desc'] ?? '') !== '')<small>{{ $i['desc'] }}</small>@endif</span>
                            </a>
                        @endforeach
                        @if($g['feature'])<a href="{{ $g['feature']['url'] }}" class="mk-mg-item mk-mg-feat"><span><b>{{ $g['feature']['label'] }} →</b>@if($g['feature']['desc'] !== '')<small>{{ $g['feature']['desc'] }}</small>@endif</span></a>@endif
                    </div>
                </details>
            @endif
        @endforeach
        @foreach($mkRight as $i)
            @if($i['style'] === 'link')
                <a href="{{ $i['url'] }}" @if($i['tab']) target="_blank" rel="noopener" @endif>{{ $i['label'] }}</a>
            @endif
        @endforeach
    </div>
</nav>
{{-- MARKER-MKT-NAV-POLISH — measure the floating bar so the page tucks under it exactly. --}}
<script>
(function () {
    var nav = document.getElementById('mk-nav');
    if (!nav) return;
    var inner = nav.querySelector('.mk-nav-inner');
    var phone = window.matchMedia('(max-width: 860px)');
    // MARKER-MKT-NAV-PHONE — desktop and phone can use different styles.
    function apply() {
        var style = phone.matches ? nav.dataset.stylePhone : nav.dataset.styleDesktop;
        nav.classList.toggle('is-float', style === 'float');
        fit();
    }
    function fit() {
        if (!nav.classList.contains('is-float')) { nav.style.removeProperty('--mkf-h'); return; }
        nav.style.setProperty('--mkf-h', (inner.offsetHeight + parseFloat(getComputedStyle(nav).paddingTop)) + 'px');
    }
    apply();
    if (phone.addEventListener) phone.addEventListener('change', apply); else phone.addListener(apply);
    window.addEventListener('resize', fit); window.addEventListener('load', fit);
})();
</script>
<script>
/* MARKER-MKT-MENU-GROUPS — dropdowns: hover or click, Esc / outside click closes; phone accordion one-at-a-time */
(function () {
  var dds = Array.prototype.slice.call(document.querySelectorAll('#mk-nav .mk-dd'));
  if (!dds.length && !document.querySelector('#mk-nav .mk-mg')) return;
  var hover = window.matchMedia('(hover: hover)'), t = null;
  function close(except) { dds.forEach(function (d) { if (d !== except) { d.classList.remove('on'); d.querySelector('.mk-dd-btn').setAttribute('aria-expanded', 'false'); } }); }
  function open(d) { close(d); d.classList.add('on'); d.querySelector('.mk-dd-btn').setAttribute('aria-expanded', 'true'); }
  dds.forEach(function (d) {
    var b = d.querySelector('.mk-dd-btn');
    b.addEventListener('click', function (e) { e.preventDefault(); d.classList.contains('on') ? close() : open(d); });
    d.addEventListener('mouseenter', function () { if (hover.matches) { clearTimeout(t); open(d); } });
    d.addEventListener('mouseleave', function () { if (hover.matches) { t = setTimeout(function () { close(); }, 180); } });
  });
  document.addEventListener('click', function (e) { if (!e.target.closest || !e.target.closest('#mk-nav .mk-dd')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.querySelectorAll('#mk-nav .mk-mg').forEach(function (g, i, all) {
    g.addEventListener('toggle', function () { if (g.open) all.forEach(function (o) { if (o !== g) o.open = false; }); });
  });
})();
</script>
