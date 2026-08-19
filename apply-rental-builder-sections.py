#!/usr/bin/env python3
"""Rental builder sections: rental_spotlight (single model hero),
rental_categories (checkbox-picked, drag-ordered category grid), and
rental_browse (embeddable live availability browse). Plus ?category=
filter on /rentals so category tiles land pre-filtered.
Run from repo root: python3 apply-rental-builder-sections.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    os.makedirs(os.path.dirname(os.path.join(ROOT, p)), exist_ok=True)
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")
def newfile(p, content, label):
    if os.path.exists(os.path.join(ROOT, p)):
        print(f"SKIP (exists): {label}"); return
    write(p, content)
    print(f"OK: {label}")

PBC  = 'app/Http/Controllers/Tenant/PageBuilderController.php'
EDIT = 'resources/views/tenant/pages/edit.blade.php'
RBC  = 'app/Http/Controllers/Tenant/RentalBrowseController.php'

# ============================================================
# 1) Section DEFAULTS
# ============================================================
sub(PBC,
    """        'rentals_showcase' => ['eyebrow'=>'','heading'=>'Rent the good stuff','body'=>'','category_id'=>'','max_models'=>6,'show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Check availability','cta_url'=>'/rentals','bg_color'=>''],""",
    """        'rentals_showcase' => ['eyebrow'=>'','heading'=>'Rent the good stuff','body'=>'','category_id'=>'','max_models'=>6,'show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Check availability','cta_url'=>'/rentals','bg_color'=>''],
        // MARKER-RENTAL-SECTIONS — tenant-composed rental pages: single-model
        // spotlight, checkbox+drag category grid, embeddable live browse.
        'rental_spotlight'  => ['eyebrow'=>'','heading'=>'','body'=>'','model_id'=>'','image_url'=>'','image_alt'=>'','show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Reserve','cta_url'=>'','bg_color'=>''],
        'rental_categories' => ['eyebrow'=>'','heading'=>'Rent by category','body'=>'','category_ids'=>'[]','show_counts'=>'1','bg_color'=>''],
        'rental_browse'     => ['eyebrow'=>'','heading'=>'Check availability','body'=>'','show_deposit'=>'0','bg_color'=>''],""",
    "PBC: DEFAULTS")

# ============================================================
# 2) Editor extras (dropdown/checkbox data)
# ============================================================
sub(PBC,
    """                if ($section->section_type === 'rentals_showcase') {
                    $extras['rentalCategories'] = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name']);
                }""",
    """                if ($section->section_type === 'rentals_showcase') {
                    $extras['rentalCategories'] = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name']);
                }
                // MARKER-RENTAL-SECTIONS — spotlight picks a model; categories
                // section lists every category (with live unit counts) as
                // checkboxes the tenant toggles + drags into order.
                if ($section->section_type === 'rental_spotlight') {
                    $extras['rentalModels'] = \\App\\Models\\Tenant\\TenantRentalModel::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->with('category:id,name')
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name', 'category_id']);
                }
                if ($section->section_type === 'rental_categories') {
                    $extras['rentalCategories'] = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->withCount(['units as live_unit_count' => fn ($u) => $u->whereNull('archived_at')
                            ->where('status', '!=', 'retired')
                            ->where('available_for_rent', true)])
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name']);
                }""",
    "PBC: extras")

# ============================================================
# 3) edit.blade.php registration — label, icon, description, group, allowed
# ============================================================
sub(EDIT,
    """    'rentals_showcase'       => 'Rentals showcase', // MARKER-PATCH-239""",
    """    'rentals_showcase'       => 'Rentals showcase', // MARKER-PATCH-239
    'rental_spotlight'       => 'Rental spotlight', // MARKER-RENTAL-SECTIONS
    'rental_categories'      => 'Rental categories',
    'rental_browse'          => 'Rental availability',""",
    "edit: labels")

