{{--
  MARKER-PATCH-158-G33 — faq_accordion editor (Phase 2)
  Q&A list with optional "single open at a time" behavior. Renders using
  native <details>/<summary> for accessibility; small JS adds single-open
  enforcement when that mode is selected.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  $items = $c['items'] ?? [];
  if (is_string($items)) { $d = json_decode($items, true); $items = is_array($d) ? $d : []; }
  if (!is_array($items)) $items = [];
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
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Frequently asked') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase</label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="Optional">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional supporting text">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      FAQ items
      <span class="pb2-group-meta" id="pb2-faq-count">{{ count($items) }} / 20</span>
    </div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      Answers support multi-line text. Mark one item "open by default" if you want it expanded on page load.
    </div>

    <div id="pb2-faq-list">
      @foreach($items as $i => $item)
        <div class="pb2-faqrow" data-faq-idx="{{ $i }}">
          <div class="pb2-faqrow-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <span class="pb2-faqrow-pos">Q{{ $i + 1 }}</span>
            <label class="pb2-faqrow-open" title="Open this item by default">
              <input type="checkbox" data-faq-field="open_default" {{ ($item['open_default'] ?? false) ? 'checked' : '' }}>
              <span>Open</span>
            </label>
            <button type="button" class="pb2-navlist-remove" data-faq-remove title="Remove">×</button>
          </div>
          <div class="pb2-faqrow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-faq-field="question" value="{{ $item['question'] ?? ($item['q'] ?? '') }}" placeholder="Question">
            <textarea class="pb2-input pb2-input-sm pb2-textarea" data-faq-field="answer" rows="3" placeholder="Answer (multi-line supported)">{{ $item['answer'] ?? ($item['a'] ?? '') }}</textarea>
          </div>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-faq-add">+ Add FAQ</button>

    <input type="hidden" data-field="items" id="pb2-faq-json" value="{{ json_encode($items) }}">
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="layout" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Behavior & appearance</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Open behavior</label>
      <select class="pb2-input" data-field="open_mode">
        @foreach(['multiple'=>'Multiple (each independent)','single'=>'Single (only one open at a time)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('open_mode', 'multiple') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Visual style</label>
      <select class="pb2-input" data-field="style">
        @foreach(['bordered'=>'Bordered cards','divider'=>'Divider lines','minimal'=>'Minimal (subtle)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('style', 'divider') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Width</label>
      <select class="pb2-input" data-field="width">
        @foreach(['narrow'=>'Narrow (~640px)','medium'=>'Medium (~800px)','wide'=>'Wide (~960px)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('width', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Icon</label>
      <select class="pb2-input" data-field="icon_style">
        @foreach(['chevron'=>'Chevron (▾)','plus'=>'Plus / minus','arrow'=>'Arrow (→)','none'=>'None'] as $v => $n)
          <option value="{{ $v }}" {{ $get('icon_style', 'chevron') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Heading & spacing</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading alignment</label>
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left'=>'Left','center'=>'Center'] as $val => $name)
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
    <div class="pb2-group-title">Text & accent</div>

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
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">icon + question hover</span></label>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. faq">
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
