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
    .mk-nav.is-float { --mkf-h: 76px; margin-bottom: calc(-1 * var(--mkf-h)); }
    .mk-nav.is-float ~ .mkw-first > section,
    .mk-nav.is-float ~ * .mkw-first > section { border-top: var(--mkf-h) solid transparent !important; background-origin: border-box !important; }
    @media (max-width: 860px) { .mk-nav.is-float { --mkf-h: 62px; } }

    /* MARKER-MKT-HAMBURGER — three lines morph into an X */
    .mk-hamburger { cursor: pointer; transition: transform .2s ease; }
    .mk-hamburger:hover { transform: scale(1.06); }
    .mk-hamburger span { transition: transform .25s ease, opacity .2s ease; }
    .mk-hamburger.is-open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
    .mk-hamburger.is-open span:nth-child(2) { opacity: 0; }
    .mk-hamburger.is-open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }
    @media (prefers-reduced-motion: reduce) { .mk-hamburger, .mk-hamburger span { transition: none; } }
</style>

{{-- MARKER-MKT-NAV — drawn from master admin › Site & content › Navigation
     (App\Support\MarketingNav). The only source: no hard-coded links or
     buttons. $menuItems is passed only by the Navigation page's preview.
     MARKER-MKT-LOGO — the Brand page's logo, sized by height only. --}}
@php
    $mkMenu  = isset($menuItems) && is_array($menuItems) ? $menuItems : \App\Support\MarketingNav::items();
    $mkHead  = \App\Support\MarketingNav::header(isset($menuHeader) && is_array($menuHeader) ? $menuHeader : null); // MARKER-MKT-NAV-FLOAT
    $mkHere  = '/' . ltrim(request()->path(), '/');
    $mkLeft  = array_values(array_filter($mkMenu, fn ($i) => $i['side'] === 'left'));
    $mkRight = array_values(array_filter($mkMenu, fn ($i) => $i['side'] === 'right'));
    $mkClass = function (array $i, string $where) use ($mkHere): string {
        if ($i['style'] === 'button')  return 'mk-btn mk-btn--primary mk-btn--sm';
        if ($i['style'] === 'outline') return 'mk-btn mk-btn--ghost mk-btn--sm';
        if ($where === 'right')        return 'mk-nav-signin';
        $path = rtrim((string) parse_url($i['url'], PHP_URL_PATH), '/') ?: '/';
        return 'mk-nav-link' . ($path === (rtrim($mkHere, '/') ?: '/') && ! parse_url($i['url'], PHP_URL_HOST) ? ' active' : '');
    };
@endphp
<nav class="mk-nav{{ $mkHead['style'] === 'float' ? ' is-float' : '' }}" style="--mkf-bg:{{ $mkHead['bg'] }};--mkf-op:{{ $mkHead['opacity'] }}%;--mkf-blur:{{ $mkHead['blur'] }}px;--mkf-pill:color-mix(in srgb, {{ $mkHead['pill'] }} {{ $mkHead['pill_strength'] }}%, transparent)">
    <div class="mk-nav-inner">
        <a href="{{ route('marketing.home') }}" class="mk-logo">
            <img src="{{ \App\Support\Brand::url('logo') }}" alt="Intake" style="display:block;height:26px;width:auto">
        </a>

        <div class="mk-nav-links">
            @foreach($mkLeft as $i)
                <a href="{{ $i['url'] }}" class="{{ $mkClass($i, 'left') }}" @if($i['tab']) target="_blank" rel="noopener" @endif>{{ $i['label'] }}</a>
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
        @foreach($mkMenu as $i)
            @if($i['side'] === 'left' || $i['style'] === 'link')
                <a href="{{ $i['url'] }}" @if($i['tab']) target="_blank" rel="noopener" @endif>{{ $i['label'] }}</a>
            @endif
        @endforeach
    </div>
</nav>
