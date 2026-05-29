@php
  // MARKER-PATCH-158-G15
  $pageTitle = 'Edit: ' . $page->title;
  // Marketing-aware URL helpers (preserved from v1).
  $isMarketing = $isMarketing ?? false;
  $layoutName  = $isMarketing ? 'layouts.admin.page-editor' : 'layouts.tenant.app';
  $backUrl     = $isMarketing
      ? url('/admin/marketing-pages')
      : route('tenant.pages.index');
  $previewUrl  = $isMarketing
      ? 'https://' . config('intake.domain', 'intake.works') . '/' . ($page->is_home ? '' : $page->slug)
      : tenant_url($page->is_home ? '' : $page->slug);
  $storeUrl    = $isMarketing
      ? url('/admin/marketing-pages/store')
      : route('tenant.pages.store');

  // Section type labels + icon classes (Tabler-style line icons via inline SVG below).
  $typeLabels = [
    'nav'                    => 'Navigation',
    'hero'                   => 'Hero',
    'services'               => 'Services grid',
    'text_image'             => 'Text + image',
    'cta_banner'             => 'CTA banner',
    'image_gallery'          => 'Image gallery',
    'contact_form'           => 'Contact form',
    'booking_embed'          => 'Booking form',
    'classes_embed'          => 'Classes schedule',
    'footer'                 => 'Footer',
    'feature_grid'           => 'Feature grid',
    'step_timeline'          => 'Step timeline',
    'pricing_table'          => 'Pricing table',
    'faq_accordion'          => 'FAQ accordion',
    'testimonial_carousel'   => 'Testimonials',
    'logo_bar'               => 'Logo bar',
    'comparison_table'       => 'Comparison table',
    'industry_pack_showcase' => 'Industries',
    'stats_row'              => 'Stats row',
  ];

  // MARKER-PATCH-158-G18 — Inline SVG icon paths per section type. Paths are
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
    'contact_form'   => '<path d="M4 4h16v16H4z"/><polyline points="4 7 12 13 20 7"/>',
    'booking_embed'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/>',
    'classes_embed'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1" fill="currentColor"/>',
    'footer'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="16" x2="21" y2="16"/>',
    'feature_grid'   => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    'step_timeline'  => '<line x1="3" y1="6" x2="3" y2="6.01"/><line x1="3" y1="12" x2="3" y2="12.01"/><line x1="3" y1="18" x2="3" y2="18.01"/><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>',
    'pricing_table'  => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    'faq_accordion'  => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1-1 1.5-2.5 2.5"/><line x1="12" y1="17" x2="12" y2="17.01"/>',
    'testimonial_carousel' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>',
    'logo_bar'       => '<rect x="2" y="9" width="4" height="6" rx="1"/><rect x="10" y="9" width="4" height="6" rx="1"/><rect x="18" y="9" width="4" height="6" rx="1"/>',
    'comparison_table'=>'<rect x="3" y="3" width="18" height="18" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="12" y1="3" x2="12" y2="21"/>',
    'industry_pack_showcase'=>'<path d="M3 6l3-3h12l3 3v3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V6z"/><path d="M5 12v9h14v-9"/>',
    'stats_row'      => '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="17 7 21 7 21 11"/>',
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
    'contact_form'  => 'Inbound contact form',
    'booking_embed' => 'Live booking widget',
    'classes_embed' => 'Class schedule widget',
    'footer'        => 'Site footer with links + copyright',
    'feature_grid'  => 'Icon-led feature cards in a grid',
    'step_timeline' => 'Numbered process steps',
    'faq_accordion' => 'Collapsible Q&A list',
    'pricing_table' => 'Side-by-side pricing tiers',
    'testimonial_carousel' => 'Customer quotes carousel',
    'logo_bar'      => 'Trust bar with partner logos',
    'comparison_table'=>'Feature vs competitor matrix',
    'industry_pack_showcase'=>'Showcase of industries served',
    'stats_row'     => 'Big-number stats row',
  ];

  // Logical grouping for the gallery. Order matters — common ones first.
  $typeGroups = [
    'Layout'     => ['nav','hero','footer'],
    'Content'    => ['text_image','feature_grid','step_timeline','image_gallery','faq_accordion','stats_row'],
    'Conversion' => ['services','cta_banner','booking_embed','contact_form','pricing_table'],
    'Social'     => ['testimonial_carousel','logo_bar'],
  ];
