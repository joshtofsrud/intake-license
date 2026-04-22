@php
  $pageTitle = 'Edit: ' . $page->title;
  // Marketing-aware URL helpers. When editing a platform tenant page from
  // the master admin, tenant.* routes require a subdomain param and
  // tenant_url() points to a tenant subdomain we don't have. Swap for the
  // admin routes + root-domain URLs when $isMarketing is true.
  $isMarketing = $isMarketing ?? false;
  $layoutName = $isMarketing ? 'layouts.admin.page-editor' : 'layouts.tenant.app';
  $backUrl = $isMarketing
      ? url('/admin/marketing-pages')
      : route('tenant.pages.index');
  $previewUrl = $isMarketing
      ? 'https://' . config('intake.domain', 'intake.works') . '/' . ($page->is_home ? '' : $page->slug)
      : tenant_url($page->is_home ? '' : $page->slug);
  $storeUrl = $isMarketing
      ? url('/admin/marketing-pages/store')
      : route('tenant.pages.store');
@endphp

@extends($layoutName)

@push('styles')
<style>
.pb-editor { display: grid; grid-template-columns: 320px 1fr 280px; gap: 0; height: calc(100vh - 130px); margin: -24px -24px 0; }
.pb-col { overflow-y: auto; border-right: 0.5px solid var(--ia-border); padding: 20px; }
.pb-col:last-child { border-right: none; }
.pb-col-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 600; opacity: .35; margin-bottom: 14px; }

