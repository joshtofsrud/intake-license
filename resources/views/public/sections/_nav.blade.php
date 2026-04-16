<style>
.p-nav {
  position: sticky;
  top: 0;
  z-index: 100;
  background: {{ ($c['bg_style'] ?? 'solid') === 'transparent' ? 'transparent' : 'var(--p-bg)' }};
  border-bottom: {{ ($c['bg_style'] ?? 'solid') === 'transparent' ? 'none' : '1px solid rgba(0,0,0,.07)' }};
  backdrop-filter: {{ ($c['bg_style'] ?? 'solid') === 'transparent' ? 'blur(12px)' : 'none' }};
}
.p-nav-inner {
  display: flex;
  align-items: center;
  height: 64px;
  gap: 32px;
}
.p-nav-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: var(--p-font-heading);
  font-size: 18px;
  font-weight: 700;
  text-decoration: none;
  color: var(--p-text);
  flex-shrink: 0;
}
.p-nav-logo img { height: 32px; width: auto; }
.p-nav-links {
  display: flex;
  align-items: center;
  gap: 4px;
  flex: 1;
}
.p-nav-link {
  padding: 6px 14px;
  font-size: 14px;
  font-weight: 500;
  border-radius: 6px;
  color: var(--p-text);
  opacity: .7;
  transition: opacity .12s;
}
.p-nav-link:hover { opacity: 1; }
.p-nav-end { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.p-hamburger {
  display: none;
  background: none;
  border: none;
  padding: 6px;
  cursor: pointer;
  flex-direction: column;
  gap: 5px;
}
.p-hamburger span { display: block; width: 22px; height: 2px; background: var(--p-text); border-radius: 2px; }
@media (max-width: 768px) {
  .p-nav-links { display: none; }
  .p-hamburger { display: flex; }
}
</style>

<nav class="p-nav">
  <div class="p-container">
    <div class="p-nav-inner">
      @if($c['show_logo'] ?? true)
        <a href="/" class="p-nav-logo">
          @if($tenant->logo_url)
            <img src="{{ $tenant->logo_url }}" alt="{{ $tenant->name }}">
          @else
            {{ $tenant->name }}
          @endif
        </a>
      @endif

      <div class="p-nav-links">
        @foreach($navItems as $item)
          <a href="{{ $item->url }}" class="p-nav-link">{{ $item->label }}</a>
        @endforeach
      </div>

      <div class="p-nav-end">
        @if(!empty($c['cta_label']))
          <a href="{{ $c['cta_url'] ?? '/book' }}" class="p-btn p-btn--primary p-btn--sm">
            {{ $c['cta_label'] }}
          </a>
        @endif
        <button class="p-hamburger" onclick="openMobileNav()" aria-label="Open menu">
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>
  </div>
</nav>
