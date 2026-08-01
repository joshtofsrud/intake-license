{{-- MARKER-CATALOG-COVERAGE --}}
<x-filament-panels::page>

  @php $codes = $this->distributorCodes(); $t = $this->totals; @endphp

  <div class="flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Compare</label>
      <select wire:model.live="codeA"
              class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
        @foreach ($codes as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
      </select>
    </div>
    <span class="pb-2 text-gray-400 text-sm">against</span>
    <div>
      <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">&nbsp;</label>
      <select wire:model.live="codeB"
              class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
        @foreach ($codes as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
      </select>
    </div>

    <div class="flex-1"></div>

    <input wire:model.live.debounce.400ms="search" placeholder="Find a brand…"
           class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5 w-52">

    <select wire:model.live="sort"
            class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
      <option value="gap">Biggest gaps first</option>
      <option value="overlap">Most overlap first</option>
      <option value="size">Largest brands first</option>
      <option value="name">A–Z</option>
    </select>
  </div>

  @if ($codeA === $codeB)
    <div class="rounded-lg bg-amber-500/10 ring-1 ring-amber-500/30 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
      Pick two different distributors.
    </div>
  @else

    {{-- totals --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-gray-200 dark:bg-white/10 rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10">
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold">{{ number_format($t['a']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">{{ $codeA }} items</div>
      </div>
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold">{{ number_format($t['b']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">{{ $codeB }} items</div>
      </div>
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($t['a_matched']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">carried by both</div>
      </div>
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold">{{ number_format($t['shared_brands']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">brands in both</div>
      </div>
    </div>

    <p class="text-xs text-gray-400">
      Matched means a link a person hasn't disputed — auto-linked or confirmed. Held and rejected
      pairs count as unmatched.
      <br>
      Brands don't always agree across distributors: BTI files Avid parts under SRAM, so a
      one-sided brand row is often a naming difference rather than missing coverage.
    </p>

    {{-- brand table --}}
    <div class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-white/5 text-[10px] uppercase tracking-wider text-gray-400">
          <tr>
            <th class="text-left py-2.5 px-4">Brand</th>
            <th class="text-right py-2.5 px-3">{{ $codeA }}</th>
            <th class="text-right py-2.5 px-3">{{ $codeB }}</th>
            <th class="text-right py-2.5 px-3">Both</th>
            <th class="text-right py-2.5 px-3">{{ $codeA }} only</th>
            <th class="text-right py-2.5 px-3">{{ $codeB }} only</th>
            <th class="text-right py-2.5 px-4">Overlap</th>
          </tr>
        </thead>
        <tbody>
        @forelse ($this->rows as $r)
          <tr wire:key="brand-{{ $r['brand'] }}"
              wire:click="drill(@js($r['brand']))"
              class="border-t border-gray-100 dark:border-white/5 cursor-pointer
                     hover:bg-gray-50 dark:hover:bg-white/5
                     {{ $brand === $r['brand'] ? 'bg-primary-500/5' : '' }}">
            <td class="px-4 py-2.5 font-medium">
              {{ $r['brand'] }}
              @unless ($r['both'])
                <span class="text-[10px] uppercase tracking-wide text-gray-400 ml-2">one side only</span>
              @endunless
            </td>
            <td class="px-3 py-2.5 text-right font-mono text-xs">{{ number_format($r['a']) }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs">{{ number_format($r['b']) }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs text-primary-600 dark:text-primary-400">
              {{ number_format(max($r['a_matched'], $r['b_matched'])) }}
            </td>
            <td class="px-3 py-2.5 text-right font-mono text-xs text-gray-500">{{ number_format($r['a_only']) }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs text-gray-500">{{ number_format($r['b_only']) }}</td>
            <td class="px-4 py-2.5 text-right font-mono text-xs">
              {{ $r['rate'] === null ? '—' : $r['rate'] . '%' }}
            </td>
          </tr>

          @if ($brand === $r['brand'])
            <tr wire:key="drill-{{ $r['brand'] }}">
              <td colspan="7" class="px-4 py-3 bg-gray-50 dark:bg-white/5 border-t border-gray-100 dark:border-white/5">
                <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-2">
                  Unmatched in {{ $r['brand'] }} — no counterpart at the other distributor
                </div>
                @if (count($this->drill))
                  <div class="max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-200 dark:ring-white/10">
                    <table class="w-full text-xs">
                      @foreach ($this->drill as $d)
                        <tr class="border-b border-gray-100 dark:border-white/5 last:border-0">
                          <td class="px-3 py-1.5 w-14">
                            <span class="text-[10px] font-bold rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5">
                              {{ $d->distributor_code }}
                            </span>
                          </td>
                          <td class="px-3 py-1.5">{{ $d->name }}</td>
                          <td class="px-3 py-1.5 font-mono text-gray-400">{{ $d->manufacturer_sku ?: '—' }}</td>
                          <td class="px-3 py-1.5 font-mono text-gray-400">{{ $d->upc ?: ($d->ean ?: 'no barcode') }}</td>
                          <td class="px-3 py-1.5 text-right font-mono text-gray-400">
                            {{ $d->msrp_cents ? '$' . number_format($d->msrp_cents / 100, 2) : '—' }}
                          </td>
                        </tr>
                      @endforeach
                    </table>
                  </div>
                  <p class="text-[11px] text-gray-400 mt-2">Showing up to 300.</p>
                @else
                  <p class="text-xs text-gray-400">Everything in this brand is matched.</p>
                @endif
              </td>
            </tr>
          @endif
        @empty
          <tr><td colspan="7" class="p-8 text-center text-sm text-gray-400">
            Nothing to compare. Sync both distributors, then run
            <span class="font-mono">catalog:index-identifiers</span> and
            <span class="font-mono">catalog:match</span>.
          </td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  @endif

</x-filament-panels::page>