@endphp

@extends($layoutName)

@push('styles')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ============================================================================
   MARKER-PATCH-158-G15 — Page builder v2 chrome
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

/* The editor takes over the full viewport, anchored past the tenant sidebar.
   MARKER-PATCH-158-G17 — replaced the original negative-margin escape with
   position:fixed because the tenant layout's .ia-content uses padding 28px 32px
   (not 24px), so the old -24px escape left bands of leftover padding around
   the editor — visible as the cropped topbar + right pane bleeding off the
   right edge. position:fixed sidesteps the layout padding entirely. */
.pb2-shell {
  position: fixed;
  top: 0;
  left: 220px;          /* tenant sidebar width */
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
  grid-template-columns: 280px 1fr 360px;
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
  grid-template-columns: 280px 1fr 360px;
  flex: 1;
  min-height: 0;
}

/* PANES (left + right) */
.pb2-pane {
  border-right: 0.5px solid var(--pb2-border);
  background: var(--pb2-surface);
  display: flex; flex-direction: column;
  overflow: hidden;
}
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

/* MARKER-PATCH-158-G18 — Add-section gallery */
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

/* MARKER-PATCH-158-G17 — hide v1 _section.blade.php's footer when rendered
   inside the v2 inspector. v1's `.pb-section-actions` had a duplicate
   "Delete section" button and an "Auto-saves as you type" hint that
   conflicted with the v2 inspector header's delete icon + footer status. */
.pb2-insp-body .pb-section-actions { display: none; }

/* ============================================================================
   MARKER-PATCH-158-G19 — Phase 2 per-type editor partials (Hero first)
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

/* MARKER-PATCH-158-G25 — Nav link list (similar to btnlist but with
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

/* MARKER-PATCH-158-G26 — Footer link columns (nested list editor) */
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

/* MARKER-PATCH-158-G27 — Stats row list editor */
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

/* MARKER-PATCH-158-G30 — Pricing table plans list editor */
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

/* MARKER-PATCH-158-G31 — feature_grid features list editor */
.pb2-insp-body .pb2-feat {
  background: var(--pb2-surface-2);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 8px;
}
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

