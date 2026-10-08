@php
  $pageTitle = 'Edit: ' . $page->title;
  // Marketing-aware URL helpers (preserved from v1).
  $isMarketing = $isMarketing ?? false;
  $isBookingExtras = $isBookingExtras ?? false;
  $layoutName  = $isMarketing ? 'layouts.admin.page-editor' : 'layouts.tenant.app';
  $backUrl     = $isBookingExtras
      ? route('tenant.booking-editor.index')
      : ($isMarketing ? url('/admin/marketing-pages') : route('tenant.pages.index'));
  $previewUrl  = $isMarketing
      ? 'https://' . config('intake.domain', 'intake.works') . '/' . ($page->is_home ? '' : $page->slug)
      : tenant_url($page->is_home ? '' : $page->slug);
  // iframe/live-reload use an authenticated same-origin
  // route that renders drafts too; "Open live" keeps the public $previewUrl.
  // marketing preview is a draft-capable routed page.
  $previewSrc  = $isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/preview')
      : route('tenant.pages.preview', $page->id);
  $storeUrl    = $isMarketing
      ? url('/admin/marketing-pages/store')
      : route('tenant.pages.store');
  // endpoints that used to hardcode tenant routes, which
  // don't bind a tenant on the apex host. url() literals, matching G19A.
  $updateUrl = $isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/builder')
      : route('tenant.pages.update', $page->id);
  $mediaFeedUrl = $isMarketing
      ? url('/admin/marketing-media/feed')
      : route('tenant.media.feed');
  $historyListUrl = $isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/history')
      : route('tenant.pages.history', $page->id);
  $historyRestoreTpl = $isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/history/__RID__/restore')
      : route('tenant.pages.history.restore', [$page->id, '__RID__']);

  // Section type labels + icon classes (Tabler-style line icons via inline SVG below).
  $typeLabels = [
    'nav'                    => 'Navigation',
    'hero'                   => 'Hero',
    'services'               => 'Services grid',
    'text_image'             => 'Text + image',
    'cta_banner'             => 'CTA banner',
    'image_gallery'          => 'Image gallery',
    'image_carousel'         => 'Image carousel',
    'scroll_words'           => 'Scroll words',
    'contact_form'           => 'Contact form',
    'booking_embed'          => 'Booking form',
    'classes_embed'          => 'Classes schedule',
    'footer'                 => 'Footer',
    'feature_grid'           => 'Feature grid',
    'feature_groups'         => 'Feature groups with index',
    'step_timeline'          => 'Step timeline',
    'pricing_table'          => 'Pricing table',
    'rentals_showcase'       => 'Rentals showcase',
    'rental_spotlight'       => 'Rental spotlight',
    'rental_categories'      => 'Rental categories',
    'rental_browse'          => 'Rental availability',
    'products_showcase'      => 'Product showcase',
    'faq_accordion'          => 'FAQ accordion',
    'testimonial_carousel'   => 'Testimonials',
    'logo_bar'               => 'Logo bar',
    'comparison_table'       => 'Comparison table',
    'industry_pack_showcase' => 'Industries',
    'book_call'              => 'Book a call',
    'try_demo'               => 'Try the demo',
    'roi'                    => 'ROI',
    'feature_tiles'          => 'Feature tiles',
    'stats_row'              => 'Stats row',
    'custom_html'            => 'Custom HTML',
  ];

  // Inline SVG icon paths per section type. Paths are
  // 24x24 viewBox; stroke-currentcolor; rendered identically in the section
  // list (left pane) and the add-section gallery so the icon is a reliable
  // visual anchor for each type.
  $typeIconPaths = [
    'nav'            => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
    'hero'           => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><circle cx="8" cy="15" r="1.2" fill="currentColor"/>',
    'services'       => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'text_image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.4"/><polyline points="3 17 9 12 21 19"/>',
    'cta_banner'     => '<path d="M3 11l18-5v12L3 14z"/>',
    'image_gallery'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="1.2"/><polyline points="3 17 9 12 14 16 21 11"/>',
    'image_carousel' => '<rect x="7" y="5" width="10" height="14" rx="2"/><line x1="3" y1="9" x2="3" y2="15"/><line x1="21" y1="9" x2="21" y2="15"/>',
    'scroll_words' => '<line x1="4" y1="7" x2="11" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="15" y2="17"/>',
    'contact_form'   => '<path d="M4 4h16v16H4z"/><polyline points="4 7 12 13 20 7"/>',
    'booking_embed'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/>',
    'classes_embed'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1" fill="currentColor"/>',
    'footer'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="16" x2="21" y2="16"/>',
    'feature_grid'   => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    'feature_groups' => '<line x1="4" y1="6" x2="8" y2="6"/><line x1="4" y1="12" x2="8" y2="12"/><line x1="4" y1="18" x2="8" y2="18"/><rect x="12" y="4" width="9" height="7" rx="1"/><rect x="12" y="13" width="9" height="7" rx="1"/>',
    'step_timeline'  => '<line x1="3" y1="6" x2="3" y2="6.01"/><line x1="3" y1="12" x2="3" y2="12.01"/><line x1="3" y1="18" x2="3" y2="18.01"/><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>',
    'pricing_table'  => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    'rentals_showcase' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-3 11.5V14l-3-3 4-3 2 3h2"/>',
    'rental_spotlight' => '<path d="M12 3l2.5 5 5.5.8-4 3.9.9 5.5-4.9-2.6-4.9 2.6.9-5.5-4-3.9 5.5-.8z"/>',
    'rental_categories' => '<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/>',
    'rental_browse' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
    'products_showcase' => '<path d="M4 7h16l-1.5 12.5a1 1 0 0 1-1 .9H6.5a1 1 0 0 1-1-.9L4 7zm4 0V6a4 4 0 0 1 8 0v1"/>',
    'faq_accordion'  => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1-1 1.5-2.5 2.5"/><line x1="12" y1="17" x2="12" y2="17.01"/>',
    'testimonial_carousel' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>',
    'logo_bar'       => '<rect x="2" y="9" width="4" height="6" rx="1"/><rect x="10" y="9" width="4" height="6" rx="1"/><rect x="18" y="9" width="4" height="6" rx="1"/>',
    'comparison_table'=>'<rect x="3" y="3" width="18" height="18" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="12" y1="3" x2="12" y2="21"/>',
    'industry_pack_showcase'=>'<path d="M3 6l3-3h12l3 3v3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V6z"/><path d="M5 12v9h14v-9"/>',
    'stats_row'      => '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="17 7 21 7 21 11"/>',
    'book_call'      => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'try_demo'       => '<polygon points="5 3 19 12 5 21 5 3"/>',
    'roi'            => '<polyline points="4 17 9 12 13 15 20 7"/><polyline points="15 7 20 7 20 12"/>',
    'feature_tiles'  => '<rect x="3" y="3" width="11" height="8" rx="1.5"/><rect x="16" y="3" width="5" height="8" rx="1.5"/><rect x="3" y="13" width="18" height="8" rx="1.5"/>',
    'custom_html'    => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
  ];

  // Add-section gallery descriptions (one-liners shown under the type label
  // in the gallery card). Kept short so cards stay scannable.
  $typeDescriptions = [
    'nav'           => 'Top navigation with logo + CTA',
    'hero'          => 'Big headline + lede + buttons',
    'services'      => 'Live grid pulled from your services',
    'text_image'    => 'Side-by-side text and image',
    'cta_banner'    => 'Single call-to-action strip',
    'image_gallery' => 'Photo grid (Instagram-style)',
    'image_carousel' => 'Sliding photo carousel',
    'scroll_words'   => 'Words that change as you scroll',
    'contact_form'  => 'Inbound contact form',
    'booking_embed' => 'Live booking widget',
    'classes_embed' => 'Class schedule widget',
    'footer'        => 'Site footer with links + copyright',
    'feature_grid'  => 'Icon-led feature cards in a grid',
    'feature_groups' => 'Grouped features with an index that stays on screen',
    'step_timeline' => 'Numbered process steps',
    'faq_accordion' => 'Collapsible Q&A list',
    'pricing_table' => 'Side-by-side pricing tiers',
    'rentals_showcase' => 'Live rental fleet with rates',
    'rental_spotlight' => 'Feature one rental model',
    'rental_categories' => 'Category grid — pick and order',
    'rental_browse' => 'Live date-picker availability browse',
    'products_showcase' => 'Live products from your online store',
    'testimonial_carousel' => 'Customer quotes carousel',
    'logo_bar'      => 'Trust bar with partner logos',
    'comparison_table'=>'Feature vs competitor matrix',
    'industry_pack_showcase'=>'Showcase of industries served',
    'book_call'     => 'Let visitors book a call from your Scheduling calendar',
    'try_demo'      => 'Send visitors into the live demo shop, signed in, no account',
    'roi'           => 'Results with their sources, plus a rental extension calculator',
    'feature_tiles' => 'Tiles that open a drawer with details and a screenshot',
    'stats_row'     => 'Big-number stats row',
    'custom_html'   => 'Paste raw HTML, rendered as-is',
  ];

  // Logical grouping for the gallery. Order matters — common ones first.
  $typeGroups = [
    'Layout'     => ['nav','hero','footer'],
    'Content'    => ['text_image','feature_grid','feature_groups','step_timeline','image_gallery','image_carousel','scroll_words','faq_accordion','stats_row'],
    'Conversion' => ['services','cta_banner','booking_embed','contact_form','book_call','try_demo','roi','pricing_table','rentals_showcase','rental_spotlight','rental_categories','rental_browse','products_showcase'], // book_call
    'Social'     => ['testimonial_carousel','logo_bar'],
    'Advanced'   => ['custom_html'],
  ];
@endphp

@extends($layoutName)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
<style>
/* ============================================================================
   Page builder v2 chrome
   Three-pane layout matching the v2 mockup. Phase 1 ships the chrome only;
   field rendering still uses the existing _section.blade.php content (Phase
   2 will replace each section type's fields).
============================================================================ */
:root {
  --pb2-bg:          #0a0a0a;
  --pb2-surface:     #131313;
  --pb2-surface-2:   #181818;
  --pb2-surface-3:   #1f1f1f;
  --pb2-border:      rgba(255,255,255,0.08);
  --pb2-border-2:    rgba(255,255,255,0.16);
  --pb2-text:        #f5f5f4;
  --pb2-text-dim:    rgba(245,245,244,0.55);
  --pb2-text-faint:  rgba(245,245,244,0.32);
  --pb2-accent:      var(--ia-accent, #BEF264);
  --pb2-info:        #60A5FA;
  --pb2-info-dim:    rgba(96,165,250,0.16);
  --pb2-danger:      #F87171;
  --pb2-mono:        'JetBrains Mono', ui-monospace, monospace;
}

/* ----------------------------------------------------
   The builder used to hardcode a dark palette in :root, so it ignored the
   admin theme entirely — pick Light Premium and every screen turned light
   except this one. Values below are derived from theme-b's own --ia-*
   palette so the builder matches the rest of the admin, rather than being
   a separately-invented light grey.
   Scoped to body so it beats the :root defaults above without !important. */
body.ia-theme-b {
  --pb2-bg:          #F7F8FA;
  --pb2-surface:     #FFFFFF;
  --pb2-surface-2:   #F1F3F6;
  --pb2-surface-3:   #E7EAEF;
  --pb2-border:      rgba(15,20,25,0.10);
  --pb2-border-2:    rgba(15,20,25,0.20);
  --pb2-text:        #0F1419;
  --pb2-text-dim:    rgba(15,20,25,0.62);
  --pb2-text-faint:  rgba(15,20,25,0.42);
  --pb2-info:        #2563EB;
  --pb2-info-dim:    rgba(37,99,235,0.12);
}

/* The accent is a light lime — legible on dark, not on white. Anything that
   paints text or an icon in it needs a darker ink in light mode. */
body.ia-theme-b .pb2-status.is-live .pb2-status-label,
body.ia-theme-b .pb2-section-item.selected {
  color: #3F6212;
}
body.ia-theme-b .pb2-status-btn--go {
  color: #1A2E05;
}

/* The preview frame stays dark: it contains the tenant's real site, and a
   bright surround makes their own colors hard to judge. */
body.ia-theme-b .pb2-preview-col,
body.ia-theme-b .pb2-preview-bar {
  background: #131313;
  color: rgba(245,245,244,0.55);
}
body.ia-theme-b .pb2-preview-frame-wrap {
  background: #131313;
}

/* The editor takes over the full viewport, anchored past the tenant sidebar.
   replaced the original negative-margin escape with
   position:fixed because the tenant layout's .ia-content uses padding 28px 32px
   (not 24px), so the old -24px escape left bands of leftover padding around
   the editor — visible as the cropped topbar + right pane bleeding off the
   right edge. position:fixed sidesteps the layout padding entirely. */
.pb2-shell {
  position: fixed;
  top: 0;
  /* was a hardcoded 220px, which meant collapsing
     the sidebar left the builder exactly where it was. */
  left: var(--ia-sidebar-w, 220px);
  transition: left .16s ease;
  right: 0;
  bottom: 0;
  margin: 0;
  z-index: 50;          /* above .ia-content but below modals (z=200+) */
  background: var(--pb2-bg);
  color: var(--pb2-text);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  font-size: 13px;
}
@media (max-width: 900px) {
  .pb2-shell { left: 0; }  /* sidebar collapses on mobile in tenant layout */
}

/* TOPBAR */
.pb2-topbar {
  height: 48px;
  border-bottom: 0.5px solid var(--pb2-border);
  display: grid;
  /* matches the two-pane layout; right column stays
     aligned over the inspector. */
  grid-template-columns: auto 1fr 360px;
  align-items: center;
  background: var(--pb2-surface);
  flex-shrink: 0;
}
.pb2-topbar-left {
  display: flex; align-items: center; gap: 12px;
  padding: 0 18px;
  height: 100%;
  border-right: 0.5px solid var(--pb2-border);
  font-size: 13px;
}
.pb2-back-btn {
  color: var(--pb2-text-dim);
  text-decoration: none;
  font-size: 12px;
  display: inline-flex; align-items: center; gap: 4px;
}
.pb2-back-btn:hover { color: var(--pb2-text); }
.pb2-page-title {
  font-weight: 500;
  font-size: 13px;
  margin-left: auto;
  display: flex; align-items: center; gap: 6px;
  font-family: var(--pb2-mono);
  color: var(--pb2-text-dim);
}
.pb2-page-title b { color: var(--pb2-text); font-weight: 500; }

.pb2-topbar-center {
  display: flex; justify-content: center; gap: 6px;
}
.pb2-device-toggle {
  display: inline-flex;
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 2px;
  gap: 2px;
}
.pb2-device-btn {
  background: transparent;
  border: 0;
  color: var(--pb2-text-dim);
  padding: 5px 11px;
  border-radius: 4px;
  cursor: pointer;
  font: inherit; font-size: 11.5px;
  display: inline-flex; align-items: center; gap: 5px;
  transition: all 0.12s;
}
.pb2-device-btn.active { background: var(--pb2-surface-3); color: var(--pb2-text); }
/* Guides toggle beside the device switch */
.pb2-guides-btn { background: var(--pb2-surface-2); border: 0; color: var(--pb2-text-dim); padding: 5px 11px; border-radius: 6px;
  cursor: pointer; font: inherit; font-size: 11.5px; display: inline-flex; align-items: center; gap: 5px; }
.pb2-guides-btn:hover { color: var(--pb2-text); }
.pb2-guides-btn[aria-pressed="true"] { color: var(--pb2-accent); box-shadow: inset 0 0 0 .5px var(--pb2-accent); }
body.ia-theme-b .pb2-guides-btn[aria-pressed="true"] { color: #3F6212; box-shadow: inset 0 0 0 .5px #3F6212; }
.pb2-device-btn:hover:not(.active) { color: var(--pb2-text); }

.pb2-topbar-right {
  display: flex; align-items: center; gap: 4px;
  padding: 0 14px 0 0;
  justify-content: flex-end;
}
.pb2-icon-btn {
  width: 28px; height: 28px;
  background: transparent;
  border: 0;
  border-radius: 4px;
  color: var(--pb2-text-dim);
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all 0.12s;
}
.pb2-icon-btn:hover { background: var(--pb2-surface-3); color: var(--pb2-text); }
.pb2-icon-btn.disabled { color: var(--pb2-text-faint); pointer-events: none; }
.pb2-topbar-divider {
  width: 1px; height: 18px; background: var(--pb2-border); margin: 0 6px;
}
.pb2-btn {
  background: var(--pb2-surface-3);
  border: 0.5px solid var(--pb2-border-2);
  color: var(--pb2-text);
  padding: 6px 14px;
  border-radius: 6px;
  cursor: pointer;
  font: inherit; font-size: 12px; font-weight: 500;
  transition: all 0.12s;
  display: inline-flex; align-items: center; gap: 6px;
  text-decoration: none;
}
.pb2-btn:hover { background: var(--pb2-surface-2); border-color: var(--pb2-text-faint); }
.pb2-btn-primary {
  background: var(--pb2-accent);
  color: #0a1a00;
  border-color: var(--pb2-accent);
  font-weight: 600;
}
.pb2-btn-primary:hover { filter: brightness(1.05); }

/* MAIN LAYOUT */
.pb2-layout {
  display: grid;
  /* single sidebar: preview + inspector. The section
     list lives in a slide-in panel now. */
  grid-template-columns: 1fr 360px;
  flex: 1;
  min-height: 0;
  position: relative;
}

/* PANES (left + right) */
.pb2-pane {
  border-right: 0.5px solid var(--pb2-border);
  background: var(--pb2-surface);
  display: flex; flex-direction: column;
  overflow: hidden;
}
/* sections list docked atop the inspector column */
.pb2-sections-docked {
  flex: 0 0 auto;
  max-height: 40%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-bottom: 0.5px solid var(--pb2-border);
}
.pb2-sections-docked .pb2-section-list { flex: 1 1 auto; overflow-y: auto; }
.pb2-sections-docked .pb2-pane-footer { flex: 0 0 auto; }
/* collapsible docked sections */
.pb2-sections-toggle { cursor: pointer; user-select: none; }
.pb2-sections-head-right { display: flex; align-items: center; gap: 8px; }
.pb2-sections-chevron { opacity: .55; transition: transform .15s ease; flex: 0 0 auto; }
.pb2-sections-docked.collapsed { max-height: none; }
.pb2-sections-docked.collapsed .pb2-section-list,
.pb2-sections-docked.collapsed .pb2-pane-footer { display: none; }
.pb2-sections-docked.collapsed .pb2-sections-chevron { transform: rotate(-90deg); }
.pb2-pane-right { border-right: 0; border-left: 0.5px solid var(--pb2-border); }
.pb2-pane-header {
  padding: 14px 18px 10px;
  display: flex; align-items: center; justify-content: space-between;
}
.pb2-pane-header-title {
  font-family: var(--pb2-mono);
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--pb2-text-dim);
  font-weight: 500;
}
.pb2-pane-header-meta {
  font-family: var(--pb2-mono);
  font-size: 10.5px;
  color: var(--pb2-text-faint);
}

/* SECTION LIST */
.pb2-section-list {
  flex: 1;
  overflow-y: auto;
  padding: 0 10px 10px;
}
.pb2-section-list::-webkit-scrollbar { width: 5px; }
.pb2-section-list::-webkit-scrollbar-thumb { background: var(--pb2-border-2); border-radius: 2px; }

.pb2-section-item.pb2-in-row { box-shadow: inset 2px 0 0 var(--pb2-accent); }
.pb2-section-item {
  display: grid;
  grid-template-columns: 14px 18px 1fr auto;
  align-items: center; gap: 8px;
  padding: 7px 10px;
  border-radius: 6px;
  font-size: 12px;
  color: var(--pb2-text-dim);
  cursor: pointer;
  transition: all 0.12s;
  margin-bottom: 2px;
  position: relative;
  border: 1px solid transparent;
}
.pb2-section-item:hover { background: var(--pb2-surface-2); color: var(--pb2-text); }
.pb2-section-item.selected {
  background: var(--pb2-info-dim);
  color: var(--pb2-text);
  border-color: rgba(96,165,250,0.45);
}
.pb2-section-item.selected::before {
  content: '';
  position: absolute;
  left: -10px; top: 50%;
  width: 3px; height: 18px;
  background: var(--pb2-info);
  border-radius: 0 2px 2px 0;
  transform: translateY(-50%);
}
.pb2-section-item.hidden { opacity: 0.4; }
.pb2-section-item.hidden .pb2-section-name { text-decoration: line-through; }

.pb2-drag-handle {
  color: var(--pb2-text-faint);
  cursor: grab;
  font-size: 10px;
  user-select: none;
  display: flex; align-items: center; justify-content: center;
}
.pb2-section-icon {
  display: flex; align-items: center; justify-content: center;
  opacity: 0.7;
}
.pb2-section-item.selected .pb2-section-icon { opacity: 1; }
.pb2-section-item .pb2-section-icon svg { width: 14px; height: 14px; }
.pb2-section-name { font-weight: 500; }
.pb2-section-meta {
  font-family: var(--pb2-mono);
  font-size: 10px;
  color: var(--pb2-text-faint);
}

.pb2-section-add {
  margin: 10px 4px 4px;
  padding: 10px;
  border: 1px dashed var(--pb2-border-2);
  border-radius: 6px;
  text-align: center;
  color: var(--pb2-text-dim);
  font-size: 12px;
  cursor: pointer;
  transition: all 0.12s;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.pb2-section-add:hover {
  border-color: var(--pb2-accent);
  color: var(--pb2-accent);
  background: rgba(190,242,100,0.06);
}

.pb2-add-panel {
  margin: 6px 4px;
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  display: none;
  max-height: 60vh;
  overflow-y: auto;
}
.pb2-add-panel.open { display: block; }
.pb2-add-panel::-webkit-scrollbar { width: 5px; }
.pb2-add-panel::-webkit-scrollbar-thumb { background: var(--pb2-border-2); border-radius: 2px; }

/* Add-section gallery */
.pb2-paste-card {
  display: flex; align-items: center; gap: 10px; width: 100%;
  margin: 4px 0 10px; padding: 9px 10px; text-align: left;
  background: transparent; color: var(--pb2-text);
  border: 1px dashed var(--pb2-accent, #c6ff4a); border-radius: 6px; cursor: pointer; font: inherit;
}
.pb2-paste-card:hover { background: rgba(198, 255, 74, .06); }
.pb2-paste-card .pb2-paste-name { font-weight: 600; font-size: 12.5px; }
.pb2-paste-card .pb2-paste-sub { font-size: 11px; color: var(--pb2-text-dim); margin-top: 1px; }
.pb2-gallery-group-label {
  font-family: var(--pb2-mono);
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--pb2-text-faint);
  font-weight: 500;
  padding: 8px 4px 6px;
  margin-top: 2px;
}
.pb2-gallery-group-label:first-child { margin-top: 0; padding-top: 2px; }

.pb2-gallery-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 4px;
  margin-bottom: 4px;
}

.pb2-gallery-card {
  background: transparent;
  border: 0.5px solid transparent;
  color: var(--pb2-text-dim);
  padding: 8px 10px;
  border-radius: 5px;
  cursor: pointer;
  font: inherit;
  text-align: left;
  display: grid;
  grid-template-columns: 24px 1fr;
  gap: 10px;
  align-items: center;
  transition: all 0.12s;
}
.pb2-gallery-card:hover {
  background: var(--pb2-surface-3);
  color: var(--pb2-text);
  border-color: var(--pb2-border-2);
}

.pb2-gallery-card-icon {
  width: 24px; height: 24px;
  display: flex; align-items: center; justify-content: center;
  background: var(--pb2-bg);
  border-radius: 4px;
  opacity: 0.85;
}
.pb2-gallery-card-icon svg { width: 14px; height: 14px; }
.pb2-gallery-card:hover .pb2-gallery-card-icon { opacity: 1; }

.pb2-gallery-card-text {
  display: flex; flex-direction: column; gap: 1px;
  min-width: 0;
}
.pb2-gallery-card-name {
  font-size: 12px;
  font-weight: 500;
  color: inherit;
}
.pb2-gallery-card-desc {
  font-size: 10.5px;
  color: var(--pb2-text-faint);
  line-height: 1.35;
}

/* Drag-reorder visual states */
.pb2-section-item.dragging {
  opacity: 0.4;
  cursor: grabbing;
}
.pb2-section-item.drag-over-top {
  border-top: 2px solid var(--pb2-accent);
  padding-top: 5px;
}
.pb2-section-item.drag-over-bottom {
  border-bottom: 2px solid var(--pb2-accent);
  padding-bottom: 5px;
}

.pb2-status {
  border-bottom: 0.5px solid var(--pb2-border);
  padding: 14px 18px;
  flex: 0 0 auto;
}
.pb2-status-head { display: flex; align-items: center; gap: 8px; }
.pb2-status-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.pb2-status.is-live  .pb2-status-dot { background: var(--pb2-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pb2-accent) 22%, transparent); }
.pb2-status.is-draft .pb2-status-dot { background: var(--pb2-text-faint); box-shadow: 0 0 0 3px rgba(127,127,127,.14); }
.pb2-status-label { font-size: 12.5px; font-weight: 700; letter-spacing: .01em; }
.pb2-status.is-live .pb2-status-label { color: var(--pb2-accent); }
.pb2-status-since { font-size: 10.5px; color: var(--pb2-text-faint); margin-left: auto; }
.pb2-status-say { font-size: 11.5px; color: var(--pb2-text-dim); line-height: 1.55; margin-top: 7px; }
.pb2-status-url { font-family: var(--pb2-mono); font-size: 11px; color: var(--pb2-text); }
.pb2-status-acts { display: flex; gap: 7px; margin-top: 12px; }
.pb2-status-acts form { margin: 0; }
.pb2-status-btn {
  display: inline-block; text-align: center; text-decoration: none;
  border: 1px solid var(--pb2-border-strong, rgba(255,255,255,.2));
  background: none; color: var(--pb2-text);
  border-radius: 8px; padding: 7px 12px;
  font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit;
  white-space: nowrap;
}
.pb2-status-btn:hover { background: rgba(127,127,127,.08); }
.pb2-status-btn--go { background: var(--pb2-accent); border-color: var(--pb2-accent); color: #10160a; }
.pb2-status-btn--go:hover { filter: brightness(1.06); background: var(--pb2-accent); }
.pb2-status-btn--go:disabled { opacity: .4; cursor: not-allowed; }
.pb2-status-btn--off:hover { border-color: rgba(240,120,120,.5); color: #F0999B; }
.pb2-status-hint { font-size: 11px; color: var(--pb2-text-faint); margin-top: 8px; line-height: 1.5; }
.pb2-status-nav { margin: 10px 0 0; }
.pb2-status-navbtn {
  display: flex; align-items: center; gap: 8px; width: 100%;
  background: none; border: none; padding: 0; cursor: pointer;
  font-family: inherit; font-size: 11.5px; color: var(--pb2-text-dim);
}
.pb2-status-navbtn:hover { color: var(--pb2-text); }
.pb2-status-check {
  width: 14px; height: 14px; border-radius: 4px; flex: none;
  border: 1px solid var(--pb2-border-strong, rgba(255,255,255,.2));
}
.pb2-status-check.on { background: var(--pb2-accent); border-color: var(--pb2-accent); }

.pb2-pane-footer {
  border-top: 0.5px solid var(--pb2-border);
  padding: 12px 18px;
  display: flex; align-items: center; gap: 10px;
  font-size: 11px;
  color: var(--pb2-text-dim);
}
.pb2-pane-footer-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--pb2-accent);
}
.pb2-save-time {
  font-family: var(--pb2-mono);
  color: var(--pb2-text-faint);
  font-size: 10.5px;
  margin-left: auto;
}

