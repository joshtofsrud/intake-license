#!/usr/bin/env python3
"""Fleet model photos. Adds image_url to rental models, a photo
upload/replace/remove control in the fleet model drawer (uploads through
the existing tenant uploads endpoint, saves via the data-mf autosave
rail), and renders the photo on every public rental surface: showcase
cards, /rentals browse cards, embedded rental_browse cards, and
rental_spotlight (section image wins, model photo is the fallback).
Run from repo root: python3 apply-rental-model-photos.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) Migration
# ============================================================
mig = 'database/migrations/2026_08_18_100000_add_image_url_to_tenant_rental_models.php'
if os.path.exists(os.path.join(ROOT, mig)):
    print("SKIP (exists): migration")
else:
    write(mig, """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// MARKER-RENTAL-MODEL-PHOTOS — one marketing photo per rental model,
// rendered on every public rental surface.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable()->after('subtitle');
        });
    }
    public function down(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
""")
    print("OK: migration")

# ============================================================
# 2) Model fillable
# ============================================================
sub('app/Models/Tenant/TenantRentalModel.php',
    """        'tenant_id', 'category_id', 'name', 'subtitle',""",
    """        'tenant_id', 'category_id', 'name', 'subtitle', 'image_url', // MARKER-RENTAL-MODEL-PHOTOS""",
    "model fillable")

# ============================================================
# 3) Controller — image_url field on updateModel
# ============================================================
sub('app/Http/Controllers/Tenant/RentalFleetController.php',
    """            case 'subtitle':
                $request->validate(['value' => ['nullable', 'string', 'max:120']]);
                $model->update(['subtitle' => ($value === '' ? null : $value)]);
                break;""",
    """            case 'subtitle':
                $request->validate(['value' => ['nullable', 'string', 'max:120']]);
                $model->update(['subtitle' => ($value === '' ? null : $value)]);
                break;
            case 'image_url': // MARKER-RENTAL-MODEL-PHOTOS
                $request->validate(['value' => ['nullable', 'string', 'max:500']]);
                $model->update(['image_url' => ($value === '' ? null : $value)]);
                break;""",
    "controller: image_url case")

# ============================================================
# 4) Fleet drawer — photo control
# ============================================================
sub('resources/views/tenant/rentals/fleet.blade.php',
    """              <div class="fl-fg" style="grid-column:1/3"><span class="fl-lbl">Model name</span><input class="fl-inp" value="{{ $model->name }}" data-mf="name"></div>""",
    """              {{-- MARKER-RENTAL-MODEL-PHOTOS — one marketing photo per model.
                   Uploads through the tenant uploads endpoint, then saves the
                   URL over the same data-mf autosave rail as every field. --}}
              <div class="fl-fg" style="grid-column:1/5">
                <span class="fl-lbl">Photo <span style="opacity:.5;text-transform:none;letter-spacing:0">— shows on your public rental pages</span></span>
                <div class="fl-photo" data-photo-wrap>
                  <div class="fl-photo-thumb" data-photo-thumb style="{{ $model->image_url ? 'background-image:url(\\'' . $model->image_url . '\\')' : '' }}"></div>
                  <button type="button" class="ia-btn ia-btn--sm" data-photo-pick>{{ $model->image_url ? 'Replace' : 'Upload' }}</button>
                  <button type="button" class="ia-btn ia-btn--sm" data-photo-remove style="{{ $model->image_url ? '' : 'display:none' }}">Remove</button>
                  <input type="file" accept="image/jpeg,image/png,image/webp,image/svg+xml" data-photo-file style="display:none">
                  <input type="hidden" data-mf="image_url" value="{{ $model->image_url }}">
                </div>
              </div>
              <div class="fl-fg" style="grid-column:1/3"><span class="fl-lbl">Model name</span><input class="fl-inp" value="{{ $model->name }}" data-mf="name"></div>""",
    "fleet blade: photo control")

sub('resources/views/tenant/rentals/fleet.blade.php',
    """  .fl-model-body{display:none;border-top:.5px solid var(--ia-border-strong,rgba(255,255,255,.22));padding:14px;background:rgba(255,255,255,.03)}""",
    """  .fl-model-body{display:none;border-top:.5px solid var(--ia-border-strong,rgba(255,255,255,.22));padding:14px;background:rgba(255,255,255,.03)}
  /* MARKER-RENTAL-MODEL-PHOTOS */
  .fl-photo{display:flex;align-items:center;gap:10px}
  .fl-photo-thumb{width:64px;height:48px;border-radius:8px;border:.5px solid var(--ia-border);background:rgba(255,255,255,.05) center/cover no-repeat}""",
    "fleet blade: photo styles")