.pb-preview-col { display: flex; flex-direction: column; padding: 0; border-right: 0.5px solid var(--ia-border); background: #f5f5f5; }
.pb-preview-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 0.5px solid var(--ia-border); background: var(--ia-surface); }
.pb-preview-toolbar-left { display: flex; align-items: center; gap: 8px; }
.pb-preview-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 600; opacity: .35; }
.pb-device-btn { background: none; border: none; color: var(--ia-text); opacity: .3; cursor: pointer; padding: 4px 6px; border-radius: 4px; font-size: 16px; }
.pb-device-btn.active { opacity: .8; background: rgba(255,255,255,.06); }
.pb-device-btn:hover { opacity: .6; }
.pb-preview-frame-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 16px; overflow: auto; }
.pb-preview-frame { border: none; background: #fff; border-radius: 8px; box-shadow: 0 2px 20px rgba(0,0,0,.15); transition: width .3s, height .3s; width: 100%; height: 100%; }
.pb-preview-frame.mobile { width: 375px; height: 100%; }

.pb-section-block { border-radius: var(--ia-r-lg); border: 0.5px solid var(--ia-border); background: var(--ia-surface); margin-bottom: 8px; overflow: hidden; }
.pb-section-block.active { border-color: var(--ia-accent); }
.pb-section-head { display: flex; align-items: center; gap: 8px; padding: 10px 14px; cursor: pointer; border-bottom: 0.5px solid transparent; }
.pb-section-block.open .pb-section-head { border-bottom-color: var(--ia-border); }
.pb-drag-handle { cursor: grab; opacity: .3; padding: 2px 4px; font-size: 14px; letter-spacing: -3px; user-select: none; }
.pb-drag-handle:active { cursor: grabbing; }
.pb-section-type { font-size: 12px; font-weight: 500; flex: 1; text-transform: capitalize; }
.pb-section-chevron { opacity: .4; font-size: 10px; transition: transform .15s; }
.pb-section-block.open .pb-section-chevron { transform: rotate(180deg); }
.pb-section-body { display: none; padding: 14px; }
.pb-section-block.open .pb-section-body { display: block; }
.pb-field-row { margin-bottom: 10px; }
.pb-field-label { font-size: 10px; opacity: .4; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; font-weight: 500; }
.pb-input { width: 100%; padding: 6px 10px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: var(--ia-input-bg); color: var(--ia-text); font-size: 13px; }
.pb-input:focus { outline: none; border-color: var(--ia-accent); }
.pb-textarea { width: 100%; padding: 6px 10px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: var(--ia-input-bg); color: var(--ia-text); font-size: 13px; resize: vertical; min-height: 60px; font-family: inherit; }
.pb-section-actions { display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 0.5px solid var(--ia-border); }

.pb-add-section { padding: 12px; border-radius: var(--ia-r-lg); border: 0.5px dashed var(--ia-border); text-align: center; cursor: pointer; font-size: 13px; opacity: .5; margin-top: 4px; }
.pb-add-section:hover { opacity: 1; border-color: var(--ia-accent); }
.pb-add-panel { display: none; margin-top: 8px; }
.pb-add-panel.open { display: block; }
.pb-type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.pb-type-btn { padding: 8px 10px; border-radius: var(--ia-r-md); border: 0.5px solid var(--ia-border); background: transparent; color: var(--ia-text); font-size: 11px; cursor: pointer; text-align: left; }
.pb-type-btn:hover { border-color: var(--ia-accent); background: var(--ia-accent-soft); }
.pb-type-icon { font-size: 14px; margin-bottom: 2px; display: block; }

.pb-save-btn { width: 100%; justify-content: center; }
.nav-item-row { display: flex; gap: 6px; margin-bottom: 6px; align-items: center; }

.pb-status { position: fixed; bottom: 20px; right: 20px; padding: 8px 16px; border-radius: 8px; font-size: 13px; background: #0a0a0a; color: #BEF264; z-index: 9999; opacity: 0; transition: opacity .3s; pointer-events: none; }

@media (max-width: 1100px) {
  .pb-editor { grid-template-columns: 280px 1fr; }
  .pb-editor > .pb-col:last-child { display: none; }
}
@media (max-width: 768px) {
  .pb-editor { grid-template-columns: 1fr; height: auto; }
  .pb-preview-col { min-height: 400px; }
}
</style>
@endpush

@section('content')

<div class="ia-page-head" style="margin-bottom:0">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title" style="font-size:16px">{{ $page->title }}</h1>
    <p class="ia-page-subtitle" style="font-size:12px">
      {{ $page->is_home ? '/' : '/' . $page->slug }} ·
      @if($page->is_published)
        <span style="color:#3B6D11">Published</span>
      @else
        <span style="opacity:.5">Draft</span>
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ $backUrl }}" class="ia-btn ia-btn--ghost ia-btn--sm">← {{ $isMarketing ? 'Marketing pages' : 'Pages' }}</a>
    <a href="{{ $previewUrl }}" target="_blank" class="ia-btn ia-btn--secondary ia-btn--sm">Open in new tab ↗</a>
    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" onclick="savePageSettings()">Save changes</button>
  </div>
</div>

