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
  $logoUrl  = $showLogo && isset($tenant) ? \App\Support\ColorHelper::pickLogo($tenant, $navBg) : null;

  // Colors
  $textColor = ($c['text_color'] ?? '') ?: '#0a0a0a';
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
.{{ $instId }} .p-nav-logo img { height: {{ $height === 'compact' ? '24px' : ($height === 'spacious' ? '38px' : '30px') }}; width: auto; }

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
