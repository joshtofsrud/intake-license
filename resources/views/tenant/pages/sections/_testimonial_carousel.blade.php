{{--
  MARKER-TS-EDITOR: testimonials editor. Replaces the raw JSON box in the
  generic editor with a list of quotes: quote, name, role and an optional photo
  (upload or pick from the library). Wired by initTsList() in pages/edit.blade.php;
  the list is saved as the section's testimonials array, same shape as before
  (quote, author, role, avatar_url), so existing sections keep their quotes.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $tsItems = $c['testimonials'] ?? [];
  if (is_string($tsItems)) { $d = json_decode($tsItems, true); $tsItems = is_array($d) ? $d : []; }
  if (! is_array($tsItems)) $tsItems = [];
  $tsRow = function (array $t, int $i) {
      return view('tenant.pages.sections._testimonial_row', ['t' => $t, 'i' => $i])->render();
  };
@endphp

<style>
  .pb2-tsrow .pb2-tiacc-thumb { width:38px; height:38px; border-radius:50%; background:var(--pb2-surface-3) center/cover no-repeat; flex:none; }
</style>

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker, e.g. Reviews">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'What customers say') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional supporting text">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Quotes <span class="pb2-group-meta" id="pb2-ts-count">{{ count($tsItems) }} / 12</span></div>
    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">One card per quote. A quote with no text isn't shown. The photo is optional; without one the card shows a plain circle.</div>
    <div id="pb2-ts-list">
      @foreach($tsItems as $i => $t)
        {!! $tsRow(is_array($t) ? $t : [], $i) !!}
      @endforeach
    </div>
    <template id="pb2-ts-tpl">{!! $tsRow([], 0) !!}</template>
    <button type="button" class="pb2-addrow" id="pb2-ts-add">+ Add quote</button>
    <input type="hidden" data-field="testimonials" id="pb2-ts-json" value="{{ json_encode(array_values($tsItems)) }}">
  </div>
</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Color <span class="pb2-field-hint">blank follows your site's theme</span></label>
      <div class="pb2-color-row">
        @php $tsBg = $get('bg_color'); $tsBg = is_string($tsBg) && preg_match('/^#[0-9a-fA-F]{6}$/', $tsBg) ? $tsBg : ''; @endphp
        <input type="color" data-field="bg_color" value="{{ $tsBg ?: '#ffffff' }}" class="pb2-color-swatch" @if($tsBg === '') data-blank="1" @endif>
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $tsBg }}" placeholder="theme">
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. reviews">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_mobile" value="1" {{ $get('hide_on_mobile') && ! in_array((string) $get('hide_on_mobile'), ['0', 'false'], true) ? 'checked' : '' }}>
      <span>Hide on mobile</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_desktop" value="1" {{ $get('hide_on_desktop') && ! in_array((string) $get('hide_on_desktop'), ['0', 'false'], true) ? 'checked' : '' }}>
      <span>Hide on desktop</span>
    </label>
  </div>
</div>
