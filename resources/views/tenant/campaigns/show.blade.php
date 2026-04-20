@extends('layouts.tenant.app')
@php $pageTitle = $campaign->name; @endphp

@push('styles')
<style>
/* =============== Composer shell =============== */
.cb-back {
  font-size: 12px;
  opacity: .5;
  text-decoration: none;
  color: inherit;
  margin-bottom: 8px;
  display: inline-block;
}
.cb-back:hover { opacity: .8; }

.cb-meta-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
.cb-meta-row .ia-form-group { margin-bottom: 0; }

.cb-shell {
  display: grid;
  grid-template-columns: 240px 1fr 280px;
  gap: 16px;
  align-items: start;
}

.cb-col {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 14px;
  min-height: 400px;
}

.cb-col-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  opacity: .4;
  font-weight: 600;
  margin-bottom: 10px;
}

/* =============== LEFT: Blocks list =============== */
.cb-blocks {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 16px;
}
.cb-block-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm);
  cursor: pointer;
  font-size: 12px;
  transition: border-color .1s;
}
.cb-block-row:hover { border-color: var(--ia-text-muted); }
.cb-block-row.selected { border-color: var(--ia-accent); background: var(--ia-accent-soft); }
.cb-block-icon { font-size: 14px; opacity: .7; }
.cb-block-label { flex: 1; text-transform: capitalize; }
.cb-block-remove {
  background: none;
  border: none;
  cursor: pointer;
  opacity: .3;
  font-size: 14px;
  padding: 0 4px;
  color: inherit;
}
.cb-block-remove:hover { opacity: .8; color: #ff6b6b; }

.cb-add-heading {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  opacity: .4;
  font-weight: 600;
  margin: 12px 0 6px;
}
.cb-add-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
}
.cb-add-btn {
  padding: 8px 6px;
  background: var(--ia-surface-2);
  border: 0.5px dashed var(--ia-border);
  border-radius: var(--ia-r-sm);
  cursor: pointer;
  font-size: 11px;
  color: var(--ia-text-muted);
  transition: all .1s;
  font-family: inherit;
}
.cb-add-btn:hover { border-color: var(--ia-accent); color: var(--ia-text); border-style: solid; }

/* =============== CENTER: Preview =============== */
.cb-preview-wrap {
  padding: 0;
  overflow: hidden;
}
.cb-preview-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: var(--ia-surface-2);
  border-bottom: 0.5px solid var(--ia-border);
  font-size: 11px;
  opacity: .7;
  text-transform: uppercase;
  letter-spacing: .06em;
}
.cb-preview-iframe {
  width: 100%;
  height: 600px;
  border: none;
  background: #f4f4f2;
  display: block;
}
.cb-preview-status {
  font-size: 11px;
  opacity: .5;
}

/* =============== RIGHT: Settings panel =============== */
.cb-settings-empty {
  font-size: 12px;
  opacity: .4;
  text-align: center;
  padding: 24px 12px;
  line-height: 1.5;
}
.cb-field { margin-bottom: 12px; }
.cb-field-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  opacity: .5;
  font-weight: 600;
  margin-bottom: 4px;
  display: block;
}
.cb-field-input,
.cb-field-textarea,
.cb-field-select {
  width: 100%;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm);
  color: var(--ia-text);
  padding: 7px 10px;
  font-size: 12px;
  font-family: inherit;
  box-sizing: border-box;
}
.cb-field-textarea { min-height: 80px; resize: vertical; font-family: var(--ia-font-mono); }
.cb-field-input:focus,
.cb-field-textarea:focus,
.cb-field-select:focus { outline: none; border-color: var(--ia-accent); }

.cb-align-group {
  display: flex;
  gap: 2px;
}
.cb-align-btn {
  flex: 1;
  padding: 7px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm);
  cursor: pointer;
  font-size: 12px;
  color: var(--ia-text-muted);
  font-family: inherit;
}
.cb-align-btn.active { background: var(--ia-accent-soft); border-color: var(--ia-accent); color: var(--ia-text); }

/* =============== Sidebar (audience / send / stats) =============== */
.cb-sidebar-section { margin-top: 16px; }

@media (max-width: 1100px) {
  .cb-shell { grid-template-columns: 1fr; }
  .cb-preview-iframe { height: 480px; }
}

/* =============== Image block settings UI =============== */
.cb-img-preview {
  padding: 12px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm);
  margin-bottom: 12px;
  text-align: center;
}
.cb-img-change {
  margin-top: 8px;
  background: none;
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm);
  padding: 6px 12px;
  font-size: 11px;
  color: var(--ia-text-muted);
  cursor: pointer;
  font-family: inherit;
}
.cb-img-change:hover { border-color: var(--ia-accent); color: var(--ia-text); }

