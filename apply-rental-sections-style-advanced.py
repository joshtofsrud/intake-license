#!/usr/bin/env python3
"""Style + Advanced tabs for rental_spotlight, rental_categories, and
rental_browse — matching feature_grid's model so tenants get one mental
model. Style: bg none/color/gradient(+angle), heading/body/accent
colors, card bg + border, plus per-section extras (spotlight: image
side + corner radius; categories: columns + photo/compact tiles;
browse: button color override). Advanced: anchor ID, custom classes,
hide on mobile/desktop. Public partials rewritten to consume it all
(existing logic preserved: shape-safe decodes, tile photos, image
fallback, unglued directives).
Run from repo root: python3 apply-rental-sections-style-advanced.py
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

PBC = 'app/Http/Controllers/Tenant/PageBuilderController.php'

STYLE_COMMON = "'bg_mode'=>'none','bg_gradient_from'=>'','bg_gradient_to'=>'','bg_gradient_angle'=>135,'text_color'=>'','text_color_body'=>'','accent_color'=>'','card_bg'=>'','card_border'=>'','anchor_id'=>'','custom_classes'=>'','hide_on_mobile'=>false,'hide_on_desktop'=>false"

# 1) DEFAULTS
sub(PBC,
    "'rental_spotlight'  => ['eyebrow'=>'','heading'=>'','body'=>'','model_id'=>'','image_url'=>'','image_alt'=>'','show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Reserve','cta_url'=>'','bg_color'=>''],",
    "'rental_spotlight'  => ['eyebrow'=>'','heading'=>'','body'=>'','model_id'=>'','image_url'=>'','image_alt'=>'','show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Reserve','cta_url'=>'','bg_color'=>'','image_position'=>'left','image_radius'=>14," + STYLE_COMMON + "],",
    "DEFAULTS: spotlight")
sub(PBC,
    "'rental_categories' => ['eyebrow'=>'','heading'=>'Rent by category','body'=>'','category_ids'=>'[]','category_images'=>'{}','show_counts'=>'1','bg_color'=>''],",
    "'rental_categories' => ['eyebrow'=>'','heading'=>'Rent by category','body'=>'','category_ids'=>'[]','category_images'=>'{}','show_counts'=>'1','bg_color'=>'','columns'=>'auto','tile_style'=>'photo'," + STYLE_COMMON + "],",
    "DEFAULTS: categories")
sub(PBC,
    "'rental_browse'     => ['eyebrow'=>'','heading'=>'Check availability','body'=>'','show_deposit'=>'0','bg_color'=>''],",
    "'rental_browse'     => ['eyebrow'=>'','heading'=>'Check availability','body'=>'','show_deposit'=>'0','bg_color'=>'','button_bg'=>'','button_text'=>''," + STYLE_COMMON + "],",
    "DEFAULTS: browse")

# ============================================================
# 2) Editor partials — replace stub Style tab, append Advanced
# ============================================================
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
</div>
"""

def style_tab(extra_groups=""):
    return """{{--=================== STYLE ===================--}}
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
""" + extra_groups + """
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
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">eyebrow & links</span></label>
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
</div>
"""

SPOT_EXTRA = """
  <div class="pb2-group">
    <div class="pb2-group-title">Image</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Image side</label>
      <div class="pb2-seg" data-field-seg="image_position">
        @foreach(['left'=>'Left','right'=>'Right'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('image_position', 'left') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="image_position" value="{{ $get('image_position', 'left') }}">
    </div>
    <div class="pb2-field">
      <div class="pb2-slider-row">
        <label class="pb2-field-label" style="margin:0">Corner radius</label>
        <span class="pb2-slider-value pb2-rsp-rad">{{ $get('image_radius', 14) }}px</span>
      </div>
      <input type="range" min="0" max="28" value="{{ $get('image_radius', 14) }}" data-field="image_radius" oninput="this.parentNode.querySelector('.pb2-rsp-rad').textContent=this.value+'px'">
    </div>
  </div>
"""

