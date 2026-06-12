{{--
  MARKER-PATCH-158-G19 — Hero section editor (Phase 2)
  Replaces the v1 _section.blade.php hero branch with a 4-tab editor:
    Content   — eyebrow, headline (multi-line), accent phrase, sub, footnote, buttons
    Layout    — height, alignment (h+v), max-width, padding
    Style     — background (color/image/gradient), overlay, text colors, accent
    Advanced  — anchor ID, custom classes, mobile/desktop visibility

  All fields are [data-field="…"] so the existing G16 autosave layer
  picks them up automatically. No new JS contract needed.
--}}
@php
  $c = $c ?? ($section->content ?? []);

  // Helpers — fall back to v2 default if missing
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $eq  = fn($k, $v, $d = null) => ($c[$k] ?? $d) === $v;

  // Buttons array — normalize JSON string if needed (defensive)
  $buttons = $c['buttons'] ?? [];
  if (is_string($buttons)) { $decoded = json_decode($buttons, true); $buttons = is_array($decoded) ? $decoded : []; }
  if (!is_array($buttons)) $buttons = [];

  // Backward compat: if buttons[] is empty AND legacy cta_primary_label is set,
  // synthesize a buttons[] view so the editor shows them as editable rows. The
  // first save with the new editor writes buttons[] proper.
  if (empty($buttons) && !empty($c['cta_primary_label'] ?? '')) {
      $buttons = [
          ['label' => $c['cta_primary_label'], 'url' => $c['cta_primary_url'] ?? '/', 'style' => 'primary'],
      ];
      if (!empty($c['cta_secondary_label'] ?? '')) {
          $buttons[] = ['label' => $c['cta_secondary_label'], 'url' => $c['cta_secondary_url'] ?? '#', 'style' => 'outline'];
      }
  }
@endphp

{{-- Hidden is_visible toggle so the v1-compatible save payload still works.
     The G16 inspector header eye icon toggles this. --}}
<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--==================================================================
    CONTENT TAB
