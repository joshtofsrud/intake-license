#!/bin/bash
# master-distributors-page-follows-code — the whole page, not just the form.
#
#   Three things.
#
#   1. A BROKEN GUARD I INTRODUCED. The previous patch inserted the HLC-only
#      wrapper by searching backwards for the nearest `<div`, which landed it
#      INSIDE `@if($state?->last_error)`, and appended its `@endif` after
#      `</x-filament-panels::page>`. Blade matched the nearest endif, so the
#      pairing became: my guard closed on the error div's endif, and the
#      error div's `@if` closed at end of file. Net effect — everything from
#      the sync error line to the bottom of the page only rendered when a
#      sync error existed. The counts balanced, which is why the check I ran
#      passed. Removed and placed properly around the Test mapping section.
#
#   2. The banner said "HLC connected" regardless of the selected
#      distributor, on a page whose entire point is now that it switches.
#
#   3. Run delta sync is hidden for BTI. BTI has no delta: BtiClient always
#      downloads the whole feed, and DistributorCatalogSyncService's --since
#      watermark has nothing to filter on. Offering the button implies an
#      incremental pull that doesn't exist, and it would silently do a full
#      one. The sync section says so instead.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if grep -q "MARKER-PAGE-FOLLOWS-CODE" resources/views/filament/pages/distributors.blade.php; then
  echo "master-distributors-page-follows-code already applied — aborting."; exit 1
fi

python3 - <<'PFC_0_EOF'
import io
p = 'resources/views/filament/pages/distributors.blade.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------- 1. undo the misplaced guard
bad_open = """            {{-- MARKER-MASTER-DIST-PER-CODE — the mapping test uses hardcoded HLC
     variant shapes, so it's hidden for other distributors rather than
     shown with data that cannot apply to them. --}}
@if (strtoupper($this->code) === 'HLC')
"""
assert s.count(bad_open) == 1, ('open', s.count(bad_open))
s = s.replace(bad_open, "")

bad_close = """</x-filament-panels::page>
@endif
"""
assert s.count(bad_close) == 1, ('close', s.count(bad_close))
s = s.replace(bad_close, "</x-filament-panels::page>\n")

# ---------------------------------------------- 2. banner follows the code
old = """            {{ $ok ? '● HLC connected' : '○ HLC not verified' }}"""
assert s.count(old) == 1, s.count(old)
new = """            {{-- MARKER-PAGE-FOLLOWS-CODE --}}
            {{ $ok ? '● ' . $this->code . ' connected' : '○ ' . $this->code . ' not verified' }}"""
s = s.replace(old, new)

old = """                Enter the platform key and click “Test connection”."""
assert s.count(old) == 1, s.count(old)
new = """                Enter {{ $this->code }}'s credentials and click “Test connection”."""
s = s.replace(old, new)

# ---------------------------------------------- 3. guard the mapping test
old = """    {{-- live mapping test --}}
    <x-filament::section heading="Test mapping">"""
assert s.count(old) == 1, s.count(old)
new = """    {{-- MARKER-PAGE-FOLLOWS-CODE — the mapping test runs hardcoded HLC variant
         shapes through the resolver, so it can say nothing about another
         distributor. Hidden rather than shown with data that cannot apply. --}}
    @if (strtoupper($this->code) === 'HLC')
    {{-- live mapping test --}}
    <x-filament::section heading="Test mapping">"""
s = s.replace(old, new)

# close it before the page component ends
old = """</x-filament-panels::page>"""
assert s.count(old) == 1, s.count(old)
new = """    @endif

</x-filament-panels::page>"""
s = s.replace(old, new)

# ---------------------------------------------- 4. note the feed shape
old = """        <x-slot name="description">Tier-1: pulls the shared catalog through the field map. Cost is nulled here (per-tenant). Use the header buttons to queue a run.</x-slot>"""
assert s.count(old) == 1, s.count(old)
new = """        <x-slot name="description">Tier-1: pulls the shared catalog through the field map. Cost is nulled here (per-tenant). Use the header buttons to queue a run.
            @if (strtoupper($this->code) === 'BTI')
                <br><b>{{ $this->code }} is a bulk file feed</b> — every run downloads the whole
                catalog, so there is no delta.
            @endif
        </x-slot>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
PFC_0_EOF

# ---------------------------------------------- header action
python3 - <<'PFC_1_EOF'
import io
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """            Action::make('runDelta')->label('Run delta sync')->color('gray')"""
assert s.count(old) == 1, s.count(old)
new = """            // MARKER-PAGE-FOLLOWS-CODE — BTI has no delta: the client always
            // downloads the whole feed and the --since watermark has nothing
            // to filter on. Offering the button would imply an incremental
            // pull that doesn't exist and quietly run a full one.
            Action::make('runDelta')->label('Run delta sync')->color('gray')
                ->visible(fn () => strtoupper($this->code) !== 'BTI')"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('header action ok')
PFC_1_EOF

echo
echo "master-distributors-page-follows-code applied."