<div class="pb-editor">

  {{-- LEFT: Sections --}}
  <div class="pb-col">
    <div class="pb-col-label">Sections</div>

    <div id="pb-canvas">
      @foreach($sections as $section)
        @include('tenant.pages._section', ['section' => $section])
      @endforeach
    </div>

    <div class="pb-add-section" id="pb-add-trigger">+ Add section</div>

    <div class="pb-add-panel" id="pb-add-panel">
      <div class="pb-type-grid">
        @php
          $typeIcons  = ['hero'=>'🖼','services'=>'⚙','text_image'=>'📝','cta_banner'=>'📣','image_gallery'=>'🖼','contact_form'=>'✉','booking_embed'=>'📅','pricing_table'=>'💲','feature_grid'=>'▦','step_timeline'=>'🔢','testimonial_carousel'=>'💬','logo_bar'=>'⚑','faq_accordion'=>'❓','comparison_table'=>'📊','industry_pack_showcase'=>'🏷','stats_row'=>'📈'];
          $typeLabels = ['hero'=>'Hero','services'=>'Services','text_image'=>'Text + Image','cta_banner'=>'CTA Banner','image_gallery'=>'Gallery','contact_form'=>'Contact','booking_embed'=>'Booking','pricing_table'=>'Pricing','feature_grid'=>'Feature grid','step_timeline'=>'Step timeline','testimonial_carousel'=>'Testimonials','logo_bar'=>'Logo bar','faq_accordion'=>'FAQ','comparison_table'=>'Comparison','industry_pack_showcase'=>'Industries','stats_row'=>'Stats'];

          // Filter section list based on context — tenant sites don't need marketing
          // sections, and marketing pages don't need "services" or "booking_embed"
          // since those require a real tenant catalog.
          if ($isMarketing) {
              unset($typeLabels['services'], $typeLabels['booking_embed']);
          } else {
              // Tenant editor: hide marketing-specific types for now
              foreach (['pricing_table','feature_grid','step_timeline','testimonial_carousel','logo_bar','faq_accordion','comparison_table','industry_pack_showcase','stats_row'] as $t) {
                  unset($typeLabels[$t]);
              }
          }
        @endphp
        @foreach($typeLabels as $type => $label)
          <button type="button" class="pb-type-btn" onclick="addSection('{{ $type }}')">
            <span class="pb-type-icon">{{ $typeIcons[$type] ?? '□' }}</span>{{ $label }}
          </button>
        @endforeach
      </div>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" style="margin-top:8px;width:100%"
        onclick="document.getElementById('pb-add-panel').classList.remove('open');document.getElementById('pb-add-trigger').style.display=''">
        Cancel
      </button>
    </div>
  </div>

  {{-- CENTER: Live Preview --}}
  <div class="pb-preview-col">
    <div class="pb-preview-toolbar">
      <div class="pb-preview-toolbar-left">
        <span class="pb-preview-label">Live Preview</span>
      </div>
      <div>
        <button type="button" class="pb-device-btn active" onclick="setDevice('desktop',this)" title="Desktop">🖥</button>
        <button type="button" class="pb-device-btn" onclick="setDevice('mobile',this)" title="Mobile">📱</button>
      </div>
    </div>
    <div class="pb-preview-frame-wrap">
      <iframe id="pb-preview" class="pb-preview-frame" src="{{ $previewUrl }}"></iframe>
    </div>
  </div>

  {{-- RIGHT: Page Settings --}}
  <div class="pb-col">
    <div class="pb-col-label">Page Settings</div>

    <div class="pb-field-row">
      <div class="pb-field-label">Title</div>
      <input type="text" class="pb-input" id="pg-title" value="{{ $page->title }}">
    </div>
    <div class="pb-field-row">
      <div class="pb-field-label">Meta title</div>
      <input type="text" class="pb-input" id="pg-meta-title" value="{{ $page->meta_title }}" placeholder="Defaults to page title">
    </div>
    <div class="pb-field-row">
      <div class="pb-field-label">Meta description</div>
      <textarea class="pb-textarea" id="pg-meta-desc" rows="2" placeholder="Short description…">{{ $page->meta_description }}</textarea>
    </div>
    <div class="pb-field-row" style="display:flex;gap:16px">
      <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
        <input type="checkbox" id="pg-published" {{ $page->is_published ? 'checked' : '' }}> Published
      </label>
      @if(!$page->is_home)
      <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
        <input type="checkbox" id="pg-in-nav" {{ $page->is_in_nav ? 'checked' : '' }}> In nav
      </label>
      @endif
    </div>

    <div style="border-top:0.5px solid var(--ia-border);margin:16px 0;padding-top:16px">
      <div class="pb-col-label">Navigation</div>
      <div id="nav-items-list">
        @foreach($navItems as $i => $navItem)
          <div class="nav-item-row">
            <input type="text" class="pb-input" data-nav-label="{{ $i }}" value="{{ $navItem->label }}" placeholder="Label" style="flex:1">
            <input type="text" class="pb-input" data-nav-url="{{ $i }}" value="{{ $navItem->url }}" placeholder="/page" style="flex:1">
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="this.closest('.nav-item-row').remove()" style="padding:4px 8px">×</button>
          </div>
        @endforeach
      </div>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" style="margin-bottom:10px;width:100%" onclick="addNavItem()">+ Add link</button>
      <button type="button" class="ia-btn ia-btn--secondary pb-save-btn ia-btn--sm" onclick="saveNav()">Save nav</button>
    </div>
  </div>

