@php
  $c    = $section->content ?? [];
  $type = $section->section_type;
  $typeLabels = [
    'nav'           => 'Navigation bar',
    'hero'          => 'Hero',
    'services'      => 'Services preview',
    'text_image'    => 'Text + image',
    'cta_banner'    => 'CTA banner',
    'image_gallery' => 'Image gallery',
    'contact_form'  => 'Contact form',
    'booking_embed' => 'Booking form embed',
    'footer'        => 'Footer',
  ];
  $typeIcons = [
    'nav'=>'🧭','hero'=>'🖼','services'=>'⚙','text_image'=>'📝',
    'cta_banner'=>'📣','image_gallery'=>'🖼','contact_form'=>'✉',
    'booking_embed'=>'📅','footer'=>'⬇',
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

    {{-- ============================================================
         NAVIGATION BAR
         ============================================================ --}}
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

    {{-- ============================================================
         HERO
         ============================================================ --}}
    @elseif($type === 'hero')
      <div class="pb-field-row">
        <div class="pb-field-label">Headline</div>
        <input type="text" class="pb-input" data-field="headline" value="{{ $c['headline'] ?? '' }}" placeholder="Your main headline">
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
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0"
              id="hero-bg-color-{{ $section->id }}"
              onchange="document.getElementById('hero-bg-hex-{{ $section->id }}').value=this.value">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace"
              id="hero-bg-hex-{{ $section->id }}" value="{{ $c['bg_color'] ?? '#1a1a1a' }}"
              onchange="document.getElementById('hero-bg-color-{{ $section->id }}').value=this.value;this.setAttribute('data-field','bg_color')" data-field="bg_color_text">
          </div>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Text color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="text_color" value="{{ $c['text_color'] ?? '#ffffff' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0"
              id="hero-text-color-{{ $section->id }}"
              onchange="document.getElementById('hero-text-hex-{{ $section->id }}').value=this.value">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace"
              id="hero-text-hex-{{ $section->id }}" value="{{ $c['text_color'] ?? '#ffffff' }}"
              onchange="document.getElementById('hero-text-color-{{ $section->id }}').value=this.value" data-field="text_color_text">
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

      <div style="border-top:0.5px solid var(--ia-border);margin:8px 0;padding-top:12px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background image</div>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" class="pb-input" data-field="bg_image_url" value="{{ $c['bg_image_url'] ?? '' }}" placeholder="https://… or upload" style="flex:1" id="hero-bg-url-{{ $section->id }}">
            <label class="ia-btn ia-btn--secondary ia-btn--sm" style="cursor:pointer;flex-shrink:0">
              Upload
              <input type="file" accept="image/*" style="display:none" onchange="uploadImage(this,'hero','hero-bg-url-{{ $section->id }}')">
            </label>
          </div>
          @if(!empty($c['bg_image_url']))
            <img src="{{ $c['bg_image_url'] }}" style="margin-top:8px;max-height:80px;border-radius:6px;opacity:.8">
          @endif
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Image overlay opacity</div>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="range" min="0" max="100" value="{{ $c['overlay_opacity'] ?? 45 }}" data-field="overlay_opacity"
              style="flex:1" oninput="this.nextElementSibling.textContent=this.value+'%'">
            <span style="font-size:11px;opacity:.4;min-width:32px">{{ $c['overlay_opacity'] ?? 45 }}%</span>
          </div>
          <div style="font-size:10px;opacity:.3;margin-top:2px">Darkens the image so text is readable</div>
        </div>
      </div>

      <div style="border-top:0.5px solid var(--ia-border);margin:8px 0;padding-top:12px">
        <div class="pb-field-label" style="margin-bottom:8px">Advanced</div>
        <div class="pb-field-row">
          <div class="pb-field-label">Content max width</div>
          <select class="pb-input" data-field="content_width">
            <option value="narrow" {{ ($c['content_width'] ?? 'normal') === 'narrow' ? 'selected' : '' }}>Narrow (480px)</option>
            <option value="normal" {{ ($c['content_width'] ?? 'normal') === 'normal' ? 'selected' : '' }}>Normal (680px)</option>
            <option value="wide" {{ ($c['content_width'] ?? 'normal') === 'wide' ? 'selected' : '' }}>Wide (900px)</option>
            <option value="full" {{ ($c['content_width'] ?? 'normal') === 'full' ? 'selected' : '' }}>Full width</option>
          </select>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Vertical alignment</div>
          <select class="pb-input" data-field="vertical_align">
            <option value="center" {{ ($c['vertical_align'] ?? 'center') === 'center' ? 'selected' : '' }}>Center</option>
            <option value="top" {{ ($c['vertical_align'] ?? 'center') === 'top' ? 'selected' : '' }}>Top</option>
            <option value="bottom" {{ ($c['vertical_align'] ?? 'center') === 'bottom' ? 'selected' : '' }}>Bottom</option>
          </select>
        </div>
      </div>

    {{-- ============================================================
         SERVICES
         ============================================================ --}}
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

    {{-- ============================================================
         TEXT + IMAGE
         ============================================================ --}}
    @elseif($type === 'text_image')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}" placeholder="Section heading">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Body text</div>
        <textarea class="pb-textarea" data-field="body" rows="5" placeholder="Your content here. Supports multiple paragraphs.">{{ $c['body'] ?? '' }}</textarea>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Image</div>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="url" class="pb-input" data-field="image_url" value="{{ $c['image_url'] ?? '' }}" placeholder="https://… or upload" style="flex:1" id="ti-img-{{ $section->id }}">
          <label class="ia-btn ia-btn--secondary ia-btn--sm" style="cursor:pointer;flex-shrink:0">
            Upload
            <input type="file" accept="image/*" style="display:none" onchange="uploadImage(this,'general','ti-img-{{ $section->id }}')">
          </label>
        </div>
        @if(!empty($c['image_url']))
          <img src="{{ $c['image_url'] }}" style="margin-top:8px;max-height:80px;border-radius:6px;opacity:.8">
        @endif
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="pb-field-row">
          <div class="pb-field-label">Image position</div>
          <select class="pb-input" data-field="image_position">
            <option value="right" {{ ($c['image_position'] ?? 'right') === 'right' ? 'selected' : '' }}>Right</option>
            <option value="left" {{ ($c['image_position'] ?? 'right') === 'left' ? 'selected' : '' }}>Left</option>
          </select>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Image aspect ratio</div>
          <select class="pb-input" data-field="image_ratio">
            <option value="4/3" {{ ($c['image_ratio'] ?? '4/3') === '4/3' ? 'selected' : '' }}>4:3 (landscape)</option>
            <option value="1/1" {{ ($c['image_ratio'] ?? '4/3') === '1/1' ? 'selected' : '' }}>1:1 (square)</option>
            <option value="3/4" {{ ($c['image_ratio'] ?? '4/3') === '3/4' ? 'selected' : '' }}>3:4 (portrait)</option>
            <option value="16/9" {{ ($c['image_ratio'] ?? '4/3') === '16/9' ? 'selected' : '' }}>16:9 (widescreen)</option>
          </select>
        </div>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Image border radius</div>
        <select class="pb-input" data-field="image_radius">
          <option value="none" {{ ($c['image_radius'] ?? 'normal') === 'none' ? 'selected' : '' }}>None (square corners)</option>
          <option value="normal" {{ ($c['image_radius'] ?? 'normal') === 'normal' ? 'selected' : '' }}>Normal (12px)</option>
          <option value="large" {{ ($c['image_radius'] ?? 'normal') === 'large' ? 'selected' : '' }}>Large (24px)</option>
          <option value="round" {{ ($c['image_radius'] ?? 'normal') === 'round' ? 'selected' : '' }}>Fully round</option>
        </select>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-label" style="margin-bottom:8px">Button <span style="opacity:.4">(optional)</span></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div class="pb-field-row">
            <div class="pb-field-label">Label</div>
            <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? '' }}" placeholder="Learn more">
          </div>
          <div class="pb-field-row">
            <div class="pb-field-label">URL</div>
            <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '' }}" placeholder="/about">
          </div>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Button style</div>
          <select class="pb-input" data-field="cta_style">
            <option value="primary" {{ ($c['cta_style'] ?? 'primary') === 'primary' ? 'selected' : '' }}>Primary (filled)</option>
            <option value="outline" {{ ($c['cta_style'] ?? 'primary') === 'outline' ? 'selected' : '' }}>Outline</option>
            <option value="text" {{ ($c['cta_style'] ?? 'primary') === 'text' ? 'selected' : '' }}>Text link</option>
          </select>
        </div>
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background color <span style="opacity:.4">(optional)</span></div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="section_bg" value="{{ $c['section_bg'] ?? '#ffffff' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['section_bg'] ?? '' }}" placeholder="Inherit" data-field="section_bg_text">
          </div>
        </div>
      </div>

    {{-- ============================================================
         CTA BANNER
         ============================================================ --}}
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
      <div class="pb-field-row">
        <div class="pb-field-label">Text alignment</div>
        <select class="pb-input" data-field="text_align">
          <option value="center" {{ ($c['text_align'] ?? 'center') === 'center' ? 'selected' : '' }}>Center</option>
          <option value="left" {{ ($c['text_align'] ?? 'center') === 'left' ? 'selected' : '' }}>Left</option>
          <option value="right" {{ ($c['text_align'] ?? 'center') === 'right' ? 'selected' : '' }}>Right</option>
        </select>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Padding size</div>
        <select class="pb-input" data-field="padding_size">
          <option value="small" {{ ($c['padding_size'] ?? 'normal') === 'small' ? 'selected' : '' }}>Small</option>
          <option value="normal" {{ ($c['padding_size'] ?? 'normal') === 'normal' ? 'selected' : '' }}>Normal</option>
          <option value="large" {{ ($c['padding_size'] ?? 'normal') === 'large' ? 'selected' : '' }}>Large</option>
        </select>
      </div>

    {{-- ============================================================
         CONTACT FORM
         ============================================================ --}}
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
        <span style="font-size:12px;opacity:.5">Show subject field</span>
        <input type="checkbox" data-field="show_subject" value="1"
          {{ ($c['show_subject'] ?? false) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Submit button text</div>
        <input type="text" class="pb-input" data-field="submit_label" value="{{ $c['submit_label'] ?? 'Send message' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Success message</div>
        <input type="text" class="pb-input" data-field="success_message" value="{{ $c['success_message'] ?? 'Thanks! We\'ll be in touch soon.' }}" placeholder="Message shown after submission">
      </div>

    {{-- ============================================================
         BOOKING EMBED
         ============================================================ --}}
    @elseif($type === 'booking_embed')
      <div class="pb-field-row">
        <div class="pb-field-label">Section heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Book online' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <input type="text" class="pb-input" data-field="subheading" value="{{ $c['subheading'] ?? '' }}" placeholder="Optional subheading">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Button text</div>
        <input type="text" class="pb-input" data-field="btn_label" value="{{ $c['btn_label'] ?? 'Book an appointment' }}">
      </div>
      <div style="padding:12px;background:rgba(255,255,255,.03);border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);margin-top:8px">
        <div style="font-size:11px;opacity:.3">The full multi-step booking form will be embedded here on the live site. Customize its appearance in the <strong>Intake Form Editor</strong>.</div>
      </div>

    {{-- ============================================================
         FOOTER
         ============================================================ --}}
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
          placeholder="© {{ date('Y') }} {{ $currentTenant->name }}. All rights reserved.">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show "Powered by Intake"</span>
        <input type="checkbox" data-field="show_powered_by" value="1"
          {{ ($c['show_powered_by'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show navigation links</span>
        <input type="checkbox" data-field="show_nav" value="1"
          {{ ($c['show_nav'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show "Book online" link</span>
        <input type="checkbox" data-field="show_book_link" value="1"
          {{ ($c['show_book_link'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;opacity:.5">Show contact email</span>
        <input type="checkbox" data-field="show_email" value="1"
          {{ ($c['show_email'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div style="border-top:0.5px solid var(--ia-border);margin:10px 0;padding-top:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="footer_bg" value="{{ ($c['footer_bg'] ?? null) ?: '#ffffff' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['footer_bg'] ?? '' }}" placeholder="Default" data-field="footer_bg_text">
          </div>
          <div style="font-size:10px;opacity:.3;margin-top:2px">Leave blank to use site background</div>
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Text color</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" data-field="footer_text" value="{{ ($c['footer_text'] ?? null) ?: '#111111' }}"
              style="width:36px;height:32px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer;padding:0">
            <input type="text" class="pb-input" style="width:80px;font-size:11px;font-family:monospace" value="{{ $c['footer_text'] ?? '' }}" placeholder="Default" data-field="footer_text_text">
          </div>
        </div>
      </div>

    {{-- ============================================================
         IMAGE GALLERY
         ============================================================ --}}
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
          <div class="pb-field-label">Gap size</div>
          <select class="pb-input" data-field="gap_size">
            <option value="none" {{ ($c['gap_size'] ?? 'normal') === 'none' ? 'selected' : '' }}>None</option>
            <option value="small" {{ ($c['gap_size'] ?? 'normal') === 'small' ? 'selected' : '' }}>Small (4px)</option>
            <option value="normal" {{ ($c['gap_size'] ?? 'normal') === 'normal' ? 'selected' : '' }}>Normal (12px)</option>
            <option value="large" {{ ($c['gap_size'] ?? 'normal') === 'large' ? 'selected' : '' }}>Large (24px)</option>
          </select>
        </div>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Image shape</div>
        <select class="pb-input" data-field="image_shape">
          <option value="square" {{ ($c['image_shape'] ?? 'square') === 'square' ? 'selected' : '' }}>Square (1:1)</option>
          <option value="landscape" {{ ($c['image_shape'] ?? 'square') === 'landscape' ? 'selected' : '' }}>Landscape (4:3)</option>
          <option value="portrait" {{ ($c['image_shape'] ?? 'square') === 'portrait' ? 'selected' : '' }}>Portrait (3:4)</option>
        </select>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Border radius</div>
        <select class="pb-input" data-field="image_radius">
          <option value="none" {{ ($c['image_radius'] ?? 'normal') === 'none' ? 'selected' : '' }}>None</option>
          <option value="normal" {{ ($c['image_radius'] ?? 'normal') === 'normal' ? 'selected' : '' }}>Normal</option>
          <option value="large" {{ ($c['image_radius'] ?? 'normal') === 'large' ? 'selected' : '' }}>Large</option>
        </select>
      </div>
      <div style="padding:10px;background:rgba(255,255,255,.03);border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);margin-top:8px">
        <div style="font-size:11px;opacity:.3">Image upload for galleries coming soon. For now, paste image URLs in the gallery data.</div>
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