/* PREVIEW */
.pb2-preview-col {
  background: var(--pb2-bg);
  display: flex; flex-direction: column;
  overflow: hidden;
}
.pb2-preview-bar {
  height: 38px;
  border-bottom: 0.5px solid var(--pb2-border);
  display: flex; align-items: center;
  padding: 0 16px;
  gap: 14px;
  background: var(--pb2-surface);
  flex-shrink: 0;
}
.pb2-url-bar {
  flex: 1;
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 5px 10px;
  font-family: var(--pb2-mono);
  font-size: 11px;
  color: var(--pb2-text-dim);
  display: flex; align-items: center; gap: 8px;
  height: 26px;
}
.pb2-url-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--pb2-accent); }
.pb2-url-meta { margin-left: auto; color: var(--pb2-text-faint); font-size: 10.5px; }

.pb2-preview-frame-wrap {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  background:
    repeating-linear-gradient(45deg, transparent 0 14px, rgba(255,255,255,0.012) 14px 15px);
}
.pb2-preview-frame-wrap::-webkit-scrollbar { width: 6px; }
.pb2-preview-frame-wrap::-webkit-scrollbar-thumb { background: var(--pb2-border-2); border-radius: 3px; }

.pb2-preview-frame {
  background: white;
  margin: 0 auto;
  border-radius: 6px;
  border: 0.5px solid var(--pb2-border-2);
  box-shadow: 0 30px 80px rgba(0,0,0,0.4);
  max-width: 1200px;
  transition: max-width 0.25s;
  width: 100%;
  height: 100%;
  min-height: 600px;
  display: block;
}
.pb2-preview-frame.device-tablet { max-width: 820px; }
.pb2-preview-frame.device-mobile { max-width: 420px; }

/* INSPECTOR */
.pb2-insp-header {
  padding: 14px 18px;
  border-bottom: 0.5px solid var(--pb2-border);
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 12px;
}
.pb2-insp-header-title { display: flex; align-items: center; gap: 10px; }
.pb2-insp-header-icon {
  width: 28px; height: 28px;
  background: var(--pb2-info-dim);
  color: var(--pb2-info);
  border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
}
.pb2-insp-header-name { font-size: 14px; font-weight: 500; }
.pb2-insp-header-sub {
  font-family: var(--pb2-mono);
  font-size: 10.5px;
  color: var(--pb2-text-faint);
  margin-top: 1px;
}
.pb2-insp-actions { display: flex; gap: 2px; }

.pb2-insp-tabs {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  border-bottom: 0.5px solid var(--pb2-border);
}
.pb2-insp-tab {
  background: transparent;
  border: 0;
  color: var(--pb2-text-dim);
  padding: 11px 0;
  font: inherit; font-size: 11.5px; font-weight: 500;
  cursor: pointer;
  position: relative;
  transition: color 0.12s;
}
.pb2-insp-tab:hover { color: var(--pb2-text); }
.pb2-insp-tab.active { color: var(--pb2-text); }
.pb2-insp-tab.active::after {
  content: '';
  position: absolute;
  left: 0; right: 0; bottom: -0.5px;
  height: 2px;
  background: var(--pb2-accent);
}

.pb2-insp-body {
  flex: 1;
  overflow-y: auto;
}
.pb2-insp-body::-webkit-scrollbar { width: 5px; }
.pb2-insp-body::-webkit-scrollbar-thumb { background: var(--pb2-border-2); border-radius: 2px; }

.pb2-insp-empty {
  padding: 60px 24px;
  text-align: center;
  color: var(--pb2-text-faint);
  font-size: 12px;
}
.pb2-insp-empty-icon {
  width: 48px; height: 48px;
  margin: 0 auto 14px;
  border-radius: 50%;
  background: var(--pb2-surface-2);
  display: flex; align-items: center; justify-content: center;
  color: var(--pb2-text-dim);
}
.pb2-insp-empty-title {
  font-size: 13px;
  color: var(--pb2-text);
  margin-bottom: 6px;
  font-weight: 500;
}
.pb2-insp-empty-hint { font-family: var(--pb2-mono); font-size: 10.5px; line-height: 1.5; }

/* The v1 _section.blade.php is rendered inside the inspector body.
   It uses .pb-field-row, .pb-field-label, .pb-input, .pb-textarea — we
   restyle those here to fit the v2 dark inspector. */
.pb2-insp-body .pb-section-block {
  /* hide the v1 accordion chrome — we don't need it in the inspector */
  border: 0;
  padding: 14px 18px;
}
.pb2-insp-body .pb-section-head {
  display: none; /* we have our own header */
}
.pb2-insp-body .pb-section-body {
  display: block !important;
  padding: 0;
}
.pb2-insp-body .pb-field-row {
  margin-bottom: 12px;
}
.pb2-insp-body .pb-field-label {
  display: block;
  font-size: 11px;
  color: var(--pb2-text-dim);
  margin-bottom: 5px;
  font-weight: 400;
}
.pb2-insp-body .pb-input,
.pb2-insp-body .pb-textarea,
.pb2-insp-body select.pb-input {
  width: 100%;
  background: var(--pb2-bg);
  border: 0.5px solid var(--pb2-border);
  color: var(--pb2-text);
  padding: 7px 10px;
  font-family: inherit;
  font-size: 12px;
  border-radius: 4px;
  transition: border-color 0.12s, background 0.12s;
}
.pb2-insp-body .pb-input { height: 30px; }
.pb2-insp-body .pb-textarea { resize: vertical; line-height: 1.5; padding: 8px 10px; }
.pb2-insp-body .pb-input:hover,
.pb2-insp-body .pb-textarea:hover { border-color: var(--pb2-border-2); }
.pb2-insp-body .pb-input:focus,
.pb2-insp-body .pb-textarea:focus {
  outline: 0;
  border-color: var(--pb2-accent);
  background: var(--pb2-surface-2);
}

/* hide v1 _section.blade.php's footer when rendered
   inside the v2 inspector. v1's `.pb-section-actions` had a duplicate
   "Delete section" button and an "Auto-saves as you type" hint that
   conflicted with the v2 inspector header's delete icon + footer status. */
.pb2-insp-body .pb-section-actions { display: none; }

/* ============================================================================
   Phase 2 per-type editor partials (Hero first)
   Field framework used by resources/views/tenant/pages/sections/_*.blade.php.
   All [data-field] inputs are picked up by G16 autosave automatically.
============================================================================ */

/* Tab panels — only the one matching the active tab is shown */
.pb2-insp-body .pb2-tab-panel { display: block; }
.pb2-insp-body .pb2-tab-panel[hidden] { display: none; }

/* Field groups — visual sections within a tab */
.pb2-insp-body .pb2-group {
  border-bottom: 0.5px solid var(--pb2-border);
  padding: 14px 18px;
}
.pb2-insp-body .pb2-group:last-child { border-bottom: 0; }

.pb2-insp-body .pb2-group-title {
  font-family: var(--pb2-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--pb2-text-dim);
  font-weight: 500;
  margin-bottom: 12px;
  display: flex; align-items: center; justify-content: space-between;
}
.pb2-insp-body .pb2-group-meta {
  font-family: var(--pb2-mono);
  font-size: 10px;
  color: var(--pb2-text-faint);
  font-weight: 400;
}

/* Fields */
.pb2-insp-body .pb2-field { margin-bottom: 12px; }
.pb2-insp-body .pb2-field:last-child { margin-bottom: 0; }
.pb2-insp-body .pb2-field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 12px;
}
.pb2-insp-body .pb2-field-row .pb2-field { margin-bottom: 0; }

.pb2-insp-body .pb2-field-label {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 11px;
  color: var(--pb2-text-dim);
  margin-bottom: 5px;
  font-weight: 400;
}
.pb2-insp-body .pb2-field-hint {
  font-family: var(--pb2-mono);
  font-size: 10px;
  color: var(--pb2-text-faint);
  font-weight: 400;
  margin-left: 8px;
  text-align: right;
}

/* Inputs */
.pb2-insp-body .pb2-input {
  width: 100%;
  background: var(--pb2-bg);
  border: 0.5px solid var(--pb2-border);
  color: var(--pb2-text);
  padding: 7px 10px;
  font-family: inherit;
  font-size: 12px;
  border-radius: 4px;
  height: 30px;
  transition: border-color 0.12s, background 0.12s;
}
.pb2-insp-body .pb2-textarea {
  height: auto;
  resize: vertical;
  line-height: 1.5;
  padding: 8px 10px;
  min-height: 64px;
}
.pb2-insp-body .pb2-input-sm { font-size: 11px; height: 26px; padding: 4px 8px; }
.pb2-insp-body .pb2-input-mono { font-family: var(--pb2-mono); font-size: 11px; }
.pb2-insp-body .pb2-input:hover { border-color: var(--pb2-border-2); }
.pb2-insp-body .pb2-input:focus {
  outline: 0;
  border-color: var(--pb2-accent);
  background: var(--pb2-surface-2);
}

/* Segmented control */
.pb2-insp-body .pb2-seg {
  display: flex;
  background: var(--pb2-bg);
  border-radius: 4px;
  padding: 2px;
  gap: 2px;
}
.pb2-insp-body .pb2-seg-btn {
  flex: 1;
  background: transparent;
  border: 0;
  color: var(--pb2-text-dim);
  padding: 6px 8px;
  font: inherit;
  font-size: 11px;
  border-radius: 3px;
  cursor: pointer;
  transition: all 0.12s;
}
.pb2-insp-body .pb2-seg-btn:hover { color: var(--pb2-text); }
.pb2-insp-body .pb2-seg-btn.active {
  background: var(--pb2-surface-3);
  color: var(--pb2-text);
}

/* Color picker row */
.pb2-insp-body .pb2-color-row {
  display: flex; gap: 6px; align-items: center;
}
.pb2-insp-body .pb2-color-swatch {
  width: 28px; height: 28px;
  border-radius: 4px;
  border: 0.5px solid var(--pb2-border-2);
  cursor: pointer;
  padding: 0;
  background: transparent;
}