sub(EDIT,
    """    'rentals_showcase' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-3 11.5V14l-3-3 4-3 2 3h2"/>',""",
    """    'rentals_showcase' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-3 11.5V14l-3-3 4-3 2 3h2"/>',
    'rental_spotlight' => '<path d="M12 3l2.5 5 5.5.8-4 3.9.9 5.5-4.9-2.6-4.9 2.6.9-5.5-4-3.9 5.5-.8z"/>',
    'rental_categories' => '<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/>',
    'rental_browse' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',""",
    "edit: icons")

sub(EDIT,
    """    'rentals_showcase' => 'Live rental fleet with rates', // MARKER-PATCH-239""",
    """    'rentals_showcase' => 'Live rental fleet with rates', // MARKER-PATCH-239
    'rental_spotlight' => 'Feature one rental model',
    'rental_categories' => 'Category grid — pick and order',
    'rental_browse' => 'Live date-picker availability browse',""",
    "edit: descriptions")

sub(EDIT,
    """    'Conversion' => ['services','cta_banner','booking_embed','contact_form','pricing_table','rentals_showcase','products_showcase'],""",
    """    'Conversion' => ['services','cta_banner','booking_embed','contact_form','pricing_table','rentals_showcase','rental_spotlight','rental_categories','rental_browse','products_showcase'],""",
    "edit: group")

sub(EDIT,
    """'rentals_showcase','products_showcase','custom_html']);""",
    """'rentals_showcase','rental_spotlight','rental_categories','rental_browse','products_showcase','custom_html']);""",
    "edit: allowed types")

# ============================================================
# 4) edit.blade.php — checkbox+drag category list init
# ============================================================
sub(EDIT,
    """    // MARKER-PATCH-297 — image gallery image-tile repeater
    initGalleryList(body);""",
    """    // MARKER-PATCH-297 — image gallery image-tile repeater
    initGalleryList(body);

    // MARKER-RENTAL-SECTIONS — rental_categories checkbox + drag-order list
    initRentalCategoryList(body);""",
    "edit: init dispatch")

sub(EDIT,
    """  function initStepsList(body) {""",
    """  // MARKER-RENTAL-SECTIONS — every fleet category renders as a row with a
  // checkbox (include it?) and a drag handle (order it). Serializes the
  // checked ids, in DOM order, to the hidden category_ids JSON field.
  function initRentalCategoryList(body) {
    const root = body.querySelector('#pb2-rcat-list');
    const json = body.querySelector('#pb2-rcat-json');
    if (!root || !json) return;
    let dragEl = null;

    function serialize() {
      const out = [];
      root.querySelectorAll('.pb2-rcat').forEach(row => {
        const cb = row.querySelector('[data-rcat-check]');
        if (cb && cb.checked) out.push(row.dataset.catId);
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
    }

    root.querySelectorAll('.pb2-rcat').forEach(row => {
      const cb = row.querySelector('[data-rcat-check]');
      if (cb) cb.addEventListener('change', serialize);
      const h = row.querySelector('.pb2-navlist-handle');
      if (!h) return;
      h.addEventListener('mousedown', () => { row.draggable = true; });
      row.addEventListener('mouseup',    () => { row.draggable = false; });
      row.addEventListener('mouseleave', () => { row.draggable = false; });
      row.addEventListener('dragstart', e => {
        dragEl = row; row.style.opacity = '.4';
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
      });
      row.addEventListener('dragend', () => {
        row.style.opacity = ''; row.draggable = false; dragEl = null; serialize();
      });
      row.addEventListener('dragover', e => {
        e.preventDefault();
        if (!dragEl || dragEl === row) return;
        const r = row.getBoundingClientRect();
        const before = e.clientY < r.top + r.height / 2;
        root.insertBefore(dragEl, before ? row : row.nextSibling);
      });
    });
  }

  function initStepsList(body) {""",
    "edit: initRentalCategoryList")

# ============================================================
# 5) Browse controller — ?category= filter
# ============================================================
sub(RBC,
    """        $units = $this->availability->availableUnits(
            $tenant->id,
            null,""",
    """        // MARKER-RENTAL-SECTIONS — category tiles land pre-filtered.
        $categoryId = null;
        if ($request->filled('category')) {
            $categoryId = TenantRentalCategory::where('tenant_id', $tenant->id)
                ->whereNull('archived_at')
                ->where('id', $request->query('category'))
                ->value('id');
        }

        $units = $this->availability->availableUnits(
            $tenant->id,
            $categoryId,""",
    "RBC: category filter")

