<x-filament-panels::page>
    @php
        $kind = $plan['kind'] ?? 'changelog';
        $isRoadmap = $kind === \App\Services\Platform\ChangelogRoadmapImporter::KIND_ROADMAP;
        $newCount = count($plan['new'] ?? []);
        $updCount = count($plan['updates'] ?? []);
        $skipCount = count($plan['skipped'] ?? []);
        $errCount = count($plan['errors'] ?? []);
    @endphp

    <div class="space-y-6">

        {{-- Summary bar --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-emerald-300">New</div>
                <div class="text-2xl font-semibold text-emerald-100">{{ $newCount }}</div>
            </div>
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-amber-300">Updates</div>
                <div class="text-2xl font-semibold text-amber-100">{{ $updCount }}</div>
            </div>
            <div class="rounded-lg border border-zinc-500/30 bg-zinc-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-400">Skipped (no change)</div>
                <div class="text-2xl font-semibold text-zinc-300">{{ $skipCount }}</div>
            </div>
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-rose-300">Errors</div>
                <div class="text-2xl font-semibold text-rose-100">{{ $errCount }}</div>
            </div>
        </div>

        <div class="text-sm text-zinc-400">
            All imported entries default to <strong class="text-zinc-200">drafts (unpublished)</strong>.
            You'll publish them manually from the {{ $isRoadmap ? 'roadmap' : 'changelog' }} list once you've reviewed.
        </div>

        {{-- Errors --}}
        @if ($errCount > 0)
            <div class="rounded-lg border border-rose-500/40 bg-rose-500/5 p-4 space-y-2">
                <div class="font-semibold text-rose-200">Errors — these will not be imported</div>
                <ul class="space-y-2 text-sm text-rose-100/90">
                    @foreach ($plan['errors'] as $err)
                        <li class="border-l-2 border-rose-500/40 pl-3">
                            @if (!empty($err['line']))
                                <span class="text-rose-300">Line {{ $err['line'] }}:</span>
                            @endif
                            {{ $err['message'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- New entries --}}
        @if ($newCount > 0)
            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-zinc-100">New entries ({{ $newCount }})</h2>
                <div class="space-y-2">
                    @foreach ($plan['new'] as $i => $row)
                        <label class="block rounded-lg border border-zinc-700 bg-zinc-900/40 p-4 cursor-pointer hover:border-emerald-500/50">
                            <div class="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model="newSelections.{{ $i }}"
                                    class="mt-1 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-500"
                                />
                                <div class="flex-1 space-y-1">
                                    <div class="flex items-center gap-2 text-xs text-zinc-400">
                                        @if ($isRoadmap)
                                            <span class="rounded bg-zinc-800 px-2 py-0.5 text-zinc-200">{{ $row['status'] }}</span>
                                            @if (!empty($row['rough_timeframe']))
                                                <span>· {{ $row['rough_timeframe'] }}</span>
                                            @endif
                                        @else
                                            <span>{{ $row['shipped_on'] }}</span>
                                            @if (!empty($row['category']))
                                                <span>· <span class="rounded bg-zinc-800 px-2 py-0.5 text-zinc-200">{{ $row['category'] }}</span></span>
                                            @endif
                                            @if (!empty($row['is_highlighted']))
                                                <span class="rounded bg-lime-500/20 px-2 py-0.5 text-lime-200">Highlighted</span>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="font-medium text-zinc-100">{{ $row['title'] }}</div>
                                    <div class="text-sm text-zinc-300 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($row['body'], 400) }}</div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Updates --}}
        @if ($updCount > 0)
            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-zinc-100">Updates to existing entries ({{ $updCount }})</h2>
                <div class="space-y-2">
                    @foreach ($plan['updates'] as $i => $row)
                        <label class="block rounded-lg border border-zinc-700 bg-zinc-900/40 p-4 cursor-pointer hover:border-amber-500/50">
                            <div class="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model="updateSelections.{{ $i }}"
                                    class="mt-1 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-amber-500 focus:ring-amber-500"
                                />
                                <div class="flex-1 space-y-2">
                                    <div class="font-medium text-zinc-100">{{ $row['incoming']['title'] }}</div>
                                    <div class="space-y-2">
                                        @foreach ($row['diff'] as $field => $vals)
                                            <div class="rounded border border-zinc-700 bg-zinc-900 p-2 text-xs">
                                                <div class="text-zinc-400 uppercase tracking-wide">{{ $field }}</div>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-1">
                                                    <div>
                                                        <div class="text-rose-300 text-[10px] uppercase">old</div>
                                                        <div class="text-rose-100 whitespace-pre-line">{{ is_bool($vals['old']) ? ($vals['old'] ? 'true' : 'false') : (string) $vals['old'] }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-emerald-300 text-[10px] uppercase">new</div>
                                                        <div class="text-emerald-100 whitespace-pre-line">{{ is_bool($vals['new']) ? ($vals['new'] ? 'true' : 'false') : (string) $vals['new'] }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Skipped --}}
        @if ($skipCount > 0)
            <details class="rounded-lg border border-zinc-700 bg-zinc-900/40 p-4">
                <summary class="cursor-pointer text-sm font-medium text-zinc-300">
                    Skipped — {{ $skipCount }} entries already match the database (no changes)
                </summary>
                <ul class="mt-3 space-y-1 text-sm text-zinc-400">
                    @foreach ($plan['skipped'] as $row)
                        <li>· {{ $row['title'] }} <span class="text-zinc-500">({{ $isRoadmap ? $row['status'] : $row['shipped_on'] }})</span></li>
                    @endforeach
                </ul>
            </details>
        @endif

        {{-- Action bar --}}
        <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-6">
            <x-filament::button
                wire:click="commitImport"
                wire:confirm="Import the selected entries as drafts?"
                :disabled="$newCount === 0 && $updCount === 0"
                color="success"
            >
                Import selected
            </x-filament::button>

            <x-filament::button wire:click="cancelImport" color="gray">
                Cancel
            </x-filament::button>

            <div class="ml-auto flex items-center gap-2">
                <input
                    type="file"
                    wire:model="reuploadFile"
                    accept=".yml,.yaml"
                    class="text-sm text-zinc-300 file:mr-2 file:rounded file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-zinc-200 hover:file:bg-zinc-700"
                />
                <x-filament::button wire:click="reupload" color="warning" size="sm">
                    Re-parse uploaded file
                </x-filament::button>
            </div>
        </div>

    </div>
</x-filament-panels::page>
