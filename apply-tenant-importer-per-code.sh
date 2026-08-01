#!/bin/bash
# tenant-importer-per-code — import from BTI, not just HLC.
#
#   The tenant importer was HLC-only in every layer: importFilterOptions()
#   queried self::CODE, importRun() passed self::CODE to the import service,
#   and the view said "Import from HLC" with an HLC-shaped heading. A shop
#   with BTI connected had 24,643 items in the shared catalog and no way to
#   pull any of them in.
#
#   Now a distributor picker sits at the top, and the brand list, category
#   list, item count and the import itself all follow it. Only distributors
#   the registry supports AND that actually have catalog rows are offered —
#   showing an empty distributor would look like a broken filter.
#
#   The picker reloads the page rather than filtering in the browser: brands
#   and categories are per distributor and there are thousands of each, so
#   shipping every distributor's lists to filter client-side would be worse
#   than a round trip.
#
#   Matching means this is no longer purely additive, and that's the point:
#   importing a BTI item that is already carried from HLC now attaches a
#   second source to the existing item instead of creating a duplicate,
#   because DistributorCatalogImportService consults catalog_matches first.
#   The preview counts already distinguish merged from created.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-IMPORTER-PER-CODE" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "tenant-importer-per-code already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'TIP_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# --- filter options take a code -----------------------------------------
old = """    private function importFilterOptions(): array
    {
        $base = \\App\\Models\\PlatformDistributorCatalog::query()
            ->where('distributor_code', self::CODE)->where('is_active', true);

        return [
            'brands' => (clone $base)->whereNotNull('manufacturer')
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            'categories' => (clone $base)->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
    }"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-IMPORTER-PER-CODE \u2014 brands, categories and the item count for
     * ONE distributor. Was pinned to self::CODE, which left a shop with BTI
     * connected unable to import any of its 24,643 items.
     */
    private function importFilterOptions(string $code): array
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
    }

    /**
     * Distributors the registry supports AND that have catalog rows. A
     * supported distributor with an empty catalog would present as a broken
     * filter rather than as "nothing synced yet".
     *
     * @return array<int,string>
     */
    private function importableCodes(): array
    {
        $supported = app(\\App\\Services\\Distributors\\DistributorRegistry::class)->supported();

        return \\App\\Models\\PlatformDistributorCatalog::query()
            ->whereIn('distributor_code', $supported)
            ->where('is_active', true)
            ->select('distributor_code')->distinct()
            ->orderBy('distributor_code')
            ->pluck('distributor_code')->all();
    }

    /** The distributor being imported from, defaulting to the first available. */
    private function importCode(?string $requested): string
    {
        $codes = $this->importableCodes();
        $requested = strtoupper((string) $requested);

        return in_array($requested, $codes, true)
            ? $requested
            : ($codes[0] ?? self::CODE);
    }"""
s = s.replace(old, new)

# --- import() -----------------------------------------------------------
old = """    public function import(): \\Illuminate\\Contracts\\View\\View
    {
        $this->guard();

        return view('tenant.distributors.import', array_merge(
            $this->importFilterOptions(),
            ['filters' => []],
        ));
    }"""
assert s.count(old) == 1, s.count(old)
new = """    public function import(\\Illuminate\\Http\\Request $request): \\Illuminate\\Contracts\\View\\View
    {
        $this->guard();

        // MARKER-IMPORTER-PER-CODE
        $code = $this->importCode($request->query('code'));

        return view('tenant.distributors.import', array_merge(
            $this->importFilterOptions($code),
            ['filters' => []],
        ));
    }"""
s = s.replace(old, new)

# --- importRun() --------------------------------------------------------
old = """        $data = $request->validate([
            'mode'               => ['required', 'in:preview,commit'],
            'brand'              => ['nullable', 'string', 'max:128'],
            'category'           => ['nullable', 'string', 'max:64'],
            'include_unsellable' => ['nullable'],
        ]);"""
assert s.count(old) == 1, s.count(old)
new = """        $data = $request->validate([
            'mode'               => ['required', 'in:preview,commit'],
            'code'               => ['nullable', 'string', 'max:32'],
            'brand'              => ['nullable', 'string', 'max:128'],
            'category'           => ['nullable', 'string', 'max:64'],
            'include_unsellable' => ['nullable'],
        ]);

        // MARKER-IMPORTER-PER-CODE
        $code = $this->importCode($data['code'] ?? null);"""
s = s.replace(old, new)

old = """        $view = $this->importFilterOptions();"""
assert s.count(old) == 1, s.count(old)
new = """        $view = $this->importFilterOptions($code);"""
s = s.replace(old, new)

old = """            ->import(tenant()->id, self::CODE, $filters, $data['mode'] !== 'commit', 2000);"""
assert s.count(old) == 1, s.count(old)
new = """            ->import(tenant()->id, $code, $filters, $data['mode'] !== 'commit', 2000);"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
TIP_0_EOF

# ------------------------------------------------------------------ view
python3 - <<'TIP_1_EOF'
import io
p = 'resources/views/tenant/distributors/import.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """@php $pageTitle = 'Import from HLC'; @endphp"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, """@php $pageTitle = 'Import from a distributor'; @endphp""")

old = """  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>"""
assert s.count(old) == 1, s.count(old)
new = """  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Import from {{ $importCode }}</h1>

  {{-- MARKER-IMPORTER-PER-CODE — brands, categories and counts are per
       distributor, and there are thousands of each, so switching reloads
       rather than shipping every distributor's lists to filter in the
       browser. --}}
  @if (count($importCodes) > 1)
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
      <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600">Distributor</span>
      @foreach ($importCodes as $c)
        <a href="{{ route('tenant.distributors.import', ['code' => $c]) }}"
           style="padding:5px 13px;border-radius:20px;font-size:12.5px;font-weight:600;text-decoration:none;
                  border:1px solid {{ $c === $importCode ? 'var(--ia-text)' : 'var(--ia-border)' }};
                  background:{{ $c === $importCode ? 'var(--ia-text)' : 'var(--ia-surface-2)' }};
                  color:{{ $c === $importCode ? 'var(--ia-bg)' : 'var(--ia-text-dim)' }}">{{ $c }}</a>
      @endforeach
    </div>
  @endif"""
s = s.replace(old, new)

old = """<b>{{ number_format($catalogTotal) }}</b> HLC items in the shared catalog."""
assert s.count(old) == 1, s.count(old)
new = """<b>{{ number_format($catalogTotal) }}</b> {{ $importCode }} items in the shared catalog.
      An item another distributor already supplies is added as a second source rather than duplicated."""
s = s.replace(old, new)

# every import form must carry the distributor
old = """<form method="POST" action="{{ route('tenant.distributors.import.run') }}">"""
n = s.count(old)
assert n >= 1, n
new = """<form method="POST" action="{{ route('tenant.distributors.import.run') }}">
            <input type="hidden" name="code" value="{{ $importCode }}">"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok, forms tagged:', n)
TIP_1_EOF

echo
echo "tenant-importer-per-code applied."
