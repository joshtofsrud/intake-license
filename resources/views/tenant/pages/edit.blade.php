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
  padding: 8px;
  display: none;
}
.pb2-add-panel.open { display: block; }
.pb2-add-panel-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
}
.pb2-add-type-btn {
  background: transparent;
  border: 0;
  color: var(--pb2-text-dim);
  padding: 8px 6px;
  border-radius: 4px;
  cursor: pointer;
  font: inherit; font-size: 11px;
  text-align: left;
  transition: all 0.12s;
}
.pb2-add-type-btn:hover { background: var(--pb2-surface-3); color: var(--pb2-text); }

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
               data-section-type="{{ $section->section_type }}">
            <span class="pb2-drag-handle" title="Drag to reorder">⋮⋮</span>
            <span class="pb2-section-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
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
          <div class="pb2-add-panel-grid">
            @php
              $availableTypes = $isMarketing
                ? ['hero','text_image','cta_banner','image_gallery','contact_form','feature_grid','step_timeline','faq_accordion','footer','nav']
                : ['hero','services','text_image','cta_banner','image_gallery','contact_form','booking_embed','classes_embed','footer','nav'];
            @endphp
            @foreach($availableTypes as $t)
              <button type="button" class="pb2-add-type-btn" onclick="addSection('{{ $t }}')">
                {{ $typeLabels[$t] ?? $t }}
              </button>
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

    // bg_color is persisted to its own column server-side (not under content[]).
    const bgColor = content.bg_color;
    delete content.bg_color;

    const isVisibleEl = body.querySelector('[data-field="is_visible"]');
    const isVisible   = isVisibleEl ? (isVisibleEl.checked ? 1 : 0) : 1;

    const fd = new FormData();
    fd.append('_token', getCsrf());
    fd.append('section_op', 'update');
    fd.append('page_id', PAGE_ID);
    fd.append('section_id', sectionId);
    fd.append('is_visible', isVisible);
    if (bgColor !== undefined) fd.append('bg_color', bgColor);
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

  // ─── Tab switching (cosmetic for Phase 1) ─────────────────────────────
  document.querySelectorAll('.pb2-insp-tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('.pb2-insp-tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
    });
  });

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
