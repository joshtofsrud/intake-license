{{-- MARKER-SHOP-FLOAT-NAV: header style for the shop nav, included inside
     the nav partial's <style>. Same behaviour as the intake.works header
     (marketing/sections/_shell_nav): a Floating bar is a rounded glass pill
     that hovers over the page, and the first section runs up behind it.
     Settings come from App\Support\ShopNavHeader. A nav with none of the new
     settings gets only the hamburger animation and the phone drop panel
     (used on pages that have no full-screen menu); everything else below
     is conditional. --}}
@php
  $S   = '.' . $instId;
  $nhD = $pfD;
  $nhP = $pfP;
@endphp
{{ $S }} { {!! \App\Support\ShopNavHeader::vars($nhD, false, $pfLinkD) !!} --pf-menu-bg: {{ $pfMenuBg }}; --pf-menu-solid: {{ $pfMenuSolid }}; --pf-menu-link: {{ $pfMenuLink }}; }
@media (max-width: 768px) {
  {{ $S }} { {!! \App\Support\ShopNavHeader::vars($nhP, true, $pfLinkP) !!} }
}
@unless($sticky)
{{ $S }} { position: relative; }
@endunless
@if($sticky)
/* builder preview only: the section wrapper is exactly the nav's height, so the wrapper sticks instead */
[data-pb-section]:has(> {{ $S }}) { position: sticky; top: 0; z-index: 100; }
@endif

/* three lines morph into an X while the drop panel is open */
{{ $S }} .p-hamburger { position: relative; }
{{ $S }} .p-hamburger::after { content: ""; position: absolute; inset: -10px; }
{{ $S }} .p-hamburger span { transition: transform .25s ease, opacity .2s ease; }
{{ $S }} .p-hamburger.is-open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
{{ $S }} .p-hamburger.is-open span:nth-child(2) { opacity: 0; }
{{ $S }} .p-hamburger.is-open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
@media (prefers-reduced-motion: reduce) { {{ $S }} .p-hamburger span { transition: none; } }

/* phone drop panel: glass under a Floating bar, solid where the page has no full-screen menu */
{{ $S }} .p-nav-drop { display: none; }
@media (max-width: 768px) {
  {{ $S }} .p-nav-drop.open {
    display: flex; flex-direction: column;
    position: absolute; left: 12px; right: 12px; top: calc(100% + 8px); z-index: 150;
    padding: 6px 18px 14px; border-radius: 20px;
    max-height: calc(100vh - 120px); overflow: auto;
    background: color-mix(in srgb, var(--pf-menu-bg) var(--pf-op), transparent);
    -webkit-backdrop-filter: blur(var(--pf-blur)); backdrop-filter: blur(var(--pf-blur));
    border: .5px solid color-mix(in srgb, var(--pf-edge) 14%, transparent);
    box-shadow: 0 18px 40px rgba(0,0,0,.18);
  }
  {{ $S }}.is-drop-solid .p-nav-drop.open {
    left: 0; right: 0; top: 100%; border-radius: 0 0 18px 18px;
    background: var(--pf-menu-solid); -webkit-backdrop-filter: none; backdrop-filter: none;
    border-width: 0; box-shadow: 0 18px 30px rgba(0,0,0,.12);
  }
  {{ $S }} .p-nav-drop a:not(.p-nav-cta) {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 2px; font-size: 16px; font-weight: 500; text-decoration: none;
    color: var(--pf-menu-link);
    border-bottom: 1px solid color-mix(in srgb, var(--pf-menu-link) 12%, transparent);
  }
  {{ $S }} .p-nav-drop a.active { font-weight: 700; }
  {{ $S }} .p-nav-drop .p-nav-cta { justify-content: center; margin-top: 12px; padding: 11px 18px; }
}