CAT_EXTRA = """
  <div class="pb2-group">
    <div class="pb2-group-title">Tiles</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Columns</label>
      <div class="pb2-seg" data-field-seg="columns">
        @foreach(['auto'=>'Auto','2'=>'2','3'=>'3','4'=>'4'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('columns', 'auto') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="columns" value="{{ $get('columns', 'auto') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Tile style</label>
      <div class="pb2-seg" data-field-seg="tile_style">
        @foreach(['photo'=>'Photo header','compact'=>'Compact'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('tile_style', 'photo') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="tile_style" value="{{ $get('tile_style', 'photo') }}">
    </div>
  </div>
"""

BROWSE_EXTRA = """
  <div class="pb2-group">
    <div class="pb2-group-title">Buttons</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Button background</label>
        <div class="pb2-color-row">
          <input type="color" data-field="button_bg" value="{{ $get('button_bg') ?: '#111111' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="button_bg_text" value="{{ $get('button_bg') }}" placeholder="theme default">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Button text</label>
        <div class="pb2-color-row">
          <input type="color" data-field="button_text" value="{{ $get('button_text') ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="button_text_text" value="{{ $get('button_text') }}" placeholder="theme default">
        </div>
      </div>
    </div>
  </div>
"""

sub('resources/views/tenant/pages/sections/_rental_spotlight.blade.php',
    OLD_STYLE_TAB, style_tab(SPOT_EXTRA), "editor spotlight: style+advanced")
sub('resources/views/tenant/pages/sections/_rental_categories.blade.php',
    OLD_STYLE_TAB, style_tab(CAT_EXTRA), "editor categories: style+advanced")
sub('resources/views/tenant/pages/sections/_rental_browse.blade.php',
    OLD_STYLE_TAB, style_tab(BROWSE_EXTRA), "editor browse: style+advanced")

# ============================================================
# 3) Shared style-resolution snippet for the public partials
# ============================================================
def style_php(prefix, default_anchor):
    return f"""  // MARKER-RENTAL-STYLE — style + advanced resolution (feature_grid model).
  $stBgMode  = $c['bg_mode'] ?? (!empty($c['bg_color']) ? 'color' : 'none');
  $stText    = ($c['text_color'] ?? '') ?: 'inherit';
  $stBody    = ($c['text_color_body'] ?? '') ?: 'inherit';
  $stAccent  = ($c['accent_color'] ?? '') ?: 'inherit';
  $stCardBg  = ($c['card_bg'] ?? '') ?: 'rgba(255,255,255,.6)';
  $stCardBd  = ($c['card_border'] ?? '') ?: 'rgba(0,0,0,.1)';
  $anchorId  = trim($c['anchor_id'] ?? '') ?: '{default_anchor}';
  $custClass = trim($c['custom_classes'] ?? '');
  $instId    = '{prefix}-' . ($section->id ?? uniqid());
@endphp
<style>
.{{{{ $instId }}}} {{
  @if($stBgMode === 'color' && !empty($c['bg_color'])) background: {{{{ $c['bg_color'] }}}};
  @elseif($stBgMode === 'gradient') background: linear-gradient({{{{ (int)($c['bg_gradient_angle'] ?? 135) }}}}deg, {{{{ ($c['bg_gradient_from'] ?? '') ?: '#ffffff' }}}} 0%, {{{{ ($c['bg_gradient_to'] ?? '') ?: '#f4f4f4' }}}} 100%);
  @endif
}}
.{{{{ $instId }}}} .rs-head {{ color: {{{{ $stText }}}}; }}
.{{{{ $instId }}}} .rs-body {{ color: {{{{ $stBody }}}}; }}
.{{{{ $instId }}}} .p-eyebrow, .{{{{ $instId }}}} .rs-accent {{ color: {{{{ $stAccent }}}}; }}
.{{{{ $instId }}}} .rs-card {{ background: {{{{ $stCardBg }}}}; border-color: {{{{ $stCardBd }}}}; }}
@if(!empty($c['hide_on_mobile']))
@media (max-width: 768px) {{ .{{{{ $instId }}}} {{ display: none; }} }}
@endif
@if(!empty($c['hide_on_desktop']))
@media (min-width: 769px) {{ .{{{{ $instId }}}} {{ display: none; }} }}
@endif
</style>
@php $__rs_done = true;"""

