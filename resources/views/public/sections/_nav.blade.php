{{-- MARKER-PATCH-158-G25 — nav public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $layout = $c['layout'] ?? 'standard';
  $height = $c['height'] ?? 'normal';
  $heightMap = ['compact'=>'52px','normal'=>'64px','spacious'=>'80px'];
  $navHeight = $heightMap[$height] ?? '64px';
  $logoAlign = $c['logo_alignment'] ?? 'left';

  // Background — backward compat: legacy bg_style was solid|transparent
  $bgMode = $c['bg_mode'] ?? ($c['bg_style'] ?? 'solid');
  // Migrate "transparent" legacy values
  if ($bgMode === 'transparent' && !isset($c['bg_mode'])) $bgMode = 'transparent';
  $bgColor = $c['bg_color'] ?? '#ffffff';

  // Border
  $border = $c['border_bottom'] ?? 'hairline';
  $borderCss = match($border) {
      'none'     => 'none',
      'shadow'   => 'none; box-shadow: 0 1px 12px rgba(0,0,0,0.06)',
      default    => '1px solid rgba(0,0,0,0.07)',
  };

  // Sticky
  $sticky = (bool)($c['sticky'] ?? true);

  // CTA
  $showCta   = (bool)($c['show_cta'] ?? true) && !empty($c['cta_label']);
  $ctaLabel  = $c['cta_label']  ?? 'Book Now';
  $ctaUrl    = $c['cta_url']    ?? '/book';
  $ctaStyle  = $c['cta_style']  ?? 'primary';

  // Logo
  $showLogo = (bool)($c['show_logo'] ?? true);
  $navBg    = $bgMode === 'transparent' ? 'transparent' : $bgColor;
  // MARKER-PATCH-274 — tenant picks the logo version explicitly. 'auto' keeps
  // the legacy contrast-based pick for back-compat; light = logo_light_url
  // (falls back to the primary logo), dark = the primary logo_url.
  $logoVariant = $c['logo_variant'] ?? 'auto';
  if (!$showLogo || !isset($tenant)) {
      $logoUrl = null;
  } elseif ($logoVariant === 'light') {
      $logoUrl = $tenant->logo_light_url ?: $tenant->logo_url;
  } elseif ($logoVariant === 'dark') {
      $logoUrl = $tenant->logo_url;
  } else {
      $logoUrl = $bgMode === 'transparent' ? $tenant->logo_url : \App\Support\ColorHelper::pickLogo($tenant, $bgColor);
  }

  // MARKER-PATCH-158-G28 — independent logo size, no longer tied to nav height
  $logoSizeMap = [
      'small'  => '22px',
      'medium' => '30px',
      'large'  => '40px',
      'xl'     => '52px',
  ];
  $logoHeight = $logoSizeMap[$c['logo_size'] ?? 'medium'] ?? '30px';

  // Colors
  // MARKER-PATCH-620 — content-aware default: when the tenant hasn't chosen a
  // text color, derive black/white from the nav background's luminance so
  // icons and text stay visible on dark navs. Explicit choices always win.
  $autoText  = preg_match('/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', (string) $bgColor)
      ? \App\Support\ColorHelper::accentTextColor($bgColor)
      : '#0a0a0a';
  $textColor = ($c['text_color'] ?? '') ?: $autoText;
  $linkColor = ($c['link_color'] ?? '') ?: $textColor;
  $activeStyle = $c['active_link_style'] ?? 'underline';

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $navItems    = $navItems ?? collect();
  $currentPath = request()->path() ?? '';
  if ($currentPath !== '/' && !str_starts_with($currentPath, '/')) {
      $currentPath = '/' . $currentPath;
  }

  $instId = 'p-nav-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  @if($sticky) position: sticky; top: 0; @endif
  z-index: 100;
  @if($bgMode === 'transparent')
  background: transparent;
  border-bottom: none;
  @elseif($bgMode === 'blur')
  background: rgba(255,255,255,0.78);
  backdrop-filter: blur(14px) saturate(180%);
  -webkit-backdrop-filter: blur(14px) saturate(180%);
  border-bottom: {{ $borderCss }};
  @else
  background: {{ $bgColor }};
  border-bottom: {{ $borderCss }};
  @endif
}
.{{ $instId }} .p-nav-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-nav-inner {
  display: flex;
  align-items: center;
  height: {{ $navHeight }};
  gap: 24px;
  @if($layout === 'centered')
  flex-direction: column; height: auto; padding: 14px 0; gap: 10px;
  @endif
}

.{{ $instId }} .p-nav-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 17px;
  font-weight: 600;
  text-decoration: none;
  color: {{ $textColor }};
  flex-shrink: 0;
  letter-spacing: -.01em;
  @if($layout === 'standard' && $logoAlign === 'center')
  margin: 0 auto;
  @endif
}
.{{ $instId }} .p-nav-logo img { height: {{ $logoHeight }}; width: auto; }

