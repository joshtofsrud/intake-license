{{--
  nav editor (Phase 2)
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

    {{-- tenant picks which logo shows; no background guessing --}}
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

  {{-- the one menu control for the whole site. Built by
       initNavLinkList() in pages/edit.blade.php from the JSON below; saved
       with the section's Save button (op=update_nav). --}}
  @php
    $snT     = $currentTenant ?? tenant();
    $snRows  = \App\Support\ShopNav::rows((string) $snT->id);
    $snPages = \App\Support\ShopNav::pages((string) $snT->id);
    $snApps  = collect([
        ['label' => 'Book',    'url' => '/book',    'on' => true],
        ['label' => 'Shop',    'url' => '/shop',    'on' => $snT->online_store_enabled && (bool) (($snT->settings['storefront']['enabled'] ?? true))],
        ['label' => 'Rentals', 'url' => '/rentals', 'on' => (bool) ($snT->rentals_visible ?? false)],
    ])->filter(fn ($d) => $d['on'])->values()->map(fn ($d) => ['label' => $d['label'], 'url' => $d['url']])->all();
  @endphp
  <div class="pb2-group">
    <div class="pb2-group-title">Menu <span class="pb2-group-meta" id="pb2-nav-links-count">{{ count($snRows) }}</span></div>
    <div class="pb2-field-hint sn-legend" style="text-align:left;display:block">
      <b>This list is your site’s menu</b> — every page, desktop and phone. Nothing else changes it.
      Page items follow their page and hide while it’s unpublished. Buttons are just items styled as a button.
      Live once you Save.
    </div>
    {{-- MARKER-SHOP-MENU-GROUPS: drawn by initNavLinkList() --}}
    @unless($isMarketing ?? false)<div class="mg-box" id="pb2-nav-groups"></div>@endunless
    <div id="pb2-nav-linklist"
         data-rows="{{ json_encode($snRows) }}"
         data-groups="{{ json_encode(\App\Support\ShopNav::groups($snT)) }}"
         data-icons="{{ json_encode(\App\Support\ShopNav::icons()) }}"
         data-pages="{{ json_encode($snPages) }}"
         data-apps="{{ json_encode($snApps) }}"></div>
    <div class="pb2-navlist-status" id="pb2-nav-status" style="margin-top:8px"></div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Customer account</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_account" value="1" {{ $get('show_account', true) ? 'checked' : '' }}>
      <span>Show account link</span>
    </label>

    <div class="pb2-field-hint" style="margin-top:6px">Lets customers sign in to see their bookings, orders, rentals and messages.</div>
  </div>

  {{-- the header button is now an item in the Menu above
       (style: Button), not a separate setting. --}}

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

    {{-- independent logo size control --}}
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

@unless($isMarketing ?? false)
  {{-- MARKER-SHOP-FLOAT-NAV: header style, same settings as the
       intake.works header. Desktop and phone are set up separately; the
       phone uses the desktop settings unless "Different on phones" is on.
       Shown/hidden by data attributes on .pnf (the inspector is injected as
       HTML, so inline handlers, not scripts). --}}
  @php
    $pnf  = \App\Support\ShopNavHeader::resolve($c);
    $pnfD = $pnf['d'];
    $pnfP = $pnf['p'];
    $pnfRange = function (string $field, int $min, int $max, int $step, int $val, string $unit, string $zero = '') {
        $show = ($zero !== '' && $val === 0) ? $zero : $val . $unit;
        $js = "var o=this.nextElementSibling;o.textContent=" . ($zero !== '' ? "(this.value==0?'" . $zero . "':this.value+'" . $unit . "')" : "this.value+'" . $unit . "'")
            . ";var h=this.closest('.pb2-field').querySelector('[data-field=" . $field . "]');h.value=this.value;h.dispatchEvent(new Event('change',{bubbles:true}));";
        return '<div class="pnf-range"><input type="range" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $val . '" oninput="' . e($js) . '"><output>' . e($show) . '</output></div>'
            . '<input type="hidden" data-field="' . e($field) . '" value="' . $val . '">';
    };
  @endphp
  <style>
    .pnf[data-screen="desktop"] .pnf-p, .pnf[data-screen="phone"] .pnf-d { display: none; }
    .pnf[data-dstyle="classic"] .pnf-d .pnf-fo { display: none; }
    .pnf[data-pstyle="classic"] .pnf-p .pnf-fo { display: none; }
    .pnf[data-own="0"] .pnf-own { display: none; }
    .pnf[data-own="1"] .pnf-same { display: none; }
    .pnf .pnf-range { display: flex; align-items: center; gap: 12px; }
    .pnf .pnf-range input[type=range] { flex: 1; min-width: 0; }
    .pnf .pnf-range output { min-width: 44px; text-align: right; font-variant-numeric: tabular-nums; font-size: 12.5px; opacity: .75; }
    .pnf .pnf-auto { background: none; border: 0; padding: 0 0 0 6px; font: inherit; font-size: 11.5px; text-decoration: underline; color: inherit; opacity: .7; cursor: pointer; }
    .pnf .pnf-sub { margin-top: 14px; padding-top: 12px; border-top: 1px solid rgba(127,127,127,.15); }
  </style>
  <div class="pb2-group pnf" data-screen="desktop" data-dstyle="{{ $pnfD['style'] }}" data-pstyle="{{ $pnfP['style'] }}" data-own="{{ $pnfP['own'] ? '1' : '0' }}">
    <div class="pb2-group-title">Header style</div>

    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="__pnf_screen">
        <button type="button" class="pb2-seg-btn active" data-seg-value="desktop"
                onclick="this.closest('.pnf').dataset.screen='desktop';var d=document.querySelector('.pb2-device-btn[data-device=desktop]');if(d)d.click();">Desktop</button>
        <button type="button" class="pb2-seg-btn" data-seg-value="phone"
                onclick="this.closest('.pnf').dataset.screen='phone';var d=document.querySelector('.pb2-device-btn[data-device=mobile]');if(d)d.click();">Phone</button>
      </div>
      <div class="pb2-field-hint" style="text-align:left;display:block;margin-top:4px">Which screen you're setting up. The preview switches with it.</div>
    </div>

    @foreach(['d' => 'fl_', 'p' => 'flp_'] as $pnfK => $px)
      @php $h = $pnfK === 'd' ? $pnfD : $pnfP; @endphp
      <div class="pnf-{{ $pnfK }}">
        @if($pnfK === 'p')
          <label class="pb2-checkbox-row">
            <input type="checkbox" data-field="flp_own" value="1" {{ $pnfP['own'] ? 'checked' : '' }}
                   onchange="this.closest('.pnf').dataset.own=this.checked?'1':'0'">
            <span>Different on phones</span>
          </label>
          <div class="pb2-field-hint pnf-same" style="text-align:left;display:block;margin-top:4px">Phones use the desktop settings.</div>
        @endif

        <div class="{{ $pnfK === 'p' ? 'pnf-own' : '' }}">
          <div class="pb2-field">
            <label class="pb2-field-label">Style</label>
            @php $sf = $pnfK === 'd' ? 'hdr_style' : 'flp_style'; $sk = $pnfK === 'd' ? 'dstyle' : 'pstyle'; @endphp
            <div class="pb2-seg" data-field-seg="{{ $sf }}">
              @foreach(['classic' => 'Classic', 'float' => 'Floating'] as $val => $name)
                <button type="button" class="pb2-seg-btn {{ $h['style'] === $val ? 'active' : '' }}" data-seg-value="{{ $val }}"
                        onclick="this.closest('.pnf').dataset.{{ $sk }}='{{ $val }}'">{{ $name }}</button>
              @endforeach
            </div>
            <input type="hidden" data-field="{{ $sf }}" value="{{ $h['style'] }}">
          </div>

          <div class="pnf-fo">
            <div class="pb2-field">
              <label class="pb2-field-label">Bar color</label>
              <div class="pb2-color-row">
                <input type="color" data-field="{{ $px }}bg" value="{{ $h['bg'] }}" class="pb2-color-swatch">
              </div>
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">Opacity</label>
              {!! $pnfRange($px . 'opacity', 0, 100, 5, $h['opacity'], '%') !!}
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">Blur <span class="pb2-field-hint">frosted glass behind the bar</span></label>
              {!! $pnfRange($px . 'blur', 0, 30, 1, $h['blur'], 'px') !!}
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">Spacing</label>
              <div class="pb2-seg" data-field-seg="{{ $px }}space">
                @foreach(['tight' => 'Tight', 'normal' => 'Normal', 'roomy' => 'Roomy'] as $val => $name)
                  <button type="button" class="pb2-seg-btn {{ $h['space'] === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
                @endforeach
              </div>
              <input type="hidden" data-field="{{ $px }}space" value="{{ $h['space'] }}">
            </div>
            {{-- MARKER-SHOP-NAV-CORNERS --}}
            <div class="pb2-field">
              <label class="pb2-field-label">Corners <span class="pb2-field-hint">the bar, its links and the phone menu</span></label>
              <div class="pb2-seg" data-field-seg="{{ $px }}corners">
                @foreach(['round' => 'Round', 'soft' => 'Soft', 'square' => 'Square'] as $val => $name)
                  <button type="button" class="pb2-seg-btn {{ $h['corners'] === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
                @endforeach
              </div>
              <input type="hidden" data-field="{{ $px }}corners" value="{{ $h['corners'] }}">
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">From top
                <button type="button" class="pnf-auto"
                        onclick="var f=this.closest('.pb2-field');var h=f.querySelector('[data-field={{ $px }}top_gap]');h.value='-1';f.querySelector('output').textContent='auto';h.dispatchEvent(new Event('change',{bubbles:true}));">Auto</button>
              </label>
              <div class="pnf-range">
                <input type="range" min="0" max="80" step="2" value="{{ $h['top_gap'] >= 0 ? $h['top_gap'] : ($pnfK === 'd' ? 18 : 10) }}"
                       oninput="this.nextElementSibling.textContent=this.value+'px';var h=this.closest('.pb2-field').querySelector('[data-field={{ $px }}top_gap]');h.value=this.value;h.dispatchEvent(new Event('change',{bubbles:true}));">
                <output>{{ $h['top_gap'] >= 0 ? $h['top_gap'] . 'px' : 'auto' }}</output>
              </div>
              <input type="hidden" data-field="{{ $px }}top_gap" value="{{ $h['top_gap'] }}">
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">Edge room <span class="pb2-field-hint">inside the bar, left and right</span></label>
              {!! $pnfRange($px . 'pad_x', 0, 60, 2, $h['pad_x'], 'px', 'auto') !!}
            </div>
            <label class="pb2-checkbox-row">
              <input type="checkbox" data-field="{{ $px }}fade" value="1" {{ $h['fade'] ? 'checked' : '' }}>
              <span>Fade the page under the bar</span>
            </label>
            <div class="pb2-field">
              <label class="pb2-field-label">Link pill <span class="pb2-field-hint">the shape behind the links</span></label>
              <div class="pb2-color-row">
                <input type="color" data-field="{{ $px }}pill" value="{{ $h['pill'] }}" class="pb2-color-swatch">
              </div>
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">Pill strength</label>
              {!! $pnfRange($px . 'pill_strength', 0, 30, 1, $h['pill_strength'], '%') !!}
            </div>
          </div>
        </div>

        @if($pnfK === 'p')
          <div class="pnf-sub">
            <div class="pb2-field">
              <label class="pb2-field-label">Header buttons on phones</label>
              <div class="pb2-seg" data-field-seg="flp_btn_pos">
                @foreach(['bar' => 'In the bar', 'menu' => 'In menu only'] as $val => $name)
                  <button type="button" class="pb2-seg-btn {{ $pnfP['btn_pos'] === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
                @endforeach
              </div>
              <input type="hidden" data-field="flp_btn_pos" value="{{ $pnfP['btn_pos'] }}">
            </div>
            <div class="pb2-field">
              <label class="pb2-field-label">Distance from ☰ <span class="pb2-field-hint">when in the bar</span></label>
              {!! $pnfRange('flp_btn_dist', 0, 200, 2, $pnfP['btn_dist'], 'px') !!}
            </div>
          </div>
        @endif
      </div>
    @endforeach

    <div class="pb2-field-hint" style="text-align:left;display:block;margin-top:12px">
      <b>What Floating changes:</b> the bar becomes a rounded glass pill that hovers over the page, and the first section starts
      behind it (its top padding grows just enough to clear the bar). On booking, rentals and account pages the bar floats
      but doesn't tuck over the page. Background and Border below apply to Classic only. Nothing else on your pages changes.
    </div>
  </div>

@endunless

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

@unless($isMarketing ?? false)
  {{-- MARKER-SHOP-FLOAT-NAV: link weight, header buttons and phone menu colors (both header styles) --}}
  @php $pnfL = \App\Support\ShopNavHeader::resolve($c); @endphp
  <div class="pb2-group">
    <div class="pb2-group-title">Links &amp; buttons</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Link weight</label>
        <div class="pb2-seg" data-field-seg="fl_link_weight">
          @foreach(['400' => 'Regular', '500' => 'Medium', '600' => 'Semibold'] as $val => $name)
            <button type="button" class="pb2-seg-btn {{ $pnfL['d']['link_weight'] === (string) $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
          @endforeach
        </div>
        <input type="hidden" data-field="fl_link_weight" value="{{ $pnfL['d']['link_weight'] }}">
      </div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Link brightness</label>
      <div class="pb2-seg" data-field-seg="fl_link_bright">
        @foreach(['soft' => 'Soft', 'bright' => 'Bright'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $pnfL['d']['link_bright'] === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="fl_link_bright" value="{{ $pnfL['d']['link_bright'] }}">
    </div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Button text</label>
        <div class="pb2-color-row">
          <input type="color" data-field="fl_btn_text" value="{{ $pnfL['d']['btn_text'] ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="fl_btn_text_text" value="{{ $pnfL['d']['btn_text'] }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Button fill</label>
        <div class="pb2-color-row">
          <input type="color" data-field="fl_btn_fill" value="{{ $pnfL['d']['btn_fill'] ?: '#0a0a0a' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="fl_btn_fill_text" value="{{ $pnfL['d']['btn_fill'] }}" placeholder="auto">
        </div>
      </div>
    </div>
    <div class="pb2-field-hint" style="text-align:left;display:block;margin-top:4px">Buttons are menu items styled as a button. These colors apply in the bar and in the phone menu. Clear a box to go back to auto.</div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Phone menu</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Panel</label>
        <div class="pb2-color-row">
          <input type="color" data-field="flp_menu_bg" value="{{ $pnfL['p']['menu_bg'] ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="flp_menu_bg_text" value="{{ $pnfL['p']['menu_bg'] }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Links</label>
        <div class="pb2-color-row">
          <input type="color" data-field="flp_menu_link" value="{{ $pnfL['p']['menu_link'] ?: '#0a0a0a' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="flp_menu_link_text" value="{{ $pnfL['p']['menu_link'] }}" placeholder="auto">
        </div>
      </div>
    </div>
    <div class="pb2-field-hint" style="text-align:left;display:block;margin-top:4px">The menu that opens from ☰. Auto follows the bar.</div>
  </div>

@endunless
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