/* Image tile / empty-state */
.pb2-insp-body .pb2-image-tile {
  display: grid;
  grid-template-columns: 56px 1fr;
  gap: 12px;
  background: var(--pb2-surface-2);
  border-radius: 4px;
  padding: 10px;
  align-items: center;
}
.pb2-insp-body .pb2-image-tile-thumb {
  width: 56px; height: 56px;
  border-radius: 4px;
  border: 0.5px solid var(--pb2-border-2);
  background: var(--pb2-bg);
}
.pb2-insp-body .pb2-image-tile-name {
  font-size: 11.5px;
  font-weight: 500;
  margin-bottom: 4px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.pb2-insp-body .pb2-image-tile-actions {
  display: flex; gap: 12px;
}
.pb2-insp-body .pb2-textlink {
  background: transparent; border: 0; padding: 0;
  font: inherit; font-size: 11px;
  color: var(--pb2-info);
  cursor: pointer;
}
.pb2-insp-body .pb2-textlink:hover { text-decoration: underline; }
.pb2-insp-body .pb2-textlink-danger { color: var(--pb2-danger); }

.pb2-insp-body .pb2-image-empty {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  width: 100%;
  padding: 22px;
  background: var(--pb2-bg);
  border: 1px dashed var(--pb2-border-2);
  border-radius: 6px;
  color: var(--pb2-text-dim);
  font: inherit; font-size: 12px;
  cursor: pointer;
  transition: all 0.12s;
}
.pb2-insp-body .pb2-image-empty:hover {
  border-color: var(--pb2-accent);
  color: var(--pb2-accent);
  background: rgba(190,242,100,0.04);
}
.pb2-insp-body .pb2-image-empty-icon { font-size: 18px; margin-bottom: 2px; }

/* Slider */
.pb2-insp-body .pb2-slider-row {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 5px;
}
.pb2-insp-body .pb2-slider-value {
  font-family: var(--pb2-mono);
  font-size: 11px;
  color: var(--pb2-text);
}
.pb2-insp-body input[type=range] {
  -webkit-appearance: none;
  appearance: none;
  width: 100%;
  height: 4px;
  background: var(--pb2-bg);
  border-radius: 2px;
  outline: none;
}
.pb2-insp-body input[type=range]::-webkit-slider-thumb {
  -webkit-appearance: none; appearance: none;
  width: 14px; height: 14px;
  border-radius: 50%;
  background: var(--pb2-accent);
  cursor: pointer;
  border: 2px solid var(--pb2-surface);
}

/* Checkbox row */
.pb2-insp-body .pb2-checkbox-row {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 0;
  font-size: 12px;
  cursor: pointer;
  color: var(--pb2-text);
}
.pb2-insp-body .pb2-checkbox-row input { accent-color: var(--pb2-accent); }

/* Add-row pseudo-button */
.pb2-insp-body .pb2-addrow {
  width: 100%;
  border: 1px dashed var(--pb2-border-2);
  border-radius: 4px;
  padding: 8px;
  text-align: center;
  color: var(--pb2-text-dim);
  font: inherit; font-size: 11px;
  cursor: pointer;
  background: transparent;
  margin-top: 4px;
  transition: all 0.12s;
}
.pb2-insp-body .pb2-addrow:hover {
  border-color: var(--pb2-accent);
  color: var(--pb2-accent);
}

/* Button list (Hero CTAs) */
.pb2-insp-body .pb2-btnlist { display: flex; flex-direction: column; gap: 6px; }
.pb2-insp-body .pb2-btnlist-item {
  display: grid;
  grid-template-columns: 14px 1fr 22px;
  gap: 8px;
  align-items: center;
  background: var(--pb2-surface-2);
  border-radius: 4px;
  padding: 8px 8px 8px 10px;
}
.pb2-insp-body .pb2-btnlist-handle {
  color: var(--pb2-text-faint);
  cursor: grab;
  font-size: 10px;
  user-select: none;
}
.pb2-insp-body .pb2-btnlist-fields {
  display: grid;
  grid-template-columns: 1fr 1fr 90px;
  gap: 6px;
}
.pb2-insp-body .pb2-btnlist-remove {
  background: transparent;
  border: 0;
  color: var(--pb2-text-faint);
  width: 22px; height: 22px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  line-height: 1;
}
.pb2-insp-body .pb2-btnlist-remove:hover {
  background: var(--pb2-danger);
  color: white;
}

/* Nav link list (similar to btnlist but with
   different fields layout + per-row meta column for open-in-new-tab) */
.pb2-insp-body .pb2-navlist { display: flex; flex-direction: column; gap: 6px; }
.pb2-insp-body .pb2-navlist-item {
  display: grid;
  grid-template-columns: 14px 1fr auto;
  gap: 8px;
  align-items: center;
  background: var(--pb2-surface-2);
  border-radius: 4px;
  padding: 8px 8px 8px 10px;
}
.pb2-insp-body .pb2-navlist-handle {
  color: var(--pb2-text-faint);
  cursor: grab;
  font-size: 10px;
  user-select: none;
}
.pb2-insp-body .pb2-navlist-fields {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 6px;
}
.pb2-insp-body .pb2-navlist-meta {
  display: flex;
  align-items: center;
  gap: 4px;
}
.pb2-insp-body .pb2-navlist-meta label {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 6px;
  border-radius: 4px;
  color: var(--pb2-text-faint);
  font-size: 11px;
  cursor: pointer;
}
.pb2-insp-body .pb2-navlist-meta label:hover { background: var(--pb2-bg); }
.pb2-insp-body .pb2-navlist-meta label input[type="checkbox"] { accent-color: var(--pb2-accent); }
.pb2-insp-body .pb2-navlist-meta label input[type="checkbox"]:checked + span { color: var(--pb2-accent); }
.pb2-insp-body .pb2-navlist-remove {
  background: transparent;
  border: 0;
  color: var(--pb2-text-faint);
  width: 22px; height: 22px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  line-height: 1;
}
.pb2-insp-body .pb2-navlist-remove:hover {
  background: var(--pb2-danger);
  color: white;
}
.pb2-insp-body .pb2-navlist-handle { cursor: grab; user-select: none; }
.pb2-insp-body .pb2-navlist-item.dragging { opacity: .45; }
.pb2-insp-body .pb2-navlist-item.drag-over-top { box-shadow: inset 0 2px 0 var(--pb2-accent, #BEF264); }
.pb2-insp-body .pb2-navlist-item.drag-over-bottom { box-shadow: inset 0 -2px 0 var(--pb2-accent, #BEF264); }

/* Details disclosure for "Add from existing pages" */
.pb2-insp-body .pb2-details { margin-top: 4px; }
.pb2-insp-body .pb2-details-summary {
  font-size: 11px;
  color: var(--pb2-text-dim);
  cursor: pointer;
  padding: 6px 0;
  user-select: none;
  list-style: none;
}
.pb2-insp-body .pb2-details-summary::-webkit-details-marker { display: none; }
.pb2-insp-body .pb2-details-summary::before {
  content: '▸';
  display: inline-block;
  margin-right: 6px;
  transition: transform 0.12s;
}
.pb2-insp-body .pb2-details[open] .pb2-details-summary::before { transform: rotate(90deg); }
.pb2-insp-body .pb2-details-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 4px 0 6px;
}
.pb2-insp-body .pb2-pagelink {
  background: transparent;
  border: 0;
  color: var(--pb2-text-dim);
  text-align: left;
  padding: 5px 8px;
  font-size: 11.5px;
  font: inherit;
  font-size: 11.5px;
  border-radius: 4px;
  cursor: pointer;
}
.pb2-insp-body .pb2-pagelink:hover { background: var(--pb2-surface-3); color: var(--pb2-text); }
.pb2-insp-body .pb2-pagelink .pb2-field-hint {
  margin-left: 6px;
  text-align: left;
}

/* Footer link columns (nested list editor) */
.pb2-insp-body .pb2-ftr-col {
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-ftr-col-head {
  display: grid;
  grid-template-columns: 14px 1fr 22px;
  gap: 6px;
  align-items: center;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-ftr-col-links {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 6px;
  padding-left: 18px;
}
.pb2-insp-body .pb2-ftr-link {
  display: grid;
  grid-template-columns: 1fr 1.3fr 22px;
  gap: 4px;
  align-items: center;
}
.pb2-insp-body .pb2-ftr-addlink {
  margin-left: 18px;
  font-size: 11px;
  padding: 3px 8px;
}

/* Stats row list editor */
.pb2-insp-body .pb2-statrow {
  display: grid;
  grid-template-columns: 14px 1fr auto;
  gap: 8px;
  align-items: center;
  background: var(--pb2-surface-2);
  border-radius: 4px;
  padding: 8px 8px 8px 10px;
  margin-bottom: 6px;
}
.pb2-insp-body .pb2-statrow-fields {
  display: grid;
  grid-template-columns: 80px 1fr 1.2fr;
  gap: 6px;
}

/* Pricing table plans list editor */
.pb2-insp-body .pb2-plan {
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 10px;
}
.pb2-insp-body .pb2-plan-head {
  display: grid;
  grid-template-columns: 14px 1fr auto auto;
  gap: 8px;
  align-items: center;
  margin-bottom: 10px;
}
.pb2-insp-body .pb2-plan-pos {
  font-size: 11px;
  font-weight: 500;
  color: var(--pb2-text-dim);
}
.pb2-insp-body .pb2-plan-featured {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 4px;
  color: var(--pb2-text-faint);
  font-size: 11px;
  cursor: pointer;
}
.pb2-insp-body .pb2-plan-featured:hover { background: var(--pb2-bg); }
.pb2-insp-body .pb2-plan-featured input[type="checkbox"] { accent-color: var(--pb2-accent); }
.pb2-insp-body .pb2-plan-featured input[type="checkbox"]:checked + span { color: var(--pb2-accent); }

.pb2-insp-body .pb2-plan-fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.pb2-insp-body .pb2-plan-price-row,
.pb2-insp-body .pb2-plan-cta-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}

.pb2-insp-body .pb2-plan-features {
  background: var(--pb2-bg);
  border-radius: 4px;
  padding: 8px;
  margin-top: 4px;
}
.pb2-insp-body .pb2-plan-features-label {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 500;
  color: var(--pb2-text-faint);
  margin-bottom: 6px;
}
.pb2-insp-body .pb2-plan-feature-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 6px;
}
.pb2-insp-body .pb2-plan-feature {
  display: grid;
  grid-template-columns: 1fr 22px;
  gap: 4px;
  align-items: center;
}
.pb2-insp-body .pb2-plan-addfeat {
  font-size: 10.5px;
  padding: 3px 8px;
}

/* feature_grid features list editor */
.pb2-insp-body .pb2-feat {
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 8px;
}
/* while a card is being dragged the whole list collapses
   to title rows so tall cards stay visible and droppable in the inspector. */
.pb2-insp-body #pb2-feat-list.pb2-feat-compact .pb2-feat-fields { display: none; }
.pb2-insp-body #pb2-feat-list.pb2-feat-compact .pb2-feat { padding: 6px 10px; }
.pb2-insp-body .pb2-feat-head {
  display: grid;
  grid-template-columns: 14px 38px 1fr 22px;
  gap: 6px;
  align-items: center;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-feat-icon {
  text-align: center;
  font-family: var(--pb2-mono);
}
.pb2-insp-body .pb2-feat-fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.pb2-insp-body .pb2-feat-cta-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}

/* logo_bar logos list editor */
.pb2-insp-body .pb2-logorow {
  display: grid;
  grid-template-columns: 14px 1fr auto;
  gap: 8px;
  align-items: center;
  background: var(--pb2-surface-2);
  border-radius: 4px;
  padding: 8px 8px 8px 10px;
  margin-bottom: 6px;
}
.pb2-insp-body .pb2-logorow {
  /* fields stack so Name/Link aren't slivers. */
  grid-template-columns: 14px 46px 1fr auto;
  align-items: start;
}
.pb2-insp-body .pb2-logorow-fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}
.pb2-insp-body .pb2-logo-scale {
  display: flex; align-items: center; gap: 6px; margin-left: auto;
  font-size: 10.5px; color: var(--pb2-text-dim, rgba(255,255,255,.6));
}
.pb2-insp-body .pb2-logo-scale input[type=range] { width: 76px; accent-color: var(--pb2-accent, #BEF264); }
.pb2-insp-body .pb2-logo-acts { flex-wrap: wrap; align-items: center; }
.pb2-insp-body .pb2-logo-thumb {
  width: 46px; height: 32px; border-radius: 4px;
  background: var(--pb2-surface-3, rgba(255,255,255,.06)) center/contain no-repeat;
  border: 1px solid var(--pb2-border, rgba(255,255,255,.12));
}
.pb2-insp-body .pb2-logo-acts { display: flex; gap: 4px; }
.pb2-insp-body .pb2-logo-btn {
  font-size: 10.5px; padding: 4px 7px; border-radius: 4px; cursor: pointer;
  border: 1px solid var(--pb2-border, rgba(255,255,255,.12));
  background: none; color: var(--pb2-text-dim, rgba(255,255,255,.6));
}
.pb2-insp-body .pb2-logo-btn:hover { color: var(--pb2-text, #f0f0f0); background: rgba(255,255,255,.06); }

/* FAQ accordion items list editor */
.pb2-insp-body .pb2-faqrow {
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-faqrow-head {
  display: grid;
  grid-template-columns: 14px 1fr auto auto;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-faqrow-pos {
  font-size: 11px;
  font-weight: 500;
  color: var(--pb2-text-dim);
}
.pb2-insp-body .pb2-faqrow-open {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 4px;
  color: var(--pb2-text-faint);
  font-size: 11px;
  cursor: pointer;
}
.pb2-insp-body .pb2-faqrow-open:hover { background: var(--pb2-bg); }
.pb2-insp-body .pb2-faqrow-open input[type="checkbox"] { accent-color: var(--pb2-accent); }
.pb2-insp-body .pb2-faqrow-open input[type="checkbox"]:checked + span { color: var(--pb2-accent); }
.pb2-insp-body .pb2-faqrow-fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

/* step_timeline steps list editor */
.pb2-insp-body .pb2-steprow {
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-steprow-head {
  display: grid;
  grid-template-columns: 14px 1fr 38px 22px;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}
.pb2-insp-body .pb2-steprow-pos {
  font-size: 11px;
  font-weight: 500;
  color: var(--pb2-text-dim);
}
.pb2-insp-body .pb2-steprow-fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pb2-insp-footer {
  border-top: 0.5px solid var(--pb2-border);
  padding: 10px 18px;
  font-family: var(--pb2-mono);
  font-size: 10px;
  color: var(--pb2-text-faint);
  display: flex; align-items: center; gap: 10px;
}
/* / -INLINE — one line: name, tag, number */
.pb2-section-item:has(.pb2-section-hidden) { grid-template-columns: 14px 18px minmax(0, 1fr) auto auto; }
.pb2-section-item .pb2-section-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pb2-section-hidden{font-size:10px;color:var(--pb2-text-faint);border:.5px dashed var(--pb2-border-2);border-radius:99px;padding:0 6px;white-space:nowrap;max-width:150px;overflow:hidden;text-overflow:ellipsis}
/* menu rows in the Nav section */
.sn-row.sn-new{box-shadow:0 0 0 2px var(--pb2-accent)}
.sn-insp{flex:1;overflow-y:auto;min-height:0}
.sn-row{border:.5px solid var(--pb2-border);border-radius:8px;padding:8px;margin-bottom:6px;background:var(--pb2-surface-2)}
.sn-row.drag{opacity:.4}.sn-row.over{box-shadow:inset 0 2px 0 var(--pb2-accent)}
.sn-r1{display:flex;align-items:center;gap:6px}
.sn-grip{cursor:grab;color:var(--pb2-text-faint);user-select:none;font-size:12px;width:12px}
.sn-in{flex:1;min-width:0;background:var(--pb2-bg);border:.5px solid var(--pb2-border);border-radius:6px;color:var(--pb2-text);font:inherit;font-size:12.5px;padding:5px 7px}
.sn-x{background:none;border:0;color:var(--pb2-text-faint);font-size:16px;cursor:pointer;line-height:1;padding:0 2px}
.sn-x:hover{color:var(--pb2-danger)}
.sn-r2{display:flex;align-items:center;gap:6px;margin-top:6px;padding-left:18px;flex-wrap:wrap}
.sn-tgt{font-size:11.5px;color:var(--pb2-text-dim);flex:1 1 100%;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sn-tgt code{font-family:var(--pb2-mono,monospace);font-size:11px;color:var(--pb2-text)}
.sn-chip{font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px;background:var(--pb2-surface-3);color:var(--pb2-text);margin-right:4px}
.sn-warn{font-size:10px;color:#F0C46A;border:.5px solid rgba(240,196,106,.5);border-radius:99px;padding:0 6px;margin-left:4px}
.sn-seg{display:inline-flex;background:var(--pb2-bg);border-radius:6px;padding:2px;gap:1px}
.sn-seg button{border:0;background:none;color:var(--pb2-text-dim);font:inherit;font-size:11px;padding:3px 6px;border-radius:4px;cursor:pointer}
.sn-seg button.on{background:var(--pb2-surface-3);color:var(--pb2-text)}
.sn-tab{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--pb2-text-dim);margin-left:auto;cursor:pointer}
.sn-add{display:flex;gap:6px;margin-top:8px;position:relative}
.sn-add .pb2-btn{font-size:12px}
.sn-pop{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:20;background:var(--pb2-surface);border:.5px solid var(--pb2-border-2);border-radius:8px;padding:4px;box-shadow:0 12px 30px rgba(0,0,0,.45)}
.sn-pop button{display:flex;justify-content:space-between;width:100%;background:none;border:0;color:var(--pb2-text);font:inherit;font-size:12.5px;padding:7px 8px;border-radius:6px;cursor:pointer;text-align:left}
.sn-pop button:hover{background:var(--pb2-surface-2)}
.sn-pop small{color:var(--pb2-text-faint);font-family:var(--pb2-mono,monospace)}
.pb2-insp-body .sn-legend{font-size:12px;line-height:1.55;border:.5px dashed var(--pb2-border-2);border-radius:8px;padding:9px 11px;margin-bottom:10px}
.pb2-insp-body .sn-legend b{color:var(--pb2-text);font-weight:600}
.pb2-insp-body .sn-in{width:auto}
/* controls fit the panel; it never scrolls sideways. */
.pb2-insp-body { overflow-x: hidden; }
.pb2-insp-body .pb2-field-row > * { min-width: 0; }
.pb2-insp-body .pb2-field-row:has(.pb2-seg) { grid-template-columns: 1fr; }
.pb2-insp-body .pb2-seg { min-width: 0; }
.pb2-insp-body .pb2-seg-btn { min-width: 0; white-space: nowrap; }
.pb2-insp-body input[type="range"] { min-width: 0; max-width: 100%; }

.pb2-insp-footer .pb2-dirty-note { font-family: var(--pb2-mono); }
.pb2-insp-footer .pb2-dirty-note.is-dirty { color: var(--pb2-accent); }
.pb2-btn[data-pb2-save].is-dirty { box-shadow: 0 0 0 2px var(--pb2-accent); }
.pb2-insp-footer kbd {
  background: var(--pb2-bg);
  border: 0.5px solid var(--pb2-border);
  border-radius: 3px;
  padding: 1px 5px;
  font-family: var(--pb2-mono);
  font-size: 10px;
  color: var(--pb2-text);
}

/* responsive collapse */
@media (max-width: 1200px) {
  .pb2-topbar { grid-template-columns: 240px 1fr 320px; }
  /* two columns since the section list became a
     slide-in panel; three left an empty 240px column under 1200px. */
  .pb2-layout { grid-template-columns: 1fr 320px; }
}
@media (max-width: 900px) {
  .pb2-topbar { grid-template-columns: 1fr; height: auto; }
  .pb2-topbar-left, .pb2-topbar-center { display: none; }
  .pb2-layout { grid-template-columns: 1fr; }
  .pb2-pane, .pb2-pane-right { display: none; }
  .pb2-preview-col { display: flex; }
}

/* =====================================================================
   mockup reskin. Cascade-layer overrides on the
   existing pb2-* vocabulary; no selector renamed, no partial touched.
   ===================================================================== */
:root {
  --pb2-surface:   #1c1c1c;
  --pb2-surface-2: #222222;
  --pb2-surface-3: #262626;
  --pb2-border:    rgba(255,255,255,.13);
  --pb2-border-2:  rgba(255,255,255,.22);
}

/* --- slide-in sections panel ------------------------------------- */
.pb2-sections-panel {
  position: absolute;
  top: 0; bottom: 0; left: 0;
  width: 300px;
  z-index: 60;
  transform: translateX(-105%);
  transition: transform .16s ease;
  border-right: .5px solid var(--pb2-border-2);
  box-shadow: 18px 0 50px rgba(0,0,0,.45);
}
.pb2-sections-panel.open { transform: none; }
.pb2-sections-btn { display: inline-flex; align-items: center; gap: 7px; margin-right: 10px; }
.pb2-sections-count {
  font-size: 10px; font-weight: 700; font-family: ui-monospace, monospace;
  background: var(--pb2-accent); color: #0a0a0a;
  border-radius: 999px; padding: 1px 7px;
}

/* --- inspector tabs: lime underline ------------------------------- */
.pb2-insp-tab {
  background: none !important;
  border: none;
  border-bottom: 2px solid transparent !important;
  border-radius: 0 !important;
  font-weight: 600;
  letter-spacing: .01em;
}
.pb2-insp-tab.active {
  color: var(--pb2-text) !important;
  border-bottom-color: var(--pb2-accent) !important;
}

/* --- groups + fields: mockup micro-labels -------------------------- */
.pb2-insp-body .pb2-group { border-bottom: .5px solid var(--pb2-border); padding: 13px 16px; }
.pb2-insp-body .pb2-group-title,
.pb2-insp-body .pb2-field-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .07em;
  font-weight: 650;
  color: var(--pb2-text-dim, rgba(255,255,255,.55));
}
.pb2-insp-body .pb2-field-label { font-size: 11px; text-transform: none; letter-spacing: 0; font-weight: 500; }
.pb2-insp-body .pb2-field-hint { font-size: 10.5px; opacity: .45; line-height: 1.5; }

/* --- inputs: soft fill, 8px radius --------------------------------- */
.pb2-insp-body .pb2-input,
.pb2-insp-body .pb2-textarea,
.pb2-insp-body select.pb2-input {
  background: rgba(255,255,255,.07);
  border: .5px solid var(--pb2-border);
  border-radius: 8px;
  /* form controls don't inherit color, so a
     bare .pb2-textarea rendered black text on this dark panel. */
  color: var(--pb2-text);
}
.pb2-insp-body .pb2-input:focus,
.pb2-insp-body .pb2-textarea:focus {
  border-color: var(--pb2-accent);
  outline: none;
}

/* --- sliders + checkboxes: lime ------------------------------------ */
.pb2-insp-body input[type="range"] { accent-color: var(--pb2-accent); }
.pb2-insp-body input[type="checkbox"] { accent-color: var(--pb2-accent); }
/* checkbox rows render as mockup toggle switches. */
.pb2-insp-body .pb2-checkbox-row input[type="checkbox"] {
  appearance: none;
  -webkit-appearance: none;
  width: 32px; height: 18px;
  border-radius: 999px;
  background: var(--pb2-surface-3);
  border: .5px solid var(--pb2-border);
  position: relative;
  cursor: pointer;
  transition: background .15s;
  flex-shrink: 0;
  margin: 0;
}
.pb2-insp-body .pb2-checkbox-row input[type="checkbox"]::after {
  content: '';
  position: absolute;
  width: 12px; height: 12px;
  border-radius: 50%;
  background: #8a8a8a;
  top: 2px; left: 2px;
  transition: all .15s;
}
.pb2-insp-body .pb2-checkbox-row input[type="checkbox"]:checked {
  background: var(--pb2-accent);
  border-color: transparent;
}
.pb2-insp-body .pb2-checkbox-row input[type="checkbox"]:checked::after {
  left: 17px;
  background: #0a0a0a;
}
.pb2-insp-body .pb2-checkbox-row { display: flex; align-items: center; gap: 9px; justify-content: space-between; flex-direction: row-reverse; cursor: pointer; }
.pb2-insp-body .pb2-slider-value {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 10.5px;
  color: var(--pb2-accent);
}

/* --- section list rows: mockup feel -------------------------------- */
.pb2-section-list .pb2-section-item { border-radius: 7px; }
.pb2-section-list .pb2-section-item:hover { background: rgba(255,255,255,.06); }
.pb2-section-list .pb2-section-item.selected {
  background: rgba(190,242,100,.09);
  outline: .5px solid rgba(190,242,100,.3);
}

/* --- 9-point anchor picker ----------------------- */
.pb2-anchor {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
  width: 84px;
}
.pb2-anchor-dot {
  aspect-ratio: 1;
  border: .5px solid var(--pb2-border);
  border-radius: 5px;
  background: var(--pb2-surface-3);
  position: relative;
  cursor: pointer;
  padding: 0;
}
.pb2-anchor-dot:hover { border-color: var(--pb2-border-2); }
.pb2-anchor-dot.on { border-color: var(--pb2-accent); }
.pb2-anchor-dot.on::after {
  content: '';
  position: absolute;
  inset: 30%;
  border-radius: 50%;
  background: var(--pb2-accent);
}

/* hints read like the mockup */
.pb2-insp-body .pb2-field-hint {
  font-family: 'Inter', -apple-system, sans-serif;
  text-align: left;
  font-size: 10.5px;
  opacity: .45;
  line-height: 1.5;
}

/* --- preview: framed canvas ----------------------------------------- */
.pb2-preview-frame-wrap { padding: 14px; background: #0d0d0d; }
.pb2-preview-frame {
  border-radius: 12px;
  border: .5px solid var(--pb2-border-2);
  background: #fff;
}

/* media picker modal */
#pb2-media-modal { display:none; position:fixed; inset:0; z-index:200; }
.pb2-media-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.6); }
.pb2-media-dialog {
  position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
  width:min(760px,92vw); max-height:82vh; display:flex; flex-direction:column;
  background:var(--pb2-surface,#1c1c1c); border:.5px solid var(--pb2-border-2,rgba(255,255,255,.22));
  border-radius:14px; overflow:hidden; box-shadow:0 30px 80px rgba(0,0,0,.5);
}
.pb2-media-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:.5px solid var(--pb2-border,rgba(255,255,255,.13)); font-size:13.5px; font-weight:650; }
.pb2-media-close { background:none; border:none; color:var(--pb2-text-dim,rgba(255,255,255,.55)); font-size:22px; line-height:1; cursor:pointer; }
.pb2-media-body { padding:16px 18px; overflow-y:auto; }
.pb2-media-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; }
.pb2-media-cell { aspect-ratio:1; padding:0; border:.5px solid var(--pb2-border,rgba(255,255,255,.13)); border-radius:9px; overflow:hidden; cursor:pointer; background:#111; }
.pb2-media-cell:hover { border-color:var(--pb2-accent,#BEF264); }
.pb2-media-cell img { width:100%; height:100%; object-fit:cover; display:block; }
.pb2-media-empty { color:var(--pb2-text-dim,rgba(255,255,255,.5)); font-size:13px; text-align:center; padding:30px; }
</style>
@endpush

@section('content')

<div class="pb2-shell">

  {{-- ============ TOPBAR ============ --}}
  <div class="pb2-topbar">
    <div class="pb2-topbar-left">
      <a href="{{ $backUrl }}" class="pb2-back-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="15 18 9 12 15 6"/></svg>
        Pages
      </a>
      <div class="pb2-page-title">
        <b>{{ $page->title }}</b>
        <span style="opacity:.5">·</span>
        <span>{{ $page->is_home ? '/' : '/' . $page->slug }}</span>
      </div>
    </div>

    <div class="pb2-topbar-center">
      <div class="pb2-device-toggle">
        <button class="pb2-device-btn active" data-device="desktop" type="button">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          Desktop
        </button>
        <button class="pb2-device-btn" data-device="tablet" type="button">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="2" width="16" height="20" rx="2"/></svg>
          Tablet
        </button>
        <button class="pb2-device-btn" data-device="mobile" type="button">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="2" width="14" height="20" rx="2"/></svg>
          Mobile
        </button>
      </div>
      @if($isMarketing ?? false)
      {{-- dashed lines at the page width, off by default --}}
      <button class="pb2-guides-btn" id="pb2-guides-btn" type="button" aria-pressed="false" title="Show the page width on the preview">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="3 3"><line x1="6" y1="2" x2="6" y2="22"/><line x1="18" y1="2" x2="18" y2="22"/></svg>
        Guides
      </button>
      @endif
    </div>

    <div class="pb2-topbar-right">
      {{-- replaces the phase-4 undo/redo placeholders --}}
      <button class="pb2-icon-btn" type="button" id="pb2-history-btn" title="History — rewind this page">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
      </button>
      <div class="pb2-topbar-divider"></div>
      @unless($isMarketing ?? false)
      <button type="button" class="pb2-btn" id="pb2-bk-toggle" title="Brand Kit — copy your saved brand colors">&#9670; Brand Kit</button>
      @endunless
      <a href="{{ $previewUrl }}" target="_blank" class="pb2-btn" title="Open live in new tab">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Preview
      </a>
      <button class="pb2-btn pb2-btn-primary" type="button" data-pb2-save onclick="savePageSettings()">Save</button>
    </div>
  </div>

@unless($isMarketing ?? false)
  {{-- Brand Kit floating reference card (copy-from palette) --}}
  <style>
  #pb2-bk-card{position:fixed;top:64px;right:336px;width:300px;z-index:1200;background:#151515;border:0.5px solid rgba(255,255,255,.16);border-radius:12px;box-shadow:0 26px 64px -22px rgba(0,0,0,.85);color:#f1f1f1;font-size:13px}
  #pb2-bk-card[hidden]{display:none}
  #pb2-bk-card .bkh{display:flex;align-items:center;justify-content:space-between;padding:11px 13px;border-bottom:0.5px solid rgba(255,255,255,.09);background:#1d1d1d;border-radius:12px 12px 0 0}
  #pb2-bk-card .bkh b{font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px}
  #pb2-bk-card .bkh b em{font-style:normal;font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-accent,#3FD16B);background:rgba(63,209,107,.13);padding:2px 6px;border-radius:4px;font-weight:600}
  #pb2-bk-card .bkx{background:0;border:0;color:rgba(255,255,255,.4);font-size:18px;line-height:1;cursor:pointer}
  #pb2-bk-card .bkx:hover{color:#fff}
  #pb2-bk-card .bknote{padding:10px 13px;font-size:11px;color:rgba(255,255,255,.62);line-height:1.5;border-bottom:0.5px solid rgba(255,255,255,.09);background:rgba(63,209,107,.10)}
  #pb2-bk-rows{padding:10px 13px;display:flex;flex-direction:column;gap:7px;max-height:48vh;overflow-y:auto}
  #pb2-bk-card .bkrow{display:flex;align-items:center;gap:8px}
  #pb2-bk-card .bksw{width:30px;height:30px;border-radius:7px;border:0.5px solid rgba(255,255,255,.16);flex:0 0 auto;cursor:pointer;position:relative;overflow:hidden}
  #pb2-bk-card .bksw input{position:absolute;inset:-4px;opacity:0;cursor:pointer;border:0;padding:0;width:140%;height:140%}
  #pb2-bk-card .bkname{flex:1;min-width:0;background:rgba(255,255,255,.05);border:0.5px solid rgba(255,255,255,.16);border-radius:6px;color:#f1f1f1;font-size:12px;padding:6px 8px;font-family:inherit}
  #pb2-bk-card .bkname:focus{outline:0;border-color:var(--ia-accent,#3FD16B)}
  #pb2-bk-card .bkhex{font-family:ui-monospace,monospace;font-size:11px;color:rgba(255,255,255,.55);flex:0 0 auto;min-width:62px;text-align:right}
  #pb2-bk-card .bkcopy{font-family:ui-monospace,monospace;font-size:10px;color:rgba(255,255,255,.62);background:#242424;border:0.5px solid rgba(255,255,255,.16);padding:5px 9px;border-radius:6px;cursor:pointer;flex:0 0 auto}
  #pb2-bk-card .bkcopy:hover{border-color:var(--ia-accent,#3FD16B);color:var(--ia-accent,#3FD16B)}
  #pb2-bk-card .bkcopy.done{color:var(--ia-accent,#3FD16B);border-color:var(--ia-accent,#3FD16B);background:rgba(63,209,107,.13)}
  #pb2-bk-card .bkdel{background:0;border:0;color:rgba(255,255,255,.4);font-size:16px;line-height:1;cursor:pointer;flex:0 0 auto;padding:0 2px}
  #pb2-bk-card .bkdel:hover{color:#EF4444}
  #pb2-bk-card .bkfoot{display:flex;align-items:center;justify-content:space-between;padding:10px 13px;border-top:0.5px solid rgba(255,255,255,.09)}
  #pb2-bk-card .bkadd{background:0;border:0.5px dashed rgba(255,255,255,.16);color:rgba(255,255,255,.62);font-size:11.5px;padding:7px 11px;border-radius:6px;cursor:pointer}
  #pb2-bk-card .bkadd:hover{border-color:var(--ia-accent,#3FD16B);color:var(--ia-accent,#3FD16B)}
  #pb2-bk-card .bkstatus{font-family:ui-monospace,monospace;font-size:10px;color:rgba(255,255,255,.4)}
  #pb2-bk-toggle.on{background:rgba(63,209,107,.13);color:var(--ia-accent,#3FD16B);border-color:var(--ia-accent,#3FD16B)}
  @media(max-width:1100px){#pb2-bk-card{right:14px;top:60px}}
  </style>
  <div id="pb2-bk-card" data-save-url="{{ route('tenant.pages.brand-kit.save') }}" hidden>
    <div class="bkh"><b>&#9670; Brand Kit <em>reference</em></b><button type="button" class="bkx" id="pb2-bk-close" aria-label="Close">&times;</button></div>
    <div class="bknote">Copy a value, paste it into any section field. A saved palette &mdash; it doesn&rsquo;t change sections automatically.</div>
    <div id="pb2-bk-rows">
      @foreach($brandKit as $c)
        @php $bkHex = preg_match('/^#[0-9a-fA-F]{6}$/', $c['value']) ? $c['value'] : '#888888'; @endphp
        <div class="bkrow" data-bk-row data-bk-role="{{ $c['role'] ?? '' }}">
          <label class="bksw" style="background: {{ $c['value'] }}"><input type="color" value="{{ $bkHex }}" data-bk-color></label>
          <input type="text" class="bkname" value="{{ $c['name'] }}" data-bk-name placeholder="Name">
          <code class="bkhex" data-bk-hex>{{ $c['value'] }}</code>
          <button type="button" class="bkcopy" data-bk-copy title="Copy">Copy</button>
          <button type="button" class="bkdel" data-bk-del title="Remove">&times;</button>
        </div>
      @endforeach
    </div>
    <div class="bkfoot"><button type="button" class="bkadd" id="pb2-bk-add">+ Add color</button><span class="bkstatus" id="pb2-bk-status">Saved</span></div>
  </div>
  <script>
  (function(){
    var card=document.getElementById('pb2-bk-card'), toggle=document.getElementById('pb2-bk-toggle');
    if(!card||!toggle) return;
    var rows=document.getElementById('pb2-bk-rows'), statusEl=document.getElementById('pb2-bk-status');
    var SAVE_URL=card.getAttribute('data-save-url'), saveT=null;
    function csrf(){var m=document.querySelector('meta[name="csrf-token"]');var i=document.querySelector('input[name="_token"]');return (m&&m.content)||(i&&i.value)||'';}
    function status(t){if(statusEl)statusEl.textContent=t;}
    function setOpen(o){card.hidden=!o;toggle.classList.toggle('on',o);}
    toggle.addEventListener('click',function(){setOpen(card.hidden);});
    document.getElementById('pb2-bk-close').addEventListener('click',function(){setOpen(false);});
    function serialize(){
      return [].slice.call(rows.querySelectorAll('[data-bk-row]')).map(function(r){
        return {name:((r.querySelector('[data-bk-name]').value||'').trim())||'Color',
                value:(r.querySelector('[data-bk-hex]').textContent||'').trim(),
                role:r.getAttribute('data-bk-role')||''};
      }).filter(function(x){return x.value;});
    }
    function save(){
      clearTimeout(saveT); status('Saving\u2026');
      saveT=setTimeout(function(){
        fetch(SAVE_URL,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf(),'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({colors:serialize()})})
          .then(function(r){return r.json();}).then(function(d){status(d&&d.ok?'Saved':'Save failed');})
          .catch(function(){status('Save failed');});
      },350);
    }
    function wireRow(r){
      var color=r.querySelector('[data-bk-color]'), sw=r.querySelector('.bksw'), hex=r.querySelector('[data-bk-hex]'), copy=r.querySelector('[data-bk-copy]'), del=r.querySelector('[data-bk-del]'), name=r.querySelector('[data-bk-name]');
      color.addEventListener('input',function(){sw.style.background=color.value;hex.textContent=color.value;save();});
      name.addEventListener('input',save);
      copy.addEventListener('click',function(){var v=(hex.textContent||'').trim();if(navigator.clipboard)navigator.clipboard.writeText(v).catch(function(){});var o=copy.textContent;copy.textContent='Copied \u2713';copy.classList.add('done');setTimeout(function(){copy.textContent=o;copy.classList.remove('done');},1100);});
      del.addEventListener('click',function(){r.remove();save();});
    }
    [].slice.call(rows.querySelectorAll('[data-bk-row]')).forEach(wireRow);
    document.getElementById('pb2-bk-add').addEventListener('click',function(){
      var r=document.createElement('div');
      r.className='bkrow'; r.setAttribute('data-bk-row',''); r.setAttribute('data-bk-role','');
      r.innerHTML='<label class="bksw" style="background:#3FD16B"><input type="color" value="#3FD16B" data-bk-color></label>'
        +'<input type="text" class="bkname" value="" data-bk-name placeholder="Name">'
        +'<code class="bkhex" data-bk-hex>#3FD16B</code>'
        +'<button type="button" class="bkcopy" data-bk-copy title="Copy">Copy</button>'
        +'<button type="button" class="bkdel" data-bk-del title="Remove">\u00D7</button>';
      rows.appendChild(r); wireRow(r); r.querySelector('[data-bk-name]').focus(); save();
    });
  })();
  </script>
  @endunless

  {{-- ============ MAIN LAYOUT ============ --}}
  <div class="pb2-layout">

    {{-- LEFT: section list — now a slide-in panel
         (same DOM, same Sortable/visibility/selection bindings). Clicking
         a section item closes it. --}}

    {{-- CENTER: live preview --}}
    <div class="pb2-preview-col">
      <div class="pb2-preview-bar">
        {{-- sections moved into the inspector column --}}
        <div class="pb2-url-bar">
          <div class="pb2-url-dot"></div>
          <span>{{ parse_url($previewUrl, PHP_URL_HOST) }}{{ $page->is_home ? '/' : '/' . $page->slug }}</span>
          <span class="pb2-url-meta">
            @if($page->is_published) Live @else Draft · unpublished @endif
          </span>
        </div>
      </div>

      <div class="pb2-preview-frame-wrap">
        <iframe id="pb2-preview" class="pb2-preview-frame" src="{{ $previewSrc }}"></iframe>
      </div>
    </div>

    {{-- RIGHT: inspector --}}
    <aside class="pb2-pane pb2-pane-right" id="pb2-inspector">
    {{-- publishing had no control anywhere in the app.
         This box states who can see the page right now, then offers the one
         action that changes it. --}}
    @php
      $pubUrl  = $page->is_home ? '/' : '/' . $page->slug;
      $pubHost = parse_url($previewUrl ?? '', PHP_URL_HOST);
      $pubEmpty = $sections->count() === 0;
    @endphp
    <div class="pb2-status {{ $page->is_published ? 'is-live' : 'is-draft' }}">
      <div class="pb2-status-head">
        <span class="pb2-status-dot"></span>
        <span class="pb2-status-label">{{ $page->is_published ? 'Live' : 'Draft' }}</span>
        @if($page->is_published && $page->published_at)
          <span class="pb2-status-since">since {{ tlocal_date($page->published_at, 'M j') }}</span>
        @endif
      </div>

      <div class="pb2-status-say">
        {{-- a guide never has a public URL. --}}
        @if(($page->kind ?? 'page') === 'howto')
          @if($page->is_published)
            Shops on a qualifying plan see this on their Help page. Others see it locked or not at all, per its gate.
          @else
            Only you can see this. No shop sees a draft guide.
          @endif
        @elseif($page->is_published)
          Anyone can visit <span class="pb2-status-url">{{ $pubHost }}{{ $pubUrl }}</span>.
        @else
          Only you can see this. Visitors to <span class="pb2-status-url">{{ $pubUrl }}</span> get a 404.
        @endif
      </div>

      <div class="pb2-status-acts">
        @if($page->is_published)
          <a class="pb2-status-btn" href="{{ rtrim($previewUrl ?? '', '/') . $pubUrl }}" target="_blank" rel="noopener">View live ↗</a>
          <form method="POST" action="{{ $updateUrl }}" style="flex:1">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="set_published">
            <input type="hidden" name="is_published" value="0">
            <button type="submit" class="pb2-status-btn pb2-status-btn--off" style="width:100%">Unpublish</button>
          </form>
        @else
          <form method="POST" action="{{ $updateUrl }}" style="flex:1">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="set_published">
            <input type="hidden" name="is_published" value="1">
            <button type="submit" class="pb2-status-btn pb2-status-btn--go" style="width:100%"
                    @disabled($pubEmpty)>Publish page</button>
          </form>
        @endif
      </div>

      @if($pubEmpty && !$page->is_published)
        <div class="pb2-status-hint">Add a section first — there's nothing on this page yet.</div>
      @endif

      @if($isMarketing ?? false)
        @include('admin.partials.nav-status', ['page' => $page])
      @elseif(!$page->is_home)
        @include('tenant.pages._nav-status', ['page' => $page])
      @endif
    </div>

    {{-- the page's title, description and share
         image for Google and link previews. These live in the page head, so
         nothing on the page shows them: the legend says so plainly. Saved by
         its own op with its own Save button; section edits are untouched. --}}
    @if(($page->kind ?? 'page') !== 'howto')
    @php
      $ssMkt     = $isMarketing ?? false;
      $ssTenant  = \App\Models\Tenant::find($page->tenant_id);
      $ssOwnImg  = \App\Support\Brand::storagePublicUrl($page->og_image_url);
      $ssFallImg = $ssMkt ? \App\Support\Brand::url('og') : \App\Support\Seo::shareImageFallback($ssTenant);
      $ssSite    = $ssMkt ? 'Intake' : (string) ($ssTenant->name ?? '');
      $ssCustom  = filled($page->meta_title) || filled($page->meta_description) || filled($page->og_image_url);
      $ssHost    = (string) parse_url($previewUrl ?? '', PHP_URL_HOST);
      $ssPath    = $page->is_home ? '/' : '/' . $page->slug;
    @endphp
    <style>
    .pb2-ss { border-bottom: 0.5px solid var(--pb2-border); flex: 0 0 auto; }
    .pb2-ss-head { display: flex; align-items: center; gap: 8px; width: 100%; padding: 11px 18px;
      background: none; border: none; cursor: pointer; font-family: inherit; color: var(--pb2-text); text-align: left; }
    .pb2-ss-title { font-size: 12px; font-weight: 600; }
    .pb2-ss-state { font-size: 10.5px; color: var(--pb2-text-faint); margin-left: auto; }
    .pb2-ss-state.is-custom { color: var(--pb2-accent); }
    body.ia-theme-b .pb2-ss-state.is-custom { color: #3F6212; }
    .pb2-ss-chev { color: var(--pb2-text-faint); transition: transform .15s; flex: none; }
    .pb2-ss-head[aria-expanded="true"] .pb2-ss-chev { transform: rotate(180deg); }
    .pb2-ss-body { padding: 0 18px 14px; }
    .pb2-ss-legend { font-size: 11px; color: var(--pb2-text-dim); line-height: 1.5; margin-bottom: 12px; }
    .pb2-ss-field { margin-bottom: 11px; }
    .pb2-ss-label { display: flex; justify-content: space-between; align-items: baseline; font-size: 11px;
      font-weight: 500; color: var(--pb2-text-dim); margin-bottom: 5px; }
    .pb2-ss-count, .pb2-ss-hint { font-family: var(--pb2-mono); font-size: 10px; color: var(--pb2-text-faint); }
    .pb2-ss-count.is-over { color: #F0C46A; }
    .pb2-ss-input { width: 100%; box-sizing: border-box; background: rgba(255,255,255,.07); border: .5px solid var(--pb2-border);
      border-radius: 8px; color: var(--pb2-text); padding: 7px 10px; font-family: inherit; font-size: 12px; line-height: 1.45; }
    body.ia-theme-b .pb2-ss-input { background: rgba(15,20,25,.04); }
    .pb2-ss-input:focus { border-color: var(--pb2-accent); outline: none; }
    .pb2-ss-input::placeholder { color: var(--pb2-text-faint); }
    textarea.pb2-ss-input { resize: vertical; min-height: 58px; }
    .pb2-ss-img { display: flex; gap: 10px; align-items: center; }
    .pb2-ss-thumb { width: 96px; aspect-ratio: 1200 / 630; flex: none; border-radius: 6px; overflow: hidden;
      border: .5px solid var(--pb2-border); background: rgba(127,127,127,.08); position: relative;
      display: flex; align-items: center; justify-content: center; font-size: 10px; color: var(--pb2-text-faint); text-align: center; line-height: 1.3; }
    .pb2-ss-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pb2-ss-thumb.is-default img { opacity: .45; }
    .pb2-ss-tag { position: absolute; left: 4px; bottom: 4px; font-size: 9px; padding: 1px 5px; border-radius: 4px;
      background: rgba(0,0,0,.6); color: #fff; }
    .pb2-ss-imgacts { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .pb2-ss-imgacts .pb2-status-btn { padding: 5px 10px; font-size: 11.5px; }
    .pb2-ss-preview { margin-top: 12px; padding: 10px 11px; border-radius: 8px; background: rgba(127,127,127,.07); }
    .pb2-ss-pv-cap { font-size: 9.5px; text-transform: uppercase; letter-spacing: .07em; font-weight: 650; color: var(--pb2-text-faint); margin-bottom: 6px; }
    .pb2-ss-pv-url { font-family: var(--pb2-mono); font-size: 10.5px; color: var(--pb2-text-dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pb2-ss-pv-title { font-size: 13px; font-weight: 600; color: var(--pb2-info); margin: 2px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pb2-ss-pv-desc { font-size: 11px; color: var(--pb2-text-dim); line-height: 1.45;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .pb2-ss-pv-desc.is-empty { font-style: italic; color: var(--pb2-text-faint); }
    .pb2-ss-foot { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
    .pb2-ss-msg { font-size: 11px; color: var(--pb2-text-faint); flex: 1; min-width: 0; }
    .pb2-ss-msg.is-error { color: var(--pb2-danger, #F87171); }
    </style>
    <div class="pb2-ss" id="pb2-ss"
         data-update-url="{{ $updateUrl }}"
         data-page-title="{{ $page->title }}"
         data-site="{{ $ssSite }}"
         data-mkt="{{ $ssMkt ? '1' : '0' }}"
         data-fallback-img="{{ $ssFallImg }}">
      <button type="button" class="pb2-ss-head" id="pb2-ss-toggle" aria-expanded="false" aria-controls="pb2-ss-body">
        <span class="pb2-ss-title">Search &amp; sharing</span>
        <span class="pb2-ss-state {{ $ssCustom ? 'is-custom' : '' }}" id="pb2-ss-state">{{ $ssCustom ? 'Custom' : 'Default' }}</span>
        <svg class="pb2-ss-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="pb2-ss-body" id="pb2-ss-body" hidden>
        <div class="pb2-ss-legend">
          What Google shows for this page, and the card people see when a link to it is shared.
          Nothing on the page itself changes. Leave a field blank to use the default shown in grey.
        </div>

        <div class="pb2-ss-field">
          <label class="pb2-ss-label" for="pb2-ss-mt">Title <span class="pb2-ss-count" id="pb2-ss-mt-count"></span></label>
          <input class="pb2-ss-input" id="pb2-ss-mt" type="text" maxlength="120"
                 value="{{ $page->meta_title }}" placeholder="{{ $page->title }}">
        </div>

        <div class="pb2-ss-field">
          <label class="pb2-ss-label" for="pb2-ss-md">Description <span class="pb2-ss-count" id="pb2-ss-md-count"></span></label>
          <textarea class="pb2-ss-input" id="pb2-ss-md" rows="3" maxlength="320"
                    placeholder="None. Search engines pick text from the page.">{{ $page->meta_description }}</textarea>
        </div>

        <div class="pb2-ss-field">
          <div class="pb2-ss-label">Share image <span class="pb2-ss-hint">best at 1200 × 630</span></div>
          <div class="pb2-ss-img">
            <div class="pb2-ss-thumb" id="pb2-ss-thumb"></div>
            <div class="pb2-ss-imgacts">
              <button type="button" class="pb2-status-btn" id="pb2-ss-pick">Choose from library</button>
              <button type="button" class="pb2-status-btn" id="pb2-ss-clear">Use default</button>
            </div>
          </div>
          <input type="hidden" id="pb2-ss-img" value="{{ $ssOwnImg }}">
        </div>

        <div class="pb2-ss-preview">
          <div class="pb2-ss-pv-cap">In search results</div>
          <div class="pb2-ss-pv-url">{{ $ssHost }}{{ $ssPath }}</div>
          <div class="pb2-ss-pv-title" id="pb2-ss-pv-title"></div>
          <div class="pb2-ss-pv-desc" id="pb2-ss-pv-desc"></div>
        </div>

        <div class="pb2-ss-foot">
          <span class="pb2-ss-msg" id="pb2-ss-msg"></span>
          <button type="button" class="pb2-status-btn pb2-status-btn--go" id="pb2-ss-save" disabled>Save</button>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var root = document.getElementById('pb2-ss');
      if (!root) return;
      var $ = function (id) { return document.getElementById(id); };
      var toggle = $('pb2-ss-toggle'), body = $('pb2-ss-body');
      var mt = $('pb2-ss-mt'), md = $('pb2-ss-md'), img = $('pb2-ss-img');
      var thumb = $('pb2-ss-thumb'), save = $('pb2-ss-save'), msg = $('pb2-ss-msg'), state = $('pb2-ss-state');
      var pageTitle = root.dataset.pageTitle || '', site = root.dataset.site || '';
      var isMkt = root.dataset.mkt === '1', fallbackImg = root.dataset.fallbackImg || '';
      var saved = { mt: mt.value, md: md.value, img: img.value };

      function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
      function fullTitle() {
        var t = mt.value.trim();
        if (isMkt) return t || (pageTitle + ' \u2014 Intake');
        return (t || pageTitle) + (site ? ' \u2014 ' + site : '');
      }
      function count(el, out, ideal) {
        var n = el.value.trim().length;
        out.textContent = n ? n + ' / ' + ideal : '';
        out.classList.toggle('is-over', n > ideal);
      }
      function paintThumb() {
        var own = img.value.trim();
        var src = own || fallbackImg;
        thumb.classList.toggle('is-default', !own);
        $('pb2-ss-clear').style.display = own ? '' : 'none';
        if (!src) { thumb.innerHTML = 'No image \u2014 links share without a picture'; return; }
        thumb.innerHTML = '<img src="' + esc(src) + '" alt="">' + (own ? '' : '<span class="pb2-ss-tag">Default</span>');
      }
      function dirty() {
        return mt.value !== saved.mt || md.value !== saved.md || img.value !== saved.img;
      }
      function paint() {
        count(mt, $('pb2-ss-mt-count'), 60);
        count(md, $('pb2-ss-md-count'), 155);
        $('pb2-ss-pv-title').textContent = fullTitle();
        var d = $('pb2-ss-pv-desc'), dv = md.value.trim();
        d.textContent = dv || 'No description set. Google will choose text from the page.';
        d.classList.toggle('is-empty', !dv);
        paintThumb();
        save.disabled = !dirty();
        if (dirty()) { msg.textContent = 'Unsaved'; msg.classList.remove('is-error'); }
      }

      toggle.addEventListener('click', function () {
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
      });
      mt.addEventListener('input', paint);
      md.addEventListener('input', paint);
      $('pb2-ss-clear').addEventListener('click', function () { img.value = ''; paint(); });
      $('pb2-ss-pick').addEventListener('click', function () {
        if (typeof window.pb2OpenMediaPicker !== 'function') return;
        window.__sharePick = function (url) { img.value = url || ''; paint(); };
        window.pb2OpenMediaPicker('__share_pick');
      });

      save.addEventListener('click', function () {
        var tok = document.querySelector('#pb2-page-form input[name="_token"]');
        var fd = new FormData();
        fd.append('_token', tok ? tok.value : '');
        fd.append('_method', 'PATCH');
        fd.append('op', 'set_search_sharing');
        fd.append('meta_title', mt.value.trim());
        fd.append('meta_description', md.value.trim());
        fd.append('og_image_url', img.value.trim());
        save.disabled = true;
        msg.classList.remove('is-error');
        msg.textContent = 'Saving\u2026';
        fetch(root.dataset.updateUrl, {
          method: 'POST', body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
          return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok, d: d }; });
        }).then(function (res) {
          if (!res.ok || !res.d || !res.d.ok) {
            var e = res.d && (res.d.message || res.d.error);
            if (res.d && res.d.errors) { var k = Object.keys(res.d.errors)[0]; if (k) e = res.d.errors[k][0]; }
            throw new Error(e || 'Could not save. Please try again.');
          }
          mt.value = mt.value.trim(); md.value = md.value.trim();
          img.value = res.d.image_url || '';
          saved = { mt: mt.value, md: md.value, img: img.value };
          var custom = !!(saved.mt || saved.md || saved.img);
          state.textContent = custom ? 'Custom' : 'Default';
          state.classList.toggle('is-custom', custom);
          paint();
          msg.textContent = 'Saved \u2713';
        }).catch(function (err) {
          msg.textContent = err.message;
          msg.classList.add('is-error');
          save.disabled = !dirty();
        });
      });

      window.addEventListener('beforeunload', function (e) {
        if (dirty()) { e.preventDefault(); e.returnValue = ''; }
      });

      paint();
      msg.textContent = '';
    })();
    </script>
    @endif

    @if($isMarketing ?? false)
    {{-- the widest any section on this page can go.
         Saves on click; sections and Content width sliders follow it. --}}
    @php
      $pwNow  = in_array((int) ($page->page_width ?? 0), [960, 1280, 1440], true) ? (int) $page->page_width : 1080;
      $pwOpts = [960 => 'Narrow', 1080 => 'Standard', 1280 => 'Wide', 1440 => 'Extra wide'];
    @endphp
    <style>
    .pb2-pw-opts { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .pb2-pw-opt { display: flex; flex-direction: column; align-items: flex-start; gap: 1px; padding: 7px 9px; border-radius: 6px;
      background: transparent; border: .5px solid var(--pb2-border); color: var(--pb2-text); cursor: pointer; font: inherit; text-align: left; }
    .pb2-pw-opt:hover { border-color: var(--pb2-border-2); }
    .pb2-pw-opt.is-on { border-color: var(--pb2-accent); box-shadow: inset 0 0 0 .5px var(--pb2-accent); }
    .pb2-pw-opt b { font-size: 12px; font-weight: 600; }
    .pb2-pw-opt span { font-family: var(--pb2-mono); font-size: 10px; color: var(--pb2-text-faint); }
    </style>
    <div class="pb2-ss" id="pb2-pw" data-update-url="{{ $updateUrl }}" data-width="{{ $pwNow }}">
      <button type="button" class="pb2-ss-head" id="pb2-pw-toggle" aria-expanded="false" aria-controls="pb2-pw-body">
        <span class="pb2-ss-title">Page width</span>
        <span class="pb2-ss-state {{ $pwNow !== 1080 ? 'is-custom' : '' }}" id="pb2-pw-state">{{ $pwNow }}px</span>
        <svg class="pb2-ss-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="pb2-ss-body" id="pb2-pw-body" hidden>
        <div class="pb2-ss-legend">
          The widest any section on this page can go. Content width on a section can be narrower, never wider.
          Backgrounds still run edge to edge.
        </div>
        <div class="pb2-pw-opts">
          @foreach($pwOpts as $w => $name)
            <button type="button" class="pb2-pw-opt {{ $w === $pwNow ? 'is-on' : '' }}" data-w="{{ $w }}"><b>{{ $name }}</b><span>{{ $w }}px{{ $w === 1080 ? ' · default' : '' }}</span></button>
          @endforeach
        </div>
        <div class="pb2-ss-msg" id="pb2-pw-msg" style="margin-top:8px"></div>
      </div>
    </div>
    <script>
    (function () {
      var root = document.getElementById('pb2-pw');
      if (!root) return;
      var head = document.getElementById('pb2-pw-toggle');
      var body = document.getElementById('pb2-pw-body');
      var state = document.getElementById('pb2-pw-state');
      var msg = document.getElementById('pb2-pw-msg');
      window.PB2_PAGE_WIDTH = parseInt(root.dataset.width, 10) || 1080;

      head.addEventListener('click', function () {
        var open = head.getAttribute('aria-expanded') !== 'true';
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
      });

      // Content width sliders never go past the page width.
      function clamp(scope) {
        (scope || document).querySelectorAll('[data-field="content_max_width"]').forEach(function (f) {
          var range = f.type === 'range' ? f : (f.closest('.pb2-field, .pb2-group') || f.parentNode).querySelector('input[type="range"]');
          if (!range) return;
          if (!range.dataset.pwMax) range.dataset.pwMax = range.max;
          var cap = Math.min(parseInt(range.dataset.pwMax, 10) || 9999, window.PB2_PAGE_WIDTH);
          if (String(range.max) !== String(cap)) range.max = cap;
        });
      }
      clamp();
      try { new MutationObserver(function () { clamp(); }).observe(document.body, { childList: true, subtree: true }); } catch (e) {}

      root.querySelectorAll('.pb2-pw-opt').forEach(function (b) {
        b.addEventListener('click', function () {
          var w = parseInt(b.dataset.w, 10);
          if (w === window.PB2_PAGE_WIDTH) return;
          var tok = document.querySelector('#pb2-page-form input[name="_token"]');
          var fd = new FormData();
          fd.append('_token', tok ? tok.value : '');
          fd.append('_method', 'PATCH');
          fd.append('op', 'set_page_width');
          fd.append('page_width', String(w));
          msg.classList.remove('is-error');
          msg.textContent = 'Saving\u2026';
          fetch(root.dataset.updateUrl, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
          }).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok, d: d }; });
          }).then(function (res) {
            if (!res.ok || !res.d || !res.d.ok) throw new Error((res.d && (res.d.error || res.d.message)) || 'Could not save. Please try again.');
            window.PB2_PAGE_WIDTH = res.d.page_width;
            root.querySelectorAll('.pb2-pw-opt').forEach(function (o) { o.classList.toggle('is-on', parseInt(o.dataset.w, 10) === res.d.page_width); });
            state.textContent = res.d.page_width + 'px';
            state.classList.toggle('is-custom', res.d.page_width !== 1080);
            msg.textContent = 'Saved';
            clamp();
            var f = document.getElementById('pb2-preview');
            if (f) { try { f.contentWindow.location.reload(); } catch (e) { f.src = f.src; } }
          }).catch(function (e) {
            msg.classList.add('is-error');
            msg.textContent = e.message;
          });
        });
      });

      // Guides: dashed lines at the page width (and dotted at the text edge)
      // drawn into the preview. Off by default; the choice is remembered here.
      var gBtn = document.getElementById('pb2-guides-btn');
      var frame = null;
      var on = false;
      try { on = localStorage.getItem('pb2-guides') === '1'; } catch (e) {}
      function paintGuides() {
        frame = frame || document.getElementById('pb2-preview');
        if (!frame) return;
        var doc; try { doc = frame.contentDocument; } catch (e) { return; }
        if (!doc || !doc.body) return;
        var g = doc.getElementById('pb2-guides');
        if (!on) { if (g) g.remove(); return; }
        if (g) return;
        g = doc.createElement('div');
        g.id = 'pb2-guides';
        g.innerHTML = '<style>'
          + '#pb2-guides{position:fixed;inset:0;pointer-events:none;z-index:2147483000}'
          + '#pb2-guides .o{position:absolute;top:0;bottom:0;left:50%;width:min(100%,var(--mk-max,1080px));transform:translateX(-50%);border-left:1px dashed rgba(190,242,100,.75);border-right:1px dashed rgba(190,242,100,.75)}'
          + '#pb2-guides .i{position:absolute;top:0;bottom:0;left:var(--mk-gutter,24px);right:var(--mk-gutter,24px);border-left:1px dotted rgba(190,242,100,.35);border-right:1px dotted rgba(190,242,100,.35)}'
          + '#pb2-guides .t{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);font:600 10px/1 ui-monospace,Menlo,monospace;color:#0a0a0a;background:rgba(190,242,100,.9);padding:3px 6px;border-radius:4px}'
          + '</style><div class="o"><div class="i"></div><span class="t"></span></div>';
        doc.body.appendChild(g);
        var t = g.querySelector('.t');
        try { t.textContent = (frame.contentWindow.getComputedStyle(doc.documentElement).getPropertyValue('--mk-max').trim() || '1080px') + ' page width'; } catch (e) {}
      }
      function setOn(v) {
        on = v;
        if (gBtn) gBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        try { localStorage.setItem('pb2-guides', on ? '1' : '0'); } catch (e) {}
        paintGuides();
      }
      if (gBtn) gBtn.addEventListener('click', function () { setOn(!on); });
      function hook() {
        var f = document.getElementById('pb2-preview');
        if (!f) return false;
        f.addEventListener('load', paintGuides);
        return true;
      }
      if (!hook()) document.addEventListener('DOMContentLoaded', hook);
      setOn(on);
    })();
    </script>
    @endif

    {{-- sections docked above the inspector --}}
    <div class="pb2-sections-docked" id="pb2-sections-pane">
      {{-- collapsible header --}}
      <div class="pb2-pane-header pb2-sections-toggle" onclick="toggleSectionsDock()" title="Collapse / expand the section list">
        <div class="pb2-pane-header-title">Sections</div>
        <div class="pb2-sections-head-right">
          <span class="pb2-pane-header-meta">{{ $sections->count() }}</span>
          <svg class="pb2-sections-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>

      <div class="pb2-section-list" id="pb2-canvas">
        @foreach($sections as $idx => $section)
          <div class="pb2-section-item @if($idx === 0) selected @endif @if(!$section->is_visible) hidden @endif"
               data-section-id="{{ $section->id }}"
               data-section-type="{{ $section->section_type }}"
               data-w="{{ \App\Support\SectionRows::width($section) }}"
               draggable="false">
            <span class="pb2-drag-handle" title="Drag to reorder">⋮⋮</span>
            <span class="pb2-section-icon">
              {{-- per-type icon (was generic rect for all) --}}
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                {!! $typeIconPaths[$section->section_type] ?? '<rect x="3" y="3" width="18" height="18" rx="2"/>' !!}
              </svg>
            </span>
            <span class="pb2-section-name">{{ $typeLabels[$section->section_type] ?? $section->section_type }}</span>
            {{-- which screens this section is hidden on --}}
            @php
              $pbHc  = (array) ($section->content ?? []);
              $pbOn  = fn ($k) => ! empty($pbHc[$k]) && ! in_array((string) $pbHc[$k], ['0', 'false'], true);
              $pbHid = array_keys(array_filter(['phone' => $pbOn('hide_on_mobile'), 'tablet' => $pbOn('hide_on_tablet'), 'desktop' => $pbOn('hide_on_desktop')]));
            @endphp
            @if($pbHid)
              <span class="pb2-section-hidden" title="Hidden on {{ implode(', ', $pbHid) }}">Hidden: {{ implode(', ', $pbHid) }}</span>
            @endif
            <span class="pb2-section-meta">{{ sprintf('%02d', $idx + 1) }}</span>
          </div>
        @endforeach

        <div class="pb2-section-add" onclick="toggleAddPanel()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add section
        </div>

        <div class="pb2-add-panel" id="pb2-add-panel">
          {{-- Add-section gallery. Grouped by purpose,
               each option shown as a card with icon + label + one-line desc.
               Marketing context shows different types than tenant context. --}}
          @php
            $allowed = $isBookingExtras
              ? ['hero','cta_banner','feature_grid','custom_html','text_image','image_gallery','image_carousel','stats_row','testimonial_carousel','faq_accordion','logo_bar','step_timeline','pricing_table'] // content sections; chrome/shop/nav excluded
              : ($isMarketing
              ? ['nav','hero','text_image','cta_banner','image_gallery','image_carousel','scroll_words','contact_form','feature_grid','step_timeline','faq_accordion','footer','pricing_table','testimonial_carousel','logo_bar','stats_row','comparison_table','industry_pack_showcase','book_call','try_demo','roi','feature_tiles','custom_html','feature_groups'] /* (marketing only) */
              : ['nav','hero','text_image','cta_banner','image_gallery','image_carousel','scroll_words','contact_form','booking_embed','classes_embed','feature_grid','step_timeline','faq_accordion','footer','testimonial_carousel','logo_bar','stats_row','pricing_table','rentals_showcase','rental_spotlight','rental_categories','rental_browse','products_showcase','custom_html']);
          @endphp

          <div class="pb2-gallery">
            {{-- the copied section, while the copy lasts --}}
            @php $pbClip = \App\Support\SectionClipboard::peek((string) $page->tenant_id); @endphp
            <div id="pb2-paste-slot">
              @if($pbClip && in_array($pbClip['section_type'], $allowed, true))
                <button type="button" class="pb2-paste-card" onclick="pasteSection()">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                  <span>
                    <span class="pb2-paste-name">Paste {{ $pbClip['label'] }}</span>
                    <span class="pb2-paste-sub">copied from {{ $pbClip['from_page'] }} · {{ $pbClip['age'] }} · keeps for {{ \App\Support\SectionClipboard::TTL_MINUTES }} min</span>
                  </span>
                </button>
              @endif
            </div>
            @foreach($typeGroups as $groupName => $groupTypes)
              @php $visibleTypes = array_intersect($groupTypes, $allowed); @endphp
              @if(count($visibleTypes) > 0)
                <div class="pb2-gallery-group-label">{{ $groupName }}</div>
                <div class="pb2-gallery-grid">
                  @foreach($visibleTypes as $t)
                    <button type="button" class="pb2-gallery-card" onclick="addSection('{{ $t }}')">
                      <span class="pb2-gallery-card-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                          {!! $typeIconPaths[$t] ?? '<rect x="3" y="3" width="18" height="18" rx="2"/>' !!}
                        </svg>
                      </span>
                      <span class="pb2-gallery-card-text">
                        <span class="pb2-gallery-card-name">{{ $typeLabels[$t] ?? $t }}</span>
                        <span class="pb2-gallery-card-desc">{{ $typeDescriptions[$t] ?? '' }}</span>
                      </span>
                    </button>
                  @endforeach
                </div>
              @endif
            @endforeach
          </div>
        </div>
      </div>

      <div class="pb2-pane-footer">
        <div class="pb2-pane-footer-dot"></div>
        <div>{{ $page->is_published ? 'Published' : 'Draft' }}</div>
        <div class="pb2-save-time" id="pb2-save-time">Saved</div>
      </div>
    </div>

      {{-- Header + tabs + body get injected here when a section is selected. --}}
      @php $firstSection = $sections->first(); @endphp

      @if($firstSection)
        <div class="pb2-insp-header" id="pb2-insp-header">
          <div class="pb2-insp-header-title">
            <div class="pb2-insp-header-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
            </div>
            <div>
              <div class="pb2-insp-header-name" id="pb2-insp-name">{{ $typeLabels[$firstSection->section_type] ?? $firstSection->section_type }}</div>
              <div class="pb2-insp-header-sub" id="pb2-insp-sub">section · {{ $page->slug ?: 'home' }} · 01</div>
            </div>
          </div>
          <div class="pb2-insp-actions">
            <button class="pb2-icon-btn" id="pb2-toggle-visible" title="Toggle visibility">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            {{-- copy to paste on another page --}}
            <button class="pb2-icon-btn" id="pb2-copy-section" title="Copy section (paste it on another page within 30 min)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
            </button>
            {{-- duplicate button --}}
            <button class="pb2-icon-btn" id="pb2-duplicate-section" title="Duplicate section">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
            <button class="pb2-icon-btn" id="pb2-delete-section" title="Delete">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
          </div>
        </div>

        <div class="pb2-insp-tabs">
          <button class="pb2-insp-tab active" data-tab="content">Content</button>
          {{-- Layout merged into Content. --}}
          <button class="pb2-insp-tab" data-tab="style">Design</button>{{-- label only; data-tab unchanged --}}
          <button class="pb2-insp-tab" data-tab="advanced">Advanced</button>
        </div>

        <div class="pb2-insp-body" id="pb2-insp-body">
          {{-- render the per-type editor on initial load
               (with tab panels + full fields), mirroring the ?_inspector=
               click path; legacy _section is the fallback for un-migrated types. --}}
          @php $firstPerType = 'tenant.pages.sections._' . $firstSection->section_type; @endphp
          @if(view()->exists($firstPerType))
            {{-- both captured, then the shared overlap control is
                 placed inside the Design tab rather than after every tab. --}}
            @php ob_start(); @endphp
            @include($firstPerType, ['section' => $firstSection, 'c' => $firstSection->content ?? [], 'navItems' => $navItems ?? collect(), 'availablePages' => $availablePages ?? collect(), 'isBookingExtras' => $isBookingExtras ?? false])
            @php $__pb2Editor = ob_get_clean(); ob_start(); @endphp
            @include('tenant.pages.sections._overlap', ['section' => $firstSection])
            @php $__pb2Overlap = ob_get_clean(); @endphp
            {!! \App\Support\InspectorOverlap::place($__pb2Editor, $__pb2Overlap) !!}
          @else
            @include('tenant.pages._section', ['section' => $firstSection])
          @endif
        </div>

        <div class="pb2-insp-footer">
          <span id="pb2-dirty-note" class="pb2-dirty-note">All changes saved</span>
          <button type="button" class="pb2-btn" id="pb2-insp-revert" style="margin-left:auto" disabled>Revert</button>
          <button type="button" class="pb2-btn pb2-btn-primary" data-pb2-save onclick="pb2SaveNow()">Save</button>
        </div>
      @else
        <div class="pb2-insp-empty">
          <div class="pb2-insp-empty-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
          </div>
          <div class="pb2-insp-empty-title">No sections yet</div>
          <div class="pb2-insp-empty-hint">Add a section from the left pane to start.</div>
        </div>
      @endif
    </aside>

  </div>
</div>

{{-- Hidden form data for the section editor (this is used by the v1 _section partial's
     existing JS for autosave). We preserve all the v1 endpoints + JS so nothing breaks. --}}
<form id="pb2-page-form" style="display:none;">
  @csrf
  <input type="hidden" name="_method" value="PATCH">
  <input type="hidden" id="pg-title" value="{{ $page->title }}">
  <input type="hidden" id="pg-slug" value="{{ $page->slug }}">
  <input type="hidden" id="pg-meta-title" value="{{ $page->meta_title }}">
  <input type="hidden" id="pg-meta-desc" value="{{ $page->meta_description }}">
  <input type="hidden" id="pg-is-published" value="{{ $page->is_published ? '1' : '0' }}">
  <input type="hidden" id="pg-is-home" value="{{ $page->is_home ? '1' : '0' }}">
  <input type="hidden" id="pg-is-in-nav" value="{{ $page->is_in_nav ? '1' : '0' }}">
  <input type="hidden" id="pg-nav-order" value="{{ $page->nav_order ?? 0 }}">
</form>


{{-- history drawer --}}
<style>
#pb2-hist{position:fixed;top:0;right:0;bottom:0;width:352px;z-index:1300;background:#151515;
  border-left:.5px solid rgba(255,255,255,.16);box-shadow:-26px 0 64px -22px rgba(0,0,0,.85);
  color:#f1f1f1;font-size:13px;display:flex;flex-direction:column}
#pb2-hist[hidden]{display:none}
#pb2-hist .hh{display:flex;align-items:center;justify-content:space-between;padding:13px 15px;
  border-bottom:.5px solid rgba(255,255,255,.09);background:#1d1d1d}
#pb2-hist .hh b{font-size:13px;font-weight:600}
#pb2-hist .hx{background:0;border:0;color:rgba(255,255,255,.4);font-size:19px;line-height:1;cursor:pointer}
#pb2-hist .hx:hover{color:#fff}
#pb2-hist .hnote{padding:10px 15px;font-size:11px;color:rgba(255,255,255,.55);line-height:1.5;
  border-bottom:.5px solid rgba(255,255,255,.09)}
#pb2-hist-rows{flex:1;overflow-y:auto;padding:8px}
#pb2-hist .hrow{padding:10px 11px;border-radius:9px;display:flex;gap:10px;align-items:flex-start}
#pb2-hist .hrow:hover{background:rgba(255,255,255,.05)}
#pb2-hist .hrow .hmain{flex:1;min-width:0}
#pb2-hist .hlbl{font-weight:600;font-size:12.5px}
#pb2-hist .hmeta{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px}
#pb2-hist .hbtn{background:#242424;border:.5px solid rgba(255,255,255,.16);color:#f1f1f1;
  font:inherit;font-size:11px;padding:5px 10px;border-radius:6px;cursor:pointer;flex:0 0 auto}
#pb2-hist .hbtn:hover{border-color:var(--ia-accent,#3FD16B);color:var(--ia-accent,#3FD16B)}
#pb2-hist .hempty{padding:26px 15px;text-align:center;color:rgba(255,255,255,.35);font-size:12.5px}
</style>

<div id="pb2-hist" hidden aria-label="Page history">
  <div class="hh"><b>History</b><button type="button" class="hx" id="pb2-hist-x" aria-label="Close">&times;</button></div>
  <div class="hnote">Rewinding changes your draft only &mdash; your live page stays as it is until you publish. Every rewind can itself be undone.</div>
  <div id="pb2-hist-rows"><div class="hempty">Loading&hellip;</div></div>
</div>

<form method="POST" id="pb2-hist-form" style="display:none">@csrf</form>

<script>
  (function () {
    var btn   = document.getElementById('pb2-history-btn');
    var panel = document.getElementById('pb2-hist');
    var rows  = document.getElementById('pb2-hist-rows');
    var form  = document.getElementById('pb2-hist-form');
    if (!btn || !panel) { return; }

    var listUrl = @json($historyListUrl);
    // Built from the named route so any group prefix is honoured.
    var restoreTpl = @json($historyRestoreTpl);

    function close() { panel.hidden = true; }

    document.getElementById('pb2-hist-x').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { close(); }
    });

    btn.addEventListener('click', function () {
      if (!panel.hidden) { close(); return; }
      panel.hidden = false;
      rows.textContent = '';
      var loading = document.createElement('div');
      loading.className = 'hempty';
      loading.textContent = 'Loading\u2026';
      rows.appendChild(loading);

      fetch(listUrl, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { revisions: [] }; })
        .then(function (data) { render(data.revisions || []); })
        .catch(function () { render(null); });
    });

    function render(list) {
      rows.textContent = '';
      if (list === null) {
        var err = document.createElement('div');
        err.className = 'hempty';
        err.textContent = "Couldn't load history \u2014 try again.";
        rows.appendChild(err);
        return;
      }
      if (!list.length) {
        var none = document.createElement('div');
        none.className = 'hempty';
        none.textContent = 'No restore points yet. One is saved automatically before each change.';
        rows.appendChild(none);
        return;
      }

      list.forEach(function (r, i) {
        var row = document.createElement('div');
        row.className = 'hrow';

        var main = document.createElement('div');
        main.className = 'hmain';
        var lbl = document.createElement('div');
        lbl.className = 'hlbl';
        lbl.textContent = r.label;
        var meta = document.createElement('div');
        meta.className = 'hmeta';
        var bits = [];
        if (r.when) { bits.push(r.when); }
        if (r.actor) { bits.push(r.actor); }
        bits.push(r.sections + (r.sections === 1 ? ' section' : ' sections'));
        meta.textContent = bits.join(' \u00b7 ');
        if (r.exact) { meta.title = r.exact; }
        main.appendChild(lbl);
        main.appendChild(meta);
        row.appendChild(main);

        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'hbtn';
        b.textContent = i === 0 ? 'Restore' : 'Rewind';
        b.addEventListener('click', function () {
          // in-app dialog, no browser prompts
          IntakeConfirm.show({
            title: 'Rewind this page?',
            message: 'Rewind to \u201C' + r.label + '\u201D. Your current draft is saved first, so you can undo this.',
            confirmText: 'Rewind',
            danger: true
          }).then(function (ok) {
            if (!ok) return;
            form.action = restoreTpl.replace('__RID__', r.id);
            form.submit();
          });
        });
        row.appendChild(b);

        rows.appendChild(row);
      });
    }
  })();
</script>

@push('scripts')
<script>
// page builder v2 chrome
// autosave + live preview reload
(function() {
  const PAGE_ID    = @json($page->id);
  const UPDATE_URL = @json($isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/builder')
      : route('tenant.pages.update', $page->id));
  const SECTION_URL = (sid) => @json($isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/sections/')
      : url('/admin/pages/' . $page->id . '/sections/')) + sid;
  const ADD_SECTION_URL = @json($isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/sections')
      : url('/admin/pages/' . $page->id . '/sections'));
  // STORE_URL is the endpoint that v1 used for section_op=update.
  // Same handler accepts the same payload here.
  const STORE_URL  = @json($storeUrl);
  const PREVIEW_URL = @json($previewSrc);
  // upload endpoint  (revised by ). Built as a raw URL string
  // instead of route() to avoid RouteNotFoundException if the route cache
  // is stale post-deploy. The endpoint path is stable and tenant-scoped.
  const UPLOAD_URL = @json($isMarketing
      ? url('/admin/marketing-uploads')
      : url('/admin/uploads'));
  const TYPE_LABELS = @json($typeLabels);

  const PREVIEW_IFRAME = document.getElementById('pb2-preview');

  // ─── CSRF helper ──────────────────────────────────────────────────────
  function getCsrf() {
    return (window.IntakeAdmin && window.IntakeAdmin.csrfToken)
        || document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value
        || '';
  }

  // ─── Inspector status indicator ───────────────────────────────────────
  let statusTimer = null;
  function setStatus(text, persistMs) {
    const el = document.getElementById('pb2-save-time');
    if (!el) return;
    el.textContent = text;
    clearTimeout(statusTimer);
    if (persistMs) {
      statusTimer = setTimeout(() => { el.textContent = 'Saved'; }, persistMs);
    }
  }

  // ─── Preview iframe debounced reload ──────────────────────────────────
  let previewTimer = null;
  function refreshPreview(immediate) {
    clearTimeout(previewTimer);
    const fire = () => {
      if (!PREVIEW_IFRAME) return;
      // Same-origin iframe (tenant subdomain editor → tenant subdomain preview):
      // contentWindow.reload() preserves scroll position and is cleaner than src=
      // swap. Falls back to src+cache-bust if cross-origin (shouldn't happen,
      // but harmless).
      try {
        PREVIEW_IFRAME.contentWindow.location.reload();
      } catch (e) {
        PREVIEW_IFRAME.src = PREVIEW_URL + (PREVIEW_URL.includes('?') ? '&' : '?') + 't=' + Date.now();
      }
    };
    if (immediate) { fire(); return; }
    previewTimer = setTimeout(fire, 600);
  }

  // ─── Save a section ───────────────────────────────────────────────────
  // Mirrors v1 logic: collects [data-field] inputs from the inspector body
  // and POSTs section_op=update to the existing endpoint.
  function saveSection(sectionId) {
    const body = document.getElementById('pb2-insp-body');
    if (!body || !sectionId) return Promise.resolve();

    setStatus('Saving…');

    const content = {};

    // Color picker text-shadow sync (mirrors v1) — fields ending in _text
    // shadow a hex picker; keep them in lockstep, allow blank to clear.
    body.querySelectorAll('input[data-field$="_text"]').forEach(textInput => {
      const baseField = textInput.getAttribute('data-field').replace(/_text$/, '');
      const picker = body.querySelector('input[data-field="' + baseField + '"][type="color"]');
      if (!picker) return;
      const txt = (textInput.value || '').trim();
      if (/^#[0-9a-fA-F]{6}$/.test(txt)) {
        picker.value = txt;
        picker.removeAttribute('data-blank');
      } else if (txt === '') {
        picker.setAttribute('data-blank', '1');
      } else {
        picker.removeAttribute('data-blank');
      }
    });

    body.querySelectorAll('[data-field]').forEach(el => {
      const field = el.getAttribute('data-field');
      if (field.endsWith('_text')) return;
      if (el.type === 'color' && el.getAttribute('data-blank') === '1') {
        content[field] = '';
        return;
      }
      if (el.type === 'checkbox') {
        content[field] = el.checked ? '1' : '0';
      } else {
        content[field] = el.value;
      }
    });

    // bg_color used to be stripped from content[] and
    // sent as a top-level form field for the section's own bg_color column.
    // That broke v2 partials where bg_color is just one of many fields inside
    // content[] (gated by bg_mode). Now we send the bg_color column ONLY if
    // the section has no bg_mode field (i.e. legacy partials) — v2 partials
    // keep bg_color in content[] so the renderer picks up the value.
    let bgColorForColumn;
    const hasV2BgMode = body.querySelector('[data-field="bg_mode"]') !== null;
    if (!hasV2BgMode && content.bg_color !== undefined) {
      bgColorForColumn = content.bg_color;
      delete content.bg_color;
    }

    const isVisibleEl = body.querySelector('[data-field="is_visible"]');
    const isVisible   = isVisibleEl ? (isVisibleEl.checked ? 1 : 0) : 1;

    const fd = new FormData();
    fd.append('_token', getCsrf());
    fd.append('section_op', 'update');
    fd.append('page_id', PAGE_ID);
    fd.append('section_id', sectionId);
    fd.append('is_visible', isVisible);
    if (bgColorForColumn !== undefined) fd.append('bg_color', bgColorForColumn);
    Object.keys(content).forEach(k => fd.append('content[' + k + ']', content[k]));

    return fetch(STORE_URL, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
      .then(r => r.json().catch(() => ({ success: r.ok })))
      .then(resp => {
        if (resp && resp.success !== false) {
          pb2SaveOk = true;
          setStatus('Saved ✓', 1500);
          refreshPreview();
          // Reflect any title/label change in the section list (uses the
          // first visible text input as a best-guess label proxy).
          updateSidebarMetaFromInspector(sectionId);
        } else {
          pb2SaveOk = false; setStatus('Save failed', 3000);
          console.error('save failed', resp);
        }
      })
      .catch(err => {
        pb2SaveOk = false; setStatus('Save failed', 3000);
        console.error('save error', err);
      });
  }

  // Soft refresh of the sidebar row's name if the section type label hasn't
  // changed but the row is selected. Phase 2 may want richer titles (e.g.
  // "Hero · Skip the shop visit") — for now we just leave the type label.
  function updateSidebarMetaFromInspector(sectionId) { /* no-op for phase 1.2 */ }

  // ─── Wire autosave listeners on inspector inputs ──────────────────────
  // Called after the inspector body is populated (initial render + every
  // section selection swap).
  const saveTimers = {};
  // arriving from "Add it in the menu" / "Edit menu" on
  // another page: open the Nav section; the menu editor finishes the job.
  (function () {
    var q = new URLSearchParams(location.search);
    var sid = q.get('section');
    if (!sid && q.get('select') !== 'nav') return;
    window.pb2NavIntent = { add: q.get('add') || null };
    if (history.replaceState) history.replaceState(null, '', location.pathname);
    window.addEventListener('load', function () {
      var n = sid ? document.querySelector('.pb2-section-item[data-section-id="' + sid + '"]')
                  : document.querySelector('.pb2-section-item[data-section-type="nav"]');
      if (n && !n.classList.contains('selected')) n.click();
    });
  })();
  // the inspector no longer saves on every keystroke.
  // Edits mark the section dirty; Save (top bar, or Cmd/Ctrl+S) writes them,
  // Revert reloads the saved version. Switching section with unsaved edits
  // asks first (in-app dialog), and leaving the page warns.
  var pb2Dirty = false;
  var pb2SaveOk = true;
  window.pb2NavPending = false;
  function pb2Paint() {
    var note = document.getElementById('pb2-dirty-note');
    var rev  = document.getElementById('pb2-insp-revert');
    if (note) note.textContent = pb2Dirty ? 'Unsaved changes — Save, or \u2318S' : 'All changes saved';
    if (note) note.classList.toggle('is-dirty', pb2Dirty);
    if (rev)  rev.disabled = !pb2Dirty;
    document.querySelectorAll('[data-pb2-save]').forEach(function (b) { b.classList.toggle('is-dirty', pb2Dirty); });
  }
  function pb2MarkDirty() {
    if (!pb2Dirty) { pb2Dirty = true; setStatus('Unsaved'); }
    pb2Paint();
    pb2QueueDraft();
  }
  window.pb2MarkDirty = pb2MarkDirty;
  function pb2SetClean() {
    pb2Dirty = false; window.pb2NavPending = false; window.pb2NavSaver = null;
    clearTimeout(pb2DraftTimer);
    pb2Paint();
  }
  function pb2SaveNow() {
    if (!selectedId) { pb2SetClean(); return Promise.resolve(true); }
    pb2SaveOk = true;
    var jobs = [saveSection(selectedId)];
    if (window.pb2NavPending && typeof window.pb2NavSaver === 'function') {
      jobs.push(Promise.resolve(window.pb2NavSaver()));
    }
    return Promise.all(jobs).then(function () {
      if (pb2SaveOk) { pb2SetClean(); refreshPreview(true); }
      return pb2SaveOk;
    });
  }
  window.pb2SaveNow = pb2SaveNow;

  // redraw the current section's panel WITHOUT losing
  // unsaved edits: send them as the draft first, then redraw from the draft.
  var pb2KeepDirty = false;
  function pb2FlushDraft() {
    if (!pb2Dirty || !selectedId) return Promise.resolve();
    clearTimeout(pb2DraftTimer);
    var c = pb2CollectContent(), fl = { section_op: 'draft', section_id: selectedId };
    Object.keys(c).forEach(function (k) { fl['content[' + k + ']'] = c[k]; });
    if (window.pb2NavPending && window.pb2NavRows) fl.nav_rows = JSON.stringify(window.pb2NavRows);
    return pb2Post(fl).catch(function () {});
  }
  function pb2ReloadSame(item, idx) {
    var keep = pb2Dirty;
    return pb2FlushDraft().then(function () {
      pb2KeepDirty = keep;
      selectSection(selectedId, item.dataset.sectionType, idx, true);
    });
  }

  // unsaved edits show in the preview straight away.
  // ~0.15s after a change the section's fields go to the session as a draft,
  // the preview is rendered with it, and only that section is swapped in the
  // frame: no reload, no scroll jump. Save still writes; Revert discards.
  var pb2DraftTimer = null, pb2DraftSeq = 0;
  function pb2CollectContent() {
    var body = document.getElementById('pb2-insp-body');
    var content = {};
    if (!body) return content;
    body.querySelectorAll('input[data-field$="_text"]').forEach(function (t) {
      var base = t.getAttribute('data-field').replace(/_text$/, '');
      var picker = body.querySelector('input[data-field="' + base + '"][type="color"]');
      if (!picker) return;
      var txt = (t.value || '').trim();
      if (/^#[0-9a-fA-F]{6}$/.test(txt)) { picker.value = txt; picker.removeAttribute('data-blank'); }
      else if (txt === '') { picker.setAttribute('data-blank', '1'); }
      else { picker.removeAttribute('data-blank'); }
    });
    body.querySelectorAll('[data-field]').forEach(function (el) {
      var f = el.getAttribute('data-field');
      if (f.slice(-5) === '_text') return;
      if (el.type === 'color' && el.getAttribute('data-blank') === '1') { content[f] = ''; return; }
      content[f] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
    });
    return content;
  }
  function pb2Post(fields) {
    var fd = new FormData();
    fd.append('_token', getCsrf());
    fd.append('page_id', PAGE_ID);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
  }
  function pb2QueueDraft() {
    clearTimeout(pb2DraftTimer);
    pb2DraftTimer = setTimeout(pb2SendDraft, 150);
  }
  function pb2SendDraft() {
    if (!selectedId) return;
    var sid = selectedId, seq = ++pb2DraftSeq, c = pb2CollectContent();
    var f = { section_op: 'draft', section_id: sid };
    Object.keys(c).forEach(function (k) { f['content[' + k + ']'] = c[k]; });
    if (window.pb2NavPending && window.pb2NavRows) f.nav_rows = JSON.stringify(window.pb2NavRows);
    pb2Post(f)
      .then(function (r) {
        if (!r.ok) throw new Error('draft ' + r.status);
        return fetch(PREVIEW_URL + (PREVIEW_URL.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now(), { headers: { 'Accept': 'text/html' } });
      })
      .then(function (r) { return r.text(); })
      .then(function (html) { if (seq === pb2DraftSeq) pb2SwapSection(sid, html); })
      .catch(function () { if (seq === pb2DraftSeq) refreshPreview(true); });
  }
  // Replace the section's contents inside its existing wrapper, so the
  // preview's hover/click-to-select listeners on the wrapper keep working.
  function pb2SwapSection(sid, html) {
    var doc = null;
    try { doc = PREVIEW_IFRAME && PREVIEW_IFRAME.contentDocument; } catch (e) { doc = null; }
    var sel = '[data-pb-section="' + sid + '"]';
    var cur = doc && doc.querySelector(sel);
    var next = new DOMParser().parseFromString(html, 'text/html').querySelector(sel);
    if (!cur || !next) { refreshPreview(true); return; }
    while (cur.firstChild) cur.removeChild(cur.firstChild);
    Array.prototype.slice.call(next.childNodes).forEach(function (n) {
      var node = doc.importNode(n, true);
      cur.appendChild(node);
    });
    // Scripts arriving this way don't run; re-create them so they do.
    cur.querySelectorAll('script').forEach(function (old) {
      var s = doc.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) s.setAttribute(old.attributes[i].name, old.attributes[i].value);
      s.textContent = old.textContent;
      old.parentNode.replaceChild(s, old);
    });
    // re-run page-wide effects for the redrawn section
    try {
      var w = doc.defaultView;
      if (w.mkAppearScan) w.mkAppearScan();
      if (w.mkPaintBg) w.mkPaintBg();
    } catch (e) {}
  }
  function pb2DraftClear() {
    return pb2Post({ section_op: 'draft_clear' }).catch(function () {});
  }
  function pb2Revert() {
    var item = document.querySelector('.pb2-section-item.selected');
    if (!item) return;
    var items = Array.prototype.slice.call(document.querySelectorAll('.pb2-section-item'));
    pb2SetClean();
    pb2DraftClear().then(function () {
      selectSection(item.dataset.sectionId, item.dataset.sectionType, items.indexOf(item) + 1, true);
      refreshPreview(true);
    });
  }
  function pb2AskUnsaved() {
    if (window.IntakeConfirm && typeof window.IntakeConfirm.show === 'function') {
      return window.IntakeConfirm.show({
        title: 'Save your changes first?',
        message: 'This section has edits that aren\u2019t saved. Save them and carry on, or stay here (Revert discards them).',
        confirmText: 'Save and continue',
        cancelText: 'Stay here'
      });
    }
    return Promise.resolve(false); // no dialog helper: stay put rather than lose edits
  }
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); pb2SaveNow(); }
  });
  window.addEventListener('beforeunload', function (e) {
    if (pb2Dirty) { e.preventDefault(); e.returnValue = ''; }
  });
  (function () {
    var rev = document.getElementById('pb2-insp-revert');
    if (rev) rev.addEventListener('click', pb2Revert);
    pb2Paint();
  })();
  function attachAutosaveListeners(sectionId) {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;
    body.querySelectorAll('input, textarea, select').forEach(input => {
      // Skip our own non-field controls
      if (!input.hasAttribute('data-field') && input.name !== 'is_visible') return;

      // mark unsaved; Save writes.
      input.addEventListener('input',  () => pb2MarkDirty());
      input.addEventListener('change', () => pb2MarkDirty());
    });
  }

  // Wire on initial load (first section's fields are already rendered)
  let selectedId = document.querySelector('.pb2-section-item.selected')?.dataset.sectionId;
  if (selectedId) attachAutosaveListeners(selectedId);

  // live preview bridge. Same-origin iframe + the
  // deterministic instance class (p-hero-{id} / p-cta-{id}) lets design
  // fields apply INSTANTLY on input; autosave persistence is untouched and
  // the post-save reload converges preview to truth. Cross-origin (custom
  // domain) previews silently no-op.
  function pb2LiveNode() {
    try {
      const doc = PREVIEW_IFRAME ? PREVIEW_IFRAME.contentDocument : null;
      if (!doc || !selectedId) return null;
      return doc.querySelector('[class*="-' + selectedId + '"]');
    } catch (err) { return null; }
  }
  const PB2_LIVE = {
    content_max_width: (n, v) => {
      const c = n.querySelector('.p-hero-content, .p-cta-inner');
      if (c) c.style.maxWidth = (parseInt(v) || 0) ? v + 'px' : '';
    },
    text_align: (n, v) => {
      const c = n.querySelector('.p-hero-content, .p-cta-inner');
      if (!c) return;
      c.style.textAlign = v;
      c.style.marginLeft  = (v === 'left') ? '0' : 'auto';
      c.style.marginRight = (v === 'right') ? '0' : 'auto';
    },
    vertical_align: (n, v) => {
      n.style.alignItems = ({ top: 'flex-start', center: 'center', bottom: 'flex-end' })[v] || 'center';
    },
    bg_overlay_opacity: (n, v) => {
      const o = n.querySelector('.p-hero-veil, .p-cta-veil');
      if (o) o.style.opacity = (parseInt(v) || 0) / 100;
    },
    bg_blur: (n, v) => {
      const o = n.querySelector('.p-hero-veil, .p-cta-veil');
      if (!o) return;
      o.style.backdropFilter = 'blur(' + v + 'px)';
      o.style.webkitBackdropFilter = 'blur(' + v + 'px)';
    },
    bg_parallax_depth: (n, v) => {
      const b = n.querySelector('[data-ia-parallax]');
      if (b) b.setAttribute('data-ia-parallax', ((parseInt(v) || 0) / 100).toFixed(2));
    },
  };
  function pb2LiveApply(e) {
    const f = e.target && e.target.dataset ? e.target.dataset.field : null;
    if (!f || !(f in PB2_LIVE)) return;
    const node = pb2LiveNode();
    if (!node) return;
    try { PB2_LIVE[f](node, e.target.value); } catch (err) { /* live-only, never blocks save */ }
  }
  const pb2Inspector = document.getElementById('pb2-inspector');
  if (pb2Inspector) {
    pb2Inspector.addEventListener('input', pb2LiveApply);
    pb2Inspector.addEventListener('change', pb2LiveApply); // hidden inputs (anchor dots) dispatch change
  }

  // ─── Section selection (swap inspector body, re-attach autosave) ──────
  function selectSection(sectionId, type, idx, force) {
    // never drop unsaved edits by clicking away.
    if (!force && pb2Dirty && String(sectionId) !== String(selectedId)) {
      pb2AskUnsaved().then(function (save) {
        if (!save) return;
        pb2SaveNow().then(function (ok) { if (ok) selectSection(sectionId, type, idx, true); });
      });
      return;
    }
    document.querySelectorAll('.pb2-section-item').forEach(el => el.classList.remove('selected'));
    const item = document.querySelector(`.pb2-section-item[data-section-id="${sectionId}"]`);
    if (!item) return;
    item.classList.add('selected');
    selectedId = sectionId;

    const label = TYPE_LABELS[type] || type;
    const name  = document.getElementById('pb2-insp-name');
    const sub   = document.getElementById('pb2-insp-sub');
    if (name) name.textContent = label;
    if (sub)  sub.textContent  = `section · ${idx.toString().padStart(2, '0')}`;

    fetch(`${UPDATE_URL}?_inspector=${sectionId}` + (pb2KeepDirty ? '&_draft=1' : ''), {
      headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(r => r.text())
      .then(html => {
        const body = document.getElementById('pb2-insp-body');
        if (body) {
          body.innerHTML = html;
          attachAutosaveListeners(sectionId);
          // wire up new per-type controls
          initInspectorControls();
          // a redraw that carried unsaved edits stays unsaved
          if (pb2KeepDirty) { pb2KeepDirty = false; pb2SetClean(); pb2MarkDirty(); }
          else pb2SetClean(); // controls may fire change while wiring up
        }
      })
      .catch(err => console.error('inspector load failed', err));
  }

  document.querySelectorAll('.pb2-section-item').forEach((el, idx) => {
    el.addEventListener('click', e => {
      if (e.target.closest('.pb2-drag-handle')) return;
      const sid  = el.dataset.sectionId;
      const type = el.dataset.sectionType;
      if (!sid) return;
      selectSection(sid, type, idx + 1);
      scrollPreviewTo(sid);
    });
  });

  // ─── keep the two panes pointed at the same thing ──
  // postMessage rather than reaching into the iframe directly: the preview is
  // same-origin today, but this keeps working if it ever isn't.
  function scrollPreviewTo(sectionId) {
    const frame = document.getElementById('pb2-preview');
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage(
      { source: 'pb-builder', type: 'scrollTo', id: sectionId },
      window.location.origin
    );
  }

  window.addEventListener('message', (e) => {
    if (e.origin !== window.location.origin) return;
    const d = e.data || {};
    if (d.source !== 'pb-preview' || d.type !== 'select' || !d.id) return;

    const item = document.querySelector(`.pb2-section-item[data-section-id="${d.id}"]`);
    if (!item) return;

    // Index as shown in the list, so the inspector subtitle stays truthful.
    const all = Array.prototype.slice.call(document.querySelectorAll('.pb2-section-item'));
    selectSection(d.id, item.dataset.sectionType || d.sectionType, all.indexOf(item) + 1);

    // The list scrolls independently and the chosen row is often out of view.
    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });

  // ─── Inspector header: visibility toggle + delete ─────────────────────
  const visBtn = document.getElementById('pb2-toggle-visible');
  if (visBtn) {
    visBtn.addEventListener('click', () => {
      if (!selectedId) return;
      const body = document.getElementById('pb2-insp-body');
      const isVisibleEl = body?.querySelector('[data-field="is_visible"]');
      if (isVisibleEl) {
        isVisibleEl.checked = !isVisibleEl.checked;
        // Trigger change so autosave handles persistence
        isVisibleEl.dispatchEvent(new Event('change'));
      }
      // Also flip the sidebar row's hidden class
      const item = document.querySelector(`.pb2-section-item[data-section-id="${selectedId}"]`);
      if (item) item.classList.toggle('hidden');
    });
  }

  const delBtn = document.getElementById('pb2-delete-section');
  if (delBtn) {
    delBtn.addEventListener('click', () => {
      if (!selectedId) return;
      // in-app dialog, no browser prompts
      IntakeConfirm.show({
        title: 'Delete this section?',
        message: 'This cannot be undone.',
        confirmText: 'Delete',
        danger: true
      }).then((ok) => {
        if (!ok) return;
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('section_op', 'delete');
      fd.append('page_id', PAGE_ID);
      fd.append('section_id', selectedId);
      fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(() => { location.reload(); })
        .catch(err => { console.error('delete failed', err); IntakeConfirm.alert({ title: 'Delete failed', message: 'Could not delete section.' }); });
      });
    });
  }

  // copy the selected section to the clipboard, and
  // show the Paste card here too, so the same page can take a copy.
  const copyBtn = document.getElementById('pb2-copy-section');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      if (!selectedId) return;
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('section_op', 'copy');
      fd.append('page_id', PAGE_ID);
      fd.append('section_id', selectedId);
      setStatus('Copying…');
      fetch(STORE_URL, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      })
        .then(r => r.json())
        .then(resp => {
          if (!resp || !resp.success) { setStatus('Copy failed', 3000); return; }
          setStatus('Copied — paste it from Add section on any page of this site', 5000);
          const slot = document.getElementById('pb2-paste-slot');
          if (slot) {
            const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
            slot.innerHTML = '<button type="button" class="pb2-paste-card" onclick="pasteSection()">'
              + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>'
              + '<span><span class="pb2-paste-name">Paste ' + esc(resp.label) + '</span>'
              + '<span class="pb2-paste-sub">copied from ' + esc(resp.from_page) + ' · just now · keeps for ' + esc(resp.minutes) + ' min</span></span></button>';
          }
        })
        .catch(err => { setStatus('Copy failed', 3000); console.error('copy failed', err); });
    });
  }

  // duplicate section
  const dupBtn = document.getElementById('pb2-duplicate-section');
  if (dupBtn) {
    dupBtn.addEventListener('click', () => {
      if (!selectedId) return;
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('section_op', 'duplicate');
      fd.append('page_id', PAGE_ID);
      fd.append('section_id', selectedId);
      setStatus('Duplicating…');
      fetch(STORE_URL, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      })
        .then(r => r.json())
        .then(resp => {
          if (resp && resp.id) {
            // Reload so the new section appears in the sidebar; the source
            // selection state will reset to the first section by default.
            location.reload();
          } else {
            setStatus('Duplicate failed', 3000);
            console.error('duplicate response:', resp);
          }
        })
        .catch(err => {
          setStatus('Duplicate failed', 3000);
          console.error('duplicate failed', err);
        });
    });
  }

  // drag-reorder via native HTML5 D&D, gated by the
  // drag-handle. We set draggable=true on the row only while the user holds
  // the drag handle, so clicks elsewhere on the row still select normally.
  (function setupDragReorder() {
    const list = document.getElementById('pb2-canvas');
    if (!list) return;
    let draggedEl = null;

    document.querySelectorAll('.pb2-section-item').forEach(row => {
      const handle = row.querySelector('.pb2-drag-handle');
      if (!handle) return;

      // Only enable drag when the handle is pressed
      handle.addEventListener('mousedown', () => { row.draggable = true; });
      handle.addEventListener('touchstart', () => { row.draggable = true; }, { passive: true });
      row.addEventListener('mouseup',     () => { row.draggable = false; });
      row.addEventListener('mouseleave',  () => { row.draggable = false; });

      row.addEventListener('dragstart', e => {
        draggedEl = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox needs data set or dragstart won't fire properly
        try { e.dataTransfer.setData('text/plain', row.dataset.sectionId); } catch (_) {}
      });
      row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        row.draggable = false;
        document.querySelectorAll('.pb2-section-item').forEach(el => {
          el.classList.remove('drag-over-top', 'drag-over-bottom');
        });
        draggedEl = null;
      });

      row.addEventListener('dragover', e => {
        if (!draggedEl || draggedEl === row) return;
        e.preventDefault();
        const rect = row.getBoundingClientRect();
        const mid  = rect.top + rect.height / 2;
        row.classList.toggle('drag-over-top', e.clientY < mid);
        row.classList.toggle('drag-over-bottom', e.clientY >= mid);
      });
      row.addEventListener('dragleave', () => {
        row.classList.remove('drag-over-top', 'drag-over-bottom');
      });

      row.addEventListener('drop', e => {
        if (!draggedEl || draggedEl === row) return;
        e.preventDefault();
        const rect = row.getBoundingClientRect();
        const mid  = rect.top + rect.height / 2;
        const insertBefore = e.clientY < mid;
        row.classList.remove('drag-over-top', 'drag-over-bottom');

        if (insertBefore) {
          row.parentNode.insertBefore(draggedEl, row);
        } else {
          row.parentNode.insertBefore(draggedEl, row.nextSibling);
        }
        persistReorder();
        refreshMetaNumbers();
      });
    });

    function persistReorder() {
      const ids = Array.from(document.querySelectorAll('.pb2-section-item'))
        .map(el => el.dataset.sectionId)
        .filter(Boolean);
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('section_op', 'reorder');
      fd.append('page_id', PAGE_ID);
      ids.forEach((id, i) => fd.append(`order[${i}]`, id));
      setStatus('Reordering…');
      fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json().catch(() => null))
        .then(() => {
          setStatus('Saved ✓', 1500);
          refreshPreview();
        })
        .catch(err => {
          setStatus('Reorder failed', 3000);
          console.error('reorder failed', err);
        });
    }

    function refreshMetaNumbers() {
      document.querySelectorAll('.pb2-section-item').forEach((el, i) => {
        const meta = el.querySelector('.pb2-section-meta');
        if (meta) meta.textContent = String(i + 1).padStart(2, '0');
      });
    }
  })();

  // ─── Device toggle ────────────────────────────────────────────────────
  document.querySelectorAll('.pb2-device-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.pb2-device-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const device = btn.dataset.device;
      PREVIEW_IFRAME.classList.remove('device-desktop', 'device-tablet', 'device-mobile');
      PREVIEW_IFRAME.classList.add('device-' + device);
    });
  });

  // ─── Tab switching ────────────────────────────────────────────────────
  // was cosmetic; now actually swaps visible
  // .pb2-tab-panel sections. Per-type partials (e.g. _hero.blade.php) wrap
  // each tab's fields in <div class="pb2-tab-panel" data-tab="...">. The
  // legacy _section.blade.php has no tab panels so all fields stay in
  // "Content" by default.
  let activeTab = 'content';
  function showTab(tabName) {
    activeTab = tabName;
    document.querySelectorAll('.pb2-insp-tab').forEach(x => {
      x.classList.toggle('active', x.dataset.tab === tabName);
    });
    document.querySelectorAll('.pb2-insp-body .pb2-tab-panel').forEach(panel => {
      panel.hidden = panel.dataset.tab !== tabName;
    });
  }
  document.querySelectorAll('.pb2-insp-tab').forEach(t => {
    t.addEventListener('click', () => showTab(t.dataset.tab));
  });

  // Per-type interactive controls (segmented controls,
  // bg-mode pane toggling, image upload, button list editor). Called after
  // every inspector body swap so newly-injected controls work.
  function initInspectorControls() {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;
    // controls rendered elsewhere ask to sit after a field.
    body.querySelectorAll('[data-move-after]').forEach(function (el) {
      var t = body.querySelector('[data-field="' + el.dataset.moveAfter + '"]');
      var row = t && (t.closest('label') || t.closest('.pb2-field'));
      if (row && row !== el) row.after(el);
    });

    // Restore the active tab after inspector reload so users don't bounce
    // back to "Content" mid-edit. If the new partial has no panels at all
    // (legacy), tabs become cosmetic again.
    const hasPanels = body.querySelector('.pb2-tab-panel');
    if (hasPanels) showTab(activeTab);

    // Segmented controls — clicking a button updates the hidden input it
    // 9-point anchor picker (.pb2-anchor). Each dot
    // carries data-anchor="<text_align> <vertical_align>"; clicking writes
    // both hidden inputs and dispatches change so autosave fires — the
    // exact contract the seg binder uses below.
    body.querySelectorAll('.pb2-anchor').forEach(grid => {
      const fields = (grid.dataset.anchorFields || 'text_align vertical_align').split(' ');
      grid.querySelectorAll('.pb2-anchor-dot').forEach(dot => {
        dot.addEventListener('click', () => {
          grid.querySelectorAll('.pb2-anchor-dot').forEach(d => d.classList.remove('on'));
          dot.classList.add('on');
          const vals = (dot.dataset.anchor || '').split(' ');
          fields.forEach((f, i) => {
            const target = body.querySelector(`input[type="hidden"][data-field="${f}"]`);
            if (target && vals[i] !== undefined) {
              target.value = vals[i];
              target.dispatchEvent(new Event('change', { bubbles: true }));
            }
          });
        });
      });
    });

    // controls (via data-field-seg) and dispatches a change event so
    // autosave fires.
    body.querySelectorAll('.pb2-seg').forEach(seg => {
      const fieldName = seg.dataset.fieldSeg;
      const target = body.querySelector(`input[type="hidden"][data-field="${fieldName}"]`);
      seg.querySelectorAll('.pb2-seg-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          seg.querySelectorAll('.pb2-seg-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          if (target) {
            target.value = btn.dataset.segValue;
            target.dispatchEvent(new Event('change', { bubbles: true }));
          }
          // For bg_mode, show only the matching .pb2-bg-pane
          if (fieldName === 'bg_mode') updateBgModePanes(body, btn.dataset.segValue);
        });
      });
    });

    // Show the right bg-mode pane on initial load
    const bgModeInput = body.querySelector('input[type="hidden"][data-field="bg_mode"]');
    if (bgModeInput) updateBgModePanes(body, bgModeInput.value);

    // Color picker text-input sync (autosave layer handles the _text shadow
    // pattern; we just need the swatch's input event to update its sibling
    // text input visually as the user picks a color).
    body.querySelectorAll('input[type="color"][data-field]').forEach(picker => {
      const fieldName = picker.dataset.field;
      const text = body.querySelector(`input[data-field="${fieldName}_text"]`);
      picker.addEventListener('input', () => {
        if (text) text.value = picker.value;
      });
      if (text) {
        text.addEventListener('input', () => {
          if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
        });
      }
    });

    // Image upload
    body.querySelectorAll('[data-image-upload]').forEach(btn => {
      const fieldName = btn.dataset.imageUpload;
      btn.addEventListener('click', () => triggerImageUpload(fieldName));
    });
    body.querySelectorAll('[data-image-replace]').forEach(btn => {
      const fieldName = btn.dataset.imageReplace;
      btn.addEventListener('click', () => triggerImageUpload(fieldName));
    });
    // inject a "Choose from library" control next to
    // every upload/replace trigger (once), wired to the picker modal.
    body.querySelectorAll('[data-image-upload],[data-image-replace]').forEach(btn => {
      const fieldName = btn.dataset.imageUpload || btn.dataset.imageReplace;
      const host = btn.closest('.pb2-image-tile-actions') || btn.parentElement;
      if (!host || host.querySelector('[data-image-pick]')) return;
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.dataset.imagePick = fieldName;
      if (btn.classList.contains('pb2-image-empty')) {
        // empty-state: a slim secondary line under the big upload button
        pick.className = 'pb2-textlink';
        pick.textContent = 'or choose from library';
        pick.style.cssText = 'display:block;margin-top:8px;font-size:12px';
        btn.insertAdjacentElement('afterend', pick);
      } else {
        pick.className = 'pb2-textlink';
        pick.textContent = 'Library';
        host.appendChild(pick);
      }
      pick.addEventListener('click', () => openMediaPicker(fieldName));
    });
    body.querySelectorAll('[data-image-remove]').forEach(btn => {
      const fieldName = btn.dataset.imageRemove;
      btn.addEventListener('click', () => {
        const hidden = body.querySelector(`input[data-field="${fieldName}"]`);
        if (hidden) {
          hidden.value = '';
          hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        // Reload inspector to switch to empty-state UI
        if (selectedId) {
          const item = document.querySelector(`.pb2-section-item[data-section-id="${selectedId}"]`);
          if (item) {
            const idx = Array.from(document.querySelectorAll('.pb2-section-item')).indexOf(item) + 1;
            setTimeout(() => pb2ReloadSame(item, idx), 300);
          }
        }
      });
    });

    // Button list (Hero CTAs)
    initButtonList(body);

    // Services category multi-select serializer
    initServiceCategoryList(body);

    // Nav links editor (saves to update_nav op,
    // not into content[]. Nav items are a tenant-global resource.)
    initNavLinkList(body);

    // Footer link columns + social links list editors.
    // Both serialize to hidden JSON [data-field] inputs that autosave picks up.
    initFooterLinkColumns(body);
    initFooterSocialLinks(body);

    // Stats row list editor
    initStatsList(body);

    // Pricing table plans list editor (nested:
    // plan rows + features sub-list per plan + radio-like featured toggle)
    initPlansList(body);

    // feature_grid features list editor
    initFeaturesList(body);

    // image gallery image-tile repeater
    initGalleryList(body);

    // rental_categories checkbox + drag-order list
    initRentalCategoryList(body);

    // logo_bar logos list editor
    initLogosList(body);

    // faq_accordion items list editor
    initFaqList(body);

    // text_image style switch + accordion items
    initTiAccList(body);

    // feature tiles list
    initFtList(body);

    // hero background video upload
    initHeroVideo(body);

    // step_timeline steps list editor
    initStepsList(body);
  }

  // every fleet category renders as a row with a
  // checkbox (include it?) and a drag handle (order it). Serializes the
  // checked ids, in DOM order, to the hidden category_ids JSON field.
  function initRentalCategoryList(body) {
    const root = body.querySelector('#pb2-rcat-list');
    const json = body.querySelector('#pb2-rcat-json');
    const imgJson = body.querySelector('#pb2-rcat-img-json');
    if (!root || !json) return;
    let dragEl = null;

    function serialize() {
      const out = [];
      const imgs = {};
      root.querySelectorAll('.pb2-rcat').forEach(row => {
        const cb = row.querySelector('[data-rcat-check]');
        if (cb && cb.checked) out.push(row.dataset.catId);
        const sel = row.querySelector('[data-rcat-photo]');
        if (sel && sel.value) imgs[row.dataset.catId] = sel.value;
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (imgJson) {
        imgJson.value = JSON.stringify(imgs);
        imgJson.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    root.querySelectorAll('.pb2-rcat').forEach(row => {
      const cb = row.querySelector('[data-rcat-check]');
      if (cb) cb.addEventListener('change', serialize);
      const sel = row.querySelector('[data-rcat-photo]');
      if (sel) sel.addEventListener('change', serialize);
      const h = row.querySelector('.pb2-navlist-handle');
      if (!h) return;
      h.addEventListener('mousedown', () => { row.draggable = true; });
      row.addEventListener('mouseup',    () => { row.draggable = false; });
      row.addEventListener('mouseleave', () => { row.draggable = false; });
      row.addEventListener('dragstart', e => {
        dragEl = row; row.style.opacity = '.4';
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
      });
      row.addEventListener('dragend', () => {
        row.style.opacity = ''; row.draggable = false; dragEl = null; serialize();
      });
      row.addEventListener('dragover', e => {
        e.preventDefault();
        if (!dragEl || dragEl === row) return;
        const r = row.getBoundingClientRect();
        const before = e.clientY < r.top + r.height / 2;
        root.insertBefore(dragEl, before ? row : row.nextSibling);
      });
    });
  }

  function initStepsList(body) {
    const root   = body.querySelector('#pb2-step-list');
    const addBtn = body.querySelector('#pb2-step-add');
    const json   = body.querySelector('#pb2-step-json');
    const count  = body.querySelector('#pb2-step-count');
    if (!root || !json) return;

    const MAX_STEPS = 8;

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-steprow').forEach((row, i) => {
        const title = row.querySelector('[data-step-field="title"]')?.value || '';
        const desc  = row.querySelector('[data-step-field="desc"]')?.value || '';
        const icon  = row.querySelector('[data-step-field="icon"]')?.value || '';
        if (title.trim() === '' && desc.trim() === '') return;
        out.push({ title, desc, icon });
        const pos = row.querySelector('.pb2-steprow-pos');
        if (pos) pos.textContent = 'Step ' + (i + 1);
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX_STEPS;
    }

    function wireRow(row) {
      row.querySelectorAll('[data-step-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = row.querySelector('[data-step-remove]');
      if (rm) rm.addEventListener('click', () => { row.remove(); serialize(); });
    }

    root.querySelectorAll('.pb2-steprow').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-steprow').length >= MAX_STEPS) return;
        const row = document.createElement('div');
        row.className = 'pb2-steprow';
        row.innerHTML = `
          <div class="pb2-steprow-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <span class="pb2-steprow-pos">New step</span>
            <input type="text" class="pb2-input pb2-input-sm pb2-feat-icon" data-step-field="icon" placeholder="🔧" maxlength="4">
            <button type="button" class="pb2-navlist-remove" data-step-remove title="Remove">×</button>
          </div>
          <div class="pb2-steprow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-step-field="title" placeholder="Step title">
            <textarea class="pb2-input pb2-input-sm pb2-textarea" data-step-field="desc" rows="2" placeholder="Description (optional)"></textarea>
          </div>
        `;
        root.appendChild(row);
        wireRow(row);
        serialize();
        row.querySelector('[data-step-field="title"]')?.focus();
      });
    }
  }

  // Text + image: Classic/Accordion switch and the accordion's item list.
  // the Feature tiles list: edit, reorder, remove, add, images.
  // upload a background video into the hero's video field.
  function initHeroVideo(body) {
    const field = body.querySelector('input[data-field="bg_video_url"]');
    const up = body.querySelector('[data-hero-video-upload]'), clr = body.querySelector('[data-hero-video-clear]'), st = body.querySelector('[data-hero-video-status]');
    if (!field || !up) return;
    const set = (v) => { field.value = v; field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); };
    if (clr) clr.addEventListener('click', () => set(''));
    up.addEventListener('click', () => {
      const input = document.createElement('input'); input.type = 'file'; input.accept = 'video/mp4,video/webm,video/quicktime,.mov'; input.style.display = 'none';
      document.body.appendChild(input);
      input.addEventListener('change', async () => {
        const file = input.files && input.files[0]; input.remove(); if (!file) return;
        if (file.size > 25 * 1024 * 1024) { IntakeConfirm.alert({ title: 'Video too large', message: 'Videos can be up to 25 MB. Shorten or compress it and try again.' }); return; }
        if (st) st.textContent = 'Uploading\u2026';
        const fd = new FormData(); fd.append('_token', getCsrf()); fd.append('file', file); fd.append('type', 'video');
        try {
          const resp = await fetch(UPLOAD_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
          const data = await resp.json().catch(() => null);
          if (data && data.ok && data.url) { set(data.url); if (st) st.textContent = 'Uploaded \u2713'; }
          else {
            if (st) st.textContent = '';
            const msg = (data && (data.message || (data.errors && Object.values(data.errors)[0] && Object.values(data.errors)[0][0]))) || (resp.status === 413 ? 'The server refused a file this large. Its upload limit needs raising.' : 'Please try again.');
            IntakeConfirm.alert({ title: 'Upload failed', message: msg });
          }
        } catch (e) { if (st) st.textContent = ''; IntakeConfirm.alert({ title: 'Upload failed', message: 'Please try again.' }); }
      });
      input.click();
    });
  }

  function initFtList(body) {
    const root = body.querySelector('#pb2-ft-list'), json = body.querySelector('#pb2-ft-json');
    if (!root || !json) return;
    const MAX = 12, count = body.querySelector('#pb2-ft-count'), addBtn = body.querySelector('#pb2-ft-add');
    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-ftrow').forEach((row, i) => {
        const it = {};
        row.querySelectorAll('[data-ft-field]').forEach(el => { it[el.getAttribute('data-ft-field')] = el.type === 'checkbox' ? el.checked : el.value; });
        const pos = row.querySelector('.pb2-faqrow-pos'); if (pos) pos.textContent = String(i + 1).padStart(2, '0');
        const tt = row.querySelector('.pb2-ft-title'); if (tt) tt.textContent = it.title || 'Untitled';
        if ((it.title || '').trim() === '') return;
        out.push(it);
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX;
    }
    function thumb(row) {
      const u = row.querySelector('[data-ft-field="d_image"]').value, t = row.querySelector('.pb2-tiacc-thumb');
      t.style.backgroundImage = u ? 'url("' + u.replace(/"/g, '%22') + '")' : ''; t.textContent = u ? '' : 'No image';
      const c = row.querySelector('[data-ft-clear]'); if (c) c.hidden = !u;
    }
    function wire(row) {
      row.querySelectorAll('[data-ft-field]').forEach(el => { el.addEventListener('input', serialize); el.addEventListener('change', serialize); });
      row.querySelector('[data-ft-toggle]').addEventListener('click', () => { const b = row.querySelector('[data-ft-body]'); b.hidden = !b.hidden; });
      row.querySelector('[data-ft-remove]').addEventListener('click', () => { row.remove(); serialize(); });
      row.querySelector('[data-ft-up]').addEventListener('click', () => { const p = row.previousElementSibling; if (p) { root.insertBefore(row, p); serialize(); } });
      row.querySelector('[data-ft-clear]').addEventListener('click', () => { row.querySelector('[data-ft-field="d_image"]').value = ''; thumb(row); serialize(); });
      row.querySelector('[data-ft-lib]').addEventListener('click', () => {
        window.__tiAccPick = (url) => { row.querySelector('[data-ft-field="d_image"]').value = url || ''; thumb(row); serialize(); };
        openMediaPicker('__tiacc_pick');
      });
      const st = row.querySelector('[data-ft-status]');
      row.querySelector('[data-ft-upload]').addEventListener('click', () => {
        const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/avif'; input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', async () => {
          const file = input.files && input.files[0]; input.remove(); if (!file) return;
          if (st) st.textContent = 'Uploading\u2026';
          const fd = new FormData(); fd.append('_token', getCsrf()); fd.append('file', file); fd.append('type', 'general');
          try {
            const resp = await fetch(UPLOAD_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            const data = await resp.json();
            if (data && data.ok && data.url) { row.querySelector('[data-ft-field="d_image"]').value = data.url; thumb(row); serialize(); if (st) st.textContent = 'Uploaded \u2713'; }
            else { if (st) st.textContent = ''; IntakeConfirm.alert({ title: 'Upload failed', message: (data && data.message) || 'Please try again.' }); }
          } catch (e) { if (st) st.textContent = ''; IntakeConfirm.alert({ title: 'Upload failed', message: 'Please try again.' }); }
        });
        input.click();
      });
      thumb(row);
    }
    root.querySelectorAll('.pb2-ftrow').forEach(wire);
    if (addBtn) addBtn.addEventListener('click', () => {
      if (root.querySelectorAll('.pb2-ftrow').length >= MAX) return;
      const row = body.querySelector('#pb2-ft-tpl').content.firstElementChild.cloneNode(true);
      root.appendChild(row); wire(row); row.querySelector('[data-ft-body]').hidden = false;
      const t = row.querySelector('[data-ft-field="title"]'); if (t) { t.value = 'New tile'; t.focus(); t.select(); }
      serialize();
    });
  }

  function initTiAccList(body) {
    const styleIn = body.querySelector('input[type="hidden"][data-field="ti_style"]');
    if (styleIn) {
      const apply = () => {
        const acc = styleIn.value === 'accordion';
        body.querySelectorAll('[data-ti-classic]').forEach(g => { g.hidden = acc; });
        body.querySelectorAll('[data-ti-acc]').forEach(g => { g.hidden = !acc; });
      };
      styleIn.addEventListener('change', apply);
      apply();
    }
    const root = body.querySelector('#pb2-tiacc-list');
    const json = body.querySelector('#pb2-tiacc-json');
    if (!root || !json) return;
    const MAX = 8;
    const addBtn = body.querySelector('#pb2-tiacc-add');
    const count  = body.querySelector('#pb2-tiacc-count');

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-tiaccrow').forEach((row, i) => {
        const g = f => (row.querySelector('[data-tiacc-field="' + f + '"]') || {}).value || '';
        const pos = row.querySelector('.pb2-faqrow-pos');
        if (pos) pos.textContent = String(i + 1).padStart(2, '0');
        const it = { title: g('title'), body: g('body'), image_url: g('image_url'), image_alt: g('image_alt'), cta_label: g('cta_label'), cta_url: g('cta_url') };
        if (it.title.trim() === '' && it.body.trim() === '') return;
        out.push(it);
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX;
    }
    function thumb(row) {
      const u = row.querySelector('[data-tiacc-field="image_url"]').value;
      const t = row.querySelector('.pb2-tiacc-thumb');
      t.style.backgroundImage = u ? 'url("' + u.replace(/"/g, '%22') + '")' : '';
      t.textContent = u ? '' : 'No image';
      const clr = row.querySelector('[data-tiacc-clear]'); if (clr) clr.hidden = !u;
    }
    function wire(row) {
      row.querySelectorAll('[data-tiacc-field]').forEach(inp => { inp.addEventListener('input', serialize); inp.addEventListener('change', serialize); });
      const rm = row.querySelector('[data-tiacc-remove]');
      if (rm) rm.addEventListener('click', () => { row.remove(); serialize(); });
      const up = row.querySelector('[data-tiacc-up]');
      if (up) up.addEventListener('click', () => { const p = row.previousElementSibling; if (p) { root.insertBefore(row, p); serialize(); } });
      // upload straight into an item (same path as Logo bar rows); it lands in the library too.
      const upl = row.querySelector('[data-tiacc-upload]');
      const st  = row.querySelector('[data-tiacc-status]');
      const say = (msg, ms) => { if (!st) return; st.textContent = msg; if (ms) setTimeout(() => { if (st.textContent === msg) st.textContent = ''; }, ms); };
      if (upl) upl.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', async () => {
          const file = input.files && input.files[0];
          input.remove();
          if (!file) return;
          say('Uploading\u2026');
          const fd = new FormData();
          fd.append('_token', getCsrf());
          fd.append('file', file);
          fd.append('type', 'general');
          try {
            const resp = await fetch(UPLOAD_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            const data = await resp.json();
            if (data && data.ok && data.url) {
              row.querySelector('[data-tiacc-field="image_url"]').value = data.url; thumb(row); serialize(); say('Uploaded \u2713', 1500);
            } else {
              say('');
              IntakeConfirm.alert({ title: 'Upload failed', message: (data && data.message) || 'Please try again.' });
            }
          } catch (e) {
            say(''); console.error(e);
            IntakeConfirm.alert({ title: 'Upload failed', message: 'Please try again.' });
          }
        });
        input.click();
      });
      const lib = row.querySelector('[data-tiacc-lib]');
      if (lib) lib.addEventListener('click', () => {
        window.__tiAccPick = (url) => { row.querySelector('[data-tiacc-field="image_url"]').value = url || ''; thumb(row); serialize(); };
        openMediaPicker('__tiacc_pick');
      });
      const clr = row.querySelector('[data-tiacc-clear]');
      if (clr) clr.addEventListener('click', () => { row.querySelector('[data-tiacc-field="image_url"]').value = ''; thumb(row); serialize(); });
      thumb(row);
    }
    root.querySelectorAll('.pb2-tiaccrow').forEach(wire);
    if (addBtn) addBtn.addEventListener('click', () => {
      if (root.querySelectorAll('.pb2-tiaccrow').length >= MAX) return;
      const tpl = body.querySelector('#pb2-tiacc-tpl');
      if (!tpl) return;
      const row = tpl.content.firstElementChild.cloneNode(true);
      root.appendChild(row); wire(row); serialize();
      const t = row.querySelector('[data-tiacc-field="title"]'); if (t) t.focus();
    });
  }

  function initFaqList(body) {
    const root   = body.querySelector('#pb2-faq-list');
    const addBtn = body.querySelector('#pb2-faq-add');
    const json   = body.querySelector('#pb2-faq-json');
    const count  = body.querySelector('#pb2-faq-count');
    if (!root || !json) return;

    const MAX_FAQS = 20;

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-faqrow').forEach((row, i) => {
        const q = row.querySelector('[data-faq-field="question"]')?.value || '';
        const a = row.querySelector('[data-faq-field="answer"]')?.value || '';
        const od = row.querySelector('[data-faq-field="open_default"]')?.checked ? true : false;
        if (q.trim() === '' && a.trim() === '') return;
        out.push({ question: q, answer: a, open_default: od });
        const pos = row.querySelector('.pb2-faqrow-pos');
        if (pos) pos.textContent = 'Q' + (i + 1);
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX_FAQS;
    }

    function wireRow(row) {
      row.querySelectorAll('[data-faq-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = row.querySelector('[data-faq-remove]');
      if (rm) rm.addEventListener('click', () => { row.remove(); serialize(); });
    }

    root.querySelectorAll('.pb2-faqrow').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-faqrow').length >= MAX_FAQS) return;
        const row = document.createElement('div');
        row.className = 'pb2-faqrow';
        row.innerHTML = `
          <div class="pb2-faqrow-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <span class="pb2-faqrow-pos">New</span>
            <label class="pb2-faqrow-open" title="Open this item by default">
              <input type="checkbox" data-faq-field="open_default">
              <span>Open</span>
            </label>
            <button type="button" class="pb2-navlist-remove" data-faq-remove title="Remove">×</button>
          </div>
          <div class="pb2-faqrow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-faq-field="question" placeholder="Question">
            <textarea class="pb2-input pb2-input-sm pb2-textarea" data-faq-field="answer" rows="3" placeholder="Answer"></textarea>
          </div>
        `;
        root.appendChild(row);
        wireRow(row);
        serialize();
        row.querySelector('[data-faq-field="question"]')?.focus();
      });
    }
  }

  function initLogosList(body) {
    const root   = body.querySelector('#pb2-logo-list');
    const addBtn = body.querySelector('#pb2-logo-add');
    const json   = body.querySelector('#pb2-logo-json');
    const count  = body.querySelector('#pb2-logo-count');
    if (!root || !json) return;

    const MAX_LOGOS = 12;

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-logorow').forEach(row => {
        const name     = row.querySelector('[data-logo-field="name"]')?.value || '';
        const logoUrl  = row.querySelector('[data-logo-field="logo_url"]')?.value || '';
        const linkUrl  = row.querySelector('[data-logo-field="link_url"]')?.value || '';
        const scale    = parseInt(row.querySelector('[data-logo-field="scale"]')?.value || '100', 10);
        // Skip totally empty rows
        if (name.trim() === '' && logoUrl.trim() === '') return;
        out.push({ name, logo_url: logoUrl, link_url: linkUrl, scale: scale });
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX_LOGOS;
    }

    function wireRow(row) {
      row.querySelectorAll('[data-logo-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = row.querySelector('[data-logo-remove]');
      if (rm) rm.addEventListener('click', () => { row.remove(); serialize(); });

      // live % readout beside the scale slider.
      const sc  = row.querySelector('[data-logo-field="scale"]');
      const out = row.querySelector('[data-logo-scale-out]');
      if (sc && out) sc.addEventListener('input', () => { out.textContent = sc.value + '%'; });

      // image comes from an upload or the library,
      // like every other image field in the builder.
      const hidden = row.querySelector('[data-logo-field="logo_url"]');
      const thumb  = row.querySelector('[data-logo-thumb]');
      const clear  = row.querySelector('[data-logo-clear]');
      function setUrl(url) {
        hidden.value = url || '';
        if (thumb) thumb.style.backgroundImage = url ? `url('${url}')` : '';
        if (clear) clear.style.display = url ? '' : 'none';
        serialize();
      }
      const up = row.querySelector('[data-logo-upload]');
      if (up) up.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', async () => {
          const file = input.files?.[0];
          input.remove();
          if (!file) return;
          setStatus('Uploading\u2026');
          const fd = new FormData();
          fd.append('_token', getCsrf());
          fd.append('file', file);
          fd.append('type', 'partner_logo'); // never 'logo' — that is the brand logo
          try {
            const resp = await fetch(UPLOAD_URL, {
              method: 'POST', body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await resp.json();
            if (data && data.ok && data.url) { setUrl(data.url); setStatus('Uploaded \u2713', 1500); }
            else { setStatus('Upload failed', 3000); IntakeConfirm.alert({ title: 'Upload failed', message: (data && data.message) || 'Please try again.' }); }
          } catch (e) {
            setStatus('Upload failed', 3000); console.error(e);
            IntakeConfirm.alert({ title: 'Upload failed', message: 'Please try again.' });
          }
        });
        input.click();
      });
      const lib = row.querySelector('[data-logo-lib]');
      if (lib) lib.addEventListener('click', () => {
        window.__logoPick = setUrl;
        openMediaPicker('__logo_pick');
      });
      if (clear) clear.addEventListener('click', () => setUrl(''));
    }

    root.querySelectorAll('.pb2-logorow').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-logorow').length >= MAX_LOGOS) return;
        const row = document.createElement('div');
        row.className = 'pb2-logorow';
        row.innerHTML = `
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-logo-thumb" data-logo-thumb></div>
          <div class="pb2-logorow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-logo-field="name" placeholder="Name (e.g. Acme Co)">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-logo-field="link_url" placeholder="Link URL (optional)">
            <div class="pb2-logo-acts">
              <button type="button" class="pb2-logo-btn" data-logo-upload>Upload</button>
              <button type="button" class="pb2-logo-btn" data-logo-lib>Library</button>
              <button type="button" class="pb2-logo-btn" data-logo-clear style="display:none">Clear</button>
              <span class="pb2-logo-scale">
                <input type="range" min="60" max="140" step="5" data-logo-field="scale" value="100" title="Scale this logo">
                <span data-logo-scale-out>100%</span>
              </span>
            </div>
            <input type="hidden" data-logo-field="logo_url">
          </div>
          <button type="button" class="pb2-navlist-remove" data-logo-remove title="Remove">×</button>
        `;
        root.appendChild(row);
        wireRow(row);
        serialize();
        row.querySelector('[data-logo-field="name"]')?.focus();
      });
    }
  }

  // the feature-groups editor. Delegated, so it works
  // for editor bodies the builder injects later. Groups serialize into the
  // hidden data-field="groups" JSON, which the builder saves like any field.
  (function () {
    function serializePfg(ed) {
      var out = [];
      ed.querySelectorAll('.pfg-list .pfg-group').forEach(function (g) {
        var v = function (k) { var el = g.querySelector('[data-pfg-field="' + k + '"]'); return el ? el.value : ''; };
        var feats = v('features').split('\n').map(function (l) { return l.trim(); }).filter(Boolean).map(function (l) {
          var parts = l.split(/\s+[\u2014\u2013|]\s+|\s+-\s+/);
          var title = parts.shift();
          return { title: title.trim(), body: parts.join(' \u2014 ').trim() };
        });
        out.push({ label: v('label').trim(), heading: v('heading').trim(), lead: v('lead').trim(), features: feats });
      });
      var json = ed.querySelector('.pfg-json');
      if (json) { json.value = JSON.stringify(out); json.dispatchEvent(new Event('change', { bubbles: true })); }
      var cnt = ed.querySelector('.pfg-count'); if (cnt) { cnt.textContent = out.length; }
    }
    document.addEventListener('input', function (e) {
      var ed = e.target.closest && e.target.closest('.pfg-editor');
      if (ed && e.target.hasAttribute('data-pfg-field')) { serializePfg(ed); }
    });
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-pfg]');
      if (!b) { return; }
      var ed = b.closest('.pfg-editor'); if (!ed) { return; }
      var act = b.getAttribute('data-pfg'), grp = b.closest('.pfg-group'), list = ed.querySelector('.pfg-list');
      if (act === 'add') { list.appendChild(ed.querySelector('.pfg-tpl').content.firstElementChild.cloneNode(true)); }
      else if (act === 'remove' && grp) { grp.remove(); }
      else if (act === 'up' && grp && grp.previousElementSibling) { list.insertBefore(grp, grp.previousElementSibling); }
      else if (act === 'down' && grp && grp.nextElementSibling) { list.insertBefore(grp.nextElementSibling, grp); }
      serializePfg(ed);
    });
  })();

  function initFeaturesList(body) {
    const root   = body.querySelector('#pb2-feat-list');
    const addBtn = body.querySelector('#pb2-feat-add');
    const json   = body.querySelector('#pb2-feat-json');
    const count  = body.querySelector('#pb2-feat-count');
    if (!root || !json) return;

    const MAX_FEATS = 12;
    let dragFeat = null;

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-feat').forEach(featEl => {
        out.push({
          icon:      featEl.querySelector('[data-feat-field="icon"]')?.value || '',
          title:     featEl.querySelector('[data-feat-field="title"]')?.value || '',
          price:     featEl.querySelector('[data-feat-field="price"]')?.value || '',
          body:      featEl.querySelector('[data-feat-field="body"]')?.value || '',
          cta_label: featEl.querySelector('[data-feat-field="cta_label"]')?.value || '',
          cta_url:   featEl.querySelector('[data-feat-field="cta_url"]')?.value || '',
          service_id: featEl.querySelector('[data-feat-field="service_id"]')?.value || '',
        });
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX_FEATS;
    }

    function wireFeat(featEl) {
      featEl.querySelectorAll('[data-feat-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = featEl.querySelector('[data-feat-remove]');
      if (rm) rm.addEventListener('click', () => { featEl.remove(); serialize(); });

      // the ⋮⋮ handle was rendered but inert; same
      // handle-armed drag the gallery tiles use. Reorder persists via
      // serialize() on drop.
      const h = featEl.querySelector('.pb2-navlist-handle');
      if (h) {
        h.addEventListener('mousedown', () => { featEl.draggable = true; });
        featEl.addEventListener('mouseup',    () => { featEl.draggable = false; });
        featEl.addEventListener('mouseleave', () => { featEl.draggable = false; });
        featEl.addEventListener('dragstart', e => {
          dragFeat = featEl; featEl.style.opacity = '.4';
          root.classList.add('pb2-feat-compact'); // collapse all cards to their title row while dragging
          e.dataTransfer.effectAllowed = 'move';
          try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
        });
        featEl.addEventListener('dragend', () => {
          featEl.style.opacity = ''; featEl.draggable = false; dragFeat = null;
          root.classList.remove('pb2-feat-compact');
          serialize();
        });
        featEl.addEventListener('dragover', e => {
          e.preventDefault();
          if (!dragFeat || dragFeat === featEl) return;
          const r = featEl.getBoundingClientRect();
          const before = e.clientY < r.top + r.height / 2;
          root.insertBefore(dragFeat, before ? featEl : featEl.nextSibling);
        });
      }

      // autofill — pick a service, fill the card from the catalog.
      const svcSel = featEl.querySelector('[data-feat-field="service_id"]');
      if (svcSel) svcSel.addEventListener('change', () => {
        const opt = svcSel.options[svcSel.selectedIndex];
        if (!opt || !opt.value) return;
        const set = (f, v) => { const el = featEl.querySelector('[data-feat-field="' + f + '"]'); if (el) el.value = v; };
        set('title', opt.dataset.name || '');
        set('price', opt.dataset.price ? opt.dataset.price + ' & up' : '');
        set('body', opt.dataset.body || '');
        serialize();
      });
    }

    root.querySelectorAll('.pb2-feat').forEach(wireFeat);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-feat').length >= MAX_FEATS) return;
        const svcOpts = body.querySelector('#pb2-feat-service-template')?.innerHTML || '<option value=""></option>';
        const featEl = document.createElement('div');
        featEl.className = 'pb2-feat';
        featEl.innerHTML = `
          <div class="pb2-feat-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <input type="text" class="pb2-input pb2-input-sm pb2-feat-icon" data-feat-field="icon" placeholder="✓" maxlength="4">
            <input type="text" class="pb2-input pb2-input-sm" data-feat-field="title" placeholder="Title">
            <button type="button" class="pb2-navlist-remove" data-feat-remove title="Remove">×</button>
          </div>
          <div class="pb2-feat-fields">
            <select class="pb2-input pb2-input-sm pb2-feat-service" data-feat-field="service_id">${svcOpts}</select>
            <input type="text" class="pb2-input pb2-input-sm" data-feat-field="price" placeholder="Price (optional)">
            <textarea class="pb2-input pb2-input-sm pb2-textarea" data-feat-field="body" rows="2" placeholder="Description"></textarea>
            <div class="pb2-feat-cta-row">
              <input type="text" class="pb2-input pb2-input-sm" data-feat-field="cta_label" placeholder="Optional CTA label">
              <input type="text" class="pb2-input pb2-input-sm" data-feat-field="cta_url" placeholder="/url">
            </div>
          </div>
        `;
        root.appendChild(featEl);
        wireFeat(featEl);
        serialize();
        featEl.querySelector('[data-feat-field="title"]')?.focus();
      });
    }
  }

  // image_gallery repeater: serializes tiles to the hidden
  // [data-field="images"] JSON, adds via the shared uploader, removes, reorders.
  function initGalleryList(body) {
    const root   = body.querySelector('#pb2-gimg-list');
    const addBtn = body.querySelector('#pb2-gimg-add');
    const json   = body.querySelector('#pb2-gimg-json');
    const count  = body.querySelector('#pb2-gimg-count');
    if (!root || !json) return;

    const MAX_IMG = 24;
    let dragEl = null;
    // the carousel partial opts into a per-slide
    // link field; the gallery partial does not set the flag and is unchanged.
    const HAS_LINK = root.dataset.links === '1';

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-gimg').forEach(tile => {
        const url = tile.querySelector('[data-gimg-field="url"]')?.value || '';
        if (!url) return;
        const rec = {
          url,
          caption: tile.querySelector('[data-gimg-field="caption"]')?.value || '',
          alt:     tile.querySelector('[data-gimg-field="alt"]')?.value || '',
        };
        if (HAS_LINK) rec.link = tile.querySelector('[data-gimg-field="link"]')?.value || '';
        out.push(rec);
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' / ' + MAX_IMG;
      const empty = root.querySelector('#pb2-gimg-empty');
      if (empty) empty.style.display = out.length ? 'none' : '';
    }

    function wireTile(tile) {
      tile.querySelectorAll('[data-gimg-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = tile.querySelector('[data-gimg-remove]');
      if (rm) rm.addEventListener('click', () => { tile.remove(); serialize(); });

      const h = tile.querySelector('.pb2-navlist-handle');
      if (h) {
        h.addEventListener('mousedown', () => { tile.draggable = true; });
        tile.addEventListener('mouseup',    () => { tile.draggable = false; });
        tile.addEventListener('mouseleave', () => { tile.draggable = false; });
        tile.addEventListener('dragstart', e => {
          dragEl = tile; tile.style.opacity = '.4';
          e.dataTransfer.effectAllowed = 'move';
          try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
        });
        tile.addEventListener('dragend', () => {
          tile.style.opacity = ''; tile.draggable = false; dragEl = null; serialize();
        });
        tile.addEventListener('dragover', e => {
          e.preventDefault();
          if (!dragEl || dragEl === tile) return;
          const r = tile.getBoundingClientRect();
          const before = e.clientY < r.top + r.height / 2;
          root.insertBefore(dragEl, before ? tile : tile.nextSibling);
        });
      }
    }

    function makeTile(url) {
      const tile = document.createElement('div');
      tile.className = 'pb2-gimg';
      tile.innerHTML =
        '<span class="pb2-navlist-handle" title="Drag to reorder">\u22EE\u22EE</span>' +
        '<div class="pb2-gimg-thumb" style="background-image:url(\'' + url + '\')"></div>' +
        '<div class="pb2-gimg-fields">' +
          '<div class="pb2-gimg-head">' +
            '<input type="text" class="pb2-input pb2-input-sm" data-gimg-field="caption" placeholder="Caption (optional)">' +
            '<button type="button" class="pb2-navlist-remove" data-gimg-remove title="Remove">\u00D7</button>' +
          '</div>' +
          '<input type="text" class="pb2-input pb2-input-sm" data-gimg-field="alt" placeholder="Alt text (accessibility)">' +
          (HAS_LINK ? '<input type="text" class="pb2-input pb2-input-sm" data-gimg-field="link" placeholder="Link URL (optional)">' : '') +
          '<input type="hidden" data-gimg-field="url" value="' + url + '">' +
        '</div>';
      return tile;
    }

    root.querySelectorAll('.pb2-gimg').forEach(wireTile);

    // "+ From library" opens the media modal in
    // append mode; each click adds a tile and the modal stays open.
    const libBtn = body.querySelector('#pb2-gimg-lib');
    if (libBtn && !libBtn.dataset.wired) {
      libBtn.dataset.wired = '1';
      window.__gimgAppend = (url) => {
        if (root.querySelectorAll('.pb2-gimg').length >= MAX_IMG) { setStatus('Max ' + MAX_IMG + ' images', 2000); return; }
        const tile = makeTile(url);
        root.appendChild(tile);
        wireTile(tile);
        serialize();
      };
      libBtn.addEventListener('click', () => openMediaPicker('__gimg_append'));
    }

    if (addBtn && !addBtn.dataset.wired) {
      addBtn.dataset.wired = '1';
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-gimg').length >= MAX_IMG) { setStatus('Max ' + MAX_IMG + ' images', 2000); return; }
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', async () => {
          const file = input.files?.[0];
          input.remove();
          if (!file) return;
          setStatus('Uploading\u2026');
          const fd = new FormData();
          fd.append('_token', getCsrf());
          fd.append('file', file);
          fd.append('type', 'gallery');
          try {
            const resp = await fetch(UPLOAD_URL, {
              method: 'POST', body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await resp.json();
            if (data && data.ok && data.url) {
              const tile = makeTile(data.url);
              root.appendChild(tile);
              wireTile(tile);
              serialize();
              setStatus('Uploaded \u2713', 1500);
              tile.querySelector('[data-gimg-field="caption"]')?.focus();
            } else {
              setStatus('Upload failed', 3000);
              IntakeConfirm.alert({ title: 'Upload failed', message: (data && data.message) || 'Please try again.' });
            }
          } catch (e) {
            setStatus('Upload failed', 3000); console.error(e); IntakeConfirm.alert({ title: 'Upload failed', message: 'Please try again.' });
          }
        });
        input.click();
      });
    }
  }

  function initPlansList(body) {
    const root   = body.querySelector('#pb2-plans-list');
    const addBtn = body.querySelector('#pb2-plans-add');
    const json   = body.querySelector('#pb2-plans-json');
    const count  = body.querySelector('#pb2-plans-count');
    if (!root || !json) return;

    const MAX_PLANS = 6;

    function serialize() {
      const plans = [];
      root.querySelectorAll('.pb2-plan').forEach((planEl, i) => {
        const plan = {
          eyebrow:      planEl.querySelector('[data-plan-field="eyebrow"]')?.value || '',
          title:        planEl.querySelector('[data-plan-field="title"]')?.value || '',
          price:        planEl.querySelector('[data-plan-field="price"]')?.value || '',
          price_suffix: planEl.querySelector('[data-plan-field="price_suffix"]')?.value || '',
          badge_label:  planEl.querySelector('[data-plan-field="badge_label"]')?.value || '',
          featured:     planEl.querySelector('[data-plan-field="featured"]')?.checked ? true : false,
          cta_label:    planEl.querySelector('[data-plan-field="cta_label"]')?.value || '',
          cta_url:      planEl.querySelector('[data-plan-field="cta_url"]')?.value || '',
          features:     [],
        };
        planEl.querySelectorAll('.pb2-plan-feature').forEach(featEl => {
          const txt = featEl.querySelector('[data-feat-field="text"]')?.value || '';
          if (txt.trim() !== '') plan.features.push(txt);
        });
        plans.push(plan);
        const posLabel = planEl.querySelector('.pb2-plan-pos');
        if (posLabel) posLabel.textContent = 'Plan ' + (i + 1);
      });
      json.value = JSON.stringify(plans);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = plans.length + ' / ' + MAX_PLANS;
    }

    // Only-one-featured enforcement
    function enforceSingleFeatured(justCheckedEl) {
      root.querySelectorAll('[data-plan-field="featured"]').forEach(cb => {
        if (cb !== justCheckedEl) cb.checked = false;
      });
    }

    function wireFeatureRow(featEl) {
      featEl.querySelectorAll('[data-feat-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = featEl.querySelector('[data-feat-remove]');
      if (rm) rm.addEventListener('click', () => { featEl.remove(); serialize(); });
    }

    function wirePlan(planEl) {
      planEl.querySelectorAll('[data-plan-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      // Featured checkbox enforces single-on
      const feat = planEl.querySelector('[data-plan-field="featured"]');
      if (feat) {
        feat.addEventListener('change', () => {
          if (feat.checked) enforceSingleFeatured(feat);
          serialize();
        });
      }
      const rm = planEl.querySelector('[data-plan-remove]');
      if (rm) rm.addEventListener('click', () => { planEl.remove(); serialize(); });

      // Wire each feature row
      planEl.querySelectorAll('.pb2-plan-feature').forEach(wireFeatureRow);

      // Add-feature button
      const addFeat = planEl.querySelector('[data-plan-addfeat]');
      if (addFeat) {
        addFeat.addEventListener('click', () => {
          const featList = planEl.querySelector('.pb2-plan-feature-list');
          if (!featList) return;
          const featEl = document.createElement('div');
          featEl.className = 'pb2-plan-feature';
          featEl.innerHTML = `
            <input type="text" class="pb2-input pb2-input-sm" data-feat-field="text" placeholder="Feature text">
            <button type="button" class="pb2-navlist-remove" data-feat-remove title="Remove">×</button>
          `;
          featList.appendChild(featEl);
          wireFeatureRow(featEl);
          serialize();
          featEl.querySelector('[data-feat-field="text"]')?.focus();
        });
      }
    }

    root.querySelectorAll('.pb2-plan').forEach(wirePlan);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-plan').length >= MAX_PLANS) return;
        const planEl = document.createElement('div');
        planEl.className = 'pb2-plan';
        planEl.innerHTML = `
          <div class="pb2-plan-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <span class="pb2-plan-pos">New plan</span>
            <label class="pb2-plan-featured" title="Mark featured">
              <input type="checkbox" data-plan-field="featured">
              <span>★ Featured</span>
            </label>
            <button type="button" class="pb2-navlist-remove" data-plan-remove title="Remove plan">×</button>
          </div>
          <div class="pb2-plan-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-plan-field="eyebrow" placeholder="01 · BASIC">
            <input type="text" class="pb2-input pb2-input-sm" data-plan-field="title" placeholder="Plan name">
            <div class="pb2-plan-price-row">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="price" placeholder="$90">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="price_suffix" placeholder="& up">
            </div>
            <input type="text" class="pb2-input pb2-input-sm" data-plan-field="badge_label" placeholder="Badge label (only shown when featured)">
            <div class="pb2-plan-features">
              <div class="pb2-plan-features-label">Features</div>
              <div class="pb2-plan-feature-list"></div>
              <button type="button" class="pb2-addrow pb2-plan-addfeat" data-plan-addfeat>+ Add feature</button>
            </div>
            <div class="pb2-plan-cta-row">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="cta_label" placeholder="Optional CTA label">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="cta_url" placeholder="/url">
            </div>
          </div>
        `;
        root.appendChild(planEl);
        wirePlan(planEl);
        serialize();
        planEl.querySelector('[data-plan-field="title"]')?.focus();
      });
    }
  }

  // Stats row list editor — flat list of { number, label, description }.
  // Serializes to #pb2-stats-json.
  function initStatsList(body) {
    const list   = body.querySelector('#pb2-stats-list');
    const addBtn = body.querySelector('#pb2-stats-add');
    const json   = body.querySelector('#pb2-stats-json');
    const count  = body.querySelector('#pb2-stats-count');
    if (!list || !json) return;

    const MAX_STATS = 6;

    function serialize() {
      const out = [];
      list.querySelectorAll('.pb2-statrow').forEach(row => {
        out.push({
          number:      row.querySelector('[data-stat-field="number"]')?.value || '',
          label:       row.querySelector('[data-stat-field="label"]')?.value || '',
          description: row.querySelector('[data-stat-field="description"]')?.value || '',
        });
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = `${out.length} / ${MAX_STATS}`;
    }

    function wireRow(row) {
      row.querySelectorAll('[data-stat-field]').forEach(input => {
        input.addEventListener('input', serialize);
        input.addEventListener('change', serialize);
      });
      const rm = row.querySelector('[data-stat-remove]');
      if (rm) rm.addEventListener('click', () => { row.remove(); serialize(); });
    }

    list.querySelectorAll('.pb2-statrow').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (list.querySelectorAll('.pb2-statrow').length >= MAX_STATS) return;
        const row = document.createElement('div');
        row.className = 'pb2-statrow';
        row.innerHTML = `
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-statrow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-stat-field="number" placeholder="200+">
            <input type="text" class="pb2-input pb2-input-sm" data-stat-field="label" placeholder="Bikes serviced">
            <input type="text" class="pb2-input pb2-input-sm" data-stat-field="description" placeholder="Description (optional)">
          </div>
          <button type="button" class="pb2-navlist-remove" data-stat-remove title="Remove">×</button>
        `;
        list.appendChild(row);
        wireRow(row);
        serialize();
        row.querySelector('[data-stat-field="number"]')?.focus();
      });
    }
  }

  // Footer link columns — nested editor: list of columns, each with heading
  // + nested list of links. Serializes to #pb2-ftr-cols-json.
  function initFooterLinkColumns(body) {
    const root   = body.querySelector('#pb2-ftr-collist');
    const addCol = body.querySelector('#pb2-ftr-addcol');
    const json   = body.querySelector('#pb2-ftr-cols-json');
    const count  = body.querySelector('#pb2-ftr-cols-count');
    if (!root || !json) return;

    function serialize() {
      const cols = [];
      root.querySelectorAll('.pb2-ftr-col').forEach(colEl => {
        const heading = colEl.querySelector('[data-col-field="heading"]')?.value || '';
        const links = [];
        colEl.querySelectorAll('.pb2-ftr-link').forEach(linkEl => {
          const label = linkEl.querySelector('[data-link-field="label"]')?.value || '';
          const url   = linkEl.querySelector('[data-link-field="url"]')?.value || '';
          if (label || url) links.push({ label, url });
        });
        cols.push({ heading, links });
      });
      json.value = JSON.stringify(cols);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = cols.length + ' column' + (cols.length === 1 ? '' : 's');
    }

    function wireLink(linkEl) {
      linkEl.querySelectorAll('[data-link-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = linkEl.querySelector('[data-link-remove]');
      if (rm) rm.addEventListener('click', () => { linkEl.remove(); serialize(); });
    }

    function wireCol(colEl) {
      colEl.querySelectorAll('[data-col-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = colEl.querySelector('[data-col-remove]');
      if (rm) rm.addEventListener('click', () => { colEl.remove(); serialize(); });

      colEl.querySelectorAll('.pb2-ftr-link').forEach(wireLink);

      const addLink = colEl.querySelector('[data-col-addlink]');
      if (addLink) {
        addLink.addEventListener('click', () => {
          const linksWrap = colEl.querySelector('.pb2-ftr-col-links');
          if (!linksWrap) return;
          const linkEl = document.createElement('div');
          linkEl.className = 'pb2-ftr-link';
          linkEl.innerHTML = `
            <input type="text" class="pb2-input pb2-input-sm" data-link-field="label" placeholder="Label">
            <input type="text" class="pb2-input pb2-input-sm" data-link-field="url" placeholder="URL">
            <button type="button" class="pb2-navlist-remove" data-link-remove title="Remove link">×</button>
          `;
          linksWrap.appendChild(linkEl);
          wireLink(linkEl);
          serialize();
          linkEl.querySelector('[data-link-field="label"]')?.focus();
        });
      }
    }

    root.querySelectorAll('.pb2-ftr-col').forEach(wireCol);

    if (addCol) {
      addCol.addEventListener('click', () => {
        const colEl = document.createElement('div');
        colEl.className = 'pb2-ftr-col';
        colEl.innerHTML = `
          <div class="pb2-ftr-col-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <input type="text" class="pb2-input pb2-input-sm" data-col-field="heading" placeholder="Column heading">
            <button type="button" class="pb2-navlist-remove" data-col-remove title="Remove column">×</button>
          </div>
          <div class="pb2-ftr-col-links"></div>
          <button type="button" class="pb2-addrow pb2-ftr-addlink" data-col-addlink>+ Add link</button>
        `;
        root.appendChild(colEl);
        wireCol(colEl);
        serialize();
        colEl.querySelector('[data-col-field="heading"]')?.focus();
      });
    }
  }

  // Footer social links — flat list of { platform, url }.
  // Serializes to #pb2-ftr-social-json.
  function initFooterSocialLinks(body) {
    const list   = body.querySelector('#pb2-ftr-sociallist');
    const addBtn = body.querySelector('#pb2-ftr-addsocial');
    const json   = body.querySelector('#pb2-ftr-social-json');
    const count  = body.querySelector('#pb2-ftr-social-count');
    if (!list || !json) return;

    function serialize() {
      const out = [];
      list.querySelectorAll('.pb2-navlist-item').forEach(row => {
        const platform = row.querySelector('[data-social-field="platform"]')?.value || 'website';
        const url      = row.querySelector('[data-social-field="url"]')?.value || '';
        if (url) out.push({ platform, url });
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (count) count.textContent = out.length + ' link' + (out.length === 1 ? '' : 's');
    }

    function wireRow(row) {
      row.querySelectorAll('[data-social-field]').forEach(inp => {
        inp.addEventListener('input', serialize);
        inp.addEventListener('change', serialize);
      });
      const rm = row.querySelector('[data-social-remove]');
      if (rm) rm.addEventListener('click', () => { row.remove(); serialize(); });
    }

    list.querySelectorAll('.pb2-navlist-item').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'pb2-navlist-item';
        row.innerHTML = `
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-navlist-fields">
            <select class="pb2-input pb2-input-sm" data-social-field="platform">
              <option value="instagram">Instagram</option>
              <option value="facebook">Facebook</option>
              <option value="twitter">X / Twitter</option>
              <option value="youtube">YouTube</option>
              <option value="tiktok">TikTok</option>
              <option value="linkedin">LinkedIn</option>
              <option value="pinterest">Pinterest</option>
              <option value="github">GitHub</option>
              <option value="website">Website</option>
              <option value="email">Email</option>
            </select>
            <input type="text" class="pb2-input pb2-input-sm" data-social-field="url" placeholder="https://...">
          </div>
          <button type="button" class="pb2-navlist-remove" data-social-remove title="Remove">×</button>
        `;
        list.appendChild(row);
        wireRow(row);
        row.querySelector('[data-social-field="url"]')?.focus();
      });
    }
  }

  // Nav link list editor. Each row has label + URL + open-in-new-tab toggle.
  // Saves via the existing tenant.pages.store endpoint with op=update_nav.
  // Auto-saves on input/change with the same 800/100ms debounce as content.
// the menu editor inside the Nav section. Rows are pages
  // (follow their page) or links, each with a style and side. Changes mark
  // the section unsaved, show in the preview straight away (sent with the
  // section's draft), and are written by the section's Save button.
  function initNavLinkList(body) {
    const host   = body.querySelector('#pb2-nav-linklist');
    const count  = body.querySelector('#pb2-nav-links-count');
    const status = body.querySelector('#pb2-nav-status');
    if (!host) return;
    let rows, pages, apps;
    try {
      rows  = JSON.parse(host.dataset.rows  || '[]');
      pages = JSON.parse(host.dataset.pages || '{}');
      apps  = JSON.parse(host.dataset.apps  || '[]');
    } catch (e) { rows = []; pages = {}; apps = []; }
    let pop = false, from = null, hl = null; // hl: page highlighted after arriving from its link
    const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    window.pb2NavRows = rows;

    function setStatus(text) {
      if (status) status.innerHTML = text ? `<span class="pb2-field-hint" style="text-align:left">${text}</span>` : '';
    }
    function changed() {
      window.pb2NavRows = rows;
      window.pb2NavPending = true;
      window.pb2NavSaver = saveNavLinks;
      if (count) count.textContent = rows.length;
      if (window.pb2MarkDirty) window.pb2MarkDirty();
      render();
    }
    function seg(i, k, opts, v) {
      return '<span class="sn-seg">' + opts.map(o => `<button type="button" data-sn-seg="${k}" data-i="${i}" data-v="${o[0]}" class="${o[0] === v ? 'on' : ''}">${o[1]}</button>`).join('') + '</span>';
    }
    function render() {
      const used = rows.filter(r => r.type === 'page').map(r => r.page);
      const free = Object.keys(pages).filter(id => used.indexOf(id) < 0);
      host.innerHTML = rows.map((r, i) => {
        const p = r.type === 'page' ? pages[r.page] : null;
        const tgt = r.type === 'page'
          ? (p ? `<span class="sn-chip">Page</span>${esc(p.t)} · <code>${esc(p.path)}</code>${p.pub ? '' : '<span class="sn-warn">hidden — unpublished</span>'}`
               : '<span class="sn-warn">page deleted — remove this item</span>')
          : `<span class="sn-chip">Link</span><input class="sn-in" style="width:calc(100% - 46px)" data-sn-k="url" data-i="${i}" value="${esc(r.url)}" placeholder="/path or https://…">`;
        return `<div class="sn-row${r.type === 'page' && r.page === hl ? ' sn-new' : ''}" draggable="true" data-i="${i}">
          <div class="sn-r1"><span class="sn-grip" title="Drag to reorder">⋮⋮</span>
            <input class="sn-in" data-sn-k="label" data-i="${i}" value="${esc(r.label)}" placeholder="${esc(p ? p.t : 'Label')}" maxlength="60">
            <button type="button" class="sn-x" data-sn-del="${i}" title="Remove from the menu">×</button></div>
          <div class="sn-r2"><div class="sn-tgt">${tgt}</div>
            ${seg(i, 'style', [['link','Link'],['button','Button'],['outline','Outline']], r.style)}
            ${seg(i, 'side', [['left','L'],['right','R']], r.side)}
            <label class="sn-tab"><input type="checkbox" data-sn-tab="${i}" ${r.tab ? 'checked' : ''}>New tab</label></div>
        </div>`;
      }).join('') +
      (rows.length ? '' : '<div class="pb2-field-hint" style="text-align:left;padding:6px 0">The menu is empty — the header shows just the logo.</div>') +
      `<div class="sn-add"><button type="button" class="pb2-btn" data-sn-pop="1">+ Page</button>
        <button type="button" class="pb2-btn" data-sn-addlink="1">+ Link or button</button>
        ${pop ? '<div class="sn-pop">' +
          free.map(id => `<button type="button" data-sn-addpage="${id}"><span>${esc(pages[id].t)}${pages[id].pub ? '' : ' (unpublished)'}</span><small>${esc(pages[id].path)}</small></button>`).join('') +
          apps.map(a => `<button type="button" data-sn-addapp="${esc(a.url)}" data-label="${esc(a.label)}"><span>${esc(a.label)}</span><small>${esc(a.url)}</small></button>`).join('') +
          (free.length || apps.length ? '' : '<div class="pb2-field-hint" style="padding:8px">Every page is already in the menu.</div>') + '</div>' : ''}
      </div>`;
    }
    host.addEventListener('input', e => {
      const k = e.target.dataset.snK; if (!k) return;
      rows[+e.target.dataset.i][k] = e.target.value;
      window.pb2NavRows = rows; window.pb2NavPending = true; window.pb2NavSaver = saveNavLinks;
      if (window.pb2MarkDirty) window.pb2MarkDirty();
    });
    host.addEventListener('change', e => {
      if (e.target.dataset.snTab !== undefined) { rows[+e.target.dataset.snTab].tab = e.target.checked; changed(); }
    });
    host.addEventListener('click', e => {
      const t = e.target.closest('button'); if (!t) return;
      if (t.dataset.snSeg) { rows[+t.dataset.i][t.dataset.snSeg] = t.dataset.v; changed(); return; }
      if (t.dataset.snDel !== undefined) { rows.splice(+t.dataset.snDel, 1); changed(); return; }
      if (t.dataset.snPop) { pop = !pop; render(); return; }
      if (t.dataset.snAddlink) { rows.push({type:'link', page:null, label:'', url:'', style:'link', side:'left', tab:false}); changed(); return; }
      const at = rows.filter(r => r.side === 'left').length;
      if (t.dataset.snAddpage) { rows.splice(at, 0, {type:'page', page:t.dataset.snAddpage, label:'', url:'', style:'link', side:'left', tab:false}); pop = false; changed(); return; }
      if (t.dataset.snAddapp)  { rows.splice(at, 0, {type:'link', page:null, label:t.dataset.label, url:t.dataset.snAddapp, style:'link', side:'left', tab:false}); pop = false; changed(); return; }
    });
    host.addEventListener('dragstart', e => { const r = e.target.closest('.sn-row'); if (r) { from = +r.dataset.i; r.classList.add('drag'); } });
    host.addEventListener('dragover',  e => { const r = e.target.closest('.sn-row'); if (r && from !== null) { e.preventDefault(); host.querySelectorAll('.sn-row.over').forEach(x => x.classList.remove('over')); r.classList.add('over'); } });
    host.addEventListener('drop',      e => { const r = e.target.closest('.sn-row'); if (!r || from === null) return; e.preventDefault(); const m = rows.splice(from, 1)[0]; rows.splice(+r.dataset.i, 0, m); from = null; changed(); });
    host.addEventListener('dragend',   () => { from = null; });

    function saveNavLinks() {
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('op', 'update_nav');
      rows.forEach((r, i) => {
        fd.append(`nav_items[${i}][page_id]`, r.type === 'page' ? (r.page || '') : '');
        fd.append(`nav_items[${i}][label]`, r.label || '');
        fd.append(`nav_items[${i}][url]`, r.url || '');
        fd.append(`nav_items[${i}][style]`, r.style);
        fd.append(`nav_items[${i}][side]`, r.side);
        fd.append(`nav_items[${i}][open_in_new_tab]`, r.tab ? '1' : '0');
      });
      setStatus('Saving the menu…');
      return fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(r => r.json().then(j => ({ ok: r.ok, j })))
        .then(({ ok, j }) => {
          if (ok && j.ok) { setStatus(''); return true; }
          pb2SaveOk = false;
          setStatus(esc((j && (j.message || j.error)) || 'The menu was not saved.'));
          return false;
        })
        .catch(() => { pb2SaveOk = false; setStatus('The menu was not saved — check your connection.'); return false; });
    }
    render();
    // finish what the link on another page started:
    // put that page in the list (unsaved), highlight it, explain in a dialog.
    // Deferred, because the builder marks everything clean right after load.
    const intent = window.pb2NavIntent;
    if (intent) {
      window.pb2NavIntent = null;
      const apply = () => {
        let title, msg;
        const p = intent.add ? pages[intent.add] : null;
        if (p) {
          hl = intent.add;
          if (rows.some(r => r.type === 'page' && r.page === intent.add)) {
            title = '\u201c' + p.t + '\u201d is already in your menu';
            msg = 'It\u2019s highlighted on the right. Drag to move it or change its style, then press Save.';
            render();
          } else {
            rows.splice(rows.filter(r => r.side === 'left').length, 0, {type:'page', page:intent.add, label:'', url:'', style:'link', side:'left', tab:false});
            changed();
            title = 'Added \u201c' + p.t + '\u201d to your menu';
            msg = 'Not saved yet. It\u2019s highlighted on the right. Drag to move it or change its style, then press Save. Leave without saving and nothing changes.';
          }
          const el = host.querySelector('.sn-row.sn-new');
          if (el) el.scrollIntoView({ block: 'center' });
        } else {
          title = 'This is your site\u2019s menu';
          msg = 'Every page shows it, desktop and phone. Add, remove or reorder items here \u2014 changes go live when you press Save.';
        }
        if (window.IntakeConfirm && window.IntakeConfirm.alert) window.IntakeConfirm.alert({ title: title, message: msg, okText: 'Got it' });
      };
      if (document.readyState === 'complete') setTimeout(apply, 150);
      else window.addEventListener('load', () => setTimeout(apply, 150));
    }
  }

  // Service category checkbox list — serializes checked IDs into a hidden
  // JSON field that autosave picks up via the [data-field] contract.
  function initServiceCategoryList(body) {
    const catList   = body.querySelector('#pb2-svc-catlist');
    const jsonField = body.querySelector('#pb2-svc-catids-json');
    const countMeta = body.querySelector('#pb2-svc-cat-count');
    if (!catList || !jsonField) return;

    function serialize() {
      const ids = [];
      catList.querySelectorAll('input[type="checkbox"][data-svc-cat-id]').forEach(cb => {
        if (cb.checked) ids.push(cb.dataset.svcCatId);
      });
      jsonField.value = JSON.stringify(ids);
      jsonField.dispatchEvent(new Event('change', { bubbles: true }));
      if (countMeta) countMeta.textContent = (ids.length === 0 ? 'all' : ids.length) + ' selected';
    }

    catList.querySelectorAll('input[type="checkbox"][data-svc-cat-id]').forEach(cb => {
      cb.addEventListener('change', serialize);
    });
  }

  function updateBgModePanes(body, mode) {
    body.querySelectorAll('.pb2-bg-pane').forEach(p => {
      p.style.display = p.dataset.bgMode === mode ? 'block' : 'none';
    });
  }

  // Image upload: opens a file picker, posts to /admin/uploads, injects URL
  // into the hidden input + triggers a section save + reloads the inspector.
  // single place that applies a chosen/uploaded URL to
  // an image field: set value, fire change (autosave + live bridge), reload
  // the inspector so the tile reflects it. Shared by upload AND picker.
  function applyImageToField(fieldName, url) {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;
    const hidden = body.querySelector(`input[data-field="${fieldName}"]`);
    if (!hidden) return;
    hidden.value = url;
    hidden.dispatchEvent(new Event('change', { bubbles: true })); // now unsaved until Save
    if (selectedId) {
      const item = document.querySelector(`.pb2-section-item[data-section-id="${selectedId}"]`);
      if (item) {
        const idx = Array.from(document.querySelectorAll('.pb2-section-item')).indexOf(item) + 1;
        setTimeout(() => pb2ReloadSame(item, idx), 350);
      }
    }
  }

  // library picker modal. Lazy-loads media.feed once.
  let __mediaCache = null;
  function openMediaPicker(fieldName) {
    let modal = document.getElementById('pb2-media-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'pb2-media-modal';
      modal.innerHTML =
        '<div class="pb2-media-backdrop"></div>' +
        '<div class="pb2-media-dialog">' +
          '<div class="pb2-media-head"><span>Choose from library</span>' +
          '<button type="button" class="pb2-media-close">&times;</button></div>' +
          '<div class="pb2-media-body" id="pb2-media-body">Loading…</div>' +
        '</div>';
      document.body.appendChild(modal);
      modal.querySelector('.pb2-media-backdrop').addEventListener('click', closeMediaPicker);
      modal.querySelector('.pb2-media-close').addEventListener('click', closeMediaPicker);
    }
    modal.dataset.field = fieldName;
    modal.style.display = 'block';
    renderMediaGrid();
    if (__mediaCache === null) {
      fetch('{{ $mediaFeedUrl }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => { __mediaCache = (d && d.media) || []; renderMediaGrid(); })
        .catch(() => { __mediaCache = []; renderMediaGrid(); });
    }
  }
  window.pb2OpenMediaPicker = openMediaPicker; // the Search & sharing panel lives outside this scope
  function closeMediaPicker() {
    const m = document.getElementById('pb2-media-modal');
    if (m) m.style.display = 'none';
  }
  function renderMediaGrid() {
    const body = document.getElementById('pb2-media-body');
    const modal = document.getElementById('pb2-media-modal');
    if (!body || !modal) return;
    if (__mediaCache === null) { body.textContent = 'Loading…'; return; }
    if (!__mediaCache.length) {
      body.innerHTML = '<div class="pb2-media-empty">No images in your library yet. Upload one on the Media page, or use the upload button.</div>';
      return;
    }
    const field = modal.dataset.field;
    body.innerHTML = '<div class="pb2-media-grid">' + __mediaCache.map(m =>
      '<button type="button" class="pb2-media-cell" data-url="' + m.url + '" title="' + (m.original_name || '') + '">' +
        '<img src="' + m.url + '" loading="lazy" alt="">' +
      '</button>').join('') + '</div>';
    body.querySelectorAll('.pb2-media-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        // append mode hands the pick to the image
        // repeater and keeps the modal open so several can be added at once.
        if (field === '__tiacc_pick' && window.__tiAccPick) {
          window.__tiAccPick(cell.dataset.url);
          window.__tiAccPick = null;
          closeMediaPicker();
          return;
        }
        if (field === '__logo_pick' && window.__logoPick) {
          window.__logoPick(cell.dataset.url);
          window.__logoPick = null;
          closeMediaPicker();
          return;
        }
        if (field === '__share_pick' && window.__sharePick) {
          window.__sharePick(cell.dataset.url);
          window.__sharePick = null;
          closeMediaPicker();
          return;
        }
        if (field === '__gimg_append' && window.__gimgAppend) {
          window.__gimgAppend(cell.dataset.url);
          setStatus('Added \u2713', 1200);
          return;
        }
        applyImageToField(field, cell.dataset.url);
        closeMediaPicker();
      });
    });
  }

  function triggerImageUpload(fieldName) {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;
    const hidden = body.querySelector(`input[data-field="${fieldName}"]`);
    if (!hidden) return;

    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml';
    input.style.display = 'none';
    document.body.appendChild(input);
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      input.remove();
      if (!file) return;
      setStatus('Uploading…');
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('file', file);
      fd.append('type', 'hero');
      try {
        const resp = await fetch(UPLOAD_URL, {
          method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const data = await resp.json();
        if (data && data.ok && data.url) {
          hidden.value = data.url;
          hidden.dispatchEvent(new Event('change', { bubbles: true }));
          setStatus('Uploaded ✓', 1500);
          // Reload inspector so the image tile shows the new file
          if (selectedId) {
            const item = document.querySelector(`.pb2-section-item[data-section-id="${selectedId}"]`);
            if (item) {
              const idx = Array.from(document.querySelectorAll('.pb2-section-item')).indexOf(item) + 1;
              setTimeout(() => pb2ReloadSame(item, idx), 400);
            }
          }
        } else {
          setStatus('Upload failed', 3000);
          IntakeConfirm.alert({ title: 'Upload failed', message: data?.message || 'Please try again.' });
        }
      } catch (e) {
        setStatus('Upload failed', 3000);
        console.error(e);
        IntakeConfirm.alert({ title: 'Upload failed', message: 'Please try again.' });
      }
    });
    input.click();
  }

  // Button list editor — generalized for all section types that have a
  // buttons[] list. Each list element has class .pb2-btnlist and is
  // paired (within the same .pb2-group) with a hidden input[data-field="buttons"],
  // an "+ Add button" button matched by a wrapping .pb2-group, and an
  // optional count badge in the group title (matched by .pb2-group-meta).
  // was hardcoded to #pb2-hero-* IDs; now class-based.
  function initButtonList(body) {
    body.querySelectorAll('.pb2-btnlist').forEach(list => {
      // Find the group containing this list
      const group = list.closest('.pb2-group');
      if (!group) return;
      const json   = group.querySelector('input[type="hidden"][data-field="buttons"]');
      const addBtn = group.querySelector('.pb2-addrow');
      const count  = group.querySelector('.pb2-group-meta');
      if (!json) return;

      // Max-buttons read from the current meta text "N / X" (best effort).
      // Defaults to 4. text_image and cta_banner use 3.
      let maxBtns = 4;
      if (count) {
        const m = count.textContent.match(/\/\s*(\d+)/);
        if (m) maxBtns = parseInt(m[1], 10);
      }

      function serialize() {
        const out = [];
        list.querySelectorAll('.pb2-btnlist-item').forEach(row => {
          out.push({
            label: row.querySelector('[data-btn-field="label"]')?.value || '',
            url:   row.querySelector('[data-btn-field="url"]')?.value || '',
            style: row.querySelector('[data-btn-field="style"]')?.value || 'primary',
          });
        });
        json.value = JSON.stringify(out);
        json.dispatchEvent(new Event('change', { bubbles: true }));
        if (count) count.textContent = `${out.length} / ${maxBtns}`;
      }

      function wireRow(row) {
        row.querySelectorAll('[data-btn-field]').forEach(input => {
          input.addEventListener('input', serialize);
          input.addEventListener('change', serialize);
        });
        const remove = row.querySelector('.pb2-btnlist-remove');
        if (remove) {
          remove.addEventListener('click', () => {
            row.remove();
            serialize();
          });
        }
      }

      list.querySelectorAll('.pb2-btnlist-item').forEach(wireRow);

      if (addBtn) {
        addBtn.addEventListener('click', () => {
          if (list.querySelectorAll('.pb2-btnlist-item').length >= maxBtns) return;
          const row = document.createElement('div');
          row.className = 'pb2-btnlist-item';
          row.innerHTML = `
            <span class="pb2-btnlist-handle">⋮⋮</span>
            <div class="pb2-btnlist-fields">
              <input type="text" class="pb2-input pb2-input-sm" data-btn-field="label" placeholder="Button label">
              <input type="text" class="pb2-input pb2-input-sm" data-btn-field="url" placeholder="/path or https://…">
              <select class="pb2-input pb2-input-sm" data-btn-field="style">
                <option value="primary">Primary</option>
                <option value="outline">Outline</option>
                <option value="ghost">Ghost</option>
                <option value="link">Link</option>
              </select>
            </div>
            <button type="button" class="pb2-btnlist-remove" title="Remove">×</button>
          `;
          list.appendChild(row);
          wireRow(row);
          serialize();
        });
      }
    });
  }

  // Initial wire-up — the first section's fields are already rendered
  initInspectorControls();

  // collapsible docked sections list (state persisted)
  window.toggleSectionsDock = function() {
    const el = document.getElementById('pb2-sections-pane');
    if (!el) return;
    const collapsed = el.classList.toggle('collapsed');
    try { localStorage.setItem('pb2_sections_collapsed', collapsed ? '1' : '0'); } catch (e) {}
  };
  try {
    if (localStorage.getItem('pb2_sections_collapsed') === '1') {
      const el = document.getElementById('pb2-sections-pane');
      if (el) el.classList.add('collapsed');
    }
  } catch (e) {}

  // ─── Add section panel ────────────────────────────────────────────────
  window.toggleAddPanel = function() {
    const panel = document.getElementById('pb2-add-panel');
    if (panel) panel.classList.toggle('open');
  };

  // paste the clipboard section onto this page.
  window.pasteSection = function() {
    const fd = new FormData();
    fd.append('_token', getCsrf());
    fd.append('section_op', 'paste');
    fd.append('page_id', PAGE_ID);
    fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(r => r.json().catch(() => null))
      .then(resp => {
        if (resp && resp.success) { location.reload(); return; }
        IntakeConfirm.alert({ title: 'Nothing to paste', message: (resp && resp.error) || 'The copy has expired.' });
      })
      .catch(err => console.error('paste failed', err));
  };

  window.addSection = function(type) {
    const fd = new FormData();
    fd.append('_token', getCsrf());
    fd.append('section_op', 'add');
    fd.append('page_id', PAGE_ID);
    fd.append('type', type);
    fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json().catch(() => null))
      .then(() => { location.reload(); })
      .catch(err => console.error('add section failed', err));
  };

  // ─── Save (manual button in topbar) ───────────────────────────────────
  window.savePageSettings = function() { return pb2SaveNow(); };
  window.addEventListener('load', function () { setTimeout(pb2SetClean, 50); pb2DraftClear(); }); // no leftovers

  // ─── Listen for save events from inside inspector (future hook) ───────
  document.addEventListener('pb-section-saved', () => {
    refreshPreview();
    setStatus('Saved ✓', 1500);
  });
})();
</script>