.{{ $instId }} .p-nav-links {
  display: flex;
  align-items: center;
  gap: 2px;
  @if($layout === 'standard')
  flex: 1;
  justify-content: {{ $logoAlign === 'center' ? 'flex-start' : 'center' }};
  @elseif($layout === 'split')
  flex: 1;
  justify-content: flex-end;
  margin-right: 16px;
  @endif
}
.{{ $instId }} .p-nav-link {
  padding: 7px 13px;
  font-size: 14px;
  font-weight: 500;
  border-radius: 6px;
  color: {{ $linkColor }};
  opacity: .7;
  text-decoration: none;
  transition: all 0.15s;
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.{{ $instId }} .p-nav-link:hover { opacity: 1; }

@if($activeStyle === 'underline')
.{{ $instId }} .p-nav-link.active::after {
  content: '';
  position: absolute;
  left: 13px; right: 13px;
  bottom: -2px;
  height: 2px;
  background: {{ $linkColor }};
  border-radius: 1px;
}
.{{ $instId }} .p-nav-link.active { opacity: 1; }
@elseif($activeStyle === 'dot')
.{{ $instId }} .p-nav-link.active::before {
  content: '';
  width: 5px; height: 5px;
  border-radius: 50%;
  background: {{ $linkColor }};
  display: inline-block;
}
.{{ $instId }} .p-nav-link.active { opacity: 1; }
@elseif($activeStyle === 'pill')
.{{ $instId }} .p-nav-link.active {
  background: rgba(0,0,0,0.06);
  opacity: 1;
}
@endif

/* MARKER-PATCH-582 — nav instant search */
.{{ $instId }} .p-nav-search { position: relative; display: flex; align-items: center; }
.{{ $instId }} .p-nav-search-btn { background: none; border: 0; padding: 8px; display: flex; color: {{ $linkColor }}; opacity: .75; } /* MARKER-PATCH-620 — match nav link color */
.{{ $instId }} .p-nav-search-btn:hover { opacity: 1; }
.{{ $instId }} .p-nav-search-panel { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: min(380px, 86vw); background: #fff; border: 1px solid rgba(0,0,0,.1); border-radius: 14px; box-shadow: 0 14px 44px rgba(0,0,0,.14); padding: 10px; z-index: 300; }
.{{ $instId }} .p-nav-search.open .p-nav-search-panel { display: block; }
.{{ $instId }} .p-nav-search-panel input { width: 100%; font: inherit; font-size: 14px; padding: 10px 13px; border: 1.5px solid rgba(0,0,0,.13); border-radius: 9px; }
.{{ $instId }} .p-nav-search-results a { display: flex; gap: 11px; align-items: center; padding: 9px 6px; border-radius: 9px; text-decoration: none; color: inherit; }
.{{ $instId }} .p-nav-search-results a:hover { background: rgba(0,0,0,.045); }
.{{ $instId }} .p-nav-search-results img { width: 38px; height: 38px; object-fit: contain; border: 1px solid rgba(0,0,0,.07); border-radius: 8px; background: #fff; }
.{{ $instId }} .p-nav-search-results .n { font-size: 13px; font-weight: 600; line-height: 1.3; }
.{{ $instId }} .p-nav-search-results .m { font-size: 11px; opacity: .55; }
.{{ $instId }} .p-nav-search-results .pr { margin-left: auto; font-size: 13px; font-weight: 700; white-space: nowrap; }
.{{ $instId }} .p-nav-search-results .all { display: block; text-align: center; font-size: 12.5px; font-weight: 600; padding: 10px 0 4px; opacity: .65; }
.{{ $instId }} .p-nav-end {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

.{{ $instId }} .p-nav-cta {
  display: inline-flex;
  align-items: center;
  padding: 8px 18px;
  font-size: 14px;
  font-weight: 500;
  border-radius: 6px;
  text-decoration: none;
  border: 1px solid transparent;
  transition: all 0.15s;
}
.{{ $instId }} .p-nav-cta--primary { background: #0a0a0a; color: #ffffff; }
.{{ $instId }} .p-nav-cta--primary:hover { filter: brightness(1.1); }
.{{ $instId }} .p-nav-cta--outline { background: transparent; color: {{ $textColor }}; border-color: {{ $textColor }}; opacity: .85; }
.{{ $instId }} .p-nav-cta--outline:hover { opacity: 1; }
.{{ $instId }} .p-nav-cta--ghost { background: rgba(0,0,0,0.05); color: {{ $textColor }}; }
.{{ $instId }} .p-nav-cta--ghost:hover { background: rgba(0,0,0,0.1); }

.{{ $instId }} .p-hamburger {
  display: none;
  background: none;
  border: 0;
  padding: 6px;
  cursor: pointer;
  flex-direction: column;
  gap: 5px;
}
.{{ $instId }} .p-hamburger span { display: block; width: 22px; height: 2px; background: {{ $textColor }}; border-radius: 2px; }

@media (max-width: 768px) {
  .{{ $instId }} .p-nav-links { display: none; }
  .{{ $instId }} .p-hamburger { display: flex; }
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<nav class="{{ $instId }} p-nav {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-nav-wrap">
    <div class="p-nav-inner">

      @if($showLogo)
        <a href="/" class="p-nav-logo">
          @if($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $tenant->name ?? 'Logo' }}">
          @else
            {{ $tenant->name ?? 'Logo' }}
          @endif
        </a>
      @endif

      <div class="p-nav-links">
        @foreach($navItems as $item)
          @php
            $itemUrl    = $item->url ?? '/';
            $itemPath   = parse_url($itemUrl, PHP_URL_PATH) ?? $itemUrl;
            $isActive   = ($itemPath === $currentPath) || ($currentPath === '/' && $itemPath === '/');
            $isExternal = !empty($item->is_external) || str_starts_with($itemUrl, 'http');
            $newTab     = !empty($item->open_in_new_tab);
          @endphp
          <a href="{{ $itemUrl }}"
             class="p-nav-link {{ $isActive ? 'active' : '' }}"
             @if($newTab) target="_blank" rel="noopener" @endif>
            {{ $item->label }}
            @if($isExternal)<span style="opacity:.5;font-size:11px">↗</span>@endif
          </a>
        @endforeach
      </div>

      <div class="p-nav-end">
        {{-- MARKER-PATCH-582 — instant shop search (store tenants only) --}}
        @php
          $navSearchTenant = $tenant ?? $currentTenant ?? tenant(); // MARKER-PATCH-584
          $navShopSearch = $navSearchTenant
              && $navSearchTenant->online_store_enabled
              && (bool) (($navSearchTenant->settings['storefront']['enabled'] ?? true));
        @endphp
        @if($navShopSearch)
          <div class="p-nav-search" id="{{ $instId }}-search">
            <button type="button" class="p-nav-search-btn" aria-label="Search the shop"
                    onclick="pNavSearchOpen('{{ $instId }}')">
              <svg width="17" height="17" viewBox="0 0 17 17" fill="none"><circle cx="7.2" cy="7.2" r="5.4" stroke="currentColor" stroke-width="1.7"/><path d="M11.5 11.5L15.5 15.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </button>
            <div class="p-nav-search-panel">
              <input type="search" placeholder="Search the shop…" autocomplete="off"
                     oninput="pNavSearchType('{{ $instId }}', this.value)"
                     onkeydown="if(event.key==='Enter'){window.location='/shop?q='+encodeURIComponent(this.value)}">
              <div class="p-nav-search-results"></div>
            </div>
          </div>
        @endif
        @if($showCta)
          <a href="{{ $ctaUrl }}" class="p-nav-cta p-nav-cta--{{ $ctaStyle }}">
            {{ $ctaLabel }}
          </a>
        @endif
        <button class="p-hamburger" onclick="if (typeof openMobileNav === 'function') openMobileNav()" aria-label="Open menu">
          <span></span><span></span><span></span>
        </button>
      </div>

    </div>
  </div>
</nav>

@if($navShopSearch ?? false)
@once
<script>
/* MARKER-PATCH-582 — nav instant search (shared across nav instances) */
var pNavSearchTimer;
function pNavEsc(x) { var d = document.createElement('div'); d.textContent = x || ''; return d.innerHTML; }
function pNavSearchOpen(id) {
  var box = document.getElementById(id + '-search');
  box.classList.toggle('open');
  if (box.classList.contains('open')) box.querySelector('input').focus();
}
function pNavSearchType(id, q) {
  clearTimeout(pNavSearchTimer);
  var out = document.querySelector('#' + id + '-search .p-nav-search-results');
  if ((q || '').trim().length < 2) { out.innerHTML = ''; return; }
  pNavSearchTimer = setTimeout(function () {
    fetch('/shop/search.json?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        out.innerHTML = (d.items || []).map(function (i) {
          return '<a href="' + i.url + '">'
            + (i.img ? '<img src="' + i.img + '" alt="">' : '<span style="width:38px"></span>')
            + '<span><span class="n">' + pNavEsc(i.name) + '</span><br><span class="m">'
            + pNavEsc(i.brand || '') + (i.stock ? ' · in stock' : '') + '</span></span>'
            + (i.price ? '<span class="pr">' + i.price + '</span>' : '')
            + '</a>';
        }).join('')
        + ((d.items || []).length
            ? '<a class="all" href="/shop?q=' + encodeURIComponent(q) + '">See all results →</a>'
            : '<div style="padding:12px 6px;font-size:13px;opacity:.5">Nothing found</div>');
      }).catch(function () {});
  }, 220);
}
document.addEventListener('click', function (e) {
  document.querySelectorAll('.p-nav-search.open').forEach(function (b) {
    if (!b.contains(e.target)) b.classList.remove('open');
  });
});
</script>
@endonce
@endif

