@extends('layouts.tenant.app')
@php $pageTitle = 'Edit: ' . $page->title; @endphp

@push('styles')
<style>
.pb-layout { display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: start; }
.pb-canvas { min-height: 400px; }
.pb-section-block { border-radius: var(--ia-r-lg); border: 0.5px solid var(--ia-border); background: var(--ia-surface); margin-bottom: 10px; overflow: hidden; transition: box-shadow var(--ia-t); }
.pb-section-block.dragging { opacity: .5; }
.pb-section-block.drag-over { border-color: var(--ia-accent); }
.pb-section-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; cursor: pointer; border-bottom: 0.5px solid transparent; transition: border-color var(--ia-t); }
.pb-section-block.open .pb-section-head { border-bottom-color: var(--ia-border); }
.pb-drag-handle { cursor: grab; opacity: .3; padding: 2px 4px; font-size: 16px; letter-spacing: -3px; user-select: none; }
.pb-drag-handle:active { cursor: grabbing; }
.pb-section-type { font-size: 12px; font-weight: 500; flex: 1; text-transform: capitalize; }
.pb-section-chevron { opacity: .4; font-size: 11px; transition: transform var(--ia-t); }
.pb-section-block.open .pb-section-chevron { transform: rotate(180deg); }
.pb-section-body { display: none; padding: 16px; }
.pb-section-block.open .pb-section-body { display: block; }
.pb-field-row { margin-bottom: 12px; }
.pb-field-label { font-size: 11px; opacity: .4; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
.pb-input { width: 100%; padding: 7px 10px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: var(--ia-input-bg); color: var(--ia-text); font-size: 13px; }
.pb-input:focus { outline: none; border-color: var(--ia-accent); }
.pb-textarea { width: 100%; padding: 7px 10px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: var(--ia-input-bg); color: var(--ia-text); font-size: 13px; resize: vertical; min-height: 72px; font-family: inherit; }
.pb-section-actions { display: flex; justify-content: space-between; margin-top: 14px; padding-top: 12px; border-top: 0.5px solid var(--ia-border); }
.pb-type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pb-type-btn { padding: 10px 12px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: transparent; color: var(--ia-text); font-size: 12px; cursor: pointer; text-align: left; transition: all var(--ia-t); }
.pb-type-btn:hover { border-color: var(--ia-accent); background: var(--ia-accent-soft); }
.pb-type-icon { font-size: 16px; margin-bottom: 4px; display: block; }
.pb-add-section { margin-top: 10px; padding: 16px; border-radius: var(--ia-r-lg); border: 0.5px dashed var(--ia-border); text-align: center; cursor: pointer; transition: all var(--ia-t); font-size: 13px; opacity: .5; }
.pb-add-section:hover { opacity: 1; border-color: var(--ia-accent); }
.pb-add-panel { display: none; }
.pb-add-panel.open { display: block; }
.pb-save-btn { width: 100%; justify-content: center; }
.nav-item-row { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
@media (max-width: 900px) { .pb-layout { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">
      Page builder
    </div>
    <h1 class="ia-page-title">{{ $page->title }}</h1>
    <p class="ia-page-subtitle">
      {{ $page->is_home ? '/' : '/' . $page->slug }} ·
      @if($page->is_published)
        <span style="color:#3B6D11">Published</span>
      @else
        <span style="opacity:.5">Draft</span>
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.pages.index') }}" class="ia-btn ia-btn--ghost">← Pages</a>
    <a href="{{ tenant_url($page->is_home ? '' : $page->slug) }}" target="_blank"
       class="ia-btn ia-btn--secondary">Preview →</a>
  </div>
</div>

<div class="pb-layout">

  <div>
    <div class="pb-canvas" id="pb-canvas">
      @foreach($sections as $section)
        @include('tenant.pages._section', ['section' => $section])
      @endforeach
    </div>

    <div class="pb-add-section" id="pb-add-trigger">
      + Add section
    </div>

    <div class="pb-add-panel ia-card" id="pb-add-panel" style="margin-top:10px">
      <div style="font-size:12px;font-weight:500;opacity:.5;text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">
        Choose a section type
      </div>
      <div class="pb-type-grid">
        @php
          $typeIcons = [
            'hero' => '🖼', 'services' => '⚙', 'text_image' => '📝',
            'cta_banner' => '📣', 'image_gallery' => '🖼',
            'contact_form' => '✉', 'booking_embed' => '📅',
          ];
          $typeLabels = [
            'hero' => 'Hero', 'services' => 'Services preview',
            'text_image' => 'Text + image', 'cta_banner' => 'CTA banner',
            'image_gallery' => 'Image gallery', 'contact_form' => 'Contact form',
            'booking_embed' => 'Booking form',
          ];
        @endphp
        @foreach($typeLabels as $type => $label)
          <button type="button" class="pb-type-btn" onclick="addSection('{{ $type }}')">
            <span class="pb-type-icon" style="font-size:16px">{{ $typeIcons[$type] ?? '□' }}</span>
            {{ $label }}
          </button>
        @endforeach
      </div>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" style="margin-top:10px;width:100%"
        onclick="document.getElementById('pb-add-panel').classList.remove('open');document.getElementById('pb-add-trigger').style.display=''">
        Cancel
      </button>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">

    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Page settings
      </div>
      <form method="POST" action="{{ route('tenant.pages.store', ['update' => $page->id]) }}">
        @csrf
        <input type="hidden" name="op" value="update_page">
        <div class="ia-form-group">
          <label class="ia-form-label">Title</label>
          <input type="text" name="title" class="ia-input" value="{{ $page->title }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Meta title</label>
          <input type="text" name="meta_title" class="ia-input" value="{{ $page->meta_title }}"
            placeholder="Defaults to page title">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Meta description</label>
          <textarea name="meta_description" class="pb-textarea" rows="2"
            placeholder="Short description for search engines…">{{ $page->meta_description }}</textarea>
        </div>
        <div style="display:flex;gap:16px;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
            <input type="hidden" name="is_published" value="0">
            <input type="checkbox" name="is_published" value="1" {{ $page->is_published ? 'checked' : '' }}>
            Published
          </label>
          @if(!$page->is_home)
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
              <input type="hidden" name="is_in_nav" value="0">
              <input type="checkbox" name="is_in_nav" value="1" {{ $page->is_in_nav ? 'checked' : '' }}>
              Show in nav
            </label>
          @endif
        </div>
        <button type="submit" class="ia-btn ia-btn--primary pb-save-btn">Save settings</button>
      </form>
    </div>

    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Navigation
      </div>
      <form method="POST" action="{{ route('tenant.pages.store', ['update' => $page->id]) }}" id="nav-form">
        @csrf
        <input type="hidden" name="op" value="update_nav">

        <div id="nav-items-list">
          @foreach($navItems as $i => $navItem)
            <div class="nav-item-row">
              <input type="text" name="nav_items[{{ $i }}][label]" class="pb-input"
                value="{{ $navItem->label }}" placeholder="Label" style="flex:1">
              <input type="text" name="nav_items[{{ $i }}][url]" class="pb-input"
                value="{{ $navItem->url }}" placeholder="/page" style="flex:1">
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                onclick="this.closest('.nav-item-row').remove()">×</button>
            </div>
          @endforeach
        </div>

        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
          style="margin-bottom:10px" onclick="addNavItem()">+ Add link</button>

        <button type="submit" class="ia-btn ia-btn--secondary pb-save-btn">Save nav</button>
      </form>
    </div>

  </div>
</div>

@endsection

@push('scripts')
<script>
var pageId   = '{{ $page->id }}';
var csrf     = window.IntakeAdmin.csrfToken;
var navCount = {{ $navItems->count() }};
var storeUrl = '{{ route("tenant.pages.store") }}';

document.getElementById('pb-add-trigger').addEventListener('click', function () {
  document.getElementById('pb-add-panel').classList.add('open');
  this.style.display = 'none';
});

function addSection(type) {
  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('section_op', 'add');
  fd.append('page_id', pageId);
  fd.append('type', type);

  fetch(storeUrl, {
    method: 'POST', body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function (r) { return r.json(); })
  .then(function (resp) {
    if (resp.success) window.location.reload();
  });
}

document.querySelectorAll('.pb-section-head').forEach(function (head) {
  head.addEventListener('click', function () {
    head.closest('.pb-section-block').classList.toggle('open');
  });
});

var saveTimers = {};

document.querySelectorAll('.pb-section-body').forEach(function (body) {
  var sectionId = body.getAttribute('data-section-id');
  body.querySelectorAll('input, textarea, select').forEach(function (input) {
    input.addEventListener('input', function () {
      clearTimeout(saveTimers[sectionId]);
      saveTimers[sectionId] = setTimeout(function () { saveSection(sectionId, body); }, 800);
    });
    input.addEventListener('change', function () {
      clearTimeout(saveTimers[sectionId]);
      saveTimers[sectionId] = setTimeout(function () { saveSection(sectionId, body); }, 100);
    });
  });
});

function saveSection(sectionId, body) {
  var content = {};
  body.querySelectorAll('[data-field]').forEach(function (el) {
    content[el.getAttribute('data-field')] = el.value;
  });

  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('section_op', 'update');
  fd.append('page_id', pageId);
  fd.append('section_id', sectionId);
  fd.append('is_visible', body.querySelector('[data-field="is_visible"]')?.value ?? 1);
  Object.keys(content).forEach(function (k) { fd.append('content[' + k + ']', content[k]); });

  fetch(storeUrl, {
    method: 'POST', body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  }).then(function (r) { return r.json(); })
    .then(function (resp) {
      if (resp.success) showStatus('Saved ✓');
    });
}

document.querySelectorAll('.pb-delete-section').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (!confirm('Delete this section?')) return;
    var sectionId = btn.getAttribute('data-section-id');
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('section_op', 'delete');
    fd.append('page_id', pageId);
    fd.append('section_id', sectionId);

    fetch(storeUrl, {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function () {
      btn.closest('.pb-section-block').remove();
    });
  });
});

var canvas   = document.getElementById('pb-canvas');
var dragging = null;

canvas.querySelectorAll('.pb-drag-handle').forEach(function (handle) {
  handle.addEventListener('mousedown', function (e) {
    e.preventDefault();
    dragging = handle.closest('.pb-section-block');
    dragging.classList.add('dragging');

    var onMove = function (e2) {
      var target = document.elementFromPoint(e2.clientX, e2.clientY);
      var over   = target ? target.closest('.pb-section-block') : null;
      if (over && over !== dragging) {
        var all = Array.from(canvas.querySelectorAll('.pb-section-block'));
        var di  = all.indexOf(dragging);
        var oi  = all.indexOf(over);
        canvas.insertBefore(dragging, di < oi ? over.nextSibling : over);
      }
    };

    var onUp = function () {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      dragging.classList.remove('dragging');

      var order = Array.from(canvas.querySelectorAll('.pb-section-block'))
        .map(function (b) { return b.getAttribute('data-section-id'); });

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('section_op', 'reorder');
      fd.append('page_id', pageId);
      order.forEach(function (id) { fd.append('order[]', id); });

      fetch(storeUrl, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      dragging = null;
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
});

function addNavItem() {
  var list = document.getElementById('nav-items-list');
  var row  = document.createElement('div');
  row.className = 'nav-item-row';
  row.innerHTML =
    '<input type="text" name="nav_items[' + navCount + '][label]" class="pb-input" placeholder="Label" style="flex:1">' +
    '<input type="text" name="nav_items[' + navCount + '][url]" class="pb-input" placeholder="/page" style="flex:1">' +
    '<button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="this.closest(\'.nav-item-row\').remove()">×</button>';
  list.appendChild(row);
  navCount++;
}

function showStatus(msg) {
  var el = document.getElementById('pb-status');
  if (!el) {
    el = document.createElement('div');
    el.id = 'pb-status';
    el.style.cssText = 'position:fixed;bottom:20px;right:20px;padding:8px 16px;border-radius:8px;font-size:13px;background:#0a0a0a;color:#BEF264;z-index:9999;transition:opacity .3s';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.style.opacity = 1;
  clearTimeout(el._t);
  el._t = setTimeout(function () { el.style.opacity = 0; }, 2000);
}
</script>
@endpush