.cb-img-picker-btn {
  display: block;
  width: 100%;
  padding: 30px 12px;
  background: var(--ia-surface-2);
  border: 0.5px dashed var(--ia-border);
  border-radius: var(--ia-r-md);
  cursor: pointer;
  text-align: center;
  color: var(--ia-text-muted);
  margin-bottom: 12px;
  font-family: inherit;
}
.cb-img-picker-btn:hover { border-color: var(--ia-accent); border-style: solid; color: var(--ia-text); }

/* =============== TipTap rich text editor =============== */
.cb-tt-toolbar {
  display: flex;
  gap: 2px;
  padding: 6px 8px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-bottom: none;
  border-top-left-radius: var(--ia-r-sm);
  border-top-right-radius: var(--ia-r-sm);
}
.cb-tt-btn {
  background: transparent;
  border: 0.5px solid transparent;
  border-radius: 3px;
  cursor: pointer;
  font-size: 12px;
  padding: 4px 8px;
  min-width: 26px;
  color: var(--ia-text-muted);
  font-family: inherit;
}
.cb-tt-btn:hover { background: var(--ia-hover); color: var(--ia-text); }
.cb-tt-btn.active { background: var(--ia-accent-soft); border-color: var(--ia-accent); color: var(--ia-text); }
.cb-tt-editor {
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-top: none;
  border-bottom-left-radius: var(--ia-r-sm);
  border-bottom-right-radius: var(--ia-r-sm);
  padding: 10px 12px;
  min-height: 120px;
  font-size: 13px;
  line-height: 1.55;
  color: var(--ia-text);
}
.cb-tt-editor .ProseMirror {
  outline: none;
  min-height: 100px;
}
.cb-tt-editor .ProseMirror p { margin: 0 0 8px; }
.cb-tt-editor .ProseMirror p:last-child { margin-bottom: 0; }
.cb-tt-editor .ProseMirror ul,
.cb-tt-editor .ProseMirror ol { padding-left: 20px; margin: 0 0 8px; }
.cb-tt-editor .ProseMirror a { color: var(--ia-accent); text-decoration: underline; }

/* =============== TipTap rich text editor =============== */
.cb-tt-toolbar {
  display: flex;
  gap: 2px;
  padding: 6px 8px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-bottom: none;
  border-top-left-radius: var(--ia-r-sm);
  border-top-right-radius: var(--ia-r-sm);
}
.cb-tt-btn {
  background: transparent;
  border: 0.5px solid transparent;
  border-radius: 3px;
  cursor: pointer;
  font-size: 12px;
  padding: 4px 8px;
  min-width: 26px;
  color: var(--ia-text-muted);
  font-family: inherit;
}
.cb-tt-btn:hover { background: var(--ia-hover); color: var(--ia-text); }
.cb-tt-btn.active { background: var(--ia-accent-soft); border-color: var(--ia-accent); color: var(--ia-text); }
.cb-tt-editor {
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-top: none;
  border-bottom-left-radius: var(--ia-r-sm);
  border-bottom-right-radius: var(--ia-r-sm);
  padding: 10px 12px;
  min-height: 120px;
  font-size: 13px;
  line-height: 1.55;
  color: var(--ia-text);
}
.cb-tt-editor .ProseMirror {
  outline: none;
  min-height: 100px;
}
.cb-tt-editor .ProseMirror p { margin: 0 0 8px; }
.cb-tt-editor .ProseMirror p:last-child { margin-bottom: 0; }
.cb-tt-editor .ProseMirror ul,
.cb-tt-editor .ProseMirror ol { padding-left: 20px; margin: 0 0 8px; }
.cb-tt-editor .ProseMirror a { color: var(--ia-accent); text-decoration: underline; }

