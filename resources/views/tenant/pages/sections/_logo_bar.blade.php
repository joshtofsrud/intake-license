{{--
  MARKER-PATCH-158-G32 — logo_bar editor (Phase 2)
  Single consolidated logos list (each row: name + optional logo URL +
  optional link URL). Renders as a horizontal strip — static grid OR
  scrolling marquee. Falls back to a text "pill" when no logo URL given.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // Migrate v1 parallel arrays (shop_names + logos) into the unified logos
  // list when no new-style logos are present. v1 stored just strings.
  $rawLogos = $c['logos'] ?? [];
  if (is_string($rawLogos)) { $d = json_decode($rawLogos, true); $rawLogos = is_array($d) ? $d : []; }
  if (!is_array($rawLogos)) $rawLogos = [];

  // Detect v1 shape (array of strings) — convert
  $isV1Shape = !empty($rawLogos) && is_string($rawLogos[0] ?? null);
  if ($isV1Shape || empty($rawLogos)) {
      $shopNames = $c['shop_names'] ?? [];
      if (is_string($shopNames)) { $d = json_decode($shopNames, true); $shopNames = is_array($d) ? $d : []; }
      if (!is_array($shopNames)) $shopNames = [];
      $migrated = [];
      foreach ($shopNames as $name) {
          if (trim((string)$name) !== '') $migrated[] = ['name' => $name, 'logo_url' => '', 'link_url' => ''];
      }
      // Mix v1 logo URLs in if they were strings
      foreach ($rawLogos as $url) {
          if (is_string($url) && trim($url) !== '') $migrated[] = ['name' => '', 'logo_url' => $url, 'link_url' => ''];
      }
      $logos = $migrated;
  } else {
      $logos = $rawLogos;
  }
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
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Trusted by') }}" placeholder="e.g. Trusted by">
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
      Logos
      <span class="pb2-group-meta" id="pb2-logo-count">{{ count($logos) }} / 12</span>
    </div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      Name is required. Logo URL renders the actual image; without one, the name shows as a text pill. Link URL is optional — makes the logo clickable.
    </div>

    <div id="pb2-logo-list">
      @foreach($logos as $i => $lg)
        <div class="pb2-logorow" data-logo-idx="{{ $i }}">
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-logorow-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-logo-field="name" value="{{ $lg['name'] ?? '' }}" placeholder="Name (e.g. Acme Co)">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-logo-field="logo_url" value="{{ $lg['logo_url'] ?? '' }}" placeholder="Logo image URL (optional)">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-logo-field="link_url" value="{{ $lg['link_url'] ?? '' }}" placeholder="Link URL (optional)">
          </div>
          <button type="button" class="pb2-navlist-remove" data-logo-remove title="Remove">×</button>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-logo-add">+ Add logo</button>

    <input type="hidden" data-field="logos" id="pb2-logo-json" value="{{ json_encode($logos) }}">
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Display</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Layout</label>
      <select class="pb2-input" data-field="layout">
        @foreach(['grid'=>'Static grid','marquee'=>'Scrolling marquee'] as $v => $n)
          <option value="{{ $v }}" {{ $get('layout', 'grid') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Logos per row <span class="pb2-field-hint">grid layout only</span></label>
      <select class="pb2-input" data-field="cols">
        @foreach(['auto'=>'Auto-fit','3'=>'3','4'=>'4','5'=>'5','6'=>'6'] as $v => $n)
          <option value="{{ $v }}" {{ (string)$get('cols', 'auto') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Logo size</label>
      <select class="pb2-input" data-field="logo_size">
        @foreach(['small'=>'Small (28px tall)','medium'=>'Medium (40px tall)','large'=>'Large (56px tall)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('logo_size', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Marquee speed <span class="pb2-field-hint">marquee only</span></label>
      <select class="pb2-input" data-field="marquee_speed">
        @foreach(['slow'=>'Slow','normal'=>'Normal','fast'=>'Fast'] as $v => $n)
          <option value="{{ $v }}" {{ $get('marquee_speed', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Alignment & spacing</div>

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
            <option value="{{ $v }}" {{ $get('padding_top', 'compact') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Padding bottom</label>
        <select class="pb2-input" data-field="padding_bottom">
          @foreach(['none'=>'None','compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_bottom', 'compact') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>

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
    <div class="pb2-group-title">Logo treatment</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Logo style</label>
      <select class="pb2-input" data-field="logo_treatment">
        @foreach([
          'color'         => 'Full color',
          'grayscale'     => 'Grayscale always',
          'grayscale_hover'=> 'Grayscale, color on hover',
          'muted'         => 'Faded opacity',
        ] as $v => $n)
          <option value="{{ $v }}" {{ $get('logo_treatment', 'grayscale_hover') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text colors</div>

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
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#666666' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Accent</label>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. partners">
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
