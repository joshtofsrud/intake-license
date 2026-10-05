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
