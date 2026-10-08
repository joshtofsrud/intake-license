{{-- Feature tiles editor (intake.works only) --}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $ftTiles = $c['tiles'] ?? [];
  if (is_string($ftTiles)) { $dd = json_decode($ftTiles, true); $ftTiles = is_array($dd) ? $dd : []; }
  if (! is_array($ftTiles)) $ftTiles = [];
@endphp
<style>.pb2-tiacc-thumb{width:56px;height:38px;border-radius:4px;background:var(--pb2-surface-3) center/cover no-repeat;font-size:10px;color:var(--pb2-text-faint);display:flex;align-items:center;justify-content:center;flex:none}.pb2-ftrow+.pb2-ftrow{margin-top:8px}</style>

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Heading</div>
    <div class="pb2-field"><label class="pb2-field-label">Eyebrow</label><input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}"></div>
    <div class="pb2-field"><label class="pb2-field-label">Heading</label><input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}"></div>
    <div class="pb2-field"><label class="pb2-field-label">Highlight phrase <span class="pb2-field-hint">part of the heading, in the accent colour</span></label><input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}"></div>
    <div class="pb2-field"><label class="pb2-field-label">Intro</label><textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2">{{ $get('subheading') }}</textarea></div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Tiles <span class="pb2-group-meta" id="pb2-ft-count">{{ count($ftTiles) }} / 12</span></div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-bottom:8px">Three to a row; a wide tile takes two. Tapping a tile opens its drawer under that row and pushes the page down.</div>
    <div id="pb2-ft-list">
      @foreach($ftTiles as $i => $it)
<div class="pb2-ftrow pb2-faqrow">
  <div class="pb2-faqrow-head">
    <span class="pb2-navlist-handle">⋮⋮</span><span class="pb2-faqrow-pos">{{ str_pad((string) (($i ?? 0) + 1), 2, '0', STR_PAD_LEFT) }}</span>
    <span class="pb2-ft-title" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">{{ $it['title'] ?? '' }}</span>
    <button type="button" class="pb2-textlink" data-ft-toggle>Edit</button>
    <button type="button" class="pb2-textlink" data-ft-up title="Move up" aria-label="Move up">↑</button>
    <button type="button" class="pb2-navlist-remove" data-ft-remove title="Remove" aria-label="Remove">×</button>
  </div>
  <div class="pb2-faqrow-fields" data-ft-body hidden>
    <div style="display:grid;grid-template-columns:110px 1fr;gap:6px">
      <select class="pb2-input pb2-input-sm" data-ft-field="icon"><option value="calendar" @selected(($it['icon'] ?? '') === 'calendar')>Calendar</option><option value="clipboard" @selected(($it['icon'] ?? '') === 'clipboard')>Clipboard</option><option value="register" @selected(($it['icon'] ?? '') === 'register')>Register</option><option value="people" @selected(($it['icon'] ?? '') === 'people')>People</option><option value="mail" @selected(($it['icon'] ?? '') === 'mail')>Mail</option><option value="pulse" @selected(($it['icon'] ?? '') === 'pulse')>Pulse</option><option value="chart" @selected(($it['icon'] ?? '') === 'chart')>Chart</option><option value="globe" @selected(($it['icon'] ?? '') === 'globe')>Globe</option><option value="gift" @selected(($it['icon'] ?? '') === 'gift')>Gift</option><option value="wrench" @selected(($it['icon'] ?? '') === 'wrench')>Wrench</option><option value="box" @selected(($it['icon'] ?? '') === 'box')>Box</option><option value="clock" @selected(($it['icon'] ?? '') === 'clock')>Clock</option></select>
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="title" value="{{ $it['title'] ?? '' }}" placeholder="Tile title">
    </div>
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="body" rows="2" placeholder="One or two lines">{{ $it['body'] ?? '' }}</textarea>
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="chips" rows="2" placeholder="Chips, one per line (optional)">{{ $it['chips'] ?? '' }}</textarea>
    <div style="display:flex;gap:14px;font-size:11.5px;color:var(--pb2-text-dim)">
      <label><input type="checkbox" data-ft-field="flow" {{ ! empty($it['flow']) ? 'checked' : '' }}> Chips are steps (arrows)</label>
      <label><input type="checkbox" data-ft-field="wide" {{ ! empty($it['wide']) ? 'checked' : '' }}> Wide tile</label>
    </div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-top:4px">Drawer, opened by tapping the tile. Leave its heading and text blank and the tile won't open.</div>
    <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_kicker" value="{{ $it['d_kicker'] ?? '' }}" placeholder="Small label, e.g. Work orders">
    <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_heading" value="{{ $it['d_heading'] ?? '' }}" placeholder="Drawer heading">
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="d_body" rows="2" placeholder="A short paragraph">{{ $it['d_body'] ?? '' }}</textarea>
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="d_points" rows="4" placeholder="Points, one per line">{{ $it['d_points'] ?? '' }}</textarea>
    <div style="display:flex;gap:10px;align-items:center">
      <div class="pb2-tiacc-thumb"></div>
      <button type="button" class="pb2-textlink" data-ft-upload>Upload</button>
      <button type="button" class="pb2-textlink" data-ft-lib>Choose from library</button>
      <button type="button" class="pb2-textlink pb2-textlink-danger" data-ft-clear>Remove</button>
      <span class="pb2-field-hint" data-ft-status></span>
    </div>
    <input type="hidden" data-ft-field="d_image" value="{{ $it['d_image'] ?? '' }}">
    <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_address" value="{{ $it['d_address'] ?? '' }}" placeholder="Address shown in the window bar">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta1_label" value="{{ $it['d_cta1_label'] ?? '' }}" placeholder="Button 1">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta1_url" value="{{ $it['d_cta1_url'] ?? '' }}" placeholder="/demo">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta2_label" value="{{ $it['d_cta2_label'] ?? '' }}" placeholder="Button 2">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta2_url" value="{{ $it['d_cta2_url'] ?? '' }}" placeholder="https://app.intake.works/signup">
    </div>
  </div>