==================================================================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow <span class="pb2-field-hint">small label above the headline</span></label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="e.g. Now booking weekly pickups">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Headline <span class="pb2-field-hint">use a new line to break</span></label>
      <textarea class="pb2-input pb2-textarea" data-field="headline" rows="3" placeholder="Your main headline">{{ $get('headline') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase <span class="pb2-field-hint">rendered in accent color inside headline</span></label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="e.g. We come to you">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="3" placeholder="Supporting text below the headline">{{ $get('subheading') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Footnote <span class="pb2-field-hint">small text below buttons</span></label>
      <input type="text" class="pb2-input" data-field="note" value="{{ $get('note') }}" placeholder="e.g. Free local pickup · 24h turnaround">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Buttons
      <span class="pb2-group-meta" id="pb2-hero-btn-count">{{ count($buttons) }} / 4</span>
    </div>

    <div class="pb2-btnlist" id="pb2-hero-btnlist">
      @foreach($buttons as $i => $b)
        <div class="pb2-btnlist-item" data-btn-idx="{{ $i }}">
          <span class="pb2-btnlist-handle">⋮⋮</span>
          <div class="pb2-btnlist-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-btn-field="label" value="{{ $b['label'] ?? '' }}" placeholder="Button label">
            <input type="text" class="pb2-input pb2-input-sm" data-btn-field="url" value="{{ $b['url'] ?? '' }}" placeholder="/book or https://…">
            <select class="pb2-input pb2-input-sm" data-btn-field="style">
              @foreach(['primary'=>'Primary','outline'=>'Outline','ghost'=>'Ghost','link'=>'Link'] as $val => $name)
                <option value="{{ $val }}" {{ ($b['style'] ?? 'primary') === $val ? 'selected' : '' }}>{{ $name }}</option>
              @endforeach
            </select>
          </div>
          <button type="button" class="pb2-btnlist-remove" data-btn-remove="{{ $i }}" title="Remove">×</button>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-hero-addbtn">
      + Add button
    </button>

    {{-- Hidden field that's actually persisted — the JS rebuilds this JSON
         from the editable rows above on every change so the autosave layer
         picks it up via the standard [data-field] selector. --}}
    <input type="hidden" data-field="buttons" id="pb2-hero-buttons-json" value="{{ json_encode($buttons) }}">

    {{-- Legacy CTA fields. We zero them out when buttons[] is populated so
         the renderer doesn't double-render. The renderer uses buttons[] if
         non-empty, falls back to cta_primary/secondary otherwise. --}}
    <input type="hidden" data-field="cta_primary_label" value="{{ $get('cta_primary_label') }}">
    <input type="hidden" data-field="cta_primary_url" value="{{ $get('cta_primary_url') }}">
    <input type="hidden" data-field="cta_secondary_label" value="{{ $get('cta_secondary_label') }}">
    <input type="hidden" data-field="cta_secondary_url" value="{{ $get('cta_secondary_url') }}">
  </div>

</div>

{{--==================================================================
    LAYOUT TAB
==================================================================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Height & spacing</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Section height</label>
      {{-- MARKER-PATCH-254 — mockup seg; same values, same save contract. --}}
      <div class="pb2-seg" data-field-seg="height">
        @foreach(['small'=>'S','medium'=>'M','large'=>'L','fullscreen'=>'Full'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('height', 'large') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}" title="{{ ['small'=>'~380px','medium'=>'~520px','large'=>'~680px','fullscreen'=>'100vh'][$val] }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="height" value="{{ $get('height', 'large') }}">
    </div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Padding top</label>
        {{-- MARKER-PATCH-254 --}}
        <div class="pb2-seg" data-field-seg="padding_top">
          @foreach(['none'=>'None','compact'=>'Tight','normal'=>'Normal','spacious'=>'Airy'] as $v => $n)
            <button type="button" class="pb2-seg-btn {{ $get('padding_top', 'normal') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
          @endforeach
        </div>
        <input type="hidden" data-field="padding_top" value="{{ $get('padding_top', 'normal') }}">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Padding bottom</label>
        {{-- MARKER-PATCH-254 --}}
        <div class="pb2-seg" data-field-seg="padding_bottom">
          @foreach(['none'=>'None','compact'=>'Tight','normal'=>'Normal','spacious'=>'Airy'] as $v => $n)
            <button type="button" class="pb2-seg-btn {{ $get('padding_bottom', 'normal') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
          @endforeach
        </div>
        <input type="hidden" data-field="padding_bottom" value="{{ $get('padding_bottom', 'normal') }}">
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Alignment</div>

    {{-- MARKER-PATCH-252 — 9-point content anchor: one picker, same two
         fields (text_align x vertical_align), same save contract. --}}
    <div class="pb2-field">
      <label class="pb2-field-label">Content position</label>
      <div style="display:flex;gap:14px;align-items:center">
        @php
          $curH = $get('text_align', 'left');
          $curV = $get('vertical_align', 'center');
        @endphp
        <div class="pb2-anchor" data-anchor-fields="text_align vertical_align">
          @foreach(['top','center','bottom'] as $row)
            @foreach(['left','center','right'] as $col)
              <button type="button" class="pb2-anchor-dot {{ $curH === $col && $curV === $row ? 'on' : '' }}" data-anchor="{{ $col }} {{ $row }}" title="{{ ucfirst($row) }} {{ $col }}"></button>
            @endforeach
          @endforeach
        </div>
        <div class="pb2-field-hint" style="flex:1">Anchor the text block to any of 9 positions. Mobile stacks it safely regardless.</div>
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'left') }}">
      <input type="hidden" data-field="vertical_align" value="{{ $get('vertical_align', 'center') }}">
    </div>

    {{-- MARKER-PATCH-253 — slider like the mockup; range fires input
         continuously so the live bridge moves the hero under your cursor. --}}
    <div class="pb2-field">
      <div class="pb2-slider-row">
        <label class="pb2-field-label" style="margin:0">Content max-width</label>
        <span class="pb2-slider-value" id="pb2-mw-val">{{ $get('content_max_width', 680) }}px</span>
      </div>
      <input type="range" min="320" max="1600" step="20" value="{{ $get('content_max_width', 680) }}" data-field="content_max_width" oninput="document.getElementById('pb2-mw-val').textContent=this.value+'px'">
    </div>
  </div>

  {{-- MARKER-PATCH-158-G21 — Typography sizing overrides --}}
  <div class="pb2-group">
    <div class="pb2-group-title">Typography</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Headline size</label>
      <select class="pb2-input" data-field="headline_size">
        @foreach(['auto'=>'Auto (responsive)','small'=>'Small','medium'=>'Medium','large'=>'Large','xl'=>'Extra large'] as $v => $n)
          <option value="{{ $v }}" {{ $get('headline_size', 'auto') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Subheading size</label>
      <select class="pb2-input" data-field="subheading_size">
        @foreach(['xs'=>'Extra small','small'=>'Small','medium'=>'Medium','large'=>'Large','xl'=>'Extra large'] as $v => $n)
          <option value="{{ $v }}" {{ $get('subheading_size', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

</div>

{{--==================================================================
    STYLE TAB
==================================================================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>

    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['color'=>'Color','image'=>'Image','gradient'=>'Gradient'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode', 'color') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode', 'color') }}">
    </div>

    {{-- Color mode --}}
    <div class="pb2-bg-pane" data-bg-mode="color">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#1a1a1a') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color', '#1a1a1a') }}">
        </div>
      </div>
    </div>

    {{-- Image mode --}}
    <div class="pb2-bg-pane" data-bg-mode="image">
      <div class="pb2-field">
        <label class="pb2-field-label">Background image</label>
        @if(!empty($get('bg_image_url')))
          <div class="pb2-image-tile">
            <div class="pb2-image-tile-thumb" style="background-image: url('{{ $get('bg_image_url') }}'); background-size: cover; background-position: center;"></div>
            <div class="pb2-image-tile-info">
              <div class="pb2-image-tile-name">{{ basename(parse_url($get('bg_image_url'), PHP_URL_PATH) ?? 'image') }}</div>
              <div class="pb2-image-tile-actions">
                <button type="button" class="pb2-textlink" data-image-replace="bg_image_url">Replace</button>
                <button type="button" class="pb2-textlink pb2-textlink-danger" data-image-remove="bg_image_url">Remove</button>
              </div>
            </div>
          </div>
        @else
          <button type="button" class="pb2-image-empty" data-image-upload="bg_image_url">
            <span class="pb2-image-empty-icon">⬆</span>
            <span>Upload an image</span>
            <span class="pb2-field-hint">JPG, PNG, WebP, or SVG · 5 MB max</span>
          </button>
        @endif
        <input type="hidden" data-field="bg_image_url" value="{{ $get('bg_image_url') }}">
      </div>

      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">Position</label>
          <select class="pb2-input" data-field="bg_image_position">
            @foreach(['center'=>'Center','top'=>'Top','bottom'=>'Bottom','left'=>'Left','right'=>'Right'] as $v => $n)
              <option value="{{ $v }}" {{ $get('bg_image_position', 'center') === $v ? 'selected' : '' }}>{{ $n }}</option>
            @endforeach
          </select>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">Size</label>
          <select class="pb2-input" data-field="bg_image_size">
            @foreach(['cover'=>'Cover','contain'=>'Contain'] as $v => $n)
              <option value="{{ $v }}" {{ $get('bg_image_size', 'cover') === $v ? 'selected' : '' }}>{{ $n }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Overlay opacity</label>
          <span class="pb2-slider-value" id="pb2-overlay-val">{{ $get('bg_overlay_opacity', 45) }}%</span>
        </div>
        <input type="range" min="0" max="100" value="{{ $get('bg_overlay_opacity', 45) }}" data-field="bg_overlay_opacity" oninput="document.getElementById('pb2-overlay-val').textContent=this.value+'%'">
      </div>

      <div class="pb2-field">
        <label class="pb2-field-label">Overlay color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_overlay_color" value="{{ $get('bg_overlay_color', '#000000') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_overlay_color_text" value="{{ $get('bg_overlay_color', '#000000') }}">
        </div>
      </div>

      {{-- MARKER-PATCH-249 — motion + blur (apply when background is an image). --}}
      <div class="pb2-field">
        <label class="pb2-checkbox-row">
          <input type="checkbox" data-field="bg_parallax" value="1" {{ $get('bg_parallax', '0') === '1' ? 'checked' : '' }}>
          <span>Parallax scroll</span>
        </label>
        <div class="pb2-field-hint">Background drifts slower than the page. Image backgrounds only; visitors with reduced-motion enabled see it static.</div>
      </div>
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Parallax depth</label>
          <span class="pb2-slider-value" id="pb2-pdepth-val">{{ $get('bg_parallax_depth', 35) }}</span>
        </div>
        <input type="range" min="0" max="70" value="{{ $get('bg_parallax_depth', 35) }}" data-field="bg_parallax_depth" oninput="document.getElementById('pb2-pdepth-val').textContent=this.value">
      </div>
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Background blur</label>
          <span class="pb2-slider-value" id="pb2-blur-val">{{ $get('bg_blur', 0) }}px</span>
        </div>
        <input type="range" min="0" max="14" value="{{ $get('bg_blur', 0) }}" data-field="bg_blur" oninput="document.getElementById('pb2-blur-val').textContent=this.value+'px'">
      </div>
    </div>

    {{-- Gradient mode --}}
    <div class="pb2-bg-pane" data-bg-mode="gradient">
      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">From</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#1a1a1a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from', '#1a1a1a') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#0a0a0a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to', '#0a0a0a') }}">
          </div>
        </div>
      </div>
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Angle</label>
          <span class="pb2-slider-value" id="pb2-grad-val">{{ $get('bg_gradient_angle', 135) }}°</span>
        </div>
        <input type="range" min="0" max="360" value="{{ $get('bg_gradient_angle', 135) }}" data-field="bg_gradient_angle" oninput="document.getElementById('pb2-grad-val').textContent=this.value+'\xB0'">
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text color</div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Headline</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color', '#ffffff') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color', '#ffffff') }}">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Body <span class="pb2-field-hint">blank = headline w/ 70% opacity</span></label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#cccccc' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">used for highlight phrase + primary button</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>

</div>

{{--==================================================================
    ADVANCED TAB
==================================================================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Anchor & classes</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID <span class="pb2-field-hint">links can jump here with #id</span></label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. hero-top">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Custom CSS classes <span class="pb2-field-hint">space-separated</span></label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="my-class another-class">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_mobile" value="1" {{ ($get('hide_on_mobile') ? 'checked' : '') }}>
      <span>Hide on mobile</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_desktop" value="1" {{ ($get('hide_on_desktop') ? 'checked' : '') }}>
      <span>Hide on desktop</span>
    </label>
  </div>

</div>
