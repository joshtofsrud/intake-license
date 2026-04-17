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
</style>

<nav class="mk-nav">
    <div class="mk-nav-inner">
        <a href="{{ route('marketing.home') }}" class="mk-logo">
            <div class="mk-logo-mark">I</div>
            intake
        </a>

        <div class="mk-nav-links">
            @if(count($navItems))
                @foreach($navItems as $item)
                    <a href="{{ $item->url }}" class="mk-nav-link">{{ $item->label }}</a>
                @endforeach
            @else
                {{-- Sensible defaults until someone edits nav in the admin --}}
                <a href="{{ route('marketing.features') }}" class="mk-nav-link {{ request()->routeIs('marketing.features') ? 'active' : '' }}">Features</a>
                <a href="{{ route('marketing.pricing') }}"  class="mk-nav-link {{ request()->routeIs('marketing.pricing')  ? 'active' : '' }}">Pricing</a>
                <a href="{{ route('marketing.docs') }}"     class="mk-nav-link {{ request()->routeIs('marketing.docs')     ? 'active' : '' }}">Docs</a>
            @endif
        </div>

        <div class="mk-nav-end">
            <a href="{{ route('platform.login') }}"  class="mk-nav-signin">Sign in</a>
            <a href="{{ route('platform.signup') }}" class="mk-btn mk-btn--primary mk-btn--sm">Start free trial</a>
        </div>

        <button class="mk-hamburger" onclick="toggleMobileNav()" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
    </div>

    <div class="mk-mobile-nav" id="mk-mobile-nav">
        @if(count($navItems))
            @foreach($navItems as $item)
                <a href="{{ $item->url }}">{{ $item->label }}</a>
            @endforeach
        @else
            <a href="{{ route('marketing.features') }}">Features</a>
            <a href="{{ route('marketing.pricing') }}">Pricing</a>
            <a href="{{ route('marketing.docs') }}">Docs</a>
        @endif
        <a href="{{ route('platform.login') }}">Sign in</a>
        <a href="{{ route('platform.signup') }}" style="color:var(--mk-accent);margin-top:4px">Start free trial →</a>
    </div>
</nav>
