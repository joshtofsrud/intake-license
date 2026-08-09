#!/usr/bin/env bash
set -euo pipefail
# apply-import-category-scope.sh — MARKER-SSEL-SCOPE
# Categories on the catalog-import page scope to the selected brand, on every
# distributor:
#   - importFilterOptions() takes an optional brand and filters the category
#     list by manufacturer (so a Preview round-trip renders scoped too)
#   - new JSON endpoint /distributors/import/categories?code=&brand=
#   - searchable-select gains data-name, a change event on pick, and a
#     setOptions() API so the page can swap the category list live
#   - import page: picking a brand fetches that brand's categories; a category
#     no longer in the list resets to "Any category"
# REQUIRES apply-searchable-select-picker (MARKER-SSEL) to be applied first.

CTRL=app/Http/Controllers/Tenant/DistributorController.php
COMP=resources/views/components/tenant/searchable-select.blade.php
VIEW=resources/views/tenant/distributors/import.blade.php
ROUTES=routes/web.php

for f in "$CTRL" "$COMP" "$VIEW" "$ROUTES"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

grep -q "MARKER-SSEL" "$COMP" || { echo "PRECONDITION FAILED: run apply-searchable-select-picker.sh first"; exit 1; }

if grep -q "MARKER-SSEL-SCOPE" "$CTRL"; then
  echo "Already applied (MARKER-SSEL-SCOPE present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) importFilterOptions learns an optional brand scope
edit("""    private function importFilterOptions(string $code): array
    {
        $base = \\App\\Models\\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true);

        return [
            'importCode' => $code,
            'importCodes' => $this->importableCodes(),
            'brands' => (clone $base)->whereNotNull('manufacturer')
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            'categories' => (clone $base)->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
    }""",
"""    private function importFilterOptions(string $code, ?string $brand = null): array
    {
        $base = \\App\\Models\\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true);

        return [
            'importCode' => $code,
            'importCodes' => $this->importableCodes(),
            'brands' => (clone $base)->whereNotNull('manufacturer')
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            // MARKER-SSEL-SCOPE — categories narrow to the chosen brand so
            // the picker never offers a category the brand has no items in.
            'categories' => (clone $base)->whereNotNull('category')
                ->when($brand !== null && $brand !== '', fn ($q) => $q->where('manufacturer', $brand))
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
    }

    /** MARKER-SSEL-SCOPE — categories for one brand, for the live picker. */
    public function importCategories(\\Illuminate\\Http\\Request $request): \\Illuminate\\Http\\JsonResponse
    {
        $this->guard();

        $code  = $this->importCode($request->query('code'));
        $brand = trim((string) $request->query('brand', ''));

        $categories = \\App\\Models\\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true)
            ->whereNotNull('category')
            ->when($brand !== '', fn ($q) => $q->where('manufacturer', $brand))
            ->distinct()->orderBy('category')->pluck('category');

        return response()->json(['categories' => $categories]);
    }""",
"importFilterOptions + endpoint")

# 2) the preview/commit round-trip re-renders with the scoped list
edit("""        $view = $this->importFilterOptions($code);
        $view['filters'] = $filters;""",
"""        $view = $this->importFilterOptions($code, $filters['brand'] ?? null); // MARKER-SSEL-SCOPE
        $view['filters'] = $filters;""",
"importRun scoped render")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- route
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """                Route::get('/import',             [TenantControllers\\DistributorController::class, 'import'])->name('import');"""
new = """                Route::get('/import',             [TenantControllers\\DistributorController::class, 'import'])->name('import');
                Route::get('/import/categories',  [TenantControllers\\DistributorController::class, 'importCategories'])->name('import.categories'); // MARKER-SSEL-SCOPE"""
n = src.count(old)
if n != 1:
    print(f"FAIL route: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   route import.categories")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- component
python3 - "$COMP" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) root carries the field name so pages can target an instance
edit("""<div class="ssel" data-noun="{{ $noun }}">""",
"""<div class="ssel" data-noun="{{ $noun }}" data-name="{{ $name }}">{{-- MARKER-SSEL-SCOPE --}}""",
"component data-name")

# 2) picking a value announces itself like a native select
edit("""      opts.forEach(function (x) { x.classList.toggle('is-sel', x === o); });
      close();
      btn.focus();
    }""",
"""      opts.forEach(function (x) { x.classList.toggle('is-sel', x === o); });
      close();
      btn.focus();
      // MARKER-SSEL-SCOPE — behave like a native select for listeners.
      val.dispatchEvent(new Event('change', { bubbles: true }));
    }""",
"component change event")

# 3) a setOptions API so a page can swap the list live
edit("""    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) { close(); }
    });
  }""",
"""    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) { close(); }
    });

    // MARKER-SSEL-SCOPE — replace the option list in place. Keeps the current
    // value when it survives the new list; otherwise resets to the Any row
    // WITHOUT dispatching change (the caller initiated this, no loops).
    function setOptions(labels) {
      opts.forEach(function (o) { if (o !== anyOpt) { o.remove(); } });
      opts = [anyOpt];
      labels.forEach(function (l) {
        var o = document.createElement('div');
        o.className = 'ssel-opt';
        o.setAttribute('role', 'option');
        o.setAttribute('data-v', l);
        o.setAttribute('data-l', l);
        var t = document.createElement('span');
        t.className = 't';
        t.textContent = l;
        var tick = document.createElement('span');
        tick.className = 'ssel-tick';
        tick.textContent = '\\u2713';
        o.appendChild(t);
        o.appendChild(tick);
        list.appendChild(o);
        opts.push(o);
      });
      var keep = null;
      opts.forEach(function (o) {
        if (o !== anyOpt && o.getAttribute('data-v') === val.value) { keep = o; }
      });
      if (val.value !== '' && !keep) {
        val.value = '';
        cur.textContent = anyOpt.getAttribute('data-l');
        cur.classList.add('is-any');
      }
      opts.forEach(function (o) {
        o.classList.toggle('is-sel', o === (keep || anyOpt));
      });
      if (none) { none.remove(); none = null; }
    }

    root.__sselApi = { setOptions: setOptions, getValue: function () { return val.value; } };
  }""",
"component setOptions API")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- import view
python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = "@endsection"
n = src.count(old)
if n != 1:
    print(f"FAIL view script: @endsection found {n} times"); sys.exit(1)

script = """
{{-- MARKER-SSEL-SCOPE — picking a brand narrows the category list live --}}
<script>
  (function () {
    var brand = document.querySelector('.ssel[data-name="brand"]');
    var cat   = document.querySelector('.ssel[data-name="category"]');
    if (!brand || !cat) { return; }
    var url = @json(route('tenant.distributors.import.categories'));
    var code = @json($importCode);

    function refresh() {
      var b = brand.querySelector('.ssel-val').value;
      var catBtn = cat.querySelector('.ssel-btn');
      catBtn.disabled = true;
      catBtn.style.opacity = '.55';
      fetch(url + '?code=' + encodeURIComponent(code) + '&brand=' + encodeURIComponent(b), {
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (data && cat.__sselApi) { cat.__sselApi.setOptions(data.categories || []); }
        })
        .catch(function () {})
        .finally(function () {
          catBtn.disabled = false;
          catBtn.style.opacity = '';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
      brand.querySelector('.ssel-val').addEventListener('change', refresh);
    });
  })();
</script>

@endsection"""

src = src.replace(old, script, 1)
print("ok   view brand->category script")
open(path, 'w').write(src)
PY

php -l "$CTRL"

echo ""
echo "SUCCESS — apply-import-category-scope applied."
echo "Deploy note: route added — deploy's optimize covers route + view cache."
