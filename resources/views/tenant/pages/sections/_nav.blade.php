{{--
  MARKER-PATCH-158-G25 — nav editor (Phase 2)
  Nav is unique among section types: it has both per-section content
  (logo toggle, CTA, layout, style) AND a shared tenant resource (nav
  link items in tenant_nav_items table). The link list saves via the
  existing update_nav op; everything else uses the normal autosave.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // navItems passed from the controller; empty collection if not (defensive)
  $navItems = $navItems ?? collect();
  $availablePages = $availablePages ?? collect();
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Logo</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_logo" value="1" {{ $get('show_logo', true) ? 'checked' : '' }}>
      <span>Show logo</span>
    </label>

    {{-- MARKER-PATCH-274 — tenant picks which logo shows; no background guessing --}}
    <div class="pb2-field" style="margin-top:10px">
      <label class="pb2-field-label">Logo <span class="pb2-field-hint">which version to show</span></label>
      <div class="pb2-seg" data-field-seg="logo_variant">
        @foreach(['auto'=>'Auto','light'=>'Light','dark'=>'Dark'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('logo_variant', 'auto') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="logo_variant" value="{{ $get('logo_variant', 'auto') }}">
    </div>

    <div class="pb2-field" style="margin-top:10px">
      <label class="pb2-field-label">Logo position <span class="pb2-field-hint">only with standard layout</span></label>
      <div class="pb2-seg" data-field-seg="logo_alignment">
        @foreach(['left'=>'Left','center'=>'Center'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('logo_alignment', 'left') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="logo_alignment" value="{{ $get('logo_alignment', 'left') }}">
    </div>

    <div class="pb2-field-hint" style="text-align:left;margin-top:10px;display:block">
      Manage your logo image in Settings → Branding.
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Navigation links
      <span class="pb2-group-meta" id="pb2-nav-links-count">{{ $navItems->count() }} link{{ $navItems->count() === 1 ? '' : 's' }}</span>
    </div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      Links appear in every section of every page. Changes save when you click outside the field.
    </div>

    <div class="pb2-navlist" id="pb2-nav-linklist">
      @foreach($navItems as $i => $item)
        <div class="pb2-navlist-item" data-nav-idx="{{ $i }}">
          <span class="pb2-navlist-handle">⋮⋮</span>
          <div class="pb2-navlist-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-nav-field="label" value="{{ $item->label }}" placeholder="Label">
            <input type="text" class="pb2-input pb2-input-sm" data-nav-field="url" value="{{ $item->url }}" placeholder="/page or https://...">
          </div>
          <div class="pb2-navlist-meta">
            <label title="Open in new tab">
              <input type="checkbox" data-nav-field="open_in_new_tab" {{ $item->open_in_new_tab ? 'checked' : '' }}>
              <span>↗</span>
            </label>
            <button type="button" class="pb2-navlist-remove" title="Remove">×</button>
          </div>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-nav-addlink">+ Add link</button>

    @if($availablePages->isNotEmpty())
      <details class="pb2-details" style="margin-top:10px">
        <summary class="pb2-details-summary">Add from existing pages</summary>
        <div class="pb2-details-body">
          @foreach($availablePages as $p)
            <button type="button" class="pb2-pagelink"
              data-page-title="{{ $p->title }}"
              data-page-url="{{ $p->is_home ? '/' : '/' . $p->slug }}">
              + {{ $p->title }} <span class="pb2-field-hint">{{ $p->is_home ? '/' : '/' . $p->slug }}</span>
            </button>
          @endforeach
        </div>
      </details>
    @endif

    <div class="pb2-navlist-status" id="pb2-nav-status" style="margin-top:8px"></div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">CTA button</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_cta" value="1" {{ $get('show_cta', true) ? 'checked' : '' }}>
      <span>Show CTA button</span>
    </label>

    <div class="pb2-field" style="margin-top:10px">
      <label class="pb2-field-label">Button label</label>
      <input type="text" class="pb2-input" data-field="cta_label" value="{{ $get('cta_label', 'Book Now') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Button URL</label>
      <input type="text" class="pb2-input" data-field="cta_url" value="{{ $get('cta_url', '/book') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Button style</label>
      <select class="pb2-input" data-field="cta_style">
        @foreach(['primary'=>'Primary','outline'=>'Outline','ghost'=>'Ghost'] as $v => $n)
          <option value="{{ $v }}" {{ $get('cta_style', 'primary') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Layout style</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Arrangement</label>
      <select class="pb2-input" data-field="layout">
        @foreach(['standard'=>'Standard (logo left, links center, CTA right)','centered'=>'Centered (logo center, links below)','split'=>'Split (logo+CTA left, links right)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('layout', 'standard') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Height</label>
      <div class="pb2-seg" data-field-seg="height">
        @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('height', 'normal') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="height" value="{{ $get('height', 'normal') }}">
    </div>

    {{-- MARKER-PATCH-158-G28 — independent logo size control --}}
    <div class="pb2-field">
      <label class="pb2-field-label">Logo size</label>
      <select class="pb2-input" data-field="logo_size">
        @foreach(['small'=>'Small (22px)','medium'=>'Medium (30px)','large'=>'Large (40px)','xl'=>'Extra large (52px)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('logo_size', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="sticky" value="1" {{ $get('sticky', true) ? 'checked' : '' }}>
      <span>Sticky (stays at top when scrolling)</span>
    </label>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>

    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['solid'=>'Solid','transparent'=>'Transparent','blur'=>'Blur'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode', 'solid') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode', 'solid') }}">
    </div>

    <div class="pb2-field-hint" style="text-align:left;display:block;margin-top:4px">
      Transparent overlays the hero. Blur adds a frosted-glass effect on scroll.
    </div>

    <div class="pb2-bg-pane" data-bg-mode="solid">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#ffffff') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>

    <div class="pb2-field" style="margin-top:12px">
      <label class="pb2-field-label">Border bottom</label>
      <select class="pb2-input" data-field="border_bottom">
        @foreach(['none'=>'None','hairline'=>'Hairline','shadow'=>'Shadow'] as $v => $n)
          <option value="{{ $v }}" {{ $get('border_bottom', 'hairline') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Link colors</div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Default text</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Link</label>
        <div class="pb2-color-row">
          <input type="color" data-field="link_color" value="{{ $get('link_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="link_color_text" value="{{ $get('link_color') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Active page indicator</label>
      <select class="pb2-input" data-field="active_link_style">
        @foreach(['none'=>'None','underline'=>'Underline','dot'=>'Dot','pill'=>'Pill background'] as $v => $n)
          <option value="{{ $v }}" {{ $get('active_link_style', 'underline') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

</div>

{{--=================== ADVANCED ===================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Anchor & classes</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. top-nav">
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
