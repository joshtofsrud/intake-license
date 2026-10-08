{{-- width and the row it sits in. Same control on every section type. --}}
@php
  $pbRc  = (array) ($section->content ?? []);
  $pbRow = ! in_array($section->section_type, ['nav', 'footer'], true);
  $pbW   = \App\Support\SectionRows::width($section);
@endphp
@if($pbRow)
<div class="pb2-group">
  <div class="pb2-group-title">Width</div>
  <div class="pb2-field">
    <div class="pb2-seg" data-field-seg="col_width">
      @foreach(['full' => 'Full', 'half' => 'Half', 'third' => 'Third', 'twothirds' => '2/3'] as $val => $name)
        <button type="button" class="pb2-seg-btn {{ $pbW === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
      @endforeach
    </div>
    <input type="hidden" data-field="col_width" value="{{ $pbW }}">
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-top:6px">Neighbouring sections that fit share a row on wider screens; one that doesn't fit starts the next row. On phones every row stacks. A lime bar in the section list marks sections that share a row.</div>
  </div>
  <div class="pb2-field-row">
    <div class="pb2-field">
      <label class="pb2-field-label">Row gap</label>
      <div class="pb2-seg" data-field-seg="row_gap">
        @foreach(['s' => 'S', 'm' => 'M', 'l' => 'L'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ ($pbRc['row_gap'] ?? 'm') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="row_gap" value="{{ $pbRc['row_gap'] ?? 'm' }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Row align</label>
      <div class="pb2-seg" data-field-seg="row_align">
        @foreach(['start' => 'Top', 'center' => 'Middle', 'stretch' => 'Equal'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ ($pbRc['row_align'] ?? 'stretch') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="row_align" value="{{ $pbRc['row_align'] ?? 'stretch' }}">
    </div>
  </div>
  <div class="pb2-field-hint" style="display:block;text-align:left">Row gap and align are read from the first section in a row and apply to the whole row. Equal makes every section in the row the same height.</div>
</div>
@endif

{{-- shared by every section type.
     placed inside the Design tab by
     App\Support\InspectorOverlap, as a normal group so it gets the same
     padding as every other control and appears once, not under every tab. --}}
@php
  $overlapValue = (int) ($section->content['overlap_top'] ?? 0);
  $overlapPhone = (int) ($section->content['overlap_top_phone'] ?? 0);
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
  </div>
  <div class="pb2-field">
    <label class="pb2-field-label">Phone overlap <span class="pb2-field-hint">0 = stack on phones</span></label>
    <div style="display:flex;align-items:center;gap:12px">
      <input type="range" min="0" max="240" step="4" value="{{ $overlapPhone }}" style="flex:1;min-width:0"
             oninput="this.nextElementSibling.textContent = this.value + 'px';
                      var h = this.parentElement.parentElement.querySelector('[data-field=overlap_top_phone]');
                      h.value = this.value; h.dispatchEvent(new Event('change', { bubbles: true }));">
      <span style="min-width:52px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $overlapPhone }}px</span>
    </div>
    <input type="hidden" data-field="overlap_top_phone" value="{{ $overlapPhone }}">

    <div class="pb2-field-hint" style="margin-top:6px;display:block;text-align:left">
      Lifts this section over the one before it, so its background sits on top —
      cards riding up over a hero, for instance. <b>Phones use their own amount</b> (Phone overlap), where an
      overlap would cover the heading; the sections just stack.
    </div>
  </div>
</div>

{{-- intake.works only: shop sections don't read these yet. --}}
@if($isMarketing ?? false)
@php
  $blendOp   = (int) ($section->content['bg_opacity'] ?? 100);
  $blendCont = ! empty($section->content['bg_continue']);
@endphp
<div class="pb2-group">
  <div class="pb2-group-title">Background blend</div>
  <div class="pb2-field">
    <label class="pb2-field-label">Opacity <span class="pb2-field-hint">color and gradient</span></label>
    <div style="display:flex;align-items:center;gap:12px">
      <input type="range" min="0" max="100" step="5" value="{{ $blendOp }}" style="flex:1;min-width:0"
             oninput="this.nextElementSibling.textContent = this.value + '%';
                      var h = this.parentElement.parentElement.querySelector('[data-field=bg_opacity]');
                      h.value = this.value; h.dispatchEvent(new Event('change', { bubbles: true }));">
      <span style="min-width:44px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $blendOp }}%</span>
    </div>
    <input type="hidden" data-field="bg_opacity" value="{{ $blendOp }}">
  </div>
  @php
    $fadeEnd = (int) ($section->content['bg_grad_end'] ?? 100);
    $fadeOut = ! empty($section->content['bg_fade_out']);
  @endphp
  <div class="pb2-field">
    <label class="pb2-field-label">Gradient ends at <span class="pb2-field-hint">then holds its end color</span></label>
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
    Tick it on more sections below to extend it further. Above has a plain color: this section's gradient starts from that color.
  </div>
</div>
@endif

{{-- moved beside "Hide on desktop" by the inspector (data-move-after). --}}
@if($isMarketing ?? false)
  @php $hideTab = ! empty($section->content['hide_on_tablet']) && ! in_array((string) $section->content['hide_on_tablet'], ['0', 'false'], true); @endphp
  <label class="pb2-checkbox-row" data-move-after="hide_on_desktop">
    <input type="checkbox" data-field="hide_on_tablet" value="1" {{ $hideTab ? 'checked' : '' }}>
    <span>Hide on tablet <span class="pb2-field-hint">769–1024px</span></span>
  </label>
@endif

{{-- intake.works only. --}}
@if($isMarketing ?? false)
@php
  $apMode  = in_array($section->content['appear'] ?? 'none', ['none', 'fade', 'up'], true) ? ($section->content['appear'] ?? 'none') : 'none';
  $apDelay = max(0, min(1000, (int) ($section->content['appear_delay'] ?? 0)));
  $apDur   = max(200, min(2000, (int) ($section->content['appear_duration'] ?? 700)));
  $divOn   = ! empty($section->content['divider_below']) && ! in_array((string) $section->content['divider_below'], ['0', 'false'], true);
@endphp
<div class="pb2-group">
  <div class="pb2-group-title">Appear on scroll</div>
  <div class="pb2-field">
    <div class="pb2-seg" data-field-seg="appear">
      @foreach(['none' => 'None', 'fade' => 'Fade in', 'up' => 'Fade up'] as $v => $n)
        <button type="button" class="pb2-seg-btn {{ $apMode === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
      @endforeach
    </div>
    <input type="hidden" data-field="appear" value="{{ $apMode }}">
  </div>
  <div class="pb2-field">
    <label class="pb2-field-label">Delay <span class="pb2-field-hint">wait before it starts</span></label>
    <div style="display:flex;align-items:center;gap:12px">
      <input type="range" min="0" max="1000" step="50" value="{{ $apDelay }}" style="flex:1;min-width:0"
             oninput="this.nextElementSibling.textContent = this.value + 'ms';
                      var h = this.parentElement.parentElement.querySelector('[data-field=appear_delay]');
                      h.value = this.value; h.dispatchEvent(new Event('change', { bubbles: true }));">
      <span style="min-width:56px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ $apDelay }}ms</span>
    </div>
    <input type="hidden" data-field="appear_delay" value="{{ $apDelay }}">
  </div>
  <div class="pb2-field">
    <label class="pb2-field-label">Duration <span class="pb2-field-hint">how long the fade takes</span></label>
    <div style="display:flex;align-items:center;gap:12px">
      <input type="range" min="200" max="2000" step="100" value="{{ $apDur }}" style="flex:1;min-width:0"
             oninput="this.nextElementSibling.textContent = (this.value / 1000).toFixed(1) + 's';
                      var h = this.parentElement.parentElement.querySelector('[data-field=appear_duration]');
                      h.value = this.value; h.dispatchEvent(new Event('change', { bubbles: true }));">
      <span style="min-width:56px;text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.75">{{ number_format($apDur / 1000, 1) }}s</span>
    </div>
    <input type="hidden" data-field="appear_duration" value="{{ $apDur }}">
  </div>
  <div class="pb2-field-hint" style="text-align:left;display:block">Plays once, the first time the section scrolls into view. Visitors who prefer reduced motion see it straight away.</div>
</div>
<label class="pb2-checkbox-row" data-move-after="hide_on_tablet">
  <input type="checkbox" data-field="divider_below" value="1" {{ $divOn ? 'checked' : '' }}>
  <span>Divider line below</span>
</label>
@endif
