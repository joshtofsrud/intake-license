{{--
  editor for "Feature groups with index".
  Each group: index label, heading, lead line, and its features — one per
  line as "Title — what it does". The groups are kept in one hidden JSON
  field (data-field="groups") that the builder saves like any other field.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $groups = $c['groups'] ?? [];
  if (is_string($groups)) { $d = json_decode($groups, true); $groups = is_array($d) ? $d : []; }
  if (!is_array($groups)) $groups = [];
  $lines = function ($g) {
      $items = $g['features'] ?? [];
      if (is_string($items)) { $d = json_decode($items, true); $items = is_array($d) ? $d : []; }
      return implode("\n", array_map(fn ($it) => trim(($it['title'] ?? '') . (!empty($it['body']) ? ' — ' . $it['body'] : '')), (array) $items));
  };
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Intro <span class="pb2-field-hint">optional</span></div>
    <div class="pb2-field"><label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="e.g. Features"></div>
    <div class="pb2-field"><label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}"></div>
    <div class="pb2-field"><label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2">{{ $get('subheading') }}</textarea></div>
  </div>

  <div class="pb2-group pfg-editor">
    <div class="pb2-group-title">Groups <span class="pb2-group-meta pfg-count">{{ count($groups) }}</span></div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-bottom:8px">
      The label is what shows in the index. Features: one per line, as <b>Title — what it does</b>.
    </div>
    <div class="pfg-list">
      @foreach($groups as $g)
        <div class="pfg-group" style="border:0.5px solid var(--pb2-border);border-radius:8px;padding:10px;margin-bottom:8px">
          <div style="display:flex;gap:6px;justify-content:flex-end;margin-bottom:6px">
            <button type="button" class="pb2-addrow" style="padding:2px 8px;width:auto" data-pfg="up" title="Move up">↑</button>
            <button type="button" class="pb2-addrow" style="padding:2px 8px;width:auto" data-pfg="down" title="Move down">↓</button>
            <button type="button" class="pb2-addrow" style="padding:2px 8px;width:auto" data-pfg="remove" title="Remove group">✕</button>
          </div>
          <input type="text" class="pb2-input" data-pfg-field="label" value="{{ $g['label'] ?? '' }}" placeholder="Index label, e.g. Booking" style="margin-bottom:6px">
          <input type="text" class="pb2-input" data-pfg-field="heading" value="{{ $g['heading'] ?? '' }}" placeholder="Heading" style="margin-bottom:6px">
          <textarea class="pb2-input pb2-textarea" data-pfg-field="lead" rows="2" placeholder="Lead line (optional)" style="margin-bottom:6px">{{ $g['lead'] ?? '' }}</textarea>
          <textarea class="pb2-input pb2-textarea" data-pfg-field="features" rows="5" placeholder="Your booking page — On your own domain">{{ $lines($g) }}</textarea>
        </div>
      @endforeach
    </div>
    <template class="pfg-tpl">
      <div class="pfg-group" style="border:0.5px solid var(--pb2-border);border-radius:8px;padding:10px;margin-bottom:8px">
        <div style="display:flex;gap:6px;justify-content:flex-end;margin-bottom:6px">
          <button type="button" class="pb2-addrow" style="padding:2px 8px;width:auto" data-pfg="up" title="Move up">↑</button>
          <button type="button" class="pb2-addrow" style="padding:2px 8px;width:auto" data-pfg="down" title="Move down">↓</button>
          <button type="button" class="pb2-addrow" style="padding:2px 8px;width:auto" data-pfg="remove" title="Remove group">✕</button>
        </div>
        <input type="text" class="pb2-input" data-pfg-field="label" placeholder="Index label, e.g. Booking" style="margin-bottom:6px">
        <input type="text" class="pb2-input" data-pfg-field="heading" placeholder="Heading" style="margin-bottom:6px">
        <textarea class="pb2-input pb2-textarea" data-pfg-field="lead" rows="2" placeholder="Lead line (optional)" style="margin-bottom:6px"></textarea>
        <textarea class="pb2-input pb2-textarea" data-pfg-field="features" rows="5" placeholder="Your booking page — On your own domain"></textarea>
      </div>
    </template>
    <button type="button" class="pb2-addrow" data-pfg="add" style="margin-top:4px">+ Add group</button>
    <input type="hidden" data-field="groups" class="pfg-json" value="{{ json_encode($groups) }}">
  </div>
</div>

<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-field"><label class="pb2-field-label">Anchor id</label>
      <input type="text" class="pb2-input" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="features"></div>
  </div>
</div>