sub(RBC,
    """            'error'      => $error,
            'unitCount'  => $units->count(),
        ]);""",
    """            'error'      => $error,
            'unitCount'  => $units->count(),
            'activeCategory' => $categoryId ? $categories[$categoryId] ?? null : null, // MARKER-RENTAL-SECTIONS
        ]);""",
    "RBC: pass active category")

# rentals.blade.php — preserve filter across date changes + clear chip
sub('resources/views/public/rentals.blade.php',
    """      <input type="datetime-local" name="due" value="{{ $dueLocal->format('Y-m-d\\TH:i') }}" required>""",
    """      <input type="datetime-local" name="due" value="{{ $dueLocal->format('Y-m-d\\TH:i') }}" required>
      @if(!empty($activeCategory))<input type="hidden" name="category" value="{{ $activeCategory->id }}">@endif""",
    "rentals view: keep filter on repick")

sub('resources/views/public/rentals.blade.php',
    """  </form>""",
    """  </form>
  {{-- MARKER-RENTAL-SECTIONS — active category chip --}}
  @if(!empty($activeCategory))
    <div style="margin:14px 0 0;font-size:13px">
      Showing <b>{{ $activeCategory->name }}</b> ·
      <a href="{{ route('tenant.rentals.browse', ['starts' => $startLocal->format('Y-m-d\\TH:i'), 'due' => $dueLocal->format('Y-m-d\\TH:i')]) }}" style="opacity:.6">show everything</a>
    </div>
  @endif""",
    "rentals view: category chip")

# ============================================================
# 6) Editor partial — rental_spotlight
# ============================================================
newfile('resources/views/tenant/pages/sections/_rental_spotlight.blade.php',
"""{{--
  MARKER-RENTAL-SECTIONS — rental_spotlight editor.
  One model, hero treatment. Rates/sizes/counts come live from the fleet;
  the image is section content (the fleet has no photos yet).
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $rentalModels = $rentalModels ?? collect();
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Model</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Rental model</label>
      <select class="pb2-input" data-field="model_id">
        <option value="">— pick a model —</option>
        @foreach($rentalModels as $m)
          <option value="{{ $m->id }}" {{ $get('model_id') === (string) $m->id ? 'selected' : '' }}>{{ $m->name }}{{ $m->category ? ' · ' . $m->category->name : '' }}</option>
        @endforeach
      </select>
      <div class="pb2-field-hint">Rates, sizes, and availability pull live from your Fleet.</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_rates" value="1" {{ $get('show_rates', '1') === '1' ? 'checked' : '' }}> Show rates
      </label>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_deposit" value="1" {{ $get('show_deposit') === '1' ? 'checked' : '' }}> Show deposit
      </label>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Defaults to the model name">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-textarea" data-field="body" rows="3" placeholder="Why rent this one?">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Image</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Image</label>
      @if(!empty($get('image_url')))
        <div class="pb2-image-tile">
          <div class="pb2-image-tile-thumb" style="background-image: url('{{ $get('image_url') }}'); background-size: cover; background-position: center;"></div>
          <div class="pb2-image-tile-info">
            <div class="pb2-image-tile-name">{{ basename(parse_url($get('image_url'), PHP_URL_PATH) ?? 'image') }}</div>
            <div class="pb2-image-tile-actions">
              <button type="button" class="pb2-textlink" data-image-replace="image_url">Replace</button>
              <button type="button" class="pb2-textlink pb2-textlink-danger" data-image-remove="image_url">Remove</button>
            </div>
          </div>
        </div>
      @else
        <button type="button" class="pb2-image-empty" data-image-upload="image_url">
          <span class="pb2-image-empty-icon">⬆</span>
          <span>Upload an image</span>
          <span class="pb2-field-hint">JPG, PNG, WebP, or SVG · 5 MB max</span>
        </button>
      @endif
      <input type="hidden" data-field="image_url" value="{{ $get('image_url') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Alt text</label>
      <input type="text" class="pb2-input" data-field="image_alt" value="{{ $get('image_alt') }}" placeholder="Brief description of the image">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Call to action</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button label</label>
      <input type="text" class="pb2-input" data-field="cta_label" value="{{ $get('cta_label', 'Reserve') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button URL</label>
      <input type="text" class="pb2-input" data-field="cta_url" value="{{ $get('cta_url') }}" placeholder="Defaults to the reserve page for this model">
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <div class="pb2-field-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" {{ $get('bg_color') ? '' : 'data-blank=1' }}>
        <input type="text" class="pb2-input" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="default">
      </div>
    </div>
  </div>
</div>
""", "editor partial: rental_spotlight")