</div>

<div class="pb-status" id="pb-status"></div>

@endsection

@push('scripts')
<script>
var pageId    = '{{ $page->id }}';
var csrf      = window.IntakeAdmin.csrfToken;
var navCount  = {{ $navItems->count() }};
var storeUrl  = '{{ $storeUrl }}';
var previewUrl= '{{ $previewUrl }}';
var refreshTimer = null;

function refreshPreview() {
  clearTimeout(refreshTimer);
  refreshTimer = setTimeout(function() {
    var iframe = document.getElementById('pb-preview');
    iframe.src = previewUrl + '?t=' + Date.now();
  }, 1000);
}

function setDevice(mode, btn) {
  document.querySelectorAll('.pb-device-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
  var frame = document.getElementById('pb-preview');
  if (mode === 'mobile') { frame.classList.add('mobile'); }
  else { frame.classList.remove('mobile'); }
}

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

  fetch(storeUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(resp) { if (resp.success) window.location.reload(); });
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

  // Keep text-input "_text" shadow in sync with color picker value when text input has valid hex.
  // This lets admins type a hex directly or pick via color input, and both stay in lockstep.
  body.querySelectorAll('input[data-field$="_text"]').forEach(function (textInput) {
    var baseField = textInput.getAttribute('data-field').replace(/_text$/, '');
    var picker = body.querySelector('input[data-field="' + baseField + '"][type="color"]');
    if (!picker) return;
    var txt = (textInput.value || '').trim();
    if (/^#[0-9a-fA-F]{6}$/.test(txt)) {
      picker.value = txt;
    } else if (txt === '') {
      // Blank text = clear the color override. Leave picker alone but don't send the field.
      picker.setAttribute('data-blank', '1');
    } else {
      picker.removeAttribute('data-blank');
    }
  });

  body.querySelectorAll('[data-field]').forEach(function (el) {
    var field = el.getAttribute('data-field');
    // Skip the "_text" shadow fields — they're only for UI, not persisted.
    if (field.endsWith('_text')) return;
    // Skip color pickers that have been blanked by the text input.
    if (el.type === 'color' && el.getAttribute('data-blank') === '1') {
      content[field] = '';
      return;
    }
    if (el.type === 'checkbox') content[field] = el.checked ? '1' : '0';
    else content[field] = el.value;
  });

  // Promote bg_color out of content — server persists it to its own column.
  var bgColor = content.bg_color;
  delete content.bg_color;

  // Promote padding_override out of content if set — it maps to the section's padding column.
  var paddingOverride = content.padding_override;
  // Keep padding_override IN content so we can support per-block override without touching the column.
  // The renderer prefers $c['padding_override'] over $section->padding.

  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('section_op', 'update');
  fd.append('page_id', pageId);
  fd.append('section_id', sectionId);
  fd.append('is_visible', body.querySelector('[data-field="is_visible"]')?.checked ? 1 : 0);
  if (bgColor !== undefined) fd.append('bg_color', bgColor);
  Object.keys(content).forEach(function (k) { fd.append('content[' + k + ']', content[k]); });

  fetch(storeUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      if (resp.success) { showStatus('Saved ✓'); refreshPreview(); }
    });
}

document.querySelectorAll('.pb-delete-section').forEach(function (btn) {
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (!confirm('Delete this section?')) return;
    var sectionId = btn.getAttribute('data-section-id');
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('section_op', 'delete');
    fd.append('page_id', pageId);
    fd.append('section_id', sectionId);

    fetch(storeUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function() { btn.closest('.pb-section-block').remove(); refreshPreview(); });
  });
});

var canvas = document.getElementById('pb-canvas');
var dragging = null;

