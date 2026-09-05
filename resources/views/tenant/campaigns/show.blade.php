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
/* MARKER-CAMPAIGN-V2D — reorder handle, move/duplicate buttons */
.cb-block-row.dragging { opacity: .45; }
.cb-block-row.drop-target { border-color: var(--ia-accent); border-style: dashed; }
.cb-block-handle {
  cursor: grab; opacity: .35; font-size: 11px; letter-spacing: -1px;
  flex: 0 0 auto; user-select: none;
}
.cb-block-handle:active { cursor: grabbing; }
.cb-block-row:hover .cb-block-handle { opacity: .7; }
.cb-block-acts { display: flex; gap: 2px; flex: 0 0 auto; opacity: 0; transition: opacity .1s; }
.cb-block-row:hover .cb-block-acts,
.cb-block-row.selected .cb-block-acts { opacity: 1; }
.cb-block-act {
  background: none; border: none; cursor: pointer; padding: 1px 4px;
  color: var(--ia-text-dim, rgba(255,255,255,.55)); font-size: 11px; line-height: 1;
}
.cb-block-act:hover { color: var(--ia-accent); }
.cb-block-act[disabled] { opacity: .2; cursor: default; }

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
/* MARKER-CAMPAIGN-HDR */
.cb-hdr-toggle {
  display: flex; align-items: center; gap: 8px; cursor: pointer;
  background: var(--ia-surface-2); border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-sm); padding: 8px 10px; margin-bottom: 10px; font-size: 12px;
}
.cb-hdr-toggle input { accent-color: var(--ia-accent); cursor: pointer; }
.cb-hdr-hint { margin-left: auto; font-size: 10.5px; opacity: .45; }
/* MARKER-CAMPAIGN-V2A — viewport toggle + merge-tag chips */
.cb-preview-stage { background: #f4f4f2; display: flex; justify-content: center; overflow: hidden; }
/* MARKER-CAMPAIGN-MOBILEFIX — the email's inner table is a fixed 600px, so
   narrowing the iframe clipped it. Scale the whole thing down instead. */
.cb-preview-stage.mobile { padding: 10px 0; }
.cb-preview-stage.mobile .cb-preview-iframe {
  width: 600px;
  flex: 0 0 600px;
  transform: scale(.65);
  transform-origin: top center;
  height: 923px;          /* 600 / .65 — keeps the visible height at ~600 */
  margin-bottom: -323px;  /* reclaim the space the scale leaves behind */
  box-shadow: 0 0 0 1px rgba(0,0,0,.12);
  border-radius: 10px;
}
.cb-vp { display: inline-flex; border: .5px solid var(--ia-border); border-radius: 6px; overflow: hidden; }
.cb-vp-btn {
  font-size: 10px; padding: 3px 9px; background: none; border: none; cursor: pointer;
  color: var(--ia-text-dim, rgba(255,255,255,.55)); text-transform: uppercase; letter-spacing: .06em;
}
.cb-vp-btn.on { background: var(--ia-accent); color: #0a0a0a; font-weight: 700; }
.cb-merge { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
.cb-merge-chip {
  font-size: 10.5px; padding: 3px 8px; border-radius: 999px; cursor: pointer;
  border: .5px solid var(--ia-border); background: none; color: var(--ia-text-dim, rgba(255,255,255,.55));
}
.cb-merge-chip:hover { color: var(--ia-accent); border-color: var(--ia-accent); }

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
      @elseif($campaign->status === 'scheduled')
        {{-- MARKER-CAMPAIGN-SCHED — shown in the shop's timezone, since that
             is the clock the person scheduling it is reading. --}}
        Scheduled for {{ $campaign->scheduled_at?->setTimezone(tenant()->timezone())->format('M j, Y \a\t g:ia') }}
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

  {{-- MARKER-CAMPAIGN-CHROME — the header bar is near-black, so a dark logo
       disappears into it. This is exactly how WMM's went out invisible. --}}
  @php
    $emailLogo  = tenant()->emailLogoUrl();
    $logoChoice = tenant()->settings['email_logo_choice'] ?? 'light';
    $noLightLogo = $emailLogo && $logoChoice === 'light' && empty(tenant()->logo_light_url);
  @endphp
  @if($noLightLogo)
    <div style="border:1px solid #E0A82E;background:rgba(224,168,46,.08);color:#E0A82E;border-radius:8px;padding:10px 13px;font-size:12.5px;margin-bottom:14px">
      Your email header is dark and no light logo is set, so your main logo is being used and may be hard to see.
      Upload a light version under Settings → Branding, or set the email logo there.
    </div>
  @endif

  {{-- MARKER-CAMPAIGN-V2A — inbox preview line. --}}
  <div class="ia-form-group" style="margin-bottom:14px">
    <label class="ia-form-label">Preheader</label>
    <input type="text" name="preheader" class="ia-input" id="cb-preheader" maxlength="200"
      value="{{ old('preheader', $campaign->preheader) }}"
      placeholder="Book before Oct 15 and save 20%"
      {{ $campaign->status !== 'draft' ? 'readonly' : '' }}>
    <p style="font-size:11px;opacity:.45;margin-top:5px">The grey line beside the subject in the inbox. Leave it empty and most clients scrape your first sentence instead.</p>
  </div>

  {{-- 3-column builder --}}
  <div class="cb-shell">

    {{-- LEFT: blocks list + add palette --}}
    <div class="cb-col">
      {{-- MARKER-CAMPAIGN-HDR — pinned above the blocks: the shop header is
           added by the system, so its switch belongs with the blocks, not
           buried in settings. --}}
      <label class="cb-hdr-toggle">
        <input type="checkbox" name="show_header" value="1" id="cb-show-header"
          {{ old('show_header', $campaign->show_header ?? true) ? 'checked' : '' }}
          {{ $campaign->status !== 'draft' ? 'disabled' : '' }}
          onchange="CB.toggleHeader(this.checked)">
        <span>Include shop header</span>
        <span class="cb-hdr-hint">Logo bar at the top</span>
      </label>

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

      {{-- MARKER-CAMPAIGN-V2B --}}
      <div class="cb-add-heading">Layout</div>
      <div class="cb-add-grid">
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('spacer')">Spacer</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('two_column')">Two column</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('image_text')">Image + text</button>
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('social')">Social links</button>
        {{-- MARKER-CAMPAIGN-V2C --}}
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('gallery')">Image gallery</button>{{-- MARKER-CAMPAIGN-V2F --}}
        <button type="button" class="cb-add-btn" onclick="CB.addBlock('catalog')">Service / product</button>
      </div>
    </div>

    {{-- CENTER: live preview --}}
    <div class="cb-col cb-preview-wrap">
      <div class="cb-preview-bar">
        <span>Live preview · sample data</span>
        {{-- MARKER-CAMPAIGN-V2A --}}
        <span class="cb-vp">
          <button type="button" class="cb-vp-btn on" data-vp="desktop" onclick="CB.setViewport('desktop')">Desktop</button>
          <button type="button" class="cb-vp-btn" data-vp="mobile" onclick="CB.setViewport('mobile')">Mobile</button>
        </span>
        <span class="cb-preview-status" id="cb-preview-status">Ready</span>
      </div>
      <div class="cb-preview-stage" id="cb-preview-stage">
        <iframe class="cb-preview-iframe" id="cb-preview-iframe" sandbox="allow-same-origin"></iframe>
      </div>
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
  {{-- MARKER-CAMPAIGN-AUDIENCE — the audience travels with Save draft --}}
  <input type="hidden" name="targeting_json" id="cb-targeting-json" value="{{ json_encode($campaign->targeting ?? ['mode' => 'all']) }}">

  {{-- Action row --}}
  @if($campaign->status === 'draft')
    <div style="display:flex;gap:10px;align-items:center;margin-top:16px">
      <button type="submit" class="ia-btn ia-btn--primary">Save draft</button>
      {{-- MARKER-CAMPAIGN-V2A — test send posts on its own, so save first. --}}
      <button type="button" class="ia-btn" onclick="CB.testSend()">Send test…</button>
      <span style="font-size:11px;opacity:.45">Test uses the last saved draft and counts as one email.</span>
    </div>
  @endif
</form>

{{-- Audience + send + stats (outside the builder form) --}}
<div class="cb-sidebar-section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:20px">

  <div class="cb-col">
    <div class="cb-col-title">Audience</div>
    {{-- MARKER-CAMPAIGN-AUDIENCE --}}
    @if($campaign->status === 'draft')
      @php
        $t0 = $campaign->targeting ?? [];
        $mode0 = $t0['mode'] ?? (($t0['segment'] ?? 'all') === 'has_appointment' ? 'rules' : 'all');
      @endphp
      <div class="aud-mode">
        <button type="button" data-aud-mode="rules" class="{{ $mode0 === 'rules' ? 'on' : '' }}">Build a list</button>
        <button type="button" data-aud-mode="saved" class="{{ $mode0 === 'saved' ? 'on' : '' }}">Saved</button>
        <button type="button" data-aud-mode="all" class="{{ $mode0 === 'all' ? 'on' : '' }}">Everyone</button>
      </div>

      <div data-aud-panel="all" {{ $mode0 === 'all' ? '' : 'hidden' }}>
        <p class="aud-note">Every customer with an email address and marketing permission.</p>
      </div>

      <div data-aud-panel="saved" {{ $mode0 === 'saved' ? '' : 'hidden' }}>
        @forelse($savedAudiences as $sa)
          <label class="aud-saved-row">
            <input type="radio" name="aud_saved" value="{{ $sa->id }}"
                   {{ ($t0['audience_id'] ?? '') === $sa->id ? 'checked' : '' }}>
            <span>{{ $sa->name }}</span>
            <button type="button" class="aud-del" data-aud-delete="{{ $sa->id }}" title="Delete">×</button>
          </label>
        @empty
          <p class="aud-note">No saved audiences yet. Build a list, then save it for next time.</p>
        @endforelse
        <p class="aud-note">A saved audience re-runs its rules when the campaign sends, so it is never a stale copy.</p>
      </div>

      <div data-aud-panel="rules" {{ $mode0 === 'rules' ? '' : 'hidden' }}>
        <div class="aud-join">Customers matching all of</div>
        <div data-aud-rules></div>
        <button type="button" class="aud-add" data-aud-add>+ Add a rule</button>
        <p class="aud-note">Anyone without marketing permission is left out whatever the rules say.</p>
      </div>

      <div class="aud-count" data-aud-count>
        <div class="big" data-aud-total>—</div>
        <div class="sub2" data-aud-sub>Counting…</div>
        <div class="warn" data-aud-warn hidden></div>
      </div>

      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <button type="button" class="ia-btn" data-aud-preview style="font-size:12px;padding:6px 10px">Who's in this list</button>
        <button type="button" class="ia-btn" data-aud-save style="font-size:12px;padding:6px 10px">Save as audience…</button>
      </div>
      <div class="aud-preview" data-aud-sample hidden></div>
    @else
      <div style="font-size:13px">{{ $audienceSummary }}</div>
    @endif
  </div>

  {{-- MARKER-CAMPAIGN-ATTRIBUTION --}}
  <div class="cb-col">
    <div class="cb-col-title">Discount code</div>
    @if($campaign->status === 'draft')
      <form method="POST" action="{{ route('tenant.campaigns.discount', $campaign->id) }}">
        @csrf
        <select name="discount_id" class="cb-field-select" onchange="this.form.submit()">
          <option value="">No code</option>
          @foreach($discounts as $d)
            <option value="{{ $d->id }}" {{ $campaign->discount_id === $d->id ? 'selected' : '' }}>
              {{ $d->code }} — {{ $d->summary() }}
            </option>
          @endforeach
        </select>
      </form>
      <p style="font-size:11px;opacity:.45;margin-top:8px;line-height:1.4">
        Attach a code, then put <code>@{{discount_code}}</code> in any text block
        where you want it to appear.
        @if($discounts->isEmpty())
          You have no usable codes yet — <a href="{{ route('tenant.discounts.index') }}">create one</a>.
        @endif
      </p>
    @elseif($campaign->discount_id && $attribution)
      <div style="font-size:13px;font-weight:600">{{ $attribution['code'] }}</div>
      <div style="font-size:11.5px;opacity:.55;margin-top:2px">{{ $attribution['summary'] }}</div>
    @else
      <div style="font-size:13px;opacity:.5">No code</div>
    @endif
  </div>

  @if($campaign->status === 'draft')
    <div class="cb-col">
      <div class="cb-col-title">Send</div>
      {{-- MARKER-CAMPAIGN-DELIVERY — live sending. Only customers with
           marketing permission receive it; each email carries an
           unsubscribe link and goes out on the broadcast stream. --}}
      <p style="font-size:12px;opacity:.55;line-height:1.5;margin-bottom:12px">
        Goes only to customers with marketing permission, in batches, with an
        unsubscribe link in every email. Once sent, content cannot be edited.
      </p>
      {{-- MARKER-CAMPAIGN-CHECKS — what's wrong, before it goes out. --}}
      <div id="cb-checks" style="margin-bottom:14px">
        <div style="font-size:11px;opacity:.45">Checking…</div>
      </div>

      {{-- MARKER-CAMPAIGN-SCHED — house rule: no native dialogs. --}}
      <form method="POST" action="{{ route('tenant.campaigns.send', $campaign->id) }}" id="cb-send-form">
        @csrf
        <button type="button" class="ia-btn ia-btn--primary" style="width:100%" onclick="cbConfirmSend()">Send now</button>
      </form>

      <div style="margin-top:12px;padding-top:12px;border-top:.5px solid var(--ia-border)">
        <form method="POST" action="{{ route('tenant.campaigns.schedule', $campaign->id) }}">
          @csrf
          <label style="font-size:11px;opacity:.5;display:block;margin-bottom:5px">Or schedule it</label>
          <input type="datetime-local" name="scheduled_at" class="ia-input" style="width:100%"
            min="{{ now()->setTimezone(tenant()->timezone())->addMinutes(5)->format('Y-m-d\TH:i') }}"
            value="{{ now()->setTimezone(tenant()->timezone())->addDay()->setTime(9, 0)->format('Y-m-d\TH:i') }}">
          <button type="submit" class="ia-btn" style="width:100%;margin-top:8px">Schedule</button>
          <p style="font-size:10.5px;opacity:.45;margin:6px 0 0;line-height:1.45">
            Times are your shop's ({{ tenant()->timezone() }}). At least 5 minutes out.
            Recipients are worked out when it sends, so anyone who opts out before then is skipped.
          </p>
        </form>
      </div>
    </div>
  @elseif($campaign->status === 'scheduled')
    {{-- MARKER-CAMPAIGN-SCHED --}}
    <div class="cb-col">
      <div class="cb-col-title">Scheduled</div>
      <p style="font-size:13px;margin:0 0 4px">
        {{ $campaign->scheduled_at?->setTimezone(tenant()->timezone())->format('M j, Y \a\t g:ia') }}
      </p>
      <p style="font-size:11px;opacity:.45;margin:0 0 12px;line-height:1.45">
        {{ $campaign->scheduled_at?->diffForHumans() }} · recipients are worked out when it sends.
      </p>
      <form method="POST" action="{{ route('tenant.campaigns.unschedule', $campaign->id) }}">
        @csrf
        <button type="submit" class="ia-btn" style="width:100%">Cancel schedule</button>
      </form>
      <p style="font-size:10.5px;opacity:.45;margin:8px 0 0;line-height:1.45">
        Editing the campaign also cancels the schedule, so nothing changes underneath it.
      </p>
    </div>
  @else
    <div class="cb-col">
      <div class="cb-col-title">Performance</div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Recipients</span><strong>{{ $campaign->total_recipients }}</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Delivered</span><strong>{{ $campaign->total_sent }}</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Opened</span><strong>{{ $campaign->total_opened }}</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0"><span style="opacity:.5">Clicked</span><strong>{{ $campaign->total_clicked }}</strong></div>
      {{-- MARKER-CAMPAIGN-RESULTS --}}
      <a href="{{ route('tenant.campaigns.results', $campaign->id) }}" class="ia-btn ia-btn--ghost ia-btn--sm" style="text-decoration:none;display:block;text-align:center;margin-top:10px">See every recipient</a>
    </div>

    {{-- MARKER-CAMPAIGN-ATTRIBUTION — what the code did, with an honest
         statement of what these numbers can and can't tell you. --}}
    @if($attribution)
    <div class="cb-col">
      <div class="cb-col-title">Code redeemed</div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0">
        <span style="opacity:.5">Uses</span><strong>{{ number_format($attribution['uses']) }}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0">
        <span style="opacity:.5">Sales</span><strong>${{ number_format($attribution['sales_cents'] / 100, 2) }}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0">
        <span style="opacity:.5">Given away</span><strong>${{ number_format($attribution['given_cents'] / 100, 2) }}</strong>
      </div>
      <p style="font-size:11px;opacity:.45;margin-top:8px;line-height:1.4">
        Uses of {{ $attribution['code'] }}{{ $attribution['since'] ? ' since this went out' : '' }} —
        wherever it was redeemed. Someone who read the email and paid full price isn't
        counted, and a code passed to a friend is.
      </p>
    </div>
    @endif
  @endif
</div>

{{-- Image picker modal --}}
{{-- MARKER-CAMPAIGN-V2C — catalog picker (services + products in one list) --}}
<div class="cb-modal" id="cb-catalog-modal" style="display:none">
  <div class="cb-modal-backdrop" onclick="CB.closeCatalogPicker()"></div>
  <div class="cb-modal-panel">
    <div class="cb-modal-head">
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:600">Choose a service or product</h3>
        <p style="margin:4px 0 0;font-size:11px;opacity:.5">Live from your catalog. Price and photo refresh when the campaign sends.</p>
      </div>
      <button type="button" class="cb-modal-close" onclick="CB.closeCatalogPicker()" aria-label="Close">×</button>
    </div>

    <div class="cb-modal-body">
      <div class="cb-modal-actions">
        <input type="text" class="cb-field-input" id="cb-catalog-q" placeholder="Search services and products…" oninput="CB.searchCatalog(this.value)">
      </div>
      <div id="cb-catalog-grid" class="cb-picker-grid"></div>
    </div>
  </div>
</div>

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
          <input type="file" id="cb-upload-input" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" style="display:none" onchange="CB.handleUpload(this.files[0])">
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
  const testUrl       = @js(route('tenant.campaigns.test', $campaign->id)); // MARKER-CAMPAIGN-V2A
  const campaignId    = @js($campaign->id);
  const catalogSearchUrl = @js(route('tenant.campaigns.catalog-search')); // MARKER-CAMPAIGN-V2C
  const defaultTestTo    = @js(optional(auth('tenant')->user())->email ?? ''); // MARKER-CAMPAIGN-V2F
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
    // MARKER-CAMPAIGN-V2B
    spacer:     { label: 'Spacer',       icon: '↕' },
    two_column: { label: 'Two column',   icon: '▥' },
    image_text: { label: 'Image + text', icon: '▤' },
    social:     { label: 'Social links', icon: '◎' },
    catalog:    { label: 'Service / product', icon: '▤' }, // MARKER-CAMPAIGN-V2C
    gallery:    { label: 'Image gallery', icon: '⊞' }, // MARKER-CAMPAIGN-V2F
  };

  const DEFAULTS = {
    heading:   { text: 'Your headline here', size: 'h1', align: 'left' },
    paragraph: { text: '', align: 'left' },
    image:     { url: '', alt: '', width: '100', align: 'left', link: '', radius: '4' }, // MARKER-CAMPAIGN-V2E
    button:    { text: 'Click here', url: 'https://', align: 'left', full_width: '0' },
    divider:   {},
    footer:    { text: 'You received this because you are a customer. Reply STOP to unsubscribe.' },
    // MARKER-CAMPAIGN-V2B
    spacer:     { height: '24' },
    two_column: { left: '', right: '' },
    image_text: { url: '', alt: '', text: '', side: 'left', ratio: '45' }, // MARKER-CAMPAIGN-V2E
    social:     { links: [] },
    // MARKER-CAMPAIGN-V2C
    catalog:    { items: [], show_price: '1', show_photo: '1', cta_text: 'Book now', per_row: '2' },
    gallery:    { images: [], layout: '2' }, // MARKER-CAMPAIGN-V2F
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
    container.innerHTML = blocks.map(function(b, i) {
      const type = TYPES[b.type] || { label: b.type, icon: '?' };
      const selected = (b.id === selectedId) ? ' selected' : '';
      // MARKER-CAMPAIGN-V2D — handle, move up/down, duplicate.
      const first = i === 0, last = i === blocks.length - 1;
      const acts = readOnly ? '' : `
          <span class="cb-block-acts">
            <button type="button" class="cb-block-act" ${first ? 'disabled' : ''} onclick="event.stopPropagation();CB.move('${b.id}',-1)" title="Move up">↑</button>
            <button type="button" class="cb-block-act" ${last ? 'disabled' : ''} onclick="event.stopPropagation();CB.move('${b.id}',1)" title="Move down">↓</button>
            <button type="button" class="cb-block-act" onclick="event.stopPropagation();CB.duplicate('${b.id}')" title="Duplicate">⧉</button>
          </span>
          <button type="button" class="cb-block-remove" onclick="event.stopPropagation();CB.remove('${b.id}')" title="Remove">×</button>`;
      return `
        <div class="cb-block-row${selected}" data-block-id="${b.id}" ${readOnly ? '' : 'draggable="true"'} onclick="CB.select('${b.id}')">
          ${readOnly ? '' : '<span class="cb-block-handle" title="Drag to reorder">⋮⋮</span>'}
          <span class="cb-block-icon">${type.icon}</span>
          <span class="cb-block-label">${type.label}</span>
          ${acts}
        </div>
      `;
    }).join('');
    if (!readOnly) wireBlockDrag();
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
      html += bgField(d); // MARKER-CAMPAIGN-V2E
      html += mergeChips(); // MARKER-CAMPAIGN-V2A
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
      // MARKER-CAMPAIGN-V2F — text size.
      html += field('size', 'Text size', `
        <select class="cb-field-select" onchange="CB.updateData('size', this.value)">
          <option value="small"  ${d.size==='small'?'selected':''}>Small (14px)</option>
          <option value="normal" ${(d.size||'normal')==='normal'?'selected':''}>Normal (16px)</option>
          <option value="large"  ${d.size==='large'?'selected':''}>Large (18px)</option>
          <option value="xlarge" ${d.size==='xlarge'?'selected':''}>Extra large (20px)</option>
        </select>`);
      html += bgField(d); // MARKER-CAMPAIGN-V2E
      html += mergeChips(); // MARKER-CAMPAIGN-V2A
      // Defer the mount so the DOM nodes exist first
      setTimeout(mountTipTapEditor, 0);
    } else if (t === 'spacer') { // MARKER-CAMPAIGN-V2B
      html += field('height', 'Height', `
        <select class="cb-field-select" onchange="CB.updateData('height', this.value)">
          <option value="8"  ${String(d.height)==='8' ?'selected':''}>Tiny (8px)</option>
          <option value="16" ${String(d.height)==='16'?'selected':''}>Small (16px)</option>
          <option value="24" ${String(d.height)==='24'?'selected':''}>Medium (24px)</option>
          <option value="40" ${String(d.height)==='40'?'selected':''}>Large (40px)</option>
          <option value="64" ${String(d.height)==='64'?'selected':''}>Huge (64px)</option>
        </select>`);
    } else if (t === 'two_column') {
      html += field('left', 'Left column', `<textarea class="cb-field-textarea" rows="4" oninput="CB.updateData('left', this.value)">${escapeHtml(d.left || '')}</textarea>`);
      html += field('right', 'Right column', `<textarea class="cb-field-textarea" rows="4" oninput="CB.updateData('right', this.value)">${escapeHtml(d.right || '')}</textarea>`);
      html += bgField(d); // MARKER-CAMPAIGN-V2E
      html += mergeChips();
      html += '<p style="font-size:10.5px;opacity:.45;margin:6px 0 0">Columns sit side by side on desktop and stack on narrow phones.</p>';
    } else if (t === 'image_text') {
      if (d.url) {
        html += `<div class="cb-img-preview">
          <img src="${escapeAttr(d.url)}" alt="" style="max-width:100%;max-height:120px;display:block;margin:0 auto;border-radius:4px">
          <button type="button" class="cb-img-change" onclick="CB.openImagePicker()">Change image</button>
        </div>`;
      } else {
        html += `<button type="button" class="cb-img-picker-btn" onclick="CB.openImagePicker()">
          <span style="font-size:22px;opacity:.4">+</span>
          <span style="display:block;font-size:12px;margin-top:4px">Choose or upload image</span>
        </button>`;
      }
      html += field('alt', 'Alt text', `<input type="text" class="cb-field-input" value="${escapeAttr(d.alt || '')}" oninput="CB.updateData('alt', this.value)">`);
      html += field('text', 'Text', `<textarea class="cb-field-textarea" rows="4" oninput="CB.updateData('text', this.value)">${escapeHtml(d.text || '')}</textarea>`);
      html += field('side', 'Image on', `
        <select class="cb-field-select" onchange="CB.updateData('side', this.value)">
          <option value="left"  ${d.side!=='right'?'selected':''}>Left</option>
          <option value="right" ${d.side==='right'?'selected':''}>Right</option>
        </select>`);
      // MARKER-CAMPAIGN-V2E
      html += field('ratio', 'Split', `
        <select class="cb-field-select" onchange="CB.updateData('ratio', this.value)">
          <option value="40" ${String(d.ratio)==='40'?'selected':''}>40 / 60 — image smaller</option>
          <option value="45" ${String(d.ratio || '45')==='45'?'selected':''}>45 / 55</option>
          <option value="50" ${String(d.ratio)==='50'?'selected':''}>50 / 50</option>
          <option value="60" ${String(d.ratio)==='60'?'selected':''}>60 / 40 — image larger</option>
        </select>`);
      html += bgField(d);
      html += mergeChips();
    } else if (t === 'social') {
      const links = Array.isArray(d.links) ? d.links : [];
      let rows = '';
      links.forEach(function (l, i) {
        rows += `<div style="display:flex;gap:5px;margin-bottom:5px">
          <input type="text" class="cb-field-input" style="flex:0 0 88px" value="${escapeAttr(l.label || '')}" placeholder="Label" oninput="CB.updateSocial(${i}, 'label', this.value)">
          <input type="text" class="cb-field-input" style="flex:1;min-width:0" value="${escapeAttr(l.url || '')}" placeholder="https://" oninput="CB.updateSocial(${i}, 'url', this.value)">
          <button type="button" class="cb-block-remove" onclick="CB.removeSocial(${i})" title="Remove">×</button>
        </div>`;
      });
      html += `<div class="cb-field"><label class="cb-field-label">Links (5 max)</label>${rows}`;
      if (links.length < 5) {
        html += `<button type="button" class="cb-add-btn" style="width:100%;margin-top:4px" onclick="CB.addSocial()">+ Add link</button>`;
      }
      html += `<p style="font-size:10.5px;opacity:.45;margin:6px 0 0">Links need a full URL (https://…) or they're dropped when saved.</p></div>`;
    } else if (t === 'gallery') { // MARKER-CAMPAIGN-V2F
      const imgs = Array.isArray(d.images) ? d.images : [];
      let rows = '';
      imgs.forEach(function (im, i) {
        rows += `<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
          <div style="width:44px;height:34px;flex:0 0 auto;border-radius:4px;background:#000 center/cover no-repeat;background-image:url('${escapeAttr(im.url)}')"></div>
          <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:4px">
            <input type="text" class="cb-field-input" value="${escapeAttr(im.alt || '')}" placeholder="Alt text" oninput="CB.updateGalleryImage(${i}, 'alt', this.value)">
            <input type="text" class="cb-field-input" value="${escapeAttr(im.link || '')}" placeholder="Links to (optional)" oninput="CB.updateGalleryImage(${i}, 'link', this.value)">
          </div>
          <button type="button" class="cb-block-remove" onclick="CB.removeGalleryImage(${i})" title="Remove">×</button>
        </div>`;
      });
      html += `<div class="cb-field"><label class="cb-field-label">Images (6 max)</label>${rows}`;
      if (imgs.length < 6) {
        html += `<button type="button" class="cb-add-btn" style="width:100%;margin-top:4px" onclick="CB.addGalleryImage()">+ Add image</button>`;
      }
      html += `</div>`;
      html += field('layout', 'Layout', `
        <select class="cb-field-select" onchange="CB.updateData('layout', this.value)">
          <option value="2"      ${(d.layout||'2')==='2'?'selected':''}>Two across</option>
          <option value="3"      ${d.layout==='3'?'selected':''}>Three across</option>
          <option value="mosaic" ${d.layout==='mosaic'?'selected':''}>Mosaic — one large, rest beneath</option>
        </select>`);
      html += bgField(d);
    } else if (t === 'catalog') { // MARKER-CAMPAIGN-V2C
      const items = Array.isArray(d.items) ? d.items : [];
      let rows = '';
      items.forEach(function (it, i) {
        rows += `<div style="display:flex;gap:6px;align-items:center;margin-bottom:5px;background:var(--ia-surface-2);border-radius:5px;padding:5px 7px">
          <span style="font-size:9px;text-transform:uppercase;letter-spacing:.05em;opacity:.5;flex:0 0 auto">${it.kind === 'product' ? 'Prod' : 'Svc'}</span>
          <span style="flex:1;min-width:0;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(it._label || it.name || it.id)}</span>
          <button type="button" class="cb-block-remove" onclick="CB.removeCatalogItem(${i})" title="Remove">×</button>
        </div>`;
      });
      html += `<div class="cb-field"><label class="cb-field-label">Items (4 max)</label>${rows}`;
      if (items.length < 4) {
        html += `<button type="button" class="cb-add-btn" style="width:100%;margin-top:4px" onclick="CB.openCatalogPicker()">+ Choose service or product</button>`;
      }
      html += `<p style="font-size:10.5px;opacity:.45;margin:6px 0 0">Name, price and photo are pulled fresh when the email sends, so a price change before then is picked up.</p></div>`;
      html += field('per_row', 'Per row', `
        <select class="cb-field-select" onchange="CB.updateData('per_row', this.value)">
          <option value="2" ${String(d.per_row)!=='1'?'selected':''}>Two across</option>
          <option value="1" ${String(d.per_row)==='1'?'selected':''}>One across</option>
        </select>`);
      html += field('show_price', 'Show price', `
        <select class="cb-field-select" onchange="CB.updateData('show_price', this.value)">
          <option value="1" ${String(d.show_price)!=='0'?'selected':''}>Yes</option>
          <option value="0" ${String(d.show_price)==='0'?'selected':''}>No</option>
        </select>`);
      html += field('show_photo', 'Show photo', `
        <select class="cb-field-select" onchange="CB.updateData('show_photo', this.value)">
          <option value="1" ${String(d.show_photo)!=='0'?'selected':''}>Yes</option>
          <option value="0" ${String(d.show_photo)==='0'?'selected':''}>No</option>
        </select>`);
      html += field('cta_text', 'Button text', `<input type="text" class="cb-field-input" value="${escapeAttr(d.cta_text || '')}" placeholder="Leave empty for no button" oninput="CB.updateData('cta_text', this.value)">`);
      html += bgField(d); // MARKER-CAMPAIGN-V2E
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
      // MARKER-CAMPAIGN-V2E — size and placement.
      html += field('width', 'Width', `
        <select class="cb-field-select" onchange="CB.updateData('width', this.value)">
          <option value="100"  ${String(d.width || '100')==='100'?'selected':''}>Full width</option>
          <option value="75"   ${String(d.width)==='75'?'selected':''}>Three quarters</option>
          <option value="50"   ${String(d.width)==='50'?'selected':''}>Half</option>
          <option value="25"   ${String(d.width)==='25'?'selected':''}>Quarter</option>
          <option value="orig" ${String(d.width)==='orig'?'selected':''}>Original size</option>
        </select>`);
      html += field('align', 'Align', `
        <select class="cb-field-select" onchange="CB.updateData('align', this.value)">
          <option value="left"   ${(d.align||'left')==='left'?'selected':''}>Left</option>
          <option value="center" ${d.align==='center'?'selected':''}>Center</option>
          <option value="right"  ${d.align==='right'?'selected':''}>Right</option>
        </select>`);
      html += field('link', 'Links to', `<input type="text" class="cb-field-input" value="${escapeAttr(d.link || '')}" placeholder="https:// (optional)" oninput="CB.updateData('link', this.value)">`);
      html += field('radius', 'Corners', `
        <select class="cb-field-select" onchange="CB.updateData('radius', this.value)">
          <option value="0"  ${String(d.radius)==='0'?'selected':''}>Square</option>
          <option value="4"  ${String(d.radius || '4')==='4'?'selected':''}>Slightly round</option>
          <option value="12" ${String(d.radius)==='12'?'selected':''}>Round</option>
        </select>`);
      html += bgField(d);
    } else if (t === 'button') {
      html += field('text', 'Button label', `<input type="text" class="cb-field-input" value="${escapeAttr(d.text || '')}" oninput="CB.updateData('text', this.value)">`);
      html += field('url', 'Link URL', `<input type="text" class="cb-field-input" value="${escapeAttr(d.url || '')}" placeholder="https://..." oninput="CB.updateData('url', this.value)">`);
      html += alignField(d.align);
      // MARKER-CAMPAIGN-V2E
      html += field('full_width', 'Width', `
        <select class="cb-field-select" onchange="CB.updateData('full_width', this.value)">
          <option value="0" ${String(d.full_width || '0')==='0'?'selected':''}>Fit to text</option>
          <option value="1" ${String(d.full_width)==='1'?'selected':''}>Full width</option>
        </select>`);
      html += bgField(d);
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

  // MARKER-CAMPAIGN-V2E — background colour, shared by most block types.
  function bgField(d) {
    const v = d.bg_color || '';
    return `<div class="cb-field">
      <label class="cb-field-label">Background</label>
      <div style="display:flex;gap:6px;align-items:center">
        <input type="color" value="${escapeAttr(v || '#ffffff')}" style="width:34px;height:28px;padding:0;border:none;background:none;cursor:pointer" oninput="CB.updateData('bg_color', this.value)">
        <input type="text" class="cb-field-input" style="flex:1" value="${escapeAttr(v)}" placeholder="none" oninput="CB.updateData('bg_color', this.value)">
        <button type="button" class="cb-block-act" title="Clear" onclick="CB.updateData('bg_color',''); CB.renderSettingsPublic();">×</button>
      </div>
    </div>`;
  }

  // MARKER-CAMPAIGN-V2A — merge tags, viewport, test send.
  const MERGE_TAGS = [
    ['first_name', 'there'],
    ['last_name', ''],
    ['name', 'there'],
    ['shop_name', ''],
    ['discount_code', ''],
  ];

  function mergeChips() {
    const chips = MERGE_TAGS.map(function (t) {
      const tok = t[1] ? '{{' + t[0] + '|' + t[1] + '}}' : '{{' + t[0] + '}}';
      return '<button type="button" class="cb-merge-chip" onclick="CB.insertTag(' + JSON.stringify(tok).replace(/"/g, '&quot;') + ')">' + t[0] + '</button>';
    }).join('');
    return '<div class="cb-field"><label class="cb-field-label">Insert merge tag</label>'
      + '<div class="cb-merge">' + chips + '</div>'
      + '<p style="font-size:10.5px;opacity:.45;margin:6px 0 0">A tag with a fallback — <b>first_name|there</b> — never leaves a blank behind.</p></div>';
  }

  function insertTag(token) {
    // TipTap paragraph editor first, then a plain text input (heading).
    if (activeEditor) {
      activeEditor.chain().focus().insertContent(token).run();
      return;
    }
    const input = document.querySelector('#cb-settings input.cb-field-input');
    if (!input) return;
    const at = input.selectionStart != null ? input.selectionStart : input.value.length;
    input.value = input.value.slice(0, at) + token + input.value.slice(at);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
    input.setSelectionRange(at + token.length, at + token.length);
  }

  function setViewport(mode) {
    const stage = document.getElementById('cb-preview-stage');
    if (stage) stage.classList.toggle('mobile', mode === 'mobile');
    document.querySelectorAll('.cb-vp-btn').forEach(function (b) {
      b.classList.toggle('on', b.getAttribute('data-vp') === mode);
    });
  }

  // MARKER-CAMPAIGN-V2F — choose where the test goes; defaults to you, and
  // remembers the last address used on this device.
  function testSend() {
    let last = '';
    try { last = window.localStorage.getItem('cb-test-to') || ''; } catch (e) {}
    const to = last || defaultTestTo;

    // MARKER-AUDIENCE-POLISH — the fallback is gone; prompt() is real now.
    IntakeConfirm.prompt({
      title: 'Send a test of this campaign',
      message: 'It uses the last saved draft, with sample values in place of merge tags, and counts as one email.',
      value: to,
      placeholder: 'name@example.com',
      confirmText: 'Send test',
    }).then(submitTest);
  }

  function submitTest(address) {
    if (!address) return;
    address = String(address).trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(address)) {
      IntakeConfirm.alert({ title: 'Check the address', message: 'That doesn\'t look like a valid email address.' });
      return;
    }
    try { window.localStorage.setItem('cb-test-to', address); } catch (e) {}
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = testUrl;
    f.innerHTML = '<input type="hidden" name="_token" value="' + csrfToken + '">'
                + '<input type="hidden" name="to" value="' + address.replace(/"/g, '&quot;') + '">';
    document.body.appendChild(f);
    f.submit();
  }

  function escapeHtml(s) { return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function escapeAttr(s) { return String(s).replace(/"/g, '&quot;'); }

  // MARKER-CAMPAIGN-V2D — drag to reorder the block list.
  let dragId = null;
  let galleryPending = false; // MARKER-CAMPAIGN-V2F
  function wireBlockDrag() {
    document.querySelectorAll('#cb-blocks .cb-block-row').forEach(function (row) {
      row.addEventListener('dragstart', function (e) {
        dragId = row.getAttribute('data-block-id');
        row.classList.add('dragging');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragId); } catch (err) {}
      });
      row.addEventListener('dragend', function () {
        dragId = null;
        document.querySelectorAll('#cb-blocks .cb-block-row').forEach(function (r) {
          r.classList.remove('dragging', 'drop-target');
        });
      });
      row.addEventListener('dragover', function (e) {
        if (!dragId) return;
        e.preventDefault();
        if (row.getAttribute('data-block-id') !== dragId) row.classList.add('drop-target');
      });
      row.addEventListener('dragleave', function () { row.classList.remove('drop-target'); });
      row.addEventListener('drop', function (e) {
        e.preventDefault();
        row.classList.remove('drop-target');
        const targetId = row.getAttribute('data-block-id');
        if (!dragId || dragId === targetId) return;
        const from = blocks.findIndex(b => b.id === dragId);
        const to   = blocks.findIndex(b => b.id === targetId);
        if (from < 0 || to < 0) return;
        const [moved] = blocks.splice(from, 1);
        blocks.splice(to, 0, moved);
        selectedId = moved.id;
        renderBlocksList(); renderSettings(); syncHiddenInput(); requestPreview();
      });
    });
  }

  // MARKER-CAMPAIGN-V2D — preview ↔ builder highlighting. The iframe is
  // same-origin, so listeners attach straight to its document; nothing is
  // injected into the email itself.
  function wirePreviewHighlight() {
    const iframe = document.getElementById('cb-preview-iframe');
    const doc = iframe && (iframe.contentDocument || iframe.contentWindow.document);
    if (!doc) return;
    doc.querySelectorAll('tr[data-cb-block]').forEach(function (tr) {
      const id = tr.getAttribute('data-cb-block');
      const type = TYPES[tr.getAttribute('data-cb-type')] || { label: tr.getAttribute('data-cb-type') };
      const cell = tr.querySelector('td');
      if (cell) cell.setAttribute('data-cb-label', type.label || '');
      tr.addEventListener('mouseenter', function () {
        doc.querySelectorAll('tr[data-cb-block]').forEach(t => t.classList.remove('cb-hover'));
        tr.classList.add('cb-hover');
      });
      tr.addEventListener('mouseleave', function () { tr.classList.remove('cb-hover'); });
      tr.addEventListener('click', function (e) {
        e.preventDefault();
        CB.select(id);
      });
      if (id === selectedId) tr.classList.add('cb-active');
    });
  }

  // Scroll the preview to a block and flash it (list → preview direction).
  function revealInPreview(id) {
    const iframe = document.getElementById('cb-preview-iframe');
    const doc = iframe && (iframe.contentDocument || iframe.contentWindow.document);
    if (!doc) return;
    doc.querySelectorAll('tr[data-cb-block]').forEach(function (t) {
      t.classList.toggle('cb-active', t.getAttribute('data-cb-block') === id);
    });
    const el = doc.querySelector('tr[data-cb-block="' + id + '"]');
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('cb-flash');
    setTimeout(function () { el.classList.remove('cb-flash'); }, 900);
  }

  // MARKER-CAMPAIGN-V2A — preheader changes refresh the preview too.
  document.addEventListener('DOMContentLoaded', function () {
    var ph = document.getElementById('cb-preheader');
    if (ph) ph.addEventListener('input', requestPreview);
  });

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
          body: JSON.stringify({
            blocks: blocks,
            // MARKER-CAMPAIGN-V2A — preview the preheader as typed.
            preheader: (document.getElementById('cb-preheader') || {}).value || '',
            // MARKER-CAMPAIGN-HDR
            show_header: (document.getElementById('cb-show-header') || {}).checked ? 1 : 0,
            campaign_id: campaignId,
          }),
        });
        const html = await res.text();
        const iframe = document.getElementById('cb-preview-iframe');
        if (iframe) {
          const doc = iframe.contentDocument || iframe.contentWindow.document;
          doc.open(); doc.write(html); doc.close();
        }
        wirePreviewHighlight(); // MARKER-CAMPAIGN-V2D
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
      <button type="button" class="cb-tt-btn" data-cmd="link"    title="Link">🔗</button>{{-- MARKER-CAMPAIGN-V2F --}}
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
          // MARKER-AUDIENCE-POLISH — was window.prompt; the app has its own now.
          const prev = editor.getAttributes('link').href || '';
          IntakeConfirm.prompt({
            title: prev ? 'Edit this link' : 'Add a link',
            message: 'Leave it empty to remove the link.',
            value: prev,
            placeholder: 'https://example.com',
            confirmText: prev ? 'Update' : 'Add link',
          }).then(function (url) {
            if (url === null) {
              // empty means remove, and prompt() returns null for both empty
              // and cancel — so only act when the field HAD a link to clear.
              if (prev) { editor.chain().focus().unsetLink().run(); updateToolbarState(); }
              return;
            }
            let normalized = String(url).trim();
            if (!/^(https?:\/\/|mailto:)/i.test(normalized)) {
              normalized = 'https://' + normalized;
            }
            editor.chain().focus().setLink({ href: normalized }).run();
            updateToolbarState();
          });
          return;
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
    // MARKER-CAMPAIGN-V2A
    insertTag, setViewport, testSend,
    toggleHeader() { requestPreview(); }, // MARKER-CAMPAIGN-HDR
    renderSettingsPublic: renderSettings, // MARKER-CAMPAIGN-V2E — bg clear redraw

    // MARKER-CAMPAIGN-V2F — gallery images reuse the existing image picker.
    addGalleryImage() {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || block.type !== 'gallery') return;
      block.data = block.data || {};
      if (!Array.isArray(block.data.images)) block.data.images = [];
      if (block.data.images.length >= 6) return;
      galleryPending = true;
      CB.openImagePicker();
    },
    updateGalleryImage(i, key, value) {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || !Array.isArray(block.data?.images) || !block.data.images[i]) return;
      block.data.images[i][key] = value;
      syncHiddenInput(); requestPreview();
    },
    removeGalleryImage(i) {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || !Array.isArray(block.data?.images)) return;
      block.data.images.splice(i, 1);
      renderSettings(); syncHiddenInput(); requestPreview();
    },

    // MARKER-CAMPAIGN-V2C — catalog picker.
    openCatalogPicker() {
      const m = document.getElementById('cb-catalog-modal');
      if (m) m.style.display = 'flex';
      CB.searchCatalog(document.getElementById('cb-catalog-q')?.value || '');
    },
    closeCatalogPicker() {
      const m = document.getElementById('cb-catalog-modal');
      if (m) m.style.display = 'none';
    },
    async searchCatalog(q) {
      const grid = document.getElementById('cb-catalog-grid');
      if (!grid) return;
      grid.innerHTML = '<div class="cb-picker-empty">Searching…</div>';
      try {
        const res  = await fetch(catalogSearchUrl + '?q=' + encodeURIComponent(q || ''), { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        const list = data.items || [];
        if (!list.length) {
          grid.innerHTML = '<div class="cb-picker-empty">Nothing matched. Try another word.</div>';
          return;
        }
        grid.innerHTML = list.map(function (it) {
          const payload = escapeAttr(JSON.stringify(it));
          const photo = it.photo
            ? `<img src="${escapeAttr(it.photo)}" alt="" loading="lazy" style="width:100%;height:70px;object-fit:contain;background:#fff;border-radius:4px">`
            : `<div style="height:70px;background:var(--ia-surface-2);border-radius:4px"></div>`;
          return `<div class="cb-picker-item" style="cursor:pointer;padding:6px" onclick="CB.pickCatalogItem('${payload}')">
            ${photo}
            <div style="font-size:11.5px;margin-top:5px;line-height:1.3">${escapeHtml(it.name)}</div>
            <div style="font-size:10.5px;opacity:.5">${it.kind === 'product' ? 'Product' : 'Service'}${it.price ? ' · ' + escapeHtml(it.price) : ''}</div>
          </div>`;
        }).join('');
      } catch (err) {
        grid.innerHTML = '<div class="cb-picker-empty">Search failed.</div>';
      }
    },
    pickCatalogItem(payload) {
      let it;
      try { it = JSON.parse(payload); } catch (e) { return; }
      const block = blocks.find(b => b.id === selectedId);
      if (!block || block.type !== 'catalog') return;
      block.data = block.data || {};
      if (!Array.isArray(block.data.items)) block.data.items = [];
      if (block.data.items.length >= 4) return;
      // Store kind + id; the name rides along only so the settings list is
      // readable. Rendering always re-reads the catalog.
      block.data.items.push({ kind: it.kind, id: it.id, _label: it.name });
      CB.closeCatalogPicker();
      renderSettings(); syncHiddenInput(); requestPreview();
    },
    removeCatalogItem(i) {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || !Array.isArray(block.data?.items)) return;
      block.data.items.splice(i, 1);
      renderSettings(); syncHiddenInput(); requestPreview();
    },

    // MARKER-CAMPAIGN-V2B — social link repeater.
    addSocial() {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || block.type !== 'social') return;
      block.data = block.data || {};
      if (!Array.isArray(block.data.links)) block.data.links = [];
      if (block.data.links.length >= 5) return;
      block.data.links.push({ label: '', url: '' });
      renderSettings(); syncHiddenInput(); requestPreview();
    },
    updateSocial(i, key, value) {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || !Array.isArray(block.data?.links) || !block.data.links[i]) return;
      block.data.links[i][key] = value;
      syncHiddenInput(); requestPreview();
    },
    removeSocial(i) {
      const block = blocks.find(b => b.id === selectedId);
      if (!block || !Array.isArray(block.data?.links)) return;
      block.data.links.splice(i, 1);
      renderSettings(); syncHiddenInput(); requestPreview();
    },
    init() {
      renderBlocksList();
      renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    addBlock(type) {
      if (readOnly) return;
      const block = { id: uuid(), type: type, data: Object.assign({}, DEFAULTS[type] || {}) };
      // MARKER-CAMPAIGN-V2D — drop it after the selected block, not always last.
      const at = blocks.findIndex(b => b.id === selectedId);
      if (at >= 0) {
        blocks.splice(at + 1, 0, block);
      } else {
        blocks.push(block);
      }
      selectedId = block.id;
      renderBlocksList();
      renderSettings();
      syncHiddenInput();
      requestPreview();
    },

    remove(id) {
      if (readOnly) return;
      // MARKER-CAMPAIGN-SCHED — in-app dialog, no browser prompts.
      IntakeConfirm.show({
        title: 'Remove this block?',
        message: 'It comes out of the email. You can add it again from the palette.',
        confirmText: 'Remove',
        danger: true
      }).then((ok) => {
        if (!ok) return;
      blocks = blocks.filter(b => b.id !== id);
      if (selectedId === id) selectedId = blocks.length > 0 ? blocks[0].id : null;
      renderBlocksList();
      renderSettings();
      syncHiddenInput();
      requestPreview();
      });
    },

    select(id) {
      selectedId = id;
      renderBlocksList();
      renderSettings();
      revealInPreview(id); // MARKER-CAMPAIGN-V2D
    },

    // MARKER-CAMPAIGN-V2D — reorder + duplicate.
    move(id, delta) {
      if (readOnly) return;
      const i = blocks.findIndex(b => b.id === id);
      const j = i + delta;
      if (i < 0 || j < 0 || j >= blocks.length) return;
      const [moved] = blocks.splice(i, 1);
      blocks.splice(j, 0, moved);
      selectedId = id;
      renderBlocksList(); renderSettings(); syncHiddenInput(); requestPreview();
    },
    duplicate(id) {
      if (readOnly) return;
      const i = blocks.findIndex(b => b.id === id);
      if (i < 0) return;
      const copy = JSON.parse(JSON.stringify(blocks[i]));
      copy.id = uuid();
      blocks.splice(i + 1, 0, copy);
      selectedId = copy.id;
      renderBlocksList(); renderSettings(); syncHiddenInput(); requestPreview();
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
      // MARKER-CAMPAIGN-V2F — a gallery pick appends instead of replacing.
      if (block && block.type === 'gallery' && galleryPending) {
        galleryPending = false;
        block.data = block.data || {};
        if (!Array.isArray(block.data.images)) block.data.images = [];
        if (block.data.images.length < 6) block.data.images.push({ url: url, alt: '', link: '' });
        CB.closeImagePicker();
        renderSettings(); syncHiddenInput(); requestPreview();
        return;
      }
      // MARKER-CAMPAIGN-V2B — image_text uses the same picker.
      if (!block || (block.type !== 'image' && block.type !== 'image_text')) return;
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
      // MARKER-CAMPAIGN-SCHED — in-app dialog, no browser prompts.
      const ok = await IntakeConfirm.show({
        title: 'Delete this image?',
        message: 'It is removed from your campaign library. Emails already sent keep it; drafts using it will show a gap.',
        confirmText: 'Delete',
        danger: true
      });
      if (!ok) return;
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

{{-- MARKER-CAMPAIGN-SCRIPTS — these were outside every @push, so Blade
     discarded them: the pre-send checks panel never initialised and the
     send confirmation was undefined. Moved inside the scripts stack. --}}
{{-- MARKER-CAMPAIGN-SCHED --}}
<script>
function cbConfirmSend() {
  IntakeConfirm.show({
    title: 'Send this campaign now?',
    message: 'It goes out to everyone in the segment with marketing permission. This cannot be undone.',
    confirmText: 'Send now',
    danger: true
  }).then(function (ok) {
    if (ok) document.getElementById('cb-send-form').submit();
  });
}
</script>


{{-- MARKER-CAMPAIGN-CHECKS --}}
<style>
  .cbk-row { display:flex; gap:8px; align-items:flex-start; font-size:12px; padding:5px 0; }
  .cbk-dot { width:6px; height:6px; border-radius:50%; margin-top:6px; flex:0 0 auto; }
  .cbk-ok   .cbk-dot { background:#7BC96F; }
  .cbk-warn .cbk-dot { background:#E0A82E; }
  .cbk-fail .cbk-dot { background:#E0573E; }
  .cbk-label { font-weight:600; flex:0 0 auto; }
  .cbk-detail { opacity:.55; min-width:0; }
  .cbk-cost { font-size:12px; padding-top:8px; margin-top:6px; border-top:.5px solid var(--ia-border); }
  .cbk-blocked { font-size:11.5px; color:#E0573E; margin-top:8px; line-height:1.45; }
</style>
<script>
(function () {
  var box = document.getElementById('cb-checks');
  if (!box) return;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  fetch('{{ route('tenant.campaigns.checks', $campaign->id) }}', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j || !j.success) { box.innerHTML = ''; return; }

      var html = (j.rows || []).map(function (r) {
        return '<div class="cbk-row cbk-' + r.level + '"><span class="cbk-dot"></span>'
             + '<span class="cbk-label">' + esc(r.label) + '</span>'
             + '<span class="cbk-detail">' + esc(r.detail) + '</span></div>';
      }).join('');

      var a = j.audience || {};
      var skipped = (a.withEmail || 0) - (a.mailable || 0);
      html += '<div class="cbk-cost">'
            + '<strong>' + (a.mailable || 0) + '</strong> recipient' + ((a.mailable === 1) ? '' : 's')
            + ' · about $' + (a.cost || 0).toFixed(2)
            + (skipped > 0 ? '<div style="opacity:.5;font-size:11px;margin-top:3px">' + skipped + ' more have an address but no marketing permission.</div>' : '')
            + '</div>';

      if (j.blocking && j.blocking.length) {
        html += '<div class="cbk-blocked">Sending is blocked until these are fixed: ' + esc(j.blocking.join(', ')) + '.</div>';
      }

      box.innerHTML = html;

      // A blocked campaign shouldn't offer buttons that will only refuse.
      if (j.blocking && j.blocking.length) {
        document.querySelectorAll('#cb-send-form button, form[action*="/schedule"] button').forEach(function (b) {
          b.disabled = true;
          b.style.opacity = .45;
          b.title = 'Fix the blocking items above first';
        });
      }
    })
    .catch(function () { box.innerHTML = ''; });
})();
</script>

{{-- MARKER-CAMPAIGN-SCRIPTS — audience panel; was landing in the styles
     stack, so it ran in <head> before the panel existed. --}}
{{-- MARKER-CAMPAIGN-AUDIENCE --}}
<style>
  .aud-mode{display:flex;gap:6px;margin-bottom:10px}
  .aud-mode button{flex:1;background:none;border:.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text-dim);padding:6px 8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer}
  .aud-mode button.on{background:var(--ia-surface-2);color:var(--ia-text);border-color:var(--ia-border-strong)}
  .aud-note{font-size:11px;opacity:.5;line-height:1.45;margin:8px 0 0}
  .aud-join{font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;margin:2px 0 6px}
  /* MARKER-AUDIENCE-POLISH — one rule should read as one line, not a card. */
  .aud-rule{padding:8px 0;border-bottom:.5px solid var(--ia-border)}
  .aud-rule:first-child{padding-top:2px}
  .aud-rule-top{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) auto;gap:6px;align-items:center}
  .aud-rule-val{display:grid;grid-template-columns:80px minmax(0,1fr);gap:6px;margin-top:6px}
  .aud-rule .cb-field-select,.aud-rule .cb-field-input{padding:6px 8px;font-size:12px}
  /* MARKER-AUD-TAGPICK — tag chips */
  .aud-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
  .aud-tag{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:4px 10px;border-radius:100px;border:.5px solid var(--ia-border);cursor:pointer;color:var(--ia-text-muted);user-select:none}
  .aud-tag input{display:none}
  .aud-tag.on{border-color:var(--ia-accent);color:var(--ia-text);background:rgba(127,127,127,.08)}
  .aud-tag-empty{font-size:11.5px;color:var(--ia-text-dim);margin-top:8px}
  .aud-rule .x{background:none;border:0;color:var(--ia-text-dim);cursor:pointer;font-size:16px;line-height:1;padding:0 2px}
  .aud-rule .x:hover{color:#f87171}
  .aud-add{background:none;border:.5px dashed var(--ia-border-strong);color:var(--ia-text-dim);width:100%;
    border-radius:var(--ia-r-sm);padding:7px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer}
  .aud-add:hover{color:var(--ia-text)}
  .aud-count{margin-top:12px;padding:12px 13px;border-radius:var(--ia-r-sm);background:var(--ia-surface-2);border:.5px solid var(--ia-border)}
  .aud-count .big{font-size:24px;font-weight:700;line-height:1.1;letter-spacing:-.01em}
  .aud-count .sub2{font-size:12px;color:var(--ia-text-dim);margin-top:4px;line-height:1.5}
  .aud-count .warn{font-size:11.5px;color:#ffcf8b;margin-top:7px;line-height:1.45}
  .aud-saved-row{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px}
  .aud-saved-row .aud-del{margin-left:auto;background:none;border:0;color:var(--ia-text-dim);cursor:pointer;font-size:15px}
  .aud-preview{margin-top:10px;border:.5px solid var(--ia-border);border-radius:var(--ia-r-sm);font-size:12.5px}
  .aud-preview div{padding:6px 10px;border-top:.5px solid var(--ia-border)}
  .aud-preview div:first-child{border-top:0}
  .aud-preview .no{font-size:10.5px;border:.5px solid var(--ia-border-strong);border-radius:4px;padding:0 5px;margin-left:6px;opacity:.7}
</style>
<script>
(function () {
  var root = document.querySelector('[data-aud-count]');
  if (!root) return; // not a draft

  var FIELDS  = @json($audienceFields);
  var CHOICES = @json($audienceChoices);
  var TAGS    = @json($audienceTags ?? []); // MARKER-AUD-TAGPICK
  var saved0  = @json($campaign->targeting ?? ['mode' => 'all']);

  var hidden = document.getElementById('cb-targeting-json');
  var list   = document.querySelector('[data-aud-rules]');
  var mode   = saved0.mode || ((saved0.segment === 'has_appointment') ? 'rules' : 'all');
  var rules  = (saved0.rules || []).slice();
  if (!rules.length && saved0.segment === 'has_appointment') {
    rules = [{ field: 'visit_count', op: 'at_least', value: '1', unit: 'months' }];
  }

  var OPS = {
    age:    [['within', 'within the last'], ['longer_ago', 'more than']],
    number: [['at_least', 'at least'], ['at_most', 'at most']],
    money:  [['at_least', 'at least'], ['at_most', 'at most']],
    flag:   [['is', 'yes'], ['is_not', 'no']],
    text:   [['is', 'is'], ['is_not', 'is not']],
    choice: [['is', 'is'], ['is_not', 'is not']],
    tag:    [['is', 'has any of'], ['is_not', 'has none of']] // MARKER-AUD-TAGPICK
  };

  function sel(options, value, cls) {
    var s = document.createElement('select');
    s.className = cls || 'cb-field-select';
    options.forEach(function (o) {
      var opt = document.createElement('option');
      opt.value = o[0]; opt.textContent = o[1];
      if (String(value) === String(o[0])) opt.selected = true;
      s.appendChild(opt);
    });
    return s;
  }

  function drawRule(rule, i) {
    var wrap = document.createElement('div');
    wrap.className = 'aud-rule';

    var top = document.createElement('div');
    top.className = 'aud-rule-top';

    var fieldSel = sel(Object.keys(FIELDS).map(function (k) { return [k, FIELDS[k].label]; }), rule.field);
    fieldSel.addEventListener('change', function () {
      rules[i] = { field: fieldSel.value, op: '', value: '', unit: 'months' };
      render();
    });

    var type = (FIELDS[rule.field] || {}).type || 'flag';
    var opSel = sel(OPS[type] || OPS.flag, rule.op);
    opSel.addEventListener('change', function () { rules[i].op = opSel.value; refresh(); });

    var x = document.createElement('button');
    x.type = 'button'; x.className = 'x'; x.textContent = '×'; x.title = 'Remove';
    x.addEventListener('click', function () { rules.splice(i, 1); render(); });

    top.appendChild(fieldSel); top.appendChild(opSel); top.appendChild(x);
    wrap.appendChild(top);

    if (type === 'age') {
      var row = document.createElement('div');
      row.className = 'aud-rule-val';
      var num = document.createElement('input');
      num.className = 'cb-field-input'; num.type = 'number'; num.min = '0';
      num.value = rule.value || '6';
      num.addEventListener('input', function () { rules[i].value = num.value; refresh(); });
      // MARKER-AUDIENCE-POLISH — "more than 6 months" is not what the rule
      // means; it means more than 6 months AGO.
      var suffix = (rule.op === 'longer_ago') ? ' ago' : '';
      var unit = sel([
        ['days', 'days' + suffix], ['months', 'months' + suffix], ['years', 'years' + suffix]
      ], rule.unit || 'months');
      unit.addEventListener('change', function () { rules[i].unit = unit.value; refresh(); });
      row.appendChild(num); row.appendChild(unit);
      wrap.appendChild(row);
    } else if (type === 'number' || type === 'money') {
      var n2 = document.createElement('input');
      n2.className = 'cb-field-input'; n2.type = 'number'; n2.min = '0';
      n2.style.marginTop = '6px';
      n2.value = rule.value || (type === 'money' ? '100' : '1');
      n2.addEventListener('input', function () { rules[i].value = n2.value; refresh(); });
      wrap.appendChild(n2);
    } else if (type === 'text') {
      var t = document.createElement('input');
      t.className = 'cb-field-input'; t.style.marginTop = '6px';
      t.placeholder = 'Spokane';
      t.value = rule.value || '';
      t.addEventListener('input', function () { rules[i].value = t.value; refresh(); });
      wrap.appendChild(t);
    } else if (type === 'choice') {
      var opts = CHOICES[rule.field] || {};
      var c = sel(Object.keys(opts).map(function (k) { return [k, opts[k]]; }), rule.value);
      c.style.marginTop = '6px';
      c.addEventListener('change', function () { rules[i].value = c.value; refresh(); });
      wrap.appendChild(c);
    } else if (type === 'tag') {
      // MARKER-AUD-TAGPICK — chips, several allowed; value is ids joined by commas.
      var ids = Object.keys(TAGS);
      if (!ids.length) {
        var none = document.createElement('div');
        none.className = 'aud-tag-empty';
        none.textContent = 'No tags yet. Tag customers on their record, or from a discount code.';
        wrap.appendChild(none);
      } else {
        var picked = String(rule.value || '').split(',').filter(Boolean);
        var box = document.createElement('div'); box.className = 'aud-tags';
        ids.forEach(function (id) {
          var lab = document.createElement('label');
          lab.className = 'aud-tag' + (picked.indexOf(id) >= 0 ? ' on' : '');
          var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = picked.indexOf(id) >= 0;
          cb.addEventListener('change', function () {
            var cur = String(rules[i].value || '').split(',').filter(Boolean);
            if (cb.checked) { if (cur.indexOf(id) < 0) cur.push(id); }
            else { cur = cur.filter(function (x) { return x !== id; }); }
            rules[i].value = cur.join(',');
            lab.classList.toggle('on', cb.checked);
            refresh();
          });
          lab.appendChild(cb);
          lab.appendChild(document.createTextNode(TAGS[id]));
          box.appendChild(lab);
        });
        wrap.appendChild(box);
      }
    }

    return wrap;
  }

  function render() {
    if (list) {
      list.innerHTML = '';
      rules.forEach(function (r, i) { list.appendChild(drawRule(r, i)); });
    }
    refresh();
  }

  function targeting() {
    if (mode === 'rules')  return { mode: 'rules', rules: rules };
    if (mode === 'saved')  {
      var picked = document.querySelector('input[name="aud_saved"]:checked');
      return { mode: 'saved', audience_id: picked ? picked.value : '' };
    }
    return { mode: 'all' };
  }

  // MARKER-AUDIENCE-EMPTY — with nothing chosen there is no number to show, and
  // leaving the previous mode's count on screen reads as if it still applies.
  function unresolvedSaved() {
    return mode === 'saved' && !document.querySelector('input[name="aud_saved"]:checked');
  }

  function esc2(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

  var timer = null, sampleOpen = false;
  function refresh(withSample) {
    var payload = targeting();
    if (hidden) hidden.value = JSON.stringify(payload);

    // MARKER-AUDIENCE-EMPTY
    var sampleBox = document.querySelector('[data-aud-sample]');
    if (unresolvedSaved()) {
      root.hidden = true;
      if (sampleBox) sampleBox.hidden = true;
      return;
    }
    root.hidden = false;
    clearTimeout(timer);
    timer = setTimeout(function () { fetchCount(payload, withSample || sampleOpen); }, 250);
  }

  function fetchCount(payload, withSample) {
    var fd = new FormData();
    fd.append('_token', document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '');
    fd.append('targeting_json', JSON.stringify(payload));
    if (withSample) fd.append('with_sample', '1');

    fetch('{{ route('tenant.campaigns.audience.count') }}', {
      method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.success) return;
        var c = j.counts;
        root.querySelector('[data-aud-total]').innerHTML =
          c.mailable + ' <span style="font-size:12px;font-weight:500;opacity:.6">will receive this</span>';
        root.querySelector('[data-aud-sub]').innerHTML =
          esc2(c.matched + ' match, ' + c.mailable + ' have marketing permission') +
          '<br>' + esc2('About $' + (c.mailable * j.rate).toFixed(2) + ' to send');
        var warn = root.querySelector('[data-aud-warn]');
        if (c.blocked > 0) {
          warn.hidden = false;
          warn.textContent = c.blocked + " match but haven't given permission, so they're skipped. You can confirm permission for imported contacts on Contacts & consent.";
        } else { warn.hidden = true; }

        var box = document.querySelector('[data-aud-sample]');
        if (withSample && box) {
          box.hidden = false;
          box.innerHTML = '';
          (j.sample || []).forEach(function (p) {
            var d = document.createElement('div');
            d.textContent = p.name || p.email || '—';
            if (!p.mailable) {
              var tag = document.createElement('span');
              tag.className = 'no'; tag.textContent = 'no permission';
              d.appendChild(tag);
            }
            box.appendChild(d);
          });
          if (!(j.sample || []).length) box.innerHTML = '<div>Nobody matches these rules yet.</div>';
        }
      })
      .catch(function () {});
  }

  document.querySelectorAll('[data-aud-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      mode = b.dataset.audMode;
      document.querySelectorAll('[data-aud-mode]').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('[data-aud-panel]').forEach(function (p) { p.hidden = p.dataset.audPanel !== mode; });
      if (mode === 'rules' && !rules.length) { rules = [{ field: 'last_visit', op: 'longer_ago', value: '6', unit: 'months' }]; }
      render();
    });
  });

  document.querySelectorAll('input[name="aud_saved"]').forEach(function (r) {
    r.addEventListener('change', function () { refresh(); });
  });

  var addBtn = document.querySelector('[data-aud-add]');
  if (addBtn) addBtn.addEventListener('click', function () {
    rules.push({ field: 'visit_count', op: 'at_least', value: '1', unit: 'months' });
    render();
  });

  var prevBtn = document.querySelector('[data-aud-preview]');
  if (prevBtn) prevBtn.addEventListener('click', function () { sampleOpen = true; refresh(true); });

  var saveBtn = document.querySelector('[data-aud-save]');
  if (saveBtn) saveBtn.addEventListener('click', function () {
    if (mode !== 'rules' || !rules.length) {
      IntakeConfirm.alert({ title: 'Nothing to save', message: 'Build a list first, then save it.' });
      return;
    }
    // MARKER-AUDIENCE-POLISH — IntakeConfirm.prompt exists now, so no
    // window.prompt fallback: house rule is no native dialogs.
    IntakeConfirm.prompt({
      title: 'Save this audience',
      message: 'Give it a name you will recognise on the next campaign.',
      placeholder: 'Lapsed riders',
      confirmText: 'Save'
    }).then(function (name) {
      if (!name) return;
      var fd = new FormData();
      fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
      fd.append('name', name);
      fd.append('rules_json', JSON.stringify(rules));
      fetch('{{ route('tenant.campaigns.audience.save') }}', {
        method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function () { location.reload(); });
    });
  });

  document.querySelectorAll('[data-aud-delete]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      IntakeConfirm.show({
        title: 'Delete this audience?',
        message: 'Campaigns already sent are unaffected. Any draft using it falls back to everyone with permission.',
        confirmText: 'Delete',
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
        fd.append('_method', 'DELETE');
        fetch('/admin/campaign-audience/' + b.dataset.audDelete, {
          method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
        }).then(function () { location.reload(); });
      });
    });
  });

  render();
})();
</script>

@endpush
