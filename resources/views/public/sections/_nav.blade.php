{{-- nav public renderer (v2) --}}
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

  // the header button is a menu item now (see the right-side loop).

  // Logo
  $showLogo = (bool)($c['show_logo'] ?? true);
  $navBg    = $bgMode === 'transparent' ? 'transparent' : $bgColor;
  // tenant picks the logo version explicitly. 'auto' keeps
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

  // independent logo size, no longer tied to nav height
  $logoSizeMap = [
      'small'  => '22px',
      'medium' => '30px',
      'large'  => '40px',
      'xl'     => '52px',
  ];
  $logoHeight = $logoSizeMap[$c['logo_size'] ?? 'medium'] ?? '30px';
  // MARKER-SHOP-NAV-PHONE-LOGO: phones can have their own logo size; blank = same as desktop
  $logoHeightP = $logoSizeMap[$c['logo_size_p'] ?? ''] ?? $logoHeight;

  // Colors
  // content-aware default: when the tenant hasn't chosen a
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

  // MARKER-SHOP-MENU-GROUPS: dropdown groups (none = the menu as before)
  $navGroups = isset($tenant) && $tenant ? \App\Support\ShopNav::groups($tenant) : [];

  // MARKER-SHOP-LOCATIONS: the optional strip above the bar (today's hours, phone, address, a message)
  $stripCard = null; $stripMsg = ''; $stripUrl = ''; $stripBits = [];
  if ((bool) ($c['strip_on'] ?? false) && isset($tenant) && $tenant) {
      $stripCard = \App\Support\ShopHours::cards($tenant)[0] ?? null;
      $stripMsg  = trim((string) ($c['strip_msg'] ?? ''));
      $stripUrl  = trim((string) ($c['strip_url'] ?? ''));
      if (! preg_match('#^(/|https?://|mailto:|tel:)#i', $stripUrl)) $stripUrl = '';
      if ($stripCard) {
          if ((bool) ($c['strip_today'] ?? true) && $stripCard['today'] !== '') $stripBits[] = 'today';
          if ((bool) ($c['strip_phone'] ?? true) && $stripCard['tel'] !== '')   $stripBits[] = 'phone';
          if ((bool) ($c['strip_addr'] ?? false) && $stripCard['address'] !== '') $stripBits[] = 'addr';
      }
  }
  $stripShow   = $stripBits || $stripMsg !== '';
  $stripCenter = ($c['strip_layout'] ?? 'split') === 'center';
  $stripHex    = fn ($v) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v) ? (string) $v : '';
  $stripBg     = $stripHex($c['strip_bg'] ?? '')   ?: \App\Support\SiteLook::c('text', '#0a0a0a');
  $stripText   = $stripHex($c['strip_text'] ?? '') ?: \App\Support\SiteLook::c('bg', '#ffffff');
  $stripMsgPhone = (bool) ($c['strip_msg_phone'] ?? false);

  // MARKER-SHOP-FLOAT-NAV: header style (Classic / Floating), App\Support\ShopNavHeader
  $pfAll   = \App\Support\ShopNavHeader::resolve($c);
  $pfD     = $pfAll['d'];
  $pfP     = $pfAll['p'];
  $pfOwn   = ($c['link_color'] ?? '') ?: ($c['text_color'] ?? '');
  $pfLinkD = $pfOwn ?: (\App\Support\ShopNavHeader::autoLink($pfD) ?: $linkColor);
  $pfLinkP = $pfOwn ?: (\App\Support\ShopNavHeader::autoLink($pfP) ?: $linkColor);
  $pfMenuBg    = $pfP['menu_bg'] ?: $pfP['bg'];
  $pfMenuSolid = $pfP['menu_bg'] ?: (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $bgColor) ? $bgColor : '#ffffff');
  $pfMenuLink  = $pfP['menu_link']
      ?: ($pfP['menu_bg'] !== '' ? (\App\Support\ColorHelper::isDark($pfP['menu_bg']) ? '#ffffff' : '#0a0a0a')
      : ($pfP['style'] === 'float' ? $pfLinkP : $linkColor));
  // on a floating bar solid enough to read against, Auto picks the logo by the bar's color
  if ($showLogo && isset($tenant) && $logoVariant === 'auto' && $pfD['style'] === 'float' && $pfD['opacity'] >= 40) {
      $logoUrl = \App\Support\ColorHelper::pickLogo($tenant, $pfD['bg']);
  }
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
  max-width: var(--p-page-max, 1200px);
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

