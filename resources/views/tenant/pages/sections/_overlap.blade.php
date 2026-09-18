{{-- MARKER-SECTION-OVERLAP — shared by every section type.

     Rendered after the per-type editor from both inspector paths, so a new
     section type gets this control without anyone remembering to add it. --}}
@php
  $overlapValue = (int) ($section->content['overlap_top'] ?? 0);
@endphp

<div class="pb2-field" style="margin-top:18px;padding-top:16px;border-top:1px solid var(--pb2-border, rgba(127,127,127,.22))">
  <label class="pb2-field-label">Pull up over the section above</label>

  <div style="display:flex;align-items:center;gap:12px">
    <input type="range"
           min="0" max="240" step="4"
           value="{{ $overlapValue }}"
           style="flex:1"
           oninput="
             this.nextElementSibling.textContent = this.value + 'px';
             var h = this.parentElement.parentElement.querySelector('[data-field=overlap_top]');
             h.value = this.value;
             h.dispatchEvent(new Event('change', { bubbles: true }));
           ">
    <span style="min-width:52px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $overlapValue }}px</span>
  </div>

  <input type="hidden" data-field="overlap_top" value="{{ $overlapValue }}">

  <div class="pb2-field-hint" style="margin-top:6px">
    Lifts this section over the one before it, so its background sits on top —
    cards riding up over a hero, for instance. <b>Ignored on phones</b>, where an
    overlap would cover the heading; the sections just stack.
  </div>
</div>