sub('resources/views/tenant/rentals/fleet.blade.php',
    """  // Enter commits a text field (blur fires change fires save).""",
    """  // MARKER-RENTAL-MODEL-PHOTOS — pick file → upload → save URL via the
  // hidden data-mf input (change event rides the existing autosave).
  document.querySelectorAll('[data-photo-wrap]').forEach(function(wrap){
    var pick = wrap.querySelector('[data-photo-pick]');
    var rm   = wrap.querySelector('[data-photo-remove]');
    var file = wrap.querySelector('[data-photo-file]');
    var thumb = wrap.querySelector('[data-photo-thumb]');
    var hidden = wrap.querySelector('[data-mf="image_url"]');
    pick.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
      if (!file.files || !file.files[0]) return;
      var fd = new FormData();
      fd.append('file', file.files[0]);
      fd.append('type', 'general');
      showToast('Uploading…', 'busy');
      fetch('{{ route('tenant.uploads.store') }}', {method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:fd})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j || !j.url) { showToast(j && j.message ? j.message : 'Upload failed.', 'err'); return; }
          hidden.value = j.url;
          hidden.dispatchEvent(new Event('change', { bubbles: false }));
          thumb.style.backgroundImage = "url('" + j.url + "')";
          pick.textContent = 'Replace';
          rm.style.display = '';
        })
        .catch(function(){ showToast("Upload failed — check your connection.", 'err'); });
      file.value = '';
    });
    rm.addEventListener('click', function(){
      hidden.value = '';
      hidden.dispatchEvent(new Event('change', { bubbles: false }));
      thumb.style.backgroundImage = '';
      pick.textContent = 'Upload';
      rm.style.display = 'none';
    });
  });

  // Enter commits a text field (blur fires change fires save).""",
    "fleet blade: photo JS")

# ============================================================
# 5) Public: rentals_showcase cards
# ============================================================
sub('resources/views/public/sections/_rentals_showcase.blade.php',
    """        <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:20px 22px;background:rgba(255,255,255,.6)">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">{{ $m->category?->name }}</div>""",
    """        <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);background:rgba(255,255,255,.6);overflow:hidden">
          @if($m->image_url)
            <div style="aspect-ratio:16/10;background:url('{{ $m->image_url }}') center/cover no-repeat"></div>
          @endif
          <div style="padding:20px 22px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">{{ $m->category?->name }}</div>""",
    "showcase: card image")

sub('resources/views/public/sections/_rentals_showcase.blade.php',
    """          <div style="font-size:11.5px;opacity:.45;margin-top:10px">{{ $m->rs_unit_count }} in the fleet</div>
        </div>""",
    """          <div style="font-size:11.5px;opacity:.45;margin-top:10px">{{ $m->rs_unit_count }} in the fleet</div>
          </div>
        </div>""",
    "showcase: close padding wrap")

# ============================================================
# 6) Public: /rentals browse cards
# ============================================================
rentals = read('resources/views/public/rentals.blade.php')
import re
m = re.search(r'(\n\s*)<div class="card">', rentals)
if m is None:
    # fall back: locate the model name element to wrap image above it
    print("NOTE: /rentals card anchor varies — applying via name-line anchor")
sub('resources/views/public/rentals.blade.php',
    """<div class="name">{{ $m->name }}</div>""",
    """@if($m->image_url)<div style="aspect-ratio:16/10;border-radius:10px;background:url('{{ $m->image_url }}') center/cover no-repeat;margin-bottom:12px"></div>@endif
              <div class="name">{{ $m->name }}</div>""",
    "/rentals: card image")

# ============================================================
# 7) Public: embedded rental_browse cards
# ============================================================
sub('resources/views/public/sections/_rental_browse.blade.php',
    """              <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:20px 22px;background:rgba(255,255,255,.6);display:flex;flex-direction:column">
                <div style="font-size:16px;font-weight:650;line-height:1.3">{{ $m->name }}</div>""",
    """              <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:20px 22px;background:rgba(255,255,255,.6);display:flex;flex-direction:column">
                @if($m->image_url)<div style="aspect-ratio:16/10;border-radius:10px;background:url('{{ $m->image_url }}') center/cover no-repeat;margin-bottom:12px"></div>@endif
                <div style="font-size:16px;font-weight:650;line-height:1.3">{{ $m->name }}</div>""",
    "rental_browse: card image")

# ============================================================
# 8) Public: rental_spotlight — model photo as fallback
# ============================================================
sub('resources/views/public/sections/_rental_spotlight.blade.php',
    """  $spCta = !empty($c['cta_url']) ? $c['cta_url'] : ($spModel ? route('tenant.rentals.reserve', ['model' => $spModel->id]) : '/rentals');""",
    """  $spCta = !empty($c['cta_url']) ? $c['cta_url'] : ($spModel ? route('tenant.rentals.reserve', ['model' => $spModel->id]) : '/rentals');
  // MARKER-RENTAL-MODEL-PHOTOS — section image wins; fleet photo is the fallback.
  $spImage = !empty($c['image_url']) ? $c['image_url'] : ($spModel->image_url ?? '');""",
    "spotlight: image fallback var")

s = read('resources/views/public/sections/_rental_spotlight.blade.php')
s2 = s.replace("!empty($c['image_url'])", "!empty($spImage)") \
      .replace("{{ $c['image_url'] }}", "{{ $spImage }}")
if s2 != s:
    write('resources/views/public/sections/_rental_spotlight.blade.php', s2)
    print("OK: spotlight: use fallback image")
else:
    print("SKIP (already applied): spotlight: use fallback image")

print("\\nDone. Run migration after deploy: php artisan migrate --force")
