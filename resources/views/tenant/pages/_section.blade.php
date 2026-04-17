@php
  $c    = $section->content ?? [];
  $type = $section->section_type;
  $typeLabels = [
    'nav'                    => 'Navigation bar',
    'hero'                   => 'Hero',
    'services'               => 'Services preview',
    'text_image'             => 'Text + image',
    'cta_banner'             => 'CTA banner',
    'image_gallery'          => 'Image gallery',
    'contact_form'           => 'Contact form',
    'booking_embed'          => 'Booking form embed',
    'footer'                 => 'Footer',
    'feature_grid'           => 'Feature grid',
    'step_timeline'          => 'Step timeline',
    'pricing_table'          => 'Pricing table',
    'faq_accordion'          => 'FAQ accordion',
    'testimonial_carousel'   => 'Testimonials',
    'logo_bar'               => 'Logo / trust bar',
    'comparison_table'       => 'Comparison table',
    'industry_pack_showcase' => 'Industry showcase',
    'stats_row'              => 'Stats row',
  ];
  $typeIcons = [
    'nav'=>'🧭','hero'=>'🖼','services'=>'⚙','text_image'=>'📝',
    'cta_banner'=>'📣','image_gallery'=>'🖼','contact_form'=>'✉',
    'booking_embed'=>'📅','footer'=>'⬇',
    'feature_grid'=>'▦','step_timeline'=>'🔢','pricing_table'=>'💲',
    'faq_accordion'=>'❓','testimonial_carousel'=>'💬','logo_bar'=>'⚑',
    'comparison_table'=>'📊','industry_pack_showcase'=>'🏷','stats_row'=>'📈',
  ];
@endphp