/* nav instant search: MARKER-SHOP-SEARCH-V2 moved to public._shop-search */
.{{ $instId }} .p-nav-end {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

/* reads as a nav link, not a second CTA */
.{{ $instId }} .p-nav-account {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  font-size: 14px;
  font-weight: 500;
  border-radius: 6px;
  color: {{ $linkColor }};
  opacity: .7;
  text-decoration: none;
  transition: opacity .15s;
  white-space: nowrap;
}
.{{ $instId }} .p-nav-account:hover { opacity: 1; }
.{{ $instId }} .p-nav-account svg { flex: 0 0 auto; }
@media (max-width: 860px) { .{{ $instId }} .p-nav-account { display: none; } }

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
  /* MARKER-SHOP-NAV-PHONE-LOGO: the logo gives up room so the buttons and the menu
     button always fit inside the bar (they used to spill past its right edge) */
  .{{ $instId }} .p-nav-inner { gap: 12px; }
  @if($layout !== 'centered')
  .{{ $instId }} .p-nav-end { margin-left: auto; } /* buttons and menu button sit at the right edge */
  @endif
  .{{ $instId }} .p-nav-logo { flex-shrink: 1; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .{{ $instId }} .p-nav-logo img { height: {{ $logoHeightP }}; max-width: 100%; object-fit: contain; object-position: left center; }
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif

@if($stripShow)
/* MARKER-SHOP-LOCATIONS: the strip above the bar */
.{{ $instId }}-strip { position: relative; z-index: 101; background: {{ $stripBg }}; color: {{ $stripText }}; font-size: 12.5px; line-height: 1.4; }
.{{ $instId }}-strip .p-strip-in { max-width: var(--p-page-max, 1200px); margin: 0 auto; padding: 7px clamp(20px, 5vw, 48px); display: flex; align-items: center; flex-wrap: wrap; gap: 4px 18px; }
.{{ $instId }}-strip.is-center .p-strip-in { justify-content: center; }
.{{ $instId }}-strip .p-strip-bit { display: inline-flex; align-items: center; gap: 6px; color: inherit; text-decoration: none; opacity: .9; white-space: nowrap; }
.{{ $instId }}-strip a.p-strip-bit:hover { opacity: 1; }
.{{ $instId }}-strip .p-strip-bit svg { width: 13px; height: 13px; flex: none; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.{{ $instId }}-strip .p-strip-dot { width: 7px; height: 7px; border-radius: 50%; flex: none; background: #4ade80; }
.{{ $instId }}-strip .p-strip-dot.closed { background: #f87171; }
.{{ $instId }}-strip .p-strip-msg { margin-left: auto; color: var(--p-accent); font-weight: 600; letter-spacing: .01em; text-decoration: none; white-space: normal; }
.{{ $instId }}-strip.is-center .p-strip-msg { margin-left: 0; }
@media (max-width: 768px) {
  .{{ $instId }}-strip .p-strip-in { justify-content: space-between; gap: 4px 12px; padding: 7px 16px; }
  .{{ $instId }}-strip .p-strip-addr { display: none; }
  .{{ $instId }}-strip .p-strip-msg { margin-left: 0; font-size: 11.5px; @if(! $stripMsgPhone && $stripBits) display: none; @endif }
  @if($hideMobile) .{{ $instId }}-strip { display: none; } @endif
}
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }}-strip { display: none; } }
@endif
@endif

@include('public._nav-float')
@if($navGroups)
@php
  // panel colours: the bar's own (Floating) or the header's (Classic)
  $ddBg = $pfD['style'] === 'float' ? $pfD['bg']
      : ((! in_array($bgMode, ['transparent', 'blur'], true) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $bgColor)) ? $bgColor : '#ffffff');
  $ddText = ($pfD['style'] !== 'float' && ($c['text_color'] ?? '') !== '' && $ddBg === $bgColor) ? $c['text_color']
      : (\App\Support\ColorHelper::isDark($ddBg) ? '#ffffff' : '#0a0a0a');
@endphp
.{{ $instId }} { --p-dd-bg: {{ $ddBg }}; --p-dd-text: {{ $ddText }}; }
@include('public._nav-dd-css')
@endif
</style>

@if($stripShow)
  {{-- MARKER-SHOP-LOCATIONS: the strip above the bar --}}
  <div class="{{ $instId }}-strip p-strip{{ $stripCenter ? ' is-center' : '' }}">
    <div class="p-strip-in">
      @if(in_array('today', $stripBits, true))
        <span class="p-strip-bit"><span class="p-strip-dot{{ $stripCard['open'] ? '' : ' closed' }}"></span>{{ $stripCard['today'] }}</span>
      @endif
      @if(in_array('phone', $stripBits, true))
        <a class="p-strip-bit" href="{{ $stripCard['tel'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>{{ $stripCard['phone'] }}</a>
      @endif
      @if(in_array('addr', $stripBits, true))
        <a class="p-strip-bit p-strip-addr" href="{{ $stripCard['dir_url'] }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>{{ $stripCard['address'] }}</a>
      @endif
      @if($stripMsg !== '')
        @if($stripUrl !== '')
          <a class="p-strip-bit p-strip-msg" href="{{ $stripUrl }}">{{ $stripMsg }}</a>
        @else
          <span class="p-strip-bit p-strip-msg">{{ $stripMsg }}</span>
        @endif
      @endif
    </div>
  </div>
@endif
<nav class="{{ $instId }} p-nav {{ $customClass }}" data-pf-d="{{ $pfD['style'] }}" data-pf-p="{{ $pfP['style'] }}" @if($anchorId) id="{{ $anchorId }}" @endif>
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
        @foreach(\App\Support\ShopNav::bar(collect($navItems)->filter(fn ($i) => ($i->side ?? 'left') !== 'right'), $navGroups) as $navB)
          @if($navB['kind'] === 'group')
            @include('public._nav-dd', ['g' => $navB['group'], 'mode' => 'bar'])
            @continue
          @endif
          @php
            $item       = $navB['item'];
            $itemUrl    = $item->url ?? '/';
            $itemPath   = parse_url($itemUrl, PHP_URL_PATH) ?? $itemUrl;
            // MARKER-NAV-ANCHOR-ACTIVE: a #fragment link jumps within a page, it is never the current page
            $isActive   = ! str_contains($itemUrl, '#')
                && (($itemPath === $currentPath) || ($currentPath === '/' && $itemPath === '/'));
            // MARKER-NAV-ONE-ACTIVE: when two links point at the same page, only the first is marked current
            $isActive   = $isActive && ($item->style ?? 'link') === 'link' && empty($navActiveTaken);
            if ($isActive) $navActiveTaken = true;
            $isExternal = !empty($item->is_external) || str_starts_with($itemUrl, 'http');
            $newTab     = !empty($item->open_in_new_tab);
          @endphp
          <a href="{{ $itemUrl }}"
             class="{{ ($item->style ?? 'link') === 'button' ? 'p-nav-cta p-nav-cta--primary' : (($item->style ?? 'link') === 'outline' ? 'p-nav-cta p-nav-cta--outline' : 'p-nav-link') }} {{ $isActive ? 'active' : '' }}"
             @if($newTab) target="_blank" rel="noopener" @endif>
            {{ $item->label }}
            @if($isExternal)<span style="opacity:.5;font-size:11px">↗</span>@endif
          </a>
        @endforeach
      </div>

      <div class="p-nav-end">
        {{-- instant shop search (store tenants only) --}}
        @php
          $navSearchTenant = $tenant ?? $currentTenant ?? tenant();
          $navShopSearch = $navSearchTenant
              && $navSearchTenant->online_store_enabled
              && (bool) (($navSearchTenant->settings['storefront']['enabled'] ?? true))
              && \App\Support\Launch\Launch::visible($navSearchTenant, 'ecom');
        @endphp
        @if($navShopSearch)
          @include('public._shop-search', ['ssId' => $instId . '-search', 'ssLink' => $linkColor])
        @endif
        {{-- the only route into the customer portal --}}
        @php
          $navShowAccount = (bool) ($c['show_account'] ?? true);
          $navCustomer    = \Illuminate\Support\Facades\Auth::guard('customer')->user();
        @endphp
        @if($navShowAccount)
          <a href="{{ $navCustomer ? route('tenant.customer.portal') : route('tenant.customer.login') }}"
             class="p-nav-account">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5.2" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M2.6 14c.8-2.6 2.9-4 5.4-4s4.6 1.4 5.4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            {{ $navCustomer ? $navCustomer->first_name : 'Sign in' }}
          </a>
        @endif
        {{-- right-side menu items (the header button lives here now) --}}
        @foreach(collect($navItems)->filter(fn ($i) => ($i->side ?? 'left') === 'right') as $item)
          <a href="{{ $item->url ?? '/' }}"
             class="{{ ($item->style ?? 'link') === 'button' ? 'p-nav-cta p-nav-cta--primary' : (($item->style ?? 'link') === 'outline' ? 'p-nav-cta p-nav-cta--outline' : 'p-nav-link') }}"
             @if(!empty($item->open_in_new_tab)) target="_blank" rel="noopener" @endif>{{ $item->label }}</a>
        @endforeach
        <button class="p-hamburger" type="button" onclick="pNavMenu('{{ $instId }}', this)" aria-label="Open menu" aria-expanded="false" aria-controls="{{ $instId }}-drop">
          <span></span><span></span><span></span>
        </button>
      </div>

    </div>
  </div>

  {{-- MARKER-SHOP-FLOAT-NAV: the phone menu that drops from the bar (Floating,
       or pages that have no full-screen menu). Links first, then buttons. --}}
  <div class="p-nav-drop" id="{{ $instId }}-drop">
    @if($navShowAccount ?? false)
      <a href="{{ ($navCustomer ?? null) ? route('tenant.customer.portal') : route('tenant.customer.login') }}">{{ ($navCustomer ?? null) ? $navCustomer->first_name : 'Sign in' }}</a>
    @endif
    @foreach(\App\Support\ShopNav::bar(collect($navItems)->filter(fn ($i) => ($i->style ?? 'link') === 'link'), $navGroups) as $navB)
      @if($navB['kind'] === 'group')
        @include('public._nav-dd', ['g' => $navB['group'], 'mode' => 'acc'])
        @continue
      @endif
      @php
        $item  = $navB['item'];
        $dUrl  = $item->url ?? '/';
        $dPath = parse_url($dUrl, PHP_URL_PATH) ?? $dUrl;
        $dOn   = ! str_contains($dUrl, '#') && $dPath === $currentPath && empty($navDropTaken);
        if ($dOn) $navDropTaken = true;
      @endphp
      <a href="{{ $dUrl }}" class="{{ $dOn ? 'active' : '' }}" @if(!empty($item->open_in_new_tab)) target="_blank" rel="noopener" @endif>{{ $item->label }}</a>
    @endforeach
    @foreach(collect($navItems)->filter(fn ($i) => ($i->style ?? 'link') !== 'link' && ((($i->side ?? 'left') !== 'right') || $pfP['btn_pos'] === 'menu')) as $item)
      <a href="{{ $item->url ?? '/' }}" class="p-nav-cta {{ ($item->style ?? 'link') === 'button' ? 'p-nav-cta--primary' : 'p-nav-cta--outline' }}" @if(!empty($item->open_in_new_tab)) target="_blank" rel="noopener" @endif>{{ $item->label }}</a>
    @endforeach
  </div>
</nav>

{{-- MARKER-SHOP-FLOAT-NAV: phone menu toggle and the Floating bar's tuck.
     Re-runs safely when the builder preview swaps this section in. --}}
<script>
(function () {
  var PHONE = '(max-width: {{ \App\Support\ShopNavHeader::BREAK }}px)';
  function isFloat(nav) {
    var ph = window.matchMedia && window.matchMedia(PHONE).matches;
    return (ph ? nav.getAttribute('data-pf-p') : nav.getAttribute('data-pf-d')) === 'float';
  }
  function closeDrop(nav) {
    if (!nav) return;
    var d = nav.querySelector('.p-nav-drop'), b = nav.querySelector('.p-hamburger');
    if (d) d.classList.remove('open');
    if (b) { b.classList.remove('is-open'); b.setAttribute('aria-expanded', 'false'); }
  }
  // ☰: a Floating bar (or a page with no full-screen menu) drops its own panel;
  // otherwise the page's full-screen menu opens as before.
  window.pNavMenu = function (id, btn) {
    var nav = document.querySelector('.' + id);
    if (!nav) return;
    var full = document.getElementById('p-mobile-nav') && typeof window.openMobileNav === 'function';
    if (!isFloat(nav) && full) { window.openMobileNav(); return; }
    var d = document.getElementById(id + '-drop');
    if (!d) { if (full) window.openMobileNav(); return; }
    nav.classList.toggle('is-drop-solid', !isFloat(nav));
    var on = !d.classList.contains('open');
    d.classList.toggle('open', on);
    btn.classList.toggle('is-open', on);
    btn.setAttribute('aria-expanded', on ? 'true' : 'false');
  };
  if (!window.pNavDropWired) {
    window.pNavDropWired = true;
    document.addEventListener('click', function (e) {
      document.querySelectorAll('.p-nav-drop.open').forEach(function (d) {
        var nav = d.closest('.p-nav');
        if (e.target.closest && e.target.closest('.p-nav-drop a')) { closeDrop(nav); return; }
        if (nav && !nav.contains(e.target)) closeDrop(nav);
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') document.querySelectorAll('.p-nav').forEach(closeDrop);
    });
  }

  // The first thing on the page after the nav: the section the bar hovers over.
  function firstSection(nav) {
    var el = nav.closest('[data-pb-section]') || nav;
    while ((el = el.nextElementSibling)) {
      if (/^(STYLE|SCRIPT|LINK|NOSCRIPT|TEMPLATE)$/.test(el.tagName)) continue;
      if (!el.getClientRects().length) continue;
      break;
    }
    if (!el) return null;
    if (/^(SECTION|HEADER|FOOTER)$/.test(el.tagName)) return el;
    return el.querySelector('section, header') || el;
  }

  var id = '{{ $instId }}';
  window.pNavFits = window.pNavFits || {};
  window.pNavFits[id] = function () {
    var nav = document.querySelector('.' + id);
    if (!nav) { delete window.pNavFits[id]; return; }
    // undo the last measurement before taking a new one (the nav may have
    // been redrawn by the builder since, so find it by its mark, not a reference)
    document.querySelectorAll('[data-pf-pad]').forEach(function (el) {
      el.style.paddingTop = el.getAttribute('data-pf-pad');
      el.removeAttribute('data-pf-pad');
    });
    nav.classList.remove('is-tucked');
    nav.style.removeProperty('--pf-h');
    if (!isFloat(nav)) { nav.classList.remove('is-drop-solid'); return; }
    // booking, rentals and account pages: the bar floats but keeps its space
    if (nav.closest('.p-chrome-scope')) return;
    var wrap = nav.querySelector('.p-nav-wrap');
    if (!wrap) return;
    var h = Math.ceil(wrap.offsetHeight + (parseFloat(getComputedStyle(nav).paddingTop) || 0));
    nav.style.setProperty('--pf-h', h + 'px');
    nav.classList.add('is-tucked');
    var first = firstSection(nav);
    if (!first) return;
    var need = h + 24;
    var pt = parseFloat(getComputedStyle(first).paddingTop) || 0;
    if (pt < need) {
      first.setAttribute('data-pf-pad', first.style.paddingTop || '');
      first.style.paddingTop = need + 'px';
    }
  };
  window.pNavFit = function () {
    Object.keys(window.pNavFits || {}).forEach(function (k) { try { window.pNavFits[k](); } catch (e) {} });
  };
  if (!window.pNavFitWired) {
    window.pNavFitWired = true;
    var mq = window.matchMedia ? window.matchMedia(PHONE) : null;
    var onScreen = function () { document.querySelectorAll('.p-nav').forEach(closeDrop); window.pNavFit(); };
    if (mq && mq.addEventListener) mq.addEventListener('change', onScreen); else if (mq && mq.addListener) mq.addListener(onScreen);
    window.addEventListener('resize', function () { window.pNavFit(); });
    window.addEventListener('load', function () { window.pNavFit(); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { window.pNavFit(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.pNavFits[id]);
  else window.pNavFits[id]();
})();
</script>


@if($navGroups)
<script>
(function () {
  // MARKER-SHOP-MENU-GROUPS: dropdowns open on hover (mouse) or click/tap;
  // Esc, a click outside or leaving closes. Phone menus keep one group open.
  if (!window.__pDd) {
    window.__pDd = true;
    var ddFine = window.matchMedia && window.matchMedia('(hover: hover)').matches, ddT = null;
    // MARKER-SHOP-LOCATIONS: maps load the first time their panel opens; a wide panel stays on screen
    var ddMaps = function (root) { root.querySelectorAll('iframe[data-src]').forEach(function (f) { f.src = f.getAttribute('data-src'); f.removeAttribute('data-src'); }); };
    var ddFit = function (d) {
      var p = d.querySelector('.p-dd-panel'); if (!p) return;
      p.style.marginLeft = '';
      var r = p.getBoundingClientRect(), w = document.documentElement.clientWidth, s = 0;
      if (r.right > w - 12) s = w - 12 - r.right;
      if (r.left + s < 12) s = 12 - r.left;
      if (s) p.style.marginLeft = s + 'px';
    };
    var ddSet = function (d, on) { var was = d.classList.contains('on'); d.classList.toggle('on', on); var b = d.querySelector('.p-dd-btn'); if (b) b.setAttribute('aria-expanded', on ? 'true' : 'false'); if (on && !was) { ddMaps(d); ddFit(d); } };
    var ddAll = function (except) { document.querySelectorAll('.p-dd.on').forEach(function (d) { if (d !== except) ddSet(d, false); }); };
    document.addEventListener('mouseover', function (e) { if (!ddFine) return; var d = e.target.closest && e.target.closest('.p-dd'); if (d) { clearTimeout(ddT); ddAll(d); ddSet(d, true); } });
    document.addEventListener('mouseout', function (e) { if (!ddFine) return; var d = e.target.closest && e.target.closest('.p-dd'); if (!d || d.contains(e.relatedTarget)) return; ddT = setTimeout(function () { ddSet(d, false); }, 140); });
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('.p-dd-btn');
      if (b) { var d = b.closest('.p-dd'); ddAll(d); ddSet(d, ddFine ? true : !d.classList.contains('on')); return; }
      if (!(e.target.closest && e.target.closest('.p-dd-panel'))) ddAll(null);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') ddAll(null); });
    document.addEventListener('toggle', function (e) {
      var t = e.target; if (!t.matches || !t.matches('.p-mg') || !t.open) return;
      ddMaps(t);
      t.parentNode.querySelectorAll('.p-mg[open]').forEach(function (x) { if (x !== t) x.open = false; });
    }, true);
  }
})();
</script>
@endif