canvas.querySelectorAll('.pb-drag-handle').forEach(function (handle) {
  handle.addEventListener('mousedown', function (e) {
    e.preventDefault();
    e.stopPropagation();
    dragging = handle.closest('.pb-section-block');
    dragging.classList.add('dragging');
    dragging.style.opacity = '.5';

    var onMove = function (e2) {
      var target = document.elementFromPoint(e2.clientX, e2.clientY);
      var over = target ? target.closest('.pb-section-block') : null;
      if (over && over !== dragging) {
        var all = Array.from(canvas.querySelectorAll('.pb-section-block'));
        var di = all.indexOf(dragging);
        var oi = all.indexOf(over);
        canvas.insertBefore(dragging, di < oi ? over.nextSibling : over);
      }
    };

    var onUp = function () {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      dragging.style.opacity = '';
      dragging.classList.remove('dragging');

      var order = Array.from(canvas.querySelectorAll('.pb-section-block'))
        .map(function (b) { return b.getAttribute('data-section-id'); });

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('section_op', 'reorder');
      fd.append('page_id', pageId);
      order.forEach(function (id) { fd.append('order[]', id); });

      fetch(storeUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function() { refreshPreview(); });

      dragging = null;
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
});

function savePageSettings() {
  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('update', pageId);
  fd.append('op', 'update_page');
  fd.append('title', document.getElementById('pg-title').value);
  fd.append('meta_title', document.getElementById('pg-meta-title').value);
  fd.append('meta_description', document.getElementById('pg-meta-desc').value);
  fd.append('is_published', document.getElementById('pg-published').checked ? 1 : 0);
  var navEl = document.getElementById('pg-in-nav');
  if (navEl) fd.append('is_in_nav', navEl.checked ? 1 : 0);

  fetch(storeUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      if (resp.ok) showStatus('Settings saved ✓');
      else showStatus('Error saving');
    });
}

function addNavItem() {
  var list = document.getElementById('nav-items-list');
  var row = document.createElement('div');
  row.className = 'nav-item-row';
  row.innerHTML =
    '<input type="text" class="pb-input" data-nav-label="' + navCount + '" placeholder="Label" style="flex:1">' +
    '<input type="text" class="pb-input" data-nav-url="' + navCount + '" placeholder="/page" style="flex:1">' +
    '<button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="this.closest(\'.nav-item-row\').remove()" style="padding:4px 8px">×</button>';
  list.appendChild(row);
  navCount++;
}

function saveNav() {
  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('update', pageId);
  fd.append('op', 'update_nav');

  var rows = document.querySelectorAll('.nav-item-row');
  rows.forEach(function(row, i) {
    var label = row.querySelector('[data-nav-label]');
    var url = row.querySelector('[data-nav-url]');
    if (label && label.value) {
      fd.append('nav_items[' + i + '][label]', label.value);
      fd.append('nav_items[' + i + '][url]', url ? url.value : '/');
    }
  });

  fetch(storeUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      if (resp.ok) { showStatus('Nav saved ✓'); refreshPreview(); }
    });
}

function uploadImage(fileInput, type, targetId) {
  var file = fileInput.files[0];
  if (!file) return;
  var fd = new FormData();
  fd.append('_token', csrf);
  fd.append('file', file);
  fd.append('type', type);
  showStatus('Uploading…');
  fetch('/admin/uploads', {
    method: 'POST', body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin'
  })
  .then(function(r) { return r.json(); })
  .then(function(resp) {
    if (resp.ok) {
      document.getElementById(targetId).value = resp.url;
      document.getElementById(targetId).dispatchEvent(new Event('input'));
      showStatus('Uploaded ✓');
    } else { showStatus('Upload failed: ' + (resp.message || 'error')); }
  })
  .catch(function(e) { showStatus('Upload error: ' + e.message); });
  fileInput.value = '';
}

function showStatus(msg) {
  var el = document.getElementById('pb-status');
  el.textContent = msg;
  el.style.opacity = 1;
  clearTimeout(el._t);
  el._t = setTimeout(function () { el.style.opacity = 0; }, 2000);
}
</script>
@endpush