# ============================================================
# 7) Editor partial — rental_categories (checkbox + drag)
# ============================================================
newfile('resources/views/tenant/pages/sections/_rental_categories.blade.php',
"""{{--
  MARKER-RENTAL-SECTIONS — rental_categories editor.
  Every fleet category is a row: checkbox = include, drag handle = order.
  Saved as an ordered JSON array of category ids in content.category_ids.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $rentalCategories = $rentalCategories ?? collect();
  $picked = json_decode($get('category_ids', '[]'), true) ?: [];
  // Render picked rows first, in saved order, then the rest.
  $ordered = collect($picked)->map(fn ($id) => $rentalCategories->firstWhere('id', $id))->filter()
      ->concat($rentalCategories->filter(fn ($cat) => !in_array((string) $cat->id, array_map('strval', $picked), true)));
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Rent by category') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-textarea" data-field="body" rows="3" placeholder="Optional intro line">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Categories</div>
    <div class="pb2-field-hint" style="margin-bottom:8px">Check the categories to show. Drag ⋮⋮ to set the order.</div>
    <div class="pb2-navlist" id="pb2-rcat-list">
      @forelse($ordered as $cat)
        <div class="pb2-navlist-item pb2-rcat" data-cat-id="{{ $cat->id }}">
          <span class="pb2-navlist-handle" title="Drag to reorder">⋮⋮</span>
          <label style="display:flex;gap:8px;align-items:center;cursor:pointer;flex:1;font-size:13px">
            <input type="checkbox" data-rcat-check {{ in_array((string) $cat->id, array_map('strval', $picked), true) ? 'checked' : '' }}>
            <span>{{ $cat->name }}</span>
            <span style="margin-left:auto;font-size:11px;opacity:.5">{{ $cat->live_unit_count ?? 0 }} rentable</span>
          </label>
        </div>
      @empty
        <div class="pb2-field-hint">No fleet categories yet — add them under Rentals → Fleet.</div>
      @endforelse
    </div>
    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ $get('category_ids', '[]') }}">
    <div class="pb2-field" style="margin-top:10px">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_counts" value="1" {{ $get('show_counts', '1') === '1' ? 'checked' : '' }}> Show unit counts on tiles
      </label>
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <div class="pb2-field-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" {{ $get('bg_color') ? '' : 'data-blank=1' }}>
        <input type="text" class="pb2-input" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="default">
      </div>
    </div>
  </div>
</div>
""", "editor partial: rental_categories")

# ============================================================
# 8) Editor partial — rental_browse
# ============================================================
newfile('resources/views/tenant/pages/sections/_rental_browse.blade.php',
"""{{--
  MARKER-RENTAL-SECTIONS — rental_browse editor. The section embeds the
  live date-picker availability browse; nothing to curate beyond copy.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Check availability') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-textarea" data-field="body" rows="3" placeholder="Optional intro line">{{ $get('body') }}</textarea>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Options</div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_deposit" value="1" {{ $get('show_deposit') === '1' ? 'checked' : '' }}> Show deposit amounts
      </label>
    </div>
    <div class="pb2-field-hint">Visitors pick a pickup/return window and see what's genuinely free, straight from your fleet. Reserve buttons go to the standard reserve flow.</div>
  </div>
</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <div class="pb2-field-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" {{ $get('bg_color') ? '' : 'data-blank=1' }}>
        <input type="text" class="pb2-input" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="default">
      </div>
    </div>
  </div>
</div>
""", "editor partial: rental_browse")

