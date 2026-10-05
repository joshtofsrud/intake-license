{{--
  MARKER-SCROLL-WORDS — "Scroll words" editor (v2 inspector partial).
  A lead-in that stays put while a list of words changes as visitors scroll.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Words</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Lead-in <span class="pb2-field-hint">stays put</span></label>
      <input type="text" class="pb2-input" data-field="prefix" value="{{ $get('prefix', 'One system for') }}" maxlength="60">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Words <span class="pb2-field-hint">one per line, up to 12</span></label>
      <textarea class="pb2-input" data-field="words" rows="6" placeholder="booking&#10;service&#10;retail">{{ $get('words', "booking\nservice\nretail\nrentals\nmarketing") }}</textarea>
    </div>
    <div class="pb2-field-hint" style="text-align:left;display:block">Visitors scroll through the words one at a time. Screen readers hear the whole sentence; people who prefer reduced motion see every word at once.</div>
  </div>
</div>

{{--=================== DESIGN ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>
  {{-- MARKER-SCROLL-WORDS-BG — the standard Section background controls --}}
  <div class="pb2-group">
    <div class="pb2-group-title">Section background</div>
    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['none'=>'None','color'=>'Color','gradient'=>'Gradient'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode', 'none') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode', 'none') }}">
    </div>
    <div class="pb2-bg-pane" data-bg-mode="color">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#0a0f1a') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>
    <div class="pb2-bg-pane" data-bg-mode="gradient">
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Angle</label>
          <span class="pb2-slider-value pb2-grad-deg">{{ $get('bg_gradient_angle', 135) }}°</span>
        </div>
        <input type="range" min="0" max="360" value="{{ $get('bg_gradient_angle', 135) }}" data-field="bg_gradient_angle" oninput="this.parentNode.querySelector('.pb2-grad-deg').textContent=this.value+'°'">
      </div>
      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">From</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#0a0f1a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#0f1828') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Motion</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Style</label>
      <div class="pb2-seg" data-field-seg="mode">
        @foreach(['fade'=>'Fade in','spotlight'=>'Spotlight','slide'=>'Slide'] as $v => $n)
          <button type="button" class="pb2-seg-btn {{ $get('mode', 'spotlight') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="mode" value="{{ $get('mode', 'spotlight') }}">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Size</label>
        <div class="pb2-seg" data-field-seg="size">
          @foreach(['m'=>'M','l'=>'L','xl'=>'XL'] as $v => $n)
            <button type="button" class="pb2-seg-btn {{ $get('size', 'l') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
          @endforeach
        </div>
        <input type="hidden" data-field="size" value="{{ $get('size', 'l') }}">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Align</label>
        <div class="pb2-seg" data-field-seg="align">
          @foreach(['left'=>'Left','center'=>'Centre'] as $v => $n)
            <button type="button" class="pb2-seg-btn {{ $get('align', 'left') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
          @endforeach
        </div>
        <input type="hidden" data-field="align" value="{{ $get('align', 'left') }}">
      </div>
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Lead-in colour</label>
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color" value="{{ $get('text_color') }}" placeholder="theme default">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Word colour</label>
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color" value="{{ $get('accent_color') }}" placeholder="accent">
      </div>
    </div>
  </div>
</div>

{{--=================== ADVANCED ===================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Anchor &amp; classes</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. what-it-does">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="hide_on_mobile" value="1" {{ $get('hide_on_mobile') ? 'checked' : '' }}><span>Hide on mobile</span></label>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="hide_on_desktop" value="1" {{ $get('hide_on_desktop') ? 'checked' : '' }}><span>Hide on desktop</span></label>
  </div>
</div>