/* =============== Image picker modal =============== */
.cb-modal {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
}
.cb-modal-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
}
.cb-modal-panel {
  position: relative;
  width: 90%;
  max-width: 780px;
  max-height: 85vh;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.cb-modal-head {
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.cb-modal-close {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 22px;
  line-height: 1;
  color: inherit;
  opacity: .5;
  padding: 0 4px;
}
.cb-modal-close:hover { opacity: 1; }
.cb-modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 20px 20px;
}
.cb-modal-actions {
  display: flex;
  align-items: center;
  margin-bottom: 16px;
}
.cb-upload-btn {
  display: inline-block;
  background: var(--ia-accent);
  color: var(--ia-accent-text, #0a0a0a);
  padding: 8px 16px;
  border-radius: var(--ia-r-sm);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.cb-upload-btn:hover { filter: brightness(1.05); }

.cb-picker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px;
}
.cb-picker-item {
  position: relative;
  aspect-ratio: 1;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm);
  overflow: hidden;
  cursor: pointer;
}
.cb-picker-item:hover { border-color: var(--ia-accent); }
.cb-picker-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.cb-picker-item-del {
  position: absolute;
  top: 4px;
  right: 4px;
  background: rgba(0,0,0,0.7);
  color: white;
  border: none;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 13px;
  line-height: 1;
  cursor: pointer;
  opacity: 0;
  transition: opacity .1s;
}
.cb-picker-item:hover .cb-picker-item-del { opacity: 1; }
.cb-picker-empty {
  grid-column: 1 / -1;
  padding: 40px 12px;
  text-align: center;
  font-size: 12px;
  opacity: .5;
}
</style>
@endpush

@section('content')

<a href="{{ route('tenant.campaigns.index') }}" class="cb-back">← Back to campaigns</a>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $campaign->name }}</h1>
    <p class="ia-page-subtitle">
      @if($campaign->status === 'draft')
        Draft — not yet sent
      @elseif($campaign->status === 'sending')
        Sending now…
      @elseif($campaign->status === 'sent')
        Sent {{ $campaign->sent_at?->diffForHumans() }}
      @else
        {{ ucfirst($campaign->status) }}
      @endif
    </p>
  </div>
</div>

@if(session('success'))
  <div style="margin-bottom:16px;padding:10px 14px;background:var(--ia-accent-soft);border:0.5px solid var(--ia-accent);border-radius:var(--ia-r-md);font-size:13px">
    {{ session('success') }}
  </div>
@endif
@if(session('error'))
  <div style="margin-bottom:16px;padding:10px 14px;background:rgba(239,68,68,.1);border:0.5px solid rgba(239,68,68,.4);border-radius:var(--ia-r-md);font-size:13px;color:#FCA5A5">
    {{ session('error') }}
  </div>
@endif

<form method="POST" action="{{ route('tenant.campaigns.update', $campaign->id) }}" id="cb-form">
  @csrf @method('PATCH')

  {{-- Name + subject --}}
  <div class="cb-meta-row">
    <div class="ia-form-group">
      <label class="ia-form-label">Campaign name</label>
      <input type="text" name="name" class="ia-input"
        value="{{ old('name', $campaign->name) }}"
        required {{ $campaign->status !== 'draft' ? 'readonly' : '' }}>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Subject line</label>
      <input type="text" name="subject" class="ia-input"
        value="{{ old('subject', $campaign->subject) }}"
        placeholder="It's time for your spring tune-up"
        required {{ $campaign->status !== 'draft' ? 'readonly' : '' }}>
    </div>
  </div>

  {{-- 3-column builder --}}
  <div class="cb-shell">

    {{-- LEFT: blocks list + add palette --}}
    <div class="cb-col">
      <div class="cb-col-title">Blocks</div>
      <div class="cb-blocks" id="cb-blocks"></div>

      <div class="cb-add-heading">Add new</div>
      <div class="cb-add-grid">
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('heading')">Heading</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('paragraph')">Paragraph</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('image')">Image</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('button')">Button</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('divider')">Divider</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('footer')">Footer</button>
      </div>
    </div>

    {{-- CENTER: live preview --}}
    <div class="cb-col cb-preview-wrap">
      <div class="cb-preview-bar">
        <span>Live preview · sample data</span>
        <span class="cb-preview-status" id="cb-preview-status">Ready</span>
      </div>
      <iframe class="cb-preview-iframe" id="cb-preview-iframe" sandbox="allow-same-origin"></iframe>
    </div>

    {{-- RIGHT: settings panel --}}
    <div class="cb-col">
      <div class="cb-col-title">Block settings</div>
      <div id="cb-settings">
        <div class="cb-settings-empty">Select a block on the left to edit its settings.</div>
      </div>
    </div>

  </div>

  <input type="hidden" name="blocks_json" id="cb-blocks-json" value="">
  <input type="hidden" name="segment" id="cb-segment" value="{{ $campaign->targeting['segment'] ?? 'all' }}">

  {{-- Action row --}}
  @if($campaign->status === 'draft')
    <div style="display:flex;gap:10px;align-items:center;margin-top:16px">
      <button type="submit" class="ia-btn ia-btn--primary">Save draft</button>
    </div>
  @endif
