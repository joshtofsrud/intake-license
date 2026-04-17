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
@endphp

<div class="pb-section-block" data-section-id="{{ $section->id }}">

  <div class="pb-section-head">
    <span class="pb-drag-handle" title="Drag to reorder">⋮⋮</span>
    <span class="pb-section-type">{{ $typeLabels[$type] ?? $type }}</span>
    @if(!$section->is_visible)
      <span style="font-size:11px;opacity:.4;margin-right:4px">Hidden</span>
    @endif
    <span class="pb-section-chevron">▾</span>
  </div>

  <div class="pb-section-body" data-section-id="{{ $section->id }}">

    <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:13px;opacity:.6">Visible</span>
      <label style="cursor:pointer">
        <input type="checkbox" data-field="is_visible" value="1"
          {{ $section->is_visible ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </label>
    </div>

    @if($type === 'hero')
      <div class="pb-field-row">
        <div class="pb-field-label">Headline</div>
        <input type="text" class="pb-input" data-field="headline" value="{{ $c['headline'] ?? '' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <textarea class="pb-textarea" data-field="subheading" rows="2">{{ $c['subheading'] ?? '' }}</textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background color</div>
          <input type="color" data-field="bg_color" value="{{ $c['bg_color'] ?? '#1a1a1a' }}"
            style="width:100%;height:36px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Text color</div>
          <input type="color" data-field="text_color" value="{{ $c['text_color'] ?? '#ffffff' }}"
            style="width:100%;height:36px;border-radius:6px;border:0.5px solid var(--ia-border);cursor:pointer">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Button label</div>
          <input type="text" class="pb-input" data-field="cta_primary_label" value="{{ $c['cta_primary_label'] ?? 'Book Now' }}">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Button URL</div>
          <input type="text" class="pb-input" data-field="cta_primary_url" value="{{ $c['cta_primary_url'] ?? '/book' }}">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Secondary button label</div>
          <input type="text" class="pb-input" data-field="cta_secondary_label" value="{{ $c['cta_secondary_label'] ?? '' }}" placeholder="Optional">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Secondary button URL</div>
          <input type="text" class="pb-input" data-field="cta_secondary_url" value="{{ $c['cta_secondary_url'] ?? '' }}">
        </div>
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Height</div>
        <select class="pb-input" data-field="height">
          @foreach(['small','medium','large','fullscreen'] as $h)
            <option value="{{ $h }}" {{ ($c['height'] ?? 'large') === $h ? 'selected' : '' }}>{{ ucfirst($h) }}</option>
          @endforeach
        </select>
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

    @elseif($type === 'services')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Our services' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <input type="text" class="pb-input" data-field="subheading" value="{{ $c['subheading'] ?? '' }}" placeholder="Optional subheading">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Columns</div>
        <select class="pb-input" data-field="columns">
          @foreach([2,3,4] as $col)
            <option value="{{ $col }}" {{ ($c['columns'] ?? 3) == $col ? 'selected' : '' }}>{{ $col }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:13px;opacity:.6">Show prices</span>
        <label style="cursor:pointer">
          <input type="checkbox" data-field="show_prices" value="1"
            {{ ($c['show_prices'] ?? true) ? 'checked' : '' }}
            onchange="this.value=this.checked?1:0">
        </label>
      </div>

    @elseif($type === 'text_image')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? '' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Body text</div>
        <textarea class="pb-textarea" data-field="body" rows="4">{{ $c['body'] ?? '' }}</textarea>
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
      <div class="pb-field-row">
        <div class="pb-field-label">Image position</div>
        <select class="pb-input" data-field="image_position">
          <option value="right" {{ ($c['image_position'] ?? 'right') === 'right' ? 'selected' : '' }}>Right</option>
          <option value="left"  {{ ($c['image_position'] ?? 'right') === 'left'  ? 'selected' : '' }}>Left</option>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Button label</div>
          <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? '' }}">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Button URL</div>
          <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '' }}">
        </div>
      </div>

    @elseif($type === 'cta_banner')
      <div class="pb-field-row">
        <div class="pb-field-label">Headline</div>
        <input type="text" class="pb-input" data-field="headline" value="{{ $c['headline'] ?? 'Ready to book?' }}">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Subheading</div>
        <input type="text" class="pb-input" data-field="subheading" value="{{ $c['subheading'] ?? '' }}">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Button label</div>
          <input type="text" class="pb-input" data-field="cta_label" value="{{ $c['cta_label'] ?? 'Book Now' }}">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Button URL</div>
          <input type="text" class="pb-input" data-field="cta_url" value="{{ $c['cta_url'] ?? '/book' }}">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="pb-field-row">
          <div class="pb-field-label">Background color</div>
          <input type="color" data-field="bg_color" value="{{ $c['bg_color'] ?? '' }}"
            style="width:100%;height:36px;border-radius:6px;border:0.5px solid var(--ia-border)">
        </div>
        <div class="pb-field-row">
          <div class="pb-field-label">Text color</div>
          <input type="color" data-field="text_color" value="{{ $c['text_color'] ?? '' }}"
            style="width:100%;height:36px;border-radius:6px;border:0.5px solid var(--ia-border)">
        </div>
      </div>

    @elseif($type === 'contact_form')
      <div class="pb-field-row">
        <div class="pb-field-label">Heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Get in touch' }}">
      </div>
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:13px;opacity:.6">Show phone field</span>
        <input type="checkbox" data-field="show_phone" value="1"
          {{ ($c['show_phone'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>

    @elseif($type === 'booking_embed')
      <div class="pb-field-row">
        <div class="pb-field-label">Section heading</div>
        <input type="text" class="pb-input" data-field="heading" value="{{ $c['heading'] ?? 'Book online' }}">
      </div>
      <p style="font-size:12px;opacity:.4;margin-top:4px">
        The full booking form will be embedded here on the live site.
      </p>

    @elseif($type === 'nav')
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:13px;opacity:.6">Show logo</span>
        <input type="checkbox" data-field="show_logo" value="1"
          {{ ($c['show_logo'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
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
          <option value="solid"       {{ ($c['bg_style'] ?? 'solid') === 'solid'       ? 'selected' : '' }}>Solid</option>
          <option value="transparent" {{ ($c['bg_style'] ?? 'solid') === 'transparent' ? 'selected' : '' }}>Transparent</option>
        </select>
      </div>

    @elseif($type === 'footer')
      <div class="pb-field-row" style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:13px;opacity:.6">Show logo</span>
        <input type="checkbox" data-field="show_logo" value="1"
          {{ ($c['show_logo'] ?? true) ? 'checked' : '' }}
          onchange="this.value=this.checked?1:0">
      </div>
      <div class="pb-field-row">
        <div class="pb-field-label">Copyright text</div>
        <input type="text" class="pb-input" data-field="copyright_text"
          value="{{ $c['copyright_text'] ?? '' }}"
          placeholder="© {{ date('Y') }} {{ $currentTenant->name }}">
      </div>

    @elseif($type === 'image_gallery')
      <div class="pb-field-row">
        <div class="pb-field-label">Columns</div>
        <select class="pb-input" data-field="columns">
          @foreach([2,3,4] as $col)
            <option value="{{ $col }}" {{ ($c['columns'] ?? 3) == $col ? 'selected' : '' }}>{{ $col }}</option>
          @endforeach
        </select>
      </div>
      <p style="font-size:12px;opacity:.4">
        Image upload coming soon — paste image URLs for now.
      </p>

    @else
      <p style="font-size:13px;opacity:.4">No editor available for this section type.</p>
    @endif

    <div class="pb-section-actions">
      <span style="font-size:12px;opacity:.3">Auto-saves as you type</span>
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm pb-delete-section"
        data-section-id="{{ $section->id }}">
        Delete section
      </button>
    </div>

  </div>
</div>