{{-- Floating bar, for each screen size that uses it --}}
@foreach(['d' => '(min-width: 769px)', 'p' => '(max-width: 768px)'] as $nhKey => $nhMq)
@if(($nhKey === 'd' ? $nhD : $nhP)['style'] === 'float')
@media {{ $nhMq }} {
  {{ $S }} {
    background: transparent; border-bottom: 0; box-shadow: none;
    -webkit-backdrop-filter: none; backdrop-filter: none;
    padding: calc(var(--pf-out) + env(safe-area-inset-top, 0px)) {{ $nhKey === 'p' ? '12px' : 'clamp(12px, 3vw, 32px)' }} 0;
  }
  {{ $S }}.is-tucked { margin-bottom: calc(-1 * var(--pf-h, 0px)); }
  {{ $S }}::before {
    content: ''; position: absolute; left: 0; right: 0; top: 0; height: calc(100% + 28px);
    pointer-events: none; z-index: -1; opacity: var(--pf-fade, 0); transition: opacity .2s;
    /* MARKER-SHOP-FLOAT-NAV-FIX1: fade toward the bar's own color, not the page's */
    background: linear-gradient(to bottom, color-mix(in srgb, var(--pf-bg) 55%, transparent), transparent);
    -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
    -webkit-mask-image: linear-gradient(to bottom, #000 45%, transparent); mask-image: linear-gradient(to bottom, #000 45%, transparent);
  }
  {{ $S }} .p-nav-wrap {
    max-width: 1200px;
    padding: var(--pf-pad);
    border-radius: {{ $layout === 'centered' ? '24px' : '999px' }};
    background: color-mix(in srgb, var(--pf-bg) var(--pf-op), transparent);
    -webkit-backdrop-filter: blur(var(--pf-blur)); backdrop-filter: blur(var(--pf-blur));
    border: .5px solid color-mix(in srgb, var(--pf-edge) 12%, transparent);
    box-shadow: 0 10px 30px -14px rgba(0,0,0,.35);
  }
  {{ $S }} .p-nav-inner { height: auto; min-height: 36px; }
  {{ $S }} .p-nav-links {
    flex: 0 1 auto; background: var(--pf-pill); border-radius: 999px; padding: 4px; gap: 2px;
    @if($layout === 'split')
    margin-left: auto;
    @elseif($layout === 'standard' && $logoAlign !== 'center')
    margin: 0 auto;
    @endif
  }
  {{ $S }} .p-nav-link { border-radius: 999px; padding: 7px 14px; color: var(--pf-link); }
  {{ $S }} .p-nav-link.active::after { left: 14px; right: 14px; bottom: 3px; background: var(--pf-link); }
  {{ $S }} .p-nav-link.active::before { background: var(--pf-link); }
  {{ $S }} .p-nav-logo, {{ $S }} .p-nav-account, {{ $S }} .p-nav-search-btn { color: var(--pf-link); }
  {{ $S }} .p-hamburger span { background: var(--pf-link); }
  {{ $S }} .p-nav-cta { border-radius: 999px; }
  {{ $S }} .p-nav-cta--outline, {{ $S }} .p-nav-cta--ghost { color: var(--pf-link); border-color: var(--pf-link); }
}
@endif
@endforeach

/* link weight and brightness (both styles) */
{{ $S }} .p-nav-link, {{ $S }} .p-nav-account { font-weight: {{ $nhD['link_weight'] }}; }
@if($nhD['link_bright'] === 'bright')
{{ $S }} .p-nav-link, {{ $S }} .p-nav-account, {{ $S }} .p-nav-search-btn { opacity: .95; }
@endif

/* header button colors (both styles; same in the bar and the phone menu) */
@if($nhD['btn_fill'] !== '')
{{ $S }} .p-nav-cta--primary { background: {{ $nhD['btn_fill'] }}; }
{{ $S }} .p-nav-cta--outline { border-color: {{ $nhD['btn_fill'] }}; }
@endif
@if($nhD['btn_text'] !== '')
{{ $S }} .p-nav-cta--primary, {{ $S }} .p-nav-cta--outline { color: {{ $nhD['btn_text'] }}; }
@endif

/* phone: where the header buttons sit */
@media (max-width: 768px) {
@if($nhP['btn_pos'] === 'menu')
  {{ $S }} .p-nav-end .p-nav-cta { display: none; }
@elseif($nhP['btn_dist'] > 0)
  {{ $S }} .p-hamburger { margin-left: {{ $nhP['btn_dist'] }}px; }
@endif
}

/* the full-screen phone menu's own colors */
@if($nhP['menu_bg'] !== '')
#p-mobile-nav { background: {{ $nhP['menu_bg'] }}; }
@endif
@if($nhP['menu_link'] !== '')
#p-mobile-nav a:not(.p-btn) { color: {{ $nhP['menu_link'] }}; }
@endif
