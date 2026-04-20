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

@endsection

@push('scripts')
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
      html += field('text', 'Text (tokens like first_name supported)', `<textarea class="cb-field-textarea" oninput="CB.updateData('text', this.value)">${escapeHtml(d.text || '')}</textarea>`);
      html += alignField(d.align);
    } else if (t === 'image') {
      html += field('url', 'Image URL', `<input type="text" class="cb-field-input" value="${escapeAttr(d.url || '')}" placeholder="https://..." oninput="CB.updateData('url', this.value)">`);
      html += field('alt', 'Alt text', `<input type="text" class="cb-field-input" value="${escapeAttr(d.alt || '')}" oninput="CB.updateData('alt', this.value)">`);
      html += '<p style="font-size:11px;opacity:.4;line-height:1.4">Image upload coming in the next phase. For now, paste a URL to any publicly accessible image.</p>';
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
      // Re-render selected state of align buttons etc. without losing input focus
      if (key === 'align' || key === 'size') renderSettings();
      syncHiddenInput();
      requestPreview();
    },
  };
})();

document.addEventListener('DOMContentLoaded', CB.init);
</script>
@endpush
