#!/usr/bin/env python3
"""Category tiles get photos, picked from the fleet. Each checked
category row in the rental_categories editor gains a Photo dropdown
listing that category's models with photos (default: auto = first photo
in the category). Saved as content.category_images {categoryId: modelId};
tiles render the chosen model's photo, auto-fallback otherwise.
Run from repo root: python3 apply-rental-category-tile-photos.py
"""
import sys

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

PBC  = 'app/Http/Controllers/Tenant/PageBuilderController.php'
EDIT = 'resources/views/tenant/pages/edit.blade.php'
EDI  = 'resources/views/tenant/pages/sections/_rental_categories.blade.php'
PUB  = 'resources/views/public/sections/_rental_categories.blade.php'

# 1) DEFAULTS — add category_images map
sub(PBC,
    """        'rental_categories' => ['eyebrow'=>'','heading'=>'Rent by category','body'=>'','category_ids'=>'[]','show_counts'=>'1','bg_color'=>''],""",
    """        'rental_categories' => ['eyebrow'=>'','heading'=>'Rent by category','body'=>'','category_ids'=>'[]','category_images'=>'{}','show_counts'=>'1','bg_color'=>''],""",
    "PBC: default category_images")

# 2) extras — models with photos per category for the dropdowns
sub(PBC,
    """                if ($section->section_type === 'rental_categories') {
                    $extras['rentalCategories'] = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->withCount(['units as live_unit_count' => fn ($u) => $u->whereNull('archived_at')
                            ->where('status', '!=', 'retired')
                            ->where('available_for_rent', true)])
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name']);
                }""",
    """                if ($section->section_type === 'rental_categories') {
                    $extras['rentalCategories'] = \\App\\Models\\Tenant\\TenantRentalCategory::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->withCount(['units as live_unit_count' => fn ($u) => $u->whereNull('archived_at')
                            ->where('status', '!=', 'retired')
                            ->where('available_for_rent', true)])
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name']);
                    // MARKER-RENTAL-SECTIONS — tile photos come from the fleet:
                    // per category, the models that actually have a photo.
                    $extras['rentalModelPhotos'] = \\App\\Models\\Tenant\\TenantRentalModel::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->whereNotNull('image_url')
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name', 'category_id', 'image_url'])
                        ->groupBy('category_id');
                }""",
    "PBC: extras model photos")

# 3) Editor partial — decode map + Photo dropdown per row
sub(EDI,
    """  $pickedRaw = $get('category_ids', '[]');
  $picked = is_array($pickedRaw) ? $pickedRaw : (json_decode((string) $pickedRaw, true) ?: []);""",
    """  $pickedRaw = $get('category_ids', '[]');
  $picked = is_array($pickedRaw) ? $pickedRaw : (json_decode((string) $pickedRaw, true) ?: []);
  $imgRaw = $get('category_images', '{}');
  $imgMap = is_array($imgRaw) ? $imgRaw : (json_decode((string) $imgRaw, true) ?: []);
  $rentalModelPhotos = $rentalModelPhotos ?? collect();""",
    "editor: decode image map")

sub(EDI,
    """          <label style="display:flex;gap:8px;align-items:center;cursor:pointer;flex:1;font-size:13px">
            <input type="checkbox" data-rcat-check {{ in_array((string) $cat->id, array_map('strval', $picked), true) ? 'checked' : '' }}>
            <span>{{ $cat->name }}</span>
            <span style="margin-left:auto;font-size:11px;opacity:.5">{{ $cat->live_unit_count ?? 0 }} rentable</span>
          </label>
        </div>""",
    """          <label style="display:flex;gap:8px;align-items:center;cursor:pointer;flex:1;font-size:13px">
            <input type="checkbox" data-rcat-check {{ in_array((string) $cat->id, array_map('strval', $picked), true) ? 'checked' : '' }}>
            <span>{{ $cat->name }}</span>
            <span style="margin-left:auto;font-size:11px;opacity:.5">{{ $cat->live_unit_count ?? 0 }} rentable</span>
          </label>
          {{-- MARKER-RENTAL-SECTIONS — tile photo, picked from the fleet --}}
          @php $catModels = $rentalModelPhotos[$cat->id] ?? collect(); @endphp
          @if($catModels->isNotEmpty())
            <select class="pb2-input pb2-input-sm" data-rcat-photo style="max-width:150px" title="Tile photo">
              <option value="">Auto photo</option>
              @foreach($catModels as $pm)
                <option value="{{ $pm->id }}" {{ ($imgMap[(string) $cat->id] ?? '') === (string) $pm->id ? 'selected' : '' }}>{{ $pm->name }}</option>
              @endforeach
            </select>
          @endif
        </div>""",
    "editor: photo dropdown")

