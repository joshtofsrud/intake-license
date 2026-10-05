{{-- MARKER-SECTION-OVERLAP — shared by every section type.
     MARKER-OVERLAP-TAB — placed inside the Design tab by
     App\Support\InspectorOverlap, as a normal group so it gets the same
     padding as every other control and appears once, not under every tab. --}}
@php
  $overlapValue = (int) ($section->content['overlap_top'] ?? 0);
@endphp

<div class="pb2-group">
  <div class="pb2-group-title">Overlap</div>
  <div class="pb2-field">
    <label class="pb2-field-label">Pull up over the section above</label>

    <div style="display:flex;align-items:center;gap:12px">
      <input type="range"
             min="0" max="240" step="4"
             value="{{ $overlapValue }}"
             style="flex:1;min-width:0"
             oninput="
               this.nextElementSibling.textContent = this.value + 'px';
               var h = this.parentElement.parentElement.querySelector('[data-field=overlap_top]');
               h.value = this.value;
               h.dispatchEvent(new Event('change', { bubbles: true }));
             ">
      <span style="min-width:52px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $overlapValue }}px</span>
    </div>

    <input type="hidden" data-field="overlap_top" value="{{ $overlapValue }}">

    <div class="pb2-field-hint" style="margin-top:6px;display:block;text-align:left">
      Lifts this section over the one before it, so its background sits on top —
      cards riding up over a hero, for instance. <b>Ignored on phones</b>, where an
      overlap would cover the heading; the sections just stack.
    </div>
  </div>
</div>

{{-- MARKER-MKT-BG-BLEND — intake.works only: shop sections don't read these yet. --}}
@if($isMarketing ?? false)
@php
  $blendOp   = (int) ($section->content['bg_opacity'] ?? 100);
  $blendCont = ! empty($section->content['bg_continue']);
@endphp
<div class="pb2-group">
  <div class="pb2-group-title">Background blend</div>
  <div class="pb2-field">
    <label class="pb2-field-label">Opacity <span class="pb2-field-hint">colour and gradient</span></label>
    <div style="display:flex;align-items:center;gap:12px">
      <input type="range" min="0" max="100" step="5" value="{{ $blendOp }}" style="flex:1;min-width:0"
             oninput="this.nextElementSibling.textContent = this.value + '%';
                      var h = this.parentElement.parentElement.querySelector('[data-field=bg_opacity]');
                      h.value = this.value; h.dispatchEvent(new Event('change', { bubbles: true }));">
      <span style="min-width:44px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $blendOp }}%</span>
    </div>
    <input type="hidden" data-field="bg_opacity" value="{{ $blendOp }}">
  </div>
  {{-- MARKER-MKT-BG-FADE --}}
  @php
    $fadeEnd = (int) ($section->content['bg_grad_end'] ?? 100);
    $fadeOut = ! empty($section->content['bg_fade_out']);
  @endphp
  <div class="pb2-field">
    <label class="pb2-field-label">Gradient ends at <span class="pb2-field-hint">then holds its end colour</span></label>
    <div style="display:flex;align-items:center;gap:12px">
      <input type="range" min="20" max="100" step="5" value="{{ $fadeEnd }}" style="flex:1;min-width:0"
             oninput="this.nextElementSibling.textContent = this.value + '%';
                      var h = this.parentElement.parentElement.querySelector('[data-field=bg_grad_end]');
                      h.value = this.value; h.dispatchEvent(new Event('change', { bubbles: true }));">
      <span style="min-width:44px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $fadeEnd }}%</span>
    </div>
    <input type="hidden" data-field="bg_grad_end" value="{{ $fadeEnd }}">
  </div>
  <label class="pb2-checkbox-row">
    <input type="checkbox" data-field="bg_fade_out" value="1" {{ $fadeOut ? 'checked' : '' }}>
    <span>Fade out — melt into the page instead of a hard edge</span>
  </label>
  <label class="pb2-checkbox-row">
    <input type="checkbox" data-field="bg_continue" value="1" {{ $blendCont ? 'checked' : '' }}>
    <span>Continue gradient from the section above</span>
  </label>
  <div class="pb2-field-hint" style="text-align:left;display:block;margin-top:4px">
    If the section above has a gradient, it stretches across this section too, at its own angle — one surface, no seam.
    Tick it on more sections below to extend it further. Above has a plain colour: this section's gradient starts from that colour.
  </div>
</div>
@endif
