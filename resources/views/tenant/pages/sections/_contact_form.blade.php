{{--
  MARKER-PATCH-158-G24 — contact_form editor (Phase 2)
  Editor controls form *presentation*. The /contact backend endpoint
  accepts a fixed set of fields (name, email, phone, message); we can
  show/hide and re-label but not add new fields without backend work.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Get in touch') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase</label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="Optional">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional supporting text">{{ $get('subheading') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Footnote <span class="pb2-field-hint">e.g. response time</span></label>
      <input type="text" class="pb2-input" data-field="note" value="{{ $get('note') }}" placeholder="We respond within 24 hours">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Button & messages</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Submit button label</label>
      <input type="text" class="pb2-input" data-field="submit_label" value="{{ $get('submit_label', 'Send message') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Success message <span class="pb2-field-hint">shown after submit</span></label>
      <input type="text" class="pb2-input" data-field="success_text" value="{{ $get('success_text', 'Thanks! We\'ll be in touch soon.') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Privacy notice <span class="pb2-field-hint">optional, below button</span></label>
      <input type="text" class="pb2-input" data-field="privacy_text" value="{{ $get('privacy_text') }}" placeholder="We never share your info">
    </div>
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="layout" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Width & alignment</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Form width</label>
      <select class="pb2-input" data-field="form_width">
        @foreach(['narrow'=>'Narrow (~440px)','medium'=>'Medium (~580px)','wide'=>'Wide (~720px)','full'=>'Full width'] as $v => $n)
          <option value="{{ $v }}" {{ $get('form_width', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading alignment</label>
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left'=>'Left','center'=>'Center','right'=>'Right'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('text_align', 'center') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'center') }}">
    </div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Padding top</label>
        <select class="pb2-input" data-field="padding_top">
          @foreach(['none'=>'None','compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_top', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Padding bottom</label>
        <select class="pb2-input" data-field="padding_bottom">
          @foreach(['none'=>'None','compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_bottom', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Form fields</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_phone" value="1" {{ $get('show_phone', true) ? 'checked' : '' }}>
      <span>Show phone field</span>
    </label>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_message" value="1" {{ $get('show_message', true) ? 'checked' : '' }}>
      <span>Show message field</span>
    </label>

    <div class="pb2-field" style="margin-top:12px">
      <label class="pb2-field-label">Name field label</label>
      <input type="text" class="pb2-input" data-field="label_name" value="{{ $get('label_name', 'Name') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Email field label</label>
      <input type="text" class="pb2-input" data-field="label_email" value="{{ $get('label_email', 'Email') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Phone field label</label>
      <input type="text" class="pb2-input" data-field="label_phone" value="{{ $get('label_phone', 'Phone') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Message field label</label>
      <input type="text" class="pb2-input" data-field="label_message" value="{{ $get('label_message', 'Message') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Message placeholder</label>
      <input type="text" class="pb2-input" data-field="placeholder_message" value="{{ $get('placeholder_message', 'How can we help you?') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Message rows <span class="pb2-field-hint">textarea height</span></label>
      <input type="number" class="pb2-input" data-field="message_rows" value="{{ (int)$get('message_rows', 5) }}" min="2" max="20" step="1">
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

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
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#ffffff') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>

    <div class="pb2-bg-pane" data-bg-mode="gradient">
      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">From</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#ffffff') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#fafafa') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Input style</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Input appearance</label>
      <select class="pb2-input" data-field="input_style">
        @foreach(['default'=>'Default (bordered)','minimal'=>'Minimal (underline only)','filled'=>'Filled (gray background)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('input_style', 'default') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Input border radius</label>
      <select class="pb2-input" data-field="input_radius">
        @foreach(['none'=>'Square (0)','small'=>'Small (4px)','medium'=>'Medium (8px)','large'=>'Large (12px)','pill'=>'Pill'] as $v => $n)
          <option value="{{ $v }}" {{ $get('input_radius', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Colors</div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Heading</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Body</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#555555' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">highlight + submit button</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>

</div>

{{--=================== ADVANCED ===================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Anchor & classes</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. contact">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_mobile" value="1" {{ $get('hide_on_mobile') ? 'checked' : '' }}>
      <span>Hide on mobile</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_desktop" value="1" {{ $get('hide_on_desktop') ? 'checked' : '' }}>
      <span>Hide on desktop</span>
    </label>
  </div>

</div>
