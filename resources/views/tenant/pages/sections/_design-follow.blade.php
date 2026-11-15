{{-- MARKER-WEB-TEMPLATES: whether this section takes its layout from the shop's template.
     Shown on the menu bar, hero and footer editors when the shop is on a template. --}}
@php
  $dfLook = \App\Support\SiteLook::for(tenant());
  $dfType = $section->section_type;
  $dfC    = $c ?? ($section->content ?? []);
@endphp
@if($dfLook && isset(\App\Support\SiteLook::DESIGN_KEYS[$dfType]))
  @php
    $dfFollows = \App\Support\SiteLook::follows($dfType, $dfC);
    $dfPiece   = $dfLook['pieces'][$dfType] ?? null;
    $dfName    = \App\Support\SiteLook::PIECES[$dfType][2][$dfPiece] ?? $dfPiece;
  @endphp
  <div class="pb2-group" style="border:1px solid var(--ia-border, rgba(255,255,255,.12));border-radius:10px;padding:12px 14px;margin-bottom:14px">
    <div class="pb2-field" style="margin:0">
      <label class="pb2-field-label">Design <span class="pb2-field-hint">{{ $dfLook['name'] }} template</span></label>
      <select class="pb2-input" data-field="design">
        <option value="template" @selected($dfFollows)>Follow the template ({{ $dfName }})</option>
        <option value="own" @selected(! $dfFollows)>This section's own settings</option>
      </select>
      <div class="pb2-field-hint" style="margin-top:6px;line-height:1.5">
        @if($dfFollows)
          The layout comes from Website › Templates. Changing a layout setting below switches this section to its own settings; your words and photos are never changed by the template.
        @else
          This section keeps its own layout settings. Template changes won't touch it.
        @endif
      </div>
    </div>
  </div>
@endif
