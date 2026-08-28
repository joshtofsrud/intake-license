{{--
  MARKER-CAROUSEL-SECTION — image_carousel editor (v2 inspector partial).
  Content / Design / Advanced tabs. The image repeater reuses the gallery's
  #pb2-gimg-* contract (initGalleryList in edit.blade.php wires it), opting
  into the per-slide link field via data-links="1".
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  $images = $c['images'] ?? [];
  if (is_string($images)) { $dd = json_decode($images, true); $images = is_array($dd) ? $dd : []; }
  if (!is_array($images)) $images = [];
  $images = array_values(array_map(function ($img) {
      if (is_string($img)) return ['url' => $img, 'caption' => '', 'alt' => '', 'link' => ''];
      return [
        'url'     => $img['url'] ?? '',
        'caption' => $img['caption'] ?? '',
        'alt'     => $img['alt'] ?? '',
        'link'    => $img['link'] ?? '',
      ];
  }, $images));
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<style>
  .pb2-gimg-list { display:flex; flex-direction:column; gap:8px; }
  .pb2-gimg { display:flex; gap:8px; align-items:flex-start; padding:8px; border:0.5px solid var(--ia-border,#1f2940); border-radius:8px; background:rgba(255,255,255,.02); }
  .pb2-gimg-thumb { width:54px; height:54px; flex:0 0 54px; border-radius:6px; background-size:cover; background-position:center; background-color:rgba(0,0,0,.25); }
  .pb2-gimg-fields { flex:1 1 auto; display:flex; flex-direction:column; gap:6px; min-width:0; }
  .pb2-gimg-head { display:flex; align-items:center; gap:6px; }
  .pb2-gimg .pb2-navlist-handle { cursor:grab; opacity:.4; flex:0 0 auto; align-self:center; }
  .pb2-gimg-empty { opacity:.5; font-size:11px; padding:6px 2px; }
</style>

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Intro text <span class="pb2-field-hint">optional</span></div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Optional heading">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional supporting text">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Slides
      <span class="pb2-group-meta" id="pb2-gimg-count">{{ count($images) }} / 24</span>
    </div>

    <div class="pb2-gimg-list" id="pb2-gimg-list" data-links="1">
      @foreach($images as $i => $img)
        <div class="pb2-gimg" data-gimg-idx="{{ $i }}">
          <span class="pb2-navlist-handle" title="Drag to reorder">&#8942;&#8942;</span>
          <div class="pb2-gimg-thumb" style="background-image:url('{{ $img['url'] }}')"></div>
          <div class="pb2-gimg-fields">
            <div class="pb2-gimg-head">
              <input type="text" class="pb2-input pb2-input-sm" data-gimg-field="caption" value="{{ $img['caption'] }}" placeholder="Caption (optional)">
              <button type="button" class="pb2-navlist-remove" data-gimg-remove title="Remove">&times;</button>
            </div>
            <input type="text" class="pb2-input pb2-input-sm" data-gimg-field="alt" value="{{ $img['alt'] }}" placeholder="Alt text (accessibility)">
            <input type="text" class="pb2-input pb2-input-sm" data-gimg-field="link" value="{{ $img['link'] }}" placeholder="Link URL (optional)">
            <input type="hidden" data-gimg-field="url" value="{{ $img['url'] }}">
          </div>
        </div>
      @endforeach
      @if(empty($images))
        <div class="pb2-gimg-empty" id="pb2-gimg-empty">No slides yet &mdash; upload or pick from your library below.</div>
      @endif
    </div>

    <button type="button" class="pb2-addrow" id="pb2-gimg-add">+ Upload image</button>
    <button type="button" class="pb2-addrow" id="pb2-gimg-lib" style="margin-top:6px">+ From library</button>
    <div class="pb2-field-hint" style="margin-top:6px">JPG, PNG, WebP, or SVG &middot; 5 MB max each</div>

    <input type="hidden" data-field="images" id="pb2-gimg-json" value="{{ json_encode($images) }}">
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Layout</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Slides per view</label>
      <div class="pb2-seg" data-field-seg="slides_per_view">
        @foreach([1,2,3] as $val)
          <button type="button" class="pb2-seg-btn {{ (int)$get('slides_per_view',1) === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $val }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="slides_per_view" value="{{ $get('slides_per_view',1) }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Slide shape</label>
      <select class="pb2-input" data-field="aspect_ratio">
        @foreach(['wide'=>'Wide (16:9)','landscape'=>'Landscape (4:3)','square'=>'Square (1:1)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('aspect_ratio','wide') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Spacing</label>
      <select class="pb2-input" data-field="gap">
        @foreach(['tight'=>'Tight','normal'=>'Normal','loose'=>'Loose'] as $v => $n)
          <option value="{{ $v }}" {{ $get('gap','normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Behavior</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_arrows" value="1" {{ $get('show_arrows','1') === '1' || $get('show_arrows') === 1 || $get('show_arrows') === true ? 'checked' : '' }}>
      <span>Show previous / next arrows</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_dots" value="1" {{ $get('show_dots','1') === '1' || $get('show_dots') === 1 || $get('show_dots') === true ? 'checked' : '' }}>
      <span>Show position dots</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="loop" value="1" {{ $get('loop','1') === '1' || $get('loop') === 1 || $get('loop') === true ? 'checked' : '' }}>
      <span>Loop back to the start at the end</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="autoplay" value="1" {{ $get('autoplay') === '1' || $get('autoplay') === 1 || $get('autoplay') === true ? 'checked' : '' }}>
      <span>Autoplay</span>
    </label>
    <div class="pb2-field">
      <label class="pb2-field-label">Autoplay interval</label>
      <select class="pb2-input" data-field="autoplay_seconds">
        @foreach([3,5,8,10] as $sec)
          <option value="{{ $sec }}" {{ (int)$get('autoplay_seconds',5) === $sec ? 'selected' : '' }}>{{ $sec }} seconds</option>
        @endforeach
      </select>
      <div class="pb2-field-hint">Autoplay pauses while the visitor hovers or touches the carousel, and is skipped for visitors with reduced motion enabled.</div>
    </div>
  </div>

</div>

{{--=================== DESIGN (data-tab=style) ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Corners &amp; captions</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Corner radius</label>
      <select class="pb2-input" data-field="radius">
        @foreach(['default'=>'Theme default','none'=>'Square','sm'=>'Small','md'=>'Medium','lg'=>'Large'] as $v => $n)
          <option value="{{ $v }}" {{ $get('radius','default') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_captions" value="1" {{ $get('show_captions') ? 'checked' : '' }}>
      <span>Show captions on slides</span>
    </label>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Section background</div>
    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['none'=>'None','color'=>'Color'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode','none') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode','none') }}">
    </div>
    <div class="pb2-bg-pane" data-bg-mode="color">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color','#0a0f1a') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. carousel">
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