/* MARKER-PATCH-158-G32 — logo_bar logos list editor */
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
.pb2-insp-body .pb2-logorow-fields {
  display: grid;
  grid-template-columns: 1fr 1.4fr 1.2fr;
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
  .pb2-topbar, .pb2-layout { grid-template-columns: 240px 1fr 320px; }
}
@media (max-width: 900px) {
  .pb2-topbar { grid-template-columns: 1fr; height: auto; }
  .pb2-topbar-left, .pb2-topbar-center { display: none; }
  .pb2-layout { grid-template-columns: 1fr; }
  .pb2-pane, .pb2-pane-right { display: none; }
  .pb2-preview-col { display: flex; }
}
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
    </div>

    <div class="pb2-topbar-right">
      <button class="pb2-icon-btn disabled" title="Undo (coming in phase 4)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
      </button>
      <button class="pb2-icon-btn disabled" title="Redo (coming in phase 4)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/></svg>
      </button>
      <div class="pb2-topbar-divider"></div>
      <a href="{{ $previewUrl }}" target="_blank" class="pb2-btn" title="Open live in new tab">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Preview
      </a>
      <button class="pb2-btn pb2-btn-primary" type="button" onclick="savePageSettings()">Save</button>
    </div>
  </div>

  {{-- ============ MAIN LAYOUT ============ --}}
  <div class="pb2-layout">

    {{-- LEFT: section list --}}
    <aside class="pb2-pane">
      <div class="pb2-pane-header">
        <div class="pb2-pane-header-title">Sections</div>
        <div class="pb2-pane-header-meta">{{ $sections->count() }}</div>
      </div>

      <div class="pb2-section-list" id="pb2-canvas">
        @foreach($sections as $idx => $section)
          <div class="pb2-section-item @if($idx === 0) selected @endif @if(!$section->is_visible) hidden @endif"
               data-section-id="{{ $section->id }}"
               data-section-type="{{ $section->section_type }}"
               draggable="false">
            <span class="pb2-drag-handle" title="Drag to reorder">⋮⋮</span>
            <span class="pb2-section-icon">
              {{-- MARKER-PATCH-158-G18 — per-type icon (was generic rect for all) --}}
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                {!! $typeIconPaths[$section->section_type] ?? '<rect x="3" y="3" width="18" height="18" rx="2"/>' !!}
              </svg>
            </span>
            <span class="pb2-section-name">{{ $typeLabels[$section->section_type] ?? $section->section_type }}</span>
            <span class="pb2-section-meta">{{ sprintf('%02d', $idx + 1) }}</span>
          </div>
        @endforeach

        <div class="pb2-section-add" onclick="toggleAddPanel()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add section
        </div>

        <div class="pb2-add-panel" id="pb2-add-panel">
          {{-- MARKER-PATCH-158-G18 — Add-section gallery. Grouped by purpose,
               each option shown as a card with icon + label + one-line desc.
               Marketing context shows different types than tenant context. --}}
          @php
            $allowed = $isMarketing
              ? ['nav','hero','text_image','cta_banner','image_gallery','contact_form','feature_grid','step_timeline','faq_accordion','footer','pricing_table','testimonial_carousel','logo_bar','stats_row','comparison_table','industry_pack_showcase']
              : ['nav','hero','text_image','cta_banner','image_gallery','contact_form','booking_embed','classes_embed','feature_grid','step_timeline','faq_accordion','footer','testimonial_carousel','logo_bar','stats_row','pricing_table'];
          @endphp

          <div class="pb2-gallery">
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
    </aside>

    {{-- CENTER: live preview --}}
    <div class="pb2-preview-col">
      <div class="pb2-preview-bar">
        <div class="pb2-url-bar">
          <div class="pb2-url-dot"></div>
          <span>{{ parse_url($previewUrl, PHP_URL_HOST) }}{{ $page->is_home ? '/' : '/' . $page->slug }}</span>
          <span class="pb2-url-meta">
            @if($page->is_published) Live @else Draft · unpublished @endif
          </span>
        </div>
      </div>

      <div class="pb2-preview-frame-wrap">
        <iframe id="pb2-preview" class="pb2-preview-frame" src="{{ $previewUrl }}"></iframe>
      </div>
    </div>

    {{-- RIGHT: inspector --}}
    <aside class="pb2-pane pb2-pane-right" id="pb2-inspector">
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
            {{-- MARKER-PATCH-158-G18 — duplicate button --}}
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
          <button class="pb2-insp-tab" data-tab="layout">Layout</button>
          <button class="pb2-insp-tab" data-tab="style">Style</button>
          <button class="pb2-insp-tab" data-tab="advanced">Advanced</button>
        </div>

        <div class="pb2-insp-body" id="pb2-insp-body">
          @include('tenant.pages._section', ['section' => $firstSection])
        </div>

        <div class="pb2-insp-footer">
          <span>Changes save automatically</span>
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

