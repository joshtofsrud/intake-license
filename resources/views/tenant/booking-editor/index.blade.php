@extends('layouts.tenant.app')
@php
  $pageTitle = 'Intake Form Editor';
  $defaults = [
    'light' => [
      'booking_accent' => '',
      'booking_bg_tint' => '#FFFFFF',
      'booking_bg_opacity' => '100',
      'booking_progress_bg' => '#ABA6A6',
      'booking_progress_text' => '#000000',
      'booking_body_text' => '',
    ],
    'dark' => [
      'booking_accent' => '',
      'booking_bg_tint' => '#1a1a1a',
      'booking_bg_opacity' => '100',
      'booking_progress_bg' => '#333333',
      'booking_progress_text' => '#f0f0f0',
      'booking_body_text' => '#f0f0f0',
    ],
  ];
@endphp

@push('styles')
<style>
.bke-editor { display: grid; grid-template-columns: 280px 1fr 280px; gap: 0; height: calc(100vh - 130px); margin: -24px -24px 0; }
.bke-col { overflow-y: auto; border-right: 0.5px solid var(--ia-border); padding: 20px; }
.bke-col:last-child { border-right: none; }
.bke-col-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 600; opacity: .35; margin-bottom: 14px; }

.bke-preview-col { display: flex; flex-direction: column; padding: 0; border-right: 0.5px solid var(--ia-border); background: #f5f5f5; }
.bke-preview-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 0.5px solid var(--ia-border); background: var(--ia-surface); }
.bke-preview-toolbar-left { display: flex; align-items: center; gap: 8px; }
.bke-preview-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 600; opacity: .35; }
.bke-device-btn { background: none; border: none; color: var(--ia-text); opacity: .3; cursor: pointer; padding: 4px 6px; border-radius: 4px; font-size: 16px; }
.bke-device-btn.active { opacity: .8; background: rgba(255,255,255,.06); }
.bke-device-btn:hover { opacity: .6; }
.bke-preview-frame-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 16px; overflow: auto; }
.bke-preview-frame { border: none; background: #fff; border-radius: 8px; box-shadow: 0 2px 20px rgba(0,0,0,.15); transition: width .3s; width: 100%; height: 100%; }
.bke-preview-frame.mobile { width: 375px; }

.bke-field { margin-bottom: 14px; }
.bke-field-label { font-size: 10px; opacity: .4; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; font-weight: 500; }
.bke-input { width: 100%; padding: 6px 10px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: var(--ia-input-bg); color: var(--ia-text); font-size: 13px; }
.bke-input:focus { outline: none; border-color: var(--ia-accent); }
.bke-color-row { display: flex; gap: 8px; align-items: center; }
.bke-color-swatch { width: 32px; height: 32px; border-radius: 6px; border: 0.5px solid var(--ia-border); cursor: pointer; flex-shrink: 0; }
.bke-range-row { display: flex; align-items: center; gap: 10px; }
.bke-range-row input[type="range"] { flex: 1; }
.bke-range-val { font-size: 12px; opacity: .5; min-width: 36px; text-align: right; }
.bke-section-divider { border-top: 0.5px solid var(--ia-border); margin: 18px 0; }
/* MARKER-PATCH-601 — marketing sections manager */
.bx-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 14px; }
.bx-item { border: 1px solid var(--ia-border); border-radius: 10px; background: var(--ia-surface, #fff); overflow: hidden; }
.bx-item-head { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--ia-surface-2, #f7f7f8); cursor: pointer; }
.bx-item-type { font-weight: 600; font-size: 12.5px; text-transform: capitalize; }
.bx-item-pos { font-size: 11px; opacity: .55; }
.bx-item-actions { margin-left: auto; display: flex; gap: 6px; }
.bx-mini { border: 1px solid var(--ia-border); background: transparent; border-radius: 6px; width: 26px; height: 26px; cursor: pointer; font-size: 13px; line-height: 1; color: inherit; }
.bx-mini:hover { background: var(--ia-surface-2, #eee); }
.bx-mini-danger:hover { background: #fdecec; border-color: #f5b5b5; }
.bx-item-body { padding: 12px; display: none; flex-direction: column; gap: 10px; }
.bx-item.open .bx-item-body { display: flex; }
.bx-field { display: flex; flex-direction: column; gap: 4px; }
.bx-field label { font-size: 11px; font-weight: 600; opacity: .6; }
.bx-field input[type=text], .bx-field input[type=url], .bx-field textarea, .bx-field select { width: 100%; padding: 8px 10px; border: 1px solid var(--ia-border); border-radius: 7px; font-size: 13px; background: var(--ia-surface, #fff); color: inherit; }
.bx-field textarea { min-height: 64px; resize: vertical; font-family: inherit; }
.bx-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bx-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.bx-color { display: flex; align-items: center; gap: 6px; }
.bx-color input[type=color] { width: 34px; height: 30px; border: 1px solid var(--ia-border); border-radius: 6px; padding: 0; background: none; cursor: pointer; }
.bx-img-tile { display: flex; align-items: center; gap: 10px; }
.bx-img-thumb { width: 54px; height: 40px; border-radius: 6px; background-size: cover; background-position: center; border: 1px solid var(--ia-border); flex: none; }
.bx-add-row { display: flex; flex-wrap: wrap; gap: 8px; }
.bx-feat { border: 1px dashed var(--ia-border); border-radius: 8px; padding: 10px; display: flex; flex-direction: column; gap: 6px; }
.bx-feat-head { display: flex; align-items: center; justify-content: space-between; font-size: 11px; opacity: .55; }

.bke-status { position: fixed; bottom: 20px; right: 20px; padding: 8px 16px; border-radius: 8px; font-size: 13px; background: #0a0a0a; color: #BEF264; z-index: 9999; opacity: 0; transition: opacity .3s; pointer-events: none; }

/* "Best on desktop" notice (patch #41). Form editor is inherently
   3-column with live preview — touch-edit isn't practical. */
.bke-mobile-notice {
  display: none;
  background: rgba(250,180,106,.08);
  border: 0.5px solid rgba(250,180,106,.25);
  border-radius: var(--ia-r-lg);
  padding: 14px 16px;
  margin: 12px 0 16px;
}
.bke-mobile-notice-title {
  font-size: 13px; font-weight: 600;
  color: #FAB46A;
  margin-bottom: 4px;
  display: flex; align-items: center; gap: 6px;
}
.bke-mobile-notice-body {
  font-size: 12px;
  color: var(--ia-text-muted);
  line-height: 1.5;
}
@media (max-width: 640px) {
  .bke-mobile-notice { display: block; }
}

@media (max-width: 1100px) {
  .bke-editor { grid-template-columns: 260px 1fr; }
  .bke-editor > .bke-col:last-child { display: none; }
}
@media (max-width: 768px) {
  .bke-editor { grid-template-columns: 1fr; height: auto; }
  .bke-preview-col { min-height: 400px; }
}
</style>
@endpush

@section('content')

{{-- Mobile "best on desktop" notice (patch #41). Form editor uses a
     3-column layout with live preview; mobile users get a heads-up
     rather than a half-working interface. --}}
<div class="bke-mobile-notice">
  <div class="bke-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="bke-mobile-notice-body">
    The form editor uses a 3-column layout with live preview. Editing on mobile works, but it's much faster on a larger screen.
  </div>
</div>


<div class="ia-page-head" style="margin-bottom:0">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title" style="font-size:16px">Booking Form Customizer</h1>
    <p class="ia-page-subtitle" style="font-size:12px">Customize how your booking form looks and feels.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ tenant_url('book') }}" target="_blank" class="ia-btn ia-btn--secondary ia-btn--sm">Open in new tab ↗</a>
    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" onclick="saveBookingSettings()">Save changes</button>
  </div>
</div>

<div class="bke-editor">

  {{-- LEFT: Appearance --}}
  <div class="bke-col">
    <div class="bke-col-label">Appearance</div>

    <div class="bke-field">
      <div class="bke-field-label">Theme</div>
      <select class="bke-input" id="bke-booking_theme" data-bke="booking_theme" onchange="onThemeChange()">
        <option value="light" {{ $booking['booking_theme'] === 'light' ? 'selected' : '' }}>Light</option>
        <option value="dark" {{ $booking['booking_theme'] === 'dark' ? 'selected' : '' }}>Dark</option>
      </select>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Accent color</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_accent-swatch"
          value="{{ $booking['booking_accent'] ?: ($currentTenant->accent_color ?? '#BEF264') }}"
          onchange="syncColor('booking_accent',this.value)">
        <input type="text" class="bke-input" id="bke-booking_accent" data-bke="booking_accent"
          value="{{ $booking['booking_accent'] }}" placeholder="Uses site accent"
          onchange="syncSwatch('booking_accent',this.value)">
      </div>
    </div>

    <div class="bke-field">
      {{-- MARKER-PATCH-589 — page structure: what frames the booking page --}}
      <div class="bke-field-label" style="margin-top:16px;letter-spacing:.08em">PAGE</div>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;cursor:pointer;padding:4px 0">
        <input type="checkbox" data-bke="booking_show_nav" value="1" {{ ($booking['booking_show_nav'] ?? ($booking['booking_show_chrome'] ?? '1')) === '1' ? 'checked' : '' }}>
        Site navigation bar
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;cursor:pointer;padding:4px 0">
        <input type="checkbox" data-bke="booking_show_footer" value="1" {{ ($booking['booking_show_footer'] ?? ($booking['booking_show_chrome'] ?? '1')) === '1' ? 'checked' : '' }}>
        Site footer
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;cursor:pointer;padding:4px 0 10px">
        <input type="checkbox" data-bke="booking_show_logo" value="1" {{ ($booking['booking_show_logo'] ?? '1') === '1' ? 'checked' : '' }}>
        Logo header inside the page
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;cursor:pointer;padding:4px 0 10px">
        <input type="checkbox" data-bke="booking_hide_cta" value="1" {{ ($booking['booking_hide_cta'] ?? '0') === '1' ? 'checked' : '' }}>
        Hide CTA band on this page
      </label> {{-- MARKER-PATCH-590 --}}
      <div style="font-size:11px;color:var(--ia-text-muted);margin:-6px 0 12px">With the nav on, most sites turn the in-page logo off. Footer content itself (links, contact form, CTA band) is edited in Pages &rarr; your home page footer.</div>

      {{-- MARKER-PATCH-589 — brand kit reference --}}
      <div class="bke-field-label">Brand kit</div>
      <div style="display:flex;flex-wrap:wrap;gap:7px;padding:2px 0 8px">
        @foreach(($brandKit ?? []) as $bkc)
          <button type="button" title="{{ $bkc['name'] }} — click to copy {{ $bkc['value'] }}"
                  onclick="navigator.clipboard.writeText('{{ $bkc['value'] }}');this.style.outline='2px solid var(--ia-accent)';setTimeout(()=>this.style.outline='',600)"
                  style="width:26px;height:26px;border-radius:7px;border:1px solid rgba(255,255,255,.18);cursor:pointer;background:{{ $bkc['value'] }}"></button>
        @endforeach
      </div>
      <div style="font-size:11px;color:var(--ia-text-muted);margin:-4px 0 12px">Click a swatch to copy its hex, then paste into any color field.</div>

      <div class="bke-field-label">Background tint color</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_bg_tint-swatch"
          value="{{ $booking['booking_bg_tint'] ?: '#FFFFFF' }}"
          onchange="syncColor('booking_bg_tint',this.value)">
        <input type="text" class="bke-input" id="bke-booking_bg_tint" data-bke="booking_bg_tint"
          value="{{ $booking['booking_bg_tint'] }}"
          onchange="syncSwatch('booking_bg_tint',this.value)">
      </div>
      <div style="font-size:11px;opacity:.3;margin-top:4px">Tint over the booking page background.</div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Background tint opacity</div>
      <div class="bke-range-row">
        <input type="range" min="0" max="100" value="{{ $booking['booking_bg_opacity'] }}"
          id="bke-booking_bg_opacity-range"
          oninput="document.getElementById('bke-booking_bg_opacity').value=this.value;document.getElementById('bke-opacity-val').textContent=this.value+'%';autoSave()">
        <span class="bke-range-val" id="bke-opacity-val">{{ $booking['booking_bg_opacity'] }}%</span>
        <input type="hidden" id="bke-booking_bg_opacity" data-bke="booking_bg_opacity" value="{{ $booking['booking_bg_opacity'] }}">
      </div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Progress bar background</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_progress_bg-swatch"
          value="{{ $booking['booking_progress_bg'] ?: '#ABA6A6' }}"
          onchange="syncColor('booking_progress_bg',this.value)">
        <input type="text" class="bke-input" id="bke-booking_progress_bg" data-bke="booking_progress_bg"
          value="{{ $booking['booking_progress_bg'] }}"
          onchange="syncSwatch('booking_progress_bg',this.value)">
      </div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Progress bar text</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_progress_text-swatch"
          value="{{ $booking['booking_progress_text'] ?: '#000000' }}"
          onchange="syncColor('booking_progress_text',this.value)">
        <input type="text" class="bke-input" id="bke-booking_progress_text" data-bke="booking_progress_text"
          value="{{ $booking['booking_progress_text'] }}"
          onchange="syncSwatch('booking_progress_text',this.value)">
      </div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Body text color</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_body_text-swatch"
          value="{{ $booking['booking_body_text'] ?: '#292929' }}"
          onchange="syncColor('booking_body_text',this.value)">
        <input type="text" class="bke-input" id="bke-booking_body_text" data-bke="booking_body_text"
          value="{{ $booking['booking_body_text'] }}" placeholder="Uses site text color"
          onchange="syncSwatch('booking_body_text',this.value)">
      </div>
    </div>

    <div class="bke-section-divider"></div>
    <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center" onclick="resetDefaults()">Reset to defaults</button>
  </div>

  {{-- CENTER: Live Preview --}}
  <div class="bke-preview-col">
    <div class="bke-preview-toolbar">
      <div class="bke-preview-toolbar-left">
        <span class="bke-preview-label">Live Preview</span>
      </div>
      <div>
        <button type="button" class="bke-device-btn active" onclick="setBkeDevice('desktop',this)" title="Desktop">🖥</button>
        <button type="button" class="bke-device-btn" onclick="setBkeDevice('mobile',this)" title="Mobile">📱</button>
      </div>
    </div>
    <div class="bke-preview-frame-wrap">
      <iframe id="bke-preview" class="bke-preview-frame"
        src="{{ tenant_url('book') }}"></iframe>
    </div>
  </div>

  {{-- RIGHT: Step Labels + Headings --}}
  <div class="bke-col">
    <div class="bke-col-label">Step Labels</div>

    @foreach([1,2,3,4] as $step)
      @php $stepNames = [1=>'Services',2=>'Schedule',3=>'Details',4=>'Review']; @endphp
      <div class="bke-field">
        <div class="bke-field-label">{{ $stepNames[$step] }} step</div>
        <input type="text" class="bke-input" id="bke-booking_step{{ $step }}_label"
          data-bke="booking_step{{ $step }}_label"
          value="{{ $booking['booking_step' . $step . '_label'] }}">
      </div>
    @endforeach

    <div class="bke-section-divider"></div>
    <div class="bke-col-label">Section Headings</div>

    @foreach([1,2,3,4] as $step)
      @php $stepNames = [1=>'Services',2=>'Schedule',3=>'Details',4=>'Review']; @endphp
      <div class="bke-field">
        <div class="bke-field-label">{{ $stepNames[$step] }} heading</div>
        <input type="text" class="bke-input" id="bke-booking_step{{ $step }}_heading"
          data-bke="booking_step{{ $step }}_heading"
          value="{{ $booking['booking_step' . $step . '_heading'] }}">
      </div>
      <div class="bke-field">
        <div class="bke-field-label">{{ $stepNames[$step] }} subheading</div>
        <input type="text" class="bke-input" id="bke-booking_step{{ $step }}_sub"
          data-bke="booking_step{{ $step }}_sub"
          value="{{ $booking['booking_step' . $step . '_sub'] }}">
      </div>
    @endforeach
  </div>

  <div class="bke-section-divider"></div>

  {{-- MARKER-PATCH-601 — marketing sections manager --}}
  <div class="bke-block" id="bx-manager">
    <div class="bke-block-head">
      <div>
        <div class="bke-block-title">Marketing sections</div>
        <div class="bke-block-sub">Add promo content above or below the booking form. Same look as your booking page.</div>
      </div>
    </div>

    <div id="bx-list" class="bx-list"></div>

    <div class="bx-add-row">
      <button type="button" class="bke-btn" data-bx-add="hero">+ Hero</button>
      <button type="button" class="bke-btn" data-bx-add="cta">+ CTA</button>
      <button type="button" class="bke-btn" data-bx-add="feature_grid">+ Feature grid</button>
      <button type="button" class="bke-btn" data-bx-add="custom_html">+ Custom HTML</button>
    </div>
  </div>

</div>

<div class="bke-status" id="bke-status"></div>

@endsection

@push('scripts')
<script>
var csrf = window.IntakeAdmin.csrfToken;
var storeUrl = '{{ route("tenant.booking-editor.store") }}';
var previewUrl = '{{ tenant_url("book") }}';
var saveTimer = null;
var refreshTimer = null;

var themeDefaults = {!! json_encode($defaults) !!};

function syncColor(field, value) {
  document.getElementById('bke-' + field).value = value;
  autoSave();
}

function syncSwatch(field, value) {
  var swatch = document.getElementById('bke-' + field + '-swatch');
  if (swatch && /^#[0-9a-fA-F]{6}$/.test(value)) swatch.value = value;
  autoSave();
}

function onThemeChange() {
  var theme = document.getElementById('bke-booking_theme').value;
  var defs = themeDefaults[theme] || themeDefaults['light'];

  var colorFields = ['booking_accent', 'booking_bg_tint', 'booking_progress_bg', 'booking_progress_text', 'booking_body_text'];
  colorFields.forEach(function(field) {
    var input = document.getElementById('bke-' + field);
    var swatch = document.getElementById('bke-' + field + '-swatch');
    var val = defs[field] || '';
    if (input) input.value = val;
    if (swatch && val && /^#[0-9a-fA-F]{6}$/.test(val)) swatch.value = val;
  });

  var opInput = document.getElementById('bke-booking_bg_opacity');
  var opRange = document.getElementById('bke-booking_bg_opacity-range');
  var opVal = document.getElementById('bke-opacity-val');
  var defOp = defs['booking_bg_opacity'] || '100';
  if (opInput) opInput.value = defOp;
  if (opRange) opRange.value = defOp;
  if (opVal) opVal.textContent = defOp + '%';

  autoSave();
}

function resetDefaults() {
  if (!confirm('Reset all booking form settings to defaults?')) return;
  var theme = document.getElementById('bke-booking_theme').value;
  var defs = themeDefaults[theme] || themeDefaults['light'];

  var colorFields = ['booking_accent', 'booking_bg_tint', 'booking_progress_bg', 'booking_progress_text', 'booking_body_text'];
  colorFields.forEach(function(field) {
    var input = document.getElementById('bke-' + field);
    var swatch = document.getElementById('bke-' + field + '-swatch');
    var val = defs[field] || '';
    if (input) input.value = val;
    if (swatch && val && /^#[0-9a-fA-F]{6}$/.test(val)) swatch.value = val;
    else if (swatch) swatch.value = '#000000';
  });

  var opInput = document.getElementById('bke-booking_bg_opacity');
  var opRange = document.getElementById('bke-booking_bg_opacity-range');
  var opVal = document.getElementById('bke-opacity-val');
  if (opInput) opInput.value = '100';
  if (opRange) opRange.value = '100';
  if (opVal) opVal.textContent = '100%';

  var labelDefaults = {
    booking_step1_label: 'Services', booking_step2_label: 'Schedule',
    booking_step3_label: 'Details', booking_step4_label: 'Review',
    booking_step1_heading: 'What do you need serviced?', booking_step2_heading: 'Pick a drop-off date',
    booking_step3_heading: 'Your details', booking_step4_heading: 'Review your order',
    booking_step1_sub: 'Select one or more services.', booking_step2_sub: 'Choose a date and tell us how you\'re dropping off.',
    booking_step3_sub: 'Who you are and anything we need to know.', booking_step4_sub: 'Confirm everything looks good.'
  };
  for (var key in labelDefaults) {
    var el = document.getElementById('bke-' + key);
    if (el) el.value = labelDefaults[key];
  }

  saveBookingSettings();
}

document.querySelectorAll('[data-bke]').forEach(function(el) {
  el.addEventListener('input', function() { autoSave(); });
  el.addEventListener('change', function() { autoSave(); });
});

function autoSave() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(function() { saveBookingSettings(); }, 1000);
}

function saveBookingSettings() {
  clearTimeout(saveTimer);
  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('save_booking', '1');

  document.querySelectorAll('[data-bke]').forEach(function(el) {
    // MARKER-PATCH-589 — checkboxes send their checked state, not value
    fd.append(el.getAttribute('data-bke'),
      el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value);
  });

  // MARKER-PATCH-601 — marketing sections serialize to a single JSON field
  try { fd.append('booking_sections', JSON.stringify(BXSections.serialize())); } catch (e) {}

  fetch(storeUrl, {
    method: 'POST', body: fd,
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(r) { return r.json(); })
  .then(function(resp) {
    if (resp.ok) { showBkeStatus('Saved ✓'); refreshPreview(); }
    else showBkeStatus('Error saving');
  })
  .catch(function() { showBkeStatus('Network error'); });
}

function refreshPreview() {
  clearTimeout(refreshTimer);
  refreshTimer = setTimeout(function() {
    document.getElementById('bke-preview').src = previewUrl + '?t=' + Date.now();
  }, 500);
}

function setBkeDevice(mode, btn) {
  document.querySelectorAll('.bke-device-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
  var frame = document.getElementById('bke-preview');
  if (mode === 'mobile') frame.classList.add('mobile');
  else frame.classList.remove('mobile');
}

function showBkeStatus(msg) {
  var el = document.getElementById('bke-status');
  el.textContent = msg;
  el.style.opacity = 1;
  clearTimeout(el._t);
  el._t = setTimeout(function() { el.style.opacity = 0; }, 2000);
}

// ============================================================
// MARKER-PATCH-601 — Marketing sections manager (BXSections)
// ============================================================
var BXSections = (function () {
  var UPLOAD_URL = @json(url('/admin/uploads'));
  var initial = @json($bookingSections ?? []);
  var state = Array.isArray(initial) ? initial.slice() : [];
  var listEl;

  function uid() { return 'bx_' + Math.random().toString(36).slice(2, 10); }

  var TYPE_LABEL = { hero: 'Hero', cta: 'CTA', feature_grid: 'Feature grid', custom_html: 'Custom HTML' };

  function blank(type) {
    var base = { id: uid(), type: type, position: 'before', bg_color: '', bg_image_url: '', text_color: '', align: 'center', pad_top: 56, pad_bottom: 56 };
    if (type === 'custom_html') { base.html = ''; }
    else if (type === 'feature_grid') { base.headline = ''; base.subtext = ''; base.features = []; }
    else { base.eyebrow = ''; base.headline = ''; base.subtext = ''; base.btn_label = ''; base.btn_url = ''; base.btn2_label = ''; base.btn2_url = ''; }
    return base;
  }

  function esc(v) { return (v == null ? '' : String(v)).replace(/"/g, '&quot;'); }

  function commonFields(s) {
    return ''
      + '<div class="bx-field"><label>Placement</label><select data-bx="position">'
      +   '<option value="before"' + (s.position === 'before' ? ' selected' : '') + '>Above the form</option>'
      +   '<option value="after"'  + (s.position === 'after'  ? ' selected' : '') + '>Below the form</option>'
      + '</select></div>'
      + '<div class="bx-row3">'
      +   '<div class="bx-field"><label>Background</label><div class="bx-color"><input type="color" data-bx="bg_color" value="' + (s.bg_color || '#ffffff') + '"><input type="text" data-bx="bg_color" value="' + esc(s.bg_color) + '" placeholder="#RRGGBB"></div></div>'
      +   '<div class="bx-field"><label>Text color</label><div class="bx-color"><input type="color" data-bx="text_color" value="' + (s.text_color || '#111111') + '"><input type="text" data-bx="text_color" value="' + esc(s.text_color) + '" placeholder="#RRGGBB"></div></div>'
      +   '<div class="bx-field"><label>Align</label><select data-bx="align">'
      +     ['left','center','right'].map(function(a){ return '<option value="'+a+'"'+(s.align===a?' selected':'')+'>'+a+'</option>'; }).join('')
      +   '</select></div>'
      + '</div>'
      + '<div class="bx-field"><label>Background image</label>' + imgTile(s) + '</div>'
      + '<div class="bx-row2">'
      +   '<div class="bx-field"><label>Padding top (px)</label><input type="text" data-bx="pad_top" value="' + esc(s.pad_top) + '"></div>'
      +   '<div class="bx-field"><label>Padding bottom (px)</label><input type="text" data-bx="pad_bottom" value="' + esc(s.pad_bottom) + '"></div>'
      + '</div>';
  }

  function imgTile(s) {
    if (s.bg_image_url) {
      return '<div class="bx-img-tile">'
        + '<div class="bx-img-thumb" style="background-image:url(\'' + esc(s.bg_image_url) + '\')"></div>'
        + '<button type="button" class="bke-btn" data-bx-upload>Replace</button>'
        + '<button type="button" class="bke-btn" data-bx-imgclear>Remove</button>'
        + '<input type="hidden" data-bx="bg_image_url" value="' + esc(s.bg_image_url) + '"></div>';
    }
    return '<div class="bx-img-tile">'
      + '<button type="button" class="bke-btn" data-bx-upload>Upload image</button>'
      + '<input type="hidden" data-bx="bg_image_url" value=""></div>';
  }

  function typeFields(s) {
    if (s.type === 'custom_html') {
      return '<div class="bx-field"><label>HTML</label><textarea data-bx="html" style="min-height:120px;font-family:monospace">' + (s.html || '') + '</textarea></div>';
    }
    if (s.type === 'feature_grid') {
      return '<div class="bx-field"><label>Heading</label><input type="text" data-bx="headline" value="' + esc(s.headline) + '"></div>'
        + '<div class="bx-field"><label>Subtext</label><textarea data-bx="subtext">' + (s.subtext || '') + '</textarea></div>'
        + '<div class="bx-field"><label>Features</label><div data-bx-feats></div>'
        + '<button type="button" class="bke-btn" data-bx-featadd>+ Feature</button></div>';
    }
    // hero | cta
    return '<div class="bx-field"><label>Eyebrow</label><input type="text" data-bx="eyebrow" value="' + esc(s.eyebrow) + '"></div>'
      + '<div class="bx-field"><label>Headline</label><input type="text" data-bx="headline" value="' + esc(s.headline) + '"></div>'
      + '<div class="bx-field"><label>Subtext</label><textarea data-bx="subtext">' + (s.subtext || '') + '</textarea></div>'
      + '<div class="bx-row2">'
      +   '<div class="bx-field"><label>Button label</label><input type="text" data-bx="btn_label" value="' + esc(s.btn_label) + '"></div>'
      +   '<div class="bx-field"><label>Button URL</label><input type="text" data-bx="btn_url" value="' + esc(s.btn_url) + '"></div>'
      + '</div>'
      + '<div class="bx-row2">'
      +   '<div class="bx-field"><label>2nd button label</label><input type="text" data-bx="btn2_label" value="' + esc(s.btn2_label) + '"></div>'
      +   '<div class="bx-field"><label>2nd button URL</label><input type="text" data-bx="btn2_url" value="' + esc(s.btn2_url) + '"></div>'
      + '</div>';
  }

  function featRow(f) {
    f = f || { icon: '', title: '', text: '' };
    return '<div class="bx-feat">'
      + '<div class="bx-feat-head"><span>Feature</span><button type="button" class="bx-mini bx-mini-danger" data-bx-featdel>×</button></div>'
      + '<div class="bx-row3">'
      +   '<div class="bx-field"><label>Icon</label><input type="text" data-bxf="icon" value="' + esc(f.icon) + '" placeholder="emoji"></div>'
      +   '<div class="bx-field" style="grid-column:span 2"><label>Title</label><input type="text" data-bxf="title" value="' + esc(f.title) + '"></div>'
      + '</div>'
      + '<div class="bx-field"><label>Text</label><textarea data-bxf="text">' + (f.text || '') + '</textarea></div>'
      + '</div>';
  }

  function render() {
    listEl.innerHTML = '';
    state.forEach(function (s, idx) {
      var item = document.createElement('div');
      item.className = 'bx-item';
      item.dataset.idx = idx;
      item.innerHTML =
        '<div class="bx-item-head" data-bx-toggle>'
        + '<span class="bx-item-type">' + (TYPE_LABEL[s.type] || s.type) + '</span>'
        + '<span class="bx-item-pos">' + (s.position === 'after' ? 'below form' : 'above form') + '</span>'
        + '<span class="bx-item-actions">'
        +   '<button type="button" class="bx-mini" data-bx-up>↑</button>'
        +   '<button type="button" class="bx-mini" data-bx-down>↓</button>'
        +   '<button type="button" class="bx-mini bx-mini-danger" data-bx-del>×</button>'
        + '</span></div>'
        + '<div class="bx-item-body">' + typeFields(s) + commonFields(s) + '</div>';
      listEl.appendChild(item);
      // populate feature rows
      if (s.type === 'feature_grid') {
        var host = item.querySelector('[data-bx-feats]');
        (s.features || []).forEach(function (f) { host.insertAdjacentHTML('beforeend', featRow(f)); });
      }
      wireItem(item, idx);
    });
  }

  function pull(idx) {
    // read DOM back into state[idx]
    var item = listEl.querySelector('.bx-item[data-idx="' + idx + '"]');
    if (!item) return;
    var s = state[idx];
    item.querySelectorAll('[data-bx]').forEach(function (el) {
      var k = el.getAttribute('data-bx');
      // color has two inputs sharing data-bx; take the last non-empty text one
      s[k] = el.value;
    });
    if (s.type === 'feature_grid') {
      s.features = [];
      item.querySelectorAll('.bx-feat').forEach(function (fr) {
        var f = {};
        fr.querySelectorAll('[data-bxf]').forEach(function (el) { f[el.getAttribute('data-bxf')] = el.value; });
        s.features.push(f);
      });
    }
  }

  function pullAll() { state.forEach(function (_, i) { pull(i); }); }

  function wireItem(item, idx) {
    item.querySelector('[data-bx-toggle]').addEventListener('click', function (e) {
      if (e.target.closest('.bx-item-actions')) return;
      item.classList.toggle('open');
    });
    item.querySelector('[data-bx-del]').addEventListener('click', function () {
      pullAll(); state.splice(idx, 1); render(); triggerSave();
    });
    item.querySelector('[data-bx-up]').addEventListener('click', function () {
      if (idx === 0) return; pullAll();
      var t = state[idx - 1]; state[idx - 1] = state[idx]; state[idx] = t; render(); triggerSave();
    });
    item.querySelector('[data-bx-down]').addEventListener('click', function () {
      if (idx === state.length - 1) return; pullAll();
      var t = state[idx + 1]; state[idx + 1] = state[idx]; state[idx] = t; render(); triggerSave();
    });
    // live save on edits
    item.querySelectorAll('[data-bx],[data-bxf]').forEach(function (el) {
      el.addEventListener('change', function () { pull(idx); triggerSave(); });
      // keep the two color inputs in sync
      if (el.type === 'color') {
        el.addEventListener('input', function () {
          var partner = item.querySelector('input[type=text][data-bx="' + el.getAttribute('data-bx') + '"]');
          if (partner) partner.value = el.value;
        });
      }
    });
    // pos change updates the little label immediately
    var posSel = item.querySelector('[data-bx="position"]');
    if (posSel) posSel.addEventListener('change', function () { pull(idx); render(); });
    // uploader
    var upBtn = item.querySelector('[data-bx-upload]');
    if (upBtn) upBtn.addEventListener('click', function () { uploadFor(item, idx); });
    var clr = item.querySelector('[data-bx-imgclear]');
    if (clr) clr.addEventListener('click', function () { state[idx].bg_image_url = ''; render(); triggerSave(); });
    // feature add/del
    var fAdd = item.querySelector('[data-bx-featadd]');
    if (fAdd) fAdd.addEventListener('click', function () {
      item.querySelector('[data-bx-feats]').insertAdjacentHTML('beforeend', featRow());
      wireFeatDels(item, idx);
      pull(idx); triggerSave();
    });
    wireFeatDels(item, idx);
  }

  function wireFeatDels(item, idx) {
    item.querySelectorAll('[data-bx-featdel]').forEach(function (b) {
      if (b.dataset.wired) return; b.dataset.wired = '1';
      b.addEventListener('click', function () { b.closest('.bx-feat').remove(); pull(idx); triggerSave(); });
    });
  }

  function uploadFor(item, idx) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/gif,image/webp,image/svg+xml';
    input.style.display = 'none';
    document.body.appendChild(input);
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      input.remove();
      if (!file) return;
      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('file', file);
      fd.append('type', 'hero');
      fetch(UPLOAD_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok && d.url) { state[idx].bg_image_url = d.url; render(); triggerSave(); }
          else showBkeStatus('Upload failed');
        })
        .catch(function () { showBkeStatus('Upload failed'); });
    });
    input.click();
  }

  function triggerSave() { if (typeof autoSave === 'function') autoSave(); else if (typeof saveBookingSettings === 'function') saveBookingSettings(); }

  function serialize() { pullAll(); return state; }

  function init() {
    listEl = document.getElementById('bx-list');
    if (!listEl) return;
    render();
    document.querySelectorAll('[data-bx-add]').forEach(function (b) {
      b.addEventListener('click', function () {
        pullAll(); state.push(blank(b.getAttribute('data-bx-add')));
        render();
        // open the newly added one
        var last = listEl.querySelector('.bx-item[data-idx="' + (state.length - 1) + '"]');
        if (last) last.classList.add('open');
        triggerSave();
      });
    });
  }

  return { init: init, serialize: serialize };
})();
document.addEventListener('DOMContentLoaded', BXSections.init);

</script>
@endpush