# ============================================================
# 9) Public partial — rental_spotlight
# ============================================================
newfile('resources/views/public/sections/_rental_spotlight.blade.php',
"""{{--
  MARKER-RENTAL-SECTIONS — rental_spotlight public render. One model,
  hero treatment. Live rates/sizes/unit count; renders nothing when
  rentals are hidden or the model is gone/archived.
--}}
@php
  $spTenant = $tenant ?? $currentTenant ?? null;
  $spModel = null;
  if ($spTenant && $spTenant->rentals_visible && !empty($c['model_id'])) {
      $spModel = \\App\\Models\\Tenant\\TenantRentalModel::where('tenant_id', $spTenant->id)
          ->whereNull('archived_at')
          ->where('id', $c['model_id'])
          ->with('category:id,name')
          ->withCount(['units as sp_unit_count' => fn ($u) => $u->whereNull('archived_at')
              ->where('status', '!=', 'retired')
              ->where('available_for_rent', true)])
          ->first();
  }
  $spShowRates   = ($c['show_rates'] ?? '1') === '1';
  $spShowDeposit = ($c['show_deposit'] ?? '0') === '1';
  $spSizes = $spModel
      ? \\App\\Models\\Tenant\\TenantRentalUnit::where('tenant_id', $spTenant->id)
          ->where('model_id', $spModel->id)
          ->whereNull('archived_at')->where('status', '!=', 'retired')
          ->where('available_for_rent', true)
          ->whereNotNull('size')->distinct()->orderBy('size')->pluck('size')
      : collect();
  $spCta = !empty($c['cta_url']) ? $c['cta_url'] : ($spModel ? route('tenant.rentals.reserve', ['model' => $spModel->id]) : '/rentals');
@endphp

@if($spModel && $spModel->sp_unit_count > 0)
<section class="p-section" id="rental-spotlight" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>
  <div class="p-container">
    <div style="display:grid;grid-template-columns:{{ !empty($c['image_url']) ? '1fr 1fr' : '1fr' }};gap:40px;align-items:center" class="p-spotlight-grid">
      @if(!empty($c['image_url']))
        <div style="border-radius:var(--p-r-lg,14px);overflow:hidden;aspect-ratio:4/3">
          <img src="{{ $c['image_url'] }}" alt="{{ $c['image_alt'] ?? $spModel->name }}" style="width:100%;height:100%;object-fit:cover" loading="lazy">
        </div>
      @endif
      <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">{{ $c['eyebrow'] ?: ($spModel->category?->name ?? 'Rentals') }}</div>
        <h2 class="p-section-heading" style="margin-top:6px">{{ $c['heading'] ?: $spModel->name }}</h2>
        @if($spModel->subtitle)<div style="font-size:14px;opacity:.55;margin-top:4px">{{ $spModel->subtitle }}</div>@endif
        @if(!empty($c['body']))<p style="margin-top:14px;opacity:.7;font-size:15px;line-height:1.65">{{ $c['body'] }}</p>@endif
        @if($spShowRates)
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:18px">
            @if($spModel->daily_rate_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px"><b>{{ format_money($spModel->daily_rate_cents) }}</b>/day</span>@endif
            @if($spModel->hourly_rate_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px"><b>{{ format_money($spModel->hourly_rate_cents) }}</b>/hr</span>@endif
            @if($spModel->weekend_rate_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px"><b>{{ format_money($spModel->weekend_rate_cents) }}</b>/weekend</span>@endif
            @if($spShowDeposit && $spModel->deposit_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px">{{ format_money($spModel->deposit_cents) }} deposit</span>@endif
          </div>
        @endif
        <div style="font-size:12px;opacity:.5;margin-top:12px">
          {{ $spModel->sp_unit_count }} in the fleet@if($spSizes->isNotEmpty()) · sizes {{ $spSizes->implode(', ') }}@endif
        </div>
        <div style="margin-top:22px">
          <a href="{{ $spCta }}" class="p-btn p-btn--primary">{{ $c['cta_label'] ?: 'Reserve' }}</a>
        </div>
      </div>
    </div>
  </div>
</section>
<style>@media (max-width:720px){ #rental-spotlight .p-spotlight-grid { grid-template-columns:1fr !important; } }</style>
@endif
""", "public partial: rental_spotlight")

