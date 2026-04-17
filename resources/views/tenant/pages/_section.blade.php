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

  // Defensive decode: some older rows have JSON strings where arrays are
  // expected. Normalize everything up front so implode() etc. never trip.
  $arrayFields = ['features','steps','plans','items','testimonials','shop_names','logos','competitors','rows','stats','images'];
  foreach ($arrayFields as $af) {
      if (isset($c[$af]) && is_string($c[$af])) {
          $decoded = json_decode($c[$af], true);
          $c[$af] = is_array($decoded) ? $decoded : [];
      }
  }
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
      <div class="pb-field-row">
        <div class="pb-field-label">Columns</div>
        <select class="pb-input" data-field="columns">
          @foreach([1,2,3,4] as $col)
            <option value="{{ $col }}" {{ ($c['columns'] ?? 3) == $col ? 'selected' : '' }}>{{ $col }} column{{ $col > 1 ? 's' : '' }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show prices</span>
        <input type="checkbox" data-field="show_prices" value="1"
          {{ ($c['show_prices'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
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
