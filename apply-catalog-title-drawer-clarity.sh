#!/bin/bash
# catalog-title-drawer-clarity — drawer pins right, preview says what it shows.
#
#   1. After teleporting to body the drawer landed on the LEFT. The panel's
#      utility classes aren't dependable once the element lives outside the
#      Filament wrapper, so the positioning is now inline style — position,
#      edges, width and z-index can't be purged, overridden or reordered.
#      Layout that must be right gets styles, not classes.
#
#   2. The preview relied on grey-vs-bold to say which line was current and
#      which was proposed, which is only obvious once you already know. Each
#      line is now labelled outright, and a row whose title doesn't change
#      says so rather than showing the same text twice with no explanation.
#      The strikethrough is gone: it read as "this item is being deleted".
# NO MIGRATION. Server: view:clear is enough.
set -e
if grep -q "MARKER-DRAWER-CLARITY" resources/views/filament/pages/catalog-title-review.blade.php; then
  echo "catalog-title-drawer-clarity already applied — aborting."; exit 1
fi

python3 - <<'CTDC_0_EOF'
import io
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- 1. pin it with inline styles ----------------------------------------
old = """        <div class="fixed inset-0 bg-black/50 z-40" wire:click="closeDrawer"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-full max-w-2xl z-50 overflow-y-auto
                      bg-white dark:bg-gray-900 ring-1 ring-gray-950/10 dark:ring-white/10">"""
assert s.count(old) == 1, s.count(old)
new = """        {{-- MARKER-DRAWER-CLARITY — inline positioning. Teleported out of the
             Filament wrapper the utility classes stopped applying and this
             opened against the left edge; styles can't be purged or lost. --}}
        <div wire:click="closeDrawer"
             style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40"></div>
        <aside style="position:fixed;top:0;right:0;bottom:0;width:min(680px,100vw);
                      z-index:50;overflow-y:auto"
               class="bg-white dark:bg-gray-900 ring-1 ring-gray-950/10 dark:ring-white/10">"""
s = s.replace(old, new)

# --- 2. label the preview lines ------------------------------------------
old = """                            <div class="px-4 py-3" wire:key="prev-{{ $sc->id }}-{{ $loop->index }}">
                                <div class="text-[11px] text-gray-400 line-through">{{ $p['was'] }}</div>
                                <div class="text-sm font-semibold mt-0.5">{{ $p['now'] ?: '—' }}</div>
                                <div class="text-[10px] font-mono text-gray-400 mt-1">{{ $p['sku'] }}</div>
                            </div>"""
assert s.count(old) == 1, s.count(old)
new = """                            {{-- MARKER-DRAWER-CLARITY — say which line is which. --}}
                            @php $changed = trim($p['was']) !== trim($p['now']); @endphp
                            <div class="px-4 py-3" wire:key="prev-{{ $sc->id }}-{{ $loop->index }}">
                                <div class="flex gap-2 items-baseline">
                                    <span class="text-[9.5px] uppercase tracking-wider text-gray-400 w-20 shrink-0">Now</span>
                                    <span class="text-[11px] text-gray-400">{{ $p['was'] ?: '—' }}</span>
                                </div>
                                <div class="flex gap-2 items-baseline mt-1">
                                    <span class="text-[9.5px] uppercase tracking-wider w-20 shrink-0
                                        {{ $changed ? 'text-primary-500' : 'text-gray-400' }}">
                                        {{ $changed ? 'After saving' : 'Unchanged' }}
                                    </span>
                                    <span class="text-sm font-semibold">{{ $p['now'] ?: '—' }}</span>
                                </div>
                                <div class="text-[10px] font-mono text-gray-400 mt-1.5 pl-[5.5rem]">{{ $p['sku'] }}</div>
                            </div>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('drawer clarity ok')
CTDC_0_EOF

echo
echo "catalog-title-drawer-clarity applied."