# ============================================================
# 10) Public partial — rental_categories
# ============================================================
newfile('resources/views/public/sections/_rental_categories.blade.php',
"""{{--
  MARKER-RENTAL-SECTIONS — rental_categories public render. Tiles for the
  tenant's checked categories in their chosen order, each linking to the
  browse page pre-filtered. Empty/archived categories drop out silently.
--}}
@php
  $rcTenant = $tenant ?? $currentTenant ?? null;
  $rcIds = json_decode($c['category_ids'] ?? '[]', true) ?: [];
  $rcCats = collect();
  if ($rcTenant && $rcTenant->rentals_visible && $rcIds) {
      $found = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $rcTenant->id)
          ->whereNull('archived_at')
          ->whereIn('id', $rcIds)
          ->withCount(['units as rc_unit_count' => fn ($u) => $u->whereNull('archived_at')
              ->where('status', '!=', 'retired')
              ->where('available_for_rent', true)])
          ->get()->keyBy('id');
      foreach ($rcIds as $id) {
          $cat = $found[$id] ?? null;
          if ($cat && $cat->rc_unit_count > 0) $rcCats->push($cat);
      }
  }
  $rcShowCounts = ($c['show_counts'] ?? '1') === '1';
@endphp

@if($rcCats->isNotEmpty())
<section class="p-section" id="rental-categories" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>
  <div class="p-container">
    <div class="p-section-head-wrap" style="text-align:center">
      @if(!empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:32px">
      @foreach($rcCats as $cat)
        <a href="{{ route('tenant.rentals.browse', ['category' => $cat->id]) }}"
           style="display:block;border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:24px 22px;background:rgba(255,255,255,.6);text-decoration:none;color:inherit">
          <div style="font-size:17px;font-weight:650;line-height:1.3">{{ $cat->name }}</div>
          @if($rcShowCounts)<div style="font-size:12px;opacity:.5;margin-top:6px">{{ $cat->rc_unit_count }} to rent</div>@endif
          <div style="font-size:13px;margin-top:14px;font-weight:600">Check availability →</div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
""", "public partial: rental_categories")

