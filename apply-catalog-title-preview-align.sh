#!/bin/bash
# catalog-title-preview-align — fixes the preview label collision.
#   The Now / After saving / Unchanged labels sat in a fixed w-20 shrink-0
#   box inside a flex row. "Now" fits in 80px so its gap looked right;
#   "Unchanged" and "After saving" don't, and with shrink-0 they overflowed
#   the box straight into the title text instead of pushing it along.
#   Replaced with a two-column grid: the label column is one fixed track, so
#   every row's title starts at the same x no matter how long the label is,
#   and the SKU sits in the second column instead of being nudged into place
#   with a hand-picked padding value.
# NO MIGRATION. Server: view:clear.
set -e
if grep -q "MARKER-PREVIEW-ALIGN" resources/views/filament/pages/catalog-title-review.blade.php; then
  echo "catalog-title-preview-align already applied — aborting."; exit 1
fi

python3 - <<'CTPA_0_EOF'
import io
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                            <div class="px-4 py-3" wire:key="prev-{{ $sc->id }}-{{ $loop->index }}">
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
assert s.count(old) == 1, s.count(old)

new = """                            {{-- MARKER-PREVIEW-ALIGN — grid, not fixed-width flex labels.
                                 A w-20 shrink-0 label overflowed into the title as soon
                                 as the word was wider than the box. --}}
                            <div class="px-4 py-3 grid grid-cols-[5.5rem_1fr] gap-x-3 gap-y-1 items-baseline"
                                 wire:key="prev-{{ $sc->id }}-{{ $loop->index }}">

                                <span class="text-[9.5px] uppercase tracking-wider text-gray-400 whitespace-nowrap">Now</span>
                                <span class="text-[11px] text-gray-400">{{ $p['was'] ?: '—' }}</span>

                                <span class="text-[9.5px] uppercase tracking-wider whitespace-nowrap
                                    {{ $changed ? 'text-primary-500' : 'text-gray-400' }}">{{ $changed ? 'After saving' : 'Unchanged' }}</span>
                                <span class="text-sm font-semibold">{{ $p['now'] ?: '—' }}</span>

                                <span></span>
                                <span class="text-[10px] font-mono text-gray-400">{{ $p['sku'] }}</span>
                            </div>"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('preview align ok')
CTPA_0_EOF

echo
echo "catalog-title-preview-align applied."
