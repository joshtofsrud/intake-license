{{-- MARKER-ROI-SECTION — ROI editor (intake.works only) --}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $d   = ['calc_fleet' => 30, 'calc_rate' => 100, 'calc_idle' => 50, 'calc_takeup' => 40, 'calc_discount' => 50, 'calc_length' => 1];
  $calcOn = array_key_exists('calc_on', $c) ? ! in_array((string) $c['calc_on'], ['', '0', 'false'], true) : true;
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Heading</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="The return">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Tools that pay for themselves">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase <span class="pb2-field-hint">part of the heading, in the accent colour</span></label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="pay for themselves">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Intro</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Result 1 <span class="pb2-group-meta">leave the big line blank to hide it</span></div>
    <div class="pb2-field">
      <label class="pb2-field-label">Big line</label>
      <input type="text" class="pb2-input" data-field="s1_big" value="{{ $get('s1_big') }}" placeholder="$8 email. Over $3,000 back.">
      <div class="pb2-field-hint" style="display:block;text-align:left;margin-top:6px">Use → between the cost and the return and the return is highlighted, e.g. "$8 → $3,000+".</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">What it was</label>
      <textarea class="pb2-input pb2-textarea" data-field="s1_label" rows="2">{{ $get('s1_label') }}</textarea>
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Source</label>
        <input type="text" class="pb2-input" data-field="s1_source" value="{{ $get('s1_source') }}" placeholder="Our own shop">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Fine print</label>
        <input type="text" class="pb2-input" data-field="s1_note" value="{{ $get('s1_note') }}" placeholder="How the number was worked out">
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Result 2 <span class="pb2-group-meta">leave the big line blank to hide it</span></div>
    <div class="pb2-field">
      <label class="pb2-field-label">Big line</label>
      <input type="text" class="pb2-input" data-field="s2_big" value="{{ $get('s2_big') }}" placeholder="$8 email. Over $3,000 back.">
      <div class="pb2-field-hint" style="display:block;text-align:left;margin-top:6px">Use → between the cost and the return and the return is highlighted, e.g. "$8 → $3,000+".</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">What it was</label>
      <textarea class="pb2-input pb2-textarea" data-field="s2_label" rows="2">{{ $get('s2_label') }}</textarea>
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Source</label>
        <input type="text" class="pb2-input" data-field="s2_source" value="{{ $get('s2_source') }}" placeholder="Our own shop">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Fine print</label>
        <input type="text" class="pb2-input" data-field="s2_note" value="{{ $get('s2_note') }}" placeholder="How the number was worked out">
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Result 3 <span class="pb2-group-meta">leave the big line blank to hide it</span></div>
    <div class="pb2-field">
      <label class="pb2-field-label">Big line</label>
      <input type="text" class="pb2-input" data-field="s3_big" value="{{ $get('s3_big') }}" placeholder="$8 email. Over $3,000 back.">
      <div class="pb2-field-hint" style="display:block;text-align:left;margin-top:6px">Use → between the cost and the return and the return is highlighted, e.g. "$8 → $3,000+".</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">What it was</label>
      <textarea class="pb2-input pb2-textarea" data-field="s3_label" rows="2">{{ $get('s3_label') }}</textarea>
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Source</label>
        <input type="text" class="pb2-input" data-field="s3_source" value="{{ $get('s3_source') }}" placeholder="Our own shop">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Fine print</label>
        <input type="text" class="pb2-input" data-field="s3_note" value="{{ $get('s3_note') }}" placeholder="How the number was worked out">
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Calculator</div>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="calc_on" value="1" {{ $calcOn ? 'checked' : '' }}><span>Show the rental extension calculator</span></label>
    <div class="pb2-field">
      <label class="pb2-field-label">Title</label>
      <input type="text" class="pb2-input" data-field="calc_title" value="{{ $get('calc_title') }}" placeholder="Rentals that extend themselves">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Intro</label>
      <textarea class="pb2-input pb2-textarea" data-field="calc_intro" rows="2">{{ $get('calc_intro') }}</textarea>
    </div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin:4px 0 8px">Starting values. Visitors drag the sliders to match their own fleet; nothing they enter is saved.</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Items in the fleet</label>
        <input type="number" class="pb2-input" min="1" max="500" step="1" data-field="calc_fleet" value="{{ $get('calc_fleet', $d['calc_fleet']) }}">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Daily rate ($)</label>
        <input type="number" class="pb2-input" min="1" max="5000" step="1" data-field="calc_rate" value="{{ $get('calc_rate', $d['calc_rate']) }}">
      </div>
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Idle (% of the month)</label>
        <input type="number" class="pb2-input" min="0" max="95" step="1" data-field="calc_idle" value="{{ $get('calc_idle', $d['calc_idle']) }}">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Typical rental (days)</label>
        <input type="number" class="pb2-input" min="1" max="30" step="1" data-field="calc_length" value="{{ $get('calc_length', $d['calc_length']) }}">
      </div>
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Take the extension (%)</label>
        <input type="number" class="pb2-input" min="0" max="100" step="1" data-field="calc_takeup" value="{{ $get('calc_takeup', $d['calc_takeup']) }}">
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Extension discount (%)</label>
        <input type="number" class="pb2-input" min="0" max="90" step="1" data-field="calc_discount" value="{{ $get('calc_discount', $d['calc_discount']) }}">
      </div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Fine print</label>
      <input type="text" class="pb2-input" data-field="calc_note" value="{{ $get('calc_note') }}" placeholder="Estimate. One extra day per accepted offer.">
    </div>
  </div>

</div>

<div class="pb2-tab-panel" data-tab="style" hidden>
  {{-- MARKER-ROI-SECTION — the standard Section background controls --}}
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="roi">
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