sub(EDI,
    """    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ json_encode(array_values($picked)) }}">""",
    """    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ json_encode(array_values($picked)) }}">
    <input type="hidden" data-field="category_images" id="pb2-rcat-img-json" value="{{ json_encode((object) $imgMap) }}">""",
    "editor: hidden image map field")

# 4) edit.blade JS — serialize the photo choices too
sub(EDIT,
    """  function initRentalCategoryList(body) {
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
      if (cb) cb.addEventListener('change', serialize);""",
    """  function initRentalCategoryList(body) {
    const root = body.querySelector('#pb2-rcat-list');
    const json = body.querySelector('#pb2-rcat-json');
    const imgJson = body.querySelector('#pb2-rcat-img-json');
    if (!root || !json) return;
    let dragEl = null;

    function serialize() {
      const out = [];
      const imgs = {};
      root.querySelectorAll('.pb2-rcat').forEach(row => {
        const cb = row.querySelector('[data-rcat-check]');
        if (cb && cb.checked) out.push(row.dataset.catId);
        const sel = row.querySelector('[data-rcat-photo]');
        if (sel && sel.value) imgs[row.dataset.catId] = sel.value;
      });
      json.value = JSON.stringify(out);
      json.dispatchEvent(new Event('change', { bubbles: true }));
      if (imgJson) {
        imgJson.value = JSON.stringify(imgs);
        imgJson.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    root.querySelectorAll('.pb2-rcat').forEach(row => {
      const cb = row.querySelector('[data-rcat-check]');
      if (cb) cb.addEventListener('change', serialize);
      const sel = row.querySelector('[data-rcat-photo]');
      if (sel) sel.addEventListener('change', serialize);""",
    "edit JS: serialize photo picks")

# 5) Public render — chosen model photo, auto fallback
sub(PUB,
    """  $rcRaw = $c['category_ids'] ?? [];
  $rcIds = is_array($rcRaw) ? $rcRaw : (json_decode((string) $rcRaw, true) ?: []);""",
    """  $rcRaw = $c['category_ids'] ?? [];
  $rcIds = is_array($rcRaw) ? $rcRaw : (json_decode((string) $rcRaw, true) ?: []);
  $rcImgRaw = $c['category_images'] ?? [];
  $rcImgMap = is_array($rcImgRaw) ? $rcImgRaw : (json_decode((string) $rcImgRaw, true) ?: []);""",
    "public: decode image map")

sub(PUB,
    """      foreach ($rcIds as $id) {
          $cat = $found[$id] ?? null;
          if ($cat && $cat->rc_unit_count > 0) $rcCats->push($cat);
      }
  }""",
    """      // MARKER-RENTAL-SECTIONS — tile photos: chosen model per category,
      // else the category's first model with a photo.
      $rcPhotoModels = \\App\\Models\\Tenant\\TenantRentalModel::where('tenant_id', $rcTenant->id)
          ->whereNull('archived_at')
          ->whereNotNull('image_url')
          ->whereIn('category_id', $rcIds)
          ->orderBy('sort_order')->orderBy('name')
          ->get(['id', 'category_id', 'image_url']);
      foreach ($rcIds as $id) {
          $cat = $found[$id] ?? null;
          if ($cat && $cat->rc_unit_count > 0) {
              $chosen = $rcImgMap[(string) $id] ?? null;
              $photo = $chosen ? $rcPhotoModels->firstWhere('id', $chosen) : null;
              $cat->rc_tile_image = ($photo ?: $rcPhotoModels->firstWhere('category_id', $cat->id))?->image_url;
              $rcCats->push($cat);
          }
      }
  }""",
    "public: resolve tile photos")

sub(PUB,
    """           style="display:block;border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:24px 22px;background:rgba(255,255,255,.6);text-decoration:none;color:inherit">
          <div style="font-size:17px;font-weight:650;line-height:1.3">{{ $cat->name }}</div>""",
    """           style="display:block;border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);background:rgba(255,255,255,.6);text-decoration:none;color:inherit;overflow:hidden">
          @if($cat->rc_tile_image)
            <div style="aspect-ratio:16/10;background:url('{{ $cat->rc_tile_image }}') center/cover no-repeat"></div>
          @endif
          <div style="padding:24px 22px">
          <div style="font-size:17px;font-weight:650;line-height:1.3">{{ $cat->name }}</div>""",
    "public: tile image markup")

sub(PUB,
    """          <div style="font-size:13px;margin-top:14px;font-weight:600">Check availability →</div>
        </a>""",
    """          <div style="font-size:13px;margin-top:14px;font-weight:600">Check availability →</div>
          </div>
        </a>""",
    "public: close padding wrap")

print("Done. No migration needed.")