</div>
      @endforeach
    </div>
    <template id="pb2-ft-tpl">
      @php $i = 0; $it = []; @endphp
<div class="pb2-ftrow pb2-faqrow">
  <div class="pb2-faqrow-head">
    <span class="pb2-navlist-handle">⋮⋮</span><span class="pb2-faqrow-pos">{{ str_pad((string) (($i ?? 0) + 1), 2, '0', STR_PAD_LEFT) }}</span>
    <span class="pb2-ft-title" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">{{ $it['title'] ?? '' }}</span>
    <button type="button" class="pb2-textlink" data-ft-toggle>Edit</button>
    <button type="button" class="pb2-textlink" data-ft-up title="Move up" aria-label="Move up">↑</button>
    <button type="button" class="pb2-navlist-remove" data-ft-remove title="Remove" aria-label="Remove">×</button>
  </div>
  <div class="pb2-faqrow-fields" data-ft-body hidden>
    <div style="display:grid;grid-template-columns:110px 1fr;gap:6px">
      <select class="pb2-input pb2-input-sm" data-ft-field="icon"><option value="calendar" @selected(($it['icon'] ?? '') === 'calendar')>Calendar</option><option value="clipboard" @selected(($it['icon'] ?? '') === 'clipboard')>Clipboard</option><option value="register" @selected(($it['icon'] ?? '') === 'register')>Register</option><option value="people" @selected(($it['icon'] ?? '') === 'people')>People</option><option value="mail" @selected(($it['icon'] ?? '') === 'mail')>Mail</option><option value="pulse" @selected(($it['icon'] ?? '') === 'pulse')>Pulse</option><option value="chart" @selected(($it['icon'] ?? '') === 'chart')>Chart</option><option value="globe" @selected(($it['icon'] ?? '') === 'globe')>Globe</option><option value="gift" @selected(($it['icon'] ?? '') === 'gift')>Gift</option><option value="wrench" @selected(($it['icon'] ?? '') === 'wrench')>Wrench</option><option value="box" @selected(($it['icon'] ?? '') === 'box')>Box</option><option value="clock" @selected(($it['icon'] ?? '') === 'clock')>Clock</option></select>
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="title" value="{{ $it['title'] ?? '' }}" placeholder="Tile title">
    </div>
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="body" rows="2" placeholder="One or two lines">{{ $it['body'] ?? '' }}</textarea>
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="chips" rows="2" placeholder="Chips, one per line (optional)">{{ $it['chips'] ?? '' }}</textarea>
    <div style="display:flex;gap:14px;font-size:11.5px;color:var(--pb2-text-dim)">
      <label><input type="checkbox" data-ft-field="flow" {{ ! empty($it['flow']) ? 'checked' : '' }}> Chips are steps (arrows)</label>
      <label><input type="checkbox" data-ft-field="wide" {{ ! empty($it['wide']) ? 'checked' : '' }}> Wide tile</label>
    </div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-top:4px">Drawer, opened by tapping the tile. Leave its heading and text blank and the tile won't open.</div>
    <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_kicker" value="{{ $it['d_kicker'] ?? '' }}" placeholder="Small label, e.g. Work orders">
    <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_heading" value="{{ $it['d_heading'] ?? '' }}" placeholder="Drawer heading">
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="d_body" rows="2" placeholder="A short paragraph">{{ $it['d_body'] ?? '' }}</textarea>
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ft-field="d_points" rows="4" placeholder="Points, one per line">{{ $it['d_points'] ?? '' }}</textarea>
    <div style="display:flex;gap:10px;align-items:center">
      <div class="pb2-tiacc-thumb"></div>
      <button type="button" class="pb2-textlink" data-ft-upload>Upload</button>
      <button type="button" class="pb2-textlink" data-ft-lib>Choose from library</button>
      <button type="button" class="pb2-textlink pb2-textlink-danger" data-ft-clear>Remove</button>
      <span class="pb2-field-hint" data-ft-status></span>
    </div>
    <input type="hidden" data-ft-field="d_image" value="{{ $it['d_image'] ?? '' }}">
    <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_address" value="{{ $it['d_address'] ?? '' }}" placeholder="Address shown in the window bar">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta1_label" value="{{ $it['d_cta1_label'] ?? '' }}" placeholder="Button 1">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta1_url" value="{{ $it['d_cta1_url'] ?? '' }}" placeholder="/demo">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta2_label" value="{{ $it['d_cta2_label'] ?? '' }}" placeholder="Button 2">
      <input type="text" class="pb2-input pb2-input-sm" data-ft-field="d_cta2_url" value="{{ $it['d_cta2_url'] ?? '' }}" placeholder="https://app.intake.works/signup">
    </div>
  </div>
