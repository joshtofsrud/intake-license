#!/usr/bin/env python3
"""rentals_showcase gets the same Style + Advanced tabs as the three new
rental sections: bg none/color/gradient(+angle), heading/body/accent,
card bg + border, anchor ID, custom classes, hide on mobile/desktop.
Backward compatible: an existing bg_color keeps working as color mode.
Run from repo root: python3 apply-rentals-showcase-style-advanced.py
"""
import sys

def read(p):
    with open(p) as f: return f.read()
def write(p, s):
    with open(p, 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

STYLE_COMMON = "'bg_mode'=>'none','bg_gradient_from'=>'','bg_gradient_to'=>'','bg_gradient_angle'=>135,'text_color'=>'','text_color_body'=>'','accent_color'=>'','card_bg'=>'','card_border'=>'','anchor_id'=>'','custom_classes'=>'','hide_on_mobile'=>false,'hide_on_desktop'=>false"

# 1) DEFAULTS
sub('app/Http/Controllers/Tenant/PageBuilderController.php',
    "'rentals_showcase' => ['eyebrow'=>'','heading'=>'Rent the good stuff','body'=>'','category_id'=>'','max_models'=>6,'show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Check availability','cta_url'=>'/rentals','bg_color'=>''],",
    "'rentals_showcase' => ['eyebrow'=>'','heading'=>'Rent the good stuff','body'=>'','category_id'=>'','max_models'=>6,'show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Check availability','cta_url'=>'/rentals','bg_color'=>''," + STYLE_COMMON + "],",
    "DEFAULTS: showcase")

# 2) Editor — replace stub style tab with full style + advanced (same
#    markup as the three new sections; shared JS wires everything)
OLD_STYLE_TAB = """{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <div class="pb2-field-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" {{ $get('bg_color') ? '' : 'data-blank=1' }}>
        <input type="text" class="pb2-input" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="default">
      </div>
    </div>
  </div>
</div>"""

NEW_TABS = """{{--=================== STYLE ===================--}}
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
          <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" class="pb2-color-swatch">
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
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from') ?: '#ffffff' }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to') ?: '#f4f4f4' }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Cards</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Card background</label>
        <div class="pb2-color-row">
          <input type="color" data-field="card_bg" value="{{ $get('card_bg') ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_bg_text" value="{{ $get('card_bg') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Card border</label>
        <div class="pb2-color-row">
          <input type="color" data-field="card_border" value="{{ $get('card_border') ?: '#e5e5e5' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_border_text" value="{{ $get('card_border') }}" placeholder="auto">
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
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#111111' }}" class="pb2-color-swatch">
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
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">eyebrow & category labels</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#111111' }}" class="pb2-color-swatch">
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. rentals">
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
</div>"""

sub('resources/views/tenant/pages/sections/_rentals_showcase.blade.php',
    OLD_STYLE_TAB, NEW_TABS, "editor showcase: style+advanced")

# 3) Public — style resolution + consume
P = 'resources/views/public/sections/_rentals_showcase.blade.php'
sub(P,
    """  $rsShowRates   = ($c['show_rates'] ?? '1') === '1';
  $rsShowDeposit = ($c['show_deposit'] ?? '0') === '1';
@endphp""",
    """  $rsShowRates   = ($c['show_rates'] ?? '1') === '1';
  $rsShowDeposit = ($c['show_deposit'] ?? '0') === '1';
  // MARKER-RENTAL-STYLE — style + advanced resolution (feature_grid model).
  $stBgMode  = $c['bg_mode'] ?? (!empty($c['bg_color']) ? 'color' : 'none');
  $stText    = ($c['text_color'] ?? '') ?: 'inherit';
  $stBody    = ($c['text_color_body'] ?? '') ?: 'inherit';
  $stAccent  = ($c['accent_color'] ?? '') ?: 'inherit';
  $stCardBg  = ($c['card_bg'] ?? '') ?: 'rgba(255,255,255,.6)';
  $stCardBd  = ($c['card_border'] ?? '') ?: 'rgba(0,0,0,.1)';
  $anchorId  = trim($c['anchor_id'] ?? '') ?: 'rentals';
  $custClass = trim($c['custom_classes'] ?? '');
  $instId    = 'p-rsc-' . ($section->id ?? uniqid());
@endphp
<style>
.{{ $instId }} {
  @if($stBgMode === 'color' && !empty($c['bg_color'])) background: {{ $c['bg_color'] }};
  @elseif($stBgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ ($c['bg_gradient_from'] ?? '') ?: '#ffffff' }} 0%, {{ ($c['bg_gradient_to'] ?? '') ?: '#f4f4f4' }} 100%);
  @endif
}
.{{ $instId }} .rs-head { color: {{ $stText }}; }
.{{ $instId }} .rs-body { color: {{ $stBody }}; }
.{{ $instId }} .p-eyebrow, .{{ $instId }} .rs-accent { color: {{ $stAccent }}; }
.{{ $instId }} .rs-card { background: {{ $stCardBg }}; border-color: {{ $stCardBd }}; }
@if(!empty($c['hide_on_mobile']))
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if(!empty($c['hide_on_desktop']))
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>""",
    "public showcase: style php")

sub(P,
    """<section class="p-section" id="rentals" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>""",
    """<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">""",
    "public showcase: section tag")

sub(P,
    """      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif""",
    """      @if(!empty($c['heading']))<h2 class="p-section-heading rs-head">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p class="rs-body" style="max-width:560px;margin:10px auto 0;opacity:.85;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif""",
    "public showcase: heading colors")

sub(P,
    """        <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);background:rgba(255,255,255,.6);overflow:hidden">""",
    """        <div class="rs-card" style="border-width:1.5px;border-style:solid;border-radius:var(--p-r-lg,14px);overflow:hidden">""",
    "public showcase: card class")

sub(P,
    """          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">{{ $m->category?->name }}</div>""",
    """          <div class="rs-accent" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.75">{{ $m->category?->name }}</div>""",
    "public showcase: category accent")

print("Done. No migration needed.")
