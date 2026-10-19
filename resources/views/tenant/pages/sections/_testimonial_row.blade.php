{{-- MARKER-TS-EDITOR: one quote in the testimonials editor (also the "+ Add quote" template). --}}
<div class="pb2-tsrow pb2-faqrow">
  <div class="pb2-faqrow-head">
    <span class="pb2-navlist-handle">⋮⋮</span>
    <span class="pb2-faqrow-pos">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
    <button type="button" class="pb2-textlink" data-ts-up title="Move up" aria-label="Move up">↑</button>
    <button type="button" class="pb2-navlist-remove" data-ts-remove title="Remove" aria-label="Remove">×</button>
  </div>
  <div class="pb2-faqrow-fields">
    <textarea class="pb2-input pb2-input-sm pb2-textarea" data-ts-field="quote" rows="3" placeholder="What they said">{{ $t['quote'] ?? '' }}</textarea>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <input type="text" class="pb2-input pb2-input-sm" data-ts-field="author" value="{{ $t['author'] ?? '' }}" placeholder="Name">
      <input type="text" class="pb2-input pb2-input-sm" data-ts-field="role" value="{{ $t['role'] ?? '' }}" placeholder="Role or detail (optional)">
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <div class="pb2-tiacc-thumb"></div>
      <button type="button" class="pb2-textlink" data-ts-upload>Upload photo</button>
      <button type="button" class="pb2-textlink" data-ts-lib>Choose from library</button>
      <span class="pb2-field-hint" data-ts-status></span>
      <button type="button" class="pb2-textlink pb2-textlink-danger" data-ts-clear>Remove</button>
    </div>
    <input type="hidden" data-ts-field="avatar_url" value="{{ $t['avatar_url'] ?? '' }}">
  </div>
</div>
