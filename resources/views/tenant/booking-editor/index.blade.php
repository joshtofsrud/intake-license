@extends('layouts.tenant.app')
@php $pageTitle = 'Intake Form Editor'; @endphp

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
      <select class="bke-input" id="bke-booking_theme" data-bke="booking_theme">
        <option value="light" {{ $booking['booking_theme'] === 'light' ? 'selected' : '' }}>Light</option>
        <option value="dark" {{ $booking['booking_theme'] === 'dark' ? 'selected' : '' }}>Dark</option>
      </select>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Accent color</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_accent-swatch"
          value="{{ $booking['booking_accent'] ?: ($currentTenant->accent_color ?? '#BEF264') }}"
          onchange="document.getElementById('bke-booking_accent').value=this.value;autoSave()">
        <input type="text" class="bke-input" id="bke-booking_accent" data-bke="booking_accent"
          value="{{ $booking['booking_accent'] }}" placeholder="Uses site accent">
      </div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Background tint color</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_bg_tint-swatch"
          value="{{ $booking['booking_bg_tint'] ?: '#FFFFFF' }}"
          onchange="document.getElementById('bke-booking_bg_tint').value=this.value;autoSave()">
        <input type="text" class="bke-input" id="bke-booking_bg_tint" data-bke="booking_bg_tint"
          value="{{ $booking['booking_bg_tint'] }}">
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
          onchange="document.getElementById('bke-booking_progress_bg').value=this.value;autoSave()">
        <input type="text" class="bke-input" id="bke-booking_progress_bg" data-bke="booking_progress_bg"
          value="{{ $booking['booking_progress_bg'] }}">
      </div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Progress bar text</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_progress_text-swatch"
          value="{{ $booking['booking_progress_text'] ?: '#000000' }}"
          onchange="document.getElementById('bke-booking_progress_text').value=this.value;autoSave()">
        <input type="text" class="bke-input" id="bke-booking_progress_text" data-bke="booking_progress_text"
          value="{{ $booking['booking_progress_text'] }}">
      </div>
    </div>

    <div class="bke-field">
      <div class="bke-field-label">Body text color</div>
      <div class="bke-color-row">
        <input type="color" class="bke-color-swatch" id="bke-booking_body_text-swatch"
          value="{{ $booking['booking_body_text'] ?: '#292929' }}"
          onchange="document.getElementById('bke-booking_body_text').value=this.value;autoSave()">
        <input type="text" class="bke-input" id="bke-booking_body_text" data-bke="booking_body_text"
          value="{{ $booking['booking_body_text'] }}" placeholder="Uses site text color">
      </div>
      <div style="font-size:11px;opacity:.3;margin-top:4px">Main body text. Leave at default to use the theme color.</div>
    </div>
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
      @php
        $stepNames = [1 => 'Services', 2 => 'Schedule', 3 => 'Details', 4 => 'Review'];
      @endphp
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
      @php
        $stepNames = [1 => 'Services', 2 => 'Schedule', 3 => 'Details', 4 => 'Review'];
      @endphp
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

// Auto-save on any input change
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
    fd.append(el.getAttribute('data-bke'), el.value);
  });

  fetch(storeUrl, {
    method: 'POST', body: fd,
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(r) { return r.json(); })
  .then(function(resp) {
    if (resp.ok) {
      showBkeStatus('Saved ✓');
      refreshPreview();
    } else {
      showBkeStatus('Error saving');
    }
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
