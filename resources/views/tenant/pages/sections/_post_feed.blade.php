{{-- MARKER-POSTS: Posts section editor — the latest posts from one feed. Posts are written under Website › Posts. --}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $postFeeds = $postFeeds ?? [];
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Latest updates') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-input" data-field="body" rows="2" placeholder="Optional intro line">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Posts</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Feed</label>
      <select class="pb2-input" data-field="feed">
        <option value="">All feeds</option>
        @foreach($postFeeds as $fd)
          <option value="{{ $fd }}" @selected($get('feed') === $fd)>{{ $fd }}</option>
        @endforeach
        @if($get('feed') !== '' && ! in_array($get('feed'), $postFeeds, true))
          <option value="{{ $get('feed') }}" selected>{{ $get('feed') }}</option>
        @endif
      </select>
      <div class="pb2-field-hint">@if(count($postFeeds))Write posts under <a href="{{ route('tenant.posts.index') }}" target="_blank" style="text-decoration:underline">Website › Posts</a>.@else No posts yet — <a href="{{ route('tenant.posts.index') }}" target="_blank" style="text-decoration:underline">write the first one</a>.@endif</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">How many to show</label>
      <input type="number" class="pb2-input" data-field="max_posts" min="1" max="12" value="{{ $get('max_posts', 3) }}">
    </div>
    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="layout">
        @foreach(['list' => 'List', 'cards' => 'Cards'] as $v => $n)
          <button type="button" class="pb2-seg-btn {{ $get('layout', 'list') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="layout" value="{{ $get('layout', 'list') }}">
    </div>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="show_dates" value="1" {{ $get('show_dates', '1') === '1' ? 'checked' : '' }}><span>Show dates</span></label>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="show_images" value="1" {{ $get('show_images', '1') === '1' ? 'checked' : '' }}><span>Show photos</span></label>
    <div class="pb2-field">
      <label class="pb2-field-label">When there are none</label>
      <input type="text" class="pb2-input" data-field="empty_text" value="{{ $get('empty_text', 'Nothing posted yet — check back soon.') }}">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Button</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Label</label>
      <input type="text" class="pb2-input" data-field="cta_label" value="{{ $get('cta_label', 'See all') }}" placeholder="Leave empty to hide">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">URL</label>
      <input type="text" class="pb2-input" data-field="cta_url" value="{{ $get('cta_url') }}" placeholder="Blank = all posts in this feed">
    </div>
  </div>
</div>

<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Alignment</div>
    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left' => 'Left', 'center' => 'Center'] as $v => $n)
          <button type="button" class="pb2-seg-btn {{ $get('text_align', 'left') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'left') }}">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Colors</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="bg_color" value="{{ $get('bg_color') }}" placeholder="Inherits page background">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Text color</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="text_color" value="{{ $get('text_color') }}" placeholder="Inherits">
    </div>
  </div>
</div>

<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Anchor & classes</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. updates">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="hide_on_mobile" value="1" {{ $get('hide_on_mobile') ? 'checked' : '' }}><span>Hide on mobile</span></label>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="hide_on_desktop" value="1" {{ $get('hide_on_desktop') ? 'checked' : '' }}><span>Hide on desktop</span></label>
  </div>
</div>