</form>

{{-- Audience + send + stats (outside the builder form) --}}
<div class="cb-sidebar-section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:20px">

  <div class="cb-col">
    <div class="cb-col-title">Audience</div>
    @if($campaign->status === 'draft')
      <select class="cb-field-select" onchange="document.getElementById('cb-segment').value = this.value">
        @foreach($segments as $value => $label)
          <option value="{{ $value }}" {{ ($campaign->targeting['segment'] ?? 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
      <p style="font-size:11px;opacity:.45;margin-top:8px;line-height:1.4">Saved with the next Save draft click.</p>
    @else
      <div style="font-size:13px">{{ $segments[$campaign->targeting['segment'] ?? 'all'] ?? 'All customers' }}</div>
    @endif
  </div>

  @if($campaign->status === 'draft')
    <div class="cb-col">
      <div class="cb-col-title">Send</div>
      <p style="font-size:12px;opacity:.55;line-height:1.5;margin-bottom:12px">
        Once sent, content cannot be edited.
      </p>
      <form method="POST" action="{{ route('tenant.campaigns.send', $campaign->id) }}"
        onsubmit="return confirm('Send this campaign now? This cannot be undone.');">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary" style="width:100%">Send now</button>
      </form>
    </div>
  @else
    <div class="cb-col">
      <div class="cb-col-title">Performance</div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Recipients</span><strong>{{ $campaign->total_recipients }}</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Delivered</span><strong>{{ $campaign->total_sent }}</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Opened</span><strong>{{ $campaign->total_opened }}</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Clicked</span><strong>{{ $campaign->total_clicked }}</strong></div>
    </div>
  @endif
</div>

{{-- Image picker modal --}}
<div class="cb-modal" id="cb-picker-modal" style="display:none">
  <div class="cb-modal-backdrop" onclick="CB.closeImagePicker()"></div>
  <div class="cb-modal-panel">
    <div class="cb-modal-head">
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:600">Image library</h3>
        <p id="cb-picker-usage" style="margin:4px 0 0;font-size:11px;opacity:.5">Loading…</p>
      </div>
      <button type="button" class="cb-modal-close" onclick="CB.closeImagePicker()" aria-label="Close">×</button>
    </div>

    <div class="cb-modal-body">
      <div class="cb-modal-actions">
        <label class="cb-upload-btn">
          <input type="file" id="cb-upload-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="CB.handleUpload(this.files[0])">
          <span>Upload new image</span>
        </label>
        <span id="cb-upload-status" style="font-size:12px;opacity:.6;margin-left:12px"></span>
      </div>

      <div id="cb-picker-grid" class="cb-picker-grid">
        <div class="cb-picker-empty">Loading images…</div>
      </div>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script type="importmap">
{
  "imports": {
    "@tiptap/core":         "https://esm.sh/@tiptap/core@2.10.3",
    "@tiptap/starter-kit":  "https://esm.sh/@tiptap/starter-kit@2.10.3",
    "@tiptap/extension-link": "https://esm.sh/@tiptap/extension-link@2.10.3"
  }
}
</script>
<script type="module">
import { Editor }     from '@tiptap/core';
import StarterKit     from '@tiptap/starter-kit';
import Link           from '@tiptap/extension-link';
window.TipTap = { Editor, StarterKit, Link };
window.dispatchEvent(new Event('tiptap-loaded'));
</script>
<script>
window.CB = (function() {
  // ---- State ----
  const initialBlocks = @json($blocks);
  const previewUrl    = @js(route('tenant.campaigns.preview', $campaign->id));
  const csrfToken     = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const readOnly      = @json($campaign->status !== 'draft');

  let blocks    = Array.isArray(initialBlocks) ? initialBlocks : [];
  let selectedId = blocks.length > 0 ? blocks[0].id : null;
  let previewTimer = null;

  // ---- Block type registry ----
  const TYPES = {
    heading:   { label: 'Heading',   icon: 'H' },
    paragraph: { label: 'Paragraph', icon: '¶' },
    image:     { label: 'Image',     icon: '🖼' },
    button:    { label: 'Button',    icon: '▭' },
    divider:   { label: 'Divider',   icon: '—' },
    footer:    { label: 'Footer',    icon: '⨮' },
  };

  const DEFAULTS = {
    heading:   { text: 'Your headline here', size: 'h1', align: 'left' },
    paragraph: { text: '', align: 'left' },
    image:     { url: '', alt: '' },
    button:    { text: 'Click here', url: 'https://', align: 'left' },
    divider:   {},
    footer:    { text: 'You received this because you are a customer. Reply STOP to unsubscribe.' },
  };

  function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  // ---- Render ----
  function renderBlocksList() {
    const container = document.getElementById('cb-blocks');
    if (!container) return;
    if (blocks.length === 0) {
      container.innerHTML = '<div style="font-size:11px;opacity:.4;padding:8px;text-align:center">No blocks yet. Add one below.</div>';
      return;
    }
    container.innerHTML = blocks.map(function(b) {
      const type = TYPES[b.type] || { label: b.type, icon: '?' };
      const selected = (b.id === selectedId) ? ' selected' : '';
      return `
        <div class="cb-block-row${selected}" onclick="CB.select('${b.id}')">
          <span class="cb-block-icon">${type.icon}</span>
          <span class="cb-block-label">${type.label}</span>
          ${readOnly ? '' : `<button type="button" class="cb-block-remove" onclick="event.stopPropagation();CB.remove('${b.id}')" title="Remove">×</button>`}
        </div>
      `;
    }).join('');
  }

  function renderSettings() {
    destroyTipTapEditor();
    const wrap = document.getElementById('cb-settings');
    if (!wrap) return;
    const block = blocks.find(b => b.id === selectedId);
    if (!block) {
      wrap.innerHTML = '<div class="cb-settings-empty">Select a block on the left to edit its settings.</div>';
      return;
    }
    if (readOnly) {
      wrap.innerHTML = '<div class="cb-settings-empty">This campaign has been sent and cannot be edited.</div>';
      return;
    }

    const t = block.type;
    const d = block.data || {};
    let html = '';

    if (t === 'heading') {
      html += field('text', 'Text', `<input type="text" class="cb-field-input" value="${escapeAttr(d.text || '')}" oninput="CB.updateData('text', this.value)">`);
      html += field('size', 'Size', `
        <select class="cb-field-select" onchange="CB.updateData('size', this.value)">
          <option value="h1" ${d.size==='h1'?'selected':''}>Large (H1)</option>
          <option value="h2" ${d.size==='h2'?'selected':''}>Medium (H2)</option>
          <option value="h3" ${d.size==='h3'?'selected':''}>Small (H3)</option>
        </select>`);
      html += alignField(d.align);
    } else if (t === 'paragraph') {
      // Rich text editor — mount TipTap into this container after settings render.
      // data-tt-html holds initial content; we read it during mount.
      const initialHtml = d.html != null
        ? d.html
        : (d.text ? escapeHtml(d.text || '').replace(/\n/g, '<br>') : '');
      html += `<div class="cb-field">
        <label class="cb-field-label">Text (tokens like first_name supported)</label>
        <div class="cb-tt-toolbar" id="cb-tt-toolbar"></div>
        <div class="cb-tt-editor" id="cb-tt-editor" data-tt-html="${escapeAttr(initialHtml)}"></div>
      </div>`;
      html += alignField(d.align);
      // Defer the mount so the DOM nodes exist first
      setTimeout(mountTipTapEditor, 0);
    } else if (t === 'image') {
      const hasImage = !!(d.url && d.url.length > 0);
      if (hasImage) {
        html += `<div class="cb-img-preview">
          <img src="${escapeAttr(d.url)}" alt="" style="max-width:100%;max-height:140px;display:block;margin:0 auto;border-radius:4px">
          <button type="button" class="cb-img-change" onclick="CB.openImagePicker()">Change image</button>
        </div>`;
      } else {
        html += `<button type="button" class="cb-img-picker-btn" onclick="CB.openImagePicker()">
          <span style="font-size:22px;opacity:.4">+</span>
          <span style="display:block;font-size:12px;margin-top:4px">Choose or upload image</span>
        </button>`;
      }
      html += field('alt', 'Alt text (for screen readers)', `<input type="text" class="cb-field-input" value="${escapeAttr(d.alt || '')}" placeholder="Describe the image" oninput="CB.updateData('alt', this.value)">`);
    } else if (t === 'button') {
      html += field('text', 'Button label', `<input type="text" class="cb-field-input" value="${escapeAttr(d.text || '')}" oninput="CB.updateData('text', this.value)">`);
      html += field('url', 'Link URL', `<input type="text" class="cb-field-input" value="${escapeAttr(d.url || '')}" placeholder="https://..." oninput="CB.updateData('url', this.value)">`);
      html += alignField(d.align);
    } else if (t === 'divider') {
      html += '<p style="font-size:12px;opacity:.5;line-height:1.5">A horizontal line. No settings.</p>';
    } else if (t === 'footer') {
      html += field('text', 'Footer text', `<textarea class="cb-field-textarea" oninput="CB.updateData('text', this.value)">${escapeHtml(d.text || '')}</textarea>`);
    }

    wrap.innerHTML = html;
  }

  function field(key, label, input) {
    return `<div class="cb-field"><label class="cb-field-label">${label}</label>${input}</div>`;
  }

  function alignField(current) {
    const opts = ['left', 'center', 'right'];
    return `<div class="cb-field">
      <label class="cb-field-label">Align</label>
      <div class="cb-align-group">
        ${opts.map(a => `<button type="button" class="cb-align-btn${current===a?' active':''}" onclick="CB.updateData('align', '${a}')">${a}</button>`).join('')}
      </div>
    </div>`;
  }

  function escapeHtml(s) { return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function escapeAttr(s) { return String(s).replace(/"/g, '&quot;'); }

  // ---- Preview ----
  function requestPreview() {
    clearTimeout(previewTimer);
    const status = document.getElementById('cb-preview-status');
    if (status) status.textContent = 'Updating…';

    previewTimer = setTimeout(async function() {
      try {
        const res = await fetch(previewUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'text/html',
          },
          body: JSON.stringify({ blocks: blocks }),
        });
        const html = await res.text();
        const iframe = document.getElementById('cb-preview-iframe');
        if (iframe) {
          const doc = iframe.contentDocument || iframe.contentWindow.document;
          doc.open(); doc.write(html); doc.close();
        }
        if (status) status.textContent = 'Ready';
      } catch (err) {
        if (status) status.textContent = 'Preview failed';
      }
    }, 400);
  }

  function syncHiddenInput() {
    const input = document.getElementById('cb-blocks-json');
    if (input) input.value = JSON.stringify(blocks);
  }

  // ---- TipTap editor mount/destroy ----
  let activeEditor = null;

  function destroyTipTapEditor() {
    if (activeEditor) {
      try { activeEditor.destroy(); } catch (e) {}
      activeEditor = null;
    }
  }

  function mountTipTapEditor() {
    destroyTipTapEditor();

    const holder = document.getElementById('cb-tt-editor');
    const toolbar = document.getElementById('cb-tt-toolbar');
    if (!holder || !toolbar || !window.TipTap) return;

    const initialHtml = holder.getAttribute('data-tt-html') || '';

    const editor = new window.TipTap.Editor({
      element: holder,
      extensions: [
        window.TipTap.StarterKit.configure({
          heading: false, // headings are a separate block
          codeBlock: false,
          blockquote: false,
          horizontalRule: false,
        }),
        window.TipTap.Link.configure({
          openOnClick: false,
          autolink: true,
          HTMLAttributes: { rel: 'noopener' },
        }),
      ],
      content: initialHtml,
      onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        const block = blocks.find(b => b.id === selectedId);
        if (!block) return;
        block.data = block.data || {};
        block.data.html = html;
        delete block.data.text; // migrate off legacy text field on edit
        syncHiddenInput();
        requestPreview();
      },
    });

    activeEditor = editor;

    // Toolbar
    toolbar.innerHTML = `
      <button type="button" class="cb-tt-btn" data-cmd="bold"    title="Bold"><b>B</b></button>
      <button type="button" class="cb-tt-btn" data-cmd="italic"  title="Italic"><i>I</i></button>
      <button type="button" class="cb-tt-btn" data-cmd="link"    title="Link">↗</button>
      <button type="button" class="cb-tt-btn" data-cmd="bullet"  title="Bullet list">•</button>
      <button type="button" class="cb-tt-btn" data-cmd="ordered" title="Numbered list">1.</button>
    `;
    toolbar.querySelectorAll('.cb-tt-btn').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const cmd = btn.getAttribute('data-cmd');
        if (cmd === 'bold')    editor.chain().focus().toggleBold().run();
        if (cmd === 'italic')  editor.chain().focus().toggleItalic().run();
        if (cmd === 'bullet')  editor.chain().focus().toggleBulletList().run();
        if (cmd === 'ordered') editor.chain().focus().toggleOrderedList().run();
        if (cmd === 'link') {
          const prev = editor.getAttributes('link').href || '';
          const url  = window.prompt('URL (leave empty to remove):', prev);
          if (url === null) return;
          if (url === '') {
            editor.chain().focus().unsetLink().run();
          } else {
            let normalized = url.trim();
            if (!/^(https?:\/\/|mailto:)/i.test(normalized)) {
              normalized = 'https://' + normalized;
            }
            editor.chain().focus().setLink({ href: normalized }).run();
          }
        }
        updateToolbarState();
      });
    });

    function updateToolbarState() {
      const buttons = toolbar.querySelectorAll('.cb-tt-btn');
      buttons.forEach(function(btn) {
        const cmd = btn.getAttribute('data-cmd');
        let active = false;
        if (cmd === 'bold')    active = editor.isActive('bold');
        if (cmd === 'italic')  active = editor.isActive('italic');
        if (cmd === 'link')    active = editor.isActive('link');
        if (cmd === 'bullet')  active = editor.isActive('bulletList');
        if (cmd === 'ordered') active = editor.isActive('orderedList');
        btn.classList.toggle('active', active);
      });
    }
    editor.on('selectionUpdate', updateToolbarState);
    editor.on('transaction', updateToolbarState);
    updateToolbarState();
  }

  // If TipTap loads after initial render, remount
  window.addEventListener('tiptap-loaded', function() {
    if (document.getElementById('cb-tt-editor')) mountTipTapEditor();
  });

  // ---- TipTap editor mount/destroy ----
  let activeEditor = null;

  function destroyTipTapEditor() {
    if (activeEditor) {
      try { activeEditor.destroy(); } catch (e) {}
      activeEditor = null;
    }
  }

  function mountTipTapEditor() {
    destroyTipTapEditor();

    const holder = document.getElementById('cb-tt-editor');
    const toolbar = document.getElementById('cb-tt-toolbar');
    if (!holder || !toolbar || !window.TipTap) return;

    const initialHtml = holder.getAttribute('data-tt-html') || '';

    const editor = new window.TipTap.Editor({
      element: holder,
      extensions: [
        window.TipTap.StarterKit.configure({
          heading: false, // headings are a separate block
          codeBlock: false,
          blockquote: false,
          horizontalRule: false,
        }),
        window.TipTap.Link.configure({
          openOnClick: false,
          autolink: true,
          HTMLAttributes: { rel: 'noopener' },
        }),
      ],
      content: initialHtml,
      onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        const block = blocks.find(b => b.id === selectedId);
        if (!block) return;
        block.data = block.data || {};
        block.data.html = html;
        delete block.data.text; // migrate off legacy text field on edit
        syncHiddenInput();
        requestPreview();
      },
    });

    activeEditor = editor;

    // Toolbar
    toolbar.innerHTML = `
      <button type="button" class="cb-tt-btn" data-cmd="bold"    title="Bold"><b>B</b></button>
      <button type="button" class="cb-tt-btn" data-cmd="italic"  title="Italic"><i>I</i></button>
      <button type="button" class="cb-tt-btn" data-cmd="link"    title="Link">↗</button>
      <button type="button" class="cb-tt-btn" data-cmd="bullet"  title="Bullet list">•</button>
      <button type="button" class="cb-tt-btn" data-cmd="ordered" title="Numbered list">1.</button>
    `;
    toolbar.querySelectorAll('.cb-tt-btn').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const cmd = btn.getAttribute('data-cmd');
        if (cmd === 'bold')    editor.chain().focus().toggleBold().run();
        if (cmd === 'italic')  editor.chain().focus().toggleItalic().run();
        if (cmd === 'bullet')  editor.chain().focus().toggleBulletList().run();
        if (cmd === 'ordered') editor.chain().focus().toggleOrderedList().run();
        if (cmd === 'link') {
          const prev = editor.getAttributes('link').href || '';
          const url  = window.prompt('URL (leave empty to remove):', prev);
          if (url === null) return;
          if (url === '') {
            editor.chain().focus().unsetLink().run();
          } else {
            let normalized = url.trim();
            if (!/^(https?:\/\/|mailto:)/i.test(normalized)) {
              normalized = 'https://' + normalized;
            }
            editor.chain().focus().setLink({ href: normalized }).run();
          }
        }
        updateToolbarState();
      });
    });

    function updateToolbarState() {
      const buttons = toolbar.querySelectorAll('.cb-tt-btn');
      buttons.forEach(function(btn) {
        const cmd = btn.getAttribute('data-cmd');
        let active = false;
        if (cmd === 'bold')    active = editor.isActive('bold');
        if (cmd === 'italic')  active = editor.isActive('italic');
        if (cmd === 'link')    active = editor.isActive('link');
        if (cmd === 'bullet')  active = editor.isActive('bulletList');
        if (cmd === 'ordered') active = editor.isActive('orderedList');
        btn.classList.toggle('active', active);
      });
    }
    editor.on('selectionUpdate', updateToolbarState);
    editor.on('transaction', updateToolbarState);
    updateToolbarState();
  }

  // If TipTap loads after initial render, remount
  window.addEventListener('tiptap-loaded', function() {
    if (document.getElementById('cb-tt-editor')) mountTipTapEditor();
  });

  // ---- Public API ----
  return {
    init() {
      renderBlocksList();
      renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    addBlock(type) {
      if (readOnly) return;
      const block = { id: uuid(), type: type, data: Object.assign({}, DEFAULTS[type] || {}) };
      blocks.push(block);
      selectedId = block.id;
      renderBlocksList();
      renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    remove(id) {
      if (readOnly) return;
      if (!confirm('Remove this block?')) return;
      blocks = blocks.filter(b => b.id !== id);
      if (selectedId === id) selectedId = blocks.length > 0 ? blocks[0].id : null;
      renderBlocksList();
      renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    select(id) {
      selectedId = id;
      renderBlocksList();
      renderSettings();
    },

    updateData(key, value) {
      if (readOnly) return;
      const block = blocks.find(b => b.id === selectedId);
      if (!block) return;
      block.data = block.data || {};
      block.data[key] = value;
      if (key === 'align' || key === 'size') renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    async openImagePicker() {
      if (readOnly) return;
      const modal = document.getElementById('cb-picker-modal');
      if (!modal) return;
      modal.style.display = 'flex';
      await Promise.all([loadUsage(), loadImages()]);
    },

    closeImagePicker() {
      const modal = document.getElementById('cb-picker-modal');
      if (modal) modal.style.display = 'none';
    },

    selectImage(url) {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || block.type !== 'image') return;
      block.data = block.data || {};
      block.data.url = url;
      CB.closeImagePicker();
      renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    async handleUpload(file) {
      if (!file) return;
      const status = document.getElementById('cb-upload-status');
      if (status) status.textContent = 'Uploading…';

      const fd = new FormData();
      fd.append('image', file);

      try {
        const res = await fetch('/admin/campaign-images', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
          body: fd,
        });
        const data = await res.json();
        if (!res.ok) {
          if (status) status.textContent = data.error || 'Upload failed.';
          return;
        }
        if (status) status.textContent = 'Uploaded.';
        CB.selectImage(data.url);
      } catch (err) {
        if (status) status.textContent = 'Upload failed.';
      }
    },

    async deleteImage(id, ev) {
      if (ev) ev.stopPropagation();
      if (!confirm('Delete this image? This cannot be undone.')) return;
      try {
        await fetch('/admin/campaign-images/' + id, {
          method: 'DELETE',
          headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        await Promise.all([loadUsage(), loadImages()]);
      } catch (err) {
        alert('Delete failed.');
      }
    },
  };

  async function loadUsage() {
    const el = document.getElementById('cb-picker-usage');
    if (!el) return;
    try {
      const res = await fetch('/admin/campaign-images/usage', { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      const usedMb  = (data.bytes_used  / 1024 / 1024).toFixed(1);
      const limitMb = (data.bytes_limit / 1024 / 1024).toFixed(0);
      el.textContent = `${data.file_count} image${data.file_count === 1 ? '' : 's'} · ${usedMb} MB of ${limitMb} MB used`;
    } catch (err) {
      el.textContent = 'Usage unavailable.';
    }
  }

  async function loadImages() {
    const grid = document.getElementById('cb-picker-grid');
    if (!grid) return;
    grid.innerHTML = '<div class="cb-picker-empty">Loading images…</div>';
    try {
      const res = await fetch('/admin/campaign-images?limit=100', { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (!data.images || data.images.length === 0) {
        grid.innerHTML = '<div class="cb-picker-empty">No images yet. Upload your first one above.</div>';
        return;
      }
      grid.innerHTML = data.images.map(img => `
        <div class="cb-picker-item" onclick="CB.selectImage('${img.url}')" title="${escapeAttr(img.filename)}">
          <img src="${img.url}" alt="${escapeAttr(img.filename)}" loading="lazy">
          <button type="button" class="cb-picker-item-del" onclick="CB.deleteImage('${img.id}', event)" title="Delete">×</button>
        </div>
      `).join('');
    } catch (err) {
      grid.innerHTML = '<div class="cb-picker-empty">Failed to load images.</div>';
    }
  }
})();

document.addEventListener('DOMContentLoaded', CB.init);
</script>
@endpush
