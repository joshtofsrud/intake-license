{{-- MARKER-PATCH-HLCE --}}
<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
        {{-- token reference --}}
        <div class="lg:col-span-1 fi-section rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <h3 class="text-sm font-semibold mb-3">Tokens</h3>
            <dl class="space-y-2 text-xs">
                @foreach ($tokens as $tok => $desc)
                    <div>
                        <dt class="font-mono text-primary-600 dark:text-primary-400">{{ $tok }}</dt>
                        <dd class="text-gray-500 dark:text-gray-400">{{ $desc }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- MARKER-PATCH-544 — the attribute names that actually exist in this
                 distributor's data, with row counts. These are the only valid
                 targets for {attr:NAME}; anything not listed is an HLC data gap. --}}
            <h3 class="text-sm font-semibold mt-5 mb-1">Available attributes</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                Real attribute names in this catalog and how many rows carry each.
                Use them as <span class="font-mono">{attr:Name}</span>. Cached hourly.
            </p>
            <div class="flex flex-wrap gap-1.5 max-h-64 overflow-y-auto">
                @forelse ($attrNames as $name => $count)
                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-xs">
                        <span class="font-mono">{{ $name }}</span>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </span>
                @empty
                    <span class="text-xs text-gray-400">No attributes found for this distributor.</span>
                @endforelse
            </div>
        </div>

        {{-- live preview --}}
        <div class="lg:col-span-2 fi-section rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <h3 class="text-sm font-semibold mb-1">Live preview — real rows</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Identical titles are flagged. If look-alikes collide and the subtitle is empty,
                the data has nothing to distinguish them — that is a data gap, not a template gap.
            </p>
            <div class="space-y-3">
                @forelse ($preview as $r)
                    @if (!empty($r['missing']))
                        <div class="text-xs text-gray-400 font-mono">{{ $r['sku'] }} — not found in catalog</div>
                    @else
                        <div class="border-b border-gray-100 dark:border-white/5 pb-3">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-[11px] text-gray-400">{{ $r['sku'] }}</span>
                                @if ($r['collides'])
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-400">duplicate title</span>
                                @endif
                            </div>
                            <div class="text-sm font-semibold {{ $r['collides'] ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                {{ $r['title'] !== '' ? $r['title'] : '—' }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $r['subtitle'] }}</div>
                            @if (!empty($r['search']))
                                <div class="text-[10px] font-mono text-gray-400 dark:text-gray-600 mt-1 break-words">{{ $r['search'] }}</div>
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="text-xs text-gray-400">No preview SKUs.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
