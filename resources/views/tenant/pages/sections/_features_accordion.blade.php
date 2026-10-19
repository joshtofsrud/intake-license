{{-- Features accordion editor (intake.works only). MARKER-FEATURES-ACCORDION --}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $faPanels = $c['panels'] ?? [];
  if (is_string($faPanels)) { $dd = json_decode($faPanels, true); $faPanels = is_array($dd) ? $dd : []; }
  if (! is_array($faPanels)) $faPanels = [];
@endphp
<style>.pb2-tiacc-thumb{width:56px;height:38px;border-radius:4px;background:var(--pb2-surface-3) center/cover no-repeat;font-size:10px;color:var(--pb2-text-faint);display:flex;align-items:center;justify-content:center;flex:none}.pb2-farow+.pb2-farow{margin-top:8px}.pb2-fa-swatch{width:12px;height:12px;border-radius:3px;flex:none;border:1px solid rgba(255,255,255,.15)}</style>

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
    <div class="pb2-group-title">Panels <span class="pb2-group-meta" id="pb2-fa-count">{{ count($faPanels) }} / 9</span></div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-bottom:8px">One panel is open at a time; the rest sit as slim coloured strips. On phones they stack and open downward. {{ ($isMarketing ?? false) ? '"Live" visuals are small animated screens with sample data; ' : '' }}Picture shows an image you upload.</div>
    <div id="pb2-fa-list">
      @foreach($faPanels as $i => $it)
<div class="pb2-farow pb2-faqrow">
  <div class="pb2-faqrow-head">
    <span class="pb2-navlist-handle">⋮⋮</span><span class="pb2-faqrow-pos">{{ str_pad((string) (($i ?? 0) + 1), 2, '0', STR_PAD_LEFT) }}</span>
    <span class="pb2-fa-swatch" style="background:{{ $it['color'] ?? '#1f3b2b' }}"></span>
    <span class="pb2-fa-title" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">{{ $it['title'] ?? '' }}</span>
    <button type="button" class="pb2-textlink" data-fa-toggle>Edit</button>
    <button type="button" class="pb2-textlink" data-fa-up title="Move up" aria-label="Move up">↑</button>
    <button type="button" class="pb2-navlist-remove" data-fa-remove title="Remove" aria-label="Remove">×</button>
  </div>
  <div class="pb2-faqrow-fields" data-fa-body hidden>
    <input type="text" class="pb2-input pb2-input-sm" data-fa-field="title" value="{{ $it['title'] ?? '' }}" placeholder="Panel name, e.g. Online booking">
    <input type="text" class="pb2-input pb2-input-sm" data-fa-field="heading" value="{{ $it['heading'] ?? '' }}" placeholder="Heading when open">
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-fa-field="body" rows="2" placeholder="One or two lines">{{ $it['body'] ?? '' }}</textarea>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <input type="text" class="pb2-input pb2-input-sm" data-fa-field="link_label" value="{{ $it['link_label'] ?? '' }}" placeholder="Link label">
      <input type="text" class="pb2-input pb2-input-sm" data-fa-field="link_url" value="{{ $it['link_url'] ?? '' }}" placeholder="/demo">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;align-items:center">
      <label style="display:flex;gap:6px;align-items:center;font-size:11.5px;color:var(--pb2-text-dim)"><input type="color" data-fa-field="color" value="{{ $it['color'] ?? '#1f3b2b' }}" class="pb2-color-swatch"> Panel colour</label>
      <label style="display:flex;gap:6px;align-items:center;font-size:11.5px;color:var(--pb2-text-dim)"><input type="color" data-fa-field="accent" value="{{ $it['accent'] ?? '#BEF264' }}" class="pb2-color-swatch"> Accent</label>
    </div>
    <select class="pb2-input pb2-input-sm" data-fa-field="visual">
      @foreach((($isMarketing ?? false) ? ['flow'=>'Live: booking flow','wo'=>'Live: work order','pos'=>'Live: register sale','cust'=>'Live: customer record','wb'=>'Live: win-back emails','site'=>'Live: website colours','grow'=>'Live: add-on switches','image'=>'Picture','none'=>'Nothing'] : ['image'=>'Picture','none'=>'Nothing']) as $v => $n)
        <option value="{{ $v }}" @selected(($it['visual'] ?? 'none') === $v)>{{ $n }}</option>
      @endforeach
    </select>
    <div data-fa-imgrow style="display:flex;gap:10px;align-items:center">
      <div class="pb2-tiacc-thumb"></div>
      <button type="button" class="pb2-textlink" data-fa-upload>Upload</button>
      <button type="button" class="pb2-textlink" data-fa-lib>Choose from library</button>
      <button type="button" class="pb2-textlink pb2-textlink-danger" data-fa-clear>Remove</button>
      <span class="pb2-field-hint" data-fa-status></span>
    </div>
    <input type="hidden" data-fa-field="image" value="{{ $it['image'] ?? '' }}">
  </div>
</div>
      @endforeach
    </div>
    <template id="pb2-fa-tpl">
      @php $i = 0; $it = []; @endphp
<div class="pb2-farow pb2-faqrow">
  <div class="pb2-faqrow-head">
    <span class="pb2-navlist-handle">⋮⋮</span><span class="pb2-faqrow-pos">{{ str_pad((string) (($i ?? 0) + 1), 2, '0', STR_PAD_LEFT) }}</span>
    <span class="pb2-fa-swatch" style="background:{{ $it['color'] ?? '#1f3b2b' }}"></span>
    <span class="pb2-fa-title" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">{{ $it['title'] ?? '' }}</span>
    <button type="button" class="pb2-textlink" data-fa-toggle>Edit</button>
    <button type="button" class="pb2-textlink" data-fa-up title="Move up" aria-label="Move up">↑</button>
    <button type="button" class="pb2-navlist-remove" data-fa-remove title="Remove" aria-label="Remove">×</button>
  </div>
  <div class="pb2-faqrow-fields" data-fa-body hidden>
    <input type="text" class="pb2-input pb2-input-sm" data-fa-field="title" value="{{ $it['title'] ?? '' }}" placeholder="Panel name, e.g. Online booking">
    <input type="text" class="pb2-input pb2-input-sm" data-fa-field="heading" value="{{ $it['heading'] ?? '' }}" placeholder="Heading when open">
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-fa-field="body" rows="2" placeholder="One or two lines">{{ $it['body'] ?? '' }}</textarea>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <input type="text" class="pb2-input pb2-input-sm" data-fa-field="link_label" value="{{ $it['link_label'] ?? '' }}" placeholder="Link label">
      <input type="text" class="pb2-input pb2-input-sm" data-fa-field="link_url" value="{{ $it['link_url'] ?? '' }}" placeholder="/demo">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;align-items:center">
      <label style="display:flex;gap:6px;align-items:center;font-size:11.5px;color:var(--pb2-text-dim)"><input type="color" data-fa-field="color" value="{{ $it['color'] ?? '#1f3b2b' }}" class="pb2-color-swatch"> Panel colour</label>
      <label style="display:flex;gap:6px;align-items:center;font-size:11.5px;color:var(--pb2-text-dim)"><input type="color" data-fa-field="accent" value="{{ $it['accent'] ?? '#BEF264' }}" class="pb2-color-swatch"> Accent</label>
    </div>
    <select class="pb2-input pb2-input-sm" data-fa-field="visual">
      @foreach((($isMarketing ?? false) ? ['flow'=>'Live: booking flow','wo'=>'Live: work order','pos'=>'Live: register sale','cust'=>'Live: customer record','wb'=>'Live: win-back emails','site'=>'Live: website colours','grow'=>'Live: add-on switches','image'=>'Picture','none'=>'Nothing'] : ['image'=>'Picture','none'=>'Nothing']) as $v => $n)
        <option value="{{ $v }}" @selected(($it['visual'] ?? 'none') === $v)>{{ $n }}</option>
      @endforeach
    </select>
    <div data-fa-imgrow style="display:flex;gap:10px;align-items:center">
      <div class="pb2-tiacc-thumb"></div>
      <button type="button" class="pb2-textlink" data-fa-upload>Upload</button>
      <button type="button" class="pb2-textlink" data-fa-lib>Choose from library</button>
      <button type="button" class="pb2-textlink pb2-textlink-danger" data-fa-clear>Remove</button>
      <span class="pb2-field-hint" data-fa-status></span>
    </div>
    <input type="hidden" data-fa-field="image" value="{{ $it['image'] ?? '' }}">
  </div>
</div>
    </template>
    <button type="button" class="pb2-addrow" id="pb2-fa-add">+ Add panel</button>
    <input type="hidden" data-field="panels" id="pb2-fa-json" value="{{ json_encode($faPanels) }}">
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Behaviour</div>
    <div class="pb2-field-row">
      <div class="pb2-field"><label class="pb2-field-label">Open first</label><input type="number" class="pb2-input" data-field="start_panel" min="1" max="9" value="{{ $get('start_panel', 3) }}"></div>
      <div class="pb2-field"><label class="pb2-field-label">Seconds per panel</label><input type="number" class="pb2-input" data-field="autoplay_seconds" min="3" max="30" value="{{ $get('autoplay_seconds', 7) }}"></div>
    </div>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="autoplay" value="1" {{ in_array((string) $get('autoplay', '1'), ['0', 'false', ''], true) ? '' : 'checked' }}><span>Move to the next panel on its own (stops once a visitor picks one; hovering pauses)</span></label>
    <div class="pb2-field">
      <label class="pb2-field-label">Height <span class="pb2-field-hint">desktop</span></label>
      <div class="pb2-seg" data-field-seg="panel_height">
        @foreach(['m'=>'Medium','l'=>'Large','xl'=>'Extra large'] as $v => $n)
          <button type="button" class="pb2-seg-btn {{ $get('panel_height', 'l') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="panel_height" value="{{ $get('panel_height', 'l') }}">
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
