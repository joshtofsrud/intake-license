{{-- MARKER-REVIEW-PAGE --}}
<x-filament-panels::page>

    {{-- ---------------- controls ---------------- --}}
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="distributor"
                class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
            <option value="">All distributors ({{ $this->counts['dists'] }})</option>
            @foreach ($this->distributorOptions as $d)
                <option value="{{ $d }}">{{ $d }}</option>
            @endforeach
        </select>

        <input wire:model.live.debounce.400ms="search" placeholder="Search category or title…"
               class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5 w-64">

        @php $chips = ['review' => 'Needs review', 'info' => 'Info only', 'own' => 'Has own rule', 'all' => 'Everything']; @endphp
        @foreach ($chips as $key => $label)
            <button type="button" wire:click="$set('filter','{{ $key }}')"
                class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
                    {{ $filter === $key
                        ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 ring-primary-500'
                        : 'text-gray-500 dark:text-gray-400 ring-gray-300 dark:ring-white/10' }}">
                {{ $label }}
                <span class="opacity-60">{{ number_format($this->counts[$key]) }}</span>
            </button>
        @endforeach

        <div class="flex-1"></div>

        <button type="button" wire:click="startQueue"
            class="fi-btn text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
            Review queue ({{ $this->queueLeft }})
        </button>
        <button type="button" wire:click="recomposeDistributor"
            class="fi-btn text-xs font-semibold rounded-lg px-3 py-1.5 bg-primary-600 text-white">
            Recompose distributor
        </button>
    </div>

    {{-- ---------------- bulk bar ---------------- --}}
    @if (count($selected))
        <div class="flex items-center gap-3 rounded-xl bg-primary-500/10 ring-1 ring-primary-500/40 px-4 py-2.5">
            <b class="text-sm">{{ count($selected) }} selected</b>
            <button wire:click="markSelectedReviewed"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                Mark reviewed
            </button>
            <button wire:click="$set('selected', [])"
                class="text-xs text-gray-500">Clear</button>
        </div>
    @endif

    {{-- ---------------- list ---------------- --}}
    <div class="fi-section rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 text-[10px] uppercase tracking-wider text-gray-400">
                <tr>
                    <th class="w-10 py-2.5 px-4"></th>
                    <th class="text-left py-2.5 pr-4">Category</th>
                    <th class="text-left py-2.5 pr-4 w-32">Rule</th>
                    <th class="text-left py-2.5 pr-4">Sample title today</th>
                    <th class="text-right py-2.5 px-4 w-24">Items</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($this->scopes as $s)
                <tr wire:key="scope-{{ $s->id }}"
                    class="border-t border-gray-100 dark:border-white/5 hover:bg-gray-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 align-top">
                        <input type="checkbox" wire:model.live="selected" value="{{ $s->id }}"
                               class="rounded border-gray-300 dark:border-white/20">
                    </td>
                    <td class="pr-4 py-3 align-top cursor-pointer" wire:click="edit({{ $s->id }})">
                        <div class="font-semibold">
                            <span class="text-[10px] font-bold tracking-wide rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5 mr-1.5">{{ $s->distributor_code }}</span>
                            {{ $s->category_key !== '' ? $s->category_key : '(no category)' }}
                        </div>
                        @if ($s->reviewed)
                            <div class="text-[10px] text-green-600 dark:text-green-400 mt-1">reviewed</div>
                        @endif
                    </td>
                    <td class="pr-4 py-3 align-top text-xs">
                        @if ($s->has_own_rule)
                            <span class="text-primary-600 dark:text-primary-400 font-semibold">Own rule</span>
                        @else
                            <span class="text-gray-400">Inherited</span>
                            <div class="text-[10px] text-gray-400">
                                {{ $s->resolved_rule_scope ?: 'distributor default' }}
                            </div>
                        @endif
                    </td>
                    <td class="pr-4 py-3 align-top cursor-pointer" wire:click="edit({{ $s->id }})">
                        <div class="text-xs truncate max-w-md">{{ $s->sample_title ?: '—' }}</div>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @foreach ($s->problems() as $f)
                                <span class="text-[10px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5
                                    {{ $f['severity'] === 'bad'
                                        ? 'bg-red-500/15 text-red-600 dark:text-red-400'
                                        : 'bg-amber-500/15 text-amber-600 dark:text-amber-400' }}"
                                    title="{{ $f['detail'] }}">{{ $f['label'] }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-3 align-top text-right font-mono text-xs text-gray-500">
                        {{ number_format($s->item_count) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-gray-400">
                    Nothing here. Try another filter, or run
                    <span class="font-mono">catalog:scan-titles</span>.
                </td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3 border-t border-gray-100 dark:border-white/5">
            {{ $this->scopes->links() }}
        </div>
    </div>

    {{-- ---------------- drawer ---------------- --}}
    @if ($this->editing)
        @php $sc = $this->editing; @endphp
        {{-- MARKER-DRAWER-UX — teleported to body. position:fixed is contained by
             any ancestor with a transform, and Filament's page wrapper has one,
             which is why this opened mid-page instead of against the edge. --}}
        @teleport('body')
        <div wire:key="drawer-{{ $sc->id }}">
        <div class="fixed inset-0 bg-black/50 z-40" wire:click="closeDrawer"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-full max-w-2xl z-50 overflow-y-auto
                      bg-white dark:bg-gray-900 ring-1 ring-gray-950/10 dark:ring-white/10">

            <div class="sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-white/10 px-6 py-4 z-10">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold">
                            {{ $sc->distributor_code }} · {{ $sc->category_key ?: '(no category)' }}
                        </h2>
                        <p class="text-xs text-gray-400 font-mono mt-0.5">
                            {{ number_format($sc->item_count) }} items
                            @if ($queueMode) · {{ $this->queueLeft }} left in queue @endif
                        </p>
                    </div>
                    <button wire:click="closeDrawer" class="text-gray-400 text-sm">Close</button>
                </div>
            </div>

            <div class="px-6 py-5 space-y-6">

                @foreach ($sc->problems() as $f)
                    <div class="rounded-lg px-4 py-3 text-xs
                        {{ $f['severity'] === 'bad'
                            ? 'bg-red-500/10 text-red-600 dark:text-red-400 ring-1 ring-red-500/30'
                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-1 ring-amber-500/30' }}">
                        <b>{{ $f['label'] }}</b> — {{ $f['detail'] }}
                    </div>
                @endforeach

                <div>
                    <label class="block text-xs font-semibold mb-1.5">Title template</label>
                    <input wire:model.live.debounce.250ms="tpl"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">
                    @if (! $sc->has_own_rule)
                        {{-- MARKER-ONE-RESOLVER — name the rule being inherited, so a
                             resolution problem is visible instead of silent. --}}
                        <p class="text-[11px] text-gray-400 mt-1.5">
                            Inherited from <b>{{ $this->inheritedFrom }}</b>.
                            Saving creates a rule for this category only.
                        </p>
                    @endif
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1.5">Size comes from</label>
                    <input wire:model.live.debounce.250ms="sizeAttr" placeholder="Labeled Size"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">
                    <p class="text-[11px] text-gray-400 mt-1.5">
                        Attribute names, comma separated, tried in order before any text matching.
                    </p>
                </div>

                {{-- MARKER-DRAWER-UX — chips add themselves to the template. --}}
                <div>
                    <div class="text-xs font-semibold mb-2">Tokens — click to add</div>
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        @foreach ($this->baseTokens as $t)
                            <button type="button" wire:click="addToken(@js($t))"
                                class="font-mono text-[11px] rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5
                                       hover:ring-1 hover:ring-primary-500">{{ $t }}</button>
                        @endforeach
                    </div>

                    @if (count($this->attrNames))
                        <div class="text-xs font-semibold mb-2">Attributes on these items</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->attrNames as $n)
                                @php $tok = '{attr:' . $n . '}'; @endphp
                                <button type="button" wire:click="addToken(@js($tok))"
                                    class="font-mono text-[11px] rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5
                                           hover:ring-1 hover:ring-primary-500">{{ $tok }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if (count($sc->notes()))
                    <div class="text-[11px] text-gray-400 space-y-1">
                        @foreach ($sc->notes() as $n)
                            <div>{{ $n['label'] }} — {{ $n['detail'] }}</div>
                        @endforeach
                    </div>
                @endif

                <div>
                    <div class="text-xs font-semibold mb-2 flex items-center gap-2">
                        <span>Preview — real items from this category</span>
                        {{-- MARKER-DRAWER-UX — pending state, so a slow round trip
                             reads as working rather than stuck. --}}
                        <span wire:loading.delay wire:target="tpl,sizeAttr,addToken"
                              class="text-[10px] font-normal text-gray-400">recalculating…</span>
                    </div>
                    <div class="rounded-lg ring-1 ring-gray-200 dark:ring-white/10 divide-y divide-gray-100 dark:divide-white/5"
                         wire:loading.class="opacity-50" wire:target="tpl,sizeAttr,addToken">
                        @forelse ($this->preview as $p)
                            <div class="px-4 py-3" wire:key="prev-{{ $sc->id }}-{{ $loop->index }}">
                                <div class="text-[11px] text-gray-400 line-through">{{ $p['was'] }}</div>
                                <div class="text-sm font-semibold mt-0.5">{{ $p['now'] ?: '—' }}</div>
                                <div class="text-[10px] font-mono text-gray-400 mt-1">{{ $p['sku'] }}</div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-xs text-gray-400 text-center">
                                No samples stored — re-run catalog:scan-titles.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-white/10 px-6 py-3 flex justify-end gap-2">
                @if ($queueMode)
                    <button wire:click="approve({{ $sc->id }})"
                        class="text-xs font-semibold rounded-lg px-3 py-2 ring-1 ring-gray-300 dark:ring-white/10">
                        Looks fine — next
                    </button>
                @endif
                <button wire:click="save"
                    class="text-xs font-semibold rounded-lg px-3 py-2 ring-1 ring-gray-300 dark:ring-white/10">
                    Save only
                </button>
                <button wire:click="saveAndRecompose"
                    class="text-xs font-semibold rounded-lg px-3 py-2 bg-primary-600 text-white">
                    Save &amp; recompose {{ $sc->distributor_code }}
                </button>
            </div>
        </aside>
        </div>
        @endteleport
    @endif

</x-filament-panels::page>
