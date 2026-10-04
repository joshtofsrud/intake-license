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