# --- spotlight public ---
P = 'resources/views/public/sections/_rental_spotlight.blade.php'
sub(P,
    "  $spImage = !empty($c['image_url']) ? $c['image_url'] : ($spModel->image_url ?? '');\n@endphp",
    "  $spImage = !empty($c['image_url']) ? $c['image_url'] : ($spModel->image_url ?? '');\n  $spImgLeft = ($c['image_position'] ?? 'left') !== 'right';\n  $spImgRad = (int) ($c['image_radius'] ?? 14);\n" + style_php('p-rsp', 'rental-spotlight') + "\n@endphp",
    "public spotlight: style php")
sub(P,
    """<section class="p-section" id="rental-spotlight" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>""",
    """<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">""",
    "public spotlight: section tag")
sub(P,
    """      @if(!empty($spImage))
        <div style="border-radius:var(--p-r-lg,14px);overflow:hidden;aspect-ratio:4/3">
          <img src="{{ $spImage }}" alt="{{ $c['image_alt'] ?? $spModel->name }}" style="width:100%;height:100%;object-fit:cover" loading="lazy">
        </div>
      @endif
      <div>""",
    """      @if(!empty($spImage))
        <div style="border-radius:{{ $spImgRad }}px;overflow:hidden;aspect-ratio:4/3;{{ $spImgLeft ? '' : 'order:2' }}">
          <img src="{{ $spImage }}" alt="{{ $c['image_alt'] ?? $spModel->name }}" style="width:100%;height:100%;object-fit:cover" loading="lazy">
        </div>
      @endif
      <div>""",
    "public spotlight: image side + radius")
sub(P,
    """        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">{{ $c['eyebrow'] ?: ($spModel->category?->name ?? 'Rentals') }}</div>
        <h2 class="p-section-heading" style="margin-top:6px">{{ $c['heading'] ?: $spModel->name }}</h2>""",
    """        <div class="rs-accent" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.75">{{ $c['eyebrow'] ?: ($spModel->category?->name ?? 'Rentals') }}</div>
        <h2 class="p-section-heading rs-head" style="margin-top:6px">{{ $c['heading'] ?: $spModel->name }}</h2>""",
    "public spotlight: heading colors")
sub(P,
    """        @if(!empty($c['body']))<p style="margin-top:14px;opacity:.7;font-size:15px;line-height:1.65">{{ $c['body'] }}</p>@endif""",
    """        @if(!empty($c['body']))<p class="rs-body" style="margin-top:14px;opacity:.85;font-size:15px;line-height:1.65">{{ $c['body'] }}</p>@endif""",
    "public spotlight: body color")
sub(P,
    """<style>@media (max-width:720px){ #rental-spotlight .p-spotlight-grid { grid-template-columns:1fr !important; } }</style>""",
    """<style>@media (max-width:720px){ .{{ $instId }} .p-spotlight-grid { grid-template-columns:1fr !important; } .{{ $instId }} .p-spotlight-grid > * { order:0 !important; } }</style>""",
    "public spotlight: responsive by instId")

# --- categories public ---
P = 'resources/views/public/sections/_rental_categories.blade.php'
sub(P,
    """  $rcShowCounts = ($c['show_counts'] ?? '1') === '1';
@endphp""",
    """  $rcShowCounts = ($c['show_counts'] ?? '1') === '1';
  $rcCols = $c['columns'] ?? 'auto';
  $rcGrid = $rcCols === 'auto' ? 'repeat(auto-fill,minmax(220px,1fr))' : 'repeat(' . max(2, min(4, (int) $rcCols)) . ',1fr)';
  $rcPhotoTiles = ($c['tile_style'] ?? 'photo') !== 'compact';
""" + style_php('p-rcat', 'rental-categories') + "\n@endphp",
    "public categories: style php")
sub(P,
    """<section class="p-section" id="rental-categories" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>""",
    """<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">""",
    "public categories: section tag")