@push('scripts')
<script>
// MARKER-PATCH-158-G15 — page builder v2 chrome
// MARKER-PATCH-158-G16 — autosave + live preview reload
(function() {
  const PAGE_ID    = @json($page->id);
  const UPDATE_URL = @json($isMarketing
      ? url('/admin/marketing-pages/' . $page->id)
      : route('tenant.pages.update', $page->id));
  const SECTION_URL = (sid) => @json($isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/sections/')
      : url('/admin/pages/' . $page->id . '/sections/')) + sid;
  const ADD_SECTION_URL = @json($isMarketing
      ? url('/admin/marketing-pages/' . $page->id . '/sections')
      : url('/admin/pages/' . $page->id . '/sections'));
  // MARKER-PATCH-158-G16 — STORE_URL is the endpoint that v1 used for section_op=update.
  // Same handler accepts the same payload here.
  const STORE_URL  = @json($storeUrl);
  const PREVIEW_URL = @json($previewUrl);
  // MARKER-PATCH-158-G19 — upload endpoint  (revised by MARKER-PATCH-158-G19A). Built as a raw URL string
  // instead of route() to avoid RouteNotFoundException if the route cache
  // is stale post-deploy. The endpoint path is stable and tenant-scoped.
  const UPLOAD_URL = @json($isMarketing
      ? url('/admin/uploads')
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

    // MARKER-PATCH-158-G23 — bg_color used to be stripped from content[] and
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
          setStatus('Saved ✓', 1500);
          refreshPreview();
          // Reflect any title/label change in the section list (uses the
          // first visible text input as a best-guess label proxy).
          updateSidebarMetaFromInspector(sectionId);
        } else {
          setStatus('Save failed', 3000);
          console.error('save failed', resp);
        }
      })
      .catch(err => {
        setStatus('Save failed', 3000);
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
  function attachAutosaveListeners(sectionId) {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;
    body.querySelectorAll('input, textarea, select').forEach(input => {
      // Skip our own non-field controls
      if (!input.hasAttribute('data-field') && input.name !== 'is_visible') return;

      input.addEventListener('input', () => {
        clearTimeout(saveTimers[sectionId]);
        saveTimers[sectionId] = setTimeout(() => saveSection(sectionId), 800);
      });
      input.addEventListener('change', () => {
        clearTimeout(saveTimers[sectionId]);
        saveTimers[sectionId] = setTimeout(() => saveSection(sectionId), 100);
      });
    });
  }

  // Wire on initial load (first section's fields are already rendered)
  let selectedId = document.querySelector('.pb2-section-item.selected')?.dataset.sectionId;
  if (selectedId) attachAutosaveListeners(selectedId);

  // ─── Section selection (swap inspector body, re-attach autosave) ──────
  function selectSection(sectionId, type, idx) {
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

    fetch(`${UPDATE_URL}?_inspector=${sectionId}`, {
      headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(r => r.text())
      .then(html => {
        const body = document.getElementById('pb2-insp-body');
        if (body) {
          body.innerHTML = html;
          attachAutosaveListeners(sectionId);
          // MARKER-PATCH-158-G19 — wire up new per-type controls
          initInspectorControls();
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
    });
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
      if (!confirm('Delete this section? This cannot be undone.')) return;
      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('section_op', 'delete');
      fd.append('page_id', PAGE_ID);
      fd.append('section_id', selectedId);
      fetch(STORE_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(() => { location.reload(); })
        .catch(err => { console.error('delete failed', err); alert('Could not delete section.'); });
    });
  }

  // MARKER-PATCH-158-G18 — duplicate section
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

  // MARKER-PATCH-158-G18 — drag-reorder via native HTML5 D&D, gated by the
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
  // MARKER-PATCH-158-G19 — was cosmetic; now actually swaps visible
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

  // MARKER-PATCH-158-G19 — Per-type interactive controls (segmented controls,
  // bg-mode pane toggling, image upload, button list editor). Called after
  // every inspector body swap so newly-injected controls work.
  function initInspectorControls() {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;

    // Restore the active tab after inspector reload so users don't bounce
    // back to "Content" mid-edit. If the new partial has no panels at all
    // (legacy), tabs become cosmetic again.
    const hasPanels = body.querySelector('.pb2-tab-panel');
    if (hasPanels) showTab(activeTab);

    // Segmented controls — clicking a button updates the hidden input it
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
            setTimeout(() => selectSection(selectedId, item.dataset.sectionType, idx), 300);
          }
        }
      });
    });

    // Button list (Hero CTAs)
    initButtonList(body);

    // MARKER-PATCH-158-G22 — Services category multi-select serializer
    initServiceCategoryList(body);

    // MARKER-PATCH-158-G25 — Nav links editor (saves to update_nav op,
    // not into content[]. Nav items are a tenant-global resource.)
    initNavLinkList(body);

    // MARKER-PATCH-158-G26 — Footer link columns + social links list editors.
    // Both serialize to hidden JSON [data-field] inputs that autosave picks up.
    initFooterLinkColumns(body);
    initFooterSocialLinks(body);

    // MARKER-PATCH-158-G27 — Stats row list editor
    initStatsList(body);

    // MARKER-PATCH-158-G30 — Pricing table plans list editor (nested:
    // plan rows + features sub-list per plan + radio-like featured toggle)
    initPlansList(body);

    // MARKER-PATCH-158-G31 — feature_grid features list editor
    initFeaturesList(body);

    // MARKER-PATCH-158-G32 — logo_bar logos list editor
    initLogosList(body);
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
        // Skip totally empty rows
        if (name.trim() === '' && logoUrl.trim() === '') return;
        out.push({ name, logo_url: logoUrl, link_url: linkUrl });
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
    }

    root.querySelectorAll('.pb2-logorow').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-logorow').length >= MAX_LOGOS) return;
        const row = document.createElement('div');
        row.className = 'pb2-logorow';
        row.innerHTML = `
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-logorow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-logo-field="name" placeholder="Name (e.g. Acme Co)">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-logo-field="logo_url" placeholder="Logo image URL (optional)">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-logo-field="link_url" placeholder="Link URL (optional)">
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

  function initFeaturesList(body) {
    const root   = body.querySelector('#pb2-feat-list');
    const addBtn = body.querySelector('#pb2-feat-add');
    const json   = body.querySelector('#pb2-feat-json');
    const count  = body.querySelector('#pb2-feat-count');
    if (!root || !json) return;

    const MAX_FEATS = 12;

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
    }

    root.querySelectorAll('.pb2-feat').forEach(wireFeat);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        if (root.querySelectorAll('.pb2-feat').length >= MAX_FEATS) return;
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
  function initNavLinkList(body) {
    const list   = body.querySelector('#pb2-nav-linklist');
    const addBtn = body.querySelector('#pb2-nav-addlink');
    const count  = body.querySelector('#pb2-nav-links-count');
    const status = body.querySelector('#pb2-nav-status');
    if (!list) return;

    let saveTimer = null;
    function scheduleSave(immediate) {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(saveNavLinks, immediate ? 100 : 800);
    }

    function setStatus(text) {
      if (status) status.innerHTML = `<span class="pb2-field-hint" style="text-align:left">${text}</span>`;
    }

    function saveNavLinks() {
      const items = [];
      list.querySelectorAll('.pb2-navlist-item').forEach(row => {
        const label = row.querySelector('[data-nav-field="label"]')?.value?.trim() || '';
        const url   = row.querySelector('[data-nav-field="url"]')?.value?.trim() || '';
        const newTab = row.querySelector('[data-nav-field="open_in_new_tab"]')?.checked ? '1' : '0';
        if (!label) return; // skip blank rows
        items.push({ label, url, open_in_new_tab: newTab });
      });
      if (count) count.textContent = items.length + ' link' + (items.length === 1 ? '' : 's');

      const fd = new FormData();
      fd.append('_token', getCsrf());
      fd.append('op', 'update_nav');
      items.forEach((it, i) => {
        fd.append(`nav_items[${i}][label]`, it.label);
        fd.append(`nav_items[${i}][url]`, it.url);
        fd.append(`nav_items[${i}][open_in_new_tab]`, it.open_in_new_tab);
      });

      setStatus('Saving links…');
      fetch(STORE_URL, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      })
        .then(r => r.json().catch(() => null))
        .then(() => {
          setStatus('Links saved ✓');
          // Reload preview so changes show
          refreshPreview();
          setTimeout(() => { if (status) status.innerHTML = ''; }, 1500);
        })
        .catch(err => {
          setStatus('Save failed');
          console.error('nav save failed', err);
        });
    }

    function wireRow(row) {
      row.querySelectorAll('[data-nav-field]').forEach(input => {
        input.addEventListener('input', () => scheduleSave(false));
        input.addEventListener('change', () => scheduleSave(true));
      });
      const remove = row.querySelector('.pb2-navlist-remove');
      if (remove) {
        remove.addEventListener('click', () => {
          row.remove();
          scheduleSave(true);
        });
      }
    }

    list.querySelectorAll('.pb2-navlist-item').forEach(wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'pb2-navlist-item';
        row.innerHTML = `
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-navlist-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-nav-field="label" placeholder="Label">
            <input type="text" class="pb2-input pb2-input-sm" data-nav-field="url" placeholder="/page or https://...">
          </div>
          <div class="pb2-navlist-meta">
            <label title="Open in new tab">
              <input type="checkbox" data-nav-field="open_in_new_tab">
              <span>↗</span>
            </label>
            <button type="button" class="pb2-navlist-remove" title="Remove">×</button>
          </div>
        `;
        list.appendChild(row);
        wireRow(row);
        // Focus the new label field
        row.querySelector('[data-nav-field="label"]')?.focus();
      });
    }

    // "Add from existing pages" — fills label + URL from the page
    body.querySelectorAll('.pb2-pagelink').forEach(btn => {
      btn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'pb2-navlist-item';
        const title = btn.dataset.pageTitle || '';
        const url   = btn.dataset.pageUrl || '/';
        row.innerHTML = `
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-navlist-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-nav-field="label" value="${title.replace(/"/g, '&quot;')}">
            <input type="text" class="pb2-input pb2-input-sm" data-nav-field="url" value="${url.replace(/"/g, '&quot;')}">
          </div>
          <div class="pb2-navlist-meta">
            <label title="Open in new tab">
              <input type="checkbox" data-nav-field="open_in_new_tab">
              <span>↗</span>
            </label>
            <button type="button" class="pb2-navlist-remove" title="Remove">×</button>
          </div>
        `;
        list.appendChild(row);
        wireRow(row);
        scheduleSave(true);
      });
    });
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
  function triggerImageUpload(fieldName) {
    const body = document.getElementById('pb2-insp-body');
    if (!body) return;
    const hidden = body.querySelector(`input[data-field="${fieldName}"]`);
    if (!hidden) return;

    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/svg+xml';
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
              setTimeout(() => selectSection(selectedId, item.dataset.sectionType, idx), 400);
            }
          }
        } else {
          setStatus('Upload failed', 3000);
          alert(data?.message || 'Upload failed.');
        }
      } catch (e) {
        setStatus('Upload failed', 3000);
        console.error(e);
        alert('Upload failed.');
      }
    });
    input.click();
  }

  // Button list editor — generalized for all section types that have a
  // buttons[] list. Each list element has class .pb2-btnlist and is
  // paired (within the same .pb2-group) with a hidden input[data-field="buttons"],
  // an "+ Add button" button matched by a wrapping .pb2-group, and an
  // optional count badge in the group title (matched by .pb2-group-meta).
  // MARKER-PATCH-158-G20 — was hardcoded to #pb2-hero-* IDs; now class-based.
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

  // ─── Add section panel ────────────────────────────────────────────────
  window.toggleAddPanel = function() {
    const panel = document.getElementById('pb2-add-panel');
    if (panel) panel.classList.toggle('open');
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
  window.savePageSettings = function() {
    if (selectedId) saveSection(selectedId).then(() => refreshPreview(true));
    else setStatus('Saved', 1000);
  };

  // ─── Listen for save events from inside inspector (future hook) ───────
  document.addEventListener('pb-section-saved', () => {
    refreshPreview();
    setStatus('Saved ✓', 1500);
  });
})();
</script>
@endpush