<script>
  // lime bar on sections that share a row, recomputed as widths change.
  (function () {
    var W = { half: 3, third: 2, twothirds: 4 };
    function brackets() {
      var items = Array.prototype.slice.call(document.querySelectorAll('#pb2-canvas .pb2-section-item'));
      var row = [], sum = 0;
      function flush() { row.forEach(function (it) { it.classList.add('pb2-in-row'); }); row = []; sum = 0; }
      items.forEach(function (it) { it.classList.remove('pb2-in-row'); });
      items.forEach(function (it) {
        if (it.classList.contains('hidden')) return;
        var w = it.getAttribute('data-w') || 'full';
        if (!W[w]) { flush(); return; }
        if (sum + W[w] > 6) flush();
        row.push(it); sum += W[w];
      });
      flush();
    }
    document.addEventListener('change', function (e) {
      if (!e.target || e.target.getAttribute('data-field') !== 'col_width') return;
      var sel = document.querySelector('#pb2-canvas .pb2-section-item.selected');
      if (sel) sel.setAttribute('data-w', e.target.value);
      brackets();
    });
    document.addEventListener('DOMContentLoaded', brackets);
    if (document.readyState !== 'loading') brackets();
    window.pb2RowBrackets = brackets;
  })();
</script>
@endpush