<div class="pb-section-block" data-section-id="{{ $section->id }}">

  <div class="pb-section-head">
    <span class="pb-drag-handle" title="Drag to reorder">⋮⋮</span>
    <span style="font-size:14px;margin-right:2px">{{ $typeIcons[$type] ?? '□' }}</span>
    <span class="pb-section-type">{{ $typeLabels[$type] ?? $type }}</span>
    @if(!$section->is_visible)
      <span style="font-size:10px;opacity:.3;background:rgba(255,255,255,.06);padding:2px 6px;border-radius:4px">Hidden</span>
    @endif
    <span class="pb-section-chevron">▾</span>
  </div>

  <div class="pb-section-body" data-section-id="{{ $section->id }}">

    {{-- Visibility toggle --}}
    <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;border-bottom:0.5px solid var(--ia-border);margin-bottom:12px">
      <span style="font-size:12px;opacity:.5">Section visible</span>
      <label style="cursor:pointer;display:flex;align-items:center;gap:6px">
        <input type="checkbox" data-field="is_visible" value="1"
          {{ $section->is_visible ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
        <span style="font-size:11px;opacity:.4">{{ $section->is_visible ? 'On' : 'Off' }}</span>
      </label>
    </div>

    {{-- ============================================================ NAVIGATION BAR ============================================================ --}}
    @if($type === 'nav')
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show logo</span>
        <input type="checkbox" data-field="show_logo" value="1"
          {{ ($c['show_logo'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">CTA button label</div>
          <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? 'Book Now' }}">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">CTA button URL</div>
          <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '/book' }}">
        </div>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Background style</div>
        <select class="pb-input" data-field="bg_style">
          <option value="solid" {{ ($c['bg_style'] ?? 'solid') === 'solid' ? 'selected' : '' }}>Solid</option>
          <option value="transparent" {{ ($c['bg_style'] ?? 'solid') === 'transparent' ? 'selected' : '' }}>Transparent (overlaps hero)</option>
        </select>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Nav height</div>
        <select class="pb-input" data-field="nav_height">
          <option value="compact" {{ ($c['nav_height'] ?? 'normal') === 'compact' ? 'selected' : '' }}>Compact (52px)</option>
          <option value="normal" {{ ($c['nav_height'] ?? 'normal') === 'normal' ? 'selected' : '' }}>Normal (64px)</option>
          <option value="tall" {{ ($c['nav_height'] ?? 'normal') === 'tall' ? 'selected' : '' }}>Tall (80px)</option>
        </select>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Background color</div>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="color" data-field="bg_color" value="{{ ($c['bg_color'] ?? null) ?: '#ffffff' }}"
            style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
          <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['bg_color'] ?? '' }}" placeholder="Default" data-field="bg_color_text">
        </div>
        <div style="font-size:10px;opacity:.3;margin-top:2px">Leave blank to use site background color</div>
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show CTA on mobile</span>
        <input type="checkbox" data-field="show_cta_mobile" value="1"
          {{ ($c['show_cta_mobile'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>

    {{-- ============================================================ HERO ============================================================ --}}
    @elseif($type === 'hero')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow <span style="opacity:.4">(small uppercase label above headline)</span></div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. Built for service businesses">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Headline</div>
        <input type="text" class="pb-input" data-field="headline" value="{{ $c['headline'] ?? '' }}" placeholder="Your main headline">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Highlight words <span style="opacity:.4">(shown in accent color inside headline)</span></div>
        <input type="text" class="pb-input" data-field="accent_words" value="{{ $c['accent_words'] ?? '' }}" placeholder='e.g. "bike shops, ski shops,"'>
        <div style="font-size:10px;opacity:.3;margin-top:2px">The exact phrase here, if found in the headline, will be colored with the accent color.</div>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2" placeholder="Supporting text below the headline">{{ $c['subheading'] ?? '' }}</textarea>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="bg_color" value="{{ $c['bg_color'] ?? '#1a1a1a' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['bg_color'] ?? '#1a1a1a' }}" data-field="bg_color_text">
          </div>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Text color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="text_color" value="{{ $c['text_color'] ?? '#ffffff' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['text_color'] ?? '#ffffff' }}" data-field="text_color_text">
          </div>
        </div>
      </div>

      <div class="pb-field-row">
        <div class="pb-field-label">Text alignment</div>
        <select class="pb-input" data-field="text_align">
          <option value="left" {{ ($c['text_align'] ?? 'left') === 'left' ? 'selected' : '' }}>Left</option>
          <option value="center" {{ ($c['text_align'] ?? 'left') === 'center' ? 'selected' : '' }}>Center</option>
          <option value="right" {{ ($c['text_align'] ?? 'left') === 'right' ? 'selected' : '' }}>Right</option>
        </select>
      </div>

      <div class="pb-field-row">
        <div class="pb-field-label">Height</div>
        <select class="pb-input" data-field="height">
          @foreach(['small'=>'Small (380px)','medium'=>'Medium (520px)','large'=>'Large (680px)','fullscreen'=>'Fullscreen'] as $h => $label)
            <option value="{{ $h }}" {{ ($c['height'] ?? 'large') === $h ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </select>
      </div>

      <div style="border-top:0.5px solid var(--ia-border);margin:12px 0;padding-top:12px">
        <div class="pb-field-label" style="margin-bottom:8px">Primary button</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div class="pb-field-row">
            <div class="pb-field-label">Label</div>
            <input type="text" class="pb-input" data-field="cta_primary_label" value="{{ $c['cta_primary_label'] ?? 'Book Now' }}">
          </div>
          <div class="pb-field-row">
            <div class="pb-field-label">URL</div>
            <input type="text" class="pb-input" data-field="cta_primary_url" value="{{ $c['cta_primary_url'] ?? '/book' }}">
          </div>
        </div>
      </div>

      <div style="border-top:0.5px solid var(--ia-border);margin:8px 0;padding-top:12px">
        <div class="pb-field-label" style="margin-bottom:8px">Secondary button <span style="opacity:.4">(optional)</span></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div class="pb-field-row">
            <div class="pb-field-label">Label</div>
            <input type="text" class="pb-input" data-field="cta_secondary_label" value="{{ $c['cta_secondary_label'] ?? '' }}" placeholder="e.g. View Services">
          </div>
          <div class="pb-field-row">
            <div class="pb-field-label">URL</div>
            <input type="text" class="pb-input" data-field="cta_secondary_url" value="{{ $c['cta_secondary_url'] ?? '' }}" placeholder="#services">
          </div>
        </div>
      </div>

      <div class="pb-field-row">
        <div class="pb-field-label">Footnote / disclaimer</div>
        <input type="text" class="pb-input" data-field="note" value="{{ $c['note'] ?? '' }}" placeholder="e.g. Free 14-day trial · No credit card required">
      </div>

    {{-- ============================================================ SERVICES ============================================================ --}}
    @elseif($type === 'services')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Our services' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <input type="text" class="pb-input" data-field="subheading" value="{{ $c['subheading'] ?? '' }}" placeholder="Optional subheading text">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Columns</div>
          <select class="pb-input" data-field="columns">
            @foreach([1,2,3,4] as $col)
              <option value="{{ $col }}" {{ ($c['columns'] ?? 3) == $col ? 'selected' : '' }}>{{ $col }} column{{ $col > 1 ? 's' : '' }}</option>
            @endforeach
          </select>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Card style</div>
          <select class="pb-input" data-field="card_style">
            <option value="bordered" {{ ($c['card_style'] ?? 'bordered') === 'bordered' ? 'selected' : '' }}>Bordered</option>
            <option value="filled" {{ ($c['card_style'] ?? 'bordered') === 'filled' ? 'selected' : '' }}>Filled</option>
            <option value="minimal" {{ ($c['card_style'] ?? 'bordered') === 'minimal' ? 'selected' : '' }}>Minimal (no border)</option>
          </select>
        </div>
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show prices</span>
        <input type="checkbox" data-field="show_prices" value="1"
          {{ ($c['show_prices'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show descriptions</span>
        <input type="checkbox" data-field="show_descriptions" value="1"
          {{ ($c['show_descriptions'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show "Book now" button</span>
        <input type="checkbox" data-field="show_book_btn" value="1"
          {{ ($c['show_book_btn'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading alignment</div>
        <select class="pb-input" data-field="heading_align">
          <option value="left" {{ ($c['heading_align'] ?? 'left') === 'left' ? 'selected' : '' }}>Left</option>
          <option value="center" {{ ($c['heading_align'] ?? 'left') === 'center' ? 'selected' : '' }}>Center</option>
        </select>
      </div>

    {{-- ============================================================ TEXT + IMAGE ============================================================ --}}
    @elseif($type === 'text_image')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="Optional small label">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="Section heading">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Body text</div>
        <textarea class="pb-textarea" data-field="body" rows="5" placeholder="Your content here. Supports multiple paragraphs.">{{ $c['body'] ?? '' }}</textarea>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Image URL</div>
        <input type="url" class="pb-input" data-field="image_url" value="{{ $c['image_url'] ?? '' }}" placeholder="https://…">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Image position</div>
        <select class="pb-input" data-field="image_position">
          <option value="right" {{ ($c['image_position'] ?? 'right') === 'right' ? 'selected' : '' }}>Right</option>
          <option value="left" {{ ($c['image_position'] ?? 'right') === 'left' ? 'selected' : '' }}>Left</option>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Button label <span style="opacity:.4">(optional)</span></div>
          <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? '' }}" placeholder="Learn more">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Button URL</div>
          <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '' }}" placeholder="/about">
        </div>
      </div>

    {{-- ============================================================ CTA BANNER ============================================================ --}}
    @elseif($type === 'cta_banner')
      <div class="pb-field-row">
        <div class="pb-field-label">Headline</div>
        <input type="text" class="pb-input" data-field="headline" value="{{ $c['headline'] ?? 'Ready to book?' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <input type="text" class="pb-input" data-field="subheading" value="{{ $c['subheading'] ?? '' }}" placeholder="Optional supporting text">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Button label</div>
          <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? 'Book Now' }}">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Button URL</div>
          <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '/book' }}">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="bg_color" value="{{ ($c['bg_color'] ?? null) ?: '#BEF264' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['bg_color'] ?? '' }}" placeholder="Accent" data-field="bg_color_text">
          </div>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Text color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="text_color" value="{{ ($c['text_color'] ?? null) ?: '#000000' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['text_color'] ?? '' }}" placeholder="Auto" data-field="text_color_text">
          </div>
        </div>
      </div>

    {{-- ============================================================ CONTACT FORM ============================================================ --}}
    @elseif($type === 'contact_form')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Get in touch' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <input type="text" class="pb-input" data-field="subheading" value="{{ $c['subheading'] ?? '' }}" placeholder="Optional subheading">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show phone field</span>
        <input type="checkbox" data-field="show_phone" value="1"
          {{ ($c['show_phone'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show message field</span>
        <input type="checkbox" data-field="show_message" value="1"
          {{ ($c['show_message'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>

    {{-- ============================================================ BOOKING EMBED ============================================================ --}}
    @elseif($type === 'booking_embed')
      <div class="pb-field-row">
        <div class="pb-field-label">Section heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Book online' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Button text</div>
        <input type="text" class="pb-input" data-field="btn_label" value="{{ $c['btn_label'] ?? 'Book an appointment' }}">
      </div>

    {{-- ============================================================ FOOTER ============================================================ --}}
    @elseif($type === 'footer')
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show logo</span>
        <input type="checkbox" data-field="show_logo" value="1"
          {{ ($c['show_logo'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Copyright text</div>
        <input type="text" class="pb-input" data-field="copyright_text"
          value="{{ $c['copyright_text'] ?? '' }}"
          placeholder="© {{ date('Y') }} {{ $currentTenant->name ?? 'Intake' }}. All rights reserved.">
      </div>

    {{-- ============================================================ IMAGE GALLERY ============================================================ --}}
    @elseif($type === 'image_gallery')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="Optional heading">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Columns</div>
          <select class="pb-input" data-field="columns">
            @foreach([2,3,4,5] as $col)
              <option value="{{ $col }}" {{ ($c['columns'] ?? 3) == $col ? 'selected' : '' }}>{{ $col }}</option>
            @endforeach
          </select>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Image shape</div>
          <select class="pb-input" data-field="image_shape">
            <option value="square" {{ ($c['image_shape'] ?? 'square') === 'square' ? 'selected' : '' }}>Square (1:1)</option>
            <option value="landscape" {{ ($c['image_shape'] ?? 'square') === 'landscape' ? 'selected' : '' }}>Landscape (4:3)</option>
            <option value="portrait" {{ ($c['image_shape'] ?? 'square') === 'portrait' ? 'selected' : '' }}>Portrait (3:4)</option>
          </select>
        </div>
      </div>

    {{-- ============================================================ FEATURE GRID ============================================================ --}}
    @elseif($type === 'feature_grid')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. Everything included">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. One platform, zero chaos">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Columns</div>
        <select class="pb-input" data-field="columns">
          @foreach([2,3,4] as $col)
            <option value="{{ $col }}" {{ ($c['columns'] ?? 3) == $col ? 'selected' : '' }}>{{ $col }}</option>
          @endforeach
        </select>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Features (JSON)</div>
        <textarea class="pb-textarea" data-field="features_json" rows="10"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';this.dataset.valid='1';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=features]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message;this.dataset.valid='0'}">{{ json_encode($c['features'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Edit the list above as JSON.</div>
        <input type="hidden" data-field="features" value="{{ json_encode($c['features'] ?? []) }}">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;border-top:0.5px solid var(--ia-border);margin-top:10px;padding-top:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">CTA label <span style="opacity:.4">(optional)</span></div>
          <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? '' }}" placeholder="See all features">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">CTA URL</div>
          <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '' }}" placeholder="/features">
        </div>
      </div>

    {{-- ============================================================ STEP TIMELINE ============================================================ --}}
    @elseif($type === 'step_timeline')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. How it works">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. Up and running in minutes">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Steps (JSON)</div>
        <textarea class="pb-textarea" data-field="steps_json" rows="10"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=steps]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['steps'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Each step: {{ '{"title":"...", "desc":"...", "done":true/false}' }}</div>
        <input type="hidden" data-field="steps" value="{{ json_encode($c['steps'] ?? []) }}">
      </div>

    {{-- ============================================================ PRICING TABLE ============================================================ --}}
    @elseif($type === 'pricing_table')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. Pricing">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. Simple plans, no surprises">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Source</div>
          <select class="pb-input" data-field="source">
            <option value="config" {{ ($c['source'] ?? 'config') === 'config' ? 'selected' : '' }}>Config (plan_prices)</option>
            <option value="manual" {{ ($c['source'] ?? 'config') === 'manual' ? 'selected' : '' }}>Manual (edit JSON below)</option>
          </select>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Featured plan</div>
          <select class="pb-input" data-field="featured">
            <option value="basic"   {{ ($c['featured'] ?? 'branded') === 'basic'   ? 'selected' : '' }}>Basic</option>
            <option value="branded" {{ ($c['featured'] ?? 'branded') === 'branded' ? 'selected' : '' }}>Branded</option>
            <option value="custom"  {{ ($c['featured'] ?? 'branded') === 'custom'  ? 'selected' : '' }}>Custom</option>
          </select>
        </div>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Footnote</div>
        <input type="text" class="pb-input" data-field="footnote" value="{{ $c['footnote'] ?? '' }}" placeholder="e.g. All plans include a 14-day free trial.">
      </div>
      @if(($c['source'] ?? 'config') === 'manual')
        <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
          <div class="pb-field-label" style="margin-bottom:8px">Plans (JSON, manual source only)</div>
          <textarea class="pb-textarea" data-field="plans_json" rows="10"
            style="font-family:monospace;font-size:11px"
            oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=plans]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['plans'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
          <div style="font-size:10px;opacity:.5;margin-top:4px">Each plan: slug, name, price_cents, period, desc, features[], cta_label</div>
          <input type="hidden" data-field="plans" value="{{ json_encode($c['plans'] ?? []) }}">
        </div>
      @endif

    {{-- ============================================================ FAQ ACCORDION ============================================================ --}}
    @elseif($type === 'faq_accordion')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. Questions">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. Frequently asked">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Questions (JSON)</div>
        <textarea class="pb-textarea" data-field="items_json" rows="10"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=items]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['items'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Each item: {{ '{"q":"Question", "a":"Answer"}' }}</div>
        <input type="hidden" data-field="items" value="{{ json_encode($c['items'] ?? []) }}">
      </div>

    {{-- ============================================================ TESTIMONIAL CAROUSEL ============================================================ --}}
    @elseif($type === 'testimonial_carousel')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. Testimonials">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Testimonials (JSON)</div>
        <textarea class="pb-textarea" data-field="testimonials_json" rows="10"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=testimonials]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['testimonials'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Each: quote, author, role, avatar_url</div>
        <input type="hidden" data-field="testimonials" value="{{ json_encode($c['testimonials'] ?? []) }}">
      </div>

    {{-- ============================================================ LOGO BAR ============================================================ --}}
    @elseif($type === 'logo_bar')
      <div class="pb-field-row">
        <div class="pb-field-label">Label</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. Trusted by shops like">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Shop names <span style="opacity:.4">(comma-separated)</span></div>
        <textarea class="pb-textarea" data-field="shop_names_csv" rows="2"
          oninput="var arr=this.value.split(',').map(function(s){return s.trim()}).filter(Boolean);this.closest('.pb-section-body').querySelector('[data-field=shop_names]').value=JSON.stringify(arr)">{{ implode(', ', $c['shop_names'] ?? []) }}</textarea>
        <input type="hidden" data-field="shop_names" value="{{ json_encode($c['shop_names'] ?? []) }}">
        <div style="font-size:10px;opacity:.5;margin-top:4px">Or leave blank and use image logos via JSON below.</div>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Image logos (JSON, optional)</div>
        <textarea class="pb-textarea" data-field="logos_json" rows="6"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=logos]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['logos'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Each: {{ '{"url":"...", "alt":"..."}' }}</div>
        <input type="hidden" data-field="logos" value="{{ json_encode($c['logos'] ?? []) }}">
      </div>

    {{-- ============================================================ COMPARISON TABLE ============================================================ --}}
    @elseif($type === 'comparison_table')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. How we compare">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Competitor names <span style="opacity:.4">(comma-separated, first = you)</span></div>
        <input type="text" class="pb-input" data-field="competitors_csv" value="{{ implode(', ', $c['competitors'] ?? ['Intake']) }}"
          oninput="var arr=this.value.split(',').map(function(s){return s.trim()}).filter(Boolean);this.closest('.pb-section-body').querySelector('[data-field=competitors]').value=JSON.stringify(arr)">
        <input type="hidden" data-field="competitors" value="{{ json_encode($c['competitors'] ?? ['Intake']) }}">
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Rows (JSON)</div>
        <textarea class="pb-textarea" data-field="rows_json" rows="10"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=rows]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['rows'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Each row: {{ '{"feature":"Name", "values":["yes","no","extra"]}' }} — values match competitor order.</div>
        <input type="hidden" data-field="rows" value="{{ json_encode($c['rows'] ?? []) }}">
      </div>

    {{-- ============================================================ INDUSTRY PACK SHOWCASE ============================================================ --}}
    @elseif($type === 'industry_pack_showcase')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}" placeholder="e.g. Industry-specific">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="e.g. Built for your industry">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Industries to show</div>
          <input type="number" class="pb-input" data-field="limit" value="{{ $c['limit'] ?? 12 }}" min="1" max="24">
        </div>
        <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between;margin-top:18px">
          <span style="font-size:12px;opacity:.5">"See all" link</span>
          <input type="checkbox" data-field="show_all_link" value="1"
            {{ ($c['show_all_link'] ?? true) ? 'checked' : '' }}
            onchange="this.value=this.checked?1:0">
        </div>
      </div>
      <div style="padding:10px;background:rgba(255,255,255,.03);border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);margin-top:8px">
        <div style="font-size:11px;opacity:.4">Industries pull from <code>config/industry_packs.php</code>. Add more there to have them appear.</div>
      </div>

    {{-- ============================================================ STATS ROW ============================================================ --}}
    @elseif($type === 'stats_row')
      <div class="pb-field-row">
        <div class="pb-field-label">Eyebrow</div>
        <input type="text" class="pb-input" data-field="eyebrow" value="{{ $c['eyebrow'] ?? '' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Heading <span style="opacity:.4">(optional)</span></div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}">
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Stats (JSON)</div>
        <textarea class="pb-textarea" data-field="stats_json" rows="8"
          style="font-family:monospace;font-size:11px"
          oninput="try{this.nextElementSibling.textContent='✓ Valid';var arr=JSON.parse(this.value);this.closest('.pb-section-body').querySelector('[data-field=stats]').value=JSON.stringify(arr);}catch(e){this.nextElementSibling.textContent='⚠ '+e.message}">{{ json_encode($c['stats'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <div style="font-size:10px;opacity:.5;margin-top:4px">Each stat: {{ '{"number":"200+", "label":"Shops"}' }}</div>
        <input type="hidden" data-field="stats" value="{{ json_encode($c['stats'] ?? []) }}">
      </div>

    @else
      <p style="font-size:13px;opacity:.4">No editor available for this section type.</p>
    @endif

    {{-- Section footer --}}
    <div class="pb-section-actions">
      <span style="font-size:11px;opacity:.25">Auto-saves as you type</span>
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm pb-delete-section"
        data-section-id="{{ $section->id }}" style="font-size:11px">
        Delete section
      </button>
    </div>

  </div>
</div>
