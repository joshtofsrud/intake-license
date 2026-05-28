{{--
  MARKER-PATCH-158-G26 — footer editor (Phase 2)

  Footer has two repeatable lists:
    - Link columns: [{ heading, links: [{label, url}, ...] }]
    - Social links: [{ platform, url }]

  Both serialize to hidden JSON fields. Per-row buttons (label, URL, style)
  still use the .pb2-btnlist framework (Hero buttons style); the more complex
  shapes use bespoke list editors wired in initInspectorControls.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // Link columns array — normalize JSON string if needed
  $linkColumns = $c['link_columns'] ?? [];
  if (is_string($linkColumns)) {
      $d = json_decode($linkColumns, true);
      $linkColumns = is_array($d) ? $d : [];
  }
  if (!is_array($linkColumns)) $linkColumns = [];

  // Social links array
  $socialLinks = $c['social_links'] ?? [];
  if (is_string($socialLinks)) {
      $d = json_decode($socialLinks, true);
      $socialLinks = is_array($d) ? $d : [];
  }
  if (!is_array($socialLinks)) $socialLinks = [];

  $platforms = [
      'instagram' => 'Instagram',
      'facebook'  => 'Facebook',
      'twitter'   => 'X / Twitter',
      'youtube'   => 'YouTube',
      'tiktok'    => 'TikTok',
      'linkedin'  => 'LinkedIn',
      'pinterest' => 'Pinterest',
      'github'    => 'GitHub',
      'website'   => 'Website',
      'email'     => 'Email',
  ];
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Brand</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_logo" value="1" {{ $get('show_logo', true) ? 'checked' : '' }}>
      <span>Show logo</span>
    </label>

    <div class="pb2-field" style="margin-top:10px">
      <label class="pb2-field-label">Tagline override <span class="pb2-field-hint">blank = use tenant tagline</span></label>
      <textarea class="pb2-input pb2-textarea" data-field="tagline_override" rows="2" placeholder="A short line about your business">{{ $get('tagline_override') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Link columns
      <span class="pb2-group-meta" id="pb2-ftr-cols-count">{{ count($linkColumns) }} column{{ count($linkColumns) === 1 ? '' : 's' }}</span>
    </div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      Group related links under a heading. Common patterns: Services, About, Resources.
    </div>

    <div id="pb2-ftr-collist">
      @foreach($linkColumns as $i => $col)
        <div class="pb2-ftr-col" data-col-idx="{{ $i }}">
          <div class="pb2-ftr-col-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <input type="text" class="pb2-input pb2-input-sm" data-col-field="heading" value="{{ $col['heading'] ?? '' }}" placeholder="Column heading">
            <button type="button" class="pb2-navlist-remove" data-col-remove title="Remove column">×</button>
          </div>
          <div class="pb2-ftr-col-links">
            @foreach(($col['links'] ?? []) as $li)
              <div class="pb2-ftr-link">
                <input type="text" class="pb2-input pb2-input-sm" data-link-field="label" value="{{ $li['label'] ?? '' }}" placeholder="Label">
                <input type="text" class="pb2-input pb2-input-sm" data-link-field="url" value="{{ $li['url'] ?? '' }}" placeholder="URL">
                <button type="button" class="pb2-navlist-remove" data-link-remove title="Remove link">×</button>
              </div>
            @endforeach
          </div>
          <button type="button" class="pb2-addrow pb2-ftr-addlink" data-col-addlink>+ Add link</button>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-ftr-addcol">+ Add column</button>

    <input type="hidden" data-field="link_columns" id="pb2-ftr-cols-json" value="{{ json_encode($linkColumns) }}">
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Social links
      <span class="pb2-group-meta" id="pb2-ftr-social-count">{{ count($socialLinks) }} link{{ count($socialLinks) === 1 ? '' : 's' }}</span>
    </div>

    <div id="pb2-ftr-sociallist">
      @foreach($socialLinks as $i => $s)
        <div class="pb2-navlist-item" data-social-idx="{{ $i }}">
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-navlist-fields">
            <select class="pb2-input pb2-input-sm" data-social-field="platform">
              @foreach($platforms as $val => $name)
                <option value="{{ $val }}" {{ ($s['platform'] ?? '') === $val ? 'selected' : '' }}>{{ $name }}</option>
              @endforeach
            </select>
            <input type="text" class="pb2-input pb2-input-sm" data-social-field="url" value="{{ $s['url'] ?? '' }}" placeholder="https://...">
          </div>
          <button type="button" class="pb2-navlist-remove" data-social-remove title="Remove">×</button>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-ftr-addsocial">+ Add social link</button>

    <input type="hidden" data-field="social_links" id="pb2-ftr-social-json" value="{{ json_encode($socialLinks) }}">
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Contact info</div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      Pulled from your tenant settings — toggle which to display in the footer.
    </div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_phone" value="1" {{ $get('show_phone', false) ? 'checked' : '' }}>
      <span>Show phone number</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_email" value="1" {{ $get('show_email', true) ? 'checked' : '' }}>
      <span>Show email</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_address" value="1" {{ $get('show_address', false) ? 'checked' : '' }}>
      <span>Show address</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_hours" value="1" {{ $get('show_hours', false) ? 'checked' : '' }}>
      <span>Show hours</span>
    </label>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Copyright & badges</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Copyright text <span class="pb2-field-hint">use {year} and {name}</span></label>
      <input type="text" class="pb2-input" data-field="copyright_text" value="{{ $get('copyright_text') }}" placeholder="© {year} {name}. All rights reserved.">
    </div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_powered_by" value="1" {{ $get('show_powered_by', true) ? 'checked' : '' }}>
      <span>Show "Powered by Intake" badge</span>
    </label>
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="layout" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Layout</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Top-row arrangement</label>
      <select class="pb2-input" data-field="layout">
        @foreach([
          'columns' => 'Multi-column (brand left + link columns)',
          'centered' => 'Centered (everything stacked + centered)',
          'minimal' => 'Minimal (single row)',
        ] as $v => $n)
          <option value="{{ $v }}" {{ $get('layout', 'columns') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Bottom-row layout</label>
      <select class="pb2-input" data-field="bottom_layout">
        @foreach(['split'=>'Copyright left, badge right','stacked'=>'Stacked vertically','copyright_only'=>'Copyright only'] as $v => $n)
          <option value="{{ $v }}" {{ $get('bottom_layout', 'split') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading alignment</label>
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left'=>'Left','center'=>'Center'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('text_align', 'left') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'left') }}">
    </div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Padding top</label>
        <select class="pb2-input" data-field="padding_top">
          @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_top', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Padding bottom</label>
        <select class="pb2-input" data-field="padding_bottom">
          @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
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
    <div class="pb2-group-title">Background</div>

    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['color'=>'Color','gradient'=>'Gradient'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode', 'color') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode', 'color') }}">
    </div>

    <div class="pb2-bg-pane" data-bg-mode="color">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#0a0a0a') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>

    <div class="pb2-bg-pane" data-bg-mode="gradient">
      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">From</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#0a0a0a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#1a1a1a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>

    <div class="pb2-field" style="margin-top:12px">
      <label class="pb2-field-label">Border top</label>
      <select class="pb2-input" data-field="border_top">
        @foreach(['none'=>'None','hairline'=>'Hairline','divider'=>'Divider line'] as $v => $n)
          <option value="{{ $v }}" {{ $get('border_top', 'none') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text colors</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading <span class="pb2-field-hint">column headings + brand name</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#ffffff' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Link <span class="pb2-field-hint">default link color</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="link_color" value="{{ $get('link_color') ?: '#cccccc' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="link_color_text" value="{{ $get('link_color') }}" placeholder="auto">
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Muted <span class="pb2-field-hint">copyright + tagline</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="muted_color" value="{{ $get('muted_color') ?: '#777777' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="muted_color_text" value="{{ $get('muted_color') }}" placeholder="auto">
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. footer">
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