</div>
    </template>
    <button type="button" class="pb2-addrow" id="pb2-ft-add">+ Add tile</button>
    <input type="hidden" data-field="tiles" id="pb2-ft-json" value="{{ json_encode($ftTiles) }}">
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Footer line</div>
    <div class="pb2-field"><label class="pb2-field-label">Text</label><input type="text" class="pb2-input" data-field="footer_text" value="{{ $get('footer_text') }}" placeholder="Live in under 10 minutes."></div>
    <div class="pb2-field-row">
      <div class="pb2-field"><label class="pb2-field-label">Link label</label><input type="text" class="pb2-input" data-field="footer_cta_label" value="{{ $get('footer_cta_label') }}"></div>
      <div class="pb2-field"><label class="pb2-field-label">Link</label><input type="text" class="pb2-input" data-field="footer_cta_url" value="{{ $get('footer_cta_url') }}"></div>
    </div>
  </div>
</div>

<div class="pb2-tab-panel" data-tab="style" hidden>
  {{-- the standard Section background controls --}}
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
    <div class="pb2-group-title">Accent</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Accent color</label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Spacing</div>
    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="padding_override">
        @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
          <button type="button" class="pb2-seg-btn {{ $get('padding_override', 'normal') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="padding_override" value="{{ $get('padding_override', 'normal') }}">
    </div>
  </div>
</div>

<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Anchor &amp; classes</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="features">
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
