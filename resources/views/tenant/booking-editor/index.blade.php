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
</script>
@endpush
