{{-- MARKER-MATCH-REVIEW --}}
<x-filament-panels::page>

  @php
    $c = $this->counts;
    $reasonLabels = [
      'all'       => 'Everything held',
      'ambiguous' => 'Matches more than one',
      'msrp_far'  => 'Price far apart',
      'msrp_gap'  => 'Price differs',
      'mpn_only'  => 'Part number only',
    ];
    $why = [
      'ambiguous' => 'One row matches several on the other side — usually one distributor splits a product the other keeps whole.',
      'msrp_far'  => 'Same barcode, very different price. Usually a single item against a multi-pack.',
      'msrp_gap'  => 'Same barcode, prices apart enough to be worth a look.',
      'mpn_only'  => 'No barcode agreed — same brand and part number only. Good evidence, not proof.',
    ];
  @endphp

  {{-- filters --}}
  <div class="flex flex-wrap items-center gap-2">
    @foreach ($reasonLabels as $key => $label)
      <button type="button" wire:click="$set('reason','{{ $key }}')"
        class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
          {{ $reason === $key && $status === 'held'
             ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 ring-primary-500'
             : 'text-gray-500 dark:text-gray-400 ring-gray-300 dark:ring-white/10' }}">
        {{ $label }} <span class="opacity-60">{{ number_format($c[$key] ?? 0) }}</span>
      </button>
    @endforeach

    <div class="flex-1"></div>

    <button type="button" wire:click="$set('status','confirmed')"
      class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
        {{ $status === 'confirmed' ? 'ring-primary-500 text-primary-600' : 'ring-gray-300 dark:ring-white/10 text-gray-500' }}">
      Linked by hand <span class="opacity-60">{{ number_format($c['confirmed']) }}</span>
    </button>
    <button type="button" wire:click="$set('status','rejected')"
      class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
        {{ $status === 'rejected' ? 'ring-primary-500 text-primary-600' : 'ring-gray-300 dark:ring-white/10 text-gray-500' }}">
      Not the same <span class="opacity-60">{{ number_format($c['rejected']) }}</span>
    </button>
    <button type="button" wire:click="$set('status','held')"
      class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
        {{ $status === 'held' ? 'ring-primary-500 text-primary-600' : 'ring-gray-300 dark:ring-white/10 text-gray-500' }}">
      Needs review
    </button>
  </div>

  <p class="text-xs text-gray-400">
    {{ number_format($c['auto']) }} pairs linked automatically and aren't listed here.
  </p>

  @if ($status === 'held' && $reason !== 'all' && isset($why[$reason]))
    <div class="rounded-lg bg-amber-500/10 ring-1 ring-amber-500/30 px-4 py-2.5 text-xs text-amber-700 dark:text-amber-400">
      {{ $why[$reason] }}
    </div>
  @endif

  {{-- pairs --}}
  @php $sib = $this->siblings; @endphp

  <div class="space-y-3">
    @forelse ($this->matches as $m)
      @php
        $a = $m->rowA; $b = $m->rowB;
        $sa = $sib[$m->row_a_id] ?? 0;
        $sb = $sib[$m->row_b_id] ?? 0;
      @endphp

      <div wire:key="match-{{ $m->id }}"
           class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">

        <div class="flex items-center justify-between gap-3 px-4 py-2 bg-gray-50 dark:bg-white/5">
          <div class="flex items-center gap-2 text-[10px] uppercase tracking-wider text-gray-400">
            <span>matched on {{ $m->matched_on }}</span>
            @if ($m->msrp_spread_pct !== null)
              <span>· price differs {{ $m->msrp_spread_pct }}%</span>
            @endif
            @if ($m->hold_reason)
              <span class="rounded px-1.5 py-0.5 bg-amber-500/15 text-amber-600 dark:text-amber-400 font-bold">
                {{ $reasonLabels[$m->hold_reason] ?? $m->hold_reason }}
              </span>
            @endif
          </div>
          <div class="flex gap-2">
            @if ($m->status === 'held')
              <button wire:click="reject({{ $m->id }})"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                Not the same
              </button>
              <button wire:click="confirm({{ $m->id }})"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 bg-primary-600 text-white">
                Same product
              </button>
            @else
              <button wire:click="reopen({{ $m->id }})"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                Undo
              </button>
            @endif
          </div>
        </div>

        <div class="grid md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-100 dark:divide-white/5">
          @foreach ([[$a, $m->code_a, $sa], [$b, $m->code_b, $sb]] as [$row, $code, $others])
            <div class="p-4">
              <div class="flex items-center gap-2 mb-1.5">
                <span class="text-[10px] font-bold tracking-wide rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5">
                  {{ $code }}
                </span>
                @if ($others > 0)
                  <span class="text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                    also matches {{ $others }} other{{ $others === 1 ? '' : 's' }}
                  </span>
                @endif
              </div>

              <div class="text-sm font-semibold leading-snug">{{ $row?->name ?? '—' }}</div>

              <div class="mt-2 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                <div>
                  <span class="text-gray-400">MSRP</span>
                  <span class="font-mono ml-2">
                    {{ $row?->msrp_cents ? '$' . number_format($row->msrp_cents / 100, 2) : '—' }}
                  </span>
                </div>
                <div>
                  <span class="text-gray-400">Part no.</span>
                  <span class="font-mono ml-2">{{ $row?->manufacturer_sku ?: '—' }}</span>
                </div>
                <div>
                  <span class="text-gray-400">UPC</span>
                  <span class="font-mono ml-2">{{ $row?->upc ?: '—' }}</span>
                  @if ($row?->ean)
                    <span class="text-gray-400 ml-2">EAN</span>
                    <span class="font-mono ml-1">{{ $row->ean }}</span>
                  @endif
                </div>
                <div>
                  <span class="text-gray-400">Brand</span>
                  <span class="ml-2">{{ $row?->manufacturer ?: '—' }}</span>
                </div>
                <div>
                  <span class="text-gray-400">Category</span>
                  <span class="ml-2">{{ $row?->category_path ?: ($row?->category ?: '—') }}</span>
                </div>
                <div>
                  <span class="text-gray-400">Attributes</span>
                  <span class="ml-2">{{ count($row?->attributes ?? []) }}</span>
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @empty
      <div class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 p-10 text-center">
        <p class="text-sm text-gray-400">Nothing here.</p>
        <p class="text-xs text-gray-400 mt-1">
          Run <span class="font-mono">catalog:match</span> after a sync to look for new pairs.
        </p>
      </div>
    @endforelse
  </div>

  <div>{{ $this->matches->links() }}</div>

</x-filament-panels::page>