sub(P,
    """      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif""",
    """      @if(!empty($c['heading']))<h2 class="p-section-heading rs-head">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p class="rs-body" style="max-width:560px;margin:10px auto 0;opacity:.85;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif""",
    "public categories: heading colors")
sub(P,
    """    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:32px">""",
    """    <div style="display:grid;grid-template-columns:{{ $rcGrid }};gap:16px;margin-top:32px" class="rc-grid">""",
    "public categories: grid columns")
sub(P,
    """           style="display:block;border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);background:rgba(255,255,255,.6);text-decoration:none;color:inherit;overflow:hidden">
          @if($cat->rc_tile_image)""",
    """           class="rs-card"
           style="display:block;border-width:1.5px;border-style:solid;border-radius:var(--p-r-lg,14px);text-decoration:none;color:inherit;overflow:hidden">
          @if($rcPhotoTiles && $cat->rc_tile_image)""",
    "public categories: card class + tile toggle")
sub(P,
    """          <div style="font-size:13px;margin-top:14px;font-weight:600">Check availability →</div>""",
    """          <div class="rs-accent" style="font-size:13px;margin-top:14px;font-weight:600">Check availability →</div>""",
    "public categories: accent link")
sub(P,
    """@if($rcCats->isNotEmpty())
<section""",
    """@if($rcCats->isNotEmpty())
<style>@media (max-width:720px){ .{{ $instId ?? '' }} .rc-grid { grid-template-columns:1fr 1fr !important; } }</style>
<section""",
    "public categories: mobile grid")

# --- browse public ---
P = 'resources/views/public/sections/_rental_browse.blade.php'
sub(P,
    """  $rbShowDeposit = ($c['show_deposit'] ?? '0') === '1';
@endphp""",
    """  $rbShowDeposit = ($c['show_deposit'] ?? '0') === '1';
  $rbBtn = '';
  if (!empty($c['button_bg']))   $rbBtn .= 'background:' . $c['button_bg'] . ';border-color:' . $c['button_bg'] . ';';
  if (!empty($c['button_text'])) $rbBtn .= 'color:' . $c['button_text'] . ';';
""" + style_php('p-rbrw', 'rental-browse') + "\n@endphp",
    "public browse: style php")
sub(P,
    """<section class="p-section" id="rental-browse" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>""",
    """<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">""",
    "public browse: section tag")
sub(P,
    """    <form method="GET" action="#rental-browse" """,
    """    <form method="GET" action="#{{ $anchorId }}" """,
    "public browse: form anchor follows id")
sub(P,
    """      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif""",
    """      @if(!empty($c['heading']))<h2 class="p-section-heading rs-head">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p class="rs-body" style="max-width:560px;margin:10px auto 0;opacity:.85;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif""",
    "public browse: heading colors")
sub(P,
    """      <button type="submit" class="p-btn p-btn--primary">Check</button>""",
    """      <button type="submit" class="p-btn p-btn--primary" @if($rbBtn) style="{{ $rbBtn }}" @endif>Check</button>""",
    "public browse: check button colors")
sub(P,
    """              <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:20px 22px;background:rgba(255,255,255,.6);display:flex;flex-direction:column">""",
    """              <div class="rs-card" style="border-width:1.5px;border-style:solid;border-radius:var(--p-r-lg,14px);padding:20px 22px;display:flex;flex-direction:column">""",
    "public browse: card class")
sub(P,
    """                  <a class="p-btn p-btn--primary" style="width:100%;text-align:center" href=""",
    """                  <a class="p-btn p-btn--primary" style="width:100%;text-align:center;{{ $rbBtn }}" href=""",
    "public browse: reserve button colors")
sub(P,
    """          <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin-bottom:12px;font-weight:650">{{ $catName }}</h3>""",
    """          <h3 class="rs-accent" style="font-size:13px;text-transform:uppercase;letter-spacing:.07em;opacity:.75;margin-bottom:12px;font-weight:650">{{ $catName }}</h3>""",
    "public browse: category heading accent")

print("Done. No migration needed.")
