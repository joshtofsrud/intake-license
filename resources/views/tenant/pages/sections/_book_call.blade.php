{{-- MARKER-SCHED-SECTION — book_call editor (marketing site only) --}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $types = \App\Models\PlatformBookingType::where('kind', 'public')->orderBy('sort_order')->orderBy('name')->get();
  $selSlug = $get('booking_type', 'demo');
  $sel = $types->firstWhere('slug', $selSlug) ?: $types->first();
  $layout = $get('layout', 'calendar');
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Booking <span class="pb2-group-meta">from Scheduling</span></div>
    <div class="pb2-field">
      <label class="pb2-field-label">Booking type <span class="pb2-field-hint">public types</span></label>
      <select class="pb2-input" data-field="booking_type">
        @forelse($types as $t)
          <option value="{{ $t->slug }}" {{ $sel && $sel->slug === $t->slug ? 'selected' : '' }}>{{ $t->name }} · {{ $t->length_min }} min{{ $t->is_active ? '' : ' · OFF' }}</option>
        @empty
          <option value="">No public booking types yet</option>
        @endforelse
      </select>
    </div>
    @if($sel)
      <div style="padding:10px;background:rgba(255,255,255,.03);border-radius:var(--ia-r-md,6px);border:0.5px solid var(--ia-border,rgba(255,255,255,.08));font-size:11.5px;line-height:1.6;opacity:.85">
        Asks <b>{{ collect($sel->questionList())->pluck('label')->join(' · ') ?: 'name and email only' }}</b><br>
        Where <b>{{ \App\Models\PlatformBookingType::LOCATION_LABELS[$sel->location_mode] ?? $sel->location_mode }}</b> ·
        Reminder <b>{{ $sel->reminder_minutes ? $sel->reminder_minutes . ' min before' : 'none' }}</b><br>
        <span style="font-family:var(--pb2-mono,monospace);font-size:10px;opacity:.6">Edit these under Scheduling → Booking types. Reopen this panel to refresh.</span>
      </div>
    @endif
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Layout</div>
    <div class="pb2-field">
      <select class="pb2-input" data-field="layout">
        <option value="calendar" {{ $layout === 'calendar' ? 'selected' : '' }}>Calendar — pick a time right on the page</option>
        <option value="slots"    {{ $layout === 'slots' ? 'selected' : '' }}>Next open times — a row of the soonest slots</option>
        <option value="button"   {{ $layout === 'button' ? 'selected' : '' }}>Button — links to the booking page</option>
      </select>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button label <span class="pb2-field-hint">button + "see all" link</span></label>
      <input type="text" class="pb2-input" data-field="button_label" value="{{ $get('button_label', 'Book a call') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Slots to show <span class="pb2-field-hint">next-open-times layout</span></label>
      <input type="number" class="pb2-input" data-field="slot_count" value="{{ $get('slot_count', 5) }}" min="2" max="12">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Book a call">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading <span class="pb2-field-hint">blank = type name</span></label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="{{ $sel?->name }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Subheading <span class="pb2-field-hint">blank = type description</span></label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="3" placeholder="{{ $sel?->description }}">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Show</div>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_host" value="1" {{ $get('show_host', 1) ? 'checked' : '' }}>
      <span>Who they're meeting (name + title from Availability)</span>
    </label>
  </div>

  <div class="pb2-group">
    <div style="border:0.5px dashed var(--ia-border,rgba(255,255,255,.14));border-radius:var(--ia-r-md,6px);padding:10px;font-size:11px;line-height:1.55;opacity:.75">
      <b>What this section reads from Scheduling:</b> hours, buffers, blocked dates and existing bookings decide which slots appear. Nothing here changes those — if the calendar looks empty, check Availability, not this page.
    </div>
  </div>

</div>

{{--=================== DESIGN ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Section background</label>
      <div class="pb2-color-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="transparent">
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Colors</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Heading</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#f0f0f0' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Body</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#a3a3a3' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">selected day + buttons</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Spacing</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Vertical padding</label>
      <select class="pb2-input" data-field="padding_override">
        @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
          <option value="{{ $v }}" {{ $get('padding_override', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

</div>

{{--=================== ADVANCED ===================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Anchor</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID <span class="pb2-field-hint">link to #id from a button</span></label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="book">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_mobile" value="1" {{ ($get('hide_on_mobile') ? 'checked' : '') }}>
      <span>Hide on mobile</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_desktop" value="1" {{ ($get('hide_on_desktop') ? 'checked' : '') }}>
      <span>Hide on desktop</span>
    </label>
  </div>

  <div class="pb2-group">
    <div style="font-size:11px;line-height:1.55;opacity:.7">Calls booked here show up in Scheduling with "Booked from" set to this page's URL, so you can tell which page converts.</div>
  </div>

</div>