# ============================================================
# 11) Public partial — rental_browse (embedded live browse)
# ============================================================
newfile('resources/views/public/sections/_rental_browse.blade.php',
"""{{--
  MARKER-RENTAL-SECTIONS — rental_browse public render. The /rentals
  browse experience as an embeddable section: window picker (GET back to
  the same page, #rental-browse anchor) + grouped live availability.
  Same RentalAvailabilityService the reserve lock re-verifies.
--}}
@php
  $rbTenant = $tenant ?? $currentTenant ?? null;
  $rbGroups = []; $rbStart = null; $rbDue = null; $rbError = null; $rbCount = 0;
  if ($rbTenant && $rbTenant->rentals_visible) {
      $rbTz = $rbTenant->timezone();
      $rbDefStart = \\Carbon\\Carbon::now($rbTz)->addDay()->setTime(9, 0);
      $rbDefDue   = \\Carbon\\Carbon::now($rbTz)->addDay()->setTime(17, 0);
      try {
          $rbStart = request()->filled('starts') ? \\Carbon\\Carbon::parse(request('starts'), $rbTz) : $rbDefStart;
          $rbDue   = request()->filled('due')    ? \\Carbon\\Carbon::parse(request('due'), $rbTz)    : $rbDefDue;
      } catch (\\Throwable $e) {
          $rbStart = $rbDefStart; $rbDue = $rbDefDue;
          $rbError = 'That date didn\\'t parse — showing tomorrow instead.';
      }
      if ($rbDue->lessThanOrEqualTo($rbStart)) { $rbDue = $rbStart->copy()->addHours(4); $rbError = 'Return time must be after pickup — adjusted it for you.'; }

      $rbUnits = app(\\App\\Services\\RentalAvailabilityService::class)->availableUnits(
          $rbTenant->id, null, $rbStart->copy()->utc(), $rbDue->copy()->utc(), onlineOnly: true,
      );
      $rbCount = $rbUnits->count();
      $rbCatNames = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $rbTenant->id)
          ->whereNull('archived_at')->orderBy('sort_order')->orderBy('name')->get(['id','name'])->keyBy('id');
      foreach ($rbUnits as $u) {
          if (!$u->model) continue;
          $catName = $rbCatNames[$u->category_id]->name ?? 'Other';
          $key = $u->model->id;
          if (!isset($rbGroups[$catName][$key])) $rbGroups[$catName][$key] = ['model' => $u->model, 'count' => 0, 'sizes' => []];
          $rbGroups[$catName][$key]['count']++;
          if ($u->size && !in_array($u->size, $rbGroups[$catName][$key]['sizes'], true)) $rbGroups[$catName][$key]['sizes'][] = $u->size;
      }
  }
  $rbShowDeposit = ($c['show_deposit'] ?? '0') === '1';
@endphp

@if($rbTenant && $rbTenant->rentals_visible)
<section class="p-section" id="rental-browse" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>
  <div class="p-container">
    <div class="p-section-head-wrap" style="text-align:center">
      @if(!empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif
    </div>

    <form method="GET" action="#rental-browse" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;justify-content:center;margin-top:28px">
      <div>
        <label style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;display:block;margin-bottom:5px;font-weight:600">Pickup</label>
        <input type="datetime-local" name="starts" value="{{ $rbStart->format('Y-m-d\\TH:i') }}" required style="padding:9px 12px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px">
      </div>
      <div>
        <label style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;display:block;margin-bottom:5px;font-weight:600">Return</label>
        <input type="datetime-local" name="due" value="{{ $rbDue->format('Y-m-d\\TH:i') }}" required style="padding:9px 12px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px">
      </div>
      <button type="submit" class="p-btn p-btn--primary">Check</button>
    </form>
    @if($rbError)<div style="text-align:center;margin-top:10px;font-size:13px;opacity:.6">{{ $rbError }}</div>@endif

    @if($rbCount === 0)
      <div style="text-align:center;margin-top:36px;opacity:.55;font-size:14.5px">Nothing free in that window — try different times.</div>
    @else
      @foreach($rbGroups as $catName => $models)
        <div style="margin-top:36px">
          <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin-bottom:12px;font-weight:650">{{ $catName }}</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">
            @foreach($models as $g)
              @php $m = $g['model']; @endphp
              <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:20px 22px;background:rgba(255,255,255,.6);display:flex;flex-direction:column">
                <div style="font-size:16px;font-weight:650;line-height:1.3">{{ $m->name }}</div>
                @if($m->subtitle)<div style="font-size:12.5px;opacity:.55;margin-top:2px">{{ $m->subtitle }}</div>@endif
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
                  @if($m->daily_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->daily_rate_cents) }}</b>/day</span>@endif
                  @if($m->hourly_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->hourly_rate_cents) }}</b>/hr</span>@endif
                  @if($m->weekend_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->weekend_rate_cents) }}</b>/weekend</span>@endif
                  @if($rbShowDeposit && $m->deposit_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px">{{ format_money($m->deposit_cents) }} deposit</span>@endif
                </div>
                <div style="font-size:11.5px;opacity:.5;margin-top:10px">{{ $g['count'] }} available@if($g['sizes']) · {{ implode(', ', $g['sizes']) }}@endif</div>
                <div style="margin-top:auto;padding-top:16px">
                  <a class="p-btn p-btn--primary" style="width:100%;text-align:center" href="{{ route('tenant.rentals.reserve', ['model' => $m->id, 'starts' => $rbStart->format('Y-m-d\\TH:i'), 'due' => $rbDue->format('Y-m-d\\TH:i')]) }}">Reserve</a>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      @endforeach
    @endif
  </div>
</section>
@endif
""", "public partial: rental_browse")

print("\\nDone. No migration needed. Clear view cache after deploy.")
