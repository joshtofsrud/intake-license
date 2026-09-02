{{-- MARKER-DEMO-SECTION — try_demo editor (marketing site only) --}}
@php
  $c    = $c ?? ($section->content ?? []);
  $get  = fn($k, $d = '') => $c[$k] ?? $d;
  $demos = \App\Models\Tenant::where('is_demo', true)->orderBy('name')->get();
  $sel   = $get('demo_slug', 'demo');
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Which demo</div>
    <div class="pb2-field">
      <select class="pb2-input" data-field="demo_slug">
        @forelse($demos as $d)
          <option value="{{ $d->subdomain }}" {{ $sel === $d->subdomain ? 'selected' : '' }}>{{ $d->name }}</option>
        @empty
          <option value="demo">No demo tenants yet</option>
        @endforelse
      </select>
      <div style="font-size:11px;opacity:.6;margin-top:6px">Visitors land signed in, with no account. Manage the demo itself under Platform → Demo.</div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Layout</div>
    <div class="pb2-field">
      <select class="pb2-input" data-field="layout">
        <option value="card"   {{ $get('layout', 'card') === 'card' ? 'selected' : '' }}>Card — heading, text and button</option>
        <option value="button" {{ $get('layout') === 'button' ? 'selected' : '' }}>Button only</option>
      </select>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button label</label>
      <input type="text" class="pb2-input" data-field="button_label" value="{{ $get('button_label', 'Try the demo') }}">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text <span class="pb2-group-meta">card layout</span></div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="See it working">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Walk around a real shop">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="3" placeholder="A working shop with real work in it — no signup, nothing to install.">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div style="border:0.5px dashed var(--ia-border,rgba(255,255,255,.14));border-radius:var(--ia-r-md,6px);padding:10px;font-size:11px;line-height:1.55;opacity:.75">
      <b>Always shown, whatever you write:</b> a line telling visitors the demo resets every hour and that emails and texts never really send. That promise is not something a page should be able to forget to make.
    </div>
  </div>

</div>

<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <div class="pb2-color-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="transparent">
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Colors</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">button</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Spacing</div>
    <div class="pb2-field">
      <select class="pb2-input" data-field="padding_override">
        @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
          <option value="{{ $v }}" {{ $get('padding_override', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>
</div>

<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Anchor</div>
    <div class="pb2-field">
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="demo">
    </div>
  </div>
</div>
